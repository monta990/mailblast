# Changelog — Mail Blast

All notable changes to this project are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [1.6.1] — 2026-04-22

### Added

- **Cooldown protection** — `initQueue` checks `last_send_at` in `glpi_configs`; if a send completed less than 30 seconds ago, returns a localized error with seconds remaining. Prevents accidental duplicate sends from concurrent browser tabs.
- **Body size guard** — `queue_init` rejects bodies larger than the configured `max_attachment_mb` limit before starting a send. Prevents memory exhaustion on large base64-image bodies re-posted every batch call.
- **Non-JS fallback message** — submitting the mass-send form without JavaScript now shows a localized error instead of silently doing nothing.
- **`validateUploadedFiles()` method** — non-AJAX form attachment path was calling this missing method (fatal on JS-disabled submit). Method added with `finfo`-based MIME verification and size enforcement.

### Fixed

- **Stale job cleanup order** — `cleanupStaleJobs()` now runs *before* saving the new job entry, preventing clock-skew edge case where the new job could be immediately deleted.
- **Hardcoded GMT-7 timestamps** — `addHistory()` and `generate_report` used `gmdate(..., time() - 7*3600)`. Replaced with `date('Y-m-d H:i')` which respects the PHP/server timezone.
- **`docIdToBase64` path traversal** — added `realpath` + `str_starts_with(GLPI_DOC_DIR)` guard, matching the protection already present in `purgeDocument`.
- **`sendId` regex too permissive** — `/^[0-9a-f-]{8,40}$/` accepted all-dash strings. Tightened to exact UUID-like pattern `8-4-4-4-12`.
- **`processBatch` early return shape** — missing `sent_list` key in the empty-job guard return. JS accessed `data.sent_list` unconditionally.
- **Footer XSS** — `$_POST['footer']` saved and echoed raw. Now stripped to `b/i/u/strong/em/br` tags on save (with attribute strip) and on output.
- **Test/queue-init attachment MIME** — temp files created from base64 uploads now verify MIME via `finfo::file()` instead of trusting browser-supplied `file.type`, matching `processBatch` behaviour.
- **Duplicate email recipients** — within-batch deduplication via `$seenEmails` set prevents users sharing an email address from receiving the same message twice per batch.
- **`getActiveUsersWithEmail()` dead code** — method was never called (all sends use LIMIT/OFFSET in `processBatch`). Removed.
- **Hardcoded `'Test'` recipient name** — test sends now use the actual email address as the To: display name.
- **`html2text` table cells** — `<td>`/`<th>` now produce tab separators; previously table cell content merged into a single line in plain-text fallback.
- **`alert()` in attachment size error** — replaced last native `alert()` call with the inline `mb_formAlert` div for consistent UX.
- **History date column timezone** — shows the PHP timezone identifier (e.g. `America/Mexico_City`) next to the Date column header so timestamps are unambiguous.

### Changed

- **Send history limit raised from 5 to 10** — `addHistory()` now keeps the last 10 mass sends instead of 5. The header badge in the configuration page updated accordingly ("Last 10 mass sends").

---

## [1.6.0] — 2026-04-05

### Added

- **Sending report — XLSX** — a **Download report** button (green, Excel icon)
appears in the progress modal after every send. Clicking it POSTs the accumulated
send data to a `generate_report` PHP action that builds a proper XLSX file using
GLPI's bundled `phpoffice/phpspreadsheet ^5.1`.
The workbook includes: Date, Subject, Email, Status (Sent / Failed), Reason. Header row is bold with a blue background;
even rows have a light-blue zebra fill; all columns are auto-sized.
The file is returned as base64 JSON, decoded in the browser, and downloaded directly.
Works for full sends, partial sends, and cancellations.
- **Clear form button** — erases subject, body (TinyMCE) and footer without reloading the page. Also clears sessionStorage and the post-send summary banner.
- **Post-send summary banner** — after closing the progress modal, an inline alert shows the final count of sent and failed emails. Dismissable.
- **Status icon after send** — the finish function now sets a contextual status: green checkmark when all sent, yellow warning when partial failures, red when total failure, grey when cancelled.
- **Send history** — the last 5 sends are stored in `glpi_configs` and displayed in a table at the bottom of the compose page (date, subject, sent, failed). Populated automatically after each mass send.
- **Placeholder variables** — body, footer and subject support `{nombre}`, `{nombre_completo}` and `{email}`. Each recipient receives a personalised copy. Available variables are shown as a hint below the subject field.
- **Active recipients badge** in configuration page header showing the current count.

