<?php
// create-promo-table.php
// Script para eliminar y (re)crear la tabla montaventura_promo_codes
// con el esquema normalizado de tipos de código.

require_once __DIR__ . '/../../../wp-load.php';
if ( ! defined('ABSPATH') ) {
    exit( 'Acceso directo denegado.' );
}
global $wpdb;

// Nombre de la tabla con prefijo WP
$table   = $wpdb->prefix . 'montaventura_promo_codes';
// Charset y collate según tu instalación
$charset = $wpdb->get_charset_collate();

// 1) Si existe, la eliminamos completamente (¡pierdes todos los datos anteriores!)
$wpdb->query( "DROP TABLE IF EXISTS {$table};" );

// 2) Creamos de nuevo con esquema normalizado
$sql = "CREATE TABLE {$table} (
    id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    email            VARCHAR(100)            NOT NULL,
    code             VARCHAR(50)             NOT NULL,
    type             ENUM(
                        'reserva',
                        'bono_colectivas',
                        'bono_particulares'
                      ) NOT NULL,
    uses_remaining   INT(11)                 NOT NULL DEFAULT 0,
    created_at       DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY email_code (email, code)
) {$charset};";

// 3) Ejecutamos la creación
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta( $sql );

// 4) Mensaje de confirmación
echo "<p>Tabla `<strong>{$table}</strong>` eliminada y recreada correctamente con tipos normalizados.</p>";
