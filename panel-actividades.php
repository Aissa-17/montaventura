<?php
/*
Plugin Name: Panel de Actividades Avanzado
Description: 
  Listado dinámico de actividades con diseño moderno, que permite asignar horarios y plazas
  (un único horario/plazas para Excursión, o varios “slots” para Clases), y sincroniza 
  automáticamente en una tabla personalizada en la base de datos. Además añade un metabox
  y columnas personalizadas para el Custom Post Type “mtv_reserva”, mostrando todos los datos
  guardados de la inscripción (alumno, tutor, nivel, extras, observaciones, etc.).
Version: 1.2
Author: Tera Software
*/

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * -------------------------------------------------------------------------
 * 0) Si usas Stripe, incluye aquí tu configuración. Siempre se carga.
 * -------------------------------------------------------------------------
 */
require_once plugin_dir_path(__FILE__) . 'stripe-config.php';

// Al activar el plugin, crea la tabla de códigos promocionales
//register_activation_hook( __FILE__, 'mtv_create_promo_codes_table' );
/*function mtv_create_promo_codes_table() {
    global $wpdb;
    $table      = $wpdb->prefix . 'montaventura_promo_codes';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
      id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      email       VARCHAR(100)        NOT NULL,
      code        VARCHAR(50)         NOT NULL,
      type        ENUM('reserva','bono') NOT NULL,
      info        VARCHAR(50)         NULL,
      used        TINYINT(1)          NOT NULL DEFAULT 0,
      created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY  email_code (email, code)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}*/


/**
 * -------------------------------------------------------------------------
 * 1) Registrar el Custom Post Type "actividad"
 * -------------------------------------------------------------------------
 */
add_action( 'init', function() {
    register_post_type('actividad', [
        'labels' => [
            'name'          => 'Actividades',
            'singular_name' => 'Actividad',
        ],
        'public'       => true,
        'menu_icon'    => 'dashicons-tickets-alt',
        'supports'     => ['title','thumbnail'],
        'show_in_rest' => true,
        'has_archive'  => true,
        'rewrite'      => ['slug' => 'actividades'],
    ]);
});

register_activation_hook( __FILE__, 'mtv_create_promo_table' );
function mtv_create_promo_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'montaventura_promo_codes';

    $sql = "
    CREATE TABLE {$table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(100) NOT NULL,
        code  VARCHAR(50)  NOT NULL,
        type  VARCHAR(20)  NOT NULL,  -- 'reserva' | 'bono'
        modalidad ENUM('colectiva','particular','mixto') NOT NULL DEFAULT 'mixto',
        uses_remaining INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY email_code (email, code)
    ) {$charset_collate};
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Relleno para filas antiguas (si no tenían modalidad)
    $wpdb->query("UPDATE {$table} SET modalidad='mixto' WHERE modalidad IS NULL OR modalidad=''");
}
// Migra/asegura el esquema de la tabla de cupones si viene de una versión antigua
add_action('admin_init', 'mtv_migrate_promo_table_safely');
function mtv_migrate_promo_table_safely() {
    global $wpdb;
    $table = $wpdb->prefix . 'montaventura_promo_codes';

    // Si la tabla no existe, nada que migrar
    $exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table) );
    if (!$exists) return;

    // 1) Añadir columna modalidad si falta
    $has_modalidad = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'modalidad'");
    if (!$has_modalidad) {
        $wpdb->query("ALTER TABLE {$table}
                      ADD COLUMN modalidad ENUM('colectiva','particular','mixto')
                      NOT NULL DEFAULT 'mixto' AFTER type");
    }

    // 2) Añadir columna uses_remaining si falta y rellenarla
    $has_uses = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'uses_remaining'");
    if (!$has_uses) {
        $wpdb->query("ALTER TABLE {$table}
                      ADD COLUMN uses_remaining INT NOT NULL DEFAULT 1 AFTER modalidad");

        // Si existe la columna antigua 'used' (0/1) la usamos para pre-rellenar
        $has_used = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'used'");
        if ($has_used) {
            // reserva: 1 uso si no se ha usado; 0 si ya está usado. bono: dejamos 1 (ajústalo si quieres otro valor por defecto)
            $wpdb->query("UPDATE {$table}
                          SET uses_remaining = CASE
                              WHEN type='reserva' THEN IF(used=1,0,1)
                              ELSE 1
                          END");
        }
    }
}

// Genera códigos tipo BONO-AB12CD34 (sin 0/O/1/I para evitar confusiones)
function mtv_generate_promo_code($len = 8, $prefix = 'BONO') {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i=0; $i<$len; $i++) $code .= $alphabet[random_int(0, strlen($alphabet)-1)];
    return $prefix ? ($prefix.'-'.$code) : $code;
}

function mtv_insert_promo_code($email, $type = 'bono', $uses = 1, $code = null, $modalidad = 'mixto') {
    global $wpdb;
    $table = $wpdb->prefix . 'montaventura_promo_codes';

    $email     = sanitize_email($email);
    $type      = in_array($type, ['bono','reserva'], true) ? $type : 'bono';
    $uses      = max(1, (int)$uses);
    $modalidad = in_array($modalidad, ['colectiva','particular','mixto'], true) ? $modalidad : 'mixto';
    $code      = $code ? sanitize_text_field($code) : mtv_generate_promo_code();

    // Evitar colisiones (email,code)
    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE email=%s AND code=%s", $email, $code));
    if ($exists) $code = mtv_generate_promo_code();

    $ok = $wpdb->insert(
        $table,
        [
            'email'          => $email,
            'code'           => $code,
            'type'           => $type,
            'modalidad'      => $modalidad,
            'uses_remaining' => $type === 'reserva' ? 1 : $uses,
            'created_at'     => current_time('mysql', 1),
        ],
        ['%s','%s','%s','%s','%d','%s']
    );
    if (false === $ok) return new WP_Error('db', 'No se pudo crear el código: '.$wpdb->last_error);

    return (object)[
        'id'              => $wpdb->insert_id,
        'email'           => $email,
        'code'            => $code,
        'type'           => $type,
        'modalidad'      => $modalidad,
        'uses_remaining' => ($type === 'reserva' ? 1 : $uses),
    ];
}


/**
 * -------------------------------------------------------------------------
 * 2) Crear la tabla personalizada al activar el plugin
 * -------------------------------------------------------------------------
 */
register_activation_hook( __FILE__, function() {
    global $wpdb;
    $tabla           = $wpdb->prefix . 'actividades';
    $charset_collate = $wpdb->get_charset_collate();

    // Creamos la tabla con campos esenciales:
    $sql = "CREATE TABLE $tabla (
        id INT(11) NOT NULL AUTO_INCREMENT,
        post_id BIGINT(20) UNSIGNED NOT NULL,
        fecha_inicio DATE DEFAULT NULL,
        hora VARCHAR(255) DEFAULT NULL,
        lugar VARCHAR(255) DEFAULT NULL,
        descripcion TEXT DEFAULT NULL,
        precio DECIMAL(10,2) DEFAULT 0.00,
        url_inscripcion TEXT DEFAULT NULL,
        session_id VARCHAR(255) DEFAULT NULL,
        tipo_actividad VARCHAR(50) DEFAULT NULL,
        plazas INT(11) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT NULL,
        updated_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        KEY post_id (post_id),
        CONSTRAINT {$tabla}_ibfk_1 FOREIGN KEY (post_id)
          REFERENCES {$wpdb->prefix}posts(ID)
          ON DELETE CASCADE ON UPDATE CASCADE
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Ajustamos el índice de session_id (solo en caso de que existiera uno antiguo)
    $wpdb->query( "ALTER TABLE {$tabla} DROP INDEX session_id" );
    $wpdb->query( "ALTER TABLE {$tabla} 
        MODIFY session_id VARCHAR(255) NULL DEFAULT NULL" );
});


/**
 * -------------------------------------------------------------------------
 * 3) Registrar la metabox “Datos de la actividad” y su callback
 * -------------------------------------------------------------------------
 */
add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'mtv_datos_actividad',               // ID del meta-box
        'Datos de la actividad',             // Título del meta-box
        'mtv_render_meta_box_callback',      // Callback que imprime el HTML
        'actividad',                         // CPT sobre el que aparece
        'normal',
        'high'
    );
});

/**
 * Callback: Imprime el HTML del meta-box “Datos de la actividad”.
 */
