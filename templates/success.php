<?php
/**
 * success.php
 *
 * Tras finalizar el pago en Stripe:
 *  1) Recupera la sesión de Stripe
 *  2) Crea la reserva en WP si no existe
 *  3) Resta plazas en la actividad principal (excursión/otro)
 *  4) Resta plaza en el slot de clases si aplica
 *  5) Crea evento all-day para cualquier actividad
 *  6) Genera bonos para “horas”
 *  7) Muestra la confirmación, las opciones adicionales y los bonos generados
 *
 * Con logs en cada paso para depuración.
 */

error_log("success.php: INICIO");
@ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);

// 1) Carga WordPress
$found = false;
$dir   = __DIR__;
for ($i = 0; $i < 5; $i++) {
    if (file_exists("$dir/wp-load.php")) {
        require_once "$dir/wp-load.php";
        // Tras el require de wp-load.php:
        @ini_set('display_errors', 0);

        $found = true;
        error_log("success.php: wp-load.php cargado en nivel $i");
        break;
    }
    $dir = dirname($dir);
}
if (!$found) {
    error_log("success.php: wp-load.php NO encontrado");
    exit('Error interno.');
}

// 2) Carga Stripe
require_once dirname(__DIR__) . '/stripe-config.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
error_log("success.php: Stripe cargado");

// === Detección del flujo: FREE (bono 100%) o Stripe estándar
$is_free    = isset($_GET['free']) && $_GET['free'] !== '';
$free_token = $is_free ? sanitize_text_field($_GET['free']) : '';
$sid        = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';

if ($is_free) {
    // Leer metadata del transient y construir una sesión “ficticia”
    $meta = get_transient("mtv_free_{$free_token}");
    if (!$meta) {
        wp_die('Token gratuito inválido o caducado.');
    }
    delete_transient("mtv_free_{$free_token}");

    $session = (object)[
        'id'             => 'FREE-' . $free_token,
        'amount_total'   => 0,
        'created'        => time(),
        'customer_email' => $meta['email'] ?? '',
        'metadata'       => (object)$meta,
        'payment_status' => 'paid',
    ];
    $sid = $session->id;

    // Totales
    $importe_total_real = isset($meta['importe_total_eur']) && $meta['importe_total_eur'] !== ''
        ? (float) str_replace(',', '.', (string)$meta['importe_total_eur'])
        : 0.0;
    $importe_pagado    = 0.0;
    $importe_pendiente = max(0, $importe_total_real - $importe_pagado);

    error_log(sprintf(
        "success.php (FREE) Totales -> total=%.2f pagado=%.2f pendiente=%.2f",
        $importe_total_real, $importe_pagado, $importe_pendiente
    ));
} else {
    // Flujo normal Stripe
    error_log("success.php: session_id='{$sid}'");
    if (!$sid) {
        wp_die('Falta parámetro session_id');
    }
    try {
        $session = \Stripe\Checkout\Session::retrieve($sid);

        if (isset($session->metadata->importe_total_eur) && $session->metadata->importe_total_eur !== '') {
            $importe_total_real = (float) str_replace(',', '.', $session->metadata->importe_total_eur);
        } else {
            $importe_total_real = (float) ($session->amount_total / 100);
        }

        $importe_pagado    = (float) ($session->amount_total / 100);
        $importe_pendiente = max(0, $importe_total_real - $importe_pagado);

        error_log(sprintf(
            "success.php (Stripe) Totales -> total=%.2f pagado=%.2f pendiente=%.2f",
            $importe_total_real, $importe_pagado, $importe_pendiente
        ));
        error_log("success.php: Sesión Stripe OK: {$session->id}");
    } catch (Exception $e) {
        error_log("success.php: Error recuperando sesión Stripe: " . $e->getMessage());
        wp_die('Error Stripe: ' . esc_html($e->getMessage()));
    }
}

// Recuperamos metadatos ya como array “plano”
$metadata = (array) $session->metadata;
// --- Fusionar metadatos de todas las fuentes de Stripe ---
$metadata = [];

// 1) Metadata de la sesión
if (!empty($session->metadata)) {
    $metadata = ($session->metadata instanceof \Stripe\StripeObject)
        ? $session->metadata->toArray()
        : (array) $session->metadata;
}

// 2) Metadata del PaymentIntent (lo más frecuente)
try {
    if (!empty($session->payment_intent)) {
        $pi = \Stripe\PaymentIntent::retrieve($session->payment_intent);
        $pi_meta = ($pi->metadata instanceof \Stripe\StripeObject)
            ? $pi->metadata->toArray()
            : (array) $pi->metadata;

        // Los del PI pisan a los de Session si coinciden las claves
        $metadata = array_merge($pi_meta, $metadata);
        error_log('success.php: PI metadata = ' . json_encode($pi_meta));
    }
} catch (Exception $e) {
    error_log('success.php: no pude leer PaymentIntent metadata: ' . $e->getMessage());
}

