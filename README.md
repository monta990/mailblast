<p align="center">
  <img src="logo.png" width="120" alt="Mail Blast logo">
</p>

<h1 align="center">Mail Blast</h1>

<p align="center">
  <strong>GLPI plugin — Send bulk HTML emails to all registered users</strong>
</p>

<p align="center">
  <a href="https://github.com/glpi-project/glpi" target="_blank"><img src="https://img.shields.io/badge/GLPI-11.0%2B-blue?style=flat-square" alt="GLPI compatibility"></a>
  <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank"><img src="https://img.shields.io/badge/License-GPL%20v3%2B-green?style=flat-square" alt="License"></a>
  <a href="https://php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-%3E%3D8.2-purple?style=flat-square" alt="PHP"></a>
  <a href="https://github.com/monta990/mailblast/releases" target="_blank"><img alt="GitHub Downloads (all assets, all releases)" src="https://img.shields.io/github/downloads/monta990/mailblast/total"></a>
</p>

---

## Overview

**Mail Blast** lets any GLPI administrator compose and send a bulk HTML email to every active user that has a default email address registered — directly from GLPI's interface, using its native SMTP configuration.

No external services. No cron jobs. No extra dependencies beyond GLPI itself.

---

## Features

| Feature | Details |
|---|---|
| **Rich-text body editor** | TinyMCE 7 (GLPI11) — bold, italic, tables, images, lists, indentation, text alignment |
| **Text alignment** | Left, center, right and justify buttons in the toolbar |
| **Footer editor** | Native contenteditable editor with bold, italic and underline; line breaks preserved |
| **Inline images** | Images inserted in the body are converted to base64 at send time and immediately deleted from the server |
| **Attachments** | Drag-and-drop or file picker; bytes travel as base64 — never stored on the server |
| **Attachment size limit** | Configurable maximum combined attachment size (default 15 MB); enforced in the browser before any upload |
| **GLPI MIME validation** | Only file types allowed by GLPI's document type config are accepted |
| **Test send** | Send to your admin address or up to 5 comma-separated addresses before the mass mailing |
| **Sending report (XLSX)** | Download a formatted Excel report after every send with date, subject, email, status and reason for each recipient |
| **AJAX progress modal** | Real-time progress bar, sent / errors / pending counters, elapsed time, per-address error list |
| **Cancel mid-send** | Two-click cancel button stops the queue at the next batch |
| **Queue-based sending** | Recipients processed in configurable batches; the browser never freezes |
| **Configurable batch size** | Batch size and inter-batch delay adjustable from the plugin configuration page |
| **Form persistence** | Subject and footer saved in `glpi_configs` and restored on next visit |
| **Multi-send safe** | CSRF token rotated after each AJAX call |
| **Full i18n** | `es_MX`, `en_US`, `en_GB`, `fr_FR`, `de_DE` |

---

## Requirements

| Requirement | Version |
|---|---|
| GLPI | ≥ 11.0.0 |
| PHP | ≥ 8.2 |
| SMTP | Must be configured under *Setup → Notifications → Email followups* |

---

## Installation

### From GitHub releases (recommended)

