<?php
/**
 * Template: Reserva Montaventura
 * Muestra un card por cada slot disponible de cada actividad.
 * — “Excursión” y “Otro” usan la columna $a->plazas de la tabla principal.
 * — “Clases” y “Bono” muestran solo los horarios (sin plazas).
 */

define('WP_USE_THEMES', false);
require_once __DIR__ . '/../../../../wp-load.php';

// Para remove_accents()
require_once ABSPATH . 'wp-includes/formatting.php';

/**
 * Normaliza el meta "_mtv_tipo_actividad" a: excursion | clases | bono | otro
 */
function mtv_map_tipo($raw) {
  $t = remove_accents(strtolower(trim((string)$raw)));
  $t = preg_replace('/\s+/', ' ', $t); // colapsa espacios

  if (strpos($t, 'excursion') !== false || strpos($t, 'viaje') !== false) return 'excursion';
  if (strpos($t, 'clase')     !== false) return 'clases';
  if (strpos($t, 'bono')      !== false) return 'bono';
  if (strpos($t, 'otro')      !== false) return 'otro';
  return 'otro'; // fallback seguro
}

global $wpdb;
$tabla = $wpdb->prefix . 'actividades';

function dias_para_fecha( $fecha ) {
    if (empty($fecha)) {          // null, '', etc.
        return PHP_INT_MAX;
    }
    $hoy    = new DateTime('today');
    $inicio = DateTime::createFromFormat('Y-m-d', (string)$fecha);
    if ( ! $inicio ) {
        return PHP_INT_MAX;
    }
    $diff = $hoy->diff($inicio);
    return (int) $diff->format('%r%a');
}


// 1) Mostrar formulario si se pasa actividad
$selected_id = isset($_GET['actividad']) ? intval($_GET['actividad']) : null;
if ( $selected_id ) {
    $a = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$tabla} WHERE id = %d", $selected_id)
    );
    if ( $a ) {
        $titulo      = get_the_title( $a->post_id );
        $img         = get_the_post_thumbnail_url( $a->post_id, 'medium' )
                       ?: plugin_dir_url(__FILE__) . '../../img/placeholder.png';
        $fecha       = $a->fecha_inicio ?: 'Fecha por confirmar';
        $hora        = $a->hora ?: '';
        $lugar       = esc_html( $a->lugar );
        $precio      = number_format_i18n( $a->precio, 2 ) . ' €';
        $descripcion = esc_html( $a->descripcion );

        $raw_tipo = get_post_meta( $a->post_id, '_mtv_tipo_actividad', true );
        $type     = mtv_map_tipo($raw_tipo);

        switch ( $type ) {
            case 'excursion':
            case 'otro':
                include __DIR__ . '/formulario/form-excursion.php';
                break;
            case 'clases':
                include __DIR__ . '/formulario/form-clases.php';
                break;
            case 'bono':
                include __DIR__ . '/formulario/form-bono.php';
                break;
            default:
                wp_die( 'Tipo de actividad no válido.' );
        }
        exit;
    }
}

// 2) Listado futuras o sin fecha
$sql = "
SELECT 
  a.*,
  CAST(pm.meta_value AS UNSIGNED) AS orden
FROM {$tabla} a
LEFT JOIN {$wpdb->postmeta} pm
  ON pm.post_id = a.post_id
 AND pm.meta_key = '_mtv_orden'
WHERE a.tipo_actividad = 'bono'
   OR a.tipo_actividad = 'clases'
   OR a.fecha_inicio IS NULL
   OR a.fecha_inicio >= CURDATE()
ORDER BY 
  COALESCE(CAST(pm.meta_value AS UNSIGNED), 999999) ASC,  /* primero por orden elegido */
  a.fecha_inicio ASC                                      /* desempate por fecha */
";
$actividades = $wpdb->get_results( $sql );
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Reserva Montaventura</title>
  <link rel="stylesheet" href="../assets/css/styles.css">
  <link rel="icon" href="<?php echo plugins_url('../img/Icono-Montaventura.png', __FILE__); ?>">
</head>
<body>
<aside class="barra-lateral">
  <img src="../img/logo_montaventura.webp" alt="Logotipo Montaventura" class="logo"/>
  <h1 class="nombre-empresa">Montaventura</h1>
  <address class="direccion">
    Avenida de la Condesa de Chinchón, 107<br>28660 · Boadilla del Monte
  </address>
  <p class="telefono">Tel. 609 217 440</p>
  <p class="correo"><a href="mailto:info@montaventura.com">info@montaventura.com</a></p>
</aside>

