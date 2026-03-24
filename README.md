<p align="center">
  <img src="logo.png" width="120" alt="Mail Blast logo">
</p>

<h1 align="center">Mail Blast</h1>

<p align="center">
  <strong>GLPI plugin — Send bulk HTML emails to all registered users</strong>
</p>

<p align="center">
  <a href="https://github.com/glpi-project/glpi" target="_blank"><img src="https://img.shields.io/badge/GLPI-11.0%2B-blue" alt="GLPI compatibility"></a>
  <a href="https://www.gnu.org/licenses/old-licenses/gpl-2.0.html" target="_blank"><img src="https://img.shields.io/badge/License-GPL%20v2%2B-green" alt="License"></a>
  <a href="https://php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-%3E%3D8.2-purple" alt="PHP"></a>
  <a href="https://github.com/monta990/mailblast/releases" target="_blank"><img alt="GitHub Downloads (all assets, all releases)" src="https://img.shields.io/github/downloads/monta990/mailblast/total"></a>
</p>

---

## Overview

**Mail Blast** lets any GLPI administrator compose and send a bulk email to every active user that has a default email address registered — directly from GLPI's interface, using its native SMTP configuration.

No external services. No cron jobs. No extra dependencies beyond GLPI itself.

---

## Features

| Feature | Details |
|---|---|
| **Rich-text body editor** | TinyMCE 6 — bold, italic, tables, images, lists, indentation, text alignment |
| **Text alignment** | Left, center, right and justify buttons in the toolbar |
| **Footer editor** | Simple text editor with bold, italic and underline; line breaks preserved |
| **Inline images** | Images inserted in the body are converted to base64 before sending; nothing is stored permanently on the server |
| **Attachments** | Drag-and-drop or file picker; bytes travel as base64 — never stored on the server |
| **GLPI MIME validation** | Only file types allowed by GLPI's document type config are accepted |
| **Test send** | Send to your own admin address or any specific address before the mass mailing |
| **AJAX progress modal** | Real-time progress bar, sent / errors / pending counters, elapsed time, per-address error list |
| **Cancel mid-send** | Two-click cancel button stops the queue at the next batch |
| **Queue-based sending** | Recipients processed in batches of 15; the browser never freezes |
| **Form persistence** | Subject and footer saved in `glpi_configs` and restored on next visit |
| **Multi-send safe** | CSRF token is rotated after each AJAX call |
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
| **Message body** | Required. Full HTML — paste images, insert tables, format text, align paragraphs. |
| **Footer** | Optional. Plain text with bold, italic and underline support. Appended below the body, separated by a horizontal rule. Persisted between sessions. |

### 2 — Attachments

Drag files into the drop zone or click **browse**. Selected files are shown in a list with size and a remove button. Only MIME types allowed by GLPI's document configuration are accepted.

Files are read into browser memory and sent as base64 — nothing is saved to the server's filesystem or database.

### 3 — Test send

Before sending to everyone, verify layout and attachments:

- **Send to my address** — delivers to the default email of the currently logged-in administrator.
- **Send to a specific address** — enter any valid email address.

Result is shown inline without page reload.

### 4 — Mass mailing

Once satisfied, click **Send to all users**. A confirmation dialog shows the recipient count. The plugin sends only to:

- `is_active = 1`
- `is_deleted = 0`
- Non-empty `is_default = 1` email address in `glpi_useremails`

A progress modal shows real-time status. You can cancel the send at any time using the Cancel button — emails already sent are not recalled.

---

## Permissions

Access requires the GLPI right **`config: UPDATE`** (full administrator profile). No additional rights configuration needed.

---

## How attachments and images work

**Attachments:** JS reads each file with `FileReader.readAsDataURL()` → base64 in browser RAM → posted as JSON → PHP decodes to a per-request temp file → attached to the email → deleted immediately.

**Body images:** Images inserted via TinyMCE are uploaded to `glpi_documents` temporarily. When the email is sent, `embedImagesAsBase64()` converts each image to an inline base64 data URI and immediately deletes the document record and file from the server.

At no point does any file remain on the server after the email is sent.

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

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html).

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
  <a href="https://github.com/glpi-project/glpi" target="_blank"><img src="https://img.shields.io/badge/GLPI-11.0%2B-blue" alt="GLPI compatibility"></a>
  <a href="https://www.gnu.org/licenses/old-licenses/gpl-2.0.html" target="_blank"><img src="https://img.shields.io/badge/License-GPL%20v2%2B-green" alt="License"></a>
  <a href="https://php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-%3E%3D8.2-purple" alt="PHP"></a>
  <a href="https://github.com/monta990/mailblast/releases" target="_blank"><img alt="GitHub Downloads (all assets, all releases)" src="https://img.shields.io/github/downloads/monta990/mailblast/total"></a>