### Fixed

- `processBatch` query now includes `ORDER BY u.id ASC` — without a deterministic order, page-based LIMIT/OFFSET could skip or duplicate recipients if users changed status mid-send.
- Transport `stop()` called after each batch to close the SMTP connection and avoid leaking open connections on servers with concurrent session limits.

### Changed

- Report format changed from CSV (1.5.2) to **XLSX** using PhpSpreadsheet —
  no extra dependency needed, GLPI 11 already ships the library.
- Locales: `es_MX`, `en_US`, `en_GB`, `fr_FR`, `de_DE` — 95 strings.

---

## [1.5.2] — 2026-04-04

### Added

- **Sending report CSV** — a "Download report" button appears in the progress
  modal after every send (including partial sends and cancellations). The CSV
  contains one row per recipient with date, subject, email, status (Sent /
  Failed) and failure reason. UTF-8 BOM included for correct Excel rendering.
- **Image size limit in TinyMCE** — the `images_upload_handler` is intercepted
  before init. When a user inserts an image, the plugin checks whether the image
  size plus current attachments plus already-embedded images would exceed the
  configured `max_attachment_mb` limit. If so, the image is rejected with an
  inline error message in the editor and is never uploaded to `glpi_documents`.
  Embedded image bytes are tracked in `window._mbEmbeddedBytes` and reset on
  each new send cycle.

---

## [1.5.1] — 2026-04-04

### Fixed

- **Configuration page save failed with `AccessDeniedHttpException`** — GLPI 11
  validates CSRF automatically on every POST when `csrf_compliant = true` is set
  in `setup.php`. Calling `Session::checkCSRF($_POST)` manually was a double
  validation; the token was already consumed by GLPI's middleware before the
  plugin code ran, causing every save to throw an access denied error.
  Removed the manual call — GLPI's automatic validation is sufficient.

---

## [1.5.0] — 2026-03-23

### Added

- **Configuration page** — accessible via the gear icon in Setup → Plugins and
  via the settings button in the plugin header. Allows administrators to configure:
  - **Batch size** (1–100, default 15) — number of emails per sending batch.
  - **Delay between batches** (0–5000 ms, default 120 ms) — throttle for
    SMTP servers with rate limits.
  - **Maximum attachment size** (1–100 MB, default 15 MB) — browser-side limit
    on combined attachment size. Files exceeding the limit are rejected before
    upload, preventing SMTP timeouts on large sends.
- **Gear icon shortcut** in the send page card header linking to the config page.

### Fixed

- `countActiveUsersWithEmail()` performed a full table scan and loaded all
  rows into memory just to count. Replaced with a `SELECT COUNT(*)` query.
- `processBatch` `done` flag relied on the `total` stored at queue init time.
  If users were activated or deactivated mid-send, the flag could be wrong.
  Now uses the actual row count returned by the query as the authoritative signal.
- `embedImagesAsBase64` regex only matched GLPI 9/10 document URLs
  (`document.send.php?docid=X`). Broadened to match any `img src` containing
  `docid=\d+`, covering GLPI 11 URL formats.
- `html2text` produced poor plain-text for emails with tables, lists, and HTML
  entities — `&nbsp;` was left literal. Rewritten with proper block-element
  mapping (`<li>` → bullet, `<hr>` → `---`, etc.) and `html_entity_decode`.
- `buildHtmlBody` returned a bare HTML fragment. Wrapped in a minimal valid
  HTML5 document with `<meta charset="utf-8">` so email clients reliably
  interpret character encoding.
- Dead non-test branch removed from `sendMails()` — `getActiveUsersWithEmail()`
  was never called since mass sends always use `processBatch`.

### Changed

