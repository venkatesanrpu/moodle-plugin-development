# Phase 7_6j — Complete Bug Fix Summary

## All 4 Broken Features — Root Causes & Fixes

---

### Bug 1 — Custom Query: "(Connection error. Please try again.)"

**Root Cause:**  
`widget.js` (Phase 7_6i) removed the mandatory 2-step stream flow.  
`stream.php` **requires** `historyid` + `token` — it cannot work without them.  
- Step 1 calls `ask_agent` AJAX → creates history row → returns `{historyid, streamtoken}`  
- Step 2 opens `EventSource` to `stream.php?historyid=X&token=Y`

Phase 7_6i skipped Step 1 entirely, sending only `prompt+sesskey` to `stream.php`.  
`stream.php` returns **403** immediately (missing params) → `onerror` fires → error message.

**Fix in `amd/src/widget.js`:**  
Restored 2-step flow inside `startStream()`:
- Ajax `ask_agent` first → on success → open `EventSource` with `historyid + token`

---

### Bug 2 — History: "Failed to load history."

**Root Cause — Fatal PHP class resolution error:**  
`get_history.php` is inside namespace `block_ai_assistant_v2\external`.  
It used bare `use external_api;` (pre-Moodle 4 style).  
PHP resolves this to `block_ai_assistant_v2\external\external_api` — **class not found** → fatal.  
Also, `core_text::strlen()` was called without `use \core_text` → second fatal.

**Fix in `classes/external/get_history.php`:**
```php
// BEFORE (broken):
use external_api;
use external_value;
use external_function_parameters;

// AFTER (fixed):
use core_external\external_api;
use core_external\external_value;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
```
And all `core_text::` calls changed to `\core_text::` (fully qualified).

---

### Bug 3 — Guided Search: Syllabus Not Loading

**Root Cause — Two mismatches:**

**3a** — Wrong parameters sent to `get_syllabus`:  
`widget.js` sent `{courseid, agentkey, mainsubjectkey}` but  
`get_syllabus.php` `execute_parameters()` expects `{courseid, blockinstanceid}`.  
Result: AJAX parameter validation fails → syllabus never loads.

**3b** — Wrong result structure assumed:  
`widget.js` treated result as `result.subjects[]` but  
`get_syllabus.php` returns `{ syllabusjson: "..." }` — a raw JSON string.  
Nothing was ever parsed → subject dropdown was always empty.

**Fix in `amd/src/widget.js`:**
```javascript
// BEFORE (broken):
args: { courseid, agentkey, mainsubjectkey }
// then: result.subjects.forEach(...)

// AFTER (fixed):
args: { courseid, blockinstanceid }   // blockinstanceid from ctx
// then:
const syllabus = JSON.parse(result.syllabusjson || '{}');
const subjects = syllabus.subjects || [];
subjects.forEach(s => { ... });
```

---

### Bug 4 — MCQ: Dropdowns Vanished + Panel Flickers

**Root Cause — Double event binding:**  
`widget.js init()` binds `[data-action="toggle-mcq"]` → `togglePanel('mcq-panel')`  
`mcq.js bind()` **also** binds `[data-action="toggle-mcq"]` → `panel.hidden = !panel.hidden`

Both listeners fire on one click → panel opens → then immediately closes.  
Users see no panel, no dropdowns.

Additionally, MCQ dropdowns were never built at init time in the Phase 7_6i version —  
the dynamic control-building code was removed.

**Fix in `amd/src/mcq.js`:**
- Removed ALL `toggle-mcq` button binding (widget.js owns all panel toggles)
- Added `MutationObserver` on `[data-region="mcq-panel"]` — builds controls lazily on first show
- Controls (count, difficulty, generate button) are built by `buildControls()` in mcq.js itself

---

## Files Changed

| File | Change |
|---|---|
| `classes/external/get_history.php` | Fix `use` statements to `core_external\*`; fix `\core_text::` |
| `amd/src/widget.js` | Restore 2-step stream; fix `get_syllabus` args + response parsing; remove MCQ toggle binding |
| `amd/src/mcq.js` | Remove duplicate toggle binding; add lazy `buildControls()` via MutationObserver |

## No Changes Needed
- `stream.php` — correct as-is
- `ask_agent.php` — correct as-is  
- `render_helper.php` — fix from Phase 7_6d still applies
- `get_syllabus.php` — correct as-is (expects `blockinstanceid`)
