# Changelog — Mail Blast

All notable changes to this plugin are documented here.
Dates follow **GMT-7 (Hermosillo)**.

---

## [1.0.1] — 2026-03-22

### Fixed

- **SMTP connection created once per send cycle** — `Transport::fromDsn()` was
  instantiated on every individual email, causing a new TLS handshake per
  recipient. It is now created once before the send loop and reused for all
  recipients in `sendMails()` and `processBatch()`.
- **`countActiveUsersWithEmail()` used a full table scan** — replaced with a
  direct `SELECT COUNT(*)` query so no rows are loaded into memory just to count.
- **Orphaned queue jobs** — if the browser was closed mid-send, the
  `queue_<id>` entry remained in `glpi_configs` indefinitely. Each new queue
  now includes a `created_at` timestamp, and `cleanupStaleJobs()` removes any
  job older than 2 hours when a new send is initiated.
- **Accidental HTML output could corrupt AJAX JSON** — `ob_start()` / `ob_end_clean()`
  guards added to all three AJAX actions (`test_send`, `queue_init`, `queue_process`).
- **`queue_process` accepted an empty HTML body** — now returns a JSON error if
  `html` is blank, preventing silent empty-body mass sends.
- **Dead code in `validateUploadedFiles()`** — the `else` branch handling a
  non-array `$_FILES` entry was unreachable; removed.
- **`embedImagesAsBase64()` deleted GLPI documents** — after embedding an image
  as base64, the function hard-deleted the source document from `glpi_documents`
  and disk. Documents are no longer deleted; they are only read and embedded.
- **`sendId` used `mt_rand()`** — replaced with `bin2hex(random_bytes())` for a
  cryptographically secure job identifier.
- **`usleep` condition in `sendMails()`** — simplified from
  `!$testMode && count($recipients) > 1` to `!$testMode`; the count check was
  redundant since test mode always has exactly one recipient.
- **Double horizontal rule between EN/ES sections in README** — replaced with a
  single `---`.

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
