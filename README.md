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
| **Rich-text body editor** | TinyMCE 7 (GLPI 11) — bold, italic, tables, images, lists, indentation, text alignment |
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
| **Cooldown protection** | 30-second cooldown after each send prevents accidental duplicate blasts from concurrent browser tabs |
| **Duplicate recipient guard** | Within-batch deduplication skips users sharing an email address so no recipient receives the same message twice |
| **Send history** | Last 10 mass sends stored and displayed on the configuration page (date, subject, sent count, failed count) with server-timezone timestamps |
| **Full i18n** | `es_MX`, `fr_FR`, `de_DE` |

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

The configuration page also shows a **send history** table with the last 10 mass sends (date, subject, sent count, failed count). Timestamps use the server's configured PHP timezone; the column header shows the timezone identifier for clarity.

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
