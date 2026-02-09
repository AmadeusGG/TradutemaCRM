# Tradutema CRM – Manual de Usuario

## 1. Introducción
Tradutema CRM es un panel interno para WordPress orientado a equipos de gestión de proyectos de traducción. El plugin centraliza la revisión de pedidos de WooCommerce, la coordinación con proveedores externos y la comunicación con clientes desde una única interfaz moderna basada en Bootstrap y DataTables.

El acceso al CRM se habilita para los perfiles con la capacidad `manage_tradutema_crm`, incluyendo al rol Gestor Tradutema (`tradutema_manager`) y a los administradores del sitio. De esta manera, solo los usuarios autorizados pueden consultar y actualizar la información operativa.

## 2. Requisitos previos
- Sitio WordPress 6.0 o superior con WooCommerce activo.
- Acceso al área de administración con permisos para instalar plugins.
- Extensión Tradutema CRM instalada en `wp-content/plugins/tradutema-crm`.
- Rol de usuario Gestor Tradutema asignado a los miembros del equipo que utilizarán la herramienta.

## 3. Instalación y activación
1. Copie la carpeta `tradutema-crm` dentro de `wp-content/plugins/`.
2. Acceda a **Plugins → Plugins instalados** y active **Tradutema CRM**.
3. Durante la activación el sistema creará automáticamente:
   - El rol **Gestor Tradutema** con la capacidad `manage_tradutema_crm`.
   - Las tablas personalizadas `ttm_order_meta`, `ttm_proveedores`, `ttm_email_templates` y `ttm_logs` dentro de la base de datos de WordPress.

## 4. Roles y permisos
- Solo los usuarios con la capacidad `manage_tradutema_crm` pueden acceder al menú **Tradutema CRM**.
- Asigne el rol **Gestor Tradutema** desde **Usuarios → Todos los usuarios** seleccionando el perfil deseado y guardando los cambios.
- El CRM valida permisos y nonce en cada acción AJAX (`admin-ajax.php`); si un usuario no autorizado intenta acceder, la petición se bloquea.

## 5. Acceso al panel
Una vez asignado el rol adecuado, el usuario verá la entrada **Tradutema CRM** en el menú lateral de WordPress. Tras iniciar sesión, los perfiles con rol **Gestor Tradutema** o **Administrador** acceden automáticamente al panel principal del CRM sin pasar por la página "Mi cuenta" de WooCommerce. Todas las pantallas se cargan a pantalla completa sin la cabecera ni el pie estándar de WordPress. La interfaz se divide en:
- **Sidebar izquierdo** (15 % ancho) con accesos rápidos a Dashboard, Proveedores y Plantillas.
- **Cabecera superior** con el logo de Tradutema, el nombre del usuario conectado y un botón de cierre de sesión.
- **Área de contenido principal** (85 %) donde se muestran tablas, formularios y tarjetas informativas.

### 5.1 URLs de acceso directo
Para acceder rápidamente a cada sección desde el navegador, utilice las siguientes rutas dentro del área de administración de WordPress (sustituya `tusitio.com` por el dominio correspondiente):

- `https://tusitio.com/wp-admin/admin.php?page=tradutema-crm` — Dashboard principal del CRM.
- `https://tusitio.com/wp-admin/admin.php?page=tradutema-crm-proveedores` — Gestión completa de proveedores.
- `https://tusitio.com/wp-admin/admin.php?page=tradutema-crm-plantillas` — Administración de plantillas de email.

Recuerde que el acceso está limitado a usuarios con la capacidad `manage_tradutema_crm`, por lo que primero debe iniciar sesión en `https://tusitio.com/wp-login.php` con un usuario autorizado.

## 6. Dashboard de pedidos
### 6.1 Filtros
En la parte superior encontrará un formulario de filtros con cuatro criterios combinables:
- **Estado de pago** (estados propios de WooCommerce).
- **Estado operacional** (01-Recibido., 02-En espera de tasación., 03-Asignado y en curso., 04-Traducido., 05-En espera validación cliente. o 06-Entregado.).
- **Proveedor asignado** (lista de proveedores activos).
- **Rango de fechas** (fecha inicial y final del pedido).
Pulse **Aplicar filtros** para refrescar la tabla con los parámetros seleccionados.

### 6.2 Tabla de pedidos
La tabla se alimenta mediante AJAX y muestra, para cada pedido de WooCommerce, el número, el nombre del cliente, email, fecha, estado de pago, proveedor y estado operacional. El botón **Ver pedido** abre la ficha detallada en un modal.

