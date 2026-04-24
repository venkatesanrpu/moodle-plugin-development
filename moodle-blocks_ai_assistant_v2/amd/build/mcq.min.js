define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    const state = {};
    const q = (root, selector) => root.querySelector(selector);

    const setStatus = (root, text) => {
        const node = q(root, '[data-region="mcq-status"]');
        if (node) {
            node.textContent = text;
        }
    };

    const renderMcqs = (root, result) => {
        const body = q(root, '[data-region="mcq-body"]');
        body.innerHTML = '';
        (result.questions || []).forEach(question => {
            const card = document.createElement('article');
            card.className = 'block_ai_assistant_v2-mcqitem';
            const options = document.createElement('div');
            options.className = 'block_ai_assistant_v2-mcqoptions';

            (question.options || []).forEach(option => {
                const label = document.createElement('label');
                label.className = 'block_ai_assistant_v2-mcqoption';
                label.innerHTML = '<input type="radio" name="mcq-' + question.number + '" value="' + option.label + '"> <span><strong>' + option.label + '.</strong> ' + option.text + '</span>';
                options.appendChild(label);
            });

            const actions = document.createElement('div');
            actions.className = 'block_ai_assistant_v2-mcqactions';
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'block_ai_assistant_v2-toolbarbtn';
            button.textContent = 'Check answer';
            const feedback = document.createElement('div');
            feedback.className = 'block_ai_assistant_v2-mcqfeedback';
            button.addEventListener('click', () => {
                const checked = options.querySelector('input:checked');
                if (!checked) {
                    feedback.textContent = 'Select an option first.';
                    feedback.className = 'block_ai_assistant_v2-mcqfeedback';
                    return;
                }
                if ((checked.value || '').toUpperCase() === (question.answer || '').toUpperCase()) {
                    feedback.textContent = 'Correct. ' + (question.explanation || '');
                    feedback.className = 'block_ai_assistant_v2-mcqfeedback is-correct';
                } else {
                    feedback.textContent = 'Incorrect. Correct answer: ' + question.answer + '. ' + (question.explanation || '');
                    feedback.className = 'block_ai_assistant_v2-mcqfeedback is-wrong';
                }
            });
            actions.appendChild(button);
            actions.appendChild(feedback);

            card.innerHTML = '<div class="block_ai_assistant_v2-historymeta">Question ' + question.number + '</div>' +
                '<div class="block_ai_assistant_v2-historyq"></div>';
            card.querySelector('.block_ai_assistant_v2-historyq').textContent = question.question || '';
            card.appendChild(options);
            card.appendChild(actions);
            body.appendChild(card);
        });
    };

    const generate = root => {
        const ctx = state[root.id].context;
        const subject = q(root, '[data-region="subject-select"]').value || '';
        const topic = q(root, '[data-region="topic-select"]').value || '';
        const lesson = q(root, '[data-region="lesson-select"]').value || '';
        const count = Number(q(root, '[data-region="mcq-count"]').value || 10);
        const difficulty = q(root, '[data-region="mcq-difficulty"]').value || 'medium';
        setStatus(root, 'Generating MCQs...');
        return Ajax.call([{methodname: 'block_ai_assistant_v2_ask_mcq', args: {
            courseid: Number(ctx.courseid),
            blockinstanceid: Number(ctx.blockinstanceid),
            agentkey: ctx.agentkey,
            subject: subject,
            topic: topic,
            lesson: lesson,
            count: count,
            difficulty: difficulty
        }}])[0].then(result => {
            renderMcqs(root, result);
            setStatus(root, 'Generated ' + result.count + ' questions.');
        }).catch(error => {
            setStatus(root, 'MCQ generation failed.');
            Notification.exception(error);
        });
    };

    const bind = root => {
        const panel = q(root, '[data-region="mcq-panel"]');
        const toggle = q(root, '[data-action="toggle-mcq"]');
        const generateBtn = q(root, '[data-action="generate-mcq"]');
        toggle.addEventListener('click', () => {
            panel.hidden = !panel.hidden;
        });
        generateBtn.addEventListener('click', () => generate(root));
    };

    return {
        init: context => {
            const root = document.getElementById(context.uniqid);
            if (!root) {
                return;
            }
            state[root.id] = {context: context};
            bind(root);
        }
    };
});
