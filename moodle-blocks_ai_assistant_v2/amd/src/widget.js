define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    const state = {};

    const ensureScript = src => new Promise((resolve, reject) => {
        const existing = document.querySelector('script[data-ai-src="' + src + '"]');
        if (existing) {
            if (existing.dataset.loaded === '1') {
                resolve();
                return;
            }
            existing.addEventListener('load', () => resolve(), {once: true});
            existing.addEventListener('error', reject, {once: true});
            return;
        }
        const tag = document.createElement('script');
        tag.src = src;
        tag.async = true;
        tag.dataset.aiSrc = src;
        tag.addEventListener('load', () => {
            tag.dataset.loaded = '1';
            resolve();
        }, {once: true});
        tag.addEventListener('error', reject, {once: true});
        document.head.appendChild(tag);
    });

    const ensureCss = href => {
        if (!document.querySelector('link[data-ai-href="' + href + '"]')) {
            const tag = document.createElement('link');
            tag.rel = 'stylesheet';
            tag.href = href;
            tag.dataset.aiHref = href;
            document.head.appendChild(tag);
        }
    };

    const ensureRenderDeps = async() => {
        ensureCss('https://cdn.jsdelivr.net/npm/katex@0.16.25/dist/katex.min.css');
        await ensureScript('https://cdn.jsdelivr.net/npm/marked/marked.min.js');
        await ensureScript('https://cdn.jsdelivr.net/npm/katex@0.16.25/dist/katex.min.js');
        await ensureScript('https://cdn.jsdelivr.net/npm/katex@0.16.25/dist/contrib/auto-render.min.js');
    };

    const renderAssistantMessage = async(container, text) => {
        await ensureRenderDeps();
        const html = window.marked ? window.marked.parse(text || '') : (text || '');
        container.innerHTML = html;
        if (window.renderMathInElement) {
            window.renderMathInElement(container, {
                delimiters: [
                    {left: '$$', right: '$$', display: true},
                    {left: '$', right: '$', display: false},
                    {left: '\\(', right: '\\)', display: false},
                    {left: '\\[', right: '\\]', display: true}
                ],
                throwOnError: false
            });
        }
    };

    const q = (root, selector) => root.querySelector(selector);

    const setStatus = (root, text) => {
        const status = q(root, '[data-region="status"]');
        if (status) {
            status.textContent = text;
        }
    };

    const setBusy = (root, busy) => {
        const send = q(root, '[data-action="send-message"]');
        const input = q(root, '[data-region="prompt-input"]');
        if (send) {
            send.disabled = busy;
            send.textContent = busy ? 'Working...' : 'Send';
        }
        if (input) {
            input.disabled = busy;
        }
    };

    const appendMessage = (root, role, text) => {
        const body = q(root, '[data-region="chat-body"]');
        const empty = body.querySelector('.block_ai_assistant_v2-empty');
        if (empty) {
            empty.remove();
        }
        const div = document.createElement('div');
        div.className = 'block_ai_assistant_v2-message block_ai_assistant_v2-message--' + role;
        div.textContent = text || '';
        body.appendChild(div);
        body.scrollTop = body.scrollHeight;
        return div;
    };

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
        if (!Array.isArray(lessons)) return [];
        return lessons.map(item => {
            if (typeof item === 'string') return {key: item, label: item};
            // Shape A: { lesson, lesson_key }
            if (item.lesson !== undefined) {
                return {key: item.lesson_key || item.lesson, label: item.lesson};
            }
            // Generic: { key/id, label/name }
            return {
                key:   item.key   || item.id   || item.name  || item.label || '',
                label: item.label || item.name || item.key   || item.id    || ''
            };
        });
    };

    const normalizeTopics = topics => {
        if (!Array.isArray(topics)) {
            // Generic keyed object
            return Object.keys(topics || {}).map(k => ({
                key:     k,
                label:   (topics[k] && (topics[k].label || topics[k].name)) || k,
                lessons: normalizeLessons((topics[k] && topics[k].lessons) || [])
            }));
        }
        return topics.map(item => {
            // Shape A: { topic, topic_key, lessons }
            if (item.topic !== undefined) {
                return {
                    key:     item.topic_key || item.topic,
                    label:   item.topic,
                    lessons: normalizeLessons(item.lessons || [])
                };
            }
            // Generic
            return {
                key:     item.key   || item.id   || item.name  || item.label || '',
                label:   item.label || item.name || item.key   || item.id    || '',
                lessons: normalizeLessons(item.lessons || [])
            };
        });
    };

    const normalizeSyllabus = raw => {
        if (!raw) return [];

        // Shape B: array — each element is a subject (Shape A or generic)
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

        // Shape C: { subjects: [...] }
        if (Array.isArray(raw.subjects)) return normalizeSyllabus(raw.subjects);

        // Shape A: single-subject object { subject, subject_key, topics }
        if (raw.subject !== undefined) {
            return [{
                key:    raw.subject_key || raw.subject,
                label:  raw.subject,
                topics: normalizeTopics(raw.topics || [])
            }];
        }

        // Shape E: generic keyed object (legacy)
        return Object.keys(raw).map(key => ({
            key:    key,
            label:  (raw[key] && (raw[key].label || raw[key].name)) || key,
            topics: normalizeTopics((raw[key] && raw[key].topics) || {})
        }));
    };

    const renderActiveFilters = root => {
        const node = q(root, '[data-region="active-filters"]');
        const subject = q(root, '[data-region="subject-select"]').value || '';
        const topic = q(root, '[data-region="topic-select"]').value || '';
        const lesson = q(root, '[data-region="lesson-select"]').value || '';
        const values = [subject, topic, lesson].filter(Boolean);
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
        const topicSelect = q(root, '[data-region="topic-select"]');
        const lessonSelect = q(root, '[data-region="lesson-select"]');
        const data = state[root.id].syllabus || [];

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
            const topics = subject ? (subject.topics || []) : [];
            topicSelect.innerHTML = '';
            topicSelect.appendChild(buildOptions(topics, 'All topics'));
            topicSelect.disabled = topics.length === 0;
            resetSelect(lessonSelect, 'All lessons');
            renderActiveFilters(root);
        });

        topicSelect.addEventListener('change', () => {
            const subject = data.find(item => item.key === subjectSelect.value || item.label === subjectSelect.value);
            const topics = subject ? (subject.topics || []) : [];
            const topic = topics.find(item => item.key === topicSelect.value || item.label === topicSelect.value);
            const lessons = topic ? (topic.lessons || []) : [];
            lessonSelect.innerHTML = '';
            lessonSelect.appendChild(buildOptions(lessons, 'All lessons'));
            lessonSelect.disabled = lessons.length === 0;
            renderActiveFilters(root);
        });

        lessonSelect.addEventListener('change', () => {
            renderActiveFilters(root);
        });
    };

    const fetchSyllabus = root => {
        const ctx = state[root.id].context;
        return Ajax.call([{methodname: 'block_ai_assistant_v2_get_syllabus', args: {
            courseid: Number(ctx.courseid),
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

    const startStream = (root, params, assistantNode) => {
        const ctx = state[root.id].context;
        let finalText = '';
        let completed = false;
        const url = new URL(ctx.streamurl, window.location.origin);
        url.searchParams.set('courseid', ctx.courseid);
        url.searchParams.set('historyid', params.historyid);
        url.searchParams.set('agentkey', ctx.agentkey);
        url.searchParams.set('sesskey', ctx.sesskey);
        url.searchParams.set('token', params.streamtoken);

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
            if (!chunk || chunk === 'connected') {
                return;
            }
            finalText += chunk;
            assistantNode.textContent = finalText;
            const body = q(root, '[data-region="chat-body"]');
            body.scrollTop = body.scrollHeight;
        };

        source.onmessage = event => {
            try {
                handleChunk(JSON.parse(event.data));
            } catch (e) {
                handleChunk(event.data);
            }
        };

        ['token', 'chunk', 'delta', 'message'].forEach(eventName => {
            source.addEventListener(eventName, event => {
                try {
                    handleChunk(JSON.parse(event.data));
                } catch (e) {
                    handleChunk(event.data);
                }
            });
        });

        source.addEventListener('status', event => {
            try {
                const payload = JSON.parse(event.data);
                if (payload.message) {
                    setStatus(root, payload.message === 'connected' ? 'Connected. Generating response...' : payload.message);
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
            renderAssistantMessage(assistantNode, finalText).then(() => {
                setStatus(root, 'Saving response...');
                return Ajax.call([{methodname: 'block_ai_assistant_v2_save_history', args: {
                    courseid: Number(ctx.courseid),
                    historyid: Number(params.historyid),
                    botresponse: finalText,
                    metadata: JSON.stringify({status: 'done', streamed: true, expires: params.expires || 0})
                }}])[0];
            }).then(() => {
                setStatus(root, 'Ready');
                setBusy(root, false);
            }).catch(error => {
                setBusy(root, false);
                Notification.exception(error);
            });
        });

        source.addEventListener('error', () => {
            if (source._closedByApp || completed) {
                return;
            }
            source.close();
            state[root.id].source = null;
            setBusy(root, false);
            setStatus(root, 'Streaming failed');
            if (!finalText) {
                assistantNode.textContent = 'Unable to generate a response.';
            }
        });
    };

    const sendMessage = root => {
        const ctx = state[root.id].context;
        const input = q(root, '[data-region="prompt-input"]');
        const subject = q(root, '[data-region="subject-select"]');
        const topic = q(root, '[data-region="topic-select"]');
        const lesson = q(root, '[data-region="lesson-select"]');
        const usertext = (input.value || '').trim();
        if (!usertext) {
            setStatus(root, 'Enter a question first.');
            return;
        }

        appendMessage(root, 'user', usertext);
        const assistantNode = appendMessage(root, 'assistant', '');
        input.value = '';
        setStatus(root, 'Preparing request...');

        Ajax.call([{methodname: 'block_ai_assistant_v2_ask_agent', args: {
            courseid: Number(ctx.courseid),
            blockinstanceid: Number(ctx.blockinstanceid),
            agentkey: ctx.agentkey,
            usertext: usertext,
            subject: subject.value || '',
            topic: topic.value || '',
            lesson: lesson.value || ''
        }}])[0].then(result => {
            startStream(root, result, assistantNode);
        }).catch(error => {
            setBusy(root, false);
            Notification.exception(error);
        });
    };

    const bind = root => {
        const shell = q(root, '[data-region="chat-shell"]');
        const open = q(root, '[data-action="open-chat"]');
        const close = q(root, '[data-action="close-chat"]');
        const send = q(root, '[data-action="send-message"]');
        const input = q(root, '[data-region="prompt-input"]');
        const toggleGuided = q(root, '[data-action="toggle-guided"]');
        const guided = q(root, '[data-region="guided-search"]');

        open.addEventListener('click', () => {
            shell.hidden = false;
        });
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
        toggleGuided.addEventListener('click', () => {
            guided.hidden = !guided.hidden;
        });
    };

    return {
        init: context => {
            const root = document.getElementById(context.uniqid);
            if (!root) {
                return;
            }
            state[root.id] = {context: context, syllabus: [], source: null};
            bind(root);
            fetchSyllabus(root);
        }
    };
});