function mtv_render_meta_box_callback( $post ) {
    // 1) Nonce para seguridad
    wp_nonce_field( 'mtv_datos_actividad_nonce_action', 'mtv_datos_actividad_nonce' );

    // 2) Recuperar valores previos (si existen)
    $tipo_actividad    = get_post_meta( $post->ID, '_mtv_tipo_actividad', true );
    $fecha_inicio      = get_post_meta( $post->ID, '_mtv_fecha_inicio', true );
    $lugar             = get_post_meta( $post->ID, '_mtv_lugar', true );
    $precio            = get_post_meta( $post->ID, '_mtv_precio', true );
    $descripcion       = get_post_meta( $post->ID, '_mtv_descripcion', true );
    $excursion_hora    = get_post_meta( $post->ID, '_mtv_excursion_hora', true );
    $excursion_plazas  = get_post_meta( $post->ID, '_mtv_excursion_plazas', true );
    $clases_slots      = get_post_meta( $post->ID, '_mtv_clases_slots', true );
    $precio_texto = get_post_meta( $post->ID, '_mtv_precio_texto', true );
    $precio_base = get_post_meta( $post->ID, '_mtv_precio_base', true );
    $orden_listado = get_post_meta( $post->ID, '_mtv_orden', true );
    $curso_anual = get_post_meta( $post->ID, '_mtv_curso_anual', true );


    if ( ! is_array( $clases_slots ) ) {
        $clases_slots = [];
    }
    $bono_slots = get_post_meta( $post->ID, '_mtv_bono_slots', true );
    if ( ! is_array( $bono_slots ) ) {
        $bono_slots = [];
    }

    // 0) (Opcional) forzar selección desde querystring tras reload
    $tipo_get = isset( $_GET['mtv_tipo_actividad'] )
        ? sanitize_text_field( wp_unslash( $_GET['mtv_tipo_actividad'] ) )
        : '';
    if ( in_array( $tipo_get, [ 'excursion', 'clases', 'bono' ], true ) ) {
        $tipo_actividad = $tipo_get;
    }

    ?>

    <p>
      <label for="mtv_tipo_actividad"><strong>Tipo de actividad:</strong></label><br>
      <select name="mtv_tipo_actividad" id="mtv_tipo_actividad">
        <option value="">— Selecciona —</option>
        <option value="excursion" <?php selected( $tipo_actividad, 'excursion' ); ?>>Viajes y Excursiones</option>
        <option value="clases"    <?php selected( $tipo_actividad, 'clases' );    ?>>Cursos</option>
        <option value="bono"      <?php selected( $tipo_actividad, 'bono' );      ?>>Bono</option>
      </select>
    </p>
<p>
  <label for="mtv_orden"><strong>Orden en el listado:</strong></label><br>
  <input type="number" min="0" step="1" id="mtv_orden" name="mtv_orden"
         style="width:120px"
         value="<?php echo esc_attr( $orden_listado !== '' ? intval($orden_listado) : '' ); ?>">
  <small style="color:#666">Se ordena de menor (1) a mayor. Vacío = al final.</small>
</p>

    <p id="wrap_fecha_inicio">
  <label for="mtv_fecha_inicio"><strong>Fecha inicio:</strong></label><br>
  <input type="date" name="mtv_fecha_inicio" id="mtv_fecha_inicio"
         value="<?php echo esc_attr( $fecha_inicio ); ?>" />
</p>

<script>
jQuery(function($){
  function toggleFecha(){
    const esBono = $('#mtv_tipo_actividad').val()==='bono';
    $('#wrap_fecha_inicio').toggle(!esBono);
    if (esBono) { $('#mtv_fecha_inicio').val(''); }
  }
  toggleFecha();
  $('#mtv_tipo_actividad').on('change', toggleFecha);
});
</script>


    <p>
      <label for="mtv_lugar"><strong>Lugar:</strong></label><br>
      <input type="text" name="mtv_lugar" id="mtv_lugar" style="width:100%;"
             value="<?php echo esc_attr( $lugar ); ?>" />
    </p>

    <p>
  <label for="mtv_precio"><strong>Precio de la RESERVA (se cobra ahora) (€):</strong></label><br>
  <input type="number" min="0" step="0.01" name="mtv_precio" id="mtv_precio"
         value="<?php echo esc_attr( $precio ); ?>" />
</p>

<p>
  <label for="mtv_precio_base"><strong>Precio BASE de la actividad (para cálculo total) (€):</strong></label><br>
  <input type="number" min="0" step="0.01" name="mtv_precio_base" id="mtv_precio_base"
         value="<?php echo esc_attr( $precio_base ); ?>" />
</p>

<p>
  <label for="mtv_precio_texto"><strong>Precio (texto portada):</strong></label>
  <small style="color:#666">Ej.: “A partir de 40 €”</small><br>
  <input type="text" name="mtv_precio_texto" id="mtv_precio_texto" style="width:100%;"
         value="<?php echo esc_attr( $precio_texto ); ?>" />
</p>

    <p>
      <label for="mtv_descripcion"><strong>Descripción:</strong></label><br>
      <textarea name="mtv_descripcion" id="mtv_descripcion" rows="4" style="width:100%;"><?php
          echo esc_textarea( $descripcion );
      ?></textarea>
    </p>

    <!-- Paso 6: Bloque condicional “Horario + Plazas” según tipo -->
    <div id="mtv_bloque_horario_plazas">

      <?php if ( $tipo_actividad === 'excursion' ) : ?>
        <h4>Datos para <em>Excursión</em>:</h4>
        <p>
          <label for="mtv_excursion_hora"><strong>Hora:</strong></label><br>
          <input type="time" name="mtv_excursion_hora" id="mtv_excursion_hora"
                 value="<?php echo esc_attr( $excursion_hora ); ?>" />
        </p>
        <p>
          <label for="mtv_excursion_plazas"><strong>Plazas:</strong></label><br>
          <input type="number" min="1" step="1" name="mtv_excursion_plazas" id="mtv_excursion_plazas"
                 value="<?php echo esc_attr( $excursion_plazas ); ?>" />
        </p>

       <?php
  // Opciones adicionales estructuradas (nombre + precio)
  $excursion_extras_struct = get_post_meta( $post->ID, '_mtv_excursion_extras_struct', true );
  if ( ! is_array( $excursion_extras_struct ) ) {
      $excursion_extras_struct = [];
  }
?>
<h4>Opciones adicionales</h4>

<table class="widefat fixed striped" id="mtv_excursion_extras_table" style="max-width:620px;">
  <thead>
    <tr>
      <th style="width:60%;">Nombre de la opción</th>
      <th style="width:25%;">Precio (€)</th>
      <th style="width:15%;">Acción</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ( $excursion_extras_struct as $i => $ex ) : ?>
      <tr>
        <td>
          <input type="text"
                 name="mtv_excursion_extras[<?php echo $i; ?>][label]"
                 value="<?php echo esc_attr( $ex['label'] ?? '' ); ?>"
                 placeholder="Transporte, Comida, Seguro..." style="width:100%;">
        </td>
        <td>
          <input type="number" step="0.01"
                 name="mtv_excursion_extras[<?php echo $i; ?>][price]"
                 value="<?php echo esc_attr( $ex['price'] ?? '' ); ?>"
                 placeholder="0.00 (negativo = descuento)"  style="width:100%;">
        </td>
        <td>
          <button class="button mtv-remove-extra" type="button">Eliminar</button>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<p>
  <button type="button" class="button button-primary" id="mtv_add_extra">
    Añadir opción
  </button>
</p>
<p style="color:#666">El precio de estas opciones se sumará al “Importe total” mostrado al usuario, pero Stripe cobrará el precio base de la excursión.</p>


      <?php elseif ( $tipo_actividad === 'bono' ) : ?>
        <h4>Slots para <em>Bono</em> (edad, nivel, modalidad, día, hora, plazas):</h4>
<table class="widefat fixed striped" id="mtv_bono_slots_table">
  <thead>
    <tr>
      <th>Edad</th>
      <th>Nivel</th>
      <th>Modalidad</th>
      <th>Día</th>
      <th>Repetir</th>
      <th>Hora</th>
      <th>Plazas</th>
      <th>Acción</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ( $bono_slots as $i => $slot ) : ?>
    <tr>
      <td>
        <select name="mtv_bono_slots[<?php echo $i; ?>][edad]">
          <option value="infantil"  <?php selected($slot['edad'],'infantil'); ?>>Infantil</option>
          <option value="adulto"    <?php selected($slot['edad'],'adulto');   ?>>Adulto</option>
           <option value="mixto"     <?php selected($slot['edad'],'mixto');    ?>>Mixto</option>
        </select>
      </td>
      <td>
        <select name="mtv_bono_slots[<?php echo $i; ?>][nivel]">
          <option value="1" <?php selected($slot['nivel'],'1'); ?>>Nivel 1</option>
          <option value="2" <?php selected($slot['nivel'],'2'); ?>>Nivel 2</option>
        </select>
      </td>
      <td>
        <select name="mtv_bono_slots[<?php echo $i; ?>][modalidad]">
          <option value="particular" <?php selected($slot['modalidad'],'particular'); ?>>Particular</option>
          <option value="colectiva"  <?php selected($slot['modalidad'],'colectiva');  ?>>Colectiva</option>
        </select>
      </td>
      <td>
        <input type="date"
               name="mtv_bono_slots[<?php echo $i; ?>][date]"
               value="<?php echo esc_attr( $slot['date'] ); ?>" />
      </td>
      <td style="padding-left: 3em;">
  <input
    type="checkbox"
    name="mtv_bono_slots[<?php echo $i; ?>][repeat]"
    value="1"
    <?php checked( $slot['repeat'] ?? '', '1' ); ?>
  />
</td>
      <td>
        <input type="time"
               name="mtv_bono_slots[<?php echo $i; ?>][hora]"
               value="<?php echo esc_attr( $slot['hora'] ); ?>" />
      </td>
      <td>
        <input type="number" min="1" step="1"
               name="mtv_bono_slots[<?php echo $i; ?>][plazas]"
               value="<?php echo esc_attr( intval($slot['plazas']) ); ?>" />
      </td>
      <td>
        <button class="button mtv-remove-bono" type="button">Eliminar</button>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<p>
  <button type="button" class="button button-primary" id="mtv_add_bono_slot">
    Añadir slot
  </button>
</p>
      <?php elseif ( $tipo_actividad === 'clases' ) :

    // 1) Inicializar siempre $clases_dias para evitar warnings
    $clases_dias = get_post_meta( $post->ID, '_mtv_clases_dias', true );
    if ( ! is_array( $clases_dias ) ) {
        $clases_dias = [];
    }

?>
<?php
$usar_tarifa_ndias = get_post_meta( $post->ID, '_mtv_tarifa_por_num_dias', true );
$precio_1dia = get_post_meta( $post->ID, '_mtv_precio_1dia', true );
$precio_2dias = get_post_meta( $post->ID, '_mtv_precio_2dias', true );
$precio_3dias = get_post_meta( $post->ID, '_mtv_precio_3dias', true );
$precio_4dias = get_post_meta( $post->ID, '_mtv_precio_4dias', true );
?>
<h4>Curso anual</h4>
<label style="display:inline-flex;gap:.5rem;align-items:center">
  <input type="checkbox" name="mtv_curso_anual" value="1" <?php checked($curso_anual,'1'); ?>>
  Este curso es anual (27 sesiones automáticas)
</label>
<p style="color:#666;margin:.5rem 0 1rem">
  Si marcas esto, en el formulario de inscripción no se pedirá calendario:
  para Nivel 1 se generan los próximos <strong>27 sábados</strong>, y para Nivel 2 los próximos
  <strong>27 domingos</strong>.
</p>
<h4>Tarifas por nº de fechas (opcional)</h4>
<label>
  <input type="checkbox" name="mtv_tarifa_por_num_dias" value="1" <?php checked( $usar_tarifa_ndias, '1' ); ?>>
  Activar precios por nº de días
</label>
<div style="display:grid;grid-template-columns:160px 1fr;gap:8px;margin-top:8px;max-width:520px;">
  <label>1 fecha (€):</label>
  <input type="number" step="0.01" min="0" name="mtv_precio_1dia"
         value="<?php echo esc_attr( $precio_1dia ); ?>">
  <label>2 fechas (€):</label>
  <input type="number" step="0.01" min="0" name="mtv_precio_2dias"
         value="<?php echo esc_attr( $precio_2dias ); ?>">
  <label>3 fechas (€):</label>
  <input type="number" step="0.01" min="0" name="mtv_precio_3dias"
         value="<?php echo esc_attr( $precio_3dias ); ?>">
  <label>4 fechas (€):</label>
  <input type="number" step="0.01" min="0" name="mtv_precio_4dias" value="<?php echo esc_attr( $precio_4dias ); ?>">
</div>
<p style="color:#666;margin-top:6px;">
  Si no marcas la casilla o dejas algún precio vacío, se usará el precio general del curso.
</p>

  <h4>Días + plazas + nivel para <em>Clases</em>:</h4>
  <table class="widefat fixed striped" id="mtv_clases_dias_table">
    <thead>
      <tr>
        <th>Fecha</th>
        <th>Plazas</th>
        <th>Nivel</th>
        <th>Repetir semanal</th> 
        <th>Acción</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ( $clases_dias as $i => $dia_slot ) :
        // nivel por cada fila (fallback a '1')
        $nivel_sel = isset( $dia_slot['nivel'] ) && in_array( $dia_slot['nivel'], ['1','2'], true )
                    ? $dia_slot['nivel']
                    : '1';
        $repeat_checked = isset($dia_slot['repeat']) && $dia_slot['repeat'] === '1' ? 'checked' : '';
      ?>
      <tr>
        <td>
          <input
            type="date"
            name="mtv_clases_dias[<?php echo $i; ?>][date]"
            value="<?php echo esc_attr( $dia_slot['date'] ); ?>"
            min="<?php echo esc_attr( $fecha_inicio ); ?>"
          />
        </td>
        <td>
          <input
            type="number"
            min="1"
            step="1"
            name="mtv_clases_dias[<?php echo $i; ?>][plazas]"
            value="<?php echo esc_attr( intval( $dia_slot['plazas'] ) ); ?>"
          />
        </td>
        <td>
          <select name="mtv_clases_dias[<?php echo $i; ?>][nivel]">
            <option value="1" <?php selected( $nivel_sel, '1' ); ?>>Nivel 1</option>
            <option value="2" <?php selected( $nivel_sel, '2' ); ?>>Nivel 2</option>
          </select>
        </td>
        <td>
    <input
      type="checkbox"
      name="mtv_clases_dias[<?php echo $i;?>][repeat]"
      value="1"
      <?php echo $repeat_checked;?>
    />
  </td>
        <td>
          <button class="button mtv-remove-day" type="button">Eliminar</button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p>
    <button type="button" class="button button-primary" id="mtv_add_day">
      Añadir día
    </button>
  </p>

  <h4>Slots para <em>Clases</em> (solo horas):</h4>
  <table class="widefat fixed striped" id="mtv_clases_slots_table">
    <thead>
      <tr><th>Hora</th><th>Acción</th></tr>
    </thead>
    <tbody>
      <?php
        $clases_slots = get_post_meta( $post->ID, '_mtv_clases_slots', true );
        if ( ! is_array( $clases_slots ) ) {
            $clases_slots = [];
        }
        foreach ( $clases_slots as $j => $slot ) :
      ?>
      <tr>
        <td>
          <input
            type="time"
            name="mtv_clases_slots[<?php echo $j; ?>][hora]"
            value="<?php echo esc_attr( $slot['hora'] ); ?>"
          />
        </td>
        <td>
          <button class="button mtv-remove-slot" type="button">Eliminar</button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p>
    <button type="button" class="button button-primary" id="mtv_add_slot">
      Añadir slot
    </button>
  </p>
<?php endif; ?>


    </div>

    <!-- JS inline para toggle, reload y repeaters -->
<script>
jQuery(function($){
  // 1) Toggle + reload al cambiar tipo
  function togglearBloque(){
    var tipo = $('#mtv_tipo_actividad').val();
    $('#mtv_bloque_horario_plazas')[ (tipo==='excursion'||tipo==='clases'||tipo==='bono') ? 'show' : 'hide' ]();
  }
  togglearBloque();
  $('#mtv_tipo_actividad').on('change', function(){
    var tipo = $(this).val(),
        url  = new URL(window.location.href);
    url.searchParams.set('mtv_tipo_actividad', tipo);
    window.location.href = url;
  });

  // 2) Repeater Bono
  var $tbBono   = $('#mtv_bono_slots_table tbody');

  $('#mtv_add_bono_slot').on('click', function(e){
    e.preventDefault();
    var i = $tbBono.find('tr').length,
        fila = ''
      + '<tr>'
      +   '<td><select name="mtv_bono_slots['+i+'][edad]">'
      +       '<option value="infantil">Infantil</option>'
      +       '<option value="adulto">Adulto</option>'
      +       '<option value="mixto">Mixto</option>'
      +     '</select></td>'
      +   '<td><select name="mtv_bono_slots['+i+'][nivel]">'
      +       '<option value="1">Nivel 1</option>'
      +       '<option value="2">Nivel 2</option>'
      +     '</select></td>'
      +   '<td><select name="mtv_bono_slots['+i+'][modalidad]">'
      +       '<option value="particular">Particular</option>'
      +       '<option value="colectiva">Colectiva</option>'
      +     '</select></td>'
      +    '<td><input type="date" name="mtv_bono_slots['+i+'][date]" /></td>'
      +   '<td><input type="time" name="mtv_bono_slots['+i+'][hora]" /></td>'
      +   '<td><input type="number" min="1" step="1" name="mtv_bono_slots['+i+'][plazas]" /></td>'
      +   '<td><button class="button mtv-remove-bono" type="button">Eliminar</button></td>'
      + '</tr>';
    $tbBono.append(fila);
  });

  $tbBono.on('click','.mtv-remove-bono', function(e){
    e.preventDefault();
    $(this).closest('tr').remove();
    // reindexar
    $tbBono.find('tr').each(function(idx,tr){
      $(tr).find('select[name*="[edad]"]').attr('name','mtv_bono_slots['+idx+'][edad]');
      $(tr).find('select[name*="[nivel]"]').attr('name','mtv_bono_slots['+idx+'][nivel]');
      $(tr).find('select[name*="[modalidad]"]').attr('name','mtv_bono_slots['+idx+'][modalidad]');
      $(tr).find('input[type="date"]').attr('name','mtv_bono_slots['+idx+'][date]');
      $(tr).find('input[type="time"]').attr('name','mtv_bono_slots['+idx+'][hora]');
      $(tr).find('input[type="number"]').attr('name','mtv_bono_slots['+idx+'][plazas]');
    });
  });

  // 3) Repeater Clases: DÍAS con fecha + plazas + nivel
  var $tbDias  = $('#mtv_clases_dias_table tbody'),
      minDateD = '<?php echo esc_js($fecha_inicio); ?>';

  $('#mtv_add_day').on('click', function(e){
    e.preventDefault();
    var idx = $tbDias.find('tr').length,
        fila = ''
      + '<tr>'
      +   '<td><input type="date" name="mtv_clases_dias['+idx+'][date]" min="'+minDateD+'" /></td>'
      +   '<td><input type="number" min="1" step="1" name="mtv_clases_dias['+idx+'][plazas]" /></td>'
      +   '<td><select name="mtv_clases_dias['+idx+'][nivel]">'
      +       '<option value="1">Nivel 1</option>'
      +       '<option value="2">Nivel 2</option>'
      +     '</select></td>'
      +   '<td><button class="button mtv-remove-day" type="button">Eliminar</button></td>'
      + '</tr>';
    $tbDias.append(fila);
  });

  $tbDias.on('click','.mtv-remove-day', function(e){
    e.preventDefault();
    $(this).closest('tr').remove();
    // reindexar
    $tbDias.find('tr').each(function(i,tr){
      $(tr).find('input[type="date"]').attr('name','mtv_clases_dias['+i+'][date]');
      $(tr).find('input[type="number"]').attr('name','mtv_clases_dias['+i+'][plazas]');
      $(tr).find('select').attr('name','mtv_clases_dias['+i+'][nivel]');
    });
  });

  // 4) Repeater Clases: SLOTS solo con hora
  var $tbSlots = $('#mtv_clases_slots_table tbody');

  $('#mtv_add_slot').on('click', function(e){
    e.preventDefault();
    var idx = $tbSlots.find('tr').length,
        fila = ''
      + '<tr>'
      +   '<td><input type="time" name="mtv_clases_slots['+idx+'][hora]" /></td>'
      +   '<td><button class="button mtv-remove-slot" type="button">Eliminar</button></td>'
      + '</tr>';
    $tbSlots.append(fila);
  });

  $tbSlots.on('click','.mtv-remove-slot', function(e){
    e.preventDefault();
    $(this).closest('tr').remove();
    // reindexar
    $tbSlots.find('tr').each(function(i,tr){
      $(tr).find('input[type="time"]').attr('name','mtv_clases_slots['+i+'][hora]');
    });
  });

// 5) Repeater de Opciones adicionales (Excursión)
  var $tbExtras = $('#mtv_excursion_extras_table tbody');
  if ($tbExtras.length) {
    $('#mtv_add_extra').on('click', function(e){
      e.preventDefault();
      var i = $tbExtras.children('tr').length;
     var fila = ''
  + '<tr>'
  +   '<td><select name="mtv_bono_slots['+i+'][edad]">'
  +       '<option value="infantil">Infantil</option>'
  +       '<option value="adulto">Adulto</option>'
  +       '<option value="mixto">Mixto</option>'
  +     '</select></td>'
  +   '<td><select name="mtv_bono_slots['+i+'][nivel]">'
  +       '<option value="1">Nivel 1</option>'
  +       '<option value="2">Nivel 2</option>'
  +     '</select></td>'
  +   '<td><select name="mtv_bono_slots['+i+'][modalidad]">'
  +       '<option value="particular">Particular</option>'
  +       '<option value="colectiva">Colectiva</option>'
  +     '</select></td>'
  +   '<td><input type="date" name="mtv_bono_slots['+i+'][date]" /></td>'
  +   '<td style="text-align:center;"><input type="checkbox" name="mtv_bono_slots['+i+'][repeat]" value="1" /></td>'
  +   '<td><input type="time" name="mtv_bono_slots['+i+'][hora]" /></td>'
  +   '<td><input type="number" min="1" step="1" name="mtv_bono_slots['+i+'][plazas]" /></td>'
  +   '<td><button class="button mtv-remove-bono" type="button">Eliminar</button></td>'
  + '</tr>';
      $tbExtras.append(fila);
    });

    $tbExtras.on('click','.mtv-remove-extra', function(e){
      e.preventDefault();
      $(this).closest('tr').remove();
      // Reindexar nombres
      $tbExtras.children('tr').each(function(idx,tr){
        $(tr).find('input[type="text"]')
             .attr('name', `mtv_excursion_extras[${idx}][label]`);
        $(tr).find('input[type="number"]')
             .attr('name', `mtv_excursion_extras[${idx}][price]`);
      });
    });
  }
});
</script>


    <?php
}