// 3) Custom fields del Checkout
if (!empty($session->custom_fields) && is_array($session->custom_fields)) {
    foreach ($session->custom_fields as $cf) {
        $k = $cf->key ?? null;
        $v = $cf->text->value
          ?? $cf->dropdown->value
          ?? $cf->numeric->value
          ?? null;
        if ($k && $v !== null && $v !== '' && empty($metadata[$k])) {
            $metadata[$k] = $v;
        }
    }
    error_log('success.php: custom_fields = ' . json_encode($session->custom_fields));
}

// 4) Datos de contacto del comprador
if (!empty($session->customer_details)) {
    $cd = $session->customer_details;
    if (empty($metadata['alumno_email']) && !empty($cd->email))   $metadata['alumno_email'] = $cd->email;
    if (empty($metadata['nombre'])       && !empty($cd->name))    $metadata['nombre']       = $cd->name;
    if (empty($metadata['telefono'])     && !empty($cd->phone))   $metadata['telefono']     = $cd->phone;
}

// 5) Normalizaciones / alias para tus claves
$metadata['diaClase']    = $metadata['diaClase']
                        ?? $metadata['diaClaseISO']
                        ?? $metadata['fecha_inicio']
                        ?? $metadata['dia']
                        ?? '';
$metadata['hora_clase']  = $metadata['hora_clase']
                        ?? $metadata['horaClase']
                        ?? $metadata['hora']
                        ?? '';
$metadata['nivel_grupo'] = $metadata['nivel_grupo'] ?? $metadata['nivel'] ?? '';
$metadata['edad_group']  = $metadata['edad_group']  ?? $metadata['edad']  ?? '';

error_log('success.php: metadata fusionado = ' . json_encode($metadata));


// 4) Datos de contacto del comprador
if (!empty($session->customer_details)) {
    $cd = $session->customer_details;
    if (empty($metadata['alumno_email']) && !empty($cd->email))   $metadata['alumno_email'] = $cd->email;
    if (empty($metadata['nombre'])       && !empty($cd->name))    $metadata['nombre']       = $cd->name;
    if (empty($metadata['telefono'])     && !empty($cd->phone))   $metadata['telefono']     = $cd->phone;
}

// 5) Normalizaciones / alias para tus claves
$metadata['diaClase']    = $metadata['diaClase']
                        ?? $metadata['diaClaseISO']
                        ?? $metadata['fecha_inicio']
                        ?? $metadata['dia']
                        ?? '';
$metadata['hora_clase']  = $metadata['hora_clase']
                        ?? $metadata['horaClase']
                        ?? $metadata['hora']
                        ?? '';
$metadata['nivel_grupo'] = $metadata['nivel_grupo'] ?? $metadata['nivel'] ?? '';
$metadata['edad_group']  = $metadata['edad_group']  ?? $metadata['edad']  ?? '';

error_log('success.php: metadata fusionado = ' . json_encode($metadata));

$bonos = [];

// Si no vino 'edad' pero sí 'fecha_nacimiento', la calculamos aquí
if (empty($metadata['edad']) && !empty($metadata['fecha_nacimiento'])) {
    $ts = strtotime((string)$metadata['fecha_nacimiento']);
    if ($ts) {
        $metadata['edad'] = (string) floor((time() - $ts) / (365.25 * 24 * 3600));
    }
}

// Antes de crear, comprobamos si ya existe una reserva con este session_id
$existing = get_posts([
    'post_type'   => 'mtv_reserva',
    'meta_key'    => 'stripe_session_id',
    'meta_value'  => $session->id,
    'fields'      => 'ids',
    'numberposts' => 1,
]);
$is_new_reservation = empty($existing);