1. Download the zip file from the [releases page](https://github.com/monta990/mailblast/releases).
2. Extract its contents into your GLPI plugins directory:
   ```
   glpi/plugins/mailblast/
   ```
3. In GLPI go to **Setup → Plugins**, find **Mail Blast** and click **Install**, then **Enable**.
4. The plugin appears in the **Administration** menu as **Mail Blast**.

### Manual

```bash
cd /path/to/glpi/plugins
git clone https://github.com/monta990/mailblast.git
```

Then install and enable from **Setup → Plugins**.

---

## Usage

### 1 — Compose

| Field | Notes |
|---|---|
| **Subject** | Required. Plain text, max 250 characters. |
| **Message body** | Required. Full HTML — paste images, insert tables, format text, align paragraphs. Images inserted here are automatically embedded as base64 at send time and removed from the server. |
| **Footer** | Optional. Plain text with bold, italic and underline. Appended below the body separated by a horizontal rule. Persisted between sessions. |

### 2 — Attachments

Drag files into the drop zone or click **browse**. Selected files appear in a list with size and a remove button. Only MIME types allowed by GLPI's document configuration are accepted.

The total combined size of all attachments is validated against the configured limit (default 15 MB) directly in the browser — files that would exceed the limit are rejected before any data is sent. Files are read into browser memory and transmitted as base64 JSON — nothing is saved to the server's filesystem or database.

### 3 — Test send

Before sending to everyone, verify layout and attachments:

- **Send to my address** — delivers to the default email of the currently logged-in administrator.
- **Send to a specific address** — enter one or up to 5 comma-separated email addresses.

Result is shown inline without page reload.

### 4 — Mass mailing

Click **Send to all users**. A confirmation dialog shows the recipient count. The plugin sends only to:

- `is_active = 1`
- `is_deleted = 0`
- Non-empty `is_default = 1` email address in `glpi_useremails`

A progress modal shows real-time status. You can cancel at any time using the Cancel button — emails already sent are not recalled.

### 5 — Configuration

Access the configuration page via the **gear icon** in the plugin list (*Setup → Plugins*) or via the settings button in the plugin's card header.

| Setting | Default | Range | Description |
|---|---|---|---|
| **Batch size** | 15 | 1–100 | Number of emails sent per batch. Lower values reduce SMTP load on slow servers; higher values speed up large sends. |
| **Delay between batches** | 120 ms | 0–5000 ms | Wait time between consecutive batches. Increase this if your SMTP provider enforces rate limits (e.g. 500 ms for restrictive providers). |
| **Max attachment size** | 15 MB | 1–100 MB | Maximum combined size of all attachments. Enforced in the browser. Prevents SMTP timeouts caused by large payloads being sent to hundreds of recipients. |

---

## Architecture

### Sending flow

```
Browser                              PHP / GLPI
  │                                      │
  ├─ queue_init POST ──────────────────► │ embedImagesAsBase64()
  │                                      │ buildHtmlBody() → HTML5 wrapper
  │                                      │ html2text() → plain-text alt body
  │ ◄── { send_id, html, plain, … } ─── │ store job in glpi_configs
  │                                      │
  ├─ queue_process POST (offset=0) ────► │ SELECT users LIMIT batch_size OFFSET 0
  │                                      │ send batch via Symfony Mailer
  │ ◄── { done, next_offset, … } ─────  │
  │                                      │
  ├─ [wait batch_delay_ms] ─────────── → │
  ├─ queue_process POST (offset=N) ────► │ SELECT … LIMIT batch_size OFFSET N
  │   …                                  │   …
  └─ done ◄───────────────────────────── │ cleanupJob()
```

### Why queue-based?

Sending to hundreds of recipients in a single HTTP request would hit PHP's `max_execution_time` and the browser's request timeout. The queue approach splits the work into small batches, each completing in under a second. The browser drives the loop — no background process or cron job needed.

### Zero server-side file storage

- **Attachments** — read as base64 in the browser, posted as JSON, decoded to a per-request temp file, attached to the email via Symfony Mailer, deleted immediately after the email is sent.
- **Body images** — uploaded to `glpi_documents` by TinyMCE's native handler during composition. At send time, `embedImagesAsBase64()` converts each image to an inline data URI and immediately deletes the document record and file from the server. At no point does any image or attachment remain on the server after the email is sent.

---

## Permissions

Access requires the GLPI right **`config: UPDATE`** (full administrator profile). No additional rights configuration needed.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## Author

**Edwin Elias Alvarez** — [GitHub](https://github.com/monta990)

---

## Buy me a coffee :)
If you like my work, you can support me by a donate here:

<a href="https://www.buymeacoffee.com/monta990" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/default-yellow.png" alt="Buy Me A Coffee" height="51px" width="210px"></a>

---

## License

GPL v3 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-3.0.html).

## Issues

Report bugs or request features on the [issue tracker](https://github.com/monta990/mailblast/issues).

---

<p align="center">
  <img src="logo.png" width="120" alt="Logo de Mail Blast">
</p>

<h1 align="center">Mail Blast</h1>

<p align="center">
  <strong>Plugin para GLPI — Envío masivo de correos HTML a todos los usuarios registrados</strong>
</p>

<p align="center">
  <a href="https://github.com/glpi-project/glpi" target="_blank"><img src="https://img.shields.io/badge/GLPI-11.0%2B-blue?style=flat-square" alt="GLPI compatibility"></a>
  <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank"><img src="https://img.shields.io/badge/License-GPL%20v3%2B-green?style=flat-square" alt="License"></a>
  <a href="https://php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-%3E%3D8.2-purple?style=flat-square" alt="PHP"></a>
  <a href="https://github.com/monta990/mailblast/releases" target="_blank"><img alt="GitHub Downloads (all assets, all releases)" src="https://img.shields.io/github/downloads/monta990/mailblast/total"></a>
</p>

---

## Descripción general

**Mail Blast** permite a cualquier administrador de GLPI redactar y enviar un correo HTML masivo a todos los usuarios activos que tengan una dirección de correo predeterminada registrada — directamente desde la interfaz de GLPI, utilizando su configuración SMTP nativa.

Sin servicios externos. Sin tareas cron. Sin dependencias adicionales más allá del propio GLPI.

---

## Características

| Característica | Detalles |
|---|---|
| **Editor de cuerpo enriquecido** | TinyMCE 6 — negritas, cursiva, tablas, imágenes, listas, sangría, alineación de texto |
| **Alineación de texto** | Botones izquierda, centro, derecha y justificado en la barra de herramientas |
| **Editor de pie de página** | Editor contenteditable nativo con negrita, cursiva y subrayado; saltos de línea preservados |
| **Imágenes embebidas** | Las imágenes insertadas se convierten a base64 al enviar y se eliminan inmediatamente del servidor |
| **Archivos adjuntos** | Arrastrar y soltar o selector de archivos; los bytes viajan como base64 — nunca se almacenan en el servidor |
| **Límite de adjuntos** | Tamaño máximo total de adjuntos configurable (predeterminado 15 MB); validado en el navegador antes de cualquier envío |
| **Validación MIME de GLPI** | Solo se aceptan los tipos de archivo permitidos por la configuración de tipos de documentos de GLPI |
| **Correo de prueba** | Envía a tu dirección o hasta 5 direcciones separadas por comas antes del envío masivo |
| **Informe de envío (XLSX)** | Descarga un informe Excel formateado tras cada envío con fecha, asunto, correo, estado y motivo por destinatario |
| **Modal de progreso AJAX** | Barra de progreso en tiempo real, contadores de enviados / errores / pendientes, tiempo transcurrido |
| **Cancelar a mitad de envío** | Botón de cancelar de dos clics detiene la cola en el siguiente lote |
| **Envío por cola** | Destinatarios procesados en lotes configurables; el navegador nunca se congela |
| **Tamaño de lote configurable** | Tamaño de lote y retraso entre lotes ajustables desde la página de configuración |
| **Persistencia del formulario** | Asunto y pie guardados en `glpi_configs` y restaurados en la siguiente visita |
| **Envíos múltiples seguros** | El token CSRF rota después de cada llamada AJAX |
| **i18n completo** | `es_MX`, `en_US`, `en_GB`, `fr_FR`, `de_DE` |

---

## Requisitos

| Requisito | Versión |
|---|---|
| GLPI | ≥ 11.0.0 |
| PHP | ≥ 8.2 |
| SMTP | Debe estar configurado en *Configuración → Notificaciones → Configuración de correo electrónico* |

---

## Instalación

### Desde GitHub releases (recomendado)

1. Descarga el archivo zip desde la [página de releases](https://github.com/monta990/mailblast/releases).
2. Extrae su contenido en el directorio de plugins de GLPI:
   ```
   glpi/plugins/mailblast/
   ```
3. En GLPI ve a **Configuración → Plugins**, encuentra **Mail Blast** y haz clic en **Instalar**, luego en **Activar**.
4. El plugin aparece en el menú de **Administración** como **Mail Blast**.

### Manual

```bash
cd /ruta/a/glpi/plugins
git clone https://github.com/monta990/mailblast.git
```

Luego instala y activa desde **Configuración → Plugins**.

---

## Uso

### 1 — Redactar

| Campo | Notas |
|---|---|
| **Asunto** | Obligatorio. Texto plano, máximo 250 caracteres. |
| **Cuerpo del mensaje** | Obligatorio. HTML completo — pega imágenes, inserta tablas, da formato al texto, alinea párrafos. Las imágenes insertadas se convierten automáticamente a base64 al enviar y se eliminan del servidor. |
| **Pie de página** | Opcional. Texto simple con negrita, cursiva y subrayado. Se agrega debajo del cuerpo con separador horizontal. Se persiste entre sesiones. |

### 2 — Archivos adjuntos

Arrastra archivos a la zona de soltar o haz clic en **seleccionar**. Los archivos aparecen en una lista con tamaño y botón de eliminar. Solo se aceptan los tipos MIME permitidos por la configuración de documentos de GLPI.

El tamaño combinado de todos los adjuntos se valida contra el límite configurado (predeterminado 15 MB) directamente en el navegador — los archivos que lo excedan se rechazan antes de cualquier envío. Los archivos se transmiten como base64 JSON — nada se guarda en el servidor.

### 3 — Correo de prueba

Antes de enviar a todos, verifica el diseño y los adjuntos:

- **Enviar a mi dirección** — entrega al correo predeterminado del administrador conectado.
- **Enviar a una dirección específica** — ingresa una o hasta 5 direcciones separadas por comas.

El resultado se muestra en pantalla sin recargar la página.

### 4 — Envío masivo

Haz clic en **Enviar a todos los usuarios**. Un diálogo de confirmación muestra el número de destinatarios. El plugin envía únicamente a:

- `is_active = 1`
- `is_deleted = 0`
- Dirección no vacía con `is_default = 1` en `glpi_useremails`

Un modal de progreso muestra el estado en tiempo real. Puedes cancelar en cualquier momento — los correos ya enviados no se recuperan.

### 5 — Configuración

Accede a la página de configuración desde el **icono del engrane** en la lista de plugins (*Configuración → Plugins*) o desde el botón de configuración en el encabezado del plugin.

| Ajuste | Predeterminado | Rango | Descripción |
|---|---|---|---|
| **Tamaño de lote** | 15 | 1–100 | Número de correos por lote. Valores bajos reducen la carga en servidores SMTP lentos; valores altos aceleran envíos grandes. |
| **Retraso entre lotes** | 120 ms | 0–5000 ms | Tiempo de espera entre lotes. Auméntalo si tu proveedor SMTP aplica límites de velocidad. |
| **Tamaño máximo de adjuntos** | 15 MB | 1–100 MB | Tamaño combinado máximo de todos los adjuntos. Se valida en el navegador y previene timeouts en el SMTP al enviar a cientos de destinatarios. |

---

## Arquitectura

### Flujo de envío

```
Navegador                            PHP / GLPI
  │                                      │
  ├─ queue_init POST ──────────────────► │ embedImagesAsBase64()
  │                                      │ buildHtmlBody() → wrapper HTML5
  │                                      │ html2text() → cuerpo texto plano
  │ ◄── { send_id, html, plain, … } ─── │ guarda job en glpi_configs
  │                                      │
  ├─ queue_process POST (offset=0) ────► │ SELECT usuarios LIMIT lote OFFSET 0
  │                                      │ envía lote via Symfony Mailer
  │ ◄── { done, next_offset, … } ─────  │
  │                                      │
  ├─ [espera retraso_entre_lotes] ─────► │
  ├─ queue_process POST (offset=N) ────► │ SELECT … LIMIT lote OFFSET N
  │   …                                  │   …
  └─ done ◄───────────────────────────── │ cleanupJob()
```

### Por qué basado en cola

Enviar a cientos de destinatarios en una sola petición HTTP superaría el `max_execution_time` de PHP y el timeout del navegador. El enfoque de cola divide el trabajo en lotes pequeños, cada uno completando en menos de un segundo. El navegador conduce el bucle — sin procesos en segundo plano ni cron.

### Sin almacenamiento de archivos en el servidor

- **Adjuntos** — leídos como base64 en el navegador, enviados como JSON, decodificados a archivo temporal, adjuntados via Symfony Mailer, eliminados inmediatamente tras el envío.
- **Imágenes del cuerpo** — subidas a `glpi_documents` por TinyMCE durante la redacción. Al enviar, `embedImagesAsBase64()` convierte cada imagen a data URI inline y elimina inmediatamente el registro y el archivo del servidor. En ningún momento queda ningún archivo en el servidor después del envío.

---

## Permisos

El acceso requiere el derecho de GLPI **`config: UPDATE`** (perfil de administrador completo). No se necesita configuración adicional de derechos.

---

## Cambios

Ver [CHANGELOG.md](CHANGELOG.md).

---

## Autor

**Edwin Elias Alvarez** — [GitHub](https://github.com/monta990)

---

## Comprame un cafe :)
Si te gusta mi trabajo, me puedes apoyar con una donación:

<a href="https://www.buymeacoffee.com/monta990" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/default-yellow.png" alt="Buy Me A Coffee" height="51px" width="210px"></a>

---

## Licencia

GPL v3 o posterior. Ver [LICENSE](https://www.gnu.org/licenses/gpl-3.0.html).

## Problemas

Reporta errores o solicita funcionalidades en el [issue tracker](https://github.com/monta990/mailblast/issues).
