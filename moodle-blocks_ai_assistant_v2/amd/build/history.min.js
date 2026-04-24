// phase7_6d — History panel: zero-AJAX rendering
//
// What changed vs phase7_6c:
//   REMOVED: renderHistoryItem() — it called render_response web service,
//            which was failing with MUST_EXIST exception (courseid mismatch)
//            causing the typing-indicator to flash then show "(Could not render response.)"
//
//   ADDED:   renderFromItem() — uses item.renderedhtml pre-rendered by PHP
//            (get_history.php now calls render_helper::render() server-side).
//            JS simply sets innerHTML and calls typesetMath(). No AJAX at all.
//
//   REMOVED: 'shown.bs.modal' event listener — the block is NOT a Bootstrap
//            modal; it is an inline <aside> element. That listener never fired.
//
//   FIXED:   typesetMath() now correctly uses window.MathJax (populated by
//            Moodle's filter_mathjaxloader). The previous code used
//            require(['core/mathjax']) which returns undefined, not a MathJax
//            object. window.MathJax.typesetPromise() is the correct API.

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
     * typesetMath — re-typeset a DOM node using window.MathJax (MathJax v3).
     *
     * window.MathJax is populated by Moodle's filter_mathjaxloader.
     * It is NOT the same as the AMD module 'core/mathjax' — that module is a
     * loader shim that returns undefined, not the MathJax API object.
     *
     * Three-state guard:
     *   a) MathJax ready          → call typesetPromise() immediately.
     *   b) MathJax startup promise pending → wait for it then call.
     *   c) MathJax not yet injected → poll every 200 ms up to 5 s.
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

        // State (b): MathJax object exists but startup not complete.
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
     * renderFromItem — insert pre-rendered HTML from get_history response
     * directly into the answer node, then typeset math.
     *
     * No AJAX call. PHP already rendered the HTML via render_helper::render().
     *
     * @param {Element} answerNode  Target DOM element.
     * @param {Object}  item        History item from get_history response.
     */
    const renderFromItem = (answerNode, item) => {
        const html = item.renderedhtml || '';
        if (html.trim() === '') {
            answerNode.textContent = '(No response stored.)';
            return;
        }
        answerNode.innerHTML = html;
        typesetMath(answerNode);
    };

    /**
     * renderList — build accordion-style history cards in the panel.
     *
     * Each card: question div (clickable) + answer div (hidden until clicked).
     * First click: insert renderedhtml, typeset math, mark as rendered.
     * Subsequent clicks: just toggle visibility.
     */
    const renderList = (root, items) => {
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

            const qNode = document.createElement('div');
            qNode.className = 'block_ai_assistant_v2-historyq';
            qNode.textContent = item.usertext || '';
            qNode.style.cursor = 'pointer';

            const aNode = document.createElement('div');
            aNode.className = 'block_ai_assistant_v2-historya';
            aNode.hidden = true;
            aNode.dataset.rendered = '0';

            card.appendChild(qNode);
            card.appendChild(aNode);

            // Click → toggle accordion; render on first open (no AJAX).
            qNode.addEventListener('click', () => {
                aNode.hidden = !aNode.hidden;
                if (!aNode.hidden && aNode.dataset.rendered === '0') {
                    aNode.dataset.rendered = '1';
                    renderFromItem(aNode, item);   // ← uses item.renderedhtml
                }
            });

            list.appendChild(card);
        });
    };

    const getFilters = root => ({
        subject: q(root, '[data-region="subject-select"]').value  || '',
        topic:   q(root, '[data-region="topic-select"]').value    || '',
        lesson:  q(root, '[data-region="lesson-select"]').value   || '',
        general: !!q(root, '[data-region="history-general"]').checked
    });

    const loadHistory = root => {
        const ctx     = state[root.id].context;
        const params  = state[root.id];
        const filters = getFilters(root);

        return Ajax.call([{
            methodname: 'block_ai_assistant_v2_get_history',
            args: {
                courseid: Number(ctx.courseid),
                page:     params.page,
                perpage:  params.perpage,
                subject:  filters.subject,
                topic:    filters.topic,
                lesson:   filters.lesson,
                general:  filters.general
            }
        }])[0].then(result => {
            state[root.id].last = result;
            renderList(root, result.items || []);
            setPageInfo(root, result);
        }).catch(Notification.exception);
    };

    const bind = root => {
        const panel   = q(root, '[data-region="history-panel"]');
        const toggle  = q(root, '[data-action="toggle-history"]');
        const refresh = q(root, '[data-action="refresh-history"]');
        const prev    = q(root, '[data-action="history-prev"]');
        const next    = q(root, '[data-action="history-next"]');
        const subject = q(root, '[data-region="subject-select"]');
        const topic   = q(root, '[data-region="topic-select"]');
        const lesson  = q(root, '[data-region="lesson-select"]');
        const general = q(root, '[data-region="history-general"]');

        toggle.addEventListener('click', () => {
            panel.hidden = !panel.hidden;
            if (!panel.hidden) { state[root.id].page = 1; loadHistory(root); }
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
                if (!panel.hidden) { state[root.id].page = 1; loadHistory(root); }
            });
        });
    };

    return {
        init: context => {
            const root = document.getElementById(context.uniqid);
            if (!root) { return; }
            state[root.id] = { context, page: 1, perpage: 10, last: null };
            bind(root);
        }
    };
});
