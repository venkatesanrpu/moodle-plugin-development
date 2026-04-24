# Phase 7_6h — Fix: `rootEl.querySelector is not a function`

## Root Cause

`block_ai_assistant_v2.php` calls:

```php
$PAGE->requires->js_call_amd('block_ai_assistant_v2/widget',  'init', [$context]);
$PAGE->requires->js_call_amd('block_ai_assistant_v2/history', 'init', [$context]);
```

Moodle serialises the PHP array `$context` into a **plain JavaScript object**:

```js
{ uniqid: "...", courseid: 3, sesskey: "...", streamurl: "...", agentkey: "...", mainsubjectkey: "..." }
```

### Previous bug (all phases up to 7_6g)

Both `widget.js` and `history.js` had `init()` signatures that expected either:
- an `HTMLElement` (so `.querySelector` could be called directly), or
- a DOM string selector

So when called as `init(ctxObject)`, the argument was a plain object, and calling
`.querySelector(...)` on it threw:

```
Uncaught TypeError: rootEl.querySelector is not a function
```

## Fix Applied

### `amd/src/widget.js`

```js
// Phase 7_6h fix:
const init = (ctx) => {
    if (!ctx || typeof ctx !== 'object') { return; }
    const uniqid = ctx.uniqid || '';
    courseId   = parseInt(ctx.courseid, 10) || 0;
    sesskey    = ctx.sesskey      || '';
    streamUrl  = ctx.streamurl    || '';
    agentKey   = ctx.agentkey     || '';
    mainSubject = ctx.mainsubjectkey || '';

    const rootWrapper = uniqid ? document.getElementById(uniqid) : null;
    if (!rootWrapper) { return; }

    rootEl = rootWrapper.querySelector(SEL.ROOT) || rootWrapper;
    // ... bind event listeners on rootEl ...
};
```

### `amd/src/history.js`

```js
// Phase 7_6h fix:
const init = (ctx) => {
    if (!ctx || typeof ctx !== 'object') { return; }
    courseId = parseInt(ctx.courseid, 10) || 0;
    const uniqid = ctx.uniqid || '';

    const rootWrapper = uniqid ? document.getElementById(uniqid) : null;
    if (!rootWrapper) { return; }
    rootEl = rootWrapper;
    // ... bind history panel listeners ...
};
```

### `amd/src/mcq.js`

Already correct — its `init(context)` uses `document.getElementById(context.uniqid)`.
**No change needed.**

## Files Changed

| File | Change |
|------|--------|
| `amd/src/widget.js`  | `init()` accepts context object, extracts `uniqid`/values, finds root via `getElementById` |
| `amd/src/history.js` | `init()` accepts context object, extracts `uniqid`/courseId, finds root via `getElementById` |
| `amd/src/mcq.js`     | No change (was already correct) |

## After Applying

Run Grunt to rebuild AMD bundles:

```bash
cd /var/www/html/moodle
grunt amd --plugin block_ai_assistant_v2
```

Then purge Moodle caches:

```
Site administration → Development → Purge all caches
```

Or via CLI:

```bash
php admin/cli/purge_caches.php
```
