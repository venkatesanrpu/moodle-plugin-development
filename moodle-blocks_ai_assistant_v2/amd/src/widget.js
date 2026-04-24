// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Main chat widget for block_ai_assistant_v2.
 *
 * Phase 7_6h — fixes init() to accept a CONTEXT OBJECT passed by
 * $PAGE->requires->js_call_amd(..., [$context]).
 *
 * Root cause of "rootEl.querySelector is not a function":
 *   block_ai_assistant_v2.php passes the full $context array to js_call_amd.
 *   Moodle serialises this as a JS plain object {uniqid, courseid, sesskey...}.
 *   Previous versions of init() expected a string or HTMLElement — not an
 *   object — so rootEl ended up being the plain object, and .querySelector
 *   threw a TypeError.
 *
 * Fix: init() now reads all values directly from the context object and
 * locates the DOM wrapper via context.uniqid.
 *
 * @module     block_ai_assistant_v2/widget
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax        from 'core/ajax';
import typesetMath from 'block_ai_assistant_v2/mathjax_helper';

// ─── Selectors ───────────────────────────────────────────────────────────────

const SEL = {
    ROOT:          '[data-region="chat-shell"]',
    CHAT_BODY:     '.blockaiassistantv2-body',
    INPUT:         '.blockaiassistantv2-input',
    SEND_BTN:      '[data-action="send"]',
    CLOSE_BTN:     '[data-action="close-chat"]',
    LAUNCHER:      '[data-action="open-chat"]',
    TYPING_IMG:    '.blockaiassistantv2-typing',
    MSG_WRAPPER:   '.blockaiassistantv2-messages',
    GUIDED_BTN:    '[data-action="toggle-guided"]',
    HISTORY_BTN:   '[data-action="toggle-history"]',
    MCQ_BTN:       '[data-action="toggle-mcq"]',
    GUIDED_PANEL:  '[data-region="guided-search"]',
    HISTORY_PANEL: '[data-region="history-panel"]',
    MCQ_PANEL:     '[data-region="mcq-panel"]',
};

// ─── Module state ─────────────────────────────────────────────────────────────

let rootEl        = null;
let courseId      = 0;
let sesskey       = '';
let streamUrl     = '';
let agentKey      = '';
let mainSubject   = '';
let activeFilters = {};
let eventSource   = null;
let isStreaming    = false;

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Append a message bubble to the chat body.
 *
 * @param {string}  role    'user' | 'assistant'
 * @param {string}  text    Raw text (user) or HTML (assistant).
 * @param {boolean} isHtml  If true, set innerHTML; otherwise set textContent.
 * @returns {HTMLElement}   The created message element.
 */
const appendMessage = (role, text, isHtml = false) => {
    const body  = rootEl.querySelector(SEL.CHAT_BODY);
    const wrap  = rootEl.querySelector(SEL.MSG_WRAPPER);
    const msgEl = document.createElement('div');
    msgEl.classList.add(
        'blockaiassistantv2-message',
        `blockaiassistantv2-message--${role}`
    );
    if (isHtml) {
        msgEl.innerHTML = text;
    } else {
        msgEl.textContent = text;
    }
    if (wrap) {
        wrap.appendChild(msgEl);
    } else if (body) {
        body.appendChild(msgEl);
    }
    msgEl.scrollIntoView({behavior: 'smooth', block: 'end'});
    return msgEl;
};

/**
 * Show or hide the typing indicator image.
 *
 * @param {boolean} show
 */
const setTyping = (show) => {
    const typingEl = rootEl.querySelector(SEL.TYPING_IMG);
    if (typingEl) {
        typingEl.hidden = !show;
    }
};

/**
 * Disable / enable the send button and input.
 *
 * @param {boolean} disabled
 */
const setInputDisabled = (disabled) => {
    const inputEl = rootEl.querySelector(SEL.INPUT);
    const sendBtn = rootEl.querySelector(SEL.SEND_BTN);
    if (inputEl) {
        inputEl.disabled = disabled;
    }
    if (sendBtn) {
        sendBtn.disabled = disabled;
    }
};

// ─── Render complete (called after SSE stream ends) ───────────────────────────

/**
 * Fetch rendered HTML from the server and replace the streaming text node.
 *
 * @param {HTMLElement} answerNode  The message element currently showing raw text.
 * @param {number}      historyId   DB record id returned by the stream endpoint.
 */
const renderComplete = (answerNode, historyId) => {
    setTyping(false);

    Ajax.call([{
        methodname: 'block_ai_assistant_v2_render_response',
        args: {
            historyid: historyId,
            courseid:  courseId,
        },
    }])[0]
    .then((result) => {
        if (result && result.html) {
            answerNode.innerHTML = result.html;
            typesetMath(answerNode);
        }
        return result;
    })
    .catch((err) => {
        // eslint-disable-next-line no-console
        window.console.error('[AI Assistant] renderComplete AJAX error:', err);
    })
    .finally(() => {
        setInputDisabled(false);
        isStreaming = false;
    });
};

// ─── SSE streaming ────────────────────────────────────────────────────────────

/**
 * Start an SSE stream for the given prompt.
 *
 * @param {string} prompt  User's question.
 */