/**
 * -------------------------------------------------------------------------
 * 4) Hook save_post para guardar los metadatos de la metabox
 * -------------------------------------------------------------------------
 */
add_action( 'save_post_actividad', 'mtv_save_meta_box_data', 10, 2 );
function mtv_save_meta_box_data( $post_id, $post ) {
    // a) Evitamos autosaves, revisamos nonce y permisos:
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! isset( $_POST['mtv_datos_actividad_nonce'] ) ) {
        return;
    }
    if ( ! wp_verify_nonce( $_POST['mtv_datos_actividad_nonce'], 'mtv_datos_actividad_nonce_action' ) ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }
    if ( $post->post_type !== 'actividad' ) {
        return;
    }
if ( isset($_POST['mtv_orden']) && $_POST['mtv_orden'] !== '' ) {
    update_post_meta( $post_id, '_mtv_orden', intval($_POST['mtv_orden']) );
} else {
    delete_post_meta( $post_id, '_mtv_orden' );
}
$flag_anual = isset($_POST['mtv_curso_anual']) ? '1' : '0';
update_post_meta( $post_id, '_mtv_curso_anual', $flag_anual );

    // b) Guardamos cada campo:
    if ( isset( $_POST['mtv_tipo_actividad'] ) ) {
        $nuevo_tipo = sanitize_text_field( $_POST['mtv_tipo_actividad'] );
        update_post_meta( $post_id, '_mtv_tipo_actividad', $nuevo_tipo );
    }

    if ( isset( $_POST['mtv_fecha_inicio'] ) ) {
        $fecha = sanitize_text_field( $_POST['mtv_fecha_inicio'] );
        update_post_meta( $post_id, '_mtv_fecha_inicio', $fecha );
    }

    if ( isset( $_POST['mtv_lugar'] ) ) {
        $lugar = sanitize_text_field( $_POST['mtv_lugar'] );
        update_post_meta( $post_id, '_mtv_lugar', $lugar );
    }

    if ( isset( $_POST['mtv_precio'] ) ) {
        $precio = floatval( $_POST['mtv_precio'] );
        update_post_meta( $post_id, '_mtv_precio', $precio );
    }
    if ( isset( $_POST['mtv_precio_base'] ) ) {
    $precio_base = floatval( $_POST['mtv_precio_base'] );
    update_post_meta( $post_id, '_mtv_precio_base', $precio_base );
} else {
    delete_post_meta( $post_id, '_mtv_precio_base' );
}


    if ( isset( $_POST['mtv_descripcion'] ) ) {
        $desc = sanitize_textarea_field( $_POST['mtv_descripcion'] );
        update_post_meta( $post_id, '_mtv_descripcion', $desc );
    }