if (empty($existing)) {
    // No existe aún → la creamos
    $fecha_clase = !empty($metadata['diaClase'])
        ? sanitize_text_field($metadata['diaClase'])
        : sanitize_text_field($metadata['fecha_inicio'] ?? '');
// --- Construir texto de Observaciones con lo importante ---
$__build_obs = static function(array $md): string {
    $partes = [];

    $v = trim((string)($md['edad'] ?? ''));                   if ($v !== '') $partes[] = "Edad: $v";
    $v = trim((string)($md['botas'] ?? $md['talla_pie'] ?? $md['pie'] ?? ''));
    if ($v !== '') $partes[] = "Pie: $v";

    $v = trim((string)($md['ski'] ?? $md['esqui'] ?? $md['talla_esqui'] ?? ''));
    if ($v !== '') $partes[] = "Ski: $v";

    $v = trim((string)($md['altura'] ?? ''));                 if ($v !== '') $partes[] = "Altura: $v";
    $v = trim((string)($md['nivel'] ?? $md['nivel_grupo'] ?? ''));
    if ($v !== '') $partes[] = "Nivel: $v";

    // Si había texto libre del checkout, lo añadimos
    if (!empty($md['opciones_adicionales'])) {
        $partes[] = 'Notas: ' . trim((string)$md['opciones_adicionales']);
    }

    return implode(' | ', $partes);
};

$observaciones_text = $__build_obs($metadata);

    $post_id = wp_insert_post([
        'post_type'   => 'mtv_reserva',
        'post_status' => 'publish',
        'post_title'  => sprintf('Reserva Montaventura – %s', esc_html($metadata['nombre'] ?? '')),
        'meta_input'  => [
            'actividad_id'         => $metadata['actividad_id']      ?? '',
            'actividad_post_id'    => $metadata['actividad_post_id'] ?? '',
            'tipo_actividad'       => $metadata['tipo_actividad']    ?? '',
            'nombre'               => $metadata['nombre']            ?? '',
            'telefono'             => $metadata['telefono']          ?? '',
            'diaClase'             => $fecha_clase,
            'hora_clase'           => $metadata['hora_clase']        ?? '',
            'fecha_inicio'         => $metadata['fecha_inicio']      ?? '',
            'opciones_adicionales' => $metadata['opciones_adicionales'] ?? '',

            // adicionales
            'edad_group'  => $metadata['edad_group'] ?? ($metadata['edad'] ?? ''),
            'edad'        => $metadata['edad'] ?? '',
            'botas'       => $metadata['botas'] ?? ($metadata['pie'] ?? ($metadata['talla_pie'] ?? '')),
            'pie'         => $metadata['pie'] ?? '',
            'talla_pie'   => $metadata['talla_pie'] ?? '',
            'ski'         => $metadata['ski'] ?? ($metadata['esqui'] ?? ($metadata['talla_esqui'] ?? ($metadata['altura'] ?? ''))),
            'esqui'       => $metadata['esqui'] ?? ($metadata['ski'] ?? ($metadata['altura'] ?? '')),
            'talla_esqui' => $metadata['talla_esqui'] ?? ($metadata['altura'] ?? ''),
            'altura'      => $metadata['altura'] ?? '',
            'nivel'       => $metadata['nivel'] ?? '',
            'nivel_grupo' => $metadata['nivel_grupo'] ?? '',

            // tutor si existe
            'nombre_tutor'    => $metadata['nombre_tutor']   ?? '',
            'email_tutor'     => $metadata['email_tutor']    ?? '',
            'telefono_tutor'  => $metadata['telefono_tutor'] ?? '',

            // promo si existiera
            'used_promo_code' => $metadata['used_promo_code'] ?? '',
            'promo_info'      => $metadata['promo_info']      ?? '',

            // alumno
            'alumno_nombre'     => $metadata['nombre']           ?? '',
            'alumno_apellidos'  => $metadata['apellidos']        ?? '',
            'alumno_email'      => $session->customer_email      ?? '',
            'alumno_telefono'   => $metadata['telefono']         ?? '',
            'stripe_session_id' => $session->id,
            'observaciones' => $observaciones_text,
        ],  
    ]);
    if (!$is_new_reservation) {
    $obs_existente = (string) get_post_meta($res_id, 'observaciones', true);
    if (trim($obs_existente) === '') {
        update_post_meta($res_id, 'observaciones', $observaciones_text);
    }
}

    $res_id = $post_id;
    error_log("success.php: Reserva creada en success.php con ID={$post_id}");
} else {
    $res_id = $existing[0];
    error_log("success.php: Ya existe mtv_reserva con stripe_session_id={$session->id} (post_id={$res_id})");
}

$meta = get_post_meta($res_id);

// Variables comunes
$tipo_act  = sanitize_text_field($meta['tipo_actividad'][0] ?? '');
$dia_clase = sanitize_text_field($meta['diaClase'][0] ?? '');
if (!$dia_clase) {
    $dia_clase = sanitize_text_field($meta['fecha_inicio'][0] ?? '');
}
$post_principal = intval($meta['actividad_id'][0] ?? 0);
global $wpdb;
$row = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->prefix}actividades WHERE id = %d",
        $post_principal
    )
);
$post_activity  = $row ? intval($row->post_id) : 0;

$hora_reservada = sanitize_text_field($meta['hora_clase'][0] ?? '');
// ——— Actividad para email (robusto) ———
$actividad_para_email = '';
if (!empty($metadata['tipo_actividad'])) {
    $actividad_para_email = (string)$metadata['tipo_actividad'];           // Lo que vino desde Stripe
} elseif (!empty($meta['tipo_actividad'][0])) {
    $actividad_para_email = (string)$meta['tipo_actividad'][0];            // Lo que guardamos en la reserva
} elseif ($post_activity) {
    $actividad_para_email = get_the_title($post_activity);                 // Último recurso
}
$actividad_para_email = sanitize_text_field($actividad_para_email);

// Si por error llega una hora o queda vacío, ponemos algo legible
if ($actividad_para_email === '' || preg_match('/^\d{1,2}:\d{2}$/', $actividad_para_email)) {
    $actividad_para_email = 'Clases';
}

