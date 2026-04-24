# Phase 7_6d — Markdown & MathJax Fix + History Zero-AJAX Rendering

## Files Changed

| File | Change |
|---|---|
| `classes/local/render_helper.php` | **KEY FIX**: replaced `clean_text()`/HTMLPurifier with `strip_tags()` allowlist |
| `classes/external/get_history.php` | Added `renderedhtml` field — PHP pre-renders at fetch time |
| `amd/src/history.js` | Replaced `renderHistoryItem()` AJAX with `renderFromItem()` inline |
| `amd/src/widget.js` | Upgraded `typesetMath()` to full 3-state window.MathJax guard |
| `version.php` | Bumped to 2026042401 for AMD cache invalidation |

## Root Causes Fixed

### Fix 1 — Markdown & MathJax Not Rendering (`render_helper.php`)

`clean_text()` internally calls HTMLPurifier which **entity-encodes backslashes
inside text nodes**:
- `\(` → `&#92;(` — MathJax delimiter destroyed
- `\[` → `&#92;[` — MathJax delimiter destroyed

By the time HTML reached the browser, all MathJax delimiters were gone.

**Fix**: Replace `clean_text(FORMAT_HTML)` with `strip_tags()` using an
explicit allowlist of Parsedown-emitted tags. `strip_tags()` does NOT touch
text-node content — backslashes survive intact.

### Fix 2 — History Shows Typing Spinner Then "(Could not render response.)" (`history.js` + `get_history.php`)

`renderHistoryItem()` made a second AJAX call to `render_response` web service.
That service used `MUST_EXIST` with a `courseid` that mismatched, throwing an
exception. The typing indicator appeared before the AJAX call, then the
`.catch()` showed the error message.

**Fix**: `get_history.php` now calls `render_helper::render()` server-side for
each item and returns `renderedhtml`. `renderFromItem()` in `history.js` sets
`innerHTML = item.renderedhtml` directly — zero extra AJAX calls.

### Fix 3 — `typesetMath()` 3-State Guard (`widget.js`)

Previous implementation used a simple polling retry. Upgraded to handle all
three MathJax initialisation states:
- (a) Ready → call immediately
- (b) Startup promise pending → wait then call
- (c) Not yet injected → poll 200 ms × 25 = 5 s

### What to Delete

**Delete the entire `mathjax/` folder** from your plugin directory.
Moodle's built-in `filter_mathjaxloader` handles MathJax loading on every
course page. The plugin must never bundle or self-load MathJax.

## Deployment Steps

```bash
# 1. Copy patched files
cp -r phase7_6d/classes/local/render_helper.php   .../blocks/ai_assistant_v2/classes/local/
cp -r phase7_6d/classes/external/get_history.php  .../blocks/ai_assistant_v2/classes/external/
cp -r phase7_6d/amd/src/history.js                .../blocks/ai_assistant_v2/amd/src/
cp -r phase7_6d/amd/src/widget.js                 .../blocks/ai_assistant_v2/amd/src/
cp    phase7_6d/version.php                        .../blocks/ai_assistant_v2/

# 2. Delete the mathjax folder
rm -rf .../blocks/ai_assistant_v2/mathjax/

# 3. Rebuild AMD (minified JS)
cd /path/to/moodle
php admin/cli/build_js.php --plugin=block_ai_assistant_v2
# OR if grunt is available:
grunt amd --root=blocks/ai_assistant_v2

# 4. Purge Moodle caches
php admin/cli/purge_caches.php
```
