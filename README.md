<p align="center">
  <img src="logo.png" width="120" alt="Mail Blast logo">
</p>

<h1 align="center">Mail Blast</h1>

<p align="center">
  <strong>GLPI plugin — Send bulk HTML emails to all registered users</strong>
</p>

<p align="center">
  <a href="https://github.com/glpi-project/glpi" target="_blank"><img src="https://img.shields.io/badge/GLPI-11.0%2B-blue?style=flat-square" alt="GLPI compatibility"></a>
  <a href="https://www.gnu.org/licenses/old-licenses/gpl-2.0.html" target="_blank"><img src="https://img.shields.io/badge/License-GPL%20v2%2B-green?style=flat-square" alt="License"></a>
  <a href="https://php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-%3E%3D8.2-purple?style=flat-square" alt="PHP"></a>
</p>

---

## Overview

**Mail Blast** lets any GLPI administrator compose and send a bulk email to every active user that has a default email address registered — directly from GLPI's interface, using its native SMTP configuration.

No external services. No cron jobs. No extra dependencies beyond GLPI itself.

---

## Features

| Feature | Details |
|---|---|
| **Rich-text editor** | TinyMCE 6 for body and footer — bold, italic, links, tables, images |
| **Inline images** | Images pasted or inserted are converted to base64 in the browser; nothing touches `glpi_documents` |
| **Attachments** | Drag-and-drop or file picker; bytes live in browser RAM and travel as base64 — never stored on the server |
| **GLPI MIME validation** | Only file types allowed by GLPI's document type config are accepted |
| **Test send** | Send to your own admin address or any specific address before the mass mailing |
| **AJAX progress modal** | Real-time progress bar, sent / errors / pending counters, elapsed time, per-address error list |
| **Queue-based sending** | Recipients processed in batches of 15; the browser never freezes |
| **Form persistence** | Subject and footer saved in `glpi_configs` and restored on next visit |
| **Theme-aware** | Body content survives dark/light theme switches via `sessionStorage` |
| **Multi-send safe** | CSRF token is rotated after each AJAX call — send multiple test emails without reloading |
| **Full i18n** | `es_MX`, `en_US`, `en_GB`, `fr_FR`, `de_DE` — all strings go through GLPI's `__()` |

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

