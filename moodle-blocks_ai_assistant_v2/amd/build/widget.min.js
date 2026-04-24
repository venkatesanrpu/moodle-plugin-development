// phase7_6d: Fix streaming sequence (phase7_6c) + 3-state MathJax guard — typing indicator shown BEFORE stream starts,
//            NOT injected between the streamed text and the rendered HTML.
//
// Bug in phase7_6b:
//   The 'done' SSE handler was:
//     1. Stream tokens accumulate → assistantNode.textContent = finalText  ✓
//     2. 'done' fires → assistantNode.innerHTML = <typing indicator>       ← WRONG
//        (this overwrites the already-visible streamed text with the spinner)
//     3. save_history() → renderNode() → assistantNode.innerHTML = result.html  ✓
//
//   Correct sequence:
//     1. User sends message
//     2. assistantNode shows typing indicator immediately (before stream starts)
//     3. First token arrives → typing indicator replaced by streaming text
//     4. Tokens accumulate in assistantNode.textContent
//     5. 'done' fires → save_history() → renderNode() → innerHTML = rendered HTML
//        (NO typing indicator injected here — streamed text stays visible while saving)

define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    const state = {};

    /**
     * typesetMath — re-typeset a DOM node using Moodle's native MathJax 3
     * (loaded by filter_mathjaxloader).
     * Polls up to ~3 s (10 × 300 ms) to handle async MathJax initialisation.
     *
     * @param {Element} node
     */
    /**
     * typesetMath — re-typeset a DOM node using window.MathJax (MathJax v3).
     *
     * phase7_6d: upgraded to 3-state guard.
     * window.MathJax is populated by Moodle's filter_mathjaxloader.
     * Do NOT use require(['core/mathjax']) — that AMD shim returns undefined,
     * not the MathJax API object. window.MathJax is the correct reference.
     *
     * States:
     *   a) MathJax ready              → call typesetPromise() immediately.
     *   b) MathJax startup pending    → wait for startup.promise then call.
     *   c) MathJax not yet injected   → poll every 200 ms up to 5 s.
     *
     * @param {Element} node
     */
    const typesetMath = (node) => {
        if (!node) { return; }

        // State (a): fully initialised.
        if (window.MathJax && typeof window.MathJax.typesetPromise === 'function') {
            window.MathJax.typesetPromise([node]).catch(() => {});
            return;
        }

        // State (b): object exists but startup not yet complete.
        if (window.MathJax && window.MathJax.startup && window.MathJax.startup.promise) {
            window.MathJax.startup.promise.then(() => {
                window.MathJax.typesetPromise([node]).catch(() => {});
            });
            return;
        }

        // State (c): MathJax script not yet injected — poll up to 5 s.
        let attempts = 0;
        const timer = setInterval(() => {
            attempts++;
            if (window.MathJax && typeof window.MathJax.typesetPromise === 'function') {
                clearInterval(timer);
                window.MathJax.typesetPromise([node]).catch(() => {});
            } else if (window.MathJax && window.MathJax.startup && window.MathJax.startup.promise) {
                clearInterval(timer);
                window.MathJax.startup.promise.then(() => {
                    window.MathJax.typesetPromise([node]).catch(() => {});
                });
            } else if (attempts >= 25) { // 25 × 200 ms = 5 s
                clearInterval(timer);
            }
        }, 200);
    };

    /**
     * renderNode — call render_response web service (Parsedown + math-safe),
     * set innerHTML of node, then typeset math.
     *
     * @param {Element} node
     * @param {number}  historyid
     * @param {number}  courseid
     * @returns {Promise}
     */
    const renderNode = (node, historyid, courseid) => {
        return Ajax.call([{
            methodname: 'block_ai_assistant_v2_render_response',
            args: {historyid: Number(historyid), courseid: Number(courseid)}
        }])[0].then(result => {
            node.innerHTML = result.html || '';
            typesetMath(node);
        }).catch(() => {
            // Render failed — textContent is already showing streamed text; leave it.
        });
    };

    // ── DOM helpers ──────────────────────────────────────────────────────────
    const q = (root, sel) => root.querySelector(sel);

    const setStatus = (root, text) => {
        const s = q(root, '[data-region="status"]');
        if (s) { s.textContent = text; }
    };

    const setBusy = (root, busy) => {
        const send  = q(root, '[data-action="send-message"]');
        const input = q(root, '[data-region="prompt-input"]');
        if (send)  { send.disabled  = busy; send.textContent = busy ? 'Working...' : 'Send'; }
        if (input) { input.disabled = busy; }
    };

    /**
     * appendMessage — add a chat bubble to the chat body.
     * When role === 'assistant' and showTyping === true, the bubble starts with
     * the animated typing indicator. The FIRST token received will replace it.
     *
     * @param {Element} root
     * @param {string}  role        'user' | 'assistant'
     * @param {string}  text        Initial textContent (ignored when showTyping is true)
     * @param {boolean} showTyping  When true, inject typing indicator HTML instead of text.
     * @returns {Element}           The created bubble element.
     */
    const appendMessage = (root, role, text, showTyping = false) => {
        const body  = q(root, '[data-region="chat-body"]');
        const empty = body.querySelector('.block_ai_assistant_v2-empty');
        if (empty) { empty.remove(); }

        const div = document.createElement('div');
        div.className = 'block_ai_assistant_v2-message block_ai_assistant_v2-message--' + role;

        if (showTyping) {
            // Show animated dots immediately; first token arrival clears this.
            div.innerHTML = '<span class="block_ai_assistant_v2-typing">'
                + '<span></span><span></span><span></span></span>';
            div.dataset.typing = '1'; // flag: first token must clear this
        } else {
            div.textContent = text || '';
        }

        body.appendChild(div);
        body.scrollTop = body.scrollHeight;
        return div;
    };

    // ── Syllabus helpers (unchanged from phase7_6b) ──────────────────────────

    const buildOptions = (items, placeholder) => {
        const fragment = document.createDocumentFragment();
        const first = document.createElement('option');
        first.value = '';
        first.textContent = placeholder;
        fragment.appendChild(first);
        items.forEach(item => {
            const option = document.createElement('option');
            option.value = item.key || item.id || item.name || '';
            option.textContent = item.label || item.name || item.title || option.value;
            fragment.appendChild(option);
        });
        return fragment;
    };

    const normalizeLessons = lessons => {
        if (!Array.isArray(lessons)) { return []; }
        return lessons.map(item => {
            if (typeof item === 'string') { return {key: item, label: item}; }
            if (item.lesson !== undefined) {
                return {key: item.lesson_key || item.lesson, label: item.lesson};
            }
            return {
                key:   item.key   || item.id   || item.name  || item.label || '',
                label: item.label || item.name || item.key   || item.id    || ''
            };
        });
    };

    const normalizeTopics = topics => {
        if (!Array.isArray(topics)) {
            return Object.keys(topics || {}).map(k => ({
                key:     k,
                label:   (topics[k] && (topics[k].label || topics[k].name)) || k,
                lessons: normalizeLessons((topics[k] && topics[k].lessons) || [])
            }));
        }
        return topics.map(item => {
            if (item.topic !== undefined) {
                return {
                    key:     item.topic_key || item.topic,
                    label:   item.topic,
                    lessons: normalizeLessons(item.lessons || [])
                };
            }
            return {
                key:     item.key   || item.id   || item.name  || item.label || '',
                label:   item.label || item.name || item.key   || item.id    || '',
                lessons: normalizeLessons(item.lessons || [])
            };
        });
    };

    const normalizeSyllabus = raw => {
        if (!raw) { return []; }
        if (Array.isArray(raw)) {
            return raw.map(item => {
                if (item.subject !== undefined) {
                    return {
                        key:    item.subject_key || item.subject,
                        label:  item.subject,
                        topics: normalizeTopics(item.topics || [])
                    };
                }
                return {
                    key:    item.key   || item.id   || item.name  || item.label || '',
                    label:  item.label || item.name || item.key   || item.id    || '',
                    topics: normalizeTopics(item.topics || [])
                };
            });
        }
        if (Array.isArray(raw.subjects)) { return normalizeSyllabus(raw.subjects); }
        if (raw.subject !== undefined) {
            return [{
                key:    raw.subject_key || raw.subject,
                label:  raw.subject,
                topics: normalizeTopics(raw.topics || [])
            }];
        }
        return Object.keys(raw).map(key => ({
            key:    key,
            label:  (raw[key] && (raw[key].label || raw[key].name)) || key,
            topics: normalizeTopics((raw[key] && raw[key].topics) || {})
        }));
    };

    const renderActiveFilters = root => {
        const node    = q(root, '[data-region="active-filters"]');
        const subject = q(root, '[data-region="subject-select"]').value || '';
        const topic   = q(root, '[data-region="topic-select"]').value   || '';
        const lesson  = q(root, '[data-region="lesson-select"]').value  || '';
        const values  = [subject, topic, lesson].filter(Boolean);
        node.innerHTML = '';
        if (!values.length) {
            const span = document.createElement('span');
            span.className = 'block_ai_assistant_v2-filtertag';
            span.textContent = 'General mode';
            node.appendChild(span);
            return;
        }
        values.forEach(value => {
            const span = document.createElement('span');
            span.className = 'block_ai_assistant_v2-filtertag';
            span.textContent = value;
            node.appendChild(span);
        });
    };

    const wireSyllabus = root => {
        const subjectSelect = q(root, '[data-region="subject-select"]');
        const topicSelect   = q(root, '[data-region="topic-select"]');
        const lessonSelect  = q(root, '[data-region="lesson-select"]');
        const data          = state[root.id].syllabus || [];

        const resetSelect = (select, placeholder) => {
            select.innerHTML = '';
            select.appendChild(buildOptions([], placeholder));
            select.disabled = true;
        };

        subjectSelect.innerHTML = '';
        subjectSelect.appendChild(buildOptions(data, 'All subjects'));
        resetSelect(topicSelect, 'All topics');
        resetSelect(lessonSelect, 'All lessons');
        renderActiveFilters(root);

        subjectSelect.addEventListener('change', () => {
            const subject = data.find(item => item.key === subjectSelect.value || item.label === subjectSelect.value);
            const topics  = subject ? (subject.topics || []) : [];
            topicSelect.innerHTML = '';
            topicSelect.appendChild(buildOptions(topics, 'All topics'));
            topicSelect.disabled = topics.length === 0;
            resetSelect(lessonSelect, 'All lessons');
            renderActiveFilters(root);
        });

        topicSelect.addEventListener('change', () => {
            const subject = data.find(item => item.key === subjectSelect.value || item.label === subjectSelect.value);
            const topics  = subject ? (subject.topics || []) : [];
            const topic   = topics.find(item => item.key === topicSelect.value || item.label === topicSelect.value);
            const lessons = topic ? (topic.lessons || []) : [];
            lessonSelect.innerHTML = '';
            lessonSelect.appendChild(buildOptions(lessons, 'All lessons'));
            lessonSelect.disabled = lessons.length === 0;
            renderActiveFilters(root);
        });

        lessonSelect.addEventListener('change', () => renderActiveFilters(root));
    };

    const fetchSyllabus = root => {
        const ctx = state[root.id].context;
        return Ajax.call([{methodname: 'block_ai_assistant_v2_get_syllabus', args: {
            courseid:        Number(ctx.courseid),
            blockinstanceid: Number(ctx.blockinstanceid)
        }}])[0].then(result => {
            try {
                state[root.id].syllabus = normalizeSyllabus(JSON.parse(result.syllabusjson || '[]'));
            } catch (e) {
                state[root.id].syllabus = [];
            }
            wireSyllabus(root);
        }).catch(Notification.exception);
    };

    const closeExistingSource = root => {
        const current = state[root.id].source;
        if (current) {
            current._closedByApp = true;
            current.close();
            state[root.id].source = null;
        }
    };

    // ── Core streaming ───────────────────────────────────────────────────────

    /**
     * startStream — open SSE connection and handle token / done / error events.
     *
     * CORRECT sequence enforced here:
     *   appendMessage(..., showTyping=true)  → bubble shows typing indicator
     *   first token arrives                  → typing indicator replaced by text
     *   subsequent tokens                   → textContent grows (streaming)
     *   'done' event                         → save to DB, then renderNode (HTML+math)
     *   renderNode resolves                  → innerHTML = formatted HTML, MathJax runs
     *
     * There is NO second injection of the typing indicator in the 'done' handler.
     *
     * @param {Element} root
     * @param {object}  params        Result from block_ai_assistant_v2_ask_agent
     * @param {Element} assistantNode Pre-created bubble (already shows typing indicator)
     */
    const startStream = (root, params, assistantNode) => {
        const ctx = state[root.id].context;
        let finalText = '';
        let completed = false;

        const url = new URL(ctx.streamurl, window.location.origin);
        url.searchParams.set('courseid',   ctx.courseid);
        url.searchParams.set('historyid',  params.historyid);
        url.searchParams.set('agentkey',   ctx.agentkey);
        url.searchParams.set('sesskey',    ctx.sesskey);
        url.searchParams.set('token',      params.streamtoken);

        closeExistingSource(root);
        const source = new EventSource(url.toString());
        state[root.id].source = source;
        setStatus(root, 'Generating response...');
        setBusy(root, true);

        const handleChunk = payload => {
            let chunk = '';
            if (typeof payload === 'string') {
                chunk = payload;
            } else if (payload) {
                chunk = payload.text || payload.token || payload.delta || payload.content || payload.message || '';
            }
            if (!chunk || chunk === 'connected') { return; }

            finalText += chunk;

            // phase7_6c: First token clears the typing indicator and switches to
            // textContent mode. Subsequent tokens simply append to finalText.
            if (assistantNode.dataset.typing === '1') {
                assistantNode.dataset.typing = '0';
                assistantNode.innerHTML = '';   // clear typing dots
            }
            assistantNode.textContent = finalText;

            const body = q(root, '[data-region="chat-body"]');
            if (body) { body.scrollTop = body.scrollHeight; }
        };

        source.onmessage = event => {
            try { handleChunk(JSON.parse(event.data)); } catch (e) { handleChunk(event.data); }
        };

        ['token', 'chunk', 'delta', 'message'].forEach(eventName => {
            source.addEventListener(eventName, event => {
                try { handleChunk(JSON.parse(event.data)); } catch (e) { handleChunk(event.data); }
            });
        });

        source.addEventListener('status', event => {
            try {
                const payload = JSON.parse(event.data);
                if (payload.message) {
                    setStatus(root, payload.message === 'connected'
                        ? 'Connected. Generating response...'
                        : payload.message);
                }
            } catch (e) {
                setStatus(root, 'Connected. Generating response...');
            }
        });

        source.addEventListener('done', () => {
            completed = true;
            source._closedByApp = true;
            source.close();
            state[root.id].source = null;

            // phase7_6c: Do NOT replace assistantNode content with a typing indicator here.
            // The streamed text is visible and should stay while we save + render.
            // Status bar tells the user what is happening instead.
            setStatus(root, 'Saving & rendering...');

            // Step 1 — persist raw markdown to DB.
            Ajax.call([{methodname: 'block_ai_assistant_v2_save_history', args: {
                courseid:    Number(ctx.courseid),
                historyid:   Number(params.historyid),
                botresponse: finalText,
                metadata:    JSON.stringify({status: 'done', streamed: true, expires: params.expires || 0})
            }}])[0].then(() => {
                // Step 2 — server-side Markdown+Math → safe HTML, set innerHTML + MathJax.
                return renderNode(assistantNode, params.historyid, ctx.courseid);
            }).then(() => {
                setStatus(root, 'Ready');
                setBusy(root, false);
                const body = q(root, '[data-region="chat-body"]');
                if (body) { body.scrollTop = body.scrollHeight; }
            }).catch(error => {
                // Fallback: keep the raw streamed text visible.
                assistantNode.textContent = finalText;
                setBusy(root, false);
                setStatus(root, 'Ready');
                Notification.exception(error);
            });
        });

        source.addEventListener('error', () => {
            if (source._closedByApp || completed) { return; }
            source.close();
            state[root.id].source = null;
            setBusy(root, false);
            setStatus(root, 'Streaming failed');
            if (!finalText) {
                assistantNode.textContent = 'Unable to generate a response.';
            }
        });
    };

    // ── sendMessage ───────────────────────────────────────────────────────────

    const sendMessage = root => {
        const ctx     = state[root.id].context;
        const input   = q(root, '[data-region="prompt-input"]');
        const subject = q(root, '[data-region="subject-select"]');
        const topic   = q(root, '[data-region="topic-select"]');
        const lesson  = q(root, '[data-region="lesson-select"]');
        const usertext = (input.value || '').trim();
        if (!usertext) {
            setStatus(root, 'Enter a question first.');
            return;
        }

        appendMessage(root, 'user', usertext);

        // phase7_6c: assistantNode starts with the typing indicator visible.
        // startStream will replace it on the first token received.
        const assistantNode = appendMessage(root, 'assistant', '', true);
        input.value = '';
        setStatus(root, 'Preparing request...');

        Ajax.call([{methodname: 'block_ai_assistant_v2_ask_agent', args: {
            courseid:        Number(ctx.courseid),
            blockinstanceid: Number(ctx.blockinstanceid),
            agentkey:        ctx.agentkey,
            usertext:        usertext,
            subject:         subject.value || '',
            topic:           topic.value   || '',
            lesson:          lesson.value  || ''
        }}])[0].then(result => {
            startStream(root, result, assistantNode);
        }).catch(error => {
            // Ask failed — clear the typing indicator, show error hint.
            assistantNode.textContent = 'Could not reach the server.';
            assistantNode.dataset.typing = '0';
            setBusy(root, false);
            Notification.exception(error);
        });
    };

    // ── bind ──────────────────────────────────────────────────────────────────

    const bind = root => {
        const shell        = q(root, '[data-region="chat-shell"]');
        const open         = q(root, '[data-action="open-chat"]');
        const close        = q(root, '[data-action="close-chat"]');
        const send         = q(root, '[data-action="send-message"]');
        const input        = q(root, '[data-region="prompt-input"]');
        const toggleGuided = q(root, '[data-action="toggle-guided"]');
        const guided       = q(root, '[data-region="guided-search"]');

        open.addEventListener('click',  () => { shell.hidden = false; });
        close.addEventListener('click', () => {
            shell.hidden = true;
            closeExistingSource(root);
            setBusy(root, false);
            setStatus(root, 'Ready');
        });
        send.addEventListener('click', () => sendMessage(root));
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMessage(root);
            }
        });
        toggleGuided.addEventListener('click', () => { guided.hidden = !guided.hidden; });
    };

    return {
        init: context => {
            const root = document.getElementById(context.uniqid);
            if (!root) { return; }
            state[root.id] = {context: context, syllabus: [], source: null};
            bind(root);
            fetchSyllabus(root);
        }
    };
});
