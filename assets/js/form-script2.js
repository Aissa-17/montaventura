// assets/js/form-script2.js
document.addEventListener('DOMContentLoaded', function () {
  let datosAlumno = {};
  let datosTutor = {};
  let datosInfoAdicional = {};

  const formInicial = document.querySelector('#form-inscripcion form');
  const contenedorInicial = document.getElementById('form-inscripcion');
  const seccionInscrito = document.getElementById('seccion-inscrito');
  const formTutor = document.getElementById('form-tutor');
  const formTutorForm = formTutor.querySelector('form');
  const seccionTutor = document.getElementById('seccion-tutor');
  const nombreAlumno = document.getElementById('nombreAlumno');
  const formInfoAdicional = document.querySelector('#form-info-adicional form');
  const seccionInfoResumen = document.getElementById('seccion-informacion-resumen');
  const formInscripcionCompleta = document.getElementById('form-inscripcion-completa');
  const formularioPago = document.getElementById('formulario-pago');
  const formPago = document.getElementById('form-pago');
  const detallePago = document.getElementById('detalle-pago');
  const totalSpan = document.getElementById('importe-total');
  const checkboxesTipo = document.querySelectorAll('input[name="tipo_inscripcion"]');
  const checkboxesExtra = document.querySelectorAll('input[name="opciones_extra"]');
  const radiosDescuento = document.querySelectorAll('input[name="descuento"]');

  document.addEventListener("DOMContentLoaded", function () {
    const form = document.querySelector("form");
    form.addEventListener("submit", function (event) {
      const altura = document.querySelector('input[name="altura"]')?.value || '';
      const botas = document.querySelector('input[name="botas"]')?.value || '';
      const diaClase = document.querySelector('input[name="diaClase"]')?.value || '';
      const observaciones = document.querySelector('textarea[name="observaciones"]')?.value.trim() || '';
      const tipoSeleccionado = document.querySelector('input[name="tipo"]:checked')?.value || '';

      const infoAdicional = {
        altura: altura,
        botas: botas,
        diaClase: diaClase,
        observaciones: observaciones,
        tipo: tipoSeleccionado
      };

      document.getElementById("informacion_adicional").value = JSON.stringify(infoAdicional);
    });
  });

  // Paso 1 - Datos del Alumno
  formInicial.addEventListener('submit', function (e) {
    e.preventDefault();
    datosAlumno = {
      nombre: document.getElementById('nombre').value.trim(),
      apellidos: document.getElementById('apellidos').value.trim(),
      dni: document.getElementById('dni').value.trim(),
      fechaNacimiento: document.getElementById('fecha_nacimiento').value.trim(),
      tarjetaSanitaria: document.getElementById('tarjeta_sanitaria')?.value.trim() || '',
      pasaporte: document.getElementById('pasaporte')?.value.trim() || '',
      email: document.getElementById('email').value.trim(),
      emailSecundario: document.getElementById('email_secundario')?.value.trim() || '',
      telefono: document.getElementById('telefono').value.trim(),
    };

    nombreAlumno.textContent = datosAlumno.nombre + ' ' + datosAlumno.apellidos;
    contenedorInicial.style.display = 'none';
    seccionInscrito.style.display = 'block';

    const fechaNacimiento = new Date(datosAlumno.fechaNacimiento);
    const hoy = new Date();
    let edad = hoy.getFullYear() - fechaNacimiento.getFullYear();
    const mes = hoy.getMonth() - fechaNacimiento.getMonth();
    if (mes < 0 || (mes === 0 && hoy.getDate() < fechaNacimiento.getDate())) {
      edad--;
    }

    if (edad < 18) {
      formTutor.style.display = 'block';
      formTutor.querySelectorAll('input, select').forEach(el => el.disabled = false);
      scrollSuave(formTutor);
    } else {
      formTutor.style.display = 'none';
      seccionTutor.style.display = 'none';
      formTutor.querySelectorAll('input, select').forEach(el => {
        el.value = '';
        el.disabled = true;
      });
      document.getElementById('form-info-adicional').style.display = 'block';
      scrollSuave(document.getElementById('form-info-adicional'));
    }
  });

  // Paso 2 - Datos del Tutor
  formTutorForm.addEventListener('submit', function (e) {
    e.preventDefault();
    datosTutor = {
      nombre: formTutorForm[0].value.trim(),
      apellidos: formTutorForm[1].value.trim(),
      relacion: formTutorForm[2].value,
      documento: formTutorForm[3].value.trim(),
      email: formTutorForm[4].value.trim(),
      telefono1: formTutorForm[5].value.trim(),
      telefono2: formTutorForm[6]?.value.trim() || '',
    };
    formTutor.style.display = 'none';
    seccionTutor.style.display = 'block';
    document.getElementById('form-info-adicional').style.display = 'block';
    scrollSuave(document.getElementById('form-info-adicional'));
  });

  // Paso 3 - Información adicional
  formInfoAdicional.addEventListener('submit', function (e) {
    e.preventDefault();
    datosInfoAdicional = {
      nivel: document.querySelector('input[name="nivel"]:checked')?.value || "",
      altura: formInfoAdicional.querySelector('input[name="altura"]').value.trim(),
      botas: formInfoAdicional.querySelector('input[name="botas"]').value.trim(),
    };
    document.getElementById('form-info-adicional').style.display = 'none';
    document.getElementById('seccion-informacion-resumen').style.display = 'block';
    formInscripcionCompleta.style.display = 'block';
    scrollSuave(formInscripcionCompleta);
  });

  // Paso 4 - Calculador de importe total
  function calcularImporteTotal() {
    let total = 0;

    checkboxesTipo.forEach(cb => {
      if (cb.checked) {
        total += parseFloat(cb.value);
      }
    });

    checkboxesExtra.forEach(cb => {
      if (cb.checked) {
        total += parseFloat(cb.value);
      }
    });

    radiosDescuento.forEach(rb => {
      if (rb.checked) {
        total -= parseFloat(rb.value);
      }
    });

    total = Math.max(total, 0);
    totalSpan.textContent = total.toFixed(2).replace('.', ',') + ' €';
  }

  checkboxesTipo.forEach(cb => cb.addEventListener('change', calcularImporteTotal));
  checkboxesExtra.forEach(cb => cb.addEventListener('change', calcularImporteTotal));
  radiosDescuento.forEach(rb => rb.addEventListener('change', calcularImporteTotal));

  formInscripcionCompleta.addEventListener('submit', function (e) {
    e.preventDefault();
    formularioPago.style.display = 'block';
    scrollSuave(formularioPago);
  });

  formPago.addEventListener('change', function () {
    const metodo = formPago.querySelector('input[name="metodo_pago"]:checked')?.value;
    const importe = parseFloat(totalSpan.textContent.replace(',', '.'));

    if (metodo === 'fraccionado') {
      const pagarAhora = 40.00;
      const resto = importe - pagarAhora;
      detallePago.innerHTML = `
        <p><strong>Pago ahora:</strong> ${pagarAhora.toFixed(2).replace('.', ',')} €</p>
        <p><strong>Resto:</strong> ${resto.toFixed(2).replace('.', ',')} € (pago pendiente)</p>
      `;
    } else if (metodo === 'completo') {
      detallePago.innerHTML = `
        <p><strong>Pago completo ahora:</strong> ${importe.toFixed(2).replace('.', ',')} €</p>
      `;
    }
  });

  formPago.addEventListener('submit', function (e) {
    e.preventDefault();
    alert('Formulario completado, ¡listo para enviar a Stripe o la plataforma de pago! ✨');
  });
});