if ( isset( $_POST['mtv_precio_texto'] ) ) {
    $pt = sanitize_text_field( $_POST['mtv_precio_texto'] );
    update_post_meta( $post_id, '_mtv_precio_texto', $pt );
}

    // c) Guardar campos según “tipo de actividad”:
    $tipo_actividad = get_post_meta( $post_id, '_mtv_tipo_actividad', true );

    if ( $tipo_actividad === 'excursion' ) {
        //  - Excursión: un único par hora/plazas
        if ( isset( $_POST['mtv_excursion_hora'] ) ) {
            $hora = sanitize_text_field( $_POST['mtv_excursion_hora'] );
            update_post_meta( $post_id, '_mtv_excursion_hora', $hora );
        } else {
            delete_post_meta( $post_id, '_mtv_excursion_hora' );
        }
        if ( isset( $_POST['mtv_excursion_plazas'] ) ) {
            $plazas = intval( $_POST['mtv_excursion_plazas'] );
            update_post_meta( $post_id, '_mtv_excursion_plazas', $plazas );
        } else {
            delete_post_meta( $post_id, '_mtv_excursion_plazas' );
        }

        // Opciones adicionales (estructura)
// Opciones adicionales (estructura) — admite negativos (descuentos)
if ( isset($_POST['mtv_excursion_extras']) && is_array($_POST['mtv_excursion_extras']) ) {
    $raw   = $_POST['mtv_excursion_extras'];
    $clean = [];
    foreach ( $raw as $row ) {
        $label = sanitize_text_field( $row['label'] ?? '' );
        // permitir negativos, coma o punto
        $price_raw = str_replace(',', '.', trim($row['price'] ?? ''));
        if ( $label !== '' && $price_raw !== '' && is_numeric($price_raw) ) {
            $price = round((float)$price_raw, 2);
            $clean[] = [ 'label' => $label, 'price' => $price ];
        }
    }
    if ( $clean ) {
        update_post_meta( $post_id, '_mtv_excursion_extras_struct', $clean );
    } else {
        delete_post_meta( $post_id, '_mtv_excursion_extras_struct' );
    }
} else {
    delete_post_meta( $post_id, '_mtv_excursion_extras_struct' );
}

// Elimina el meta viejo de texto libre si existiera
delete_post_meta( $post_id, '_mtv_excursion_extras' );

        // Borramos posibles metadatos de “clases” y “bono”
        delete_post_meta( $post_id, '_mtv_clases_dias' );
        delete_post_meta( $post_id, '_mtv_clases_slots' );
        delete_post_meta( $post_id, '_mtv_bono_slots' );

    } elseif ( $tipo_actividad === 'clases' ) {
    // Guardar nivel de Clase
    if ( isset( $_POST['mtv_nivel_clase'] ) ) {
        $nv = in_array( $_POST['mtv_nivel_clase'], ['1','2'], true )
              ? $_POST['mtv_nivel_clase']
              : '1';
        update_post_meta( $post_id, '_mtv_nivel_clase', $nv );
    } else {
        delete_post_meta( $post_id, '_mtv_nivel_clase' );
    }
        // --- AHORA: mtv_clases_dias es array de arrays con date+plazas
if ( isset($_POST['mtv_clases_dias']) && is_array($_POST['mtv_clases_dias']) ) {
  $raw_days   = $_POST['mtv_clases_dias'];
  $clean_days = [];
  foreach ( $raw_days as $day ) {
    $fecha  = sanitize_text_field($day['date'] ?? '');
    $plazas = intval($day['plazas'] ?? 0);
    $nivel  = in_array($day['nivel'] ?? '', ['1','2'], true) ? $day['nivel'] : '1';
      $repeat = ( isset($day['repeat']) && $day['repeat']==='1' ) ? '1' : '0';
    if ( $fecha && $plazas > 0 ) {
      $clean_days[] = [
        'date'   => $fecha,
        'plazas' => $plazas,
        'nivel'  => $nivel,
        'repeat' => $repeat, 
      ];
    }
  }
  if ( $clean_days ) {
    update_post_meta($post_id,'_mtv_clases_dias',$clean_days);
  } else {
    delete_post_meta($post_id,'_mtv_clases_dias');
  }
} else {
    delete_post_meta( $post_id, '_mtv_clases_dias' );
}

// Slots: ya no llevan plazas, solo hora
if ( isset($_POST['mtv_clases_slots']) && is_array($_POST['mtv_clases_slots']) ) {
    $raw_slots   = $_POST['mtv_clases_slots'];
    $clean_slots = [];
    foreach ( $raw_slots as $slot ) {
        $hora = sanitize_text_field( $slot['hora'] ?? '' );
        if ( $hora ) {
            $clean_slots[] = [ 'hora' => $hora ];
        }
    }
    if ( ! empty( $clean_slots ) ) {
        update_post_meta( $post_id, '_mtv_clases_slots', $clean_slots );
    } else {
        delete_post_meta( $post_id, '_mtv_clases_slots' );
    }
} else {
    delete_post_meta( $post_id, '_mtv_clases_slots' );
}

        // Slots: ya no llevan plazas, solo hora
if ( isset($_POST['mtv_clases_slots']) && is_array($_POST['mtv_clases_slots']) ) {
    $raw_slots   = $_POST['mtv_clases_slots'];
    $clean_slots = [];
    foreach ( $raw_slots as $slot ) {
        $hora = sanitize_text_field( $slot['hora'] ?? '' );
        if ( $hora ) {
            $clean_slots[] = [ 'hora' => $hora ];
        }
    }
    if ( ! empty( $clean_slots ) ) {
        update_post_meta( $post_id, '_mtv_clases_slots', $clean_slots );
    } else {
        delete_post_meta( $post_id, '_mtv_clases_slots' );
    }
} else {
    delete_post_meta( $post_id, '_mtv_clases_slots' );
}
// ── Tarifas por nº de días ─────────────────────────────────────
$flag = isset($_POST['mtv_tarifa_por_num_dias']) ? '1' : '0';
update_post_meta( $post_id, '_mtv_tarifa_por_num_dias', $flag );

$pp1 = isset($_POST['mtv_precio_1dia'])  ? floatval($_POST['mtv_precio_1dia'])  : '';
$pp2 = isset($_POST['mtv_precio_2dias']) ? floatval($_POST['mtv_precio_2dias']) : '';
$pp3 = isset($_POST['mtv_precio_3dias']) ? floatval($_POST['mtv_precio_3dias']) : '';
$pp4 = isset($_POST['mtv_precio_4dias']) ? floatval($_POST['mtv_precio_4dias']) : '';
($pp1 !== '') ? update_post_meta($post_id,'_mtv_precio_1dia',$pp1) : delete_post_meta($post_id,'_mtv_precio_1dia');
($pp2 !== '') ? update_post_meta($post_id,'_mtv_precio_2dias',$pp2) : delete_post_meta($post_id,'_mtv_precio_2dias');
($pp3 !== '') ? update_post_meta($post_id,'_mtv_precio_3dias',$pp3) : delete_post_meta($post_id,'_mtv_precio_3dias');
($pp4 !== '') ? update_post_meta($post_id,'_mtv_precio_4dias',$pp4)  : delete_post_meta($post_id,'_mtv_precio_4dias');
        // Borramos posibles metadatos de “excursión” y “bono”
        delete_post_meta( $post_id, '_mtv_excursion_hora' );
        delete_post_meta( $post_id, '_mtv_excursion_plazas' );
        delete_post_meta( $post_id, '_mtv_bono_slots' );

    } elseif ( $tipo_actividad === 'bono' ) {
    if ( isset($_POST['mtv_bono_slots']) && is_array($_POST['mtv_bono_slots']) ) {
        $raw   = $_POST['mtv_bono_slots'];
        $clean = [];

        foreach ( $raw as $i => $slot ) {
            // 1) saneas los campos habituales…
            $edad      = in_array($slot['edad'], ['infantil','adulto','mixto'], true) 
                         ? $slot['edad'] 
                         : '';
            $nivel     = in_array($slot['nivel'], ['1','2'], true) 
                         ? $slot['nivel'] 
                         : '1';
            $modalidad = in_array($slot['modalidad'], ['particular','colectiva'], true) 
                         ? $slot['modalidad'] 
                         : 'particular';
            $date      = sanitize_text_field( $slot['date']   ?? '' );
            $hora      = sanitize_text_field( $slot['hora']   ?? '' );
            $plazas    = intval( $slot['plazas'] ?? 0 );

            // ← Aquí capturamos el checkbox “repeat”
            $repeat    = ( isset($slot['repeat']) && $slot['repeat']==='1' ) ? '1' : '0';

            // 2) solo guardamos si todos los campos mínimos están bien
            if ( $edad && $nivel && $modalidad && $date && $hora && $plazas > 0 ) {
                $clean[] = [
                    'edad'      => $edad,
                    'nivel'     => $nivel,
                    'modalidad' => $modalidad,
                    'date'      => $date,
                    'hora'      => $hora,
                    'plazas'    => $plazas,
                    // ← añadimos el repeat al array final
                    'repeat'    => $repeat,
                ];
            }
        }

        if ( ! empty($clean) ) {
            update_post_meta( $post_id, '_mtv_bono_slots', $clean );
        } else {
            delete_post_meta( $post_id, '_mtv_bono_slots' );
        }
    } else {
        delete_post_meta( $post_id, '_mtv_bono_slots' );
    }
  delete_post_meta( $post_id, '_mtv_fecha_inicio' );
        // Borramos posibles metadatos de “excursión” y “clases”
        delete_post_meta( $post_id, '_mtv_excursion_hora' );
        delete_post_meta( $post_id, '_mtv_excursion_plazas' );
        delete_post_meta( $post_id, '_mtv_clases_dias' );
        delete_post_meta( $post_id, '_mtv_clases_slots' );

    } else {
        // Tipo “otro” u vacío: borramos todo
        delete_post_meta( $post_id, '_mtv_excursion_hora' );
        delete_post_meta( $post_id, '_mtv_excursion_plazas' );
        delete_post_meta( $post_id, '_mtv_clases_dias' );
        delete_post_meta( $post_id, '_mtv_clases_slots' );
        delete_post_meta( $post_id, '_mtv_bono_slots' );
    }
}

/**
 * -------------------------------------------------------------------------
 * 5) Sincronizar siempre los metadatos a la tabla personalizada “actividades”
 * -------------------------------------------------------------------------
 */
add_action( 'save_post_actividad', 'pa_sync_actividad_tabla', 20, 3 );
function pa_sync_actividad_tabla( $post_id, $post, $update ) {
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( $post->post_type !== 'actividad' ) return;

    global $wpdb;
    $tabla = $wpdb->prefix . 'actividades';

    // --- 1) Lectura de metadatos principal
    $tipo        = get_post_meta( $post_id, '_mtv_tipo_actividad', true );
    $fecha_ini   = get_post_meta( $post_id, '_mtv_fecha_inicio', true );
    $lugar       = get_post_meta( $post_id, '_mtv_lugar', true );
    $desc        = get_post_meta( $post_id, '_mtv_descripcion', true );
   $precio_reserva = get_post_meta( $post_id, '_mtv_precio', true );     // lo que cobras ahora
$precio_base    = get_post_meta( $post_id, '_mtv_precio_base', true );

    // Para Excursión:
    $hora_exc    = get_post_meta( $post_id, '_mtv_excursion_hora', true );
    $plazas_exc  = get_post_meta( $post_id, '_mtv_excursion_plazas', true );

    // Para Clases: SOLO guardamos la PRIMERA hora (los slots ya no tienen plazas)
$first_hora_clases = '';
if ( $tipo === 'clases' ) {
    $all_slots = get_post_meta( $post_id, '_mtv_clases_slots', true );
    if ( is_array($all_slots) && ! empty($all_slots) ) {
        $first_hora_clases = sanitize_text_field( $all_slots[0]['hora'] ?? '' );
    }
}


// --- 2) Preparamos $data común
$data = [
  'post_id'        => $post_id,
  'fecha_inicio'   => $fecha_ini ?: null,
  'lugar'          => $lugar,
  'descripcion'    => $desc,
  'precio'         => floatval($precio_reserva),
  'tipo_actividad' => $tipo,
  'updated_at'     => current_time('mysql', 1),
];

$data['hora']   = null;
$data['plazas'] = 0;

if ($tipo === 'excursion') {
    $data['hora']   = $hora_exc ?: null;
    $data['plazas'] = (int) $plazas_exc;

} elseif ($tipo === 'clases') {
    $data['hora']   = $first_hora_clases ?: null;
    $data['plazas'] = 0;

} elseif ($tipo === 'bono') {
    $data['fecha_inicio'] = null;
    $data['hora']         = null;
    $data['plazas']       = 0;
}

if ( ! $update ) {
    $data['created_at'] = current_time('mysql', 1);
}

    // --- 3) INSERT o UPDATE en la tabla personalizada ---
    if ( $update ) {
        $where        = ['post_id' => $post_id];
        $where_format = ['%d'];

        $formats = [
            '%d',  // post_id
            '%s',  // fecha_inicio
            '%s',  // lugar
            '%s',  // descripcion
            '%f',  // precio
            '%s',  // tipo_actividad
            '%s',  // updated_at
            '%s',  // hora
            '%d',  // plazas
        ];

        $wpdb->update( $tabla, $data, $where, $formats, $where_format );
    if ( $tipo === 'bono' ) {
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$tabla} SET fecha_inicio = NULL WHERE post_id = %d", $post_id
        ) );
    }
    } else {
    $data_insert = [
        'post_id'        => (int) $post_id,
        'fecha_inicio'   => $fecha_ini ?: null,
        'lugar'          => $lugar,
        'descripcion'    => $desc,
        'precio'         => (float) $precio_reserva,
        'tipo_actividad' => $tipo,
        'updated_at'     => current_time('mysql', 1),
        'hora'           => $data['hora'],       // string HH:MM
        'plazas'         => (int) $data['plazas'],
        'created_at'     => current_time('mysql', 1),
    ];
    $wpdb->insert(
        $tabla,
        $data_insert,
        ['%d','%s','%s','%s','%f','%s','%s','%s','%d','%s']
    );
  }
}

/**
 * 5.b) Eliminar de la tabla “actividades” si se borra o manda a papelera
 */
add_action( 'before_delete_post', function( $post_id ) {
    global $wpdb;
    $post = get_post( $post_id );
    if ( $post && $post->post_type === 'actividad' ) {
        $tabla = $wpdb->prefix . 'actividades';
        $wpdb->delete( $tabla, ['post_id' => $post_id], ['%d'] );
    }
});
add_action( 'trashed_post', function( $post_id ) {
    global $wpdb;
    $post = get_post( $post_id );
    if ( $post && $post->post_type === 'actividad' ) {
        $tabla = $wpdb->prefix . 'actividades';
        $wpdb->delete( $tabla, ['post_id' => $post_id], ['%d'] );
    }
});


/**
 * -------------------------------------------------------------------------
 * 6) Cargar el JavaScript (jQuery) EN EL ADMIN para el repeater de Clases
 * -------------------------------------------------------------------------
 *
 * En vez de tener un archivo separado, inyectamos el JS directamente mediante 
 * wp_add_inline_script(). Se cargará solo en la pantalla de edición/creación 
 * del CPT “actividad”.
 */
add_action( 'admin_enqueue_scripts', function( $hook ) {
    global $post;
    // Solo cargar en: post-new.php o post.php Y cuando el post_type sea “actividad”
    if ( ( $hook === 'post-new.php' || $hook === 'post.php' ) 
         && isset( $post->post_type ) 
         && $post->post_type === 'actividad' ) {

        // Asegurarnos de que jQuery esté disponible
        wp_enqueue_script( 'jquery' );

        // El código JS que gestiona:
        //   1) Ocultar/Mostrar el bloque de Horario+Plazas
        //   2) El repeater de “Añadir slot” / “Eliminar slot”
        $js = <<<'JS'
jQuery(document).ready(function($){
    // 1) Toggle del bloque principal
    function togglearBloque() {
        var tipo = $('#mtv_tipo_actividad').val();
        if ( tipo === 'excursion' || tipo === 'clases' ) {
            $('#mtv_bloque_horario_plazas').show();
        } else {
            $('#mtv_bloque_horario_plazas').hide();
        }
    }
    togglearBloque();
    $('#mtv_tipo_actividad').on('change', togglearBloque);

    // 2) Lógica para el repeater (solo si existe la tabla)
    var $tabla = $('#mtv_clases_slots_table');
    if ( $tabla.length ) {
        var $tbody = $tabla.find('tbody');

        function obtenerNuevoIndice() {
            return $tbody.find('tr').length;
        }

        // Añadir nueva fila
        $('#mtv_add_slot').on('click', function(e) {
            e.preventDefault();
            var nuevoIndex = obtenerNuevoIndice();
            var filaHtml = ''
                + '<tr>'
                + '  <td>'
                + '    <input type="time" name="mtv_clases_slots[' + nuevoIndex + '][hora]" value="" />'
                + '  </td>'
                + '  <td>'
                + '    <input type="number" min="1" step="1" name="mtv_clases_slots[' + nuevoIndex + '][plazas]" value="" />'
                + '  </td>'
                + '  <td>'
                + '    <button class="button mtv-remove-slot" type="button">Eliminar</button>'
                + '  </td>'
                + '</tr>';
            $tbody.append( filaHtml );
        });

        // Eliminar fila y reindexar
        $tbody.on('click', '.mtv-remove-slot', function(e) {
            e.preventDefault();
            $(this).closest('tr').remove();
            // Reindexar cada row
            $tbody.find('tr').each(function(index, tr){
                $(tr).find('input[type="time"]').attr('name', 'mtv_clases_slots['+ index +'][hora]');
                $(tr).find('input[type="number"]').attr('name', 'mtv_clases_slots['+ index +'][plazas]');
            });
        });
    }
});
JS;

        // Inyectamos el script justo después de jQuery
        wp_add_inline_script( 'jquery', $js );
    }
});


