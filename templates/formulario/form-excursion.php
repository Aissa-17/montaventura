<?php
// form-excursion.php
defined('ABSPATH') || exit;

if (!isset($titulo)) {
    wp_die('La actividad no ha sido seleccionada correctamente.');
}

// Número de días del campamento (fijo en 5)
// Si en el futuro cambiase, bastaría con actualizar esta constante.
const NUM_DIAS = 5;

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inscripción Montaventura</title>
  <link rel="stylesheet" href="<?php echo plugins_url('../../assets/css/form-style.css', __FILE__); ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo plugins_url('../../img/Icono-Montaventura.png', __FILE__); ?>">
  <script src="https://js.stripe.com/v3/"></script>
  <style>
    /* Pequeños ajustes para mostrar el desglose de precios */
    .importe-detalle {
      margin-top: 0.5em;
      font-size: 0.9rem;
      color: #333;
    }
    .importe-detalle span {
      display: inline-block;
      min-width: 120px;
    }
  </style>
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
        <?php if ( strtolower($a->tipo_actividad) === 'excursión' ): ?>
          <li>👥 Sólo quedan <strong><?php echo esc_html($a->plazas); ?></strong> plazas libres</li>
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
                <a href="https://www.montaventura.com/wp-content/uploads/2025/08/Terminos-y-Condiciones-Generales-de-Contratacion-–-Viaje.pdf" target="_blank">
                  Esquí Indoor: términos y condiciones
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

    <div id="form-inscripcion" class="formulario-box">
      <h3>1. Datos del alumno</h3>
      <form id="form-completo" action="" method="post" onsubmit="return validarFormulario()">
        <input type="hidden" id="actividad_id" value="<?php echo esc_attr( $a->id ); ?>">
        <label for="nombre">Nombre: *</label>
        <input type="text" id="nombre" name="nombre" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+" title="Solo letras y espacios permitidos"  required>
        <label for="apellidos">Apellidos: *</label>
        <input type="text" id="apellidos" name="apellidos" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+" title="Solo letras y espacios permitidos"  required>
        <label for="dni">Documento:</label>
        <input type="text" id="dni" name="dni" pattern="[XYZxyz]?[0-9]{7,8}[A-Za-z]" title="Debe ser un DNI/NIE válido" required>
        <label for="fecha_nacimiento">Fecha de nacimiento:</label>
        <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" max="<?php echo date('Y-m-d'); ?>"  required>
        <label for="email">Email: *</label>
        <input type="email" id="email" name="email" pattern="^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$" required>
        <label for="telefono">Teléfono: *</label>
        <input type="tel" id="telefono" name="telefono" pattern="^[0-9]{9}$" title="Debe ser un número de 9 dígitos" required>
        <button type="submit" class="boton-inscripcion">Continuar</button>
      </form>
    </div>

    <div class="seccion-inscrito" style="display:none;" id="seccion-inscrito">
      <a href="#" class="volver" onclick="volverAlFormulario()">← Cambiar persona</a>
      <h2>1. Persona Inscrita: <strong id="nombreAlumno">usuario_nombre</strong>
        <a href="#" class="modificar" onclick="volverAlFormulario()">✎ Modificar</a>
      </h2>
      <p>Gracias por llegar hasta aquí. Necesitamos el DNI de la persona que hace la actividad. Si no tiene DNI, el número de la Seguridad Social.</p>
    </div>

    <div id="form-tutor" class="formulario-box" style="display: none;">
      <h2>2. Datos del Tutor/a</h2>
      <form onsubmit="return validarFormularioTutor()">
        <label for="nombre_tutor">Nombre del tutor: *</label>
        <input type="text" id="nombre_tutor" name="nombre_tutor" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+" title="Solo letras y espacios permitidos">
        <label for="apellidos_tutor">Apellidos: *</label>
        <input type="text" id="apellidos_tutor" name="apellidos_tutor" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+" title="Solo letras y espacios permitidos">
        <label for="relacion">Relación: *</label>
        <select id="relacion" name="relacion">
          <option value="">Seleccione una opción</option>
          <option>Padre</option>
          <option>Madre</option>
          <option>Otro</option>
        </select>
        <label for="documento_tutor">Documento (DNI/NIE): *</label>
        <input type="text" id="documento_tutor" name="documento_tutor" pattern="[XYZxyz]?[0-9]{7,8}[A-Za-z]" title="Debe ser un DNI/NIE válido">
        <label for="email_tutor">Email: *</label>
        <input type="email" id="email_tutor" name="email_tutor">
        <label for="telefono_tutor">Teléfono principal: *</label>
        <input type="tel" id="telefono_tutor" name="telefono_tutor" pattern="^[0-9]{9}$" title="Debe ser un número de 9 dígitos">
        <button type="submit" class="boton-inscripcion">Continuar</button>
      </form>
    </div>

    <div class="seccion-inscrito" style="display:none;" id="seccion-tutor">
      <a href="#" class="volver" onclick="volverAlFormulario()">← Volver</a>
      <h2>2. Tutor/a
        <a href="#" class="modificar" onclick="volverAlFormulario()">✎ Modificar</a>
      </h2>
      <p>Gracias por completar los datos del tutor/a. Puedes volver atrás si necesitas cambiar la información.</p>
    </div>

    <div id="form-info-adicional" class="formulario-box" style="display: none;">
      <h2>3. Información adicional</h2>
      <form id="formulario-info-adicional">
        <fieldset required>
          <legend>NIVEL ESQUÍ *</legend>
          <div class="radio-group-horizontal">
            <div class="radio-group-horizontal">
  <label>
    <input type="radio" name="nivel" value="A. Debutante" required>
    A. Debutante
    <button type="button" class="nivel-info-btn" data-nivel="A. Debutante">ℹ️</button>
  </label>
  <label>
    <input type="radio" name="nivel" value="B. Descenso en cuña">
    B. Descenso en cuña
    <button type="button" class="nivel-info-btn" data-nivel="B. Descenso en cuña">ℹ️</button>
  </label>
  <label>
    <input type="radio" name="nivel" value="C. Giros en cuña">
    C. Giros en cuña
    <button type="button" class="nivel-info-btn" data-nivel="C. Giros en cuña">ℹ️</button>
  </label>
  <label>
    <input type="radio" name="nivel" value="D. Viraje fundamental">
    D. Viraje fundamental
    <button type="button" class="nivel-info-btn" data-nivel="D. Viraje fundamental">ℹ️</button>
  </label>
  <label>
    <input type="radio" name="nivel" value="E. Paralelo">
    E. Paralelo
    <button type="button" class="nivel-info-btn" data-nivel="E. Paralelo">ℹ️</button>
  </label>
