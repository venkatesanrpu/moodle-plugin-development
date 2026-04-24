# Phase 7_6b — Native MathJax + Parsedown Fix

## Summary

Resolves the **multiple MathJax loading** issue that caused math to render as raw
`\(...\)` / `$$...$$` text in the chat widget and history panel.

---

## Root Cause

`block_ai_assistant_v2.php` was doing two conflicting things on every course page:

1. **Overwriting `window.MathJax`** via `$PAGE->requires->js_amd_inline()` to inject
   a custom startup config (`typeset: false`).
2. **Loading a plugin-bundled copy of MathJax** (`/mathjax/tex-chtml.js`) as a plain
   `<script>` tag.

Moodle's native `filter_mathjaxloader` had already initialised `window.MathJax` with
its own config before the block code ran. The result was two competing MathJax
instances — each overwriting the other's internal state — leaving
`window.MathJax.typesetPromise` in a broken state so `typesetMath()` silently failed.

Additionally, the `render_helper.php` placeholder format (`AIMATH0PLACEHOLDER`) was
susceptible to accidental Parsedown output collisions, and the `\[...\]` / `\(...\)`
regexes had a PHP double-quoted string backslash-escaping bug that could break matches.

---

## Files Changed

### `block_ai_assistant_v2.php`
- **Removed** `$PAGE->requires->js_amd_inline($mathjaxconfig)` — no longer override
  `window.MathJax` from the plugin.
- **Removed** `$PAGE->requires->js(new moodle_url('/blocks/ai_assistant_v2/mathjax/...'))` —
  no longer load a bundled MathJax copy.
- Added inline comment explaining the passive-filter approach for future maintainers.
- `/blocks/ai_assistant_v2/mathjax/` directory can be deleted from the server.

### `amd/src/widget.js`
- `typesetMath(node)` now **polls** for `window.MathJax.typesetPromise` up to 10 times
  at 300 ms intervals (~3 s total) before giving up silently. This handles the async
  initialisation order of `filter_mathjaxloader`.

### `amd/src/history.js`
- `typesetMath(node)` updated with the same polling pattern as `widget.js`.
  Removed the old comment that referenced a "plain-script load" (now stale).

### `classes/local/render_helper.php`
- **Placeholder format** changed from `AIMATH0PLACEHOLDER` → `%%AIMATH_0%%`.
  The `%%` fences survive Parsedown unchanged and cannot collide with real content.
- **Regex patterns** for `\[...\]` and `\(...\)` switched from double-quoted to
  single-quoted strings, fixing a PHP backslash-eating bug that could silently break
  display-math extraction.
- **`$...$` pattern** tightened: requires at least one non-space character at both
  ends and disallows newlines inside, preventing false positives on paragraph text.
- **`str_replace()`** now uses array form (single pass) for placeholder restoration —
  more efficient and avoids nested substitution edge cases.

---

## Moodle Admin Action Required

**Enable the MathJax filter** (if not already on):

> Site Administration → Plugins → Filters → Manage filters
> Set **MathJax** to **On** or **On but off by default** (not **Disabled**).

The filter is what loads `window.MathJax` on course pages. Without it,
`typesetMath()` will poll and time out silently — math delimiters will be visible
but un-typeset. This was always a requirement; phase7_6b makes the dependency explicit.

---

## Safe to Delete

After deploying this patch the following directory is no longer needed:

```
/blocks/ai_assistant_v2/mathjax/
```

Remove it to reduce plugin size and avoid confusion.
