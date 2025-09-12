<?php
require_once __DIR__ . '/vendor/autoload.php';

// stripe-config.php
if (!defined('ABSPATH')) {
    exit;
}

// Verifica que la librería de Stripe esté correctamente cargada
if (!class_exists('Stripe\Stripe')) {
    error_log("Error: La clase Stripe\\Stripe no está cargada.");
    http_response_code(500);
    exit('Error: La clase Stripe\\Stripe no está cargada.');
}

// =======================
// Configuración de Stripe
// =======================

// Las claves deben definirse en wp-config.php
if (!defined('STRIPE_SECRET_KEY') || !defined('STRIPE_PUBLISHABLE_KEY')) {
    error_log("Error: faltan las claves STRIPE_SECRET_KEY o STRIPE_PUBLISHABLE_KEY en wp-config.php");
    http_response_code(500);
    exit('Error: configuración incompleta de Stripe.');
}

// Establece la clave secreta
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// Para depuración opcional
if (class_exists('Stripe\Webhook')) {
    error_log("Stripe está correctamente cargado.");
} else {
    error_log("Error: Stripe NO está cargado.");
}

// ============================
// Permisos de la carpeta vendor
// ============================
$vendor_path = __DIR__ . '/vendor';
if (file_exists($vendor_path)) {
    chmod($vendor_path, 0755);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($vendor_path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        chmod($item, is_dir($item) ? 0755 : 0644);
    }
}