</div>
          </div>
        </fieldset>

        <label>ESQUÍ ALQUILER (incluido) *</label>
        <input type="number" name="altura" min="50" max="250" placeholder="Altura en cm" required>

        <label>BOTAS ALQUILER (incluidas) *</label>
        <input type="number" name="botas" min="0" max="50" placeholder="Medida del pie en cm (0 si llevas tus botas)" required>
        <button type="submit" class="boton-inscripcion">Continuar</button>
      </form>
    </div>

    <div class="seccion-inscrito" style="display:none;" id="seccion-informacion-resumen">
      <a href="#" class="volver" onclick="volverAlTutor()">← Cambiar información adicional</a>
      <h2>3. Información adicional
        <a href="#" class="modificar" onclick="volverAlFormularioInfo()">✎ Modificar</a>
      </h2>
      <p>Información adicional completada. Puedes volver atrás si necesitas cambiar la información.</p>
    </div>
<?php
  // 1) Usamos el WP post ID que nos viene en el objeto $a (del template padre)
  if ( isset($a->post_id) && $a->post_id > 0 ) {
     $post_id = intval( $a->post_id );
  } else {
      wp_die( 'ID de actividad no válido.' );
  }

  // 2) Lee el meta
// Precios y extras estructurados
$post_id  = isset($a->post_id) ? intval($a->post_id) : 0;
if (!$post_id) wp_die('ID de actividad no válido.');

$precio_base       = floatval( get_post_meta($post_id, '_mtv_precio_base', true) ); // <- NUEVO
$precio_reserva    = floatval( get_post_meta($post_id, '_mtv_precio', true) );      // reserva/anticipo
$extras_struct     = get_post_meta( $post_id, '_mtv_excursion_extras_struct', true );
if ( !is_array($extras_struct) ) $extras_struct = [];

