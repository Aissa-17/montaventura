Montaventura – Sistema de Reservas Completo (PHP + Stripe + Calendario)
Montaventura es un sistema completo de reservas y gestión de actividades desarrollado en PHP, pensado para empresas que ofrecen excursiones, bonos, clases y actividades deportivas.
Incluye:

- Formularios dinámicos para bonos, actividades, clases y excursiones
- Calendario con API propia para consultar disponibilidad
- Integración completa con Stripe (checkout, éxito, cancel, webhooks)
- Panel de administración interno
- Sistema de promociones
- Plantillas de confirmación
- Frontend propio (CSS + JS)
- Arquitectura separada por módulos
- Preparado para integrarse con WordPress o funcionar standalone

📦 Estructura del proyecto
montaventura/
│── assets/
│   ├── admin/
│   │   └── mtv-admin.js
│   ├── css/
│   │   ├── calendario-martita.css
│   │   ├── form-style.css
│   │   ├── pa-style.css
│   │   └── styles.css
│   └── js/
│       ├── calendario-martita.js
│       ├── form-script-bonos.js
│       ├── form-script.js
│       └── form-script2.js
│
│── img/
│   ├── Icono-Montaventura.png
│   └── logo_montaventura.webp
│
│── includes/
│   └── api-calendario.php
│
│── templates/
│   ├── cancel.php
│   ├── reserva.php
│   ├── success.php
│   └── formulario/
│       ├── form-bono.php
│       ├── form-clases.php
│       └── form-excursion.php
│
│── vendor/ (Stripe + Composer autoload)
│
├── checkout.php
├── create-promo-table.php
├── panel-actividades.php
├── webhook.php
├── stripe-config.php
├── composer.json
├── composer.lock
└── README.md

- Instalación
1️⃣ Clonar el repositorio
git clone https://github.com/tuusuario/montaventura.git
cd montaventura

2️⃣ Instalar dependencias PHP (Stripe)
composer install

3️⃣ Configurar Stripe
En tu archivo .env o directamente en stripe-config.php:
define("STRIPE_SECRET_KEY", "sk_live_xxxxxx");
define("STRIPE_PUBLIC_KEY", "pk_live_xxxxxx");

4️⃣ Configurar Webhook de Stripe
En dashboard → Developers → Webhooks:
https://tusitio.com/webhook.php

Añade eventos recomendados:
checkout.session.completed
payment_intent.succeeded
payment_intent.payment_failed

- Arquitectura del Proyecto
✔ Frontend (assets/)
Formularios completamente dinámicos
JS modular para cada tipo de actividad
Calendario personalizado
Estilos separados para panel, actividades y checkout

✔ Lógica principal (PHP)
checkout.php → crea sesiones de Stripe según tipo de actividad
webhook.php → gestiona pagos y crea reservas
panel-actividades.php → panel interno
api-calendario.php → devuelve disponibilidad en JSON
create-promo-table.php → inicializa tabla de cupones / promociones

✔ Templates (UI final)
templates/formulario/* → formularios dinámicos
templates/success.php → mensaje tras pago correcto
templates/cancel.php → pago cancelado
templates/reserva.php → resumen de reserva generada

- Calendario dinámico (API)
/includes/api-calendario.php
Devuelve disponibilidad en formato JSON
Conexión AJAX desde calendario-martita.js

Permite:
✔ Bloquear fechas
✔ Mostrar ocupación restante
✔ Comprobar disponibilidad para n personas

- Stripe – Flujo de Pago
1. Cliente elige actividad → formulario
2. JS genera la sesión (tipo, fecha, número de personas…)
3. PHP crea la sesión de Stripe en checkout.php
4. Redirección al Checkout
5. Stripe envía webhook → webhook.php
6. Se crea la reserva y se guarda en la base de datos
7. Usuario recibe plantilla de confirmación

- Endpoints importantes
Endpoint	Descripción
checkout.php	Crea sesiones de pago con Stripe
webhook.php	Recibe eventos de pago
includes/api-calendario.php	Calendario / disponibilidad
templates/formulario/*	Formularios dinámicos
panel-actividades.php	Panel interno admin

-  Seguridad
✔ Validación de inputs
✔ Stripe Webhook verificado
✔ Filtrado de fechas / cupos
✔ Escapado de HTML
✔ Evita reservas duplicadas mediante IDs únicos

- Frontend
CSS modular:
form-style.css, styles.css, pa-style.css, calendario-martita.css.
JS modular por actividad:
Formulario de bonos
Formulario de clases
Formulario de excursiones
Calendario interactivo
Scripts secundarios

- Ideas futuras:
Dashboard completo con estadísticas
Integración con Google Calendar
Exportación PDF de reservas
API REST para apps móviles
Integración directa con WordPress mediante shortcode
Panel para empleados (check-in, control de pagos)

- Screenshots
<img width="1918" height="952" alt="image" src="https://github.com/user-attachments/assets/8bb20a65-4cc9-4a13-8106-0fd123d9e29d" />
<img width="1918" height="953" alt="image" src="https://github.com/user-attachments/assets/0a866fb9-1310-4112-a15a-66fe82d2f227" />

- Licencia
MIT License – Libre para usar en proyectos personales y comerciales.