<main class="contenido">
  <h2>Próximas actividades</h2>
  <section id="lista-actividades" class="grid-actividades">

  <?php if ( $actividades ): ?>
    <?php foreach ( $actividades as $a ): ?>
      <?php
      $raw_tipo = get_post_meta( $a->post_id, '_mtv_tipo_actividad', true );
      $tipo     = mtv_map_tipo($raw_tipo);

      // ---- Lógica específica para "clases": ocultar si no quedan fechas futuras en _mtv_clases_dias
      if ( $tipo === 'clases' ) {
          $clases_dias = get_post_meta( $a->post_id, '_mtv_clases_dias', true );
          $clases_dias = is_array($clases_dias) ? $clases_dias : [];

          $hoy = new DateTime('today');
          $hay_futuras = false;

          foreach ( $clases_dias as $slot ) {
              $raw = trim($slot['date'] ?? '');
              if (!$raw) continue;

              // Acepta "2025-08-10" y "10/08/2025"
              $dt = DateTime::createFromFormat('Y-m-d', $raw)
                    ?: DateTime::createFromFormat('d/m/Y', $raw);
              if (!$dt) continue;

              $dt->setTime(0,0,0);
              if ( $dt >= $hoy ) { $hay_futuras = true; break; }
          }

          if ( ! $hay_futuras ) {
              continue; // ya pasó la última fecha -> ocultar la actividad
          }
      }

      // Omitir pasadas SOLO para NO-bono y NO-clases (ej. excursión/otro)
      if ( $tipo !== 'bono'
           && $tipo !== 'clases'
           && ! empty( $a->fecha_inicio )
           && dias_para_fecha( $a->fecha_inicio ) < 0 ) {
        continue;
      }

      $titulo     = get_the_title( $a->post_id );
      $img        = get_the_post_thumbnail_url( $a->post_id, 'medium' )
                     ?: 'https://via.placeholder.com/300x180?text=Actividad';
      $fecha_hora = trim(
        ( !empty($a->fecha_inicio) ? $a->fecha_inicio : '' ) . ' ' .
        ( !empty($a->hora)         ? $a->hora         : '' )
      );
      if ( $fecha_hora === '' ) $fecha_hora = 'Fecha por confirmar';

      $dias_faltan = dias_para_fecha( $a->fecha_inicio );
      ?>

      <?php if ( in_array($tipo, ['excursion','otro'], true) ): ?>
  <article class="tarjeta-actividad animar">
    <img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $titulo ); ?>" class="imagen-actividad"/>
    <div class="info">
      <h3 class="titulo"><?php echo esc_html( $titulo ); ?></h3>
      <ul class="detalles">
        <li>📅 <?php echo esc_html( $fecha_hora ); ?></li>
        <li>📍 <?php echo esc_html( $a->lugar ); ?></li>
        <?php
          $precio_texto = trim( get_post_meta( $a->post_id, '_mtv_precio_texto', true ) );
          if ( $precio_texto !== '' ) : ?>
          <li class="precio-texto">💶 <?php echo esc_html( $precio_texto ); ?></li>
        <?php endif; ?>
        <?php if ( $dias_faltan <= 7 ): ?>
          <li class="ultimas-urgencia"><strong>¡Últimas plazas disponibles!</strong></li>
          <li class="ultimas-urgencia"><strong>¡No te quedes sin tu plaza!</strong></li>
        <?php endif; ?>
      </ul>
    </div>

    <!-- SIEMPRE mostrar el botón (sin comprobar plazas) -->
    <a class="btn-inscribirse" href="?actividad=<?php echo intval( $a->id ); ?>">Inscribirme</a>
  </article>
<?php endif; ?>


      <?php if ( $tipo === 'bono' ): ?>
        <?php
        $bono_raw = get_post_meta( $a->post_id, '_mtv_bono_slots', true );
        $bono_raw = is_array( $bono_raw ) ? $bono_raw : [];
        $slots    = array_filter( $bono_raw, fn( $s ) => ! empty( $s['hora'] ) );
        ?>
        <?php if ( $slots ): ?>
          <article class="tarjeta-actividad animar">
            <img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $titulo ); ?>" class="imagen-actividad"/>
            <div class="info">
              <h3 class="titulo"><?php echo esc_html( $titulo ); ?></h3>
              <ul class="detalles">
                <li>🗓️ De Martes a Domingo</li>
                <li>📍 <?php echo esc_html( $a->lugar ); ?></li>
                <?php
                  $precio_texto = trim( get_post_meta( $a->post_id, '_mtv_precio_texto', true ) );
                  if ( $precio_texto !== '' ) : ?>
                  <li class="precio-texto">💶 <?php echo esc_html( $precio_texto ); ?></li>
                <?php endif; ?>
              </ul>
            </div>
            <a class="btn-inscribirse" href="?actividad=<?php echo intval( $a->id ); ?>">Inscribirme</a>
          </article>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ( $tipo === 'clases' ): ?>
        <?php
        $clases_raw = get_post_meta( $a->post_id, '_mtv_clases_slots', true );
        $clases_raw = is_array( $clases_raw ) ? $clases_raw : [];
        $horarios   = array_filter( $clases_raw, fn( $s ) => ! empty( $s['hora'] ) );
        ?>
        <?php if ( $horarios ): ?>
          <?php
            $prim_hora = reset( $horarios )['hora'];
            $fh_slot   = trim( (! empty( $a->fecha_inicio ) ? $a->fecha_inicio : '' ) . ' ' . $prim_hora );
            if ( trim( $fh_slot ) === '' ) {
              $fh_slot = 'Hora por confirmar';
            }
          ?>
          <article class="tarjeta-actividad animar">
            <img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $titulo ); ?>" class="imagen-actividad"/>
            <div class="info">
              <h3 class="titulo"><?php echo esc_html( $titulo ); ?></h3>
              <ul class="detalles">
                <li>📅 Sábados y Domingos</li>
                <li>📍 <?php echo esc_html( $a->lugar ); ?></li>
                <?php
                  $precio_texto = trim( get_post_meta( $a->post_id, '_mtv_precio_texto', true ) );
                  if ( $precio_texto !== '' ) : ?>
                  <li class="precio-texto">💶 <?php echo esc_html( $precio_texto ); ?></li>
                <?php endif; ?>
              </ul>
            </div>
            <a class="btn-inscribirse" href="?actividad=<?php echo intval( $a->id ); ?>">Inscribirme</a>
          </article>
        <?php endif; ?>
      <?php endif; ?>

    <?php endforeach; ?>
  <?php else: ?>
    <p style="text-align:center;">No hay actividades disponibles.</p>
  <?php endif; ?>

  </section>
</main>
</body>
</html>
