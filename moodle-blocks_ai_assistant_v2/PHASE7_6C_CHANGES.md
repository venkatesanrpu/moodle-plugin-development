# Phase 7_6c — Fix Streaming Sequence (Typing Indicator Order)

## Bug

After phase7_6b, the AI response widget showed content in the wrong order:

```
1. Streaming tokens arrive  → assistantNode.textContent = finalText  ✓ (visible)
2. 'done' SSE event fires   → assistantNode.innerHTML  = <typing dots> ✗ (OVERWRITES text!)
3. renderNode() resolves    → assistantNode.innerHTML  = rendered HTML ✓
```

The user saw:
  ① streamed plain text (correct)
  ② typing indicator dots  ← jarring regression
  ③ final rendered HTML

## Fix

The typing indicator is now shown **before** any token arrives — in `appendMessage()` —
and is cleared by the **first token** received from the stream.
The `'done'` handler no longer touches `assistantNode.innerHTML` at all.

Correct sequence after this patch:

```
1. sendMessage()            → appendMessage(..., showTyping=true)
                              assistantNode.dataset.typing = '1'
                              assistantNode shows ⋯ dots

2. First token arrives      → dataset.typing '1' detected
                              assistantNode.innerHTML = ''   (dots cleared)
                              assistantNode.textContent = firstToken

3. Subsequent tokens        → assistantNode.textContent = finalText  (grows)

4. 'done' event             → save_history() → renderNode()
                              assistantNode.innerHTML = rendered HTML + MathJax
                              Status bar: "Saving & rendering..." → "Ready"
```

## Files Changed

### `amd/src/widget.js` (only file changed in this patch)

| Location | Change |
|---|---|
| `appendMessage()` | Added `showTyping` boolean param. When `true`, injects typing indicator HTML and sets `dataset.typing='1'`. |
| `handleChunk()` | On first chunk, checks `dataset.typing==='1'`, clears innerHTML, sets typing flag to `'0'`, then writes `textContent`. |
| `startStream() 'done' handler` | **Removed** the `assistantNode.innerHTML = <typing>` line entirely. Status bar updated to `'Saving & rendering...'`. |
| `sendMessage()` | Changed `appendMessage(root, 'assistant', '')` → `appendMessage(root, 'assistant', '', true)`. |
| `sendMessage() catch` | Added fallback: clears typing flag and shows error text if `ask_agent` call fails. |

## No Other Files Changed

`history.js`, `block_ai_assistant_v2.php`, `render_helper.php` from phase7_6b remain unchanged.
