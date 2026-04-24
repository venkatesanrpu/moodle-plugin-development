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
 * Phase 7_6i — Comprehensive fix after full cross-analysis of widget.js
 * selectors against main.mustache DOM attributes.
 *
 * All 8 bugs found in Phase 7_6h are corrected here.
 * See PHASE7_6I_NOTES.md for the full bug list.
 *
 * DOM contract (from main.mustache — DO NOT change selectors without
 * also updating main.mustache):
 *
 *   #{{uniqid}}                              outer wrapper (rootWrapper)
 *   ├── [data-action="open-chat"]            launcher button (outside shell)
 *   └── [data-region="chat-shell"]           chat shell section (hidden on load)
 *       ├── [data-action="close-chat"]       × close button
 *       ├── [data-action="toggle-guided"]    toolbar: guided search
 *       ├── [data-action="toggle-history"]   toolbar: history
 *       ├── [data-action="toggle-mcq"]       toolbar: MCQ
 *       ├── [data-region="guided-search"]    guided search panel
 *       ├── [data-region="history-panel"]    history aside
 *       ├── [data-region="mcq-panel"]        MCQ section
 *       ├── [data-region="chat-body"]        message display area
 *       ├── [data-region="status"]           status line
 *       ├── [data-region="prompt-input"]     <textarea>
 *       └── [data-action="send-message"]     send button
 *
 * NOTE: There is NO .blockaiassistantv2-typing element in the mustache.
 *       Typing state is shown via the status bar [data-region="status"].
 *
 * @module     block_ai_assistant_v2/widget
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax        from 'core/ajax';
import typesetMath from 'block_ai_assistant_v2/mathjax_helper';

// ─── Selectors — VERIFIED against main.mustache ──────────────────────────────
// Each selector here maps 1-to-1 to an element in main.mustache.
// Do not change these without also changing main.mustache.

const SEL = {
    // Outer wrapper (id="{{uniqid}}")
    // — accessed via document.getElementById(uniqid), not as a CSS selector.

    // Elements at rootWrapper level (outside the shell):
    LAUNCHER:      '[data-action="open-chat"]',      // launcher button

    // Chat shell (rootEl after init):
    SHELL:         '[data-region="chat-shell"]',     // <section hidden>

    // Inside shell:
    CLOSE_BTN:     '[data-action="close-chat"]',     // × button
    TOOLBAR_GUIDED:'[data-action="toggle-guided"]',  // toolbar button
    TOOLBAR_HIST:  '[data-action="toggle-history"]', // toolbar button
    TOOLBAR_MCQ:   '[data-action="toggle-mcq"]',     // toolbar button

    GUIDED_PANEL:  '[data-region="guided-search"]',  // guided search panel
    HISTORY_PANEL: '[data-region="history-panel"]',  // history aside
    MCQ_PANEL:     '[data-region="mcq-panel"]',      // mcq section

    CHAT_BODY:     '[data-region="chat-body"]',      // FIX BUG-3: was wrong class
    STATUS:        '[data-region="status"]',          // status text line

    // Compose area (inside shell footer):
    INPUT:         '[data-region="prompt-input"]',   // FIX BUG-2: was wrong class
    SEND_BTN:      '[data-action="send-message"]',   // FIX BUG-1: was "send", must be "send-message"

    // Guided search selects (inside guided panel):
    SUBJECT_SELECT:'[data-region="subject-select"]',
    TOPIC_SELECT:  '[data-region="topic-select"]',
    LESSON_SELECT: '[data-region="lesson-select"]',
    ACTIVE_FILTERS:'[data-region="active-filters"]',
};

// ─── Module state ─────────────────────────────────────────────────────────────

let rootWrapper   = null;  // #{{uniqid}} div
let shellEl       = null;  // [data-region="chat-shell"]
let courseId      = 0;
let sesskey       = '';
let streamUrl     = '';
let agentKey      = '';
let mainSubject   = '';
let activeFilters = {};
let eventSource   = null;
let isStreaming   = false;

// ─── Status bar helper ────────────────────────────────────────────────────────
// BUG-4 / BUG-7 fix: mustache has no .blockaiassistantv2-typing element.
// Typing state is communicated through the [data-region="status"] bar instead.

const setStatus = (text) => {
    const el = shellEl.querySelector(SEL.STATUS);
    if (el) {
        el.textContent = text;
    }
};

