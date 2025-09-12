<?php
// form-bono.php: plantilla de inscripción para una única actividad
defined('ABSPATH') || exit;

$post_id    = $a->post_id;
$clases_raw = get_post_meta( $post_id, '_mtv_clases_slots', true );

// Si no existe o no es un array, lo convertimos en array vacío
if ( ! is_array( $clases_raw ) ) {
    $clases_raw = array();
}
$bono_slots = get_post_meta( $post_id, '_mtv_bono_slots', true );
if ( ! is_array( $bono_slots ) ) {
    $bono_slots = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inscripción: <?php echo esc_html($titulo); ?></title>
  <link rel="stylesheet" href="<?php echo plugins_url('../../assets/css/form-style.css', __FILE__); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo plugins_url('../../img/Icono-Montaventura.png', __FILE__); ?>">
  <script src="https://js.stripe.com/v3/"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/material_green.css"/>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
<?php
// 1) Carga bruto
$raw = get_post_meta( $post_id, '_mtv_bono_slots', true );
if ( ! is_array( $raw ) ) {
  $raw = [];
}

// 2) Expandir semanalmente
$expanded = [];
$today    = strtotime( date('Y-m-d') );
$one_year = strtotime( '+1 year', $today );

foreach ( $raw as $slot_raw ) {
  $base   = sanitize_text_field( $slot_raw['date']   ?? '' );
  $hora   = sanitize_text_field( $slot_raw['hora']   ?? '' );
  $plazas = intval(          $slot_raw['plazas'] ?? 0 );
  if ( ! $base || ! $hora || $plazas < 1 ) {
    continue;
  }

  // la fecha original
  $ts  = strtotime( $base );
  if ( ! $ts ) {
    continue;
  }
  $expanded[] = array_merge( $slot_raw, [ 'date' => date('Y-m-d', $ts) ] );

  // si está marcado “repeat”
  $rep  = ( isset( $slot_raw['repeat'] ) && $slot_raw['repeat'] === '1' );
  if ( $rep ) {
    $next = $ts + WEEK_IN_SECONDS;
    while ( $next <= $one_year ) {
      $expanded[] = array_merge( $slot_raw, [ 'date' => date('Y-m-d', $next) ] );
      $next      += WEEK_IN_SECONDS;
    }
  }
}

// 3) Sanear en $clean
$clean = [];
foreach ( $expanded as $d ) {
  $date      = sanitize_text_field( $d['date']      ?? '' );
  $hora      = sanitize_text_field( $d['hora']      ?? '' );
  $plazas    = intval(            $d['plazas']    ?? 0 );
  $edad      = sanitize_text_field( $d['edad']      ?? '' );
  $nivel     = intval(            $d['nivel']     ?? 0 );
  $modalidad = sanitize_text_field( $d['modalidad'] ?? '' );

  if ( $date && $hora && $plazas > 0 ) {
    $clean[] = compact( 'date','hora','plazas','edad','nivel','modalidad' );
  }
}

// 4) Sacar todas las fechas únicas
$enabled_dates = array_values( array_unique( array_column( $clean, 'date' ) ) );
?>
<script>
  const dias_dispo    = <?php echo wp_json_encode($clean); ?>;
  const enabled_dates = <?php echo wp_json_encode($enabled_dates); ?>;
</script>

</head>
<body>
<button id="back-button" aria-label="Volver atrás">←</button>
<button id="toggle-view" class="toggle-btn">Info actividad</button>
<div class="layout">
  <aside class="barra-lateral">
     <img
  src="<?php echo plugins_url('../../img/logo_montaventura.webp', __FILE__); ?>"
  alt="Logotipo Montaventura"
  class="logo"
  style="cursor: pointer;"
  onclick="window.location.href='https://www.montaventura.com';"
/>
    <!-- <h1 class="nombre-empresa">Montaventura</h1>
    <address class="direccion">
      Avenida de la Condesa de Chinchón, 107<br />28660 · Boadilla del Monte
    </address>
    <p class="telefono">Tel. 609 217 440</p>
    <p class="correo"><a href="mailto:info@montaventura.com">info@montaventura.com</a></p>-->
    <article class="tarjeta-actividad animar">
      <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($titulo); ?>" class="imagen-actividad" />
      <?php if (!empty($a->descripcion)) : ?>
        <div class="descripcion-actividad" id="descripcionActividad">
          <?php echo wp_kses_post(nl2br($a->descripcion)); ?>
        </div>
        <span class="boton-leer-mas" onclick="toggleDescripcion()">Leer más...</span>
        <script>
        function toggleDescripcion() {
          const desc = document.getElementById("descripcionActividad");
          desc.classList.toggle("expandida");
          const btn = event.target;
          btn.textContent = desc.classList.contains("expandida") ? "Leer menos..." : "Leer más...";
        }
        function toggleDocs() {
          var lista = document.getElementById('doc-list');
          if (!lista) return;
          lista.style.display = (lista.style.display === 'none' ? 'block' : 'none');
        }
        </script>
      <?php endif; ?>
      <ul class="detalles">
        <li>🗓️  Martes a Domingo y Avenida Condesa de Chinchón, 107</li>
        <?php
      // Recuperamos el flag y las plazas desde la base
      $manage = get_post_meta( $a->post_id, '_pa2_manage_plazas', true );
      if ( $manage ):
          $restantes = intval( $a->plazas );
      ?>
        <p class="plazas-restantes" style="font-weight:bold; margin-bottom:1em;">
          👥 Sólo quedan <strong><?php echo esc_html( $restantes ); ?></strong> plazas libres
        </p>
      <?php endif; ?>
        <li>
          <span class="dashicons dashicons-media-document"></span>
          <a href="#" id="toggle-docs" onclick="event.preventDefault(); toggleDocs();">
            🗎 Documentos informativos
          </a>
          <div id="doc-list" style="display:none; margin-top:0.5em;">
            <ul style="list-style:disc; padding-left:1.5em;">
              <li>
                <a href="https://www.montaventura.com/wp-content/uploads/2025/08/Montaventura_Equipacion-cinta_2025.pdf" target="_blank">
                  Equipación recomendada esqui Indoor
                </a>
              </li>
              <li>
                <a href="https://www.montaventura.com/wp-content/uploads/2025/08/Condiciones-Urban-Camp-Boadilla.pdf" target="_blank">
                  Esquí Indoor: términos y condiciones Urban Camp
                </a>
              </li>
            </ul>
          </div>
        </li>
      </ul>
    </article>
  </aside>
<main class="contenido">
  <h2><?php echo esc_html($titulo); ?></h2>

  <!-- 1. Datos del alumno -->
  <div id="form-inscripcion" class="formulario-box">
    <h3>1. Datos del alumno</h3>
    <form id="form-completo" onsubmit="return false;">
      <input type="hidden" id="actividad_id" value="<?php echo esc_attr($a->id); ?>">
      <label for="nombre">Nombre: *</label>
      <input type="text" id="nombre" name="nombre" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+" title="Solo letras y espacios" required>
      <label for="apellidos">Apellidos: *</label>
      <input type="text" id="apellidos" name="apellidos" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+" title="Solo letras y espacios" required>
      <label for="dni">Documento: *</label>
      <input type="text" id="dni" name="dni" pattern="[XYZxyz]?[0-9]{7,8}[A-Za-z]" title="DNI/NIE válido" required>
      <label for="fecha_nacimiento">Fecha de nacimiento: *</label>
      <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" max="<?php echo date('Y-m-d'); ?>" required>
      <label for="email">Email: *</label>
      <input type="email" id="email" name="email" required>
      <label for="telefono">Teléfono: *</label>
      <input type="tel" id="telefono" name="telefono" pattern="^[0-9]{9}$" title="9 dígitos" required>
      <button type="submit" class="boton-inscripcion">Continuar</button>
    </form>
  </div>

  <div id="seccion-inscrito" class="seccion-inscrito" style="display:none;">
    <a href="#" class="volver" onclick="volverAlFormulario()">← Cambiar persona</a>
    <h2>1. Persona inscrita: <strong id="nombreAlumno"></strong>
      <a href="#" class="modificar" onclick="volverAlFormulario()">✎ Modificar</a>
    </h2>
  </div>

  <!-- 2. Tutor legal (solo si menor) -->
  <div id="form-tutor" class="formulario-box" style="display:none;">
    <h3>2. Datos del tutor/a</h3>
    <form id="form-tutor-form" onsubmit="return false;">
      <label for="nombre_tutor">Nombre tutor/a: *</label>
      <input type="text" id="nombre_tutor" name="nombre_tutor" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+" required>
      <label for="apellidos_tutor">Apellidos tutor/a: *</label>
      <input type="text" id="apellidos_tutor" name="apellidos_tutor" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+" required>
      <label for="relacion">Relación: *</label>
      <select id="relacion" name="relacion" required>
        <option value="">— Selecciona —</option>
        <option>Padre</option><option>Madre</option><option>Otro</option>
      </select>
      <label for="documento_tutor">DNI/NIE tutor: *</label>
      <input type="text" id="documento_tutor" name="documento_tutor" pattern="[XYZxyz]?[0-9]{7,8}[A-Za-z]" required>
      <label for="email_tutor">Email tutor: *</label>
      <input type="email" id="email_tutor" name="email_tutor" required>
      <label for="telefono_tutor">Teléfono tutor: *</label>
      <input type="tel" id="telefono_tutor" name="telefono_tutor" pattern="^[0-9]{9}$" required>
      <button type="submit" class="boton-inscripcion">Continuar</button>
    </form>
  </div>

  <div id="seccion-tutor" class="seccion-inscrito" style="display:none;">
    <a href="#" class="volver" onclick="volverAlTutor()">← Volver</a>
    <h2>2. Tutor/a registrado
      <a href="#" class="modificar" onclick="volverAlTutor()">✎ Modificar</a>
    </h2>
  </div>

  <!-- 3. Tipo de inscripción -->
  <div id="seccion-inscripcion-final" class="formulario-box" style="display:none;">
    <h3>3. Tipo de inscripción</h3>
    <!-- AVISO -->
  <p class="aviso">
    ⚠️ Si dispones de un código de bonos, primero selecciona una CLASE (Colectiva o Particular) y pulsa “Siguiente” para aplicarlo.
  </p>
    <form id="form-inscripcion-final" onsubmit="return false;">
        <fieldset required>
          <legend>Selecciona las opciones que desees (Requerido)</legend>
          <label><input type="radio" name="tipo" data-precio="40.00" value="1horaC"> Clase 1 hora Colectiva - 40,00 €</label><br>
          <label><input type="radio" name="tipo" data-precio="40.00" value="1horaP"> Clase 1 hora Particular - 80,00 €</label><br>
          <label><input type="radio" name="tipo" data-precio="40.00" value="4horasC"> Bono 4 horas Colectivas - 130,00 €</label><br>
          <label><input type="radio" name="tipo" data-precio="40.00" value="8horasC"> Bono 8 horas Colectivas - 224,00 €</label><br>
          <label><input type="radio" name="tipo" data-precio="40.00" value="4horasP"> Bono 4 horas Particulares - 220,00 €</label><br>
          <label><input type="radio" name="tipo" data-precio="40.00" value="8horasP"> Bono 8 horas Particulares - 380,00 €</label><br>
        </fieldset>

        <label class="checkbox-full">
      <input type="checkbox" id="acepto_legal" required>
      * He leído y acepto las condiciones legales.
       <span class="ver-mas" onclick="window.open('https://www.montaventura.com/wp-content/uploads/2025/08/TERMINOS-Y-CONDICIONES-BONOS.pdf', '_blank')">Ver más</span>
    </label>
    <label class="checkbox-full">
            <input type="checkbox"> Derechos de Imagen
            <span class="ver-mas" onclick="window.open('https://www.montaventura.com/wp-content/uploads/2025/08/Autorizacion-para-el-Uso-de-Imagen.pdf', '_blank')">Ver más</span>
          </label>

        <label>Observaciones <br></label>
        <textarea rows="4" placeholder="¿Algo más que debamos saber?"></textarea>

      <button type="submit" class="boton-inscripcion">Continuar</button>
    </form>
  </div>

  <div id="seccion-tipo-inscripcion" class="seccion-inscrito" style="display:none;">
    <a href="#" class="volver" onclick="volverAFormularioTipo()">← Cambiar tipo</a>
    <h2>3. Tipo seleccionado: <strong id="resumen-tipo-inscripcion"></strong>
      <a href="#" class="modificar" onclick="volverAFormularioTipo()">✎ Modificar</a>
    </h2>
  </div>
<!-- 4. Nivel de esquí -->
<div id="form-info-adicional" class="formulario-box" style="display:none;">
  <h3>4. Nivel de esquí</h3>
  <form id="form-info-adicional-form" onsubmit="return false;">
    <fieldset required>
      <legend>NIVEL ESQUÍ *</legend>
      <div class="radio-group-horizontal">
        <label>
  <input type="radio" name="nivel" value="A. Debutante|1" required>
  A. Debutante
  <button type="button" class="nivel-info-btn" data-nivel="A. Debutante">ℹ️</button>
</label>
<label>
  <input type="radio" name="nivel" value="B. Descenso en cuña|1">
  B. Descenso en cuña
  <button type="button" class="nivel-info-btn" data-nivel="B. Descenso en cuña">ℹ️</button>
</label>
<label>
  <input type="radio" name="nivel" value="C. Giros en cuña|1">
  C. Giros en cuña
  <button type="button" class="nivel-info-btn" data-nivel="C. Giros en cuña">ℹ️</button>
</label>
<label>
  <input type="radio" name="nivel" value="D. Viraje fundamental|2">
  D. Viraje fundamental
  <button type="button" class="nivel-info-btn" data-nivel="D. Viraje fundamental">ℹ️</button>
</label>
<label>
  <input type="radio" name="nivel" value="E. Paralelo|2">
  E. Paralelo
  <button type="button" class="nivel-info-btn" data-nivel="E. Paralelo">ℹ️</button>
</label>

      </div>
    </fieldset>

    <label for="altura">ESQUÍ ALQUILER (incluido) *</label>
    <input
      type="number"
      id="altura"
      name="altura"
      min="50"
      max="250"
      placeholder="Altura en cm"
      required
    >

    <label for="botas">BOTAS ALQUILER (incluidas) *</label>
    <input
      type="number"
      id="botas"
      name="botas"
      min="0"
      max="50"
      placeholder="Medida del pie en cm (0 si llevas tus botas)"
      required
    >

    <button type="submit" class="boton-inscripcion">Continuar</button>
  </form>
</div>


  <div id="seccion-informacion-resumen" class="seccion-inscrito" style="display:none;">
    <a href="#" class="volver" onclick="volverAlFormularioInfo()">← Cambiar nivel</a>
    <h2>4. Nivel seleccionado: <strong id="resumen-nivel"></strong>
      <a href="#" class="modificar" onclick="volverAlFormularioInfo()">✎ Modificar</a>
    </h2>
  </div>
<?php
  // form.php dentro de if tipo===clases
  $dias_dispo = get_post_meta( $post_id, '_mtv_clases_dias', true );
  if ( ! is_array($dias_dispo) ) $dias_dispo = [];
?>
    <!-- 5. Calendario -->
  <div id="form-calendario" class="formulario-box" style="display:none;">
    <h3>5. Calendario</h3>
    <form id="form-calendario-form" onsubmit="return false;">
      <?php
      // Extrae sólo las fechas con plazas > 0
  $raw   = get_post_meta($post_id, '_mtv_bono_slots', true);
  $clean = [];
  if (is_array($raw)) {
    foreach ($raw as $d) {
      $date      = sanitize_text_field($d['date']     ?? '');
      $hora      = sanitize_text_field($d['hora']     ?? '');
      $plazas    = intval($d['plazas']    ?? 0);
      $edad      = sanitize_text_field($d['edad']     ?? '');
      $nivel     = intval($d['nivel']      ?? 0);
      $modalidad = sanitize_text_field($d['modalidad'] ?? '');
      if ($date && $hora && $plazas > 0) {
        $clean[] = [
          'date'      => $date,
          'hora'      => $hora,
          'plazas'    => $plazas,
          'edad'      => $edad,
          'nivel'     => $nivel,
          'modalidad' => $modalidad,
        ];
      }
    }
  }
?>
      <script>
// 1.1. Pasa a JS el array completo de slots
const dias_dispo    = <?php echo wp_json_encode($clean); ?>;
// 1.2. Pasa solo la lista de fechas habilitadas
const enabled_dates = <?php echo wp_json_encode($enabled_dates); ?>;

</script>

      <label for="diaClase"><strong>Selecciona un día para tu bono:</strong></label>
      <input
        type="text"
        id="diaClase"
        name="diaClase"
        placeholder="Selecciona un día"
        readonly
        required
      />
      <input type="hidden" id="diaClaseISO" name="diaClaseISO" value="" />
<label for="horaClase"><strong>Elige horario:</strong></label>
<select id="horaClase" name="horaClase" required>
  <option value="">— Selecciona —</option>
  <?php
    $slots = get_post_meta( $post_id, '_mtv_bono_slots', true );
    foreach ( $slots as $slot ) {
      $h = sanitize_text_field( $slot['hora']   ?? '' );
      $p = intval(            $slot['plazas'] ?? 0 );
      if ( ! $h || $p < 1 ) continue;
      // value sigue incluyendo las plazas para tu lógica JS/PHP de validación,
      // pero el texto visible ahora solo será la hora:
      printf(
        '<option value="%s|%d">%s</option>',
        esc_attr( $h ),
        $p,
        esc_html( $h )
      );
    }
  ?>
</select>
      <button type="submit" class="boton-inscripcion">Continuar</button>
    </form>
  </div>

  <div id="seccion-calendario" class="seccion-inscrito" style="display:none;">
    <a href="#" class="volver" onclick="volverAlCalendario()">← Cambiar horario</a>
    <h2>5. Horario reservado <a href="#" class="modificar" onclick="volverAlCalendario()">✎ Modificar</a></h2>
    <p>Día: <strong id="resumen-dia"></strong><br>Hora: <strong id="resumen-hora"></strong><br><strong id="resumen-plazas" style="display:none;"></strong></p>
  </div>

<script>


  // Lógica de envío del formulario de calendario
document
  .getElementById('form-calendario-form')
  .addEventListener('submit', function(e) {
    e.preventDefault();

    const diaRaw  = document.getElementById('diaClaseISO').value;
    const slotRaw = document.getElementById('horaClase').value;

    if (!diaRaw || !slotRaw) {
      alert('❌ Selecciona fecha y horario.');
      return;
    }

    const [hora, plazas] = slotRaw.split('|');
    const slot = dias_dispo.find(s => s.date === diaRaw && s.hora === hora);
    if (!slot) {
      alert('❌ Slot no disponible.');
      return;
    }

    document.getElementById('resumen-dia')   .textContent = diaRaw;
    document.getElementById('resumen-hora')  .textContent = hora;
    document.getElementById('resumen-plazas').textContent = plazas + ' plazas';

    document.getElementById('form-calendario')      .style.display = 'none';
    document.getElementById('seccion-calendario')   .style.display = 'block';
    document.getElementById('form-pago')            .style.display = 'block';
});

</script>


  <!-- 6. Pago -->
  <div id="form-pago" class="formulario-box" style="display:none;">
    <h3>6. Pago</h3>
    <label for="promo_code">Código promocional:</label>
    <input type="text" id="promo_code" name="promo_code" placeholder="Si tienes bono…">
    <!-- Paso 0: escondidos para transmitir al checkout -->
<input type="hidden" id="edad_group"   name="edad_group"   value="">
<input type="hidden" id="nivel_group"  name="nivel_group"  value="">
<input type="hidden" id="modalidad"    name="modalidad"    value="">
    <button id="pay-button" class="boton-inscripcion">Reservar</button>
    <div class="importe-total-box">
      <strong>Importe total:</strong>
      <span id="importe-total" class="importe-destacado">0,00 €</span>
    </div>
  </div>
</main>

<div id="nivel-info-modal">
  <div class="modal-content">
    <button class="modal-close" aria-label="Cerrar">×</button>
    <h3 id="nivel-info-title"></h3>
    <p id="nivel-info-desc"></p>
  </div>
</div>

<script src="<?php echo plugins_url('../../assets/js/form-script-bonos.js', __FILE__); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  // — Inicialización Stripe —
  const stripe = Stripe("<?php echo esc_js(STRIPE_PUBLISHABLE_KEY); ?>");

  // — Referencias a formularios y secciones —
  const formAlumno     = document.getElementById('form-completo'),
        secAlumno      = document.getElementById('seccion-inscrito'),
        formTutor      = document.getElementById('form-tutor-form'),
        secTutor       = document.getElementById('seccion-tutor'),
        formTipo       = document.getElementById('form-inscripcion-final'),
        secTipo        = document.getElementById('seccion-tipo-inscripcion'),
        formNivel      = document.getElementById('form-info-adicional-form'),
        secNivel       = document.getElementById('seccion-informacion-resumen'),
        formCal        = document.getElementById('form-calendario-form'),
        secCal         = document.getElementById('seccion-calendario'),
        pagoBox        = document.getElementById('form-pago'),
        payBtn         = document.getElementById('pay-button'),
        importeEl      = document.getElementById('importe-total'),
        nombreAlumEl   = document.getElementById('nombreAlumno');

  // — Estado intermedio —
  const datos = {
    alumno: {},
    tutor:  {},
    tipo:   {},
    nivel:  {},
    cal:    {}
  };

  function scrollSuave(el) {
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // — Paso 1: Alumno —
  formAlumno.addEventListener('submit', e => {
    e.preventDefault();
    datos.alumno = {
      nombre:           document.getElementById('nombre').value.trim(),
      apellidos:        document.getElementById('apellidos').value.trim(),
      dni:              document.getElementById('dni').value.trim(),
      fecha_nacimiento: document.getElementById('fecha_nacimiento').value,
      email:            document.getElementById('email').value.trim(),
      telefono:         document.getElementById('telefono').value.trim(),
    };
    nombreAlumEl.textContent = datos.alumno.nombre;
    formAlumno.closest('.formulario-box').style.display = 'none';
    secAlumno.style.display = 'block';

    // Calcular edad y grupo
    const fn  = new Date(datos.alumno.fecha_nacimiento),
          hoy = new Date(),
          edad = hoy.getFullYear() - fn.getFullYear()
               - ((hoy.getMonth() < fn.getMonth() ||
                  (hoy.getMonth() === fn.getMonth() && hoy.getDate() < fn.getDate())) ? 1 : 0);

    const edad_group = (edad < 12 ? 'infantil' : 'adulto');
    document.getElementById('edad_group').value = edad_group;

    if (edad < 18) {
      formTutor.closest('.formulario-box').style.display = 'block';
      scrollSuave(formTutor.closest('.formulario-box'));
    } else {
      // Saltar tutor
      formTutor.querySelectorAll('input, select').forEach(i => i.disabled = true);
      formTutor.closest('.formulario-box').style.display = 'none';
      secTutor.style.display = 'none';
      showPasoTipo();
    }
  });

  // — Paso 2: Tutor (si es menor) —
  formTutor.addEventListener('submit', e => {
    e.preventDefault();
    datos.tutor = {
      nombre_tutor:    document.getElementById('nombre_tutor').value.trim(),
      apellidos_tutor: document.getElementById('apellidos_tutor').value.trim(),
      relacion:        document.getElementById('relacion').value,
      documento_tutor: document.getElementById('documento_tutor').value.trim(),
      email_tutor:     document.getElementById('email_tutor').value.trim(),
      telefono_tutor:  document.getElementById('telefono_tutor').value.trim()
    };
    formTutor.closest('.formulario-box').style.display = 'none';
    secTutor.style.display = 'block';
    showPasoTipo();
  });

  // — Mostrar Paso 3 (Tipo de inscripción) —
  function showPasoTipo() {
    document.getElementById('form-inscripcion-final').closest('.formulario-box').style.display = 'block';
    scrollSuave(document.getElementById('form-inscripcion-final').closest('.formulario-box'));
  }

  // — Paso 3: Tipo de inscripción —
  formTipo.addEventListener('submit', e => {
    e.preventDefault();
    const selected = formTipo.querySelector('input[name="tipo"]:checked');
    if (!selected) {
      alert('❌ Elige un tipo de inscripción.');
      return;
    }
    const mod = selected.value.endsWith('C') ? 'colectiva' : 'particular';
    document.getElementById('modalidad').value = mod;
    datos.tipo = {
      valor: selected.value,
      precio: parseFloat(selected.dataset.precio)
    };
    document.getElementById('resumen-tipo-inscripcion')
            .textContent = selected.parentNode.textContent.trim();
    formTipo.closest('.formulario-box').style.display = 'none';
    secTipo.style.display = 'block';
    importeEl.textContent = datos.tipo.precio.toFixed(2).replace('.', ',') + ' €';
    // Mostrar formulario Nivel (paso 4)
    formNivel.closest('.formulario-box').style.display = 'block';
    scrollSuave(formNivel.closest('.formulario-box'));
  });

  // — Paso 4: Nivel de esquí + Alquiler —
  formNivel.addEventListener('submit', e => {
    e.preventDefault();
    const sel = formNivel.querySelector('input[name="nivel"]:checked');
    const altura = document.getElementById('altura').value.trim();
    const botas  = document.getElementById('botas').value.trim();
    if (!sel) {
      _showToast('❌ Debes elegir el nivel de esquí.');
      return;
    }
    if (!altura || !botas) {
      _showToast('❌ Rellena altura y botas.');
      return;
    }
    const [code, grupoStr] = sel.value.split('|');
    const grupo = parseInt(grupoStr, 10);
    datos.nivel = { code, grupo };
    document.getElementById('nivel_group').value = grupo;
    document.getElementById('resumen-nivel').textContent = code;
    formNivel.closest('.formulario-box').style.display = 'none';
    secNivel.style.display = 'block';
    formCal.closest('.formulario-box').style.display = 'block';
    scrollSuave(formCal.closest('.formulario-box'));
    initCalendar();
    _showToast(`Nivel ${code} y alquiler guardados.`, 3000);
  });

  // — Paso 5: Calendario —
  formCal.addEventListener('submit', async e => {
    e.preventDefault();
    const diaRaw  = document.getElementById('diaClaseISO').value;
    const slotRaw = document.getElementById('horaClase').value;
    if (!diaRaw || !slotRaw) {
      alert('❌ Selecciona fecha y horario.');
      return;
    }
    const [hora, plazas] = slotRaw.split('|');
    datos.cal = { dia: diaRaw, hora, plazas: parseInt(plazas, 10) };
    document.getElementById('resumen-dia').textContent    = diaRaw;
    document.getElementById('resumen-hora').textContent   = hora;
    document.getElementById('resumen-plazas').textContent = plazas + ' plazas';
    formCal.closest('.formulario-box').style.display = 'none';
    secCal.style.display = 'block';
    pagoBox.style.display = 'block';
    scrollSuave(secCal);
  });

  // — Paso 6: Pago con Stripe —
  payBtn.addEventListener('click', async (e) => {
  e.preventDefault();

  if (datos.tipo.precio <= 0) {
    alert('❌ Importe inválido.');
    return;
  }

  // …tus validaciones previas de slot, etc.

  payBtn.disabled = true;
  payBtn.textContent = 'Procesando...';

  const payload = {
    actividad_id: parseInt(document.getElementById('actividad_id').value, 10),
    nombre: datos.alumno.nombre,
    apellidos: datos.alumno.apellidos,
    dni: datos.alumno.dni,
    fecha_nacimiento: datos.alumno.fecha_nacimiento,
    email: datos.alumno.email,
    telefono: datos.alumno.telefono,
    ...datos.tutor,
    tipo_actividad: datos.tipo.valor,      // '1horaC' | '1horaP' | …
    nivel: datos.nivel.code,               // p.ej. 'A. Debutante'
    nivel_grupo: datos.nivel.grupo,        // 1 ó 2
    modalidad: document.getElementById('modalidad').value,
    edad_group: document.getElementById('edad_group').value,
    diaClaseISO: datos.cal.dia,
    hora_clase: datos.cal.hora,
    importe_total_eur: Number(datos.tipo.precio.toFixed(2)),
    amount_eur: datos.tipo.precio,
    altura: parseInt(document.getElementById('altura').value, 10),
    botas: parseFloat(document.getElementById('botas').value),
    promo_code: document.getElementById('promo_code').value.trim()
  };

  try {
    const res = await fetch(
      `${window.location.origin}/wp-content/plugins/montaventura/checkout.php`,
      { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }
    );

    // Leer como texto primero para evitar romperse si el servidor imprime algo
    const bodyText = await res.text();
    let data;
    try { data = JSON.parse(bodyText); }
    catch { throw new Error('Respuesta no válida del servidor:\n' + bodyText.slice(0, 500)); }

    if (!res.ok || data?.error) {
      throw new Error(data?.error || data?.data || 'Error al iniciar pago');
    }

    // —— Ramas correctas ——
    if (data.redirect) {
      // Bono aplicado => 0 € => ir directo a success (sin Stripe)
      window.location.assign(data.redirect);
      return;
    }

    if (data.id) {
      const { error } = await stripe.redirectToCheckout({ sessionId: data.id });
      if (error) throw new Error(error.message || 'No se pudo abrir Stripe.');
      return;
    }

    throw new Error('Respuesta inesperada: falta "redirect" o "id".');

  } catch (err) {
    console.error(err);
    alert('❌ ' + err.message);
    payBtn.disabled = false;
    payBtn.textContent = 'Reservar';
  }
});

  // Función de apoyo para toasts
  function _showToast(msg, duration = 2000) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), duration);
  }

  // — Modal de descripción de nivel —
  const descripciones = {
    'A. Debutante':      'Nivel para quien nunca ha esquiado. Aprenderás a ponerte los esquís, deslizarte y frenar en cuña.',
    'B. Descenso en cuña':'Ya sabes deslizarte; aquí practicas cuña profunda para controlar la velocidad.',
    'C. Giros en cuña':   'Aprenderás a dirigir tus giros manteniendo la cuña, para iniciar curvas en pendientes suaves.',
    'D. Viraje fundamental': 'Transición de cuña a viraje paralelo, manejo de bastones y coordinación de giros en pendientes medias.',
    'E. Paralelo':        'Dominas el viraje paralelo; perfeccionas carving y control en pendientes pronunciadas.'
  };
  const modal    = document.getElementById('nivel-info-modal'),
        titleEl  = document.getElementById('nivel-info-title'),
        descEl   = document.getElementById('nivel-info-desc'),
        closeBtn = modal.querySelector('.modal-close');

  document.querySelectorAll('.nivel-info-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const nivel = btn.dataset.nivel;
      titleEl.textContent = nivel;
      descEl.textContent  = descripciones[nivel] || 'Descripción no disponible.';
      modal.style.display = 'flex';
    });
  });

  closeBtn.addEventListener('click', () => modal.style.display = 'none');
  modal.addEventListener('click', e => {
    if (e.target === modal) modal.style.display = 'none';
  });
});
</script>

