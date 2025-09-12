<?php
// form-clase.php: plantilla de inscripción para una única actividad
defined('ABSPATH') || exit;

$post_id    = $a->post_id;
$clases_raw = get_post_meta( $post_id, '_mtv_clases_slots', true );
$curso_anual = get_post_meta( $post_id, '_mtv_curso_anual', true ) === '1';
$fecha_ini_anual = get_post_meta( $post_id, '_mtv_fecha_inicio', true ); // 'YYYY-mm-dd' o ''
// Si no existe o no es un array, lo convertimos en array vacío
if ( ! is_array( $clases_raw ) ) {
    $clases_raw = array();
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
  <!-- en el <head> -->
<link
  rel="stylesheet"
  href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/material_green.css"
/>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
<?php

$usar_tarifa_ndias = get_post_meta( $post_id, '_mtv_tarifa_por_num_dias', true ) === '1';
$precio_reserva = floatval( get_post_meta( $post_id, '_mtv_precio', true ) );
$precio_base    = get_post_meta( $post_id, '_mtv_precio_base', true );
$precio_base    = ($precio_base !== '' ? floatval($precio_base) : $precio_reserva);
$p1 = floatval( get_post_meta( $post_id, '_mtv_precio_1dia',  true ) );
$p2 = floatval( get_post_meta( $post_id, '_mtv_precio_2dias', true ) );
$p3 = floatval( get_post_meta( $post_id, '_mtv_precio_3dias', true ) );
$p4 = floatval( get_post_meta( $post_id, '_mtv_precio_4dias', true ) );
?>
<script>
  const usarTarifaPorDias = <?php echo $usar_tarifa_ndias ? 'true':'false'; ?>;
   const precioBase        = <?php echo json_encode($precio_base); ?>;       // p. ej. 1500
  const precioReserva     = <?php echo json_encode($precio_reserva); ?>;    // p. ej. 500
  const precioPorDias     = { 1: <?php echo $p1 ?: 0; ?>, 2: <?php echo $p2 ?: 0; ?>, 3: <?php echo $p3 ?: 0; ?>, 4: <?php echo $p4 ?: 0; ?> };
  const cursoAnual   = <?php echo $curso_anual ? 'true' : 'false'; ?>;
  const fechaInicioAnual = <?php echo json_encode($fecha_ini_anual ?: ''); ?>; // 'YYYY-mm-dd' o ''
  // Ocultar de inicio el bloque de calendario si es anual
  document.addEventListener('DOMContentLoaded', () => {
    if (cursoAnual) {
      const calBox = document.getElementById('form-calendario');
      if (calBox) calBox.style.display = 'none';
    }
  });
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
        <li>🗓️ <?php echo esc_html(trim($fecha . ' ' . $hora)); ?></li>
        <li>📍 <?php echo esc_html($lugar); ?></li>
        <?php
      // Recuperamos el flag y las plazas desde la base
      $manage = get_post_meta( $a->post_id, '_pa2_manage_plazas', true );
      if ( $manage ):
          $restantes = intval( $a->plazas );
      ?>
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
    <form id="form-inscripcion-final" onsubmit="return false;">
        <fieldset>
  <legend>Hora de clase disponible:</legend>
<?php foreach ( $clases_raw as $slot ) :
  $hora = sanitize_text_field( $slot['hora'] ?? '' );
  if ( ! $hora ) continue;

  // Etiqueta: si hay tarifas por nº de días, no mostramos precio fijo aquí
$label_precio = $usar_tarifa_ndias
  ? 'precio según nº de fechas (1/2/3/4)'
  : number_format_i18n( $precio_base, 2 ) . ' €';

  $label = sprintf('%1$s — %2$s', $hora, $label_precio);
?>
  <label>
    <input
      type="radio"
      name="tipo_clase"
      value="<?php echo esc_attr( $hora ); ?>"
      data-precio="<?php echo esc_attr( $precio_base ); ?>"
      required
    >
    <?php echo esc_html( $label ); ?>
  </label><br>
  <?php endforeach; ?>
</fieldset>


        <label class="checkbox-full">
      <input type="checkbox" id="acepto_legal" required>
      * He leído y acepto las condiciones legales.
       <span class="ver-mas" onclick="window.open('https://www.montaventura.com/wp-content/uploads/2025/08/TERMINOS-Y-CONDICIONES-CURSOS-ESQUIS.pdf', '_blank')">Ver más</span>
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
  // form.php dentro de if tipo===clases: leemos la meta RAW
  $raw = get_post_meta( $post_id, '_mtv_clases_dias', true );
  if ( ! is_array( $raw ) ) {
      $raw = [];
  }

 // Generamos $expanded con la fecha original + repeticiones semanales hasta 1 año
$expanded = [];
$hoy       = new DateTimeImmutable();
$fin       = $hoy->modify('+1 year');

foreach ( $raw as $slot ) {
    $fecha = DateTimeImmutable::createFromFormat('Y-m-d', sanitize_text_field( $slot['date'] ?? '' ));
    if ( ! $fecha ) continue;

    $nivel  = intval( $slot['nivel'] ?? 0 );
    $plazas = intval( $slot['plazas'] ?? 0 );

    // guardamos la fecha original
    $expanded[] = [
      'date'   => $fecha->format('Y-m-d'),
      'nivel'  => $nivel,
      'plazas' => $plazas,
    ];

    // si marcaste repeat, añadimos semanas hasta fin
    if ( ! empty( $slot['repeat'] ) && $slot['repeat'] === '1' ) {
        $iter = $fecha->modify('+1 week');
        while ( $iter <= $fin ) {
            $expanded[] = [
              'date'   => $iter->format('Y-m-d'),
              'nivel'  => $nivel,
              'plazas' => $plazas,
            ];
            $iter = $iter->modify('+1 week');
        }
    }
}
$dias_dispo    = $expanded;
$enabled_dates = array_map( fn( $d ) => $d['date'], $expanded );

?>
<script>
  const dias_dispo    = <?php echo wp_json_encode( $dias_dispo ); ?>;
  const enabled_dates = <?php echo wp_json_encode( $enabled_dates ); ?>;
</script>

<div id="form-calendario" class="formulario-box" style="display:none;">
  <h3>5. Calendario</h3>
  <form id="form-calendario-form" onsubmit="return false;">
    <?php
  // antes del HTML, extrae sólo las fechas con plazas > 0:
  $enabled_dates = array_map(
    fn($s) => sanitize_text_field($s['date']),
    array_filter($dias_dispo, fn($s) => intval($s['plazas'] ?? 0) > 0)
  );
?>
<label for="diaClase">Días de clase (1–4): *</label>
<input
  type="text"
  id="diaClase"
  name="diaClase"
 placeholder="Eliga los días disponibles"
  readonly
  required
/>

<input
  type="hidden"
  id="diaClaseISO"
  name="diaClaseISO"
  value=""
/>
    <label for="horaClase">Hora de clase: *</label>
    <select id="horaClase" name="horaClase" required>
      <option value="">— Selecciona —</option>
      <?php foreach ( $clases_raw as $slot ) :
        $h = sanitize_text_field( $slot['hora'] ?? '' );
        if ( ! $h ) continue;
      ?>
      <option value="<?php echo esc_attr( $h ); ?>">
        <?php echo esc_html( $h ); ?>
      </option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="boton-inscripcion">Continuar</button>
  </form>
</div>

  <div id="seccion-calendario" class="seccion-inscrito" style="display:none;">
  <?php if ( ! $curso_anual ): ?>
    <a href="#" class="volver" onclick="volverAlCalendario()">← Cambiar horario</a>
  <?php endif; ?>

  <h2>5. Horario reservado
    <?php if ( ! $curso_anual ): ?>
      <a href="#" class="modificar" onclick="volverAlCalendario()">✎ Modificar</a>
    <?php endif; ?>
  </h2>

  <p>Día: <strong id="resumen-dia"></strong><br>Hora: <strong id="resumen-hora"></strong></p>
    <input type="hidden" id="promo_code" name="promo_code" value="">
<button id="pay-button" class="boton-inscripcion">Reservar</button>
<div class="importe-total-box">
  <strong>Importe total:</strong>
  <span id="importe-total" class="importe-destacado">0,00 €</span>
  <?php
$precio_reserva = floatval( get_post_meta( $post_id, '_mtv_precio', true ) );
?>
<div id="nota-precio-reserva" class="nota-precio">
  (Precio de la reserva <?php echo number_format_i18n( $precio_reserva, 2 ); ?> €)
</div>

</div>

  </div>
  </div>

<div id="nivel-info-modal">
  <div class="modal-content">
    <button class="modal-close" aria-label="Cerrar">×</button>
    <h3 id="nivel-info-title"></h3>
    <p id="nivel-info-desc"></p>
  </div>
</div><script>
  // Slots tal y como los guardas en meta, expuestos a JS con su "grupo"
  const clases_raw = <?php echo wp_json_encode( array_map(
      fn($s) => [
          'hora'  => sanitize_text_field( $s['hora']   ?? '' ),
          'grupo' => intval(        $s['grupo']  ?? 0 ),
      ],
      get_post_meta( $post_id, '_mtv_clases_slots', true ) ?: []
  ) ); ?>;
</script>
<script>
  function ymd(d){ return d.toISOString().slice(0,10); }

// Próximas `count` fechas del día de semana `dow` (0=domingo … 6=sábado)
// Empieza en el siguiente de hoy (si hoy coincide, salta a la semana que viene).
function proximasFechas(dow, count){
  const out = [];
  const base = new Date(); base.setHours(0,0,0,0);
  let diff = (dow - base.getDay() + 7) % 7;
  if (diff === 0) diff = 7; // “siguientes”, no hoy
  let first = new Date(base); first.setDate(base.getDate()+diff);
  for (let i=0;i<count;i++){
    const d = new Date(first); d.setDate(first.getDate() + i*7);
    out.push( ymd(d) );
  }
  return out;
}

// 1) Declaramos fpDias en un scope global/script
let fpDias;

  document.addEventListener('DOMContentLoaded', () => {
    // 2) Inicializamos Flatpickr y guardamos la instancia
    fpDias = flatpickr("#diaClase", {
      locale: 'es',
      dateFormat: 'Y-m-d',
      mode: 'multiple',
      maxDateCount: 4,
      enable: enabled_dates,  // todas las fechas al cargar
      onChange(selectedDates, dateStr, instance) {
        if (!selectedDates.length) return;
        document.getElementById('diaClaseISO').value = dateStr;
        instance.input.value = selectedDates
          .map(d => d.toLocaleDateString('es-ES', {
            weekday:'long', day:'numeric', month:'long', year:'numeric'
          }))
          .join(', ');
        document.getElementById('horaClase').style.display = 'inline-block';
        document.querySelector('label[for="horaClase"]').style.display = 'block';
        const n   = selectedDates.length;
const imp = calcularImportePorFechas(n);
datos.tipo.precio = imp;
pintarImporte(imp);

      }
    });

    // 3) Ahora que fpDias existe, podemos escuchar el submit de nivel aquí
    const formNivel = document.getElementById('form-info-adicional-form');
    formNivel.addEventListener('submit', e => {
      e.preventDefault();
      const sel = formNivel.querySelector('input[name="nivel"]:checked');
      if (!sel) {
        _showToast('❌ Debes elegir el nivel de esquí.');
        return;
      }
      const [ , rawGrupo ] = sel.value.split('|');
      const grupo = parseInt(rawGrupo, 10);

      // calculamos sólo las fechas de ese grupo
      const fechasParaNivel = dias_dispo
        .filter(d => Number(d.nivel) === grupo)
        .map(d => d.date);

      // 4) Reconfiguramos Flatpickr
      fpDias.set('enable', fechasParaNivel);
      fpDias.clear();
      document.getElementById('diaClaseISO').value = '';
      document.getElementById('diaClase').value    = '';

      _showToast(`Nivel ${sel.value.split('|')[0]} seleccionado.`);
      formNivel.parentNode.style.display   = 'none';
      document.getElementById('seccion-informacion-resumen').style.display = 'block';
      document.getElementById('form-calendario').style.display            = 'block';
      scrollSuave(document.getElementById('form-calendario'));
    });
  });
</script>

<!-- 6) Por último, carga tu script de lógica -->
<script src="<?php echo plugins_url('../../assets/js/form-script.js', __FILE__); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  // — Inicialización Stripe —
  const stripe     = Stripe("<?php echo esc_js(STRIPE_PUBLISHABLE_KEY); ?>");

  // — Referencias a formularios y secciones —
  const formAlumno   = document.getElementById('form-completo'),
        secAlumno    = document.getElementById('seccion-inscrito'),
        formTutor    = document.getElementById('form-tutor-form'),
        secTutor     = document.getElementById('seccion-tutor'),
        formTipo     = document.getElementById('form-inscripcion-final'),
        secTipo      = document.getElementById('seccion-tipo-inscripcion'),
        formNivel    = document.getElementById('form-info-adicional-form'),
        secNivel     = document.getElementById('seccion-informacion-resumen'),
        formCal      = document.getElementById('form-calendario-form'),
        secCal       = document.getElementById('seccion-calendario'),
        pagoBox      = document.getElementById('form-pago'),
        payBtn       = document.getElementById('pay-button'),
        importeEl    = document.getElementById('importe-total'),
        nombreAlumEl = document.getElementById('nombreAlumno');
