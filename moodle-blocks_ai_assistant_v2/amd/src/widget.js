// widget.js — Phase 7_6j
//
// DOM contract (main.mustache) — DO NOT change selectors without updating mustache:
//
//   #{{uniqid}}                         rootWrapper
//   ├── [data-action="open-chat"]       launcher button
//   └── [data-region="chat-shell"]      shellEl (hidden on load)
//       ├── [data-action="close-chat"]
//       ├── [data-action="toggle-guided"]
//       ├── [data-action="toggle-history"]
//       ├── [data-action="toggle-mcq"]
//       ├── [data-region="guided-search"]
//       │   ├── [data-region="subject-select"]
//       │   ├── [data-region="topic-select"]
//       │   └── [data-region="lesson-select"]
//       ├── [data-region="history-panel"]
//       ├── [data-region="mcq-panel"]
//       ├── [data-region="chat-body"]
//       ├── [data-region="status"]
//       ├── [data-region="prompt-input"]   <textarea>
//       └── [data-action="send-message"]   send button
//
// Stream flow (MUST follow this 2-step sequence — stream.php requires historyid+token):
//   Step 1: Ajax → block_ai_assistant_v2_ask_agent → { historyid, streamtoken }
//   Step 2: EventSource → stream.php?courseid=X&historyid=Y&agentkey=Z&sesskey=S&token=T
//
// @module block_ai_assistant_v2/widget

import Ajax        from 'core/ajax';
import typesetMath from 'block_ai_assistant_v2/mathjax_helper';

// ─── Selectors (verified 1-to-1 against main.mustache) ───────────────────────

const SEL = {
    LAUNCHER:        '[data-action="open-chat"]',
    SHELL:           '[data-region="chat-shell"]',
    CLOSE_BTN:       '[data-action="close-chat"]',
    TOOLBAR_GUIDED:  '[data-action="toggle-guided"]',
    TOOLBAR_HIST:    '[data-action="toggle-history"]',
    TOOLBAR_MCQ:     '[data-action="toggle-mcq"]',
    GUIDED_PANEL:    '[data-region="guided-search"]',
    HISTORY_PANEL:   '[data-region="history-panel"]',
    MCQ_PANEL:       '[data-region="mcq-panel"]',
    CHAT_BODY:       '[data-region="chat-body"]',
    STATUS:          '[data-region="status"]',
    INPUT:           '[data-region="prompt-input"]',
    SEND_BTN:        '[data-action="send-message"]',
    SUBJECT_SELECT:  '[data-region="subject-select"]',
    TOPIC_SELECT:    '[data-region="topic-select"]',
    LESSON_SELECT:   '[data-region="lesson-select"]',
};

// ─── Module state ─────────────────────────────────────────────────────────────

let rootWrapper      = null;
let shellEl          = null;
let courseId         = 0;
let blockInstanceId  = 0;
let sesskey          = '';
let streamUrl        = '';
let agentKey         = '';
let activeFilters    = {};
let eventSource      = null;
let isStreaming       = false;

// ─── Status bar ───────────────────────────────────────────────────────────────

const setStatus = (text) => {
    const el = shellEl.querySelector(SEL.STATUS);
    if (el) {
        el.textContent = text;
    }
};

// ─── Message helpers ──────────────────────────────────────────────────────────

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
        const emptyEl = bodyEl.querySelector('.block_ai_assistant_v2-empty');
        if (emptyEl) {
            emptyEl.remove();
        }
        bodyEl.appendChild(msgEl);
        msgEl.scrollIntoView({behavior: 'smooth', block: 'end'});
    }
    return msgEl;
};

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

// ─── Post-stream render ───────────────────────────────────────────────────────

const renderComplete = (answerNode, historyId) => {
    setStatus('Rendering…');
    Ajax.call([{
        methodname: 'block_ai_assistant_v2_render_response',
        args: {historyid: historyId, courseid: courseId},
    }])[0]
    .then((result) => {
        if (result && result.html) {
            answerNode.innerHTML = result.html;
            typesetMath(answerNode);
        }
        return result;
    })
    .catch((err) => {
        window.console.error('[AI Assistant] renderComplete error:', err);
    })
    .finally(() => {
        setInputDisabled(false);
        isStreaming = false;
        setStatus('Ready');
    });
};

