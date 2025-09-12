<?php
// wp-content/plugins/montaventura/checkout.php

// Mostrar errores (solo en dev)
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

header('Content-Type: application/json');

// INICIO DEL LOG
error_log("========== checkout.php INICIO ==========");
error_log("REQUEST_URI: " . $_SERVER['REQUEST_URI']);
$raw_body = file_get_contents('php://input');
error_log("RAW BODY: {$raw_body}");

// 1) Cargar WordPress
error_log("checkout.php: Cargando wp-load.php...");
$path_wp = __DIR__ . '/../../../wp-load.php';
if (! file_exists($path_wp)) {
    $path_wp = __DIR__ . '/../../../../wp-load.php';
}
if (! file_exists($path_wp)) {
    error_log("ERROR: No se pudo cargar wp-load.php en {$path_wp}");
    http_response_code(500);
    echo json_encode(['error'=>'No se pudo cargar wp-load.php.']);
    exit;
}
require_once $path_wp;
error_log("checkout.php: wp-load.php cargado correctamente");

// 2) Cargar Stripe
error_log("checkout.php: Cargando Stripe SDK...");
require_once __DIR__ . '/stripe-config.php';
require_once __DIR__ . '/vendor/autoload.php';
if (! class_exists('\Stripe\Stripe')) {
    error_log("ERROR: Stripe SDK no cargado");
    http_response_code(500);
    echo json_encode(['error'=>'Stripe SDK no cargado.']);
    exit;
}
error_log("checkout.php: Stripe SDK cargado correctamente");

// 3) Leer JSON de entrada
$data = json_decode($raw_body, true);
if (! is_array($data)) {
    error_log("ERROR: JSON inválido");
    http_response_code(400);
    echo json_encode(['error'=>'JSON inválido.']);
    exit;
}
error_log("checkout.php: JSON parseado -> " . print_r($data, true));
// --- Campos adicionales del alumno
$apellidos  = sanitize_text_field($data['apellidos'] ?? '');
$dni        = sanitize_text_field($data['dni'] ?? '');

// --- Normaliza tipo_actividad
$tipo_raw = sanitize_text_field($data['tipo_actividad'] ?? $data['tipo'] ?? '');
if (preg_match('/^\d{1,2}:\d{2}$/', $tipo_raw)) {
    // si en cursos te llega la hora aquí, lo tratamos como 'clases'
    $tipo = 'clases';
} else {
    $tipo = $tipo_raw;
}

// --- Día y hora (acepta ambas claves)
$dia_clase  = sanitize_text_field($data['diaClaseISO'] ?? $data['diaClase'] ?? '');
$hora_clase = sanitize_text_field($data['hora_clase'] ?? $data['hora'] ?? '');

// --- Grupo, modalidad y edad (desde front)
$modalidad  = sanitize_text_field($data['modalidad']   ?? ''); // 'colectiva'|'particular'
$edad_group = sanitize_text_field($data['edad_group']  ?? ''); // 'infantil'|'adulto'

// --- Alquiler
$altura = isset($data['altura']) ? (int)$data['altura'] : null;
$botas  = isset($data['botas'])  ? (float)$data['botas'] : null;

// 4) Sanitizar campos básicos
$email          = sanitize_email      ($data['email']            ?? '');
$actividad_id   = intval              ($data['actividad_id']     ?? 0);
$nombre         = sanitize_text_field ($data['nombre']           ?? '');
$telefono       = sanitize_text_field ($data['telefono']         ?? '');
$fecha_nac      = sanitize_text_field ($data['fecha_nacimiento'] ?? '');
error_log("checkout.php: campos sanitizados -> email={$email}, actividad_id={$actividad_id}, nombre='{$nombre}', telefono='{$telefono}', fecha_nac='{$fecha_nac}'");


// Tutor (si menor)
$nombre_tutor   = sanitize_text_field ($data['nombre_tutor']     ?? '');
$email_tutor    = sanitize_email      ($data['email_tutor']      ?? '');
$telefono_tutor = sanitize_text_field ($data['telefono_tutor']   ?? '');
error_log("checkout.php: datos tutor -> nombre_tutor='{$nombre_tutor}', email_tutor='{$email_tutor}', telefono_tutor='{$telefono_tutor}'");

