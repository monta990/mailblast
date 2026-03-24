# Changelog — Mail Blast

All notable changes to this plugin are documented here.
Dates follow **GMT-7 (Hermosillo)**.

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
- Locales: `es_MX`, `en_US`, `en_GB`, `fr_FR`, `de_DE` — 67 strings.

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