/**
 * -------------------------------------------------------------------------
 * 7) Encolar CSS/JS en el front-end SOLO si existe el shortcode 
 *    [panel_actividades_avanzado] en el contenido.
 * -------------------------------------------------------------------------
 */
add_action( 'wp_enqueue_scripts', function() {
    if ( is_singular() && has_shortcode( get_post()->post_content, 'panel_actividades_avanzado' ) ) {
        $url = plugin_dir_url(__FILE__);
        wp_enqueue_style( 'pa-style', $url . 'pa-style.css', [], '1.1' );
        wp_enqueue_script( 
            'pa-script', 
            $url . 'pa-script.js', 
            ['jquery'], 
            '1.1', 
            true 
        );
        wp_localize_script( 'pa-script', 'pa2_vars', [
            'api_url'    => esc_url_raw( rest_url('wp/v2/actividad?per_page=100') ),
            'plugin_url' => esc_url( plugin_dir_url(__FILE__) ),
        ] );
    }
});


/**
 * -------------------------------------------------------------------------
 * 8) Shortcode [panel_actividades_avanzado]
 * -------------------------------------------------------------------------
 */
add_shortcode( 'panel_actividades_avanzado', function() {
    ob_start(); ?>
    <div class="barra-boton">
        <a href="<?php echo esc_url( plugin_dir_url(__FILE__) . 'templates/reserva.php' ); ?>" 
           class="btn-ver-todo" target="_blank">
            Ver todas las actividades disponibles
        </a>
    </div>
    <?php
    return ob_get_clean();
});


/**
 * -------------------------------------------------------------------------
 * 9) REST API: exponer campos meta + featured image en la colección “actividad”
 * -------------------------------------------------------------------------
 */
add_action( 'rest_api_init', function() {
    register_rest_field( 'actividad', 'meta', [
        'get_callback' => function( $post_arr ) {
            $id = $post_arr['id'];
            return [
                'fecha_inicio'   => get_post_meta( $id, '_mtv_fecha_inicio', true ),
                'hora'           => get_post_meta( $id, '_mtv_excursion_hora', true ),
                'lugar'          => get_post_meta( $id, '_mtv_lugar', true ),
                'precio'         => get_post_meta( $id, '_mtv_precio', true ),
                'descripcion'    => get_post_meta( $id, '_mtv_descripcion', true ),
                'tipo_actividad' => get_post_meta( $id, '_mtv_tipo_actividad', true ),
                'slots_clases'   => get_post_meta( $id, '_mtv_clases_slots', true ),
                'nivel_clase'    => get_post_meta( $id, '_mtv_nivel_clase',  true ),
                'precio_reserva' => get_post_meta( $id, '_mtv_precio', true ),
                'precio_base'    => get_post_meta( $id, '_mtv_precio_base', true ),
            ];
        },
    ]);
    register_rest_field( 'actividad', 'featured_media_src', [
        'get_callback' => function( $post_arr ) {
            $mid = get_post_thumbnail_id( $post_arr['id'] );
            return $mid ? wp_get_attachment_image_url( $mid, 'medium' ) : '';
        },
    ]);
});

// 10) Registrar el endpoint "reserva-efectuada" para nuestra página de éxito
add_action( 'init', function(){
    add_rewrite_endpoint( 'reserva-efectuada', EP_PERMALINK|EP_PAGES );
});

// 11) Interceptar la carga de plantilla cuando visitemos ?reserva-efectuada
add_filter( 'template_include', function( $template ) {
    // Si existe la query var, cargamos success.php de nuestro plugin
    if ( get_query_var( 'reserva-efectuada' ) !== '' ) {
        return plugin_dir_path( __FILE__ ) . 'templates/success.php';
    }
    return $template;
});

/**
 * -------------------------------------------------------------------------
 *  Registro del Custom Post Type “mtv_reserva” y endpoint REST “/mtv/v1/reservas-calendar”
 *  (Integramos aquí todo lo que antes estaba en montaventura-reservas.php)
 * -------------------------------------------------------------------------
 */

/**
 * 1) Registrar el CPT “mtv_reserva”
 */
function mtv_registrar_cpt_reserva() {
    $labels = [
        'name'                  => 'Reservas',
        'singular_name'         => 'Reserva',
        'menu_name'             => 'Reservas',
        'name_admin_bar'        => 'Reserva',
        'add_new'               => 'Añadir Nueva',
        'add_new_item'          => 'Añadir Nueva Reserva',
        'new_item'              => 'Nueva Reserva',
        'edit_item'             => 'Editar Reserva',
        'view_item'             => 'Ver Reserva',
        'all_items'             => 'Todas las Reservas',
        'search_items'          => 'Buscar Reservas',
        'not_found'             => 'No se encontraron reservas',
        'not_found_in_trash'    => 'No se encontraron reservas en la Papelera',
    ];

    $args = [
        'labels'               => $labels,
        'public'               => false,
        'show_ui'              => true,
        'show_in_menu'         => true,
        'menu_icon'            => 'dashicons-calendar-alt',
        'capability_type'      => 'post',
        'supports'             => [ 'title' ],
        'has_archive'          => false,
        'rewrite'              => false,
        'show_in_rest'         => true,
        'rest_base'            => 'reservas',
        'rest_controller_class'=> 'WP_REST_Posts_Controller',
    ];

    register_post_type( 'mtv_reserva', $args );
}
add_action( 'init', 'mtv_registrar_cpt_reserva' );


/**
 * 2) Registrar los campos meta para exponerlos en la REST API
 */
function mtv_registrar_meta_reserva() {
    $campos_meta = [
        'fecha_hora_inicio',
        'actividad_id',
        'tipo_actividad',
        'alumno_nombre',
        'alumno_email',
        'alumno_telefono',
        'nombre_tutor',
        'email_tutor',
        'telefono_tutor',
        'opciones_adicionales',
        'observaciones',
          'diaClase',           // una o varias fechas (coma separadas)
  'hora_clase',         // HH:MM
  'nivel_grupo',        // 1 | 2
  'edad_group',         // infantil | adulto
  'modalidad',          // colectiva | particular
  'duracion_minutos',   // 60 por defecto
  'alumno_apellidos',
    ];

    foreach ( $campos_meta as $clave ) {
        register_post_meta( 'mtv_reserva', $clave, [
            'show_in_rest' => true,
            'single'       => true,
            'type'         => 'string',
        ] );
    }
}
add_action( 'rest_api_init', 'mtv_registrar_meta_reserva' );

// 3) Registrar el endpoint REST personalizado: GET /wp-json/mtv/v1/reservas-calendar
add_action( 'rest_api_init', function() {
    register_rest_route(
        'mtv/v1',
        '/reservas-calendar',
        [
            'methods'             => 'GET',
            'callback'            => 'mtv_obtener_reservas_para_calendario',
            'permission_callback' => '__return_true',
        ]
    );
} );

/**
 * 4) Callback: Consulta todas las reservas y las devuelve formateadas
 */
function mtv_obtener_reservas_para_calendario( \WP_REST_Request $request ) {
    $q = new WP_Query([
        'post_type'      => 'mtv_reserva',
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);

    $events = [];
    if ( $q->have_posts() ) {
        $siteTz = wp_timezone();

        $norm = function($v){
            $v = is_array($v) || is_object($v) ? '' : trim((string)$v);
            return ($v === '' || $v === 'null' || $v === 'undefined') ? '' : $v;
        };
        $pick = function(...$vals) use ($norm){
            foreach ($vals as $v) { $vv = $norm($v); if ($vv !== '') return $vv; }
            return '';
        };

        while ( $q->have_posts() ) {
            $q->the_post();
            $rid = get_the_ID();
            $m   = get_post_meta($rid);

            $dias_raw = $pick($m['diaClase'][0] ?? '', $m['fecha_inicio'][0] ?? '');
            if ($dias_raw === '') { continue; }
            $dias = array_filter(array_map('trim', preg_split('/[,;\s]+/', $dias_raw)));

            $tipo = strtolower($pick($m['tipo_actividad'][0] ?? ''));
            if ($tipo === '' || preg_match('/^\d{1,2}:\d{2}$/', $tipo)) { $tipo = 'clases'; }

            $hora    = preg_match('/^\d{1,2}:\d{2}$/', ($m['hora_clase'][0] ?? '')) ? $m['hora_clase'][0] : '00:00';
            $dur_min = intval($m['duracion_minutos'][0] ?? 60);

            $alum_nombre    = $pick($m['alumno_nombre'][0] ?? '', $m['nombre'][0] ?? '');
            $alum_apellidos = $pick($m['alumno_apellidos'][0] ?? '', $m['apellidos'][0] ?? '');

            $nivel_raw = $pick($m['nivel_grupo'][0] ?? '', $m['nivel'][0] ?? '');
            $nivel_num = is_numeric($nivel_raw) ? (int)$nivel_raw : (preg_match('/(\d+)/', (string)$nivel_raw, $mm) ? (int)$mm[1] : 0);
            $nivel_for_front = $nivel_num > 0 ? (string)$nivel_num : $pick($m['nivel'][0] ?? '');

            $edad    = $pick($m['edad_group'][0] ?? '', $m['edad'][0] ?? '');
            $pie     = $pick($m['pie'][0] ?? '', $m['talla_pie'][0] ?? '', $m['botas'][0] ?? '');
            $ski     = $pick($m['ski'][0] ?? '', $m['esqui'][0] ?? '', $m['talla_esqui'][0] ?? '', $m['altura'][0] ?? '');
            $altura  = $pick($m['altura'][0] ?? '');
            $telAlum = $pick($m['alumno_telefono'][0] ?? '', $m['telefono'][0] ?? '');
            $obs     = $pick($m['observaciones'][0] ?? '');

            foreach ($dias as $i => $dia) {
                if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dia)) {
                    $d = DateTime::createFromFormat('d/m/Y', $dia, $siteTz);
                    $diaNorm = $d ? $d->format('Y-m-d') : $dia;
                } else {
                    $diaNorm = $dia;
                }

                $start = new DateTime("{$diaNorm} {$hora}:00", $siteTz);
                $end   = ($tipo === 'excursion')
                    ? (clone $start)->modify('+5 days')
                    : (clone $start)->modify("+{$dur_min} minutes");

                $base  = ($tipo === 'clases' && $nivel_num > 0) ? "Curso N{$nivel_num}" : ucfirst($tipo);
                $title = $alum_nombre ? "{$base} - {$alum_nombre}" : $base;

                $events[] = [
                    'id'     => "{$rid}-{$diaNorm}-{$i}",
                    'title'  => $title,
                    'start'  => $start->format('Y-m-d\TH:i:s'),
                    'end'    => $end->format('Y-m-d\TH:i:s'),
                    'allDay' => false,
                    'extendedProps' => [
                        'tipo_actividad'   => $tipo,
                        'alumno_nombre'    => $alum_nombre,
                        'alumno_apellidos' => $alum_apellidos,
                        'edad'             => $edad,
                        'pie'              => $pie,
                        'ski'              => $ski,
                        'nivel'            => $nivel_for_front,
                        'alumno_telefono'  => $telAlum,
                        'observaciones'    => $obs,
                        'nivel_grupo'      => $nivel_num,
                        'talla_pie'        => $pick($m['talla_pie'][0] ?? ''),
                        'talla_esqui'      => $pick($m['talla_esqui'][0] ?? ''),
                        'altura'           => $altura,
                        'diaClase'         => $dias_raw,
                        'hora_clase'       => $hora,
                    ],
                ];
            }
        }
        wp_reset_postdata();
    }
    return rest_ensure_response($events);
}
/**
 * 5) Encolar FullCalendar y nuestro JS/CSS para el front-end
 */
function mtv_enqueue_fullcalendar_assets() {
    wp_enqueue_style(
        'fullcalendar-css',
        'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css',
        [], '6.1.8'
    );
    wp_enqueue_style(
        'mtv-calendario-css',
        plugins_url( '/assets/css/calendario-martita.css', __FILE__ ),
        [], '1.0'
    );

    wp_enqueue_script(
        'fullcalendar-js',
        'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js',
        [], '6.1.8', true
    );
    wp_enqueue_script(
        'mtv-calendario-init',
        plugins_url( '/assets/js/calendario-martita.js', __FILE__ ),
        [ 'fullcalendar-js' ], '1.0', true
    );

    wp_localize_script(
        'mtv-calendario-init',
        'mtv_calendario_vars',
        [
            'reservas_endpoint' => esc_url_raw( rest_url( 'mtv/v1/reservas-calendar' ) ),
            'nonce'             => wp_create_nonce( 'wp_rest' ), 
        ]
    );
}
add_action( 'wp_enqueue_scripts', 'mtv_enqueue_fullcalendar_assets' );

