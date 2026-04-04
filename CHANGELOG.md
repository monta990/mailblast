# Changelog — Mail Blast

All notable changes to this project are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

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