1. Download `mailblast-1_0_0.zip` from the [releases page](https://github.com/monta990/mailblast/releases).
2. Extract into your GLPI plugins directory:
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
| **Message body** | Required. Full HTML — paste images, insert tables, format text. |
| **Footer** | Optional. Appended below the body, separated by a horizontal rule. Persisted between sessions. |

### 2 — Attachments

Drag files into the drop zone or click **browse**. Selected files are shown in a list with size and a remove button. Only MIME types allowed by GLPI's document configuration are accepted — the full list is visible by expanding **View allowed file types**.

Files are read into browser memory and sent as base64 — nothing is saved to the server's filesystem or database.

### 3 — Test send

Before sending to everyone, verify layout and attachments:

- **Send to my address** — delivers to the default email of the currently logged-in administrator.
- **Send to a specific address** — enter any valid email address.

Result is shown inline without page reload. You can send multiple test emails in the same session.

### 4 — Mass mailing

Once satisfied, click **Send to all users**. A confirmation dialog shows the recipient count. The plugin sends only to:

- `is_active = 1`
- `is_deleted = 0`
- Non-empty `is_default = 1` email address in `glpi_useremails`

A progress modal shows real-time status. Errors per address are listed as they occur.

---

## Permissions

Access requires the GLPI right **`config: UPDATE`** (full administrator profile). No additional rights configuration needed.

---

## How attachments work

1. JS reads each file with `FileReader.readAsDataURL()` → base64 string in browser RAM.
2. Base64 strings are posted in the AJAX body (`attachments_b64` JSON field).
3. PHP decodes each string to a per-request temp file (`tempnam()`).
4. The temp file is attached to the Symfony `Email` object via `attachFromPath()`.
5. The temp file is deleted immediately after `$transport->send()`.

At no point does a file touch `glpi_documents`, `glpi_configs`, or any persistent storage.

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
  <a href="https://github.com/glpi-project/glpi" target="_blank"><img src="https://img.shields.io/badge/GLPI-11.0%2B-blue?style=flat-square" alt="GLPI compatibility"></a>
  <a href="https://www.gnu.org/licenses/old-licenses/gpl-2.0.html" target="_blank"><img src="https://img.shields.io/badge/License-GPL%20v2%2B-green?style=flat-square" alt="License"></a>
  <a href="https://php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-%3E%3D8.2-purple?style=flat-square" alt="PHP"></a>
</p>

---

## Descripción general

**Mail Blast** permite a cualquier administrador de GLPI redactar y enviar un correo masivo a todos los usuarios activos que tengan una dirección de correo predeterminada registrada — directamente desde la interfaz de GLPI, utilizando su configuración SMTP nativa.

Sin servicios externos. Sin tareas cron. Sin dependencias adicionales más allá del propio GLPI.


---

## Características

| Característica | Detalles |
|---|---|
| **Editor de texto enriquecido** | TinyMCE 6 para cuerpo y pie — negritas, cursiva, enlaces, tablas, imágenes |
| **Imágenes embebidas** | Las imágenes pegadas o insertadas se convierten a base64 en el navegador; nada toca `glpi_documents` |
| **Archivos adjuntos** | Arrastrar y soltar o selector de archivos; los bytes viven en RAM del navegador y viajan como base64 — nunca se almacenan en el servidor |
| **Validación MIME de GLPI** | Solo se aceptan los tipos de archivo permitidos por la configuración de tipos de documentos de GLPI |
| **Correo de prueba** | Envía a tu propia dirección de administrador o a cualquier dirección antes del envío masivo |
| **Modal de progreso AJAX** | Barra de progreso en tiempo real, contadores de enviados / errores / pendientes, tiempo transcurrido, lista de errores por dirección |
| **Envío por cola** | Destinatarios procesados en lotes de 15; el navegador nunca se congela |
| **Persistencia del formulario** | Asunto y pie guardados en `glpi_configs` y restaurados en la siguiente visita |
| **Compatible con temas** | El contenido del cuerpo sobrevive cambios de tema oscuro/claro vía `sessionStorage` |
| **Envíos múltiples seguros** | El token CSRF rota después de cada llamada AJAX — envía múltiples correos de prueba sin recargar la página |
| **i18n completo** | `es_MX`, `en_US`, `en_GB`, `fr_FR`, `de_DE` — todas las cadenas pasan por `__()` de GLPI |

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

1. Descarga `mailblast-1_0_0.zip` desde la [página de releases](https://github.com/monta990/mailblast/releases).
2. Extrae en el directorio de plugins de GLPI:
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
| **Cuerpo del mensaje** | Obligatorio. HTML completo — pega imágenes, inserta tablas, da formato al texto. |
| **Pie de página** | Opcional. Se agrega debajo del cuerpo, separado por una línea horizontal. Se persiste entre sesiones. |

### 2 — Archivos adjuntos

Arrastra archivos a la zona de soltar o haz clic en **seleccionar**. Los archivos seleccionados se muestran en una lista con tamaño y botón para eliminar. Solo se aceptan los tipos MIME permitidos por la configuración de documentos de GLPI — la lista completa es visible desplegando **Ver tipos de archivo permitidos**.

Los archivos se leen en memoria del navegador y se envían como base64 — nada se guarda en el sistema de archivos ni en la base de datos del servidor.

### 3 — Correo de prueba

Antes de enviar a todos, verifica el diseño y los adjuntos:

- **Enviar a mi dirección** — entrega al correo predeterminado del administrador actualmente conectado.
- **Enviar a una dirección específica** — ingresa cualquier dirección de correo válida.

El resultado se muestra en pantalla sin recargar la página. Puedes enviar múltiples correos de prueba en la misma sesión.

### 4 — Envío masivo

Una vez satisfecho, haz clic en **Enviar a todos los usuarios**. Un diálogo de confirmación muestra el número de destinatarios. El plugin envía únicamente a:

- `is_active = 1`
- `is_deleted = 0`
- Dirección no vacía con `is_default = 1` en `glpi_useremails`

Un modal de progreso muestra el estado en tiempo real. Los errores por dirección se listan conforme ocurren.

---

## Permisos

El acceso requiere el derecho de GLPI **`config: UPDATE`** (perfil de administrador completo). No se necesita configuración adicional de derechos.

---

## Cómo funcionan los adjuntos

1. JS lee cada archivo con `FileReader.readAsDataURL()` → cadena base64 en RAM del navegador.
2. Las cadenas base64 se envían en el cuerpo AJAX (campo JSON `attachments_b64`).
3. PHP decodifica cada cadena a un archivo temporal de la solicitud (`tempnam()`).
4. El archivo temporal se adjunta al objeto Symfony `Email` vía `attachFromPath()`.
5. El archivo temporal se elimina inmediatamente después de `$transport->send()`.

En ningún momento un archivo toca `glpi_documents`, `glpi_configs` ni ningún almacenamiento persistente.

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
