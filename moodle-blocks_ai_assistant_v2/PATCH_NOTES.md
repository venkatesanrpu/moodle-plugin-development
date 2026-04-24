# Phase 7_6f Patch Notes

## What is fixed

### Problem 1 — MathJax not rendering
`render_helper.php` was calling `clean_text()` / HTMLPurifier which
entity-encodes backslashes inside text nodes:
  `\(` → `&#92;(`   →  MathJax finds nothing to typeset

**Fix:** Replace `clean_text()` with `strip_tags()` using an allowlist of
safe tags. `strip_tags()` does NOT touch text node content, so `\(`, `\[`,
`$$` delimiters survive intact.

Math tokens are also extracted into `%%AIMATH_N%%` placeholders *before*
Parsedown runs, then restored verbatim after, preventing Parsedown from
mangling LaTeX fences.

### Problem 2 — History click shows typing spinner then fails
`renderHistoryItem()` fired a second AJAX call to
`block_ai_assistant_v2_render_response` with a `courseid` that could
mismatch, causing `MUST_EXIST` to throw and leaving the typing indicator
on screen.

**Fix:** The `get_history` webservice now returns a `renderedhtml` field
(server-rendered at fetch time). `history.js` uses `renderFromItem()` which
simply sets `innerHTML = item.renderedhtml` — zero extra AJAX, no race.

### Problem 3 — MathJax 3.x typesetting race condition
Moodle's `filter_mathjaxloader` loads MathJax 3.2.2 asynchronously via
requirejs. Our AMD modules may call `MathJax.typeset()` before `window.MathJax`
exists.

**Fix:** New `mathjax_helper.js` AMD module with three-tier fallback:
  1. If `window.MathJax.typesetPromise` exists → call immediately
  2. If `window.MathJax.startup.promise` exists → wait, then typeset
  3. Otherwise → poll every 300 ms (up to 6 s) until MathJax appears

`typesetClear([node])` is called before each typeset so re-injected
content (history re-clicks) is re-processed correctly.

---

## Files changed

| File | Change |
|------|--------|
| `classes/local/render_helper.php` | Replace `clean_text()` with `strip_tags()` allowlist; math placeholder extraction |
| `classes/external/get_history.php` | Add `renderedhtml` field; call `render_helper::render()` per item |
| `amd/src/mathjax_helper.js` | NEW — centralised MathJax 3 typesetting with startup/poll fallback |
| `amd/src/widget.js` | Import `mathjax_helper`; use `typesetMath(answerNode)` after render |
| `amd/src/history.js` | Remove `renderHistoryItem()` AJAX; use `renderFromItem()` + `typesetMath` |

---

## Deployment steps

1. Copy files to Moodle server maintaining the directory structure.
2. Purge Moodle caches:
   ```
   php admin/cli/purge_caches.php
   ```
3. Rebuild AMD bundle (if using grunt):
   ```
   grunt amd --root=blocks/ai_assistant_v2
   ```
   Or use Moodle's built-in JS minification (Site admin → Development →
   Purge all caches, then enable "Cache JS" if disabled).
4. Verify in browser console:
   ```javascript
   typeof window.MathJax.typesetPromise  // → "function"
   window.MathJax.version                // → "3.2.2"
   ```