</p>

---

## Descripción general

**Mail Blast** permite a cualquier administrador de GLPI redactar y enviar un correo masivo a todos los usuarios activos que tengan una dirección de correo predeterminada registrada — directamente desde la interfaz de GLPI, utilizando su configuración SMTP nativa.

Sin servicios externos. Sin tareas cron. Sin dependencias adicionales más allá del propio GLPI.

---

## Características

| Característica | Detalles |
|---|---|
| **Editor de cuerpo enriquecido** | TinyMCE 6 — negritas, cursiva, tablas, imágenes, listas, sangría, alineación de texto |
| **Alineación de texto** | Botones izquierda, centro, derecha y justificado en la barra de herramientas |
| **Editor de pie de página** | Editor de texto simple con negrita, cursiva y subrayado; saltos de línea preservados |
| **Imágenes embebidas** | Las imágenes insertadas en el cuerpo se convierten a base64 antes de enviar; nada queda almacenado permanentemente en el servidor |
| **Archivos adjuntos** | Arrastrar y soltar o selector de archivos; los bytes viajan como base64 — nunca se almacenan en el servidor |
| **Validación MIME de GLPI** | Solo se aceptan los tipos de archivo permitidos por la configuración de tipos de documentos de GLPI |
| **Correo de prueba** | Envía a tu propia dirección de administrador o a cualquier dirección antes del envío masivo |
| **Modal de progreso AJAX** | Barra de progreso en tiempo real, contadores de enviados / errores / pendientes, tiempo transcurrido, lista de errores por dirección |
| **Cancelar a mitad de envío** | Botón de cancelar de dos clics detiene la cola en el siguiente lote |
| **Envío por cola** | Destinatarios procesados en lotes de 15; el navegador nunca se congela |
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
| **Cuerpo del mensaje** | Obligatorio. HTML completo — pega imágenes, inserta tablas, da formato al texto, alinea párrafos. |
| **Pie de página** | Opcional. Texto simple con soporte de negrita, cursiva y subrayado. Se agrega debajo del cuerpo separado por una línea horizontal. Se persiste entre sesiones. |

### 2 — Archivos adjuntos

Arrastra archivos a la zona de soltar o haz clic en **seleccionar**. Los archivos se muestran en una lista con tamaño y botón para eliminar. Solo se aceptan los tipos MIME permitidos por la configuración de documentos de GLPI.

Los archivos se leen en memoria del navegador y se envían como base64 — nada se guarda en el sistema de archivos ni en la base de datos del servidor.

### 3 — Correo de prueba

Antes de enviar a todos, verifica el diseño y los adjuntos:

- **Enviar a mi dirección** — entrega al correo predeterminado del administrador actualmente conectado.
- **Enviar a una dirección específica** — ingresa cualquier dirección de correo válida.

El resultado se muestra en pantalla sin recargar la página.

### 4 — Envío masivo

Una vez satisfecho, haz clic en **Enviar a todos los usuarios**. Un diálogo de confirmación muestra el número de destinatarios. El plugin envía únicamente a:

- `is_active = 1`
- `is_deleted = 0`
- Dirección no vacía con `is_default = 1` en `glpi_useremails`

Un modal de progreso muestra el estado en tiempo real. Puedes cancelar el envío en cualquier momento con el botón Cancelar — los correos ya enviados no se recuperan.

---

## Permisos

El acceso requiere el derecho de GLPI **`config: UPDATE`** (perfil de administrador completo). No se necesita configuración adicional de derechos.

---

## Cómo funcionan los adjuntos e imágenes

**Adjuntos:** JS lee cada archivo con `FileReader.readAsDataURL()` → base64 en RAM del navegador → enviado como JSON → PHP decodifica a archivo temporal → adjunto al correo → eliminado inmediatamente.

**Imágenes del cuerpo:** las imágenes insertadas vía TinyMCE se suben temporalmente a `glpi_documents`. Al enviar, `embedImagesAsBase64()` convierte cada imagen a una URI base64 inline y elimina inmediatamente el registro y el archivo del servidor.

En ningún momento queda ningún archivo en el servidor después de que el correo es enviado.

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

GPL v2 o posterior. Ver [LICENSE](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html).

## Problemas

Reporta errores o solicita funcionalidades en el [issue tracker](https://github.com/monta990/mailblast/issues).