</div>
<div id="toast"></div>
<script>
  document.addEventListener('DOMContentLoaded', function(){
    const btn = document.getElementById('back-button');
    if (btn) {
      btn.addEventListener('click', function(e){
        e.preventDefault();
        history.back();
      });
    }
  });
</script>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const btn     = document.getElementById('toggle-view'),
        sidebar = document.querySelector('.barra-lateral'),
        main    = document.querySelector('main.contenido');

  function setState() {
    if (window.innerWidth < 980) {
      sidebar.classList.add('hidden');
      main   .classList.remove('hidden');
      btn.textContent = 'Info';
      btn.style.display = 'block';
    } else {
      // en escritorio, quitamos clases ocultas y ocultamos el toggle
      sidebar.classList.remove('hidden');
      main   .classList.remove('hidden');
      btn.style.display = 'none';
    }
  }

  // inicial y al redimensionar
  setState();
  window.addEventListener('resize', setState);

  btn.addEventListener('click', () => {
    // alterna la vista
    const showingSidebar = sidebar.classList.contains('hidden');
    sidebar.classList.toggle('hidden');
    main   .classList.toggle('hidden');
    btn.textContent = showingSidebar ? 'Inscribirse' : 'Info actividad';
  });
});
</script>
<!-- Añade justo antes de </body> -->
<style>
  #error-modal-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.6); display: none;
    align-items: center; justify-content: center; z-index: 9999;
  }
  #error-modal {
    background: #fff; border-radius: 8px; padding: 1.5em;
    max-width: 400px; width: 90%; box-shadow: 0 2px 10px rgba(0,0,0,0.4);
    font-family: sans-serif; text-align: left;
  }
  #error-modal h2 {
    margin-top: 0;
    color: #c00;
  }
  #error-modal ul {
    padding-left: 1.2em;
  }
  #error-modal button {
    background: #c00; color: #fff; border: none;
    padding: 0.6em 1.2em; border-radius: 4px;
    cursor: pointer; margin-top: 1em;
  }