const precioStripe = precioReserva;
document.addEventListener('DOMContentLoaded', () => {
  const nota = document.getElementById('nota-precio-reserva');
  if (nota) {
nota.textContent = `Precio de la reserva ${precioStripe.toFixed(2).replace('.', ',')} €`;
  }
});

function calcularImportePorFechas(n){
  if (!usarTarifaPorDias || n <= 0) return precioBase; // anual o sin selección → 1500
  const p = precioPorDias[n];
  return (p && p > 0) ? p : precioBase;
}
function pintarImporte(importe){
  importeEl.textContent = importe.toFixed(2).replace('.', ',') + ' €';
}

  // — Estado intermedio —
  const datos = {
    alumno: {},
    tutor:  {},
    tipo:   {},
    nivel:  {},
    cal:    {}
  };

  function scrollSuave(el){
    if(el) el.scrollIntoView({ behavior:'smooth', block:'start' });
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
    formAlumno.parentNode.style.display = 'none';
    secAlumno.style.display = 'block';

    // edad
    const fn  = new Date(datos.alumno.fecha_nacimiento),
          hoy = new Date(),
          edad = hoy.getFullYear() - fn.getFullYear()
               - ((hoy.getMonth()<fn.getMonth() ||
                  (hoy.getMonth()===fn.getMonth() && hoy.getDate()<fn.getDate())) ? 1 : 0);

    if (edad < 18) {
      formTutor.parentNode.style.display = 'block';
      scrollSuave(formTutor.parentNode);
    } else {
      // saltar tutor
      formTutor.querySelectorAll('input,select').forEach(i=>i.disabled=true);
      formTutor.parentNode.style.display = 'none';
      secTutor.style.display = 'none';
      formTipo.parentNode.style.display = 'block'; // paso 3
      scrollSuave(formTipo.parentNode);
    }
  });

  // — Paso 2: Tutor (si menor) —
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
    formTutor.parentNode.style.display = 'none';
    secTutor.style.display = 'block';
    formTipo.parentNode.style.display = 'block'; // paso 3
    scrollSuave(formTipo.parentNode);
  });

  // — Paso 3: Tipo de inscripción —
  formTipo.addEventListener('submit', e => {
    e.preventDefault();
    const sel = formTipo.querySelector('input[name="tipo_clase"]:checked');
    if (!sel) {
      alert('❌ Elige un tipo de inscripción.');
      return;
    }
    datos.tipo = { valor: sel.value, precio: parseFloat(sel.dataset.precio) };
    document.getElementById('resumen-tipo-inscripcion')
            .textContent = sel.nextSibling.textContent.trim();
    formTipo.parentNode.style.display = 'none';
    secTipo.style.display = 'block';
    scrollSuave(secTipo);
    pintarImporte( calcularImportePorFechas(0) );
  document.getElementById('form-info-adicional').style.display = 'block';
  });
  
  // Paso 4: Nivel de esquí (con toast y parseo correcto)
