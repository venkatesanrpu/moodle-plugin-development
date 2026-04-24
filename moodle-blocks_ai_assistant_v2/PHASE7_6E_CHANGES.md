# Phase 7_6e — Fix Main Widget Markdown/MathJax Rendering

## Problem

After phase7_6d:
- History panel: Markdown rendering ✅ (get_history.php → render_helper::render())
- Main widget:   Markdown rendering ❌ (render_response.php → OLD render logic)

The main widget calls `block_ai_assistant_v2_render_response` web service via
`renderNode()` in widget.js. That service was NOT patched in 7_6d.

Two bugs in the old render_response.php:
1. Used `$DB->get_record(..., MUST_EXIST)` with `TABLE` const —
   `history_repository` has NO `TABLE` const; it uses `table_name()` which
   resolves dynamically between v2 and legacy table names.
2. Indirectly called `clean_text(FORMAT_HTML)` via render_helper (old version),
   which encoded `\(` → `&#92;(`, destroying all MathJax delimiters.

## Fix (this patch)

### `classes/external/render_response.php` (NEW — replaces existing file)
- Uses `history_repository::get_record()` — correct dynamic table resolution.
- Uses `IGNORE_MISSING` — never throws exception on ID mismatch.
- Calls `render_helper::render()` which uses `strip_tags()` (phase7_6d version).
- Backslashes in `\(` `\[` survive intact in returned HTML.

### `version.php`
- Bumped to `2026042407` to force AMD cache invalidation.

## Deployment

```bash
# 1. Copy patched files
cp phase7_6e/classes/external/render_response.php \
   /path/to/moodle/blocks/ai_assistant_v2/classes/external/

cp phase7_6e/version.php \
   /path/to/moodle/blocks/ai_assistant_v2/

# 2. Purge caches (no AMD rebuild needed — no JS changed)
php admin/cli/purge_caches.php
```

## MathJax Prerequisite

Ensure **MathJax filter is ON** in Moodle:
Site Admin → Plugins → Filters → Manage filters → MathJax → ON
Without this, window.MathJax is never injected and typesetMath() is a no-op.

## Full Render Pipeline (after 7_6d + 7_6e)

| Trigger | Path | Status |
|---|---|---|
| Live chat ends | widget.js → render_response.php → render_helper::render() | ✅ Fixed |
| History click | history.js → item.renderedhtml (pre-rendered by get_history.php) | ✅ Fixed |