</style>

<div id="error-modal-overlay">
  <div id="error-modal">
    <h2>🚫 ¡Uy, algo no cuadra!</h2>
    <p>No puedes reservar ese horario. Las únicas opciones válidas son:</p>
    <ul id="error-modal-list"></ul>
    <button id="error-modal-close">Entendido</button>
  </div>
</div>
<script>
  function showErrorModal(validas) {
  const overlay = document.getElementById('error-modal-overlay');
  const list    = document.getElementById('error-modal-list');
  list.innerHTML = '';
  validas.forEach(str => {
    const li = document.createElement('li');
    li.textContent = str;
    list.appendChild(li);
  });
  overlay.style.display = 'flex';
}

// y al cerrar:
document.getElementById('error-modal-close')
  .addEventListener('click', () => {
    document.getElementById('error-modal-overlay').style.display = 'none';
  });

</script>
<script>
// variable global para poder destruir/reiniciar
let calendario = null;

// Devuelve las fechas filtradas según edad/nivel/modalidad
function getAvailableDates() {
  const edadRaw  = document.getElementById('edad_group').value.toLowerCase();
  const nivelRaw = Number(document.getElementById('nivel_group').value);
  const modRaw   = document.getElementById('modalidad').value.toLowerCase();

  return [...new Set(
    dias_dispo
      .filter(s =>
        s.plazas > 0 &&
        (s.edad.toLowerCase() === edadRaw || s.edad === 'mixto') &&
        s.nivel === nivelRaw &&
        s.modalidad.toLowerCase() === modRaw
      )
      .map(s => s.date)
  )];
}