formNivel.addEventListener('submit', e => {
  e.preventDefault();

  const sel = formNivel.querySelector('input[name="nivel"]:checked');
  if (!sel) { _showToast('❌ Debes elegir el nivel de esquí.'); return; }

  // 1) Parseo "Código|grupo"
  const [rawCode, rawGrupo] = sel.value.split('|');
  const code  = rawCode.trim();        // p.ej. "A. Debutante"
  const grupo = parseInt(rawGrupo,10); // 1 ó 2

  // 2) Guardamos nivel elegido
  datos.nivel = { code, grupo };
  _showToast(`Nivel ${code} seleccionado.`);
// ========= CURSO ANUAL =========
if (cursoAnual) {
  // 1) Fechas que has creado en el admin para este grupo (nivel)
  const fechasNivel = dias_dispo
    .filter(d => Number(d.nivel) === grupo)
    .map(d => d.date)
    .sort();                // ISO YYYY-MM-DD ordena bien

  if (!fechasNivel.length) {
    alert('⚠️ No hay días creados para este nivel.');
    return;
  }

  const startISO = fechasNivel[0];
  const endISO   = fechasNivel[fechasNivel.length - 1];

  // 2) Genera 1 fecha por semana entre start y end (incluyendo extremos)
  function generarEntre(startISO, endISO) {
    const out = [];
    let d   = new Date(startISO + 'T00:00:00');
    const f = new Date(endISO   + 'T00:00:00');
    d.setHours(0,0,0,0);
    while (d <= f) {
      out.push( ymd(d) );       // ymd(d) → 'YYYY-MM-DD'
      d.setDate(d.getDate() + 7);
    }
    return out;
  }

  const todas = generarEntre(startISO, endISO);
  const total = todas.length;

  // 3) Hora elegida en el paso 3 (o primera definida)
  const horaElegida = (datos.tipo && datos.tipo.valor) ? datos.tipo.valor : (clases_raw[0]?.hora || '');
  if (!horaElegida) { alert('❌ No hay horas configuradas para este curso.'); return; }

  // 4) Guardamos todas las sesiones y mostramos resumen (precio no prorrateado)
  datos.cal = { dias: todas, hora: horaElegida };
  datos.tipo.precio = precioBase;
  pintarImporte(precioBase);

  const dow = new Date(startISO + 'T00:00:00').getDay(); // 6=sáb, 0=dom
  const diaTxt = (dow === 6) ? (total === 1 ? 'sábado' : 'sábados')
                             : (total === 1 ? 'domingo' : 'domingos');
  const fmt = iso => new Date(iso + 'T00:00:00').toLocaleDateString('es-ES');

  document.getElementById('resumen-dia').textContent =
    `Curso anual: ${total} ${diaTxt} (${fmt(startISO)} → ${fmt(endISO)})`;
  document.getElementById('resumen-hora').textContent = horaElegida;

  // UI
  formNivel.parentNode.style.display = 'none';
  document.getElementById('seccion-informacion-resumen').style.display = 'block';
  document.getElementById('form-calendario').style.display = 'none';
  document.getElementById('seccion-calendario').style.display = 'block';
  scrollSuave(document.getElementById('seccion-calendario'));
  return;
}
// ========= FIN CURSO ANUAL =========

  // Flujo normal con calendario (no anual)
  const fechasParaNivel = dias_dispo
    .filter(d => Number(d.nivel) === grupo)
    .map(d => d.date);

  fpDias.set('enable', fechasParaNivel);
  fpDias.clear();
  document.getElementById('diaClaseISO').value = '';
  document.getElementById('diaClase').value    = '';

  formNivel.parentNode.style.display = 'none';
  document.getElementById('seccion-informacion-resumen').style.display = 'block';
  document.getElementById('form-calendario').style.display = 'block';
  scrollSuave(document.getElementById('form-calendario'));
});