const startStream = (prompt) => {
    if (isStreaming) {
        return;
    }
    isStreaming = true;
    setInputDisabled(true);

    appendMessage('user', prompt);

    const answerNode = appendMessage('assistant', '');
    setTyping(true);

    const params = new URLSearchParams({
        sesskey:     sesskey,
        courseid:    courseId,
        prompt:      prompt,
        agentkey:    agentKey,
        mainsubject: mainSubject,
        filters:     JSON.stringify(activeFilters),
    });

    const url = `${streamUrl}?${params.toString()}`;
    eventSource = new EventSource(url);

    let historyId  = null;
    let rawBuffer  = '';
    let firstChunk = true;

    eventSource.onmessage = (e) => {
        const data = e.data;

        if (data === '[DONE]') {
            eventSource.close();
            eventSource = null;
            renderComplete(answerNode, historyId);
            return;
        }

        try {
            const parsed = JSON.parse(data);

            if (firstChunk) {
                firstChunk = false;
                setTyping(false);
                if (parsed.historyid) {
                    historyId = parsed.historyid;
                }
                if (parsed.chunk !== undefined) {
                    rawBuffer += parsed.chunk;
                    answerNode.textContent = rawBuffer;
                }
                return;
            }

            if (parsed.chunk !== undefined) {
                rawBuffer += parsed.chunk;
                answerNode.textContent = rawBuffer;
                answerNode.scrollIntoView({behavior: 'smooth', block: 'end'});
            }
        } catch (err) {
            rawBuffer += data;
            answerNode.textContent = rawBuffer;
        }
    };

    eventSource.onerror = () => {
        eventSource.close();
        eventSource = null;
        isStreaming = false;
        setTyping(false);
        setInputDisabled(false);
        if (!rawBuffer) {
            answerNode.textContent = '(Connection error. Please try again.)';
        }
    };
};

// ─── Send handler ─────────────────────────────────────────────────────────────

const handleSend = () => {
    const inputEl = rootEl.querySelector(SEL.INPUT);
    if (!inputEl) {
        return;
    }
    const prompt = inputEl.value.trim();
    if (!prompt) {
        return;
    }
    inputEl.value = '';
    startStream(prompt);
};

// ─── Panel toggle helpers ─────────────────────────────────────────────────────

const togglePanel = (activeRegion) => {
    [SEL.GUIDED_PANEL, SEL.HISTORY_PANEL, SEL.MCQ_PANEL].forEach((sel) => {
        const el = rootEl.querySelector(sel);
        if (!el) {
            return;
        }
        el.hidden = (el.dataset.region !== activeRegion) ? true : !el.hidden;
    });
};

// ─── Initialisation ───────────────────────────────────────────────────────────

/**
 * Initialise the widget.
 *
 * Called by block_ai_assistant_v2.php via:
 *   $PAGE->requires->js_call_amd('block_ai_assistant_v2/widget', 'init', [$context]);
 *
 * Moodle serialises $context (a PHP array) as a plain JS object:
 *   { uniqid, courseid, sesskey, streamurl, agentkey, mainsubjectkey, ... }
 *
 * Phase 7_6h fix: init() reads all values from the context object directly,
 * then locates the DOM wrapper by the uniqid string.
 *
 * @param {Object} ctx  Context object from block_ai_assistant_v2.php.
 */
const init = (ctx) => {

    // ── Phase 7_6h: read from context object ──────────────────────────────
    if (!ctx || typeof ctx !== 'object') {
        return;
    }

    const uniqid = ctx.uniqid || '';
    courseId     = parseInt(ctx.courseid, 10)  || 0;
    sesskey      = ctx.sesskey                 || '';
    streamUrl    = ctx.streamurl               || '';
    agentKey     = ctx.agentkey                || '';
    mainSubject  = ctx.mainsubjectkey          || '';
    // ──────────────────────────────────────────────────────────────────────

    // The Mustache template wraps everything in an element whose id = uniqid.
    // rootEl is the inner chat-shell; fall back to the wrapper itself.
    const rootWrapper = uniqid ? document.getElementById(uniqid) : null;
    if (!rootWrapper) {
        return;
    }

    rootEl = rootWrapper.querySelector(SEL.ROOT) || rootWrapper;

    // Launcher button (opens the shell).
    const launcherBtn = rootWrapper.querySelector(SEL.LAUNCHER);
    if (launcherBtn) {
        launcherBtn.addEventListener('click', () => {
            rootEl.hidden = false;
            launcherBtn.hidden = true;
        });
    }

    // Close button.
    const closeBtn = rootEl.querySelector(SEL.CLOSE_BTN);
    if (closeBtn) {
        closeBtn.addEventListener('click', () => {
            rootEl.hidden = true;
            if (launcherBtn) {
                launcherBtn.hidden = false;
            }
        });
    }

    // Send button.
    const sendBtn = rootEl.querySelector(SEL.SEND_BTN);
    if (sendBtn) {
        sendBtn.addEventListener('click', handleSend);
    }

    // Enter key in textarea.
    const inputEl = rootEl.querySelector(SEL.INPUT);
    if (inputEl) {
        inputEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                handleSend();
            }
        });
    }

    // Toolbar panel toggles.
    const guidedBtn = rootEl.querySelector(SEL.GUIDED_BTN);
    if (guidedBtn) {
        guidedBtn.addEventListener('click', () => togglePanel('guided-search'));
    }

    const historyBtn = rootEl.querySelector(SEL.HISTORY_BTN);
    if (historyBtn) {
        historyBtn.addEventListener('click', () => togglePanel('history-panel'));
    }

    const mcqBtn = rootEl.querySelector(SEL.MCQ_BTN);
    if (mcqBtn) {
        mcqBtn.addEventListener('click', () => togglePanel('mcq-panel'));
    }
};

export {init};