// 5) Validar campos obligatorios
if ($actividad_id <= 0 || $nombre === '' || $email === '') {
    error_log("ERROR: faltan datos obligatorios. actividad_id={$actividad_id}, nombre='{$nombre}', email='{$email}'");
    http_response_code(400);
    echo json_encode(['error'=>'Faltan datos obligatorios.']);
    exit;
}
// 6) Nivel y grupo (añadido)
// --- por esto ---
if ( ! empty( $data['nivel'] ) && strpos( $data['nivel'], '|' ) !== false ) {
    list( $nivel_code, $nivel_grupo ) = explode( '|', sanitize_text_field( $data['nivel'] ), 2 );
    $nivel_grupo = intval( $nivel_grupo );
} else {
    // por si acaso vienen mal formateados
    $nivel_code  = sanitize_text_field( $data['nivel'] ?? '' );
    $nivel_grupo = 0;
}
error_log("checkout.php: nivel recibido -> code='{$nivel_code}', grupo={$nivel_grupo}");

// 6) Calcular edad y validar tutor si menor
$edad = 0;
if ($fecha_nac && strtotime($fecha_nac)) {
    $edad = floor((time() - strtotime($fecha_nac)) / (365.25*24*3600));
}
error_log("checkout.php: edad calculada -> {$edad}");
if ($edad < 18 && ($nombre_tutor === '' || $email_tutor === '' || $telefono_tutor === '')) {
    error_log("ERROR: datos tutor obligatorios para menores");
    http_response_code(400);
    echo json_encode(['error'=>'Datos del tutor obligatorios para menores.']);
    exit;
}

// 7) Obtener datos de la actividad (incluyendo WP post_id)
global $wpdb;
$tabla_act = $wpdb->prefix . 'actividades';
$act       = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$tabla_act} WHERE id = %d",
    $actividad_id
));
if (! $act) {
    error_log("ERROR: Actividad no encontrada con id={$actividad_id}");
    http_response_code(404);
    echo json_encode(['error'=>'Actividad no encontrada.']);
    exit;
}
error_log("checkout.php: actividad encontrada -> " . print_r($act, true));

// 8) Tipo, día y hora
$tipo       = sanitize_text_field($data['tipo_actividad'] ?? $data['tipo'] ?? ($act->tipo_actividad ?? ''));
$dia_clase  = sanitize_text_field($data['diaClaseISO'] ?? $data['diaClase'] ?? '');
$hora_clase = sanitize_text_field($data['hora_clase']  ?? $data['hora']     ?? '');
$opciones   = sanitize_textarea_field($data['opciones_adicionales'] ?? '');
error_log("checkout.php: tipo/fecha/hora -> tipo={$tipo}, dia={$dia_clase}, hora={$hora_clase}");
error_log("checkout.php: opciones_adicionales -> {$opciones}");

// 9) Sanitizar y normalizar promo_code de usuario
$promo_code = isset($data['promo_code'])
    ? strtoupper(trim(sanitize_text_field($data['promo_code'])))
    : '';
error_log("checkout.php: promo_code recibido -> '{$promo_code}'");

// === VALIDACIÓN DE BONO (no consume aquí) ===
$promo_valid   = false;
$promo_info    = '';
$promo_id      = null;

$tabla_codes = $wpdb->prefix . 'montaventura_promo_codes';
$codes_table_exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $tabla_codes) );

// Normaliza tipo elegido en el paso 3
$tipo_interno = strtolower(trim($data['tipo_actividad'] ?? $data['tipo'] ?? ''));

// Permitimos bono SOLO en clases de 1 hora C/P
if ($promo_code !== '') {
  if (!preg_match('/^1hora[cp]$/', $tipo_interno)) {
    wp_send_json_error('Los bonos solo valen para una clase individual de 1 hora.', 400);
  }

  // Modalidad esperada a partir del tipo
  $expected_mod = (substr($tipo_interno, -1) === 'c') ? 'colectiva' : 'particular';

  if ($codes_table_exists) {
    // Busca bono con usos y modalidad exacta o 'mixto'
    $promo = $wpdb->get_row($wpdb->prepare(
      "SELECT id, code, modalidad, email, uses_remaining
         FROM {$tabla_codes}
        WHERE code = %s
          AND uses_remaining > 0
          AND (LOWER(modalidad) = %s OR LOWER(modalidad) = 'mixto')",
      strtoupper(trim($promo_code)),
      $expected_mod
    ));

    if (!$promo) {
      wp_send_json_error('Código inválido, agotado o no válido para esta modalidad.', 400);
    }

    // Si está ligado a email, debe coincidir
    if (!empty($promo->email) && strcasecmp($promo->email, $email) !== 0) {
      wp_send_json_error('Este bono está asociado a otro email.', 400);
    }

    // ✅ Solo marcamos como válido y guardamos info; NO consumimos aún
    $promo_valid = true;
    $promo_info  = $promo->modalidad ?: 'mixto';
    $promo_id    = (int) $promo->id;
  }
}

