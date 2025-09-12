// calendario-martita.js

document.addEventListener('DOMContentLoaded', function() {
  const calendarEl = document.getElementById('calendar-martita');

  // Creamos por anticipado el contenedor del “modal” (oculto por defecto)
  const modal = document.createElement('div');
  modal.id = 'fc-event-modal';
  modal.innerHTML = `
    <div class="fc-modal-overlay"></div>
    <div class="fc-modal-content">
      <button class="fc-modal-close">&times;</button>
      <h2 class="fc-modal-title"></h2>
      <div class="fc-modal-body"></div>
    </div>
  `;
  document.body.appendChild(modal);

  // Añadimos estilos mínimos para el modal
  const styleTag = document.createElement('style');
  styleTag.innerHTML = `
    /* Overlay semi-transparente */
    #fc-event-modal .fc-modal-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.4);
      z-index: 9998;
      display: none;
    }
    /* Contenido del modal centrado */
    #fc-event-modal .fc-modal-content {
      position: fixed;
      top: 50%; left: 50%;
      transform: translate(-50%, -50%);
      background: #fff;
      border-radius: 8px;
      max-width: 400px;
      width: 90%;
      box-shadow: 0 2px 12px rgba(0,0,0,0.3);
      z-index: 9999;
      display: none;
      overflow: hidden;
      font-family: sans-serif;
    }
    /* Botón de cerrar */
    #fc-event-modal .fc-modal-close {
      position: absolute;
      top: 8px; right: 12px;
      background: transparent;
      border: none;
      font-size: 1.5rem;
      cursor: pointer;
      line-height: 1;
    }
    /* Título */
    #fc-event-modal .fc-modal-title {
      margin: 0;
      padding: 1rem 1.5rem 0 1.5rem;
      font-size: 1.25rem;
      border-bottom: 1px solid #ddd;
    }
    /* Contenido */
    #fc-event-modal .fc-modal-body {
      padding: 1rem 1.5rem 1.5rem 1.5rem;
      max-height: 60vh;
      overflow-y: auto;
      font-size: 0.95rem;
      color: #333;
    }
    #fc-event-modal .fc-modal-body p {
      margin: 0.5rem 0;
    }
    #fc-event-modal .fc-modal-body strong {
      display: inline-block;
      width: 140px;
    }
  `;
  document.head.appendChild(styleTag);

  // Funciones para abrir y cerrar el modal
  function openModal() {
    modal.querySelector('.fc-modal-overlay').style.display = 'block';
    modal.querySelector('.fc-modal-content').style.display = 'block';
  }
  function closeModal() {
    modal.querySelector('.fc-modal-overlay').style.display = 'none';
    modal.querySelector('.fc-modal-content').style.display = 'none';
  }
  modal.querySelector('.fc-modal-close').addEventListener('click', closeModal);
  modal.querySelector('.fc-modal-overlay').addEventListener('click', closeModal);

  // Inicializamos FullCalendar
  const calendar = new FullCalendar.Calendar(calendarEl, {
    initialView: 'dayGridMonth',
    locale: 'es',
    firstDay: 1,
    timeZone: 'Europe/Madrid',
    headerToolbar: {
      left: 'prev,next today',
      center: 'title',
      right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
    },

    // 1) No mostrar la hora de fin
    displayEventEnd: false,

    // 2) Formato para que solo se vea la hora de inicio
    eventTimeFormat: {
      hour: '2-digit',
      minute: '2-digit',
      hour12: false
    },

    // Carga de eventos desde el endpoint REST
    events(fetchInfo, successCallback, failureCallback) {
      fetch(mtv_calendario_vars.reservas_endpoint)
        .then(response => {
          if (!response.ok) throw new Error(`HTTP error ${response.status}`);
          return response.json();
        })
        .then(data => successCallback(data))
        .catch(err => {
          console.error('Error al cargar las reservas:', err);
          failureCallback(err);
        });
    },

eventClick(info) {
  info.jsEvent.preventDefault();

  const ev = info.event;
  const p  = ev.extendedProps;

  // — Hora/fecha: usa el formateador de FullCalendar (misma hora que en el grid)
  const inicioStr = calendar.formatDate(ev.start, {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
    hour12: false
  });
const get = (...keys) => {
  for (const k of keys) {
    const v = p[k];
    if (v !== undefined && v !== null && String(v).trim() !== '') return v;
  }
  return '-';
};

const pie = get('pie','talla_pie','botas');
const ski = (p.ski ?? p.esqui ?? p.talla_esqui ?? p.altura ?? '-');

  // Campos alumno/monitor
  const alumno = [p.alumno_nombre, p.alumno_apellidos].filter(Boolean).join(' ').trim() || '-';
const edad   = (p.edad || p.edad_group || '-');
const nivelNum  = Number(p.nivel_grupo || p.nivel_clase || 0);            // 👈 prioriza el numérico
const nivelText = p.nivel || (nivelNum ? `N${nivelNum}` : '-');  
  const tipoAct = /^\d{1,2}:\d{2}$/.test(p.tipo_actividad) ? 'clases' : (p.tipo_actividad || '-');


  const datos = [
    { label: 'Título',          value: ev.title },
    { label: 'Inicio',          value: inicioStr },
    { label: 'Tipo actividad',  value: tipoAct },
    { label: 'Alumno',          value: alumno },
    { label: 'Edad',            value: edad },
    { label: 'Pie (botas)',     value: pie },
    { label: 'Ski',             value: ski },
    {  label: 'Nivel',  value: nivelText},
    { label: 'Teléfono alumno', value: p.alumno_telefono || '-' },
    { label: 'Observaciones',    value: (p.observaciones || '-').toString().trim() },
  ];

  // Rellenar modal
  modal.querySelector('.fc-modal-title').textContent = ev.title;
  const body = modal.querySelector('.fc-modal-body');
  body.innerHTML = '';
  datos.forEach(item => {
    const line = document.createElement('p');
    line.innerHTML = `<strong>${item.label}:</strong> ${item.value}`;
    body.appendChild(line);
  });

  openModal();
},
  });

  calendar.render();

  // ——— Exponer la instancia de FullCalendar para el form ———
  window.mtvCalendar = calendar;

  // ——— Previsualización de la clase seleccionada ———
  const dateInput  = document.getElementById('diaClase');
  const timeSelect = document.getElementById('horaClase');
  const previewEventId = 'clase-preview';

  function updatePreview() {
    const date = dateInput.value;
    const raw  = (timeSelect.value.split('|')[0] || '');
    if (!date || !raw || !window.mtvCalendar) return;

    const start = date + 'T' + raw + ':00';

    // Elimina el preview anterior si existe
    const old = window.mtvCalendar.getEventById(previewEventId);
    if (old) old.remove();

    // Añade el nuevo evento
    window.mtvCalendar.addEvent({
      id:     previewEventId,
      title:  'Tu Clase',
      start:  start,
      allDay: false
    });

    // Salta a la fecha seleccionada
    window.mtvCalendar.gotoDate(date);
  }

  dateInput.addEventListener('change', updatePreview);
  timeSelect.addEventListener('change', updatePreview);

});