// ─── 2-step SSE stream ────────────────────────────────────────────────────────
//
// CRITICAL: stream.php requires historyid + token (signed by ask_agent).
// Step 1: call ask_agent AJAX → receive historyid + streamtoken.
// Step 2: open EventSource to stream.php with those values.
//
// Phase 7_6i had removed Step 1 entirely — that is why every message gave
// "(Connection error. Please try again.)" — stream.php returned 403.

const startStream = (prompt) => {
    if (isStreaming) {
        return;
    }
    isStreaming = true;
    setInputDisabled(true);
    setStatus('Thinking…');

    appendMessage('user', prompt);
    const answerNode = appendMessage('assistant', '');

    // ── Step 1: ask_agent — creates history row, issues signed stream token ──
    Ajax.call([{
        methodname: 'block_ai_assistant_v2_ask_agent',
        args: {
            courseid:         courseId,
            blockinstanceid:  blockInstanceId,
            agentkey:         agentKey,
            usertext:         prompt,
            subject:          activeFilters.subject  || '',
            topic:            activeFilters.topic    || '',
            lesson:           activeFilters.lesson   || '',
        },
    }])[0]
    .then((res) => {
        const historyId   = res.historyid;
        const streamToken = res.streamtoken;

        // ── Step 2: open EventSource with historyid + token ──────────────
        const params = new URLSearchParams({
            sesskey:   sesskey,
            courseid:  courseId,
            historyid: historyId,
            agentkey:  agentKey,
            token:     streamToken,
        });
        const url = `${streamUrl}?${params.toString()}`;
        eventSource = new EventSource(url);

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

                if (parsed.event === 'done' || parsed.done) {
                    eventSource.close();
                    eventSource = null;
                    renderComplete(answerNode, historyId);
                    return;
                }

                if (firstChunk) {
                    firstChunk = false;
                    setStatus('Streaming…');
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

        return res;
    })
    .catch((err) => {
        window.console.error('[AI Assistant] ask_agent error:', err);
        isStreaming = false;
        setInputDisabled(false);
        setStatus('Ready');
        answerNode.textContent = '(Failed to start. Please try again.)';
    });
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

// ─── Guided search ────────────────────────────────────────────────────────────
//
// FIX BUG 3a: must pass blockinstanceid (not agentkey/mainsubjectkey).
// FIX BUG 3b: get_syllabus returns { syllabusjson: "..." } — a raw JSON string.
//             Must JSON.parse it and build the subjects[] array ourselves.
//
// Expected syllabus.json structure (from the saved edit_form data):
//   { "subjects": [ { "key": "PHYS", "label": "Physics",
//       "topics": [ { "key": "EM", "label": "Electromagnetism",
//           "lessons": [ { "key": "L1", "label": "Maxwell Equations" } ] } ] } ] }

const initGuidedSearch = () => {
    const subjectSel = shellEl.querySelector(SEL.SUBJECT_SELECT);
    if (!subjectSel || subjectSel.dataset.loaded === '1') {
        return;
    }
    subjectSel.dataset.loaded = '1';

    Ajax.call([{
        methodname: 'block_ai_assistant_v2_get_syllabus',
        args: {
            courseid:         courseId,
            blockinstanceid:  blockInstanceId,   // FIX: was missing
        },
    }])[0]
    .then((result) => {
        // FIX: result.syllabusjson is a raw JSON string — parse it first.
        let syllabus = {};
        try {
            syllabus = JSON.parse(result.syllabusjson || '{}');
        } catch (e) {
            window.console.warn('[AI Assistant] syllabus JSON parse error:', e);
        }

        const subjects = syllabus.subjects || [];
        subjects.forEach((s) => {
            const opt       = document.createElement('option');
            opt.value       = s.key || s.label || '';
            opt.textContent = s.label || s.key || '';
            subjectSel.appendChild(opt);
        });

        // Store syllabus on the element for topic/lesson cascades.
        subjectSel._syllabusData = subjects;

        return result;
    })
    .catch((err) => {
        window.console.warn('[AI Assistant] get_syllabus error:', err);
    });

    // Subject → populate topics.
    subjectSel.addEventListener('change', () => {
        activeFilters.subject = subjectSel.value || '';
        const topicSel  = shellEl.querySelector(SEL.TOPIC_SELECT);
        const lessonSel = shellEl.querySelector(SEL.LESSON_SELECT);

        if (topicSel) {
            topicSel.innerHTML = '<option value="">All topics</option>';
            topicSel.disabled  = !subjectSel.value;

            const subjects = subjectSel._syllabusData || [];
            const found    = subjects.find((s) => (s.key || s.label) === subjectSel.value);
            (found ? found.topics || [] : []).forEach((t) => {
                const opt       = document.createElement('option');
                opt.value       = t.key || t.label || '';
                opt.textContent = t.label || t.key || '';
                topicSel.appendChild(opt);
            });
            topicSel._topicsData = found ? found.topics || [] : [];
        }
        if (lessonSel) {
            lessonSel.innerHTML = '<option value="">All lessons</option>';
            lessonSel.disabled  = true;
        }
        activeFilters.topic  = '';
        activeFilters.lesson = '';
    });

    // Topic → populate lessons.
    const topicSel = shellEl.querySelector(SEL.TOPIC_SELECT);
    if (topicSel) {
        topicSel.addEventListener('change', () => {
            activeFilters.topic = topicSel.value || '';
            const lessonSel = shellEl.querySelector(SEL.LESSON_SELECT);
            if (lessonSel) {
                lessonSel.innerHTML = '<option value="">All lessons</option>';
                lessonSel.disabled  = !topicSel.value;

                const topics = topicSel._topicsData || [];
                const found  = topics.find((t) => (t.key || t.label) === topicSel.value);
                (found ? found.lessons || [] : []).forEach((l) => {
                    const opt       = document.createElement('option');
                    opt.value       = l.key || l.label || '';
                    opt.textContent = l.label || l.key || '';
                    lessonSel.appendChild(opt);
                });
            }
            activeFilters.lesson = '';
        });
    }

    const lessonSel = shellEl.querySelector(SEL.LESSON_SELECT);
    if (lessonSel) {
        lessonSel.addEventListener('change', () => {
            activeFilters.lesson = lessonSel.value || '';
        });
    }
};

// ─── Initialisation ───────────────────────────────────────────────────────────

const init = (ctx) => {
    if (!ctx || typeof ctx !== 'object') {
        return;
    }

    const uniqid    = ctx.uniqid           || '';
    courseId        = parseInt(ctx.courseid, 10)         || 0;
    blockInstanceId = parseInt(ctx.blockinstanceid, 10)  || 0;
    sesskey         = ctx.sesskey          || '';
    streamUrl       = ctx.streamurl        || '';
    agentKey        = ctx.agentkey         || '';

    rootWrapper = uniqid ? document.getElementById(uniqid) : null;
    if (!rootWrapper) {
        return;
    }

    shellEl = rootWrapper.querySelector(SEL.SHELL);
    if (!shellEl) {
        return;
    }

    // Launcher button (outside shell).
    const launcherBtn = rootWrapper.querySelector(SEL.LAUNCHER);
    if (launcherBtn) {
        launcherBtn.addEventListener('click', () => {
            shellEl.hidden = false;
            launcherBtn.hidden = true;
        });
    }

    // Close button (inside shell).
    const closeBtn = shellEl.querySelector(SEL.CLOSE_BTN);
    if (closeBtn) {
        closeBtn.addEventListener('click', () => {
            shellEl.hidden = true;
            if (launcherBtn) {
                launcherBtn.hidden = false;
            }
        });
    }

    // Send button.
    const sendBtn = shellEl.querySelector(SEL.SEND_BTN);
    if (sendBtn) {
        sendBtn.addEventListener('click', handleSend);
    }

    // Textarea: Enter sends, Shift+Enter = newline.
    const inputEl = shellEl.querySelector(SEL.INPUT);
    if (inputEl) {
        inputEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                handleSend();
            }
        });
    }

    // Toolbar panel toggles — widget.js is the SOLE owner of panel visibility.
    // mcq.js must NOT also bind toggle-mcq (causes double-toggle = panel flickers closed).
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
