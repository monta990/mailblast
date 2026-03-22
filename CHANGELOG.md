# Changelog — Mail Blast

All notable changes to this plugin are documented here.
Dates follow **GMT-7 (Hermosillo)**.

---

## [1.0.3] — 2026-03-22

### Fixed

- **Progress status messages always appeared in blue** — `mb_statusLine` was
  duplicated in the modal HTML. `getElementById` always returns the first
  match, which had a hardcoded `alert-info` class, so `setStatus('…', 'danger')`
  or `setStatus('…', 'warning')` still rendered blue. First duplicate removed;
  only the correct element (no hardcoded class) remains.
- **Cancel button skipped warning on second mass-send** — `_cancelStep` was
  declared inside the button listener closure but never reset when `startSend()`
  ran again. If the user clicked Cancel once (seeing the warning), then closed
  the modal and launched a second send, the first click on Cancel would cancel
  immediately without showing the warning. Fixed by resetting `_cancelStep = 0`
  in the state-reset block at the top of `startSend()`.
- **`GLPI_ROOT` bootstrap block referenced `inc/includes.php`** — that file
  does not exist in GLPI 11. The entire `if (!defined('GLPI_ROOT'))` block was
  dead code: GLPI 11 always bootstraps via Symfony and defines `GLPI_ROOT`
  before any plugin file runs. Replaced with a single unconditional
  `include_once GLPI_ROOT . '/inc/includes.php'`.
- **TinyMCE editor IDs used `mt_rand()`** — changed to `uniqid()`, which is the
  appropriate function for generating unique DOM identifiers in PHP.

### Removed

- **Dead `purgeDocument()` method** — was marked `@deprecated` in v1.0.1 and
  never called. Removed from `PluginMailblastMailblast`.
- **Dead `i18n.confirmSend` string** — was added when mass-send used a native
  `confirm()` dialog. The dialog was replaced with a Bootstrap modal in v1.0.2
  and the string was never used again. Removed from the `i18n` object and from
  all 5 locale files. POT drops from 64 to 63 strings.

### Changed

- **Mass-send IIFE modernised from ES5 to ES6** — top-level `var` declarations
  converted to `let` (reassigned variables) or `const` (bound-once references),
  consistent with the ES6 style used in the rest of the file.

---

## [1.0.2] — 2026-03-22

### Fixed

- **Native `alert()` dialogs replaced** — validation messages for empty
  subject/body and all internal error strings now appear as an inline Bootstrap
  alert inside the form. All error strings (`Bad server response`, `Server
  error`, `Could not start sending`, `Batch failed`) moved to the `i18n` object
  and translated in all 5 locales. POT: 60 → 64 strings.
- **Allowed file types list unreadable in dark theme** — `card card-body`
  wrapper replaced with `border rounded` div; `background-color` removed from
  `.mb-badge-type` CSS.
- **Page layout did not fill available width** — `container-xl` removed; page
  now uses `container-fluid` matching all native GLPI pages.
- **Mass send never executed** — `new FormData(form)` included the file input
  (populated via `DataTransfer`) causing the `queue_init` request to silently
  fail or exceed `post_max_size` in PHP. Both `queue_init` and `queue_process`
  now build `FormData` manually and pass attachments exclusively as
  `attachments_b64` JSON.
- **CSRF check failed on every batch** — the token consumed by `queue_init`
  was reused in `queue_process` calls, triggering GLPI's CSRF guard. All three
  AJAX actions now return a fresh `csrf` field; JS calls `updateCsrf()` before
  each next request.
- **`startSend()` missing closing brace** — button event listeners were
  defined inside `startSend()` instead of the outer IIFE and were never
  registered on page load.
- **Mass-send JS handler rewritten as clean IIFE** — shared state at IIFE
  scope, `doFetch()` wrapper surfaces raw server responses on JSON parse
  failure, `finish()` fully defensive against null DOM elements.
- **Progress modal showed no feedback** — `mb_statusLine` element was missing
  from the HTML; all status messages were silently dropped.
- **Cancel button showed "Cancelling" untranslated** — moved to `i18n` object.
- **Cancel button stayed in "Cancelling…" state permanently** — `confirm()`
  inside a Bootstrap modal is unreliable. Replaced with a two-click pattern;
  second click calls `finish()` immediately.
- **Confirmation dialog used native `confirm()`** — replaced with a Bootstrap
  modal showing recipient count and warning message.
- **Progress bar displayed no percentage** — now shows `X%` inside bar and
  `X / Y` above it.
- **Recipient count badge on Send All button was redundant** — removed.
- **Confirmation modal broken in dark themes** — replaced `card` background
  with `border rounded`, icon changed to `text-danger`.
- 6 new translatable strings. POT: 57 → 60 strings.

---

## [1.0.1] — 2026-03-22

### Fixed

- **SMTP connection created once per send cycle** — `Transport::fromDsn()` is
  now created once before the send loop in `sendMails()` and `processBatch()`.
- **`countActiveUsersWithEmail()` full table scan** — replaced with
  `SELECT COUNT(*)`.
- **Orphaned queue jobs** — `created_at` timestamp added; `cleanupStaleJobs()`
  removes jobs older than 2 hours on each new send.
- **Accidental HTML output corrupting AJAX JSON** — `ob_start()` /
  `ob_end_clean()` guards added to all three AJAX actions.
- **`queue_process` accepted empty HTML body** — now returns JSON error if blank.
- **Dead code in `validateUploadedFiles()`** — unreachable `else` branch removed.
- **`embedImagesAsBase64()` deleted GLPI documents** — documents are now only
  read and embedded, never deleted.
- **`sendId` used `mt_rand()`** — replaced with `bin2hex(random_bytes())`.
- **`usleep` redundant condition** — simplified to `!$testMode`.
- **Double `---` separator in README** — replaced with single `---`.

---

## [1.0.0] — 2026-03-21

### Added

- Initial release — GLPI 11.0+ only.
- Bulk HTML email to all active GLPI users with a registered default address.
- Configurable subject (required), HTML body (required) and HTML footer (optional).
- Rich-text editor (TinyMCE 6) for body and footer.
- Inline image embedding as base64 — nothing written to `glpi_documents`.
- Attachment support via drag-and-drop or file picker — base64 in browser RAM,
  never stored on the server.
- Only MIME types allowed by GLPI's document type configuration are accepted.
- Test-send to administrator's own address or any specific address.
- Real-time progress modal: progress bar, sent / errors / pending counters,
  elapsed time, per-address error list.
- Queue-based mass sending (batches of 15) via AJAX.
- 120 ms inter-send delay to avoid SMTP rate limiting.
- Subject and footer persisted in `glpi_configs`.
- Body survives dark/light theme switches via `sessionStorage`.
- CSRF token rotated after every AJAX call.
- Outbound mail via Symfony Mailer (`GLPIMailer::buildDsn()`).
- Fully localized: `es_MX`, `en_US`, `en_GB`, `fr_FR`, `de_DE`.