- Batch delay moved from hardcoded JS `setTimeout(120)` to the configurable
  `batch_delay_ms` value read from `glpi_configs`.
- Batch size moved from hardcoded `15` to the configurable `batch_size` value.
- License updated to **GPL v3+** across all files to match GLPI.
- Locales: `es_MX`, `en_US`, `en_GB`, `fr_FR`, `de_DE` — 84 strings.

---

## [1.4.0] — 2026-03-23

### Fixed

- **Rich-text editor lost formatting (indentation, lists, alignment) on every
  external click** — GLPI's `initEditorSystem` registers a `$(document).on('click')`
  handler that calls `.trigger('click')` on all active toolbar buttons
  (`.tox-tbtn--enabled`) when clicking outside the editor. Because active buttons
  represent current formatting state (lists, indentation, bold…), triggering them
  removes the format from the selected content. Fixed by wrapping that handler
  post-init via `$._data(document, 'events').click` so it runs without altering
  the button state.
- **Footer editor replaced** — TinyMCE footer editor conflicted with the body
  editor's event cycle, causing formatting resets on every focus change. The footer
  now uses a native `contenteditable` div with a **N / C / S** toolbar
  (Negrita / Cursiva / Subrayado). Line breaks are preserved natively; a hidden
  `<textarea>` syncs the HTML for form submission.

### Added

- **Text alignment buttons** — `alignleft`, `aligncenter`, `alignright`, and
  `alignjustify` added to the body editor toolbar. Rendered correctly in all
  major email clients.
- **Images deleted from `glpi_documents` after send** — images inserted via
  TinyMCE are uploaded to `glpi_documents` during composition. After
  `embedImagesAsBase64()` converts them to inline base64 in the email body, the
  document record and file are immediately deleted. No orphaned files accumulate.
- Locales: `es_MX`, `en_US`, `en_GB`, `fr_FR`, `de_DE` — 69 strings.

---

## [1.0.3] — 2026-03-22 - DELETED

### Fixed

- Duplicate `mb_statusLine` in progress modal HTML always rendered blue.
- `_cancelStep` not reset between sends; cancel button skipped warning on second run.
- Dead GLPI_ROOT bootstrap block referencing non-existent `inc/includes.php`.
- TinyMCE editor IDs changed from `mt_rand()` to `uniqid()`.

### Removed

- Dead `purgeDocument()` method.
- Dead `i18n.confirmSend` string from JS and all locale files.

### Changed

- Mass-send IIFE `var` declarations converted to `let` / `const`.

---

## [1.0.2] — 2026-03-22 - DELETED

### Fixed

- Native `alert()` replaced with inline Bootstrap alert.
- Allowed file types list unreadable in dark theme.
- Layout constrained to `container-xl`; changed to `container-fluid`.
- Mass send never executed — `new FormData(form)` included file input.
- CSRF check failed on every batch — token not rotated between requests.
- `startSend()` missing closing brace — button listeners never registered.
- Mass-send JS rewritten as clean IIFE with proper error surfacing.
- `mb_statusLine` element missing from modal HTML.
- Cancel button "Cancelling" string untranslated.
- Confirmation dialog used native `confirm()`; replaced with Bootstrap modal.
- Progress bar showed no percentage text.
- Recipient count badge on Send All button redundant.
- Confirmation modal broken in dark themes.

---

## [1.0.1] — 2026-03-22 - DELETED

### Fixed

- `Transport::fromDsn()` instantiated per email; moved before send loop.
- `countActiveUsersWithEmail()` full table scan; replaced with `COUNT(*)`.
- Orphaned queue jobs; `cleanupStaleJobs()` added.
- `ob_start()` / `ob_end_clean()` guards on all AJAX actions.
- `queue_process` accepted empty HTML body.
- Dead `else` branch in `validateUploadedFiles()`.
- `embedImagesAsBase64()` deleted GLPI documents; now read-only (restored in 1.4.0).
- `sendId` used `mt_rand()`; replaced with `bin2hex(random_bytes())`.

---

## [1.0.0] — 2026-03-21 - DELETED

### Added

- Initial release — GLPI 11.0+ only.