// === Normalización robusta para packs multi-hora (p.ej. "Bono 4 horas Particulares - 220,00 €") ===
$__detect_pack = function (string $label, array $md): array {
    $label = trim($label);
    $horas = 0;
    if (preg_match('/(\d+)\s*hora/i', $label, $mm)) { $horas = (int)$mm[1]; }
    elseif (preg_match('/^(\d+)\s*hora/i', $label, $mm)) { $horas = (int)$mm[1]; }

    if ($horas <= 0) return [0, ''];

    $txt = strtolower($label . ' ' . ($md['modalidad'] ?? ''));
    $mod = '';
    if (strpos($txt, 'colect') !== false)   $mod = 'C';
    if (strpos($txt, 'particul') !== false) $mod = 'P';
    // Como último recurso, si hay grupo (colectivas) y no detectamos texto:
    if ($mod === '' && !empty($md['nivel_grupo'])) $mod = 'C';
    return [$horas, $mod];
};

// Tomamos la mejor fuente disponible para detectar el pack
list($packHoras, $packMod) = $__detect_pack($tipo_act ?: $actividad_para_email, $metadata);

// Si detectamos pack (>=2 horas) normalizamos $tipo_act a "Nhora(s)C/P"
if ($packHoras >= 2 && $packMod !== '') {
    $tipo_act = $packHoras . 'horas' . $packMod; // p.ej. 4horasP
}
error_log("success.php: PACK detectado -> tipo_actividad normalizado='{$tipo_act}', horas={$packHoras}, mod={$packMod}");

// ——————————————————————————————————————
//  X) Registrar pago en la tabla gxax_montaventura_pagos
// ——————————————————————————————————————
$tabla_pagos = $wpdb->prefix . 'montaventura_pagos';

// Preparamos los datos
$nombre   = sanitize_text_field($metadata['nombre'] ?? '');
$email    = sanitize_email($session->customer_email);
$telefono = sanitize_text_field($metadata['telefono'] ?? '');
$importe_total     = $importe_total_real; // total sin comisiones
$importe_pagado_db = $importe_pagado;     // total realmente cobrado
$importe_pendiente_db = $importe_pendiente;
$fecha_pago = date('Y-m-d H:i:s', $session->created);

// Insertamos (evitando duplicados por session_id)
$existe_pago = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(1) FROM {$tabla_pagos} WHERE session_id = %s",
    $session->id
));

if (!$existe_pago) {
    $ok = $wpdb->insert(
        $tabla_pagos,
        [
            'nombre'            => $nombre,
            'email'             => $email,
            'telefono'          => $telefono,
            'importe_total'     => $importe_total,
            'importe_pagado'    => $importe_pagado_db,
            'importe_pendiente' => $importe_pendiente_db,
            'fecha_primer_pago' => $fecha_pago,
            'estado'            => 'pagado',
            'session_id'        => $session->id,
        ],
        ['%s','%s','%s','%f','%f','%f','%s','%s','%s']
    );
    if (false === $ok) {
        error_log("success.php: ERROR al insertar en {$tabla_pagos}: " . $wpdb->last_error);
    } else {
        error_log("success.php: Pago registrado en {$tabla_pagos}, filas afectadas: {$ok}");
    }
} else {
    error_log("success.php: Ya existe registro de pago para session_id={$session->id}");
}

// ————————————————
// 6) Restar plazas en la tabla principal (excursión/otro) — solo primera vez
// ————————————————
if ($is_new_reservation) {
    error_log("success.php: Intentando restar plaza de actividad_id={$post_principal}");
    if ($post_principal) {
        // 1) Bajo en la tabla custom
        $sql     = $wpdb->prepare(
            "SELECT plazas FROM {$wpdb->prefix}actividades WHERE id = %d",
            $post_principal
        );
        $current = intval($wpdb->get_var($sql));
        error_log("success.php: Plazas actuales: {$current}");
        $new     = max(0, $current - 1);
        $updated = $wpdb->update(
            "{$wpdb->prefix}actividades",
            ['plazas' => $new],
            ['id'     => $post_principal],
            ['%d'], ['%d']
        );
        error_log("success.php: UPDATE actividades SET plazas={$new} → returned " . var_export($updated, true));

        // 2) Sincronizamos el meta box de WP
        if ($post_activity) {
            update_post_meta($post_activity, '_mtv_excursion_plazas', $new);
            error_log("success.php: Sincronizado post_meta '_mtv_excursion_plazas' para post_id={$post_activity} → {$new}");
        }
    }
}

// ---------------------------
// 7) Slot de CLASES (si existe meta '_mtv_clases_dias') — solo primera vez
// ---------------------------
$target_act = $post_activity ?: intval($meta['actividad_post_id'][0] ?? 0);
$dias = get_post_meta($target_act, '_mtv_clases_dias', true);

// Si viniera serializado como JSON
if (is_string($dias)) {
    $tmp = json_decode($dias, true);
    if (json_last_error() === JSON_ERROR_NONE) { $dias = $tmp; }
}

$dia_clase_meta    = sanitize_text_field($meta['diaClase'][0] ?? '');
$fechas_reservadas = array_values(array_filter(array_map('trim', explode(',', $dia_clase_meta))));

