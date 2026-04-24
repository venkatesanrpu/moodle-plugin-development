define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    const state = {};
    const q = (root, selector) => root.querySelector(selector);

    const setPageInfo = (root, result) => {
        const node = q(root, '[data-region="history-pageinfo"]');
        if (node) {
            node.textContent = result.page + ' / ' + result.totalpages;
        }
    };

    const renderList = (root, items) => {
        const list = q(root, '[data-region="history-list"]');
        list.innerHTML = '';
        if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'block_ai_assistant_v2-historyempty';
            empty.textContent = 'No history found for the selected filters.';
            list.appendChild(empty);
            return;
        }
        items.forEach(item => {
            const card = document.createElement('article');
            card.className = 'block_ai_assistant_v2-historyitem';
            const meta = [item.formattedtime, item.subject, item.topic, item.lesson].filter(Boolean).join(' • ');
            card.innerHTML = '' +
                '<div class="block_ai_assistant_v2-historymeta">' + meta + '</div>' +
                '<div class="block_ai_assistant_v2-historyq"></div>' +
                '<div class="block_ai_assistant_v2-historya"></div>';
            card.querySelector('.block_ai_assistant_v2-historyq').textContent = item.usertext || '';
            const rawPreview = (item.previewtext || item.botresponse || '').slice(0, 320);
            // Strip common markdown markers for the plain-text preview snippet
            const cleanPreview = rawPreview
                .replace(/#{1,6}\s+/g, '')          // headings
                .replace(/\*{1,3}([^*]+)\*{1,3}/g, '$1') // bold/italic
                .replace(/`{1,3}[^`]*`{1,3}/g, '')   // inline code / fenced
                .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')  // links
                .replace(/^[>\-*+]\s+/gm, '')       // blockquote / list markers
                // escaped-bracket strip omitted (not needed for preview)
                .replace(/\s+/g, ' ').trim()
                .slice(0, 260);
            card.querySelector('.block_ai_assistant_v2-historya').textContent = cleanPreview;
            list.appendChild(card);
        });
    };

    const getFilters = root => ({
        subject: q(root, '[data-region="subject-select"]').value || '',
        topic: q(root, '[data-region="topic-select"]').value || '',
        lesson: q(root, '[data-region="lesson-select"]').value || '',
        general: !!q(root, '[data-region="history-general"]').checked
    });

    const loadHistory = root => {
        const ctx = state[root.id].context;
        const params = state[root.id];
        const filters = getFilters(root);
        return Ajax.call([{methodname: 'block_ai_assistant_v2_get_history', args: {
            courseid: Number(ctx.courseid),
            page: params.page,
            perpage: params.perpage,
            subject: filters.subject,
            topic: filters.topic,
            lesson: filters.lesson,
            general: filters.general
        }}])[0].then(result => {
            state[root.id].last = result;
            renderList(root, result.items || []);
            setPageInfo(root, result);
        }).catch(Notification.exception);
    };

    const bind = root => {
        const panel = q(root, '[data-region="history-panel"]');
        const toggle = q(root, '[data-action="toggle-history"]');
        const refresh = q(root, '[data-action="refresh-history"]');
        const prev = q(root, '[data-action="history-prev"]');
        const next = q(root, '[data-action="history-next"]');
        const subject = q(root, '[data-region="subject-select"]');
        const topic = q(root, '[data-region="topic-select"]');
        const lesson = q(root, '[data-region="lesson-select"]');
        const general = q(root, '[data-region="history-general"]');

        toggle.addEventListener('click', () => {
            panel.hidden = !panel.hidden;
            if (!panel.hidden) {
                state[root.id].page = 1;
                loadHistory(root);
            }
        });
        refresh.addEventListener('click', () => {
            state[root.id].page = 1;
            loadHistory(root);
        });
        prev.addEventListener('click', () => {
            if (state[root.id].page > 1) {
                state[root.id].page -= 1;
                loadHistory(root);
            }
        });
        next.addEventListener('click', () => {
            const last = state[root.id].last;
            if (last && last.hasnext) {
                state[root.id].page += 1;
                loadHistory(root);
            }
        });

        [subject, topic, lesson, general].forEach(node => {
            node.addEventListener('change', () => {
                if (!panel.hidden) {
                    state[root.id].page = 1;
                    loadHistory(root);
                }
            });
        });
    };

    return {
        init: context => {
            const root = document.getElementById(context.uniqid);
            if (!root) {
                return;
            }
            state[root.id] = {context: context, page: 1, perpage: 10, last: null};
            bind(root);
        }
    };
});