/**
 * 6) Shortcode [mtv_calendario] para insertar el contenedor en una página
 */
function mtv_calendario_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>Debes iniciar sesión para ver el calendario.</p>';
    }
    return '
    <div class="calendar-container">
      <h1 class="calendar-title">Calendario de Reservas</h1>
      <div id="calendar-martita"></div>
    </div>';
}
add_shortcode( 'mtv_calendario', 'mtv_calendario_shortcode' );

/**
 * -------------------------------------------------------------------------
 * 7) METABOX Y COLUMNAS PERSONALIZADAS PARA “mtv_reserva”
 * -------------------------------------------------------------------------
 */

/**
 * 7.1) Registrar el metabox “Detalles de la Reserva”
 */
add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'mtv_reserva_detalles',        // ID único del metabox
        'Detalles de la Reserva',      // Título que se mostrará
        'mtv_render_reserva_detalles', // Función callback que imprime el HTML
        'mtv_reserva',                 // CPT al que se asocia
        'normal',                      // Contexto (normal, side, advanced)
        'high'                         // Prioridad (high, default, low)
    );
});

/**
 * Callback que imprime el contenido del metabox “Detalles de la Reserva”
 * Muestra *todos* los metadatos del post, excepto los que empiezan por guión bajo.
 *
 * @param WP_Post $post El objeto WP_Post de la reserva a mostrar.
 */
function mtv_render_reserva_detalles( $post ) {
    // Obtenemos TODOS los metadatos
    $todos_los_meta = get_post_meta( $post->ID );

    if ( empty( $todos_los_meta ) ) {
        echo '<p>No hay metadatos para esta reserva.</p>';
        return;
    }

    echo '<table style="width:100%; border-collapse:collapse;">';
    echo '<tbody>';

    foreach ( $todos_los_meta as $clave => $valores ) {
        // Cada $valores es un array; tomamos siempre el primer elemento
        $valor = maybe_unserialize( $valores[0] );

        // Opcional: ocultar metadatos internos de WP (los que empiecen por "_")
        if ( strpos( $clave, '_' ) === 0 ) {
            continue;
        }

        // Si el valor es array u objeto, lo formateamos como JSON para que se vea “bonito”
        if ( is_array( $valor ) || is_object( $valor ) ) {
            $texto = wp_json_encode( $valor, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
            $mostrar = '<pre style="
                            background: #f7f7f7;
                            padding: 8px;
                            border-radius: 4px;
                            font-size: 0.9em;
                            max-height: 200px;
                            overflow: auto;
                        ">'. esc_html( $texto ) .'</pre>';
        } else {
            $mostrar = esc_html( $valor );
        }

        echo '<tr style="border-bottom:1px solid #ddd;">';
        echo '  <td style="
                        width:30%;
                        padding:6px 8px;
                        font-weight:600;
                        background:#fafafa;
                        vertical-align:top;
                    ">'. esc_html( $clave ) .'</td>';
        echo '  <td style="padding:6px 8px; vertical-align:top;">'. $mostrar .'</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
}


/**
 * 7.2) Mostrar solo Título y Alumno en “Todas las Reservas”
 */
add_filter( 'manage_mtv_reserva_posts_columns', function( $columns ) {
    return [
        'cb'                => $columns['cb'],           // checkbox
        'title'             => __( 'Título' ),           // título del post
        'col_alumno_nombre' => __( 'Alumno' ),           // columna personalizada “Alumno”
    ];
} );

// 7.3) Rellenar la columna “Alumno”
add_action( 'manage_mtv_reserva_posts_custom_column', function( $column, $post_id ) {
    if ( $column === 'col_alumno_nombre' ) {
        echo esc_html( get_post_meta( $post_id, 'alumno_nombre', true ) );
    }
}, 10, 2 );



/**
 * -------------------------------------------------------------------------
 * 8) (Opcional) Columnas ordenables en “Todas las Reservas”
 *    Descomenta este bloque si deseas que sean ordenables.
 * -------------------------------------------------------------------------
 */
/*
add_filter( 'manage_edit-mtv_reserva_sortable_columns', function( $columns ) {
    $columns['col_alumno_nombre']  = 'alumno_nombre';
    $columns['col_nivel']          = 'nivel';
    $columns['col_altura']         = 'altura';
    $columns['col_botas']          = 'botas';
    return $columns;
} );

add_action( 'pre_get_posts', function( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }

    if ( 'mtv_reserva' === $query->get( 'post_type' ) ) {
        $orderby = $query->get( 'orderby' );
        if ( 'alumno_nombre' === $orderby ) {
            $query->set( 'meta_key', 'alumno_nombre' );
            $query->set( 'orderby', 'meta_value' );
        }
        if ( 'nivel' === $orderby ) {
            $query->set( 'meta_key', 'nivel' );
            $query->set( 'orderby', 'meta_value' );
        }
        if ( 'altura' === $orderby ) {
            $query->set( 'meta_key', 'altura' );
            $query->set( 'orderby', 'meta_value_num' );
        }
        if ( 'botas' === $orderby ) {
            $query->set( 'meta_key', 'botas' );
            $query->set( 'orderby', 'meta_value_num' );
        }
    }
} );
*/


/**
 * -------------------------------------------------------------------------
 * 9) (Opcional) Estilos CSS en el Admin
 *    Descomenta e ajusta si quieres personalizar el <pre> JSON, etc.
 * -------------------------------------------------------------------------
 */

add_action( 'admin_enqueue_scripts', function() {
    wp_add_inline_style( 'wp-admin', '
        #mtv_reserva_detalles pre {
            background: #f7f7f7 !important;
            font-family: Menlo, Courier, monospace !important;
            font-size: 0.9em !important;
        }
    ' );
} );
add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'mtv_opciones_adicionales_metabox', // ID único del meta‐box
        'Opciones Adicionales',              // Título que aparecerá
        'mtv_render_opciones_adicionales',   // Callback que imprimirá el contenido
        'mtv_reserva',                       // Lo cargamos en el CPT "mtv_reserva"
        'normal',                            // Ubicación: en la columna principal
        'high'                               // Prioridad: más arriba
    );
});

/**
 * 2) Callback: pinta el valor de "opciones_adicionales" dentro de un <div> con pre-wrap.
 *    De este modo, los saltos de línea (\n) que guardaste en JavaScript se verán reflejados
 *    exactamente en el admin tal como los escribiste.
 */
function mtv_render_opciones_adicionales( $post ) {
    // Recuperamos el meta guardado (usando la misma clave que checkout.php llenó: 'opciones_adicionales')
    $texto = get_post_meta( $post->ID, 'opciones_adicionales', true );
    if ( empty( $texto ) ) {
        echo '<p><em>No hay opciones adicionales.</em></p>';
    } else {
        // Escapamos y aplicamos pre-wrap
        echo '<div style="background: #f9f9f9; padding:10px; border:1px solid #ddd; white-space: pre-wrap; font-family: monospace;">'
             . esc_html( $texto )
             . '</div>';
    }
}

/**
 * 3) (Opcional) Si quieres ocultar la fila original "opciones_adicionales" que WP ya coloca
 *    en la lista de metadatos, puedes inyectar un poco de CSS que la esconda.
 *    Atención: en WordPress no existe un selector sencillo tipo `th:contains("opciones_adicionales")`,
 *    así que lo más fiable es ocultar por la posición (p. ej. la fila X), o por atributo data-key.
 *    A continuación, si solo tienes ese campo y quieres esconderlo por completo, podrías usar:
 */
add_action( 'admin_head', function() {
    $screen = get_current_screen();
    if ( isset($screen->post_type) && $screen->post_type === 'mtv_reserva' ) {
        echo '<style>
        /* 
           Ocultamos la fila completa cuyo <th> sea "opciones_adicionales". 
           Dado que WP no soporta :contains en CSS puro, 
           lo haremos buscando el td que contiene text, por ejemplo:
        */
        table.form-table tr th {
            /* Esto es “por seguridad”: primero asegurémonos de que exista */
        }
        /* Ahora, seleccionamos el <tr> donde el <th> tenga exactamente ese texto: */
        table.form-table tr th[data-meta_key="opciones_adicionales"],
        table.form-table tr th:has(+ td[data-meta_key="opciones_adicionales"]) {
            display: none !important;
        }
        table.form-table tr th[data-meta_key="opciones_adicionales"] + td {
            display: none !important;
        }
        /* Si tu versión de WP no pone data-meta_key, verifica en HTML con “Inspeccionar elemento”
           cuál es el atributo: a veces sale algo como <th scope="row">opciones_adicionales</th>.
           En ese caso, podrías hacer tr:nth-child(n) pero cuidado con futuros cambios. */
        </style>';
    }
});
// En el fichero principal de tu plugin (por ejemplo montaventura.php)
add_action( 'admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=mtv_reserva',   // debajo de Reservas
        'Códigos promocionales',           // Título de la página
        'Códigos BONOS',                   // Texto del menú
        'manage_options',                  // Capacidad requerida
        'montaventura-promo-codes',        // Slug
        'montaventura_render_promo_codes_page'  // Callback
    );
});
// Metabox para CREAR/EDITAR reservas manuales
add_action('add_meta_boxes', function () {
  add_meta_box(
    'mtv_reserva_editor',
    'Crear / editar reserva',
    'mtv_reserva_editor_cb',
    'mtv_reserva',
    'normal',
    'high'
  );
});
// Submenú "Calendario" bajo Reservas (CPT mtv_reserva)
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=mtv_reserva',   // padre: Reservas
        'Calendario de Reservas',           // <title> de la página (no se verá, redirige)
        'Calendario',                       // texto del menú
        'edit_posts',                       // capacidad
        'mtv-calendario-redirect',          // slug único
        'mtv_admin_calendar_redirect'       // callback
    );
});

/** Redirige al calendario del front */
function mtv_admin_calendar_redirect() {
    // Si prefieres por ID (más robusto): $url = get_permalink(27824);
    $url = 'https://www.montaventura.com/27824-2/';
    wp_safe_redirect($url);
    exit;
}
add_action('admin_head', function () {
    ?>
    <script>
    jQuery(function($){
      $('#menu-posts-mtv_reserva .wp-submenu a[href*="mtv-calendario-redirect"]')
        .attr('target','_blank');
    });
    </script>
    <?php
});

