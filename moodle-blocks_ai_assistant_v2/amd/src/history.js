define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    const state = {};
    const q = (root, selector) => root.querySelector(selector);

    const setPageInfo = (root, result) => {
        const node = q(root, '[data-region="history-pageinfo"]');
        if (node) {
            node.textContent = result.page + ' / ' + result.totalpages;
        }
    };

    /**
     * typesetMath — run MathJax v4 on a node after innerHTML is set.
     * window.MathJax is guaranteed by block_ai_assistant_v2.php plain-script load.
     */
    const typesetMath = node => {
        if (window.MathJax && window.MathJax.typesetPromise) {
            window.MathJax.typesetPromise([node]).catch(() => { /* silent */ });
        }
    };

    /**
     * renderHistoryItem — call render_response web service for one history row,
     * populate the answer node with rendered HTML + typeset math.
     *
     * @param {Element} answerNode  Target DOM element for bot answer.
     * @param {number}  historyid   DB row id.
     * @param {number}  courseid    Course id.
     */
    const renderHistoryItem = (answerNode, historyid, courseid) => {
        answerNode.innerHTML = '<span class="block_ai_assistant_v2-typing">'
            + '<span></span><span></span><span></span></span>';
        Ajax.call([{
            methodname: 'block_ai_assistant_v2_render_response',
            args: {historyid: Number(historyid), courseid: Number(courseid)}
        }])[0].then(result => {
            answerNode.innerHTML = result.html || '';
            typesetMath(answerNode);
        }).catch(() => {
            answerNode.textContent = '(Could not render response.)';
        });
    };

    const renderList = (root, items) => {
        const ctx  = state[root.id].context;
        const list = q(root, '[data-region="history-list"]');
        list.innerHTML = '';
        if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'block_ai_assistant_v2-historyempty';
            empty.textContent = 'No history found.';
            list.appendChild(empty);
            return;
        }
        items.forEach(item => {
            const card = document.createElement('div');
            card.className = 'block_ai_assistant_v2-historycard';
            card.dataset.historyid = item.id;

            // Question row — always visible, click to expand/collapse answer.
            const qNode = document.createElement('div');
            qNode.className = 'block_ai_assistant_v2-historyq';
            qNode.textContent = item.usertext || '';
            qNode.style.cursor = 'pointer';

            // Answer row — hidden by default, lazy-rendered on first expand.
            const aNode = document.createElement('div');
            aNode.className = 'block_ai_assistant_v2-historya';
            aNode.hidden = true;
            aNode.dataset.rendered = '0';

            card.appendChild(qNode);
            card.appendChild(aNode);

            // Expand/collapse; trigger render_response only on first open.
            qNode.addEventListener('click', () => {
                aNode.hidden = !aNode.hidden;
                if (!aNode.hidden && aNode.dataset.rendered === '0') {
                    aNode.dataset.rendered = '1';
                    renderHistoryItem(aNode, Number(item.id), Number(ctx.courseid));
                }
            });

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