// Función de apoyo para toasts
function _showToast(msg, duration = 2000) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), duration);
}

// Paso 4 bis: Leer alquileres y actualizar resumen (sin tocar datos.nivel)
const formInfo    = document.getElementById('form-info-adicional-form');
const resumenDiv  = document.getElementById('seccion-informacion-resumen');
const resumenNivel= document.getElementById('resumen-nivel');

formInfo.addEventListener('submit', e => {
  e.preventDefault();

  // leemos código + grupo pero no sobrescribimos datos.nivel
  const altura = document.getElementById('altura').value.trim();
  const botas  = document.getElementById('botas').value.trim();
  if (!altura || !botas) {
    _showToast('❌ Rellena esquí y botas.');
    return;
  }
  datos.alquiler = { altura, botas };

  // actualizamos resumen de nivel
  resumenNivel.textContent = datos.nivel.code;

  // ocultamos el form de info y mostramos el calendario
  // ahora, respetando cursoAnual
document.getElementById('form-info-adicional').style.display = 'none';
resumenDiv.style.display = 'block';

if (cursoAnual) {
  document.getElementById('form-calendario').style.display = 'none';
  document.getElementById('seccion-calendario').style.display = 'block';
  scrollSuave(document.getElementById('seccion-calendario'));
} else {
  document.getElementById('form-calendario').style.display = 'block';
  scrollSuave(document.getElementById('form-calendario'));
}


  _showToast(`Nivel ${datos.nivel.code} y alquiler guardados.`, 3000);
});


 // Ahora el listener de "Continuar" en el calendario:
  formCal.addEventListener('submit', e => {
  e.preventDefault();

  // 1) Leemos el ISO de Flatpickr y la hora elegida
  const isoStr = document.getElementById('diaClaseISO').value;
  const hora   = document.getElementById('horaClase').value;

  if (!isoStr) {
    alert('❌ Selecciona al menos una fecha.');
    return;
  }
  if (!hora) {
    alert('❌ Selecciona una hora de clase.');
    return;
  }

  // 2) Convertimos a array de fechas (1–3)
  const fechas = isoStr.split(',').map(s => s.trim());
  if (fechas.length > 4) {
    alert('❌ Solo puedes seleccionar hasta 4 fechas.');
    return;
  }

  // 3) Comprobamos que todas las fechas existan y extraemos sus niveles
  const niveles = fechas.map(dateISO => {
    const slot = dias_dispo.find(s => s.date === dateISO);
    if (!slot) {
      alert(`❌ Fecha ${dateISO} no encontrada en la configuración del curso.`);
      throw 'fecha no válida'; // para cortar ejecución
    }
    return slot.nivel;
  });

  // 4) Aseguramos que todos los niveles sean el mismo
  const únicos = Array.from(new Set(niveles));
  if (únicos.length > 1) {
    alert('❌ Todas las fechas deben pertenecer al mismo nivel.');
    return;
  }

  // 5) Todo OK: guardamos en datos.cal y mostramos el resumen
  datos.cal = { dias: fechas, hora };
const importe = calcularImportePorFechas(fechas.length);
datos.tipo.precio = importe;
pintarImporte(importe);

  // Pinta el resumen de forma bonita:
  document.getElementById('resumen-dia').textContent = fechas
    .map(d =>
      new Date(d).toLocaleDateString('es-ES', {
        weekday: 'short', day: 'numeric', month: 'short'
      })
    )
    .join(', ');
  document.getElementById('resumen-hora').textContent = hora;

  // Avanzamos al paso de pago
  formCal.parentNode.style.display = 'none';
  secCal.style.display             = 'block';
  pagoBox.style.display            = 'block';
  scrollSuave(secCal);
});
  // — Paso 6: Pago con Stripe —
  payBtn.addEventListener('click', async e => {
    e.preventDefault();
    // validación mínima
    if (datos.tipo.precio <= 0) {
      alert('❌ Importe inválido.');
      return;
    }
    payBtn.disabled    = true;
    payBtn.textContent = 'Procesando...';

    // construye payload
      // Paso 6: Pago con Stripe — dentro de `async e => { … }`
  const payload = {
    actividad_id:   parseInt(document.getElementById('actividad_id').value,10),

    // alumno y tutor igual que antes…
    nombre:         datos.alumno.nombre,
    apellidos:      datos.alumno.apellidos,
    dni:            datos.alumno.dni,
    fecha_nacimiento: datos.alumno.fecha_nacimiento,
    email:          datos.alumno.email,
    telefono:       datos.alumno.telefono,
    nombre_tutor:   datos.tutor.nombre_tutor || '',
    email_tutor:    datos.tutor.email_tutor  || '',
    telefono_tutor: datos.tutor.telefono_tutor || '',

    // **Éstos cambian**:
    tipo_actividad: 'clases',
hora_clase: datos.tipo.valor,          
    nivel:          datos.nivel.code,                      // “A. Debutante”
    nivel_grupo:    datos.nivel.grupo,                     // 1 o 2
    diaClase:       datos.cal.dias.join(','),              // ["YYYY-MM-DD",…] → "YYYY-MM-DD,…"
    hora_clase:     datos.cal.hora,                        // p.ej. "09:59"
 // 👇 Este es el total real de la actividad (1–4 días)
  importe_total_eur: Number(datos.tipo.precio.toFixed(2)),
    amount_eur:     precioStripe,
    altura: parseInt(document.getElementById('altura').value, 10),
    botas:  parseFloat(document.getElementById('botas').value),
    promo_code:     document.getElementById('promo_code').value.trim(),
  };


    try {
      const res  = await fetch(
        `${window.location.origin}/wp-content/plugins/montaventura/checkout.php`,
        {
          method: 'POST',
          headers: { 'Content-Type':'application/json' },
          body: JSON.stringify(payload)
        }
      );
      const data = await res.json();
      // si el servidor devuelve un 400 (o cualquier status ≠200), extraemos data.data
if (!res.ok) {
  // wp_send_json_error() devuelve { success: false, data: 'Tu mensaje' }
  const msg = data.data || data.error || 'Error al iniciar pago';
  throw new Error(msg);
}

// aquí res.ok===true y data tiene sessionId
await stripe.redirectToCheckout({ sessionId: data.id });
    } catch(err) {
      console.error(err);
      alert('❌ ' + err.message);
      payBtn.disabled    = false;
      payBtn.textContent = 'Reservar';
    }
  });

});
document.addEventListener('DOMContentLoaded', () => {
  // Descripciones por nivel
  const descripciones = {
    'A. Debutante': 'Nivel para quien nunca ha esquiado. Aprenderás a ponerte los esquís, deslizarte y frenar en cuña.',
    'B. Descenso en cuña': 'Ya sabes deslizarte; aquí practicas cuña profunda para controlar la velocidad.',
    'C. Giros en cuña': 'Aprenderás a dirigir tus giros manteniendo la cuña, para iniciar curvas en pendientes suaves.',
    'D. Viraje fundamental': 'Transición de cuña a viraje paralelo, manejo de bastones y coordinación de giros en pendientes medias.',
    'E. Paralelo': 'Dominas el viraje paralelo; perfeccionas carving y control en pendientes pronunciadas.'
  };

  const modal        = document.getElementById('nivel-info-modal');
  const titleEl      = document.getElementById('nivel-info-title');
  const descEl       = document.getElementById('nivel-info-desc');
  const closeBtn     = modal.querySelector('.modal-close');

  // Al pulsar cualquier botón ℹ️
  document.querySelectorAll('.nivel-info-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const nivel = btn.dataset.nivel;
      titleEl.textContent = nivel;
      descEl.textContent  = descripciones[nivel] || 'Descripción no disponible.';
      modal.style.display = 'flex';
    });
  });

  // Cerrar modal
  closeBtn.addEventListener('click', () => {
    modal.style.display = 'none';
  });
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
</body>
</html>