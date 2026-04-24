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
 * Phase 7_6f — key changes:
 *   1. renderHistoryItem() AJAX call is REMOVED entirely.
 *   2. History items are rendered on the server (get_history returns
 *      item.renderedhtml) and injected directly via innerHTML — no
 *      extra round-trip, no MUST_EXIST race, no typing-indicator flash.
 *   3. typesetMath() uses the centralised mathjax_helper module.
 *
 * @module     block_ai_assistant_v2/history
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax        from 'core/ajax';
import typesetMath from 'block_ai_assistant_v2/mathjax_helper';

// ─── Selectors ────────────────────────────────────────────────────────────────

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

let rootEl    = null;
let courseId  = 0;
let page      = 0;
let totalPages = 1;

// ─── Render a single history item from pre-fetched data ───────────────────────

/**
 * Inject pre-rendered HTML from item.renderedhtml into answerNode.
 *
 * No AJAX call is made here — the HTML was rendered on the server
 * inside get_history::execute() using render_helper::render().
 *
 * @param {HTMLElement} answerNode  The collapsible answer div.
 * @param {Object}      item        History item object from get_history.
 */
const renderFromItem = (answerNode, item) => {
    const html = (item.renderedhtml || '').trim();
    answerNode.innerHTML = html
        ? html
        : '<em style="color:var(--bs-secondary)">(No response stored.)</em>';

    // Phase 7_6f: MathJax 3.x — typeset after innerHTML is set.
    typesetMath(answerNode);
};

// ─── Build the history list DOM ───────────────────────────────────────────────

/**
 * Render the list of history cards into the history panel.
 *
 * @param {Array}  items       Array of history item objects.
 */
const renderList = (items) => {
    const panel   = rootEl.querySelector(SEL.HISTORY_PANEL);
    const listEl  = panel ? panel.querySelector(SEL.HISTORY_LIST) : null;
    if (!listEl) {
        return;
    }

    listEl.innerHTML = '';

    if (!items || items.length === 0) {
        listEl.innerHTML =
            '<p class="text-muted p-2">No history found.</p>';
        return;
    }

    items.forEach((item) => {
        const card = document.createElement('div');
        card.classList.add('blockaiassistantv2-historycard');
        card.dataset.historyid = item.id;

        // Question row (acts as accordion toggle).
        const qNode = document.createElement('div');
        qNode.classList.add('blockaiassistantv2-historyq');
        qNode.style.cursor = 'pointer';
        qNode.textContent = item.usertext || '(no question)';

        // Answer row (hidden by default, expanded on click).
        const aNode = document.createElement('div');
        aNode.classList.add('blockaiassistantv2-historya');
        aNode.hidden = true;
        aNode.dataset.rendered = '0';

        // Click: toggle answer panel and inject HTML on first open.
        qNode.addEventListener('click', () => {
            aNode.hidden = !aNode.hidden;
            if (!aNode.hidden && aNode.dataset.rendered === '0') {
                aNode.dataset.rendered = '1';
                // Phase 7_6f: inline render — zero AJAX, no typing flash.
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
 * @param {boolean} generalOnly  True = cross-course history.
 */
const loadHistory = (generalOnly = false) => {
    const panel  = rootEl.querySelector(SEL.HISTORY_PANEL);
    const listEl = panel ? panel.querySelector(SEL.HISTORY_LIST) : null;
    if (listEl) {
        listEl.innerHTML =
            '<p class="text-muted p-2"><i class="fa fa-spinner fa-spin"></i> Loading…</p>';
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
        renderList(result.items || [], {courseid: courseId});
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

/**
 * Update pagination button states and page-info label.
 */
const updatePagination = () => {
    const panel    = rootEl.querySelector(SEL.HISTORY_PANEL);
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
 * Bind history panel controls. Called from widget.js after the block root is
 * located, or directly from the Mustache template.
 *
 * @param {HTMLElement} blockRootEl  The .blockaiassistantv2-root element.
 * @param {number}      cId         Course ID.
 */
const init = (blockRootEl, cId) => {
    rootEl   = blockRootEl;
    courseId = cId;

    const panel = rootEl.querySelector(SEL.HISTORY_PANEL);
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

    // Previous page button.
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

    // Next page button.
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

    // Load first page automatically when the panel becomes visible.
    // MutationObserver watches for `hidden` attribute removal.
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