// Helpers
$toYmd = function (string $s): string {
    $s = trim($s);
    $d1 = DateTime::createFromFormat('d/m/Y', $s);
    if ($d1 && $d1->format('d/m/Y') === $s) return $d1->format('Y-m-d');
    $d2 = DateTime::createFromFormat('Y-m-d', $s);
    if ($d2 && $d2->format('Y-m-d') === $s) return $s;
    $ts = strtotime(str_replace('/', '-', $s));
    return $ts ? date('Y-m-d', $ts) : '';
};
$getNivelNum = function ($v): int {
    if (is_numeric($v)) return (int)$v;
    return preg_match('/(\d+)/', (string)$v, $m) ? (int)$m[1] : 0;
};
$matchEqOrOneDay = function (string $slotYmd, array $objetivos): bool {
    if (in_array($slotYmd, $objetivos, true)) return true;
    $prev = date('Y-m-d', strtotime($slotYmd . ' -1 day'));
    $next = date('Y-m-d', strtotime($slotYmd . ' +1 day'));
    return in_array($prev, $objetivos, true) || in_array($next, $objetivos, true);
};

$nivelSolicitado = (int)($meta['nivel_grupo'][0] ?? 0);

// “Anual”: si hay muchas fechas en la reserva, usamos las fechas INICIO/FIN
// que hay configuradas en los slots (evita desfases sábado/domingo).
$esAnual = count($fechas_reservadas) > 2;
if ($esAnual && is_array($dias) && $dias) {
    $candidatas = [];
    foreach ($dias as $s) {
        $n = $getNivelNum($s['nivel'] ?? 0);
        if ($nivelSolicitado === 0 || $n === $nivelSolicitado) {
            $candidatas[] = $toYmd($s['date'] ?? '');
        }
    }
    $candidatas = array_values(array_filter($candidatas));
    sort($candidatas);
    // inicio/fin desde slots
    $objetivos = [];
    if (!empty($candidatas)) {
        $objetivos[] = $candidatas[0];
        $objetivos[] = $candidatas[count($candidatas) - 1];
    }
} else {
    // Clase suelta: usamos exactamente las fechas que llegan
    $objetivos = array_map($toYmd, $fechas_reservadas);
}

$objetivos = array_values(array_filter(array_unique($objetivos)));
error_log("success.php: [CLASES] objetivos=" . implode(',', $objetivos) . " nivelSolicitado={$nivelSolicitado}");

if ($is_new_reservation) {
    $toque = 0;
    if (is_array($dias) && $dias && $objetivos) {
        foreach ($dias as $i => &$slot) {
            $fechaSlotYmd = $toYmd($slot['date'] ?? '');
            $nivelSlot    = $getNivelNum($slot['nivel'] ?? 0);

            $matcheaFecha = $matchEqOrOneDay($fechaSlotYmd, $objetivos);
            $matcheaNivel = ($nivelSolicitado === 0) ? true : ($nivelSlot === $nivelSolicitado);

            if ($matcheaFecha && $matcheaNivel) {
                $antes = (int)($slot['plazas'] ?? 0);
                $slot['plazas'] = max(0, $antes - 1);
                $toque++;
                error_log("success.php: [CLASES] {$fechaSlotYmd} (nivel {$nivelSlot}): {$antes}→{$slot['plazas']}");
            } else {
                error_log("success.php: [CLASES] no aplica slot fecha={$fechaSlotYmd} nivel={$nivelSlot} " .
                    "(matchFecha=" . ($matcheaFecha ? 'SI' : 'NO') . ", matchNivel=" . ($matcheaNivel ? 'SI' : 'NO') . ")");
            }
        }
        unset($slot);

        if ($toque > 0) {
            update_post_meta($target_act, '_mtv_clases_dias', $dias);
            error_log("success.php: [CLASES] Actualizados {$toque} slot(s)");
        } else {
            error_log("success.php: [CLASES] No se actualizó ningún slot");
        }
    } else {
        error_log("success.php: [CLASES] no hay días configurados o no hay objetivos");
    }
}

// ——————————————————————————————————————
// 8) Slot de BONO (si existe meta '_mtv_bono_slots') — solo primera vez
// ——————————————————————————————————————
if ($is_new_reservation) {
    $bono_slots = get_post_meta($target_act, '_mtv_bono_slots', true);
    if (is_array($bono_slots) && count($bono_slots)) {
        error_log("success.php: [BONO] intentando restar plaza en actividad_id={$target_act} hora={$hora_reservada}");
        foreach ($bono_slots as $i => &$slot) {
            if (isset($slot['hora']) && $slot['hora'] === $hora_reservada) {
                $antes          = intval($slot['plazas']);
                $slot['plazas'] = max(0, $antes - 1);
                error_log("success.php: [BONO] slot {$slot['hora']}: {$antes}→{$slot['plazas']}");
                update_post_meta($target_act, '_mtv_bono_slots', $bono_slots);
                break;
            }
        }
        unset($slot);
    } else {
        error_log("success.php: [BONO] no hay slots de bono o meta no es array");
    }
}