function mtv_reserva_editor_cb( WP_Post $post ) {
  wp_nonce_field('mtv_reserva_editor_save', 'mtv_reserva_editor_nonce');

  // valores actuales
  $actividad_id    = get_post_meta($post->ID,'actividad_id',true);
  $tipo_actividad  = get_post_meta($post->ID,'tipo_actividad',true);
  $dia_str         = get_post_meta($post->ID,'diaClase',true);         // "2025-09-20,2025-09-27"
  $hora_clase      = get_post_meta($post->ID,'hora_clase',true);
  $nivel_grupo     = get_post_meta($post->ID,'nivel_grupo',true);
  $edad_group      = get_post_meta($post->ID,'edad_group',true);
  $modalidad       = get_post_meta($post->ID,'modalidad',true);
  $duracion        = get_post_meta($post->ID,'duracion_minutos',true) ?: 60;

  $alumno_nombre   = get_post_meta($post->ID,'alumno_nombre',true);
  $alumno_apell    = get_post_meta($post->ID,'alumno_apellidos',true);
  $alumno_email    = get_post_meta($post->ID,'alumno_email',true);
  $alumno_tel      = get_post_meta($post->ID,'alumno_telefono',true);

  $nombre_tutor    = get_post_meta($post->ID,'nombre_tutor',true);
  $email_tutor     = get_post_meta($post->ID,'email_tutor',true);
  $tel_tutor       = get_post_meta($post->ID,'telefono_tutor',true);

  $opciones_add    = get_post_meta($post->ID,'opciones_adicionales',true);
  $observaciones   = get_post_meta($post->ID,'observaciones',true);

  // actividades (desde tabla personalizada)
  global $wpdb;
  $tabla = $wpdb->prefix . 'actividades';
  $acts  = $wpdb->get_results("
    SELECT a.id, a.post_id, a.tipo_actividad, p.post_title
    FROM {$tabla} a
    JOIN {$wpdb->posts} p ON p.ID = a.post_id
    WHERE p.post_status IN ('publish','future','draft')
    ORDER BY p.post_title ASC
  ");

  // pasar fechas a array para pintarlas como inputs multiple
  $dias = array_filter(array_map('trim', explode(',', (string)$dia_str)));
  if (!$dias) { $dias = ['']; }
  ?>
  <style>
    .mtv-grid{display:grid;grid-template-columns:180px 1fr;gap:8px;align-items:center}
    .mtv-box{background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px;margin-bottom:14px}
    .mtv-inline{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .mtv-help{color:#666;font-size:12px}
  </style>

  <div class="mtv-box">
    <div class="mtv-grid">
      <label><strong>Actividad</strong></label>
      <select name="mtv_res_act_id" id="mtv_res_act_id">
        <option value="">— Selecciona —</option>
        <?php foreach($acts as $a): ?>
          <option value="<?php echo esc_attr($a->id); ?>"
            data-tipo="<?php echo esc_attr($a->tipo_actividad); ?>"
            <?php selected($actividad_id, $a->id); ?>>
            <?php echo esc_html("#{$a->id} · {$a->post_title} ({$a->tipo_actividad})"); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label><strong>Tipo de actividad</strong></label>
      <select name="mtv_res_tipo" id="mtv_res_tipo">
        <option value="">— Selecciona —</option>
        <option value="excursion" <?php selected($tipo_actividad,'excursion'); ?>>Viajes y Excursiones</option>
        <option value="clases"    <?php selected($tipo_actividad,'clases');    ?>>Cursos</option>
        <option value="bono"      <?php selected($tipo_actividad,'bono');      ?>>Bono</option>
      </select>

      <label><strong>Duración (min.)</strong></label>
      <input type="number" name="mtv_res_duracion" min="1" step="1" value="<?php echo esc_attr($duracion); ?>">
    </div>
    <p class="mtv-help">Al cambiar la actividad se rellenará el tipo automáticamente.</p>
  </div>

  <div class="mtv-box" id="mtv_box_fechas">
    <strong>Fechas y hora</strong>
    <div id="mtv_fechas_wrap">
      <?php foreach($dias as $i=>$d): ?>
        <div class="mtv-inline" style="margin-top:8px">
          <input type="date" name="mtv_res_dias[]" value="<?php echo esc_attr($d); ?>">
          <button class="button mtv-del-fecha" type="button">Quitar</button>
        </div>
      <?php endforeach; ?>
    </div>
    <p><button class="button" id="mtv_add_fecha" type="button">Añadir fecha</button></p>

    <div class="mtv-grid">
      <label><strong>Hora</strong> <span class="mtv-help">(para clases/bono)</span></label>
      <input type="time" name="mtv_res_hora" value="<?php echo esc_attr($hora_clase); ?>">
    </div>
  </div>

  <div class="mtv-box" id="mtv_box_grupo">
    <strong>Grupo (sólo Cursos / Bono)</strong>
    <div class="mtv-grid">
      <label>Nivel</label>
      <select name="mtv_res_nivel">
        <option value="">—</option>
        <option value="1" <?php selected($nivel_grupo,'1'); ?>>1</option>
        <option value="2" <?php selected($nivel_grupo,'2'); ?>>2</option>
      </select>

      <label>Edad</label>
      <select name="mtv_res_edad">
        <option value="">—</option>
        <option value="infantil" <?php selected($edad_group,'infantil'); ?>>Infantil</option>
        <option value="adulto"   <?php selected($edad_group,'adulto');   ?>>Adulto</option>
      </select>

      <label>Modalidad</label>
      <select name="mtv_res_modalidad">
        <option value="">—</option>
        <option value="colectiva"  <?php selected($modalidad,'colectiva');  ?>>Colectiva</option>
        <option value="particular" <?php selected($modalidad,'particular'); ?>>Particular</option>
      </select>
    </div>
  </div>

  <div class="mtv-box">
    <strong>Alumno</strong>
    <div class="mtv-grid">
      <label>Nombre</label>       <input type="text" name="mtv_res_alumno_nombre"    value="<?php echo esc_attr($alumno_nombre); ?>">
      <label>Apellidos</label>    <input type="text" name="mtv_res_alumno_apell"     value="<?php echo esc_attr($alumno_apell); ?>">
      <label>Email</label>        <input type="email" name="mtv_res_alumno_email"    value="<?php echo esc_attr($alumno_email); ?>">
      <label>Teléfono</label>     <input type="text"  name="mtv_res_alumno_tel"      value="<?php echo esc_attr($alumno_tel); ?>">
    </div>
  </div>

  <div class="mtv-box">
    <strong>Tutor (si menor)</strong>
    <div class="mtv-grid">
      <label>Nombre</label>    <input type="text"  name="mtv_res_tutor_nombre" value="<?php echo esc_attr($nombre_tutor); ?>">
      <label>Email</label>     <input type="email" name="mtv_res_tutor_email"  value="<?php echo esc_attr($email_tutor); ?>">
      <label>Teléfono</label>  <input type="text"  name="mtv_res_tutor_tel"    value="<?php echo esc_attr($tel_tutor); ?>">
    </div>
  </div>

  <div class="mtv-box">
    <strong>Opciones adicionales / Observaciones</strong>
    <div class="mtv-grid">
      <label>Opciones adicionales</label>
      <textarea name="mtv_res_opc" rows="3" style="width:100%"><?php echo esc_textarea($opciones_add); ?></textarea>

      <label>Observaciones</label>
      <textarea name="mtv_res_obs" rows="3" style="width:100%"><?php echo esc_textarea($observaciones); ?></textarea>
    </div>
    <p class="mtv-help">Las fechas se guardan exactamente como las escribas. Varias fechas se unen con coma y el calendario las mostrará todas.</p>
  </div>
<div class="mtv-box">
  <strong>Crear bono manual (opcional)</strong>
  <?php wp_nonce_field('mtv_reserva_bono_manual', 'mtv_reserva_bono_nonce'); ?>
  <div class="mtv-grid">
    <label>Email del bono</label>
    <input type="email" name="mtv_gen_bono_email" value="<?php echo esc_attr($alumno_email); ?>" placeholder="Email del beneficiario">
    <label>Tipo</label>
    <select name="mtv_gen_bono_type">
      <option value="bono">Bono (varios usos)</option>
      <option value="reserva">Reserva (1 uso)</option>
    </select>
    <label>Usos</label>
    <input type="number" name="mtv_gen_bono_uses" min="1" step="1" value="4">
    <label>Código (opcional)</label>
    <input type="text" name="mtv_gen_bono_code" placeholder="Vacío = autogenerar">
  </div>
  <label style="margin-top:8px;display:inline-flex;gap:.5rem;align-items:center">
    <input type="checkbox" name="mtv_gen_bono_flag" value="1"> Crear y adjuntar a esta reserva
  </label>
</div>

  <script>
    (function($){
      // Autocompletar "tipo" desde la actividad
      $('#mtv_res_act_id').on('change', function(){
        var tipo = $(this).find(':selected').data('tipo')||'';
        if(tipo){ $('#mtv_res_tipo').val(tipo).trigger('change'); }
      });
      // Mostrar/ocultar grupo/hora según tipo
      function toggleTipo(){
        var t = $('#mtv_res_tipo').val();
        $('#mtv_box_grupo')[ (t==='clases'||t==='bono') ? 'show':'hide' ]();
        // para excursión la hora no se usa, pero la dejamos editable
      }
      $('#mtv_res_tipo').on('change', toggleTipo);
      toggleTipo();

      // Fechas: añadir/quitar inputs
      $('#mtv_add_fecha').on('click', function(e){
        e.preventDefault();
        $('#mtv_fechas_wrap').append(
          '<div class="mtv-inline" style="margin-top:8px">'+
          ' <input type="date" name="mtv_res_dias[]" value=""/>'+
          ' <button class="button mtv-del-fecha" type="button">Quitar</button>'+
          '</div>'
        );
      });
      $('#mtv_fechas_wrap').on('click','.mtv-del-fecha', function(e){
        e.preventDefault(); $(this).closest(".mtv-inline").remove();
      });
    })(jQuery);
  </script>
  <?php
}
add_action('save_post_mtv_reserva','mtv_reserva_editor_save');
function mtv_reserva_editor_save( $post_id ){
  if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
  if ( ! isset($_POST['mtv_reserva_editor_nonce']) ) return;
  if ( ! wp_verify_nonce($_POST['mtv_reserva_editor_nonce'], 'mtv_reserva_editor_save') ) return;
  if ( ! current_user_can('edit_post',$post_id) ) return;

  // Sanear helpers
  $clean_text  = fn($k) => isset($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : '';
  $clean_email = fn($k) => isset($_POST[$k]) ? sanitize_email($_POST[$k]) : '';
  $clean_int   = fn($k,$def=0)=> isset($_POST[$k]) ? intval($_POST[$k]) : $def;

  // actividad y tipo
  update_post_meta($post_id,'actividad_id',   $clean_int('mtv_res_act_id'));
  update_post_meta($post_id,'tipo_actividad', $clean_text('mtv_res_tipo'));

  // fechas -> coma separadas
  $dias = array_filter(array_map('sanitize_text_field', (array)($_POST['mtv_res_dias'] ?? [])));
  update_post_meta($post_id,'diaClase', implode(',', $dias));

  // hora y duración
  update_post_meta($post_id,'hora_clase',       $clean_text('mtv_res_hora'));
  update_post_meta($post_id,'duracion_minutos', $clean_int('mtv_res_duracion',60));

  // grupo (sólo clases/bono, pero se guarda igual si viene)
  if ( isset($_POST['mtv_res_nivel']) )    update_post_meta($post_id,'nivel_grupo', sanitize_text_field($_POST['mtv_res_nivel']));
  if ( isset($_POST['mtv_res_edad']) )     update_post_meta($post_id,'edad_group',  sanitize_text_field($_POST['mtv_res_edad']));
  if ( isset($_POST['mtv_res_modalidad']) )update_post_meta($post_id,'modalidad',   sanitize_text_field($_POST['mtv_res_modalidad']));

  // alumno
  update_post_meta($post_id,'alumno_nombre',     $clean_text('mtv_res_alumno_nombre'));
  update_post_meta($post_id,'alumno_apellidos',  $clean_text('mtv_res_alumno_apell'));
  update_post_meta($post_id,'alumno_email',      $clean_email('mtv_res_alumno_email'));
  update_post_meta($post_id,'alumno_telefono',   $clean_text('mtv_res_alumno_tel'));

  // tutor
  update_post_meta($post_id,'nombre_tutor',      $clean_text('mtv_res_tutor_nombre'));
  update_post_meta($post_id,'email_tutor',       $clean_email('mtv_res_tutor_email'));
  update_post_meta($post_id,'telefono_tutor',    $clean_text('mtv_res_tutor_tel'));

  // texto
  update_post_meta($post_id,'opciones_adicionales', sanitize_textarea_field($_POST['mtv_res_opc'] ?? ''));
  update_post_meta($post_id,'observaciones',        sanitize_textarea_field($_POST['mtv_res_obs'] ?? ''));

  // título automático si está vacío
  $post = get_post($post_id);
  if ( $post && $post->post_title === '' ) {
    $when  = $dias ? reset($dias) : '';
    $title = trim("Reserva manual — {$clean_text('mtv_res_alumno_nombre')} {$clean_text('mtv_res_alumno_apell')} — {$when} {$clean_text('mtv_res_hora')}");
    if ($title) {
      wp_update_post(['ID'=>$post_id, 'post_title'=>$title]);
    }
  }
  // Crear bono manual si se marcó el checkbox
if (!empty($_POST['mtv_gen_bono_flag']) &&
    !empty($_POST['mtv_reserva_bono_nonce']) &&
    wp_verify_nonce($_POST['mtv_reserva_bono_nonce'], 'mtv_reserva_bono_manual')) {

    $email = sanitize_email($_POST['mtv_gen_bono_email'] ?? '');
    $type  = sanitize_text_field($_POST['mtv_gen_bono_type'] ?? 'bono');
    $uses  = max(1, intval($_POST['mtv_gen_bono_uses'] ?? 1));
    $code  = sanitize_text_field($_POST['mtv_gen_bono_code'] ?? '');

    if ($email) {
        if ($type === 'reserva') $uses = 1;
        $res = mtv_insert_promo_code($email, $type, $uses, $code ?: null);
        if (!is_wp_error($res)) {
            // Lo guardamos en la reserva para que quede a la vista
            update_post_meta($post_id, 'used_promo_code', $res->code);
            update_post_meta($post_id, 'promo_info', sprintf('%s|%dusos', $res->type, $res->uses_remaining));
        }
    }
}

}
function montaventura_render_promo_codes_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'montaventura_promo_codes';

    // Crear
    if (!empty($_POST['mtv_create_promo_nonce']) && wp_verify_nonce($_POST['mtv_create_promo_nonce'], 'mtv_create_promo')) {
        $email     = sanitize_email($_POST['promo_email'] ?? '');
        $modalidad = sanitize_text_field($_POST['promo_modalidad'] ?? 'mixto');   // colectiva|particular|mixto
        $coupon    = sanitize_text_field($_POST['promo_kind'] ?? 'bono');         // bono|reserva
        $uses      = max(1, (int)($_POST['promo_uses'] ?? 1));
        $code      = sanitize_text_field($_POST['promo_code'] ?? '');

        // Normaliza valores permitidos
        $modalidad = in_array($modalidad, ['colectiva','particular','mixto'], true) ? $modalidad : 'mixto';
        $coupon    = in_array($coupon, ['bono','reserva'], true) ? $coupon : 'bono';

        // Si es reserva, forzamos a 1 uso siempre (por si acaso)
        if ($coupon === 'reserva') {
            $uses = 1;
        }

        if (!$email) {
            echo '<div class="error"><p>El email es obligatorio.</p></div>';
        } else {
            $res = mtv_insert_promo_code($email, $coupon, $uses, ($code ?: null), $modalidad);
            if (is_wp_error($res)) {
                echo '<div class="error"><p>' . esc_html($res->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="updated"><p>Código creado: <strong>' . esc_html($res->code) . '</strong> — '
                   . 'Modalidad: <strong>' . esc_html($res->modalidad ?: 'mixto') . '</strong> — '
                   . 'Clase: <strong>' . esc_html($res->type) . '</strong> — '
                   . 'Usos: ' . intval($res->uses_remaining) . '</p></div>';
            }
        }
    }

    // Borrar
    if (!empty($_GET['delete_code']) && !empty($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'mtv_delete_code')) {
        $code_to_delete = sanitize_text_field($_GET['delete_code']);
        $wpdb->delete($table, ['code' => $code_to_delete], ['%s']);
        echo '<div class="updated"><p>Código <strong>' . esc_html($code_to_delete) . '</strong> eliminado.</p></div>';
    }

    // Listado (mejor seleccionar columnas explícitas)
    $codes = $wpdb->get_results("SELECT code, type, modalidad, uses_remaining, email, created_at FROM {$table} ORDER BY created_at DESC");
    ?>
    <div class="wrap">
      <h1>Códigos promocionales</h1>

      <h2 style="margin-top:1em;">Crear código manual</h2>
      <form method="post" style="max-width:620px;background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px;">
        <?php wp_nonce_field('mtv_create_promo', 'mtv_create_promo_nonce'); ?>
        <table class="form-table">
          <tr>
            <th><label for="promo_email">Email *</label></th>
            <td><input type="email" id="promo_email" name="promo_email" class="regular-text" required></td>
          </tr>

          <!-- Este select controla la MODALIDAD -->
          <tr>
            <th><label for="promo_modalidad">Tipo</label></th>
            <td>
              <select id="promo_modalidad" name="promo_modalidad">
                <option value="colectiva">Colectiva</option>
                <option value="particular">Particular</option>
                <option value="mixto">Mixto (sirve para ambas)</option>
              </select>
            </td>
          </tr>

          <!-- Clase del código = bono|reserva -->
          <tr>
            <th><label for="promo_kind">Clase del código</label></th>
            <td>
              <select id="promo_kind" name="promo_kind">
                <option value="bono">Bono (varios usos)</option>
                <option value="reserva">Reserva (1 uso)</option>
              </select>
            </td>
          </tr>

          <tr>
            <th><label for="promo_uses">Usos</label></th>
            <td>
              <input type="number" id="promo_uses" name="promo_uses" min="1" step="1" value="4">
              <p class="description">Si eliges “reserva”, se forzará a 1.</p>
            </td>
          </tr>

          <tr>
            <th><label for="promo_code">Código (opcional)</label></th>
            <td><input type="text" id="promo_code" name="promo_code" class="regular-text" placeholder="Vacío = autogenerar"></td>
          </tr>
        </table>
        <p><button type="submit" class="button button-primary">Crear código</button></p>
      </form>

      <h2 style="margin-top:1.5em;">Listado</h2>
      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr>
            <th>Código</th>
            <th>Clase</th>       <!-- bono | reserva -->
            <th>Modalidad</th>   <!-- colectiva | particular | mixto -->
            <th>Usos restantes</th>
            <th>Email</th>
            <th>Creado en</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($codes) : foreach ($codes as $c) : ?>
          <tr>
            <td><code><?php echo esc_html($c->code); ?></code></td>
            <td><?php echo esc_html($c->type); ?></td>
            <td><?php echo esc_html($c->modalidad ?: 'mixto'); ?></td>
            <td><?php echo intval($c->uses_remaining); ?></td>
            <td><?php echo esc_html($c->email); ?></td>
            <td><?php echo esc_html($c->created_at); ?></td>
            <td>
              <a href="<?php echo esc_url( wp_nonce_url( add_query_arg('delete_code', $c->code), 'mtv_delete_code') ); ?>"
                 class="button button-small"
                 onclick="return confirm('¿Eliminar el código <?php echo esc_js($c->code); ?>?');">
                 Borrar
              </a>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="7">No hay códigos promocionales.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}

add_action( 'wp_ajax_mtv_filter_slots',   'mtv_filter_slots_callback' );
add_action( 'wp_ajax_nopriv_mtv_filter_slots', 'mtv_filter_slots_callback' );

function mtv_filter_slots_callback() {
    if ( ! isset($_GET['actividad_id'], $_GET['grupo'], $_GET['date']) ) {
        wp_send_json_error('Parámetros faltantes', 400);
    }

    $actividad_id = intval( $_GET['actividad_id'] );
    $grupo        = intval( $_GET['grupo'] );      // 1 o 2
    $date         = sanitize_text_field( $_GET['date'] );

    global $wpdb;
    // 1) Obtengo post_id desde mi tabla custom
    $tabla = $wpdb->prefix . 'actividades';
    $act   = $wpdb->get_var( $wpdb->prepare(
        "SELECT post_id FROM {$tabla} WHERE id = %d",
        $actividad_id
    ) );
    if ( ! $act ) {
        wp_send_json_error('Actividad no encontrada', 404);
    }

    // 2) Leo todos los slots definidos para esa actividad
    $slots_raw = get_post_meta( $act, '_mtv_clases_slots', true );
    if ( ! is_array($slots_raw) ) {
        $slots_raw = [];
    }

    $slots_filtrados = [];
    foreach ( $slots_raw as $slot ) {
        $hora     = trim( $slot['hora'] ?? '' );
        $capacidad= intval( $slot['plazas'] ?? 0 );
        if ( ! $hora || $capacidad <= 0 ) {
            continue;
        }

        // 3) Cuento reservas YA hechas para este slot
        $reservas = get_posts([
            'post_type'   => 'mtv_reserva',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query'  => [
                [ 'key' => 'actividad_id', 'value' => $actividad_id ],
                [ 'key' => 'diaClase',      'value' => $date          ],
                [ 'key' => 'hora_clase',    'value' => $hora          ],
            ],
            'fields'      => 'ids',
        ]);

        $ocupado_por_otro = false;
        $total_reservas  = 0;
        foreach ( $reservas as $res_id ) {
            $res_grupo = intval( get_post_meta( $res_id, 'nivel_grupo', true ) );
            if ( $res_grupo !== $grupo ) {
                $ocupado_por_otro = true;
                break;
            }
            $total_reservas ++;
        }

        // 4) Si alguien de OTRO grupo ya reservó aquí, lo ocultamos
        if ( $ocupado_por_otro ) {
            continue;
        }

        // 5) Calculo plazas libres (compartidas entre tu grupo)
        $libres = max(0, $capacidad - $total_reservas);
        if ( $libres > 0 ) {
            $slots_filtrados[] = [
                'hora'   => $hora,
                'plazas' => $libres,
            ];
        }
    }

    wp_send_json_success( $slots_filtrados );
}
add_action('wp_ajax_mtv_get_slots',   'mtv_get_slots');
add_action('wp_ajax_nopriv_mtv_get_slots', 'mtv_get_slots');
function mtv_get_slots() {
  global $wpdb;

  $nivel_grupo = intval($_POST['nivel_grupo'] ?? 0); // 1|2
  $actividad   = intval($_POST['actividad_id'] ?? 0);
  $dia         = sanitize_text_field($_POST['dia'] ?? '');

  $post_id = mtv_get_post_id_by_actividad($actividad);
  if (!$post_id) wp_send_json_error('Actividad no encontrada', 404);

  // slots → solo horas
  $slots = get_post_meta($post_id, '_mtv_clases_slots', true);
  if (!is_array($slots)) $slots = [];

  // capacidad del día (por nivel)
  $capacidad_dia = 0;
  $dias_cfg = get_post_meta($post_id, '_mtv_clases_dias', true);
  if (is_array($dias_cfg)) {
    foreach ($dias_cfg as $cfg) {
      $cfg_date  = trim($cfg['date']  ?? '');
      $cfg_nivel = (string)($cfg['nivel'] ?? '1');
      if ($cfg_date === $dia && (int)$cfg_nivel === $nivel_grupo) {
        $capacidad_dia = max(0, (int)($cfg['plazas'] ?? 0));
        break;
      }
    }
  }

  $out = [];
  foreach ($slots as $slot) {
    $hora = trim($slot['hora'] ?? '');
    if (!$hora) continue;

    // ¿alguien del OTRO nivel ya reservó ese día/hora?
    $hay_otro = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} m1 ON m1.post_id=p.ID AND m1.meta_key='actividad_id' AND m1.meta_value=%d
        JOIN {$wpdb->postmeta} m2 ON m2.post_id=p.ID AND m2.meta_key='diaClase' AND m2.meta_value=%s
        JOIN {$wpdb->postmeta} m3 ON m3.post_id=p.ID AND m3.meta_key='hora_clase' AND m3.meta_value=%s
        JOIN {$wpdb->postmeta} m4 ON m4.post_id=p.ID AND m4.meta_key='nivel_grupo'
       WHERE p.post_type='mtv_reserva' AND p.post_status='publish' AND m4.meta_value <> %d",
      $actividad, $dia, $hora, $nivel_grupo
    ));
    if ($hay_otro > 0) continue; // ocultamos el slot

    // ocupadas por ESTE nivel en ese día/hora
    $ocupadas = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} m1 ON m1.post_id=p.ID AND m1.meta_key='actividad_id' AND m1.meta_value=%d
        JOIN {$wpdb->postmeta} m2 ON m2.post_id=p.ID AND m2.meta_key='diaClase' AND m2.meta_value=%s
        JOIN {$wpdb->postmeta} m3 ON m3.post_id=p.ID AND m3.meta_key='hora_clase' AND m3.meta_value=%s
        JOIN {$wpdb->postmeta} m4 ON m4.post_id=p.ID AND m4.meta_key='nivel_grupo' AND m4.meta_value=%d
       WHERE p.post_type='mtv_reserva' AND p.post_status='publish'",
      $actividad, $dia, $hora, $nivel_grupo
    ));

    // Si no hay capacidad configurada, mostramos; si la hay, comprobamos
    if ($capacidad_dia === 0 || $ocupadas < $capacidad_dia) {
      $out[] = ['hora' => $hora];
    }
  }

  wp_send_json_success($out);
}

