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
 * History panel for block_ai_assistant_v2.
 *
 * Phase 7_6i — full cross-analysis against main.mustache.
 *
 * All history.js selectors verified against main.mustache. No selector
 * changes were needed; the only fix is init() accepting the context object
 * from js_call_amd (Phase 7_6h fix retained).
 *
 * Phase 7_6f features retained:
 *   - renderHistoryItem() AJAX removed — server pre-renders via get_history.
 *   - typesetMath() uses centralised mathjax_helper module.
 *
 * DOM contract (from main.mustache):
 *   #{{uniqid}}
 *   └── [data-region="history-panel"]      <aside>
 *       ├── [data-action="refresh-history"] button
 *       ├── [data-region="history-general"] <input type="checkbox">
 *       ├── [data-region="history-list"]    list container
 *       ├── [data-action="history-prev"]    prev page button
 *       ├── [data-region="history-pageinfo"] page label
 *       └── [data-action="history-next"]    next page button
 *
 * @module     block_ai_assistant_v2/history
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax        from 'core/ajax';
import typesetMath from 'block_ai_assistant_v2/mathjax_helper';

// ─── Selectors — VERIFIED against main.mustache ──────────────────────────────

const SEL = {
    HISTORY_PANEL:   '[data-region="history-panel"]',
    HISTORY_LIST:    '[data-region="history-list"]',
    REFRESH_BTN:     '[data-action="refresh-history"]',
    GENERAL_ONLY_CB: '[data-region="history-general"]',
    PREV_BTN:        '[data-action="history-prev"]',
    NEXT_BTN:        '[data-action="history-next"]',
    PAGE_INFO:       '[data-region="history-pageinfo"]',
};

// ─── Module state ─────────────────────────────────────────────────────────────

let rootWrapper = null;   // #{{uniqid}} div
let courseId    = 0;
let page        = 0;
let totalPages  = 1;

// ─── Render a single history item from pre-fetched data ───────────────────────

/**
 * Inject pre-rendered HTML from item.renderedhtml into answerNode.
 * No AJAX — HTML was rendered on the server inside get_history::execute().
 *
 * @param {HTMLElement} answerNode
 * @param {Object}      item  History item from get_history web service.
 */
const renderFromItem = (answerNode, item) => {
    const html = (item.renderedhtml || '').trim();
    answerNode.innerHTML = html
        ? html
        : '<em style="color:var(--bs-secondary)">(No response stored.)</em>';
    typesetMath(answerNode);
};

// ─── Build the history list DOM ───────────────────────────────────────────────

/**
 * Render history cards into the history panel list element.
 *
 * @param {Array} items  Array of history item objects from get_history.
 */
const renderList = (items) => {
    const panel  = rootWrapper.querySelector(SEL.HISTORY_PANEL);
    const listEl = panel ? panel.querySelector(SEL.HISTORY_LIST) : null;
    if (!listEl) {
        return;
    }

    listEl.innerHTML = '';

    if (!items || items.length === 0) {
        listEl.innerHTML = '<p class="text-muted p-2">No history found.</p>';
        return;
    }

    items.forEach((item) => {
        const card  = document.createElement('div');
        card.classList.add('block_ai_assistant_v2-historycard');
        card.dataset.historyid = item.id;

        // Question row — acts as accordion toggle.
        const qNode = document.createElement('div');
        qNode.classList.add('block_ai_assistant_v2-historyq');
        qNode.style.cursor = 'pointer';
        qNode.textContent = item.usertext || '(no question)';

        // Answer row — hidden by default, expanded on click.
        const aNode = document.createElement('div');
        aNode.classList.add('block_ai_assistant_v2-historya');
        aNode.hidden = true;
        aNode.dataset.rendered = '0';

        qNode.addEventListener('click', () => {
            aNode.hidden = !aNode.hidden;
            if (!aNode.hidden && aNode.dataset.rendered === '0') {
                aNode.dataset.rendered = '1';
                renderFromItem(aNode, item);
            }
        });

        card.appendChild(qNode);
        card.appendChild(aNode);
        listEl.appendChild(card);
    });
};

// ─── Fetch history from server ────────────────────────────────────────────────

