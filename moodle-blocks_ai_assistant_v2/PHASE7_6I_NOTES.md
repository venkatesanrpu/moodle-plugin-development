# Phase 7_6i — Comprehensive Fix: Full Cross-Analysis

## What Was Done Differently This Time

Before writing any code, ALL files were fetched and cross-analysed together:
- `main.mustache` — exact DOM attributes, class names, data-region, data-action
- `widget.js` — every SEL constant checked against mustache 1-to-1
- `history.js` — all selectors verified
- `mcq.js` — all selectors verified (no changes needed)
- `mathjax_helper.js` — no changes needed

---

## Full Bug Map (widget.js — Phase 7_6h left these broken)

| # | Location | Bug | Root Cause | Fix |
|---|----------|-----|-----------|-----|
| 1 | `SEL.SEND_BTN` | `'[data-action="send"]'` → **null** | Mustache uses `data-action="send-message"` | Changed to `'[data-action="send-message"]'` |
| 2 | `SEL.INPUT` | `'.blockaiassistantv2-input'` → **null** | Mustache uses `<textarea data-region="prompt-input">` | Changed to `'[data-region="prompt-input"]'` |
| 3 | `SEL.CHAT_BODY` | `'.blockaiassistantv2-body'` → **null** | Class in mustache is `block_ai_assistant_v2-body` (underscore not camel) | Changed to `'[data-region="chat-body"]'` |
| 4 | `SEL.TYPING_IMG` | `'.blockaiassistantv2-typing'` → **null** | No such element exists in mustache | Removed; typing state uses `[data-region="status"]` text instead |
| 5 | `SEL.MSG_WRAPPER` | `'.blockaiassistantv2-messages'` → **null** | No wrapper element in mustache; messages go directly into chat-body | Removed; `appendMessage()` now targets `[data-region="chat-body"]` directly |
| 6 | `appendMessage()` | Messages never appeared in DOM | Fell through both null `MSG_WRAPPER` and wrong `CHAT_BODY` | Now uses correct `[data-region="chat-body"]` |
| 7 | `setTyping()` | Silently did nothing | Referenced `.blockaiassistantv2-typing` which doesn't exist | Replaced with `setStatus(text)` using `[data-region="status"]` |
| 8 | `rootEl` naming | `rootEl` was overloaded as both wrapper and shell | Caused ambiguity on launcher/close binding | Split into `rootWrapper` (#uniqid) and `shellEl` ([data-region="chat-shell"]) |

## history.js Changes

- All selectors were already correct against mustache — **no selector changes**.
- `init()` context-object fix from Phase 7_6h retained.
- `rootEl` renamed to `rootWrapper` for clarity.
- `renderList(items, ctx)` → `renderList(items)` — `ctx` was unused (grunt was right).

## mcq.js — No Changes Needed

Already uses `document.getElementById(context.uniqid)` and all selectors match mustache.

## mathjax_helper.js — No Changes Needed

---

## Deploy Steps

```bash
# 1. Copy files
cp widget.js  /path/to/moodle/blocks/ai_assistant_v2/amd/src/
cp history.js /path/to/moodle/blocks/ai_assistant_v2/amd/src/

# 2. Rebuild AMD bundles
cd /var/www/html/moodle
grunt amd --plugin block_ai_assistant_v2

# 3. Purge Moodle caches
php admin/cli/purge_caches.php

# 4. Hard reload browser (Ctrl+Shift+R)
```

---

## How to Verify Fix Is Working

Open browser console and check:
1. No `querySelector is not a function` error
2. `[data-action="send-message"]` button has click listener attached
3. `<textarea data-region="prompt-input">` is found
4. Typing a question and pressing Send → message appears in `[data-region="chat-body"]`
5. Status bar shows "Thinking…" → "Streaming…" → "Ready"