/**
 * Duplicar actividades: enlace en la lista y handler.
 */
add_filter('post_row_actions', function ($actions, $post) {
    if ($post->post_type !== 'actividad') return $actions;
    if (! current_user_can('edit_post', $post->ID)) return $actions;

    $url = wp_nonce_url(
        admin_url('admin.php?action=mtv_duplicate_actividad&post=' . $post->ID),
        'mtv_duplicate_actividad_' . $post->ID
    );
    $actions['mtv_duplicate'] = '<a href="' . esc_url($url) . '">Duplicar</a>';
    return $actions;
}, 10, 2);

add_action('admin_action_mtv_duplicate_actividad', function () {
    if (empty($_GET['post'])) wp_die('Falta el parámetro post.');
    $orig_id = absint($_GET['post']);

    if (! current_user_can('edit_post', $orig_id)) wp_die('Permisos insuficientes.');
    check_admin_referer('mtv_duplicate_actividad_' . $orig_id);

    $orig = get_post($orig_id);
    if (! $orig || $orig->post_type !== 'actividad') wp_die('Actividad no válida.');

    // 1) Crear el nuevo post en borrador
    $new_id = wp_insert_post([
        'post_type'   => 'actividad',
        'post_status' => 'draft',
        'post_title'  => $orig->post_title . ' (copia)',
        'post_author' => get_current_user_id(),
        'post_content'=> $orig->post_content,
        'post_excerpt'=> $orig->post_excerpt,
    ], true);
    if (is_wp_error($new_id)) wp_die($new_id->get_error_message());

    // 2) Copiar imagen destacada
    $thumb_id = get_post_thumbnail_id($orig_id);
    if ($thumb_id) set_post_thumbnail($new_id, $thumb_id);

    // 3) Copiar TODOS los metadatos (excepto los internos de edición)
    $skip = ['_edit_lock','_edit_last','_thumbnail_id'];
    foreach (get_post_meta($orig_id) as $key => $values) {
        if (in_array($key, $skip, true)) continue;
        foreach ($values as $v) {
            add_post_meta($new_id, $key, maybe_unserialize($v));
        }
    }

    // 4) (Opcional pero recomendado) Sincronizar ahora la tabla personalizada
    if (function_exists('pa_sync_actividad_tabla')) {
        pa_sync_actividad_tabla($new_id, get_post($new_id), true);
    }

    // 5) Abrir la copia en el editor
    wp_safe_redirect( admin_url('post.php?action=edit&post=' . $new_id) );
    exit;
});
function mtv_get_post_id_by_actividad($actividad_id){
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->prefix}actividades WHERE id=%d", $actividad_id
    ));
}