// Inicializa Flatpickr solo con esas fechas filtradas
function initCalendar() {
  const fechas = getAvailableDates();
  if (calendario) calendario.destroy();
  calendario = flatpickr("#diaClase", {
    locale: 'es',
    dateFormat: 'Y-m-d',
    enable: fechas,
    onChange(selectedDates, dateStr) {
      // 1) ISO oculto
      document.getElementById('diaClaseISO').value = dateStr;
      // 2) input bonito
      document.getElementById('diaClase').value =
        selectedDates[0].toLocaleDateString('es-ES', {
          weekday: 'long', day: 'numeric',
          month:   'long', year: 'numeric'
        });
      // 3) repoblar select de horas (igual a tu código actual)
      const edadRaw  = document.getElementById('edad_group').value.toLowerCase();
      const nivelRaw = Number(document.getElementById('nivel_group').value);
      const modRaw   = document.getElementById('modalidad').value.toLowerCase();
      const opciones = dias_dispo.filter(s =>
        s.date === dateStr &&
        s.plazas > 0 &&
        (s.edad.toLowerCase() === edadRaw || s.edad === 'mixto') &&
        s.nivel === nivelRaw &&
        s.modalidad.toLowerCase() === modRaw
      );
      if (!opciones.length) {
        // ... tu showErrorModal(validas)
        return;
      }
      const select = document.getElementById('horaClase');
      select.innerHTML = '<option value="">— Selecciona —</option>';
      opciones.forEach(s => {
        const opt = document.createElement('option');
        opt.value = `${s.hora}|${s.plazas}`;
        opt.textContent = s.hora;
        select.appendChild(opt);
      });
      select.style.display = 'inline-block';
    }
  });
}
</script>
</body>
</html>