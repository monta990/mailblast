# Changelog — Mail Blast

All notable changes to this plugin are documented here.
Dates follow **GMT-7 (Hermosillo)**.

---

## [1.0.0] — 2026-03-21

### Added

- Initial release — GLPI 11.0+ only.
- Bulk HTML email to all active GLPI users with a registered default address.
- Configurable subject (required), HTML body (required) and HTML footer (optional).
- Rich-text editor (TinyMCE 6) for body and footer: bold, italic, links, tables, images.
- Inline image embedding — pasted or inserted images are converted to base64 data-URIs
  in the browser; nothing is written to `glpi_documents` or the filesystem.
- Attachment support — files are selected via drag-and-drop or file picker, read into
  browser memory as base64, and attached to every outgoing email; no file is stored on
  the server at any point.
- Only MIME types allowed by GLPI's document type configuration are accepted.
- Test-send feature: send the exact composed message to the administrator's own address
  or any specific address before the mass mailing.
- Real-time progress modal during mass send: batch progress bar, sent / errors / pending
  counters, elapsed time, and per-address error list.
- Queue-based mass sending (batches of 15) via AJAX — the browser never blocks.
- 120 ms inter-send delay to avoid SMTP rate limiting.
- Subject and footer are persisted in `glpi_configs` and restored on next visit.
- Body survives dark/light theme switches via `sessionStorage`.
- CSRF token is rotated after every AJAX call — multiple test sends in the same session
  work without page reload.
- Outbound mail uses Symfony Mailer (via `GLPIMailer::buildDsn()`) — compatible with
  all SMTP modes configured in GLPI: plain, TLS, SSL, OAuth2.
- Fully localized: `es_MX`, `en_US`, `en_GB`, `fr_FR`, `de_DE`.
- All user-visible strings pass through GLPI's `__()` translation function — no
  hardcoded English text anywhere.