### 6.3 Ficha del pedido
El modal de detalle incluye tres bloques:
1. **Datos del pedido**: resumen de datos de WooCommerce (cliente, contacto, dirección, método y estado de pago, total y fecha).
2. **Datos adicionales**: formulario editable con los campos internos del CRM (proveedor, origen, referencia, fechas previstas y reales, idiomas, páginas, tarifa aplicada, comentario interno y envío en papel). Al guardar se registran los cambios y se refresca el dashboard.
3. **Historial y comunicaciones**:
   - **Plantillas de email**: botones para cada plantilla activa; al pulsar se previsualiza asunto y cuerpo con las variables completadas. Se puede ajustar el destinatario antes de enviar. Los mensajes se envían a través de Solid Mail (con registro en su log y del CRM).
   - **Historial de acciones**: listado cronológico con el tipo de evento (cambios de estado o emails enviados), el usuario y la fecha.

## 7. Gestión de proveedores
### 7.1 Listado
Desde la pestaña **Proveedores** se muestra una tabla con los proveedores activos, indicando nombre comercial, persona de contacto, idiomas, email y estado.

### 7.2 Creación y edición
Use el botón **Añadir proveedor** para abrir el formulario en modal. Complete los datos necesarios (nombre, contacto, tarifas, disponibilidad, idiomas, métodos de pago, etc.) y pulse **Guardar proveedor**. Para editar, utilice el botón **Editar** en la fila correspondiente; el modal se rellenará automáticamente con la información existente.

### 7.3 Desactivación
El botón **Desactivar** marca al proveedor como inactivo. Los proveedores inactivos dejan de aparecer en el selector del dashboard y pueden reactivarse editando su ficha y cambiando el estado a **Activo**.

## 8. Plantillas de email
### 8.1 Listado
En la sección **Plantillas de email** encontrará todas las plantillas registradas con su asunto, estado (activa/inactiva) y fecha de actualización.

### 8.2 Creación y edición
El botón **Añadir plantilla** abre un modal con campos para nombre, asunto, cuerpo HTML y un check de activación. Un bloque informativo muestra las variables disponibles (`{{order_id}}`, `{{customer_name}}`, `{{estado_operacional}}`, `{{tipo_envio}}`, `{{num_paginas}}`, `{{fecha_real_entrega_proveedor}}`, `{{proveedor.email}}`, `{{gdrive_link_full_folder}}`, `{{gdrive_link_source}}`, `{{gdrive_link_work}}`, `{{gdrive_link_translation}}`, `{{gdrive_link_To_Client}}`, `{{Upload_To_Client}}`, etc.); puede insertarlas en el cuerpo haciendo clic sobre cada etiqueta.

### 8.3 Gestión del estado
- Marque **Plantilla activa** para que aparezca como botón en la ficha de pedidos.
- Para desactivar una plantilla, edítela y desmarque la casilla de activación. También es posible usar el botón **Eliminar**, que la desactiva automáticamente.

## 9. Registro de actividad
Cada cambio relevante genera una entrada en la tabla de logs interna:
- Guardado de datos operacionales (`tipo = estado`).
- Envío de emails (`tipo = email`, con detalle del asunto, destinatario y plantilla utilizada).
El historial es accesible desde el modal de pedidos y sirve como trazabilidad de acciones por usuario.

## 10. Seguridad y buenas prácticas
- Todas las peticiones asíncronas verifican nonce y capacidades antes de procesarse.
- Los formularios sanitizan y escapan la información automáticamente.
- Utilice conexiones HTTPS para proteger credenciales.
- Mantenga WordPress, WooCommerce y el plugin actualizados para beneficiarse de las últimas mejoras y correcciones.

## 11. Solución de problemas
| Situación | Posible causa | Acción recomendada |
|-----------|---------------|--------------------|
| No aparece el menú Tradutema CRM | El usuario no tiene el rol Gestor Tradutema | Asignar el rol desde **Usuarios** y volver a iniciar sesión. |
| Al iniciar sesión vuelve constantemente a "Mi cuenta" | WooCommerce impide el acceso al escritorio para el rol Gestor | Verificar que el plugin está activo; este fuerza el acceso directo al panel del CRM. |
| Los pedidos no cargan en la tabla | Nonce caducado o sesión expirada | Refrescar la página; si persiste, cerrar sesión y volver a entrar. |
| Un proveedor no aparece en el selector del pedido | Está marcado como inactivo | Editar el proveedor y establecer el estado en **Activo**. |
| Emails enviados sin variables sustituidas | La plantilla contiene variables no soportadas | Revisar el listado de variables disponibles y corregir el cuerpo del mensaje. |
| Error al guardar datos adicionales | Falta algún dato obligatorio o se perdió la sesión | Verificar campos, recargar el modal y reintentar; comprobar el log del navegador para más detalles. |

## 12. Siguientes pasos
- Configurar plantillas base para cada flujo de comunicación habitual.
- Completar la base de proveedores con datos detallados para facilitar la asignación.
- Establecer procedimientos internos para revisar el historial de logs y asegurar la trazabilidad de cada pedido.