// 9) Crear evento all-day (solo si el CPT existe y es la primera vez)
if ($is_new_reservation && $dia_clase && $post_activity && post_type_exists('mtv_evento')) {
    $evt_id = wp_insert_post([
        'post_type'   => 'mtv_evento',
        'post_title'  => sprintf('Clase – %s', $dia_clase),
        'post_status' => 'publish',
        'meta_input'  => [
            '_mtv_evento_post_id' => $post_activity,
            '_mtv_evento_start'   => "{$dia_clase} 00:00:00",
            '_mtv_evento_end'     => "{$dia_clase} 23:59:59",
            'actividad_tipo'      => $tipo_act,
            'observaciones'       => $observaciones_text,
        ],
    ]);
    error_log("success.php: Evento all-day creado ID {$evt_id}");
} else {
    error_log("success.php: No creo evento (falta fecha/actividad o CPT 'mtv_evento' no existe o no es nueva).");
}

// Normalización extra del tipo para bonos multi-hora
$__tipo_original = $tipo_act;
$__candidato = $tipo_act ?: (string)($metadata['tipo_actividad'] ?? '') ?: $actividad_para_email;
if ($__candidato !== $tipo_act) {
    error_log("success.php: tipo_actividad vacío/discordante. Usando candidato='{$__candidato}' (email_act='{$actividad_para_email}')");
    $tipo_act = $__candidato;
}
error_log("success.php: estado previo normalización -> tipo_actividad='{$tipo_act}', modalidad_meta='" . ($metadata['modalidad'] ?? '') . "'");

if (!preg_match('/^(\d+)hora[s]?([CP])$/i', $tipo_act)) {
    // Intentar extraer nº de horas y modalidad desde la etiqueta larga o fallback
    if (preg_match('/(\d+)\s*hora/i', $tipo_act, $mHoras)) {
        $__h   = (int) $mHoras[1];
        $__mod = '';
        $txt   = strtolower(trim($tipo_act . ' ' . ($metadata['modalidad'] ?? '')));
        if (strpos($txt, 'particul') !== false) { $__mod = 'P'; }
        elseif (strpos($txt, 'colect') !== false) { $__mod = 'C'; }
        if ($__h > 0 && $__mod !== '') {
            $tipo_act = $__h . 'horas' . $__mod; // "4horasP" o "4horasC"
        }
    }
    error_log("success.php: Normalizado tipo_act '{$__tipo_original}' → '{$tipo_act}'");
}

// X) Consumir 1 uso del BONO aplicado (idempotente por $is_new_reservation)
$tabla_codes = $wpdb->prefix . 'montaventura_promo_codes';

// 1) Preferir el ID del bono (venía desde checkout.php)
$promo_id  = (int)($metadata['promo_id'] ?? 0);

// 2) Fallback por código si no hay id
$used_code = trim((string)(
    $metadata['used_promo_code']            // desde sesión/transient FREE
    ?? ($meta['used_promo_code'][0] ?? '')  // o meta de la reserva
));
$used_code = strtoupper($used_code);

if ($is_new_reservation) {
    // Intento 1: por ID
    if ($promo_id > 0) {
        $q = $wpdb->prepare(
            "UPDATE {$tabla_codes}
               SET uses_remaining = uses_remaining - 1
             WHERE id = %d
               AND uses_remaining > 0",
            $promo_id
        );
        $wpdb->query($q);
        error_log("success.php: [BONO] consumo por ID={$promo_id} → filas={$wpdb->rows_affected}");
    }

    // Intento 2: por code (solo si no hubo ID o no afectó filas)
    if ((int)$wpdb->rows_affected === 0 && $used_code !== '') {
        $q = $wpdb->prepare(
            "UPDATE {$tabla_codes}
               SET uses_remaining = uses_remaining - 1
             WHERE code = %s
               AND uses_remaining > 0",
            $used_code
        );
        $wpdb->query($q);
        error_log("success.php: [BONO] consumo por CODE={$used_code} → filas={$wpdb->rows_affected}");
    }

    if ($wpdb->last_error) {
        error_log("success.php: [BONO] SQL error: " . $wpdb->last_error);
    }
    if ((int)$wpdb->rows_affected === 0) {
        error_log("success.php: [BONO] no se consumió (¿ID/código no coinciden o usos=0?)");
    }
}