// 10.x) Validar que, si es clase COLECTIVA, no haya ya reservas con otro grupo
if ( preg_match('/C$/', $tipo) ) {
    $count_diff = $wpdb->get_var(
        $wpdb->prepare(
            "
            SELECT COUNT(*)
              FROM {$wpdb->posts} p
              JOIN {$wpdb->postmeta} pm_d
                ON pm_d.post_id = p.ID
               AND pm_d.meta_key   = 'diaClase'
               AND pm_d.meta_value = %s
              JOIN {$wpdb->postmeta} pm_h
                ON pm_h.post_id = p.ID
               AND pm_h.meta_key   = 'hora_clase'
               AND pm_h.meta_value = %s
              JOIN {$wpdb->postmeta} pm_g
                ON pm_g.post_id = p.ID
               AND pm_g.meta_key   = 'nivel_grupo'
               AND pm_g.meta_value != %d
              JOIN {$wpdb->postmeta} pm_a
                ON pm_a.post_id = p.ID
               AND pm_a.meta_key   = 'actividad_id'
               AND pm_a.meta_value = %d
             WHERE p.post_type   = 'mtv_reserva'
               AND p.post_status = 'publish'
            ",
            $dia_clase,
            $hora_clase,
            $nivel_grupo,
            $actividad_id
        )
    );
    if ( $count_diff > 0 ) {
        wp_send_json_error(
            'En esa clase colectiva ya hay un grupo de nivel diferente. Elige otra fecha u hora.',
            400
        );
    }
}

// 11) Crear la sesión de Stripe
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