// ─── Message helpers ──────────────────────────────────────────────────────────

/**
 * Append a message bubble to the chat body.
 *
 * BUG-3/5 fix: appends directly to [data-region="chat-body"].
 * There is no separate messages-wrapper element in main.mustache.
 *
 * @param {string}  role    'user' | 'assistant'
 * @param {string}  text    Raw text or HTML.
 * @param {boolean} isHtml  If true, set innerHTML; otherwise textContent.
 * @returns {HTMLElement}
 */
const appendMessage = (role, text, isHtml = false) => {
    const bodyEl = shellEl.querySelector(SEL.CHAT_BODY);
    const msgEl  = document.createElement('div');
    msgEl.classList.add(
        'block_ai_assistant_v2-message',
        `block_ai_assistant_v2-message--${role}`
    );
    if (isHtml) {
        msgEl.innerHTML = text;
    } else {
        msgEl.textContent = text;
    }
    if (bodyEl) {
        // Remove the empty-state placeholder on first message.
        const emptyEl = bodyEl.querySelector('.block_ai_assistant_v2-empty');
        if (emptyEl) {
            emptyEl.remove();
        }
        bodyEl.appendChild(msgEl);
        msgEl.scrollIntoView({behavior: 'smooth', block: 'end'});
    }
    return msgEl;
};

/**
 * Disable / enable the send button and textarea.
 *
 * @param {boolean} disabled
 */
const setInputDisabled = (disabled) => {
    const inputEl = shellEl.querySelector(SEL.INPUT);
    const sendBtn = shellEl.querySelector(SEL.SEND_BTN);
    if (inputEl) {
        inputEl.disabled = disabled;
    }
    if (sendBtn) {
        sendBtn.disabled = disabled;
    }
};

// ─── Render complete (called after SSE stream ends) ───────────────────────────

/**
 * Fetch server-rendered HTML and replace the raw streaming text.
 *
 * @param {HTMLElement} answerNode
 * @param {number}      historyId
 */
const renderComplete = (answerNode, historyId) => {
    setStatus('Rendering…');

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
        setStatus('Ready');
    });
};

// ─── SSE streaming ────────────────────────────────────────────────────────────

/**
 * Start an SSE stream for the given prompt.
 *
 * @param {string} prompt
 */
const startStream = (prompt) => {
    if (isStreaming) {
        return;
    }
    isStreaming = true;
    setInputDisabled(true);
    setStatus('Thinking…');

    appendMessage('user', prompt);
    const answerNode = appendMessage('assistant', '');

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
                setStatus('Streaming…');
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
        } catch (ignoreParseErr) {
            rawBuffer += data;
            answerNode.textContent = rawBuffer;
        }
    };

    eventSource.onerror = () => {
        eventSource.close();
        eventSource = null;
        isStreaming = false;
        setInputDisabled(false);
        setStatus('Ready');
        if (!rawBuffer) {
            answerNode.textContent = '(Connection error. Please try again.)';
        }
    };
};

// ─── Send handler ─────────────────────────────────────────────────────────────