// 9) Generación de bonos (simple y conforme a tu tabla)
$tipo_pack = (string)($session->metadata->tipo_actividad ?? $tipo_act ?? '');
if (preg_match('/^(\d+)hora(?:s)?([CP])$/i', $tipo_pack, $m)) {
    $num = (int)$m[1];
    $mod = (strtoupper($m[2]) === 'C') ? 'colectiva' : 'particular';
    if ($num > 1) {
        $uses = $num - 1; // la 1ª hora ya se consume en la compra
        if (function_exists('mtv_insert_promo_code')) {
            $res = mtv_insert_promo_code(
                sanitize_email($session->customer_email),
                'bono',
                $uses,
                null,
                $mod
            );
            if (!is_wp_error($res)) {
                $bonos[] = (object)[
                    'code'            => $res->code,
                    'tipo'            => 'clases_' . $mod,
                    'uses_remaining'  => $res->uses_remaining,
                ];
                error_log("success.php: BONO creado OK ({$res->code}) {$num}h {$mod}");
            } else {
                error_log("success.php: ERROR creando bono (helper): " . $res->get_error_message());
            }
        } else {
            error_log("success.php: mtv_insert_promo_code no existe");
        }
    } else {
        error_log("success.php: Pack de 1 hora; no se genera bono.");
    }
} else {
    error_log("success.php: No pack multi-hora. tipo_pack='{$tipo_pack}'");
}

// ——————————————————————————————————————
// Envío de notificación interna a info@montaventura.com (solo primera vez)
// ——————————————————————————————————————
if ($is_new_reservation) {
    $admin_to      = 'info@montaventura.com';
    $admin_subject = '🔔 Nueva reserva en Montaventura – ' . esc_html($metadata['nombre'] ?? '');
    $admin_headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Montaventura <no-reply@montaventura.com>',
    ];

    // Construimos un body con todos los campos de la reserva
    $admin_body  = '<h2>Se ha registrado una nueva reserva:</h2>';
    $admin_body .= '<ul>';
    $admin_body .= '<li><strong>Nombre:</strong> '   . esc_html($metadata['nombre']   ?? '') . '</li>';
    $admin_body .= '<li><strong>Email:</strong> '    . esc_html($session->customer_email)     . '</li>';
    $admin_body .= '<li><strong>Teléfono:</strong> ' . esc_html($metadata['telefono']  ?? '') . '</li>';
    $admin_body .= '<li><strong>Actividad:</strong> ' . esc_html($actividad_para_email) . '</li>';
    $admin_body .= '<li><strong>Fecha:</strong> '    . esc_html($metadata['diaClase'] ?? $metadata['fecha_inicio'] ?? '') . '</li>';
    $admin_body .= '<li><strong>Hora:</strong> '     . esc_html($metadata['hora_clase'] ?? '') . '</li>';
    if (!empty($metadata['nombre_tutor'])) {
        $admin_body .= '<li><strong>Tutor/a:</strong> ' . esc_html($metadata['nombre_tutor']) . '</li>';
        $admin_body .= '<li><strong>Email tutor:</strong> ' . esc_html($metadata['email_tutor']) . '</li>';
        $admin_body .= '<li><strong>Teléfono tutor:</strong> ' . esc_html($metadata['telefono_tutor']) . '</li>';
    }
    if (!empty($metadata['opciones_adicionales'])) {
        $admin_body .= '<li><strong>Opciones adicionales:</strong><br>'
            . nl2br(esc_html($metadata['opciones_adicionales'])) . '</li>';
    }
    // Si se generaron bonos, los listamos
    if (!empty($bonos)) {
        $admin_body .= '<li><strong>Bonos generados:</strong><ul>';
        foreach ($bonos as $b) {
            $admin_body .= '<li>'
                . 'Código: <strong>' . esc_html($b->code) . '</strong> – '
                . 'Tipo: ' . esc_html($b->tipo) . ' – '
                . 'Usos restantes: ' . intval($b->uses_remaining)
                . '</li>';
        }
        $admin_body .= '</ul></li>';
    }
    $admin_body .= '<li><strong>Importe total:</strong> ' . number_format($importe_total_real, 2, ',', '.') . ' €</li>';
    $admin_body .= '<li><strong>Importe pagado:</strong> ' . number_format($importe_pagado,       2, ',', '.') . ' €</li>';
    $admin_body .= '</ul>';

    if (wp_mail($admin_to, $admin_subject, $admin_body, $admin_headers)) {
        error_log("success.php: Notificación enviada a {$admin_to}");
    } else {
        error_log("success.php: Error enviando notificación a {$admin_to}");
    }
}

// ——————————————————————————————————————
// Email de confirmación al alumno (solo primera vez)
// ——————————————————————————————————————
$to      = sanitize_email($session->customer_email);
$subject = 'Tu reserva en Montaventura está confirmada';
$headers = [
    'Content-Type: text/html; charset=UTF-8',
    'From: Montaventura <no-reply@montaventura.com>',
];
$fecha_clase_mail = !empty($metadata['diaClase']) ? $metadata['diaClase'] : ($metadata['diaClaseISO'] ?? $metadata['fecha_inicio'] ?? '');