?>
<form id="form-inscripcion-completa" style="display:none;">
  <section class="formulario-box" id="extras-box">
  <h3>4. Opciones de inscripción y extras</h3>

  <?php if ($extras_struct): ?>
    <ul style="list-style:none;padding-left:0;margin:0;">
      <?php foreach ($extras_struct as $i => $ex):
        $label = esc_html($ex['label'] ?? '');
        $price = floatval($ex['price'] ?? 0);
        if ($label==='') continue;
      ?>
        <li style="margin:.25rem 0;">
          <label>
            <input type="checkbox"
                   class="extra-check"
                   data-label="<?php echo esc_attr($label); ?>"
                   data-price="<?php echo esc_attr($price); ?>">
            <?php echo $label; ?> — <?php echo number_format_i18n($price, 2); ?> €
          </label>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p><em>No hay opciones adicionales para esta excursión.</em></p>
  <?php endif; ?>

 <div class="importe-total-box" style="margin-top:1rem;">
  <strong>Importe total:</strong>
  <span id="importe-total" class="importe-destacado">
    <?php echo number_format_i18n($precio_base,2); ?> €
  </span>
  <div class="importe-detalle">
    <span>(Reserva hoy: <?php echo number_format_i18n($precio_reserva,2); ?> €)</span>
  </div>
</div>
</section>
<section class="formulario-box">
  <fieldset>
    <label class="checkbox-full">
      <input type="checkbox" id="acepto_legal" required>
      * He leído y acepto las condiciones legales.
       <span class="ver-mas" onclick="window.open('https://www.montaventura.com/wp-content/uploads/2025/08/Terminos-y-Condiciones-Generales-de-Contratacion-–-Viaje.pdf', '_blank')">Ver más</span>
    </label>
    <label class="checkbox-full">
            <input type="checkbox"> Derechos de Imagen
            <span class="ver-mas" onclick="window.open('https://www.montaventura.com/wp-content/uploads/2025/08/Autorizacion-para-el-Uso-de-Imagen.pdf', '_blank')">Ver más</span>
          </label>
  </fieldset>
  <textarea rows="4" placeholder="Observaciones o comentarios" id="observaciones"></textarea>

  <button type="submit" class="boton-inscripcion" id="pay-button">Reservar</button>
</section>
</form>
<div id="formulario-pago" style="display:none;"></div>
  </main>
</div>

<div id="nivel-info-modal">
  <div class="modal-content">
    <button class="modal-close" aria-label="Cerrar">×</button>
    <h3 id="nivel-info-title"></h3>
    <p id="nivel-info-desc"></p>
  </div>
</div>

