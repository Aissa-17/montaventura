<?php
/**
 * Stripe Webhook Handler for Montaventura
 */

// Load WordPress
$found = false;
$dir = __DIR__;
for ($i = 0; $i < 5; $i++) {
    if (file_exists($dir . '/wp-load.php')) {
        require_once $dir . '/wp-load.php';
        $found = true;
        break;
    }
    $dir = dirname($dir);
}
if (! $found) {
    error_log('❌ Webhook: wp-load.php not found.');
    http_response_code(500);
    exit;
}

// Load Stripe and config
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/stripe-config.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// Retrieve payload and signature
$payload         = @file_get_contents('php://input');
$sig_header      = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$endpoint_secret = defined('ENDPOINT_SECRET')
    ? ENDPOINT_SECRET
    : getenv('STRIPE_ENDPOINT_SECRET');

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
} catch (\UnexpectedValueException $e) {
    error_log('❌ Webhook: Invalid payload.');
    http_response_code(400);
    exit;
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    error_log('❌ Webhook: Invalid signature.');
    http_response_code(400);
    exit;
}

// Log event
error_log('ℹ️ Webhook: Received event ' . $event->type . ' (' . $event->id . ').');

if ($event->type === 'checkout.session.completed') {
    $session     = $event->data->object;
    $post_id     = intval( $session->metadata['post_id'] ?? 0 );
    $tipo_act    = sanitize_text_field( $session->metadata['tipo_act'] ?? '' );
    $hora_clase  = sanitize_text_field( $session->metadata['hora_clase'] ?? '' );

    error_log("⚙️ Webhook: tipo_act = '{$tipo_act}', hora_clase = '{$hora_clase}', post_id = {$post_id}");

    // === NUEVO: capturar día de la clase y crear evento all-day ===
    $dia_clase = sanitize_text_field( $session->metadata['diaClase'] ?? '' );
    if ( $tipo_act === 'clases' && $dia_clase ) {
        // Montar timestamps all-day (00:00 a 23:59)
        $fecha_inicio = $dia_clase . ' 00:00:00';
        $fecha_fin    = $dia_clase . ' 23:59:59';

        // Insertar evento en calendario (post type 'mtv_evento')
        wp_insert_post([
            'post_type'   => 'mtv_evento',
            'post_title'  => sprintf( 'Clase – %s', $dia_clase ),
            'post_status' => 'publish',
            'meta_input'  => [
                '_mtv_evento_post_id' => $post_id,
                '_mtv_evento_start'   => $fecha_inicio,
                '_mtv_evento_end'     => $fecha_fin,
                'actividad_tipo'      => 'clases',
            ],
        ]);
        error_log("✅ Webhook: creado evento all-day para clase el {$dia_clase}");
    }

    global $wpdb;
    $tabla_pagos = $wpdb->prefix . 'montaventura_pagos';

    // Avoid duplicates
    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tabla_pagos} WHERE session_id = %s",
            $session->id
        )
    );
    if ($exists) {
        error_log('⚠️ Webhook: Duplicate session ' . $session->id . ', skipping.');
        http_response_code(200);
        exit;
    }

    // Insert payment record
    $wpdb->insert(
        $tabla_pagos,
        [
            'post_id'        => $post_id,
            'session_id'     => $session->id,
            'amount'         => intval($session->amount_total),
            'currency'       => sanitize_text_field($session->currency),
            'payment_status' => sanitize_text_field($session->payment_status),
            'customer_email' => sanitize_email($session->customer_email),
            'created_at'     => current_time('mysql'),
        ],
        ['%d','%s','%d','%s','%s','%s','%s']
    );

    // Handle promo code
    $promo = sanitize_text_field($session->metadata['promo_code'] ?? '');
    if ($promo) {
        $tabla_promo = $wpdb->prefix . 'montaventura_promo_codes';
        $promo_row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT uses_remaining FROM {$tabla_promo} WHERE code = %s",
                $promo
            ),
            ARRAY_A
        );
        if ($promo_row) {
            $uses = max(0, intval($promo_row['uses_remaining']) - 1);
            if ($uses > 0) {
                $wpdb->update(
                    $tabla_promo,
                    ['uses_remaining' => $uses],
                    ['code' => $promo],
                    ['%d'],
                    ['%s']
                );
                error_log("✅ Promo {$promo} now has {$uses} uses.");
            } else {
                $wpdb->delete($tabla_promo, ['code' => $promo], ['%s']);
                error_log("🗑️ Promo {$promo} removed.");
            }
        }
    }

    // Update availability
    if ($post_id > 0) {
        if ($tipo_act === 'clases') {
            $slots = get_post_meta($post_id, '_mtv_clases_slots', true);
            if (is_array($slots)) {
                foreach ($slots as $i => $slot) {
                    $hora_slot = substr(sanitize_text_field($slot['hora'] ?? ''), 0, 5);
                    if ($hora_slot === substr($hora_clase, 0, 5)) {
                        $antes = intval($slot['plazas']);
                        $slots[$i]['plazas'] = max(0, $antes - 1);
                        error_log("ℹ️ Slot {$hora_slot} plazas: {$antes} -> {$slots[$i]['plazas']}");
                        break;
                    }
                }
                update_post_meta($post_id, '_mtv_clases_slots', $slots);
            }
        } else {
            // Other activity types
            $plazas = intval(get_post_meta($post_id, '_mtv_plazas', true));
            $new    = max(0, $plazas - 1);
            update_post_meta($post_id, '_mtv_plazas', $new);
            error_log("ℹ️ Post {$post_id} plazas: {$plazas} -> {$new}");
        }
    }
}

http_response_code(200);
echo json_encode(['status' => 'success']);
exit;