$body  = '<h2>¡Hola ' . esc_html($metadata['nombre'] ?? '') . '!</h2>';
$body .= '<p>Tu reserva ha sido procesada correctamente. Estos son los detalles:</p>';
$body .= '<ul>';
$body .= '<li><strong>Nombre:</strong> ' . esc_html($metadata['nombre'] ?? '') . '</li>';
$body .= '<li><strong>Email:</strong> ' . esc_html($session->customer_email) . '</li>';
$body .= '<li><strong>Teléfono:</strong> ' . esc_html($metadata['telefono'] ?? '') . '</li>';
$body .= '<li><strong>Actividad:</strong> ' . esc_html($actividad_para_email) . '</li>';
$body .= '<li><strong>Fecha de clase:</strong> ' . esc_html($fecha_clase_mail) . '</li>';
$body .= '<li><strong>Hora de clase:</strong> ' . esc_html($metadata['hora_clase'] ?? '') . '</li>';
$body .= '<li><strong>Importe total:</strong> '  . number_format($importe_total_real, 2, ',', '.') . ' €</li>';
$body .= '<li><strong>Importe pagado:</strong> ' . number_format($importe_pagado,      2, ',', '.') . ' €</li>';
if (!empty($metadata['used_promo_code'])) {
    $body .= '<li><strong>Código promo usado:</strong> ' . esc_html($metadata['used_promo_code']) . '</li>';
}
$body .= '</ul>';

if (!empty($bonos)) {
    $body .= '<h3>¡Tus bonos han quedado activos!</h3>';
    $body .= '<ul>';
    foreach ($bonos as $bono) {
        $c = esc_html($bono->code);
        $t = esc_html($bono->tipo);
        $u = intval($bono->uses_remaining);
        $body .= "<li>Código: <strong>{$c}</strong> | Tipo: {$t} | Usos restantes: {$u}</li>";
    }
    $body .= '</ul>';
    $body .= '<p>Estos códigos ya están disponibles en tu cuenta y te los enviamos también por correo.</p>';
}

$body .= '<p>Gracias por confiar en Montaventura.<br>¡Nos vemos pronto!</p>';

if ($is_new_reservation) {
    if (wp_mail($to, $subject, $body, $headers)) {
        error_log("success.php: Email de confirmación enviado a {$to}");
    } else {
        error_log("success.php: Error enviando email a {$to}");
    }
}

// 10) Renderizado final
$email_cliente  = sanitize_email($session->customer_email);
$opciones_text  = $meta['opciones_adicionales'][0] ?? '';
error_log("success.php: Renderizando, bonos: " . count($bonos));
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>¡Reserva completada!</title>
  <style>
    body { font-family: sans-serif; max-width: 600px; margin: 2em auto; }
    .notice { background: #eaf5fc; border: 1px solid #0073aa; padding: 1em; }
    .opciones { background: #f9f9f9; border: 1px solid #ddd; padding: 1em; margin-top: 1em; }
    .bonos  { margin-top: 1em; }
    .boton  {
      display: inline-block; margin-top: 1.5em;
      padding: .5em 1em; background: #0073aa; color: #fff;
      text-decoration: none; border-radius: 4px;
    }
  </style>
</head>
<body>
  <h1>¡Gracias por tu reserva!</h1>

  <div class="notice">
    <p><strong>Importe total:</strong> <?php echo number_format($importe_total_real,2,',','.'); ?> €</p>
    <p><strong>Importe pagado:</strong> <?php echo number_format($importe_pagado,2,',','.'); ?> €</p>
    <?php if ($importe_pendiente > 0): ?>
      <p><strong>Importe pendiente:</strong> <?php echo number_format($importe_pendiente,2,',','.'); ?> €</p>
    <?php endif; ?>
    <p><strong>Email alumno:</strong> <?php echo esc_html($email_cliente); ?></p>
    <?php if (! empty($meta['nombre_tutor'][0])): ?>
      <p><strong>Tutor/a:</strong> <?php echo esc_html($meta['nombre_tutor'][0]); ?></p>
      <p><strong>Email tutor:</strong> <?php echo esc_html($meta['email_tutor'][0]); ?></p>
      <p><strong>Teléfono tutor:</strong> <?php echo esc_html($meta['telefono_tutor'][0]); ?></p>
    <?php endif; ?>
  </div>

  <?php if ($opciones_text): ?>
    <div class="opciones">
      <h2>Opciones adicionales</h2>
      <p><?php echo nl2br(esc_html($opciones_text)); ?></p>
    </div>
  <?php endif; ?>

  <?php if (! empty($bonos)): ?>
    <div class="bonos">
      <h2>¡Tus bonos han quedado activos!</h2>
      <ul>
        <?php foreach ($bonos as $b): ?>
          <li>
            Código: <code><?php echo esc_html($b->code); ?></code>
            &nbsp;|&nbsp;Tipo: <?php echo esc_html($b->tipo); ?>
            &nbsp;|&nbsp;Usos: <?php echo intval($b->uses_remaining); ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <p><strong>Importe total:</strong> <?php echo number_format($importe_total_real,2,',','.'); ?> €</p>
      <p><strong>Importe pagado:</strong> <?php echo number_format($importe_pagado,2,',','.'); ?> €</p>
    </div>
  <?php endif; ?>

  <p style="text-align:center;">
    <a href="<?php echo esc_url(home_url()); ?>" class="boton">← Volver al inicio</a>
  </p>
</body>
</html>
