document.addEventListener('DOMContentLoaded', () => {
  // — Referencias a wrappers, formularios y secciones —
  //form script.js
  const formAlumno        = document.getElementById('form-completo');
  const seccionAlumno     = document.getElementById('seccion-inscrito');
  const wrapperTutor      = document.getElementById('form-tutor');
  const formTutor         = document.getElementById('form-tutor-form');
  const seccionTutor      = document.getElementById('seccion-tutor');
  const wrapperBono       = document.getElementById('form-tipo-bono');
  const formBono          = document.getElementById('form-tipo-bono-form');
  const seccionBono       = document.getElementById('seccion-tipo-bono');
  const wrapperNivel      = document.getElementById('form-info-adicional');
  const formNivel         = document.getElementById('form-info-adicional-form');
  const seccionNivel      = document.getElementById('seccion-informacion-resumen');
  const wrapperTecnicos   = document.getElementById('form-info-adicional');
  const formTecnicos      = document.getElementById('form-info-adicional-form');
  const seccionTecnicos   = document.getElementById('seccion-informacion-resumen');
  const wrapperCalendario = document.getElementById('form-calendario');
  const formCalendario    = document.getElementById('form-calendario-form');
  const seccionCalendario = document.getElementById('seccion-calendario');
  const wrapperFinal      = document.getElementById('form-inscripcion-final');
  const formFinal         = document.getElementById('form-inscripcion-final-form');
  const seccionFinal      = document.getElementById('seccion-tipo-inscripcion');
  const wrapperPago       = document.getElementById('form-pago');
  const payBtn            = document.getElementById('pay-button');
  const importeEl         = document.getElementById('importe-total');

  let datosAlumno     = {};
  let datosTutor      = {};
  let datosBono       = {};
  let datosNivel      = {};
  let datosTecnicos   = {};
  let datosCalendario = {};
  let importeTipo     = 0;

  function scrollSuave(el) {
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // — Paso 1: Alumno —
  formAlumno.addEventListener('submit', e => {
    e.preventDefault();
    datosAlumno = {
      nombre:           document.getElementById('nombre').value.trim(),
      apellidos:        document.getElementById('apellidos').value.trim(),
      dni:              document.getElementById('dni').value.trim(),
      fecha_nacimiento: document.getElementById('fecha_nacimiento').value,
      email:            document.getElementById('email').value.trim(),
      telefono:         document.getElementById('telefono').value.trim()
    };
    document.getElementById('nombreAlumno').textContent = datosAlumno.nombre;
    formAlumno.parentNode.style.display = 'none';
    seccionAlumno.style.display = 'block';

    // calcular edad
    const fn  = new Date(datosAlumno.fecha_nacimiento);
    const hoy = new Date();
    let edad  = hoy.getFullYear() - fn.getFullYear();
    if (hoy.getMonth() < fn.getMonth() ||
       (hoy.getMonth() === fn.getMonth() && hoy.getDate() < fn.getDate())) {
      edad--;
    }

    if (edad < 18) {
      wrapperTutor.style.display = 'block';
      scrollSuave(wrapperTutor);
    } else {
      wrapperTutor.querySelectorAll('input,select').forEach(i => i.disabled = true);
      wrapperTutor.style.display = 'none';
      wrapperBono.style.display  = 'block';
      scrollSuave(wrapperBono);
    }
  });

  // — Paso 2: Tutor (si aplica) —
  formTutor.addEventListener('submit', e => {
    e.preventDefault();
    datosTutor = {
      nombre_tutor:    document.getElementById('nombre_tutor').value.trim(),
      apellidos_tutor: document.getElementById('apellidos_tutor').value.trim(),
      relacion:        document.getElementById('relacion').value,
      documento_tutor: document.getElementById('documento_tutor').value.trim(),
      email_tutor:     document.getElementById('email_tutor').value.trim(),
      telefono_tutor:  document.getElementById('telefono_tutor').value.trim()
    };
    seccionTutor.style.display = 'block';
    wrapperTutor.style.display = 'none';
    wrapperBono.style.display  = 'block';
    scrollSuave(wrapperBono);
  });

  // — Paso 3: Tipo de bono —
  formBono.addEventListener('submit', e => {
    e.preventDefault();
    const sel = formBono.querySelector('input[name="tipo_bono"]:checked');
    if (!sel) { alert('❌ Selecciona un bono.'); return; }
    datosBono = { codigo: sel.value, nivel: sel.dataset.nivel };
    document.getElementById('resumen-bono').textContent = sel.value;
    seccionBono.style.display   = 'block';
    wrapperBono.style.display   = 'none';
    wrapperNivel.style.display  = 'block';
    scrollSuave(wrapperNivel);
  });

  // — Paso 4: Nivel y alquileres —
  formNivel.addEventListener('submit', e => {
    e.preventDefault();
    const sel = formNivel.querySelector('input[name="nivel"]:checked');
    if (!sel) {
      alert('❌ Selecciona un nivel de esquí.');
      return;
    }
    // extraer code|grupo
    const [code, grupo] = sel.value.split('|');
    datosNivel = { code, grupo: parseInt(grupo, 10) };

    // recoger alturas y botas
    datosTecnicos = {
      altura: document.getElementById('altura').value.trim(),
      botas:  document.getElementById('botas').value.trim()
    };
    document.getElementById('resumen-nivel').textContent  = code;
    document.getElementById('resumen-altura').textContent = datosTecnicos.altura;
    document.getElementById('resumen-pie').textContent    = datosTecnicos.botas;

    seccionNivel.style.display    = 'block';
    wrapperNivel.style.display    = 'none';
    wrapperTecnicos.style.display = 'none'; // si no usas paso extra
    wrapperCalendario.style.display = 'block';
    scrollSuave(wrapperCalendario);
  });
// — Paso 5: Calendario —  
formCalendario.addEventListener('submit', e => {
  e.preventDefault();

 const dia = document.getElementById('diaClaseISO').value;
  if (!dia) {
    alert('❌ Indica primero un día.');
    return;
  }

  // Llamamos al AJAX para traer sólo las horas permitidas
  const data = new FormData();
  data.append('action', 'mtv_get_slots');
  data.append('actividad_id', actividad_id);       // recoge tu ID
  data.append('nivel_grupo', datosNivel.grupo);     // grupo 1 o 2
  data.append('dia', dia);

  fetch(ajaxurl, { method:'POST', body:data })
    .then(r=>r.json())
    .then(json => {
      if (!json.success) throw new Error('Error al cargar horarios');
      const slots = json.data; // array de { hora, plazas }
      const sel = document.getElementById('horaClase');
      sel.innerHTML = '<option value="">— Selecciona —</option>';
      slots.forEach(s => {
        const opt = document.createElement('option');
        opt.value = `${s.hora}|${s.plazas}`;
        opt.textContent = `${s.hora} — ${s.plazas} plazas`;
        sel.append(opt);
      });
      // ahora sí, mostramos el formulario de horas
      document.getElementById('form-calendario').style.display = 'block';
    })
    .catch(err => {
      console.error(err);
      alert('❌ No se pudieron cargar los horarios. Inténtalo más tarde.');
    });
});


  // — Paso 6: Tipo de inscripción —
  formFinal.addEventListener('submit', e => {
    e.preventDefault();
    const sel = formFinal.querySelector('input[name="tipo"]:checked');
    if (!sel) { alert('❌ Elige tipo de inscripción.'); return; }
    importeTipo = parseFloat(sel.dataset.precio) || 0;
    importeEl.textContent = importeTipo.toFixed(2).replace('.',',') + ' €';
    seccionFinal.style.display = 'block';
    wrapperFinal.style.display = 'none';
    wrapperPago.style.display  = 'block';
    scrollSuave(wrapperPago);
  });
// — Paso 7: Pago con Stripe —
payBtn.addEventListener('click', async e => {
  e.preventDefault();

  // validación previa (tipo seleccionado, etc.)
  const sel = formFinal.querySelector('input[name="tipo"]:checked');
  if (!sel) {
    alert('❌ Elige tipo de inscripción.');
    return;
  }

  // Construcción del payload
  const payload = {
    actividad_id:     parseInt(document.getElementById('actividad_id').value,10),
    nombre:           datosAlumno.nombre,
    apellidos:        datosAlumno.apellidos,
    dni:              datosAlumno.dni,
    fecha_nacimiento: datosAlumno.fecha_nacimiento,
    email:            datosAlumno.email,
    telefono:         datosAlumno.telefono,
    ...datosTutor,
    tipo_actividad:   sel.value,
    nivel:            `${datosNivel.code}|${datosNivel.grupo}`,
    altura:           datosTecnicos.altura,
    botas:            datosTecnicos.botas,
    diaClase:         datosCalendario.dia,
    hora_clase:       datosCalendario.raw.split('|')[0],
    amount_eur:       importeTipo,
    promo_code:       document.getElementById('promo_code').value.trim()
  };

  payBtn.disabled    = true;
  payBtn.textContent = 'Procesando…';

  try {
    const res  = await fetch(
      `${window.location.origin}/wp-content/plugins/montaventura/checkout.php`, {
        method: 'POST',
        headers: { 'Content-Type':'application/json' },
        body: JSON.stringify(payload)
      }
    );
    const data = await res.json();

    if (!res.ok) {
      // Detectamos la validación de niveles cruzados desde el back
      if (res.status === 400 && data.error && /nivel/i.test(data.error)) {
        throw new Error(
          'Solo puedes reservar clases colectivas con gente de tu mismo nivel. ' +
          'Si quieres cambiar de nivel, pulsa “Modificar nivel”.'
        );
      }
      throw new Error(data.error||'Error al iniciar pago');
    }

    await stripe.redirectToCheckout({ sessionId: data.id });
  } catch(err) {
    console.error(err);
    alert('❌ ' + err.message);
    payBtn.disabled    = false;
    payBtn.textContent = 'Reservar';
  }
});


  // Datepicker mínimo + bloqueo Lunes/Martes
  const dia = document.getElementById('diaClase');
  if (dia) {
    const m = new Date();
    m.setDate(m.getDate() + 1);
    dia.min = m.toISOString().split('T')[0];
    dia.addEventListener('change', function() {
      const dow = new Date(this.value).getDay();
      if (dow === 1 || dow === 2) {
        alert('Lunes y martes no disponibles.');
        this.value = '';
      }
    });
    
  }
  
})
/**
 * Reconstruye el <select id="horaClase"> filtrando solo los slots
 * cuyo slot.grupo coincida con datos.nivel.grupo
 */
function rebuildHoraOptions() {
  const select = document.getElementById('horaClase');
  select.innerHTML = '<option value="">— Selecciona —</option>';
  clases_raw.forEach(slot => {
    if (slot.grupo === datos.nivel.grupo && slot.hora) {
      const opt = document.createElement('option');
      opt.value = slot.hora;
      opt.textContent = slot.hora;
      select.appendChild(opt);
    }
  });
  // Deshabilitamos hasta que el usuario elija una hora
  select.disabled = true;
  select.addEventListener('change', () => {
    select.disabled = ! select.value;
  });
}

// Dentro de tu handler de formNivel.submit(), justo tras calcular datos.nivel:
formNivel.addEventListener('submit', e => {
  e.preventDefault();
  // … tu lógica existente para datos.nivel …
  
  // Ahora reconstruimos el select de horas:
  rebuildHoraOptions();

  // Mostrar el paso 5:
  formCal.parentNode.style.display = 'block';
  scrollSuave(formCal.parentNode);
});





// Scroll suave compartido
function scrollSuave(el) {
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ======== FUNCIONES “Volver” PARA CADA PASO (SIN TUTOR) ========

// Paso 1: volver al formulario del alumno
function volverAlFormulario() {
  document.getElementById('form-inscripcion').style.display           = 'block';
  document.getElementById('seccion-inscrito').style.display           = 'none';
  document.getElementById('seccion-inscripcion-final').style.display  = 'none';
  document.getElementById('seccion-tipo-inscripcion').style.display   = 'none';
  document.getElementById('form-info-adicional').style.display        = 'none';
  document.getElementById('seccion-informacion-resumen').style.display= 'none';
  document.getElementById('form-calendario').style.display            = 'none';
  document.getElementById('seccion-calendario').style.display         = 'none';
  document.getElementById('form-pago').style.display                  = 'none';

  scrollSuave(document.getElementById('form-inscripcion'));
}

// Paso 3: volver al formulario de tipo de inscripción
function volverAFormularioTipo() {
  document.getElementById('form-inscripcion').style.display           = 'none';
  document.getElementById('seccion-inscrito').style.display           = 'block';
  document.getElementById('seccion-inscripcion-final').style.display  = 'block';
  document.getElementById('seccion-tipo-inscripcion').style.display   = 'none';
  document.getElementById('form-info-adicional').style.display        = 'none';
  document.getElementById('seccion-informacion-resumen').style.display= 'none';
  document.getElementById('form-calendario').style.display            = 'none';
  document.getElementById('seccion-calendario').style.display         = 'none';
  document.getElementById('form-pago').style.display                  = 'none';

  scrollSuave(document.getElementById('seccion-inscripcion-final'));
}

// Paso 4: volver al formulario de nivel de esquí
function volverAlFormularioInfo() {
  document.getElementById('form-inscripcion').style.display           = 'none';
  document.getElementById('seccion-inscrito').style.display           = 'block';
  document.getElementById('seccion-inscripcion-final').style.display  = 'none';
  document.getElementById('seccion-tipo-inscripcion').style.display   = 'block';
  document.getElementById('form-info-adicional').style.display        = 'block';
  document.getElementById('seccion-informacion-resumen').style.display= 'none';
  document.getElementById('form-calendario').style.display            = 'none';
  document.getElementById('seccion-calendario').style.display         = 'none';
  document.getElementById('form-pago').style.display                  = 'none';

  // Forzar reaplicación de :checked para mantener el bocadillo
  document
    .querySelectorAll('#form-info-adicional input[name="nivel"]')
    .forEach(radio => {
      if (radio.checked) {
        radio.checked = false;
        radio.checked = true;
      }
    });

  scrollSuave(document.getElementById('form-info-adicional'));
}

// Paso 5: volver al formulario de calendario
function volverAlCalendario() {
  document.getElementById('form-inscripcion').style.display           = 'none';
  document.getElementById('seccion-inscrito').style.display           = 'block';
  document.getElementById('seccion-inscripcion-final').style.display  = 'none';
  document.getElementById('seccion-tipo-inscripcion').style.display   = 'block';
  document.getElementById('form-info-adicional').style.display        = 'none';
  document.getElementById('seccion-informacion-resumen').style.display= 'block';
  document.getElementById('form-calendario').style.display            = 'block';
  document.getElementById('seccion-calendario').style.display         = 'none';
  document.getElementById('form-pago').style.display                  = 'none';

  scrollSuave(document.getElementById('form-calendario'));
}

// Paso 6: volver al formulario de pago
function volverAPago() {
  document.getElementById('form-inscripcion').style.display           = 'none';
  document.getElementById('seccion-inscrito').style.display           = 'block';
  document.getElementById('seccion-inscripcion-final').style.display  = 'none';
  document.getElementById('seccion-tipo-inscripcion').style.display   = 'block';
  document.getElementById('form-info-adicional').style.display        = 'none';
  document.getElementById('seccion-informacion-resumen').style.display= 'block';
  document.getElementById('form-calendario').style.display            = 'none';
  document.getElementById('seccion-calendario').style.display         = 'block';
  document.getElementById('form-pago').style.display                  = 'block';

  scrollSuave(document.getElementById('form-pago'));
}