function scrollSuave(elemento) {
  elemento.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function toggleLegal() {
  const info = document.getElementById('legal-info');
  if (info) info.style.display = (info.style.display === 'block') ? 'none' : 'block';
}

function toggleImagen() {
  const info = document.getElementById('imagen-info');
  if (info) info.style.display = (info.style.display === 'block') ? 'none' : 'block';
}

function volverAlFormulario() {
  document.getElementById('form-inscripcion').style.display = 'block';
  document.getElementById('seccion-inscrito').style.display = 'none';
  document.getElementById('form-tutor').style.display = 'none';
  document.getElementById('seccion-tutor').style.display = 'none';
  document.getElementById('form-info-adicional').style.display = 'none';
  document.getElementById('seccion-informacion-resumen').style.display = 'none';
  document.getElementById('form-inscripcion-completa').style.display = 'none';
  document.getElementById('formulario-pago').style.display = 'none';
}

function volverAlTutor() {
  document.getElementById('form-tutor').style.display = 'block';
  document.getElementById('seccion-tutor').style.display = 'none';
  document.getElementById('form-info-adicional').style.display = 'none';
  document.getElementById('seccion-informacion-resumen').style.display = 'none';
  document.getElementById('form-inscripcion-completa').style.display = 'none';
  document.getElementById('formulario-pago').style.display = 'none';
}

function volverAlFormularioInfo() {
  document.getElementById('form-info-adicional').style.display = 'block';
  document.getElementById('seccion-informacion-resumen').style.display = 'none';
  document.getElementById('form-inscripcion-completa').style.display = 'none';
  document.getElementById('formulario-pago').style.display = 'none';
}

function validarFormulario() {
  const fechaNacimiento = document.getElementById("fecha_nacimiento").value;
  const fechaActual = new Date().toISOString().split("T")[0];
  if (new Date(fechaNacimiento) > new Date(fechaActual)) {
    alert("La fecha de nacimiento no puede ser en el futuro.");
    return false;
  }
  return true;
}

document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.querySelector('.barra-lateral');
  if (!sidebar) return;
  let toggle = sidebar.querySelector('.sidebar-toggle');
  if (!toggle) {
    toggle = document.createElement('button');
    toggle.className = 'sidebar-toggle';
    toggle.setAttribute('aria-label', 'Mostrar menú');
    toggle.textContent = '☰';
    sidebar.appendChild(toggle);
  }
  toggle.addEventListener('click', () => {
    sidebar.classList.toggle('expanded');
  });
});

document.addEventListener('DOMContentLoaded', () => {
  const backBtn = document.getElementById('back-button');
  if (backBtn) {
    backBtn.addEventListener('click', () => {
      history.back();
    });
  }
});
