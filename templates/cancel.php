<?php
// 1) Arrancar WordPress
// Desde: /wp-content/plugins/montaventura/templates/cancel.php
require_once __DIR__ . '/../../../../wp-load.php';
defined('ABSPATH') || exit;

// 2) Mostrar errores en pantalla (solo para depuración)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 3) Cargar Stripe y configuración
require_once dirname(__DIR__) . '/stripe-config.php';    // define STRIPE_SECRET_KEY
require_once dirname(__DIR__) . '/vendor/autoload.php';
\Stripe\Stripe::setApiKey( STRIPE_SECRET_KEY );

// 4) Leer el session_id de la URL
$session_id = sanitize_text_field( $_GET['session_id'] ?? '' );

if ( $session_id ) {
    try {
        // 5) Recuperar la sesión de Checkout (metadata + datos opcionales)
        $session = \Stripe\Checkout\Session::retrieve(
            $session_id,
            ['expand'=>['customer_details']]
        );

        // 6) Extraer datos (priorizamos customer_details, si no metadata)
        $customer = $session->customer_details;
        $nombre   = $customer->name    ?? $session->metadata->nombre   ?? 'Sin nombre';
        $email    = $customer->email   ?? $session->customer_email     ?? 'Sin email';
        $telefono = $customer->phone   ?? $session->metadata->telefono ?? '';
        $total    = ($session->amount_total / 100) ?? 0;

        // 7) Insertar en la tabla con estado "cancelado"
        global $wpdb;
        $tabla = $wpdb->prefix . 'montaventura_pagos';
        $wpdb->insert(
            $tabla,
            [
                'nombre'             => $nombre,
                'email'              => $email,
                'telefono'           => $telefono,
                'importe_total'      => $total,
                'importe_pagado'     => 0,
                'importe_pendiente'  => $total,
                'fecha_primer_pago'  => current_time('mysql'),
                'fecha_segundo_pago' => null,
                'estado'             => 'cancelado',
            ],
            [ '%s','%s','%s','%f','%f','%f','%s','%s','%s' ]
        );

    } catch (\Exception $e) {
        error_log( 'Stripe cancel.php error: ' . $e->getMessage() );
        // seguimos adelante, solo mostramos la página de cancelación
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Pago Cancelado – Montaventura</title>
  <link rel="stylesheet"
        href="<?php echo plugins_url('../assets/css/form-style.css', __FILE__); ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo plugins_url('../img/Icono-Montaventura.png', __FILE__); ?>">
</head>
<body>
  <div class="layout">
    <main class="contenido">
      <h1>⚠️ Pago Cancelado</h1>
      <p>Has cancelado el proceso de pago.</p>
      <a href="<?php echo esc_url( home_url() ); ?>" class="boton-inscripcion">
        Volver al inicio
      </a>
    </main>
  </div>
</body>
</html>