/**
 * Load one page of history from the get_history web service.
 *
 * @param {boolean} generalOnly  true = cross-course history.
 */
const loadHistory = (generalOnly = false) => {
    const panel  = rootWrapper.querySelector(SEL.HISTORY_PANEL);
    const listEl = panel ? panel.querySelector(SEL.HISTORY_LIST) : null;
    if (listEl) {
        listEl.innerHTML =
            '<p class="text-muted p-2"><i class="fa fa-spinner fa-spin"></i> Loading\u2026</p>';
    }

    Ajax.call([{
        methodname: 'block_ai_assistant_v2_get_history',
        args: {
            courseid:    courseId,
            generalonly: generalOnly,
            page:        page,
            perpage:     10,
        },
    }])[0]
    .then((result) => {
        totalPages = result.totalpages || 1;
        updatePagination();
        renderList(result.items || []);
        return result;
    })
    .catch((err) => {
        if (listEl) {
            listEl.innerHTML =
                '<p class="text-danger p-2">Failed to load history.</p>';
        }
        // eslint-disable-next-line no-console
        window.console.error('[AI Assistant] loadHistory error:', err);
    });
};

// ─── Pagination ───────────────────────────────────────────────────────────────

const updatePagination = () => {
    const panel = rootWrapper.querySelector(SEL.HISTORY_PANEL);
    if (!panel) {
        return;
    }
    const prevBtn  = panel.querySelector(SEL.PREV_BTN);
    const nextBtn  = panel.querySelector(SEL.NEXT_BTN);
    const pageInfo = panel.querySelector(SEL.PAGE_INFO);

    if (prevBtn) {
        prevBtn.disabled = (page <= 0);
    }
    if (nextBtn) {
        nextBtn.disabled = (page >= totalPages - 1);
    }
    if (pageInfo) {
        pageInfo.textContent = `${page + 1} / ${totalPages}`;
    }
};

// ─── Initialisation ───────────────────────────────────────────────────────────

/**
 * Initialise the history panel.
 *
 * Phase 7_6i: accepts context object from js_call_amd (same as widget.js).
 *
 * @param {Object} ctx  Context object from block_ai_assistant_v2.php.
 */
const init = (ctx) => {
    if (!ctx || typeof ctx !== 'object') {
        return;
    }

    courseId = parseInt(ctx.courseid, 10) || 0;
    const uniqid = ctx.uniqid || '';

    rootWrapper = uniqid ? document.getElementById(uniqid) : null;
    if (!rootWrapper) {
        return;
    }

    const panel = rootWrapper.querySelector(SEL.HISTORY_PANEL);
    if (!panel) {
        return;
    }

    // Refresh button.
    const refreshBtn = panel.querySelector(SEL.REFRESH_BTN);
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            page = 0;
            const cb = panel.querySelector(SEL.GENERAL_ONLY_CB);
            loadHistory(cb ? cb.checked : false);
        });
    }

    // General-only checkbox.
    const generalCb = panel.querySelector(SEL.GENERAL_ONLY_CB);
    if (generalCb) {
        generalCb.addEventListener('change', () => {
            page = 0;
            loadHistory(generalCb.checked);
        });
    }

    // Previous page.
    const prevBtn = panel.querySelector(SEL.PREV_BTN);
    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            if (page > 0) {
                page--;
                const cb = panel.querySelector(SEL.GENERAL_ONLY_CB);
                loadHistory(cb ? cb.checked : false);
            }
        });
    }

    // Next page.
    const nextBtn = panel.querySelector(SEL.NEXT_BTN);
    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            if (page < totalPages - 1) {
                page++;
                const cb = panel.querySelector(SEL.GENERAL_ONLY_CB);
                loadHistory(cb ? cb.checked : false);
            }
        });
    }

    // Auto-load when the panel is first revealed (hidden attr removed).
    const observer = new MutationObserver(() => {
        if (!panel.hidden) {
            page = 0;
            const cb = panel.querySelector(SEL.GENERAL_ONLY_CB);
            loadHistory(cb ? cb.checked : false);
            observer.disconnect();
        }
    });
    observer.observe(panel, {attributes: true, attributeFilter: ['hidden']});
};

export {init};
