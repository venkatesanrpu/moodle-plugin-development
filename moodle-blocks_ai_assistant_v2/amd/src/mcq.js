// mcq.js — Phase 7_6j
//
// FIX BUG 4 (double-toggle): Removed ALL toggle-mcq button binding from here.
//   widget.js is the sole owner of panel show/hide.
//   mcq.js only handles: "Generate MCQ" button click → fetch → render.
//
// @module block_ai_assistant_v2/mcq

define(['core/ajax', 'block_ai_assistant_v2/mathjax_helper'], function(Ajax, typesetMath) {

    let shellEl = null;
    let courseId = 0;

    // ── Build the dropdown row HTML ───────────────────────────────────────────

    const buildControls = () => {
        const panelEl = shellEl.querySelector('[data-region="mcq-panel"]');
        if (!panelEl || panelEl.dataset.controlsBuilt === '1') {
            return;
        }
        panelEl.dataset.controlsBuilt = '1';

        // Controls container.
        const controls = document.createElement('div');
        controls.className = 'block_ai_assistant_v2-mcq-controls';

        // --- Count dropdown ---
        const countLabel  = document.createElement('label');
        countLabel.textContent = 'Questions: ';
        const countSelect = document.createElement('select');
        countSelect.dataset.region = 'mcq-count';
        [5, 10, 15, 20].forEach((n) => {
            const opt       = document.createElement('option');
            opt.value       = n;
            opt.textContent = n;
            if (n === 10) {
                opt.selected = true;
            }
            countSelect.appendChild(opt);
        });
        countLabel.appendChild(countSelect);

        // --- Difficulty dropdown ---
        const diffLabel  = document.createElement('label');
        diffLabel.style.marginLeft = '12px';
        diffLabel.textContent = 'Difficulty: ';
        const diffSelect = document.createElement('select');
        diffSelect.dataset.region = 'mcq-difficulty';
        ['Basic', 'Intermediate', 'Advanced'].forEach((d) => {
            const opt       = document.createElement('option');
            opt.value       = d.toLowerCase();
            opt.textContent = d;
            if (d === 'Basic') {
                opt.selected = true;
            }
            diffSelect.appendChild(opt);
        });
        diffLabel.appendChild(diffSelect);

        // --- Generate button ---
        const genBtn = document.createElement('button');
        genBtn.type             = 'button';
        genBtn.dataset.action   = 'generate-mcq';
        genBtn.textContent      = 'Generate';
        genBtn.className        = 'block_ai_assistant_v2-btn block_ai_assistant_v2-btn--primary';
        genBtn.style.marginLeft = '12px';

        controls.appendChild(countLabel);
        controls.appendChild(diffLabel);
        controls.appendChild(genBtn);

        // MCQ results area.
        const resultsArea = document.createElement('div');
        resultsArea.dataset.region = 'mcq-results';
        resultsArea.className      = 'block_ai_assistant_v2-mcq-results';

        panelEl.insertBefore(controls, panelEl.firstChild);
        panelEl.appendChild(resultsArea);

        // Bind generate button.
        genBtn.addEventListener('click', () => generateMcq(panelEl, countSelect, diffSelect));
    };

    // ── Generate MCQs via AJAX ────────────────────────────────────────────────

    const generateMcq = (panelEl, countSelect, diffSelect) => {
        const resultsArea = panelEl.querySelector('[data-region="mcq-results"]');
        if (!resultsArea) {
            return;
        }

        const count       = parseInt(countSelect.value, 10) || 10;
        const difficulty  = diffSelect.value || 'basic';
        const subjectSel  = shellEl.querySelector('[data-region="subject-select"]');
        const topicSel    = shellEl.querySelector('[data-region="topic-select"]');
        const lessonSel   = shellEl.querySelector('[data-region="lesson-select"]');

        const subject = subjectSel ? (subjectSel.value || '') : '';
        const topic   = topicSel   ? (topicSel.value   || '') : '';
        const lesson  = lessonSel  ? (lessonSel.value  || '') : '';

        resultsArea.innerHTML = '<p><em>Generating MCQs…</em></p>';

        Ajax.call([{
            methodname: 'block_ai_assistant_v2_generate_mcq',
            args: {
                courseid:   courseId,
                count:      count,
                difficulty: difficulty,
                subject:    subject,
                topic:      topic,
                lesson:     lesson,
            },
        }])[0]
        .then((res) => {
            if (res && res.html) {
                resultsArea.innerHTML = res.html;
                typesetMath(resultsArea);
            } else if (res && res.questions) {
                renderQuestions(resultsArea, res.questions);
            } else {
                resultsArea.innerHTML = '<p><em>No MCQs returned.</em></p>';
            }
            return res;
        })
        .catch((err) => {
            window.console.error('[AI Assistant MCQ] error:', err);
            resultsArea.innerHTML = '<p><em>Failed to generate MCQs. Please try again.</em></p>';
        });
    };

    // Fallback renderer if server returns raw questions array.
    const renderQuestions = (container, questions) => {
        container.innerHTML = '';
        questions.forEach((q, idx) => {
            const qEl       = document.createElement('div');
            qEl.className   = 'block_ai_assistant_v2-mcq-question';

            const qText     = document.createElement('p');
            qText.innerHTML = `<strong>Q${idx + 1}:</strong> ${q.question || ''}`;
            qEl.appendChild(qText);

            if (Array.isArray(q.options)) {
                const ul = document.createElement('ul');
                q.options.forEach((opt) => {
                    const li       = document.createElement('li');
                    li.textContent = opt;
                    ul.appendChild(li);
                });
                qEl.appendChild(ul);
            }

            container.appendChild(qEl);
        });
        typesetMath(container);
    };

    // ── init — called by block.php js_call_amd ─────────────────────────────────

    const init = (ctx) => {
        if (!ctx || typeof ctx !== 'object') {
            return;
        }

        courseId = parseInt(ctx.courseid, 10) || 0;

        const uniqid = ctx.uniqid || '';
        const root   = uniqid ? document.getElementById(uniqid) : null;
        if (!root) {
            return;
        }
        shellEl = root.querySelector('[data-region="chat-shell"]');
        if (!shellEl) {
            return;
        }

        // Build controls as soon as the MCQ panel becomes visible.
        // MutationObserver watches the panel's `hidden` attribute.
        const panelEl = shellEl.querySelector('[data-region="mcq-panel"]');
        if (panelEl) {
            const obs = new MutationObserver(() => {
                if (!panelEl.hidden) {
                    buildControls();
                }
            });
            obs.observe(panelEl, {attributes: true, attributeFilter: ['hidden']});

            // In case panel is already visible on init.
            if (!panelEl.hidden) {
                buildControls();
            }
        }
    };

    return {init};
});
