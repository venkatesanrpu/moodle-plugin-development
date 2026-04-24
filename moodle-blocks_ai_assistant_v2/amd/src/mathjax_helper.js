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
 * MathJax 3.x typesetting helper for block_ai_assistant_v2.
 *
 * Phase 7_6f — centralised typesetMath() used by widget.js, history.js,
 * and mcq.js.
 *
 * Why this is needed
 * ------------------
 * Moodle's filter_mathjaxloader loads MathJax 3.2.2 (tex-mml-chtml.js)
 * asynchronously via requirejs. Our AMD modules initialise in parallel, so
 * window.MathJax may not yet exist when content is first injected.
 *
 * Strategy (in priority order):
 *   1. If window.MathJax.typesetPromise exists → call it immediately.
 *   2. If window.MathJax.startup.promise exists → wait for it, then typeset.
 *   3. Otherwise → poll every 300 ms (up to 6 seconds) until MathJax appears.
 *
 * typesetClear() is called before each typeset so that re-rendered nodes
 * (e.g. history items clicked more than once) are processed again correctly.
 *
 * @module     block_ai_assistant_v2/mathjax_helper
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Typeset MathJax 3.x on a specific DOM node.
 *
 * Compatible with Moodle's filter_mathjaxloader (MathJax 3.2.2, tex-mml-chtml).
 * Safe to call multiple times on the same node (uses typesetClear first).
 *
 * @param {HTMLElement} node  Container element whose subtree should be typeset.
 * @returns {void}
 */
const typesetMath = (node) => {
    if (!node) {
        return;
    }

    /**
     * Attempt to typeset using the MathJax 3 API.
     * Returns true if MathJax was ready and the call was made.
     *
     * @returns {boolean}
     */
    const attempt = () => {
        if (
            window.MathJax &&
            typeof window.MathJax.typesetPromise === 'function'
        ) {
            // Clear "already processed" markers so re-injected content is re-typeset.
            if (typeof window.MathJax.typesetClear === 'function') {
                window.MathJax.typesetClear([node]);
            }
            window.MathJax.typesetPromise([node]).catch((err) => {
                // eslint-disable-next-line no-console
                window.console.warn('[AI Assistant] MathJax typesetPromise error:', err);
            });
            return true;
        }
        return false;
    };

    // Case 1: MathJax already initialised.
    if (attempt()) {
        return;
    }

    // Case 2: MathJax object exists but startup is still running.
    if (
        window.MathJax &&
        window.MathJax.startup &&
        window.MathJax.startup.promise
    ) {
        window.MathJax.startup.promise
            .then(() => {
                attempt();
            })
            .catch((err) => {
                // eslint-disable-next-line no-console
                window.console.warn('[AI Assistant] MathJax startup.promise rejected:', err);
            });
        return;
    }

    // Case 3: MathJax not yet present (CDN still loading) — poll.
    let attempts = 0;
    const maxAttempts = 20; // 20 × 300 ms = 6 seconds max wait.
    const intervalId = setInterval(() => {
        attempts++;
        if (attempt() || attempts >= maxAttempts) {
            clearInterval(intervalId);
        }
    }, 300);
};

export default typesetMath;