try {
    // Metadatos (incluye WP post_id y opciones)
 // ... justo donde construyes $metadata = [ ... ] en checkout.php
$metadata = [
  // (lo que ya tienes)
  'actividad_id'        => (string)$actividad_id,
  'actividad_post_id'   => (string)$act->post_id,
  'tipo_actividad'      => $tipo,
  'diaClase'            => $dia_clase,
  'hora_clase'          => $hora_clase,
  'nombre'              => $nombre,
  'apellidos'           => $apellidos,
  'email'               => $email,
  'telefono'            => $telefono,
  'dni'                 => $dni,
  'fecha_nacimiento'    => $fecha_nac,          // para reconstruir si hiciera falta
  'edad'                => (string)$edad,       // número calculado
  'edad_group'          => $edad_group,
  'modalidad'           => $modalidad,
  'altura'              => ($altura === null ? '' : (string)$altura),
  'botas'               => ($botas  === null ? '' : (string)$botas),
  'nivel'               => $nivel_code,
  'nivel_grupo'         => (string)$nivel_grupo,
  'opciones_adicionales'=> $opciones,
  'fecha_inicio'        => (string)$act->fecha_inicio,
  'importe_total_eur'   => number_format($importe_total_eur, 2, '.', ''),
];


// Tutor si menor
if ($edad < 18) {
    $metadata['nombre_tutor']   = $nombre_tutor;
    $metadata['email_tutor']    = $email_tutor;
    $metadata['telefono_tutor'] = $telefono_tutor;
}
// Bono
if ($promo_valid) {
    $metadata['used_promo_code'] = $promo_code;
    $metadata['promo_info']      = $promo_info;
}

if (isset($data['importe_total_eur']) && $data['importe_total_eur'] !== '') {
    $metadata['importe_total_eur'] = str_replace(',', '.', (string)$data['importe_total_eur']);
}

    // Totales para que success.php muestre el precio completo
// 10) Validar promo_code y decidir importe (tu bloque actual)...
// Deja tu lógica de bonos como la tienes, pero OJO con el momento de decrementar el bono.
// Ideal: NO decrementar aquí; mejor en webhook o success.php. Si lo dejas aquí, corres el riesgo de "quemarlo" si cancelan.

// === IMPORTES (una sola vez, tras validar el bono) ===
$importe_total_eur = isset($data['importe_total_eur'])
  ? (float) str_replace(',', '.', (string)$data['importe_total_eur'])
  : 0.0;

$initial_cents = (int) round(((float)($data['amount_eur'] ?? 0)) * 100);
$amount_cents  = $promo_valid ? 0 : max(0, $initial_cents);
$reserva_key = wp_generate_password(16, false); // idempotencia
// === METADATA (una sola vez) ===
$metadata = [
  'actividad_id'      => (string)$actividad_id,
  'actividad_post_id' => (string)$act->post_id,
  'tipo_actividad'    => $tipo,               // aquí puede venir '1horaC' o '1horaP'
  'diaClase'          => $dia_clase,
  'hora_clase'        => $hora_clase,
  'nombre'            => $nombre,
  'apellidos'         => $apellidos,
  'email'             => $email,
  'telefono'          => $telefono,
  'dni'               => $dni,
  'fecha_nacimiento'  => $fecha_nac,
  'edad'              => (string)$edad,
  'edad_group'        => $edad_group,
  'modalidad'         => (substr(strtolower($tipo), -1) === 'c') ? 'colectiva' : 'particular', // informativo
  'altura'            => ($altura === null ? '' : (string)$altura),
  'botas'             => ($botas  === null ? '' : (string)$botas),
  'nivel'             => $nivel_code,
  'nivel_grupo'       => (string)$nivel_grupo,
  'opciones_adicionales' => $opciones,
  'fecha_inicio'      => (string)($act->fecha_inicio ?? ''),
  'importe_total_eur' => number_format($importe_total_eur, 2, '.', ''),
  'importe_mostrado'  => sanitize_text_field($data['importe_mostrado'] ?? ''),
  'used_promo_code' => $promo_valid ? strtoupper(trim($promo_code)) : '',
  'promo_info'      => $promo_info,
  'promo_id'        => $promo_id ? (string)$promo_id : '',
  'reserva_key'     => $reserva_key,
];

if ($edad < 18) {
  $metadata['nombre_tutor']   = $nombre_tutor;
  $metadata['email_tutor']    = $email_tutor;
  $metadata['telefono_tutor'] = $telefono_tutor;
}
if ($promo_valid) {
  $metadata['used_promo_code'] = strtoupper(trim($promo_code));
  $metadata['promo_info']      = $promo_info; // 'colectiva' | 'particular' | 'mixto'
}

// === Si el importe final es 0€ (bono) => sin Stripe ===
if ($amount_cents === 0) {
  $token = wp_generate_password(20, false);
  $metadata['_free'] = 1; // marca
  set_transient("mtv_free_{$token}", $metadata, 15 * MINUTE_IN_SECONDS);
  $success = site_url("/wp-content/plugins/montaventura/templates/success.php?free={$token}");
  echo json_encode(['redirect' => $success]);
  exit;
}

// === Importe > 0 => crear sesión de Stripe y devolver sessionId ===
$session = \Stripe\Checkout\Session::create([
    'mode'                 => 'payment',
    'payment_method_types' => ['card'],
    'line_items'           => [[
        'price_data' => [
            'currency'     => 'eur',
            'product_data' => ['name' => "Reserva Montaventura – {$nombre}"],
            'unit_amount'  => $amount_cents,
        ],
        'quantity' => 1,
    ]],
    'customer_email'      => $email,
    'payment_intent_data' => ['metadata' => $metadata],
    'metadata'            => $metadata,
    'success_url'         => site_url("/wp-content/plugins/montaventura/templates/success.php?session_id={CHECKOUT_SESSION_ID}"),
    'cancel_url'          => site_url("/wp-content/plugins/montaventura/templates/cancel.php?session_id={CHECKOUT_SESSION_ID}"),
]);

echo json_encode(['id' => $session->id]);
exit;

} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log("EXCEPCIÓN Stripe API: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>'Stripe API error: '.$e->getMessage()]);
    exit;
} catch (\Exception $e) {
    error_log("EXCEPCIÓN genérica: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>'Error genérico: '.$e->getMessage()]);
    exit;
}