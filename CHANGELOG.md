# Changelog — Mail Blast

All notable changes to this plugin are documented here.
Dates follow **GMT-7 (Hermosillo)**.

---

## [1.0.2] — 2026-03-22

### Fixed

- **Native `alert()` dialogs replaced** — validation messages for empty subject/body and all internal error strings now appear as an inline Bootstrap alert inside the form instead of browser native `alert()`. The native dialog always shows an untranslatable "OK" button and bypasses GLPI's theme. All error strings (`Bad server response`, `Server error`, `Could not start sending`, `Batch failed`) moved to the `i18n` object and translated in all 5 locales. POT now contains 64 strings.
- **Allowed file types list unreadable in dark theme** — `card card-body` wrapper replaced with `border rounded` div; `background-color` removed from `.mb-badge-type` CSS. Both fixes follow the same pattern applied to the confirmation modal.
- **Page layout did not fill available width** — `container-xl` class removed; page now uses `container-fluid` matching all native GLPI pages.

- **Mass send never executed** — `new FormData(form)` included the file input
  (populated via `DataTransfer`) causing the `queue_init` request to silently
  fail or exceed `post_max_size` in PHP. Both `queue_init` and `queue_process`
  now build `FormData` manually and pass attachments exclusively as
  `attachments_b64` JSON — identical to the test-send flow.
- **CSRF check failed on every batch** — the token consumed by `queue_init`
  was reused in subsequent `queue_process` calls, triggering GLPI's CSRF
  guard. All three AJAX actions now return a fresh `csrf` field in their JSON
  response; JS calls `updateCsrf()` before each next request.
- **`startSend()` missing closing brace** — button event listeners for
  `mb_sendAll`, `mb_confirmSend`, and `mb_cancelSend` were defined inside
  `startSend()` instead of the outer IIFE, so they were never registered on
  page load and the Send All button did nothing.
- **Mass-send JS handler rewritten as clean IIFE** — previous version
  accumulated corrupt state between runs, re-added cancel listeners on every
  call, and silently swallowed all errors. Rewritten with shared state at IIFE
  scope, a single `doFetch()` wrapper that surfaces raw server responses on
  JSON parse failure, and a `finish()` that is fully defensive against null
  DOM elements.
- **Progress modal showed no feedback** — `mb_statusLine` element referenced
  by `setStatus()` did not exist in the HTML, so all status messages (errors,
  "sending…", "no users found") were silently dropped. Element added to modal.
- **Cancel button showed "Cancelling" untranslated** — string was injected
  with a raw `json_encode(__(...))` call whose UTF-8 ellipsis `…` did not match
  the msgid in the `.po` file. Moved to the `i18n` object (`i18n.cancelling`)
  so it resolves through the same translation path as all other strings.
- **Cancel button stayed in "Cancelling…" state permanently** — `confirm()`
  called inside a Bootstrap modal is unreliable across browsers. Replaced with
  a two-click pattern: first click changes the button text as a warning; second
  click sets `_cancelled = true` and immediately calls `finish()` without
  waiting for any in-flight fetch.
- **Confirmation dialog used native `confirm()`** — replaced with a Bootstrap
  modal showing the exact recipient count and a clear warning message.
- **Progress bar displayed no percentage** — bar filled visually but showed no
  readable label. Now shows `X%` inside the bar and `X / Y` above it.
- **Recipient count badge on Send All button was redundant** — the count is
  already shown in the line directly above the button; badge removed.
- **Confirmation modal looked broken in dark themes** — the recipient box used
  `background: var(--tblr-bg-surface-secondary)` which rendered as a muddy
  grey in dark mode, and the users icon used `text-primary` (amber in dark
  themes). Replaced with `border` + `rounded` classes and `text-danger` icon,
  consistent with the modal header.

### Added

- 6 new translatable strings across all 5 locales (`es_MX`, `en_US`, `en_GB`,
  `fr_FR`, `de_DE`): *"You are about to send an email to"*, *"This action
  cannot be undone. Each recipient will receive one email."*, *"Cancel"*,
  *"Yes, send now"*, *"Cancel sending? Emails already sent will not be
  recalled."*, *"Cancelling…"*, *"Sending cancelled."*. POT now contains
  60 strings.

---

## [1.0.1] — 2026-03-22

### Fixed

- **Native `alert()` dialogs replaced** — validation messages for empty subject/body and all internal error strings now appear as an inline Bootstrap alert inside the form instead of browser native `alert()`. The native dialog always shows an untranslatable "OK" button and bypasses GLPI's theme. All error strings (`Bad server response`, `Server error`, `Could not start sending`, `Batch failed`) moved to the `i18n` object and translated in all 5 locales. POT now contains 64 strings.
- **Allowed file types list unreadable in dark theme** — `card card-body` wrapper replaced with `border rounded` div; `background-color` removed from `.mb-badge-type` CSS. Both fixes follow the same pattern applied to the confirmation modal.
- **Page layout did not fill available width** — `container-xl` class removed; page now uses `container-fluid` matching all native GLPI pages.

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
- **Accidental HTML output could corrupt AJAX JSON** — `ob_start()` /
  `ob_end_clean()` guards added to all three AJAX actions (`test_send`,
  `queue_init`, `queue_process`).
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
- CSRF token is rotated after every AJAX call — multiple test emails in the same session
  work without page reload.
- Outbound mail uses Symfony Mailer (via `GLPIMailer::buildDsn()`) — compatible with
  all SMTP modes configured in GLPI: plain, TLS, SSL, OAuth2.
- Fully localized: `es_MX`, `en_US`, `en_GB`, `fr_FR`, `de_DE`.
- All user-visible strings pass through GLPI's `__()` translation function — no
  hardcoded English text anywhere.