const handleSend = () => {
    const inputEl = shellEl.querySelector(SEL.INPUT);
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

// ─── Panel toggles ────────────────────────────────────────────────────────────

/**
 * Toggle a side panel, collapsing the others.
 *
 * @param {string} targetRegion  data-region value of the panel to toggle.
 */
const togglePanel = (targetRegion) => {
    [SEL.GUIDED_PANEL, SEL.HISTORY_PANEL, SEL.MCQ_PANEL].forEach((sel) => {
        const el = shellEl.querySelector(sel);
        if (!el) {
            return;
        }
        if (el.dataset.region === targetRegion) {
            el.hidden = !el.hidden;
        } else {
            el.hidden = true;
        }
    });
};

// ─── Guided search filter helpers ─────────────────────────────────────────────

/**
 * Populate the subject/topic/lesson dropdowns from the syllabus.
 * Called once after init when the guided panel is first opened.
 */
const initGuidedSearch = () => {
    const subjectSel = shellEl.querySelector(SEL.SUBJECT_SELECT);
    if (!subjectSel || subjectSel.dataset.loaded === '1') {
        return;
    }
    subjectSel.dataset.loaded = '1';

    Ajax.call([{
        methodname: 'block_ai_assistant_v2_get_syllabus',
        args: {
            courseid:       courseId,
            agentkey:       agentKey,
            mainsubjectkey: mainSubject,
        },
    }])[0]
    .then((result) => {
        (result.subjects || []).forEach((s) => {
            const opt = document.createElement('option');
            opt.value       = s.key;
            opt.textContent = s.label;
            subjectSel.appendChild(opt);
        });
        return result;
    })
    .catch((err) => {
        // eslint-disable-next-line no-console
        window.console.warn('[AI Assistant] get_syllabus error:', err);
    });

    subjectSel.addEventListener('change', () => {
        activeFilters.subject = subjectSel.value || '';
        const topicSel  = shellEl.querySelector(SEL.TOPIC_SELECT);
        const lessonSel = shellEl.querySelector(SEL.LESSON_SELECT);
        if (topicSel) {
            topicSel.innerHTML  = '<option value="">All topics</option>';
            topicSel.disabled   = !subjectSel.value;
        }
        if (lessonSel) {
            lessonSel.innerHTML = '<option value="">All lessons</option>';
            lessonSel.disabled  = true;
        }
    });
};

// ─── Initialisation ───────────────────────────────────────────────────────────

/**
 * Initialise the widget.
 *
 * Called by block_ai_assistant_v2.php via:
 *   $PAGE->requires->js_call_amd('block_ai_assistant_v2/widget', 'init', [$context]);
 *
 * Moodle serialises $context (PHP array) into a plain JS object:
 *   { uniqid, courseid, sesskey, streamurl, agentkey, mainsubjectkey, ... }
 *
 * Phase 7_6i: reads all values from context object, finds DOM via getElementById.
 *
 * @param {Object} ctx  Context object from block_ai_assistant_v2.php.
 */
const init = (ctx) => {
    if (!ctx || typeof ctx !== 'object') {
        return;
    }

    const uniqid = ctx.uniqid       || '';
    courseId     = parseInt(ctx.courseid, 10) || 0;
    sesskey      = ctx.sesskey      || '';
    streamUrl    = ctx.streamurl    || '';
    agentKey     = ctx.agentkey     || '';
    mainSubject  = ctx.mainsubjectkey || '';

    rootWrapper = uniqid ? document.getElementById(uniqid) : null;
    if (!rootWrapper) {
        return;
    }

    shellEl = rootWrapper.querySelector(SEL.SHELL);
    if (!shellEl) {
        // Shell element not found — mustache may not have rendered yet.
        return;
    }

    // ── Launcher button (outside shell) ──────────────────────────────────
    const launcherBtn = rootWrapper.querySelector(SEL.LAUNCHER);
    if (launcherBtn) {
        launcherBtn.addEventListener('click', () => {
            shellEl.hidden = false;
            launcherBtn.hidden = true;
        });
    }

    // ── Close button (inside shell) ───────────────────────────────────────
    const closeBtn = shellEl.querySelector(SEL.CLOSE_BTN);
    if (closeBtn) {
        closeBtn.addEventListener('click', () => {
            shellEl.hidden = true;
            if (launcherBtn) {
                launcherBtn.hidden = false;
            }
        });
    }

    // ── Send button ───────────────────────────────────────────────────────
    // SEL.SEND_BTN = '[data-action="send-message"]' — matches mustache exactly.
    const sendBtn = shellEl.querySelector(SEL.SEND_BTN);
    if (sendBtn) {
        sendBtn.addEventListener('click', handleSend);
    }

    // ── Textarea: Enter key sends ─────────────────────────────────────────
    const inputEl = shellEl.querySelector(SEL.INPUT);
    if (inputEl) {
        inputEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                handleSend();
            }
        });
    }

    // ── Toolbar panel toggles ─────────────────────────────────────────────
    const guidedBtn = shellEl.querySelector(SEL.TOOLBAR_GUIDED);
    if (guidedBtn) {
        guidedBtn.addEventListener('click', () => {
            togglePanel('guided-search');
            initGuidedSearch();
        });
    }

    const historyBtn = shellEl.querySelector(SEL.TOOLBAR_HIST);
    if (historyBtn) {
        historyBtn.addEventListener('click', () => togglePanel('history-panel'));
    }

    const mcqBtn = shellEl.querySelector(SEL.TOOLBAR_MCQ);
    if (mcqBtn) {
        mcqBtn.addEventListener('click', () => togglePanel('mcq-panel'));
    }
};

export {init};