<script src="<?php echo plugins_url('../../assets/js/form-script2.js', __FILE__); ?>"></script>
<script>
// — Montaventura Reserva Adaptado —
// Asume que ya has completado los pasos 1–3 y que:
// • El formulario final (#form-inscripcion-completa) ya está visible
// • Los campos de paso 1 (#nombre, #apellidos, #dni, etc.)
//   y paso 2 (#nombre_tutor, …) y paso 3 (input[name="nivel"], #altura, #botas)
//   ya contienen los valores finales.
const PRECIO_BASE_EXC = <?php echo json_encode($precio_base ?: 0); ?>;
const DEP_RESERVA     = <?php echo json_encode($precio_reserva ?: 0); ?>;
document.addEventListener('DOMContentLoaded', function(){
  // 1) Token sesión y Stripe
  const sessionToken = Date.now().toString(36) + Math.random().toString(36).substr(2);
  const stripe       = Stripe('<?php echo esc_js(STRIPE_PUBLISHABLE_KEY); ?>');

  // 2) Elementos clave
  const payBtn = document.getElementById('pay-button');
  const form  = document.getElementById('form-inscripcion-completa');
// [NUEVO] — cálculo del total con extras (admite negativos)
// Total mostrado al usuario (no lo que cobra Stripe ahora)
let totalCalculado = Number(PRECIO_BASE_EXC) || 0;

const totalEl      = document.getElementById('importe-total');
const extrasChecks = document.querySelectorAll('.extra-check');

// helpers
const toNumber  = v => {
  const n = parseFloat(String(v).replace(',', '.'));
  return isNaN(n) ? 0 : n;
};
const formatEUR = v => v.toFixed(2).replace('.', ',') + ' €';

function recalcularTotal() {
  let extras = 0;
  extrasChecks.forEach(ch => {
    if (ch.checked) extras += toNumber(ch.dataset.price || '0');
  });
  totalCalculado = toNumber(PRECIO_BASE_EXC) + extras;
  totalEl.textContent = formatEUR(totalCalculado);
}

// inicial y al cambiar checks
extrasChecks.forEach(ch => ch.addEventListener('change', recalcularTotal));
recalcularTotal();


  // 3) Validación de “legales” y recolección de datos al pulsar Reservar
  payBtn.addEventListener('click', async function(e){
    e.preventDefault();

    // 3.1 Aceptar condiciones
    const legalChecked = form.querySelector('input[type=checkbox][required]').checked;
    if (!legalChecked) {
      return alert('Debes aceptar las condiciones legales.');
    }

    // 3.2 Recolectar datos de los pasos anteriores
    const actividadId = document.getElementById('actividad_id').value;
    const nombre      = document.getElementById('nombre').value.trim();
    const apellidos   = document.getElementById('apellidos').value.trim();
    const dni         = document.getElementById('dni').value.trim();
    const fechaNac    = document.getElementById('fecha_nacimiento').value;
    const email       = document.getElementById('email').value.trim();
    const telefono    = document.getElementById('telefono').value.trim();

    // Tutor (puede venir vacío si mayor de edad)
    const nombreTutor    = document.getElementById('nombre_tutor')?.value.trim()    || '';
    const apellidosTutor = document.getElementById('apellidos_tutor')?.value.trim() || '';
    const relacion       = document.getElementById('relacion')?.value              || '';
    const docTutor       = document.getElementById('documento_tutor')?.value.trim()|| '';
    const emailTutor     = document.getElementById('email_tutor')?.value.trim()    || '';
    const telTutor       = document.getElementById('telefono_tutor')?.value.trim() || '';

    // Información adicional (paso 3)
    const nivel   = document.querySelector('input[name="nivel"]:checked')?.value || '';
    const altura  = document.querySelector('input[name="altura"]').value.trim();
    const botas   = document.querySelector('input[name="botas"]').value.trim();

    // Observaciones
    const observaciones = document.getElementById('observaciones').value.trim();

    // 3.3 Validaciones finales básicas
    if (!nombre || !apellidos || !dni || !fechaNac || !email || !telefono) {
      return alert('Completa todos los campos obligatorios del alumno.');
    }
    // Si es menor, chequeamos tutor
    const nacimientoTs = Date.parse(fechaNac);
    const edad = isNaN(nacimientoTs)
      ? 0
      : Math.floor((Date.now() - nacimientoTs) / (365.25*24*3600*1000));
    if (edad < 18 && (!nombreTutor || !apellidosTutor || !relacion || !docTutor || !emailTutor || !telTutor)) {
      return alert('Debes rellenar los datos del tutor para menores de edad.');
    }
 // Extras elegidos para guardar en la reserva
    const extrasSeleccionados = Array.from(document.querySelectorAll('.extra-check:checked'))
      .map(ch => `${ch.dataset.label} (+${parseFloat(ch.dataset.price||'0').toFixed(2)}€)`);
    const importeMostrado = document.getElementById('importe-total').textContent;
    // 4) Desactivar botón
    payBtn.disabled    = true;
    payBtn.textContent = 'Procesando...';

    // 5) Construir payload
const payload = {
  session_token: sessionToken,
  actividad_id: Number(actividadId),
  nombre, apellidos, dni,
  fecha_nacimiento: fechaNac,
  email, telefono,
  ...(edad < 18 ? {
    nombre_tutor: nombreTutor,
    apellidos_tutor: apellidosTutor,
    relacion,
    documento_tutor: docTutor,
    email_tutor: emailTutor,
    telefono_tutor: telTutor,
  } : {}),
  nivel, altura, botas,
  observaciones,
  opciones_adicionales: extrasSeleccionados.join('\n'),
  importe_mostrado: totalEl.textContent,
  importe_total_eur: Number(totalCalculado.toFixed(2)),

  // Cobro ahora (variable)
  amount_eur:   Number(DEP_RESERVA),
amount_cents: Math.round(Number(DEP_RESERVA) * 100)
};




    // 6) Enviar a checkout.php y redirigir a Stripe
    try {
      const res = await fetch(
        `${window.location.origin}/wp-content/plugins/montaventura/checkout.php`,
        { method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify(payload)
        }
      );
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      if (data.error) throw new Error(data.error);
      await stripe.redirectToCheckout({ sessionId: data.id });
    } catch(err) {
      console.error('Error pago:', err);
      alert(`Error al iniciar el pago: ${err.message}`);
      payBtn.disabled    = false;
      payBtn.textContent = 'Reservar';
    }
  });

  // 7) Para que no haga submit normal
  form.addEventListener('submit', e => e.preventDefault());
});
</script>
<div id="toast"></div>
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