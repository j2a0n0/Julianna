(function () {
    'use strict';

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    async function request(url, method, data) {
        var response = await fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken()
            },
            body: JSON.stringify(data || {})
        });
        var body;
        try {
            body = await response.json();
        } catch (error) {
            body = {};
        }
        if (!response.ok) {
            throw new Error(body.error || body.message || '');
        }
        return body;
    }

    function feedback(element, message, isError) {
        if (!element) { return; }
        element.textContent = message || '';
        element.hidden = !message;
        element.classList.toggle('is-error', Boolean(isError));
    }

    function initIndex(root) {
        var form = root.querySelector('#idea-room-create-form');
        if (!form || root.dataset.initialized === '1') { return; }
        root.dataset.initialized = '1';
        var button = form.querySelector('button[type="submit"]');
        var result = root.querySelector('#idea-room-create-feedback');
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!form.reportValidity() || button.disabled) { return; }
            var original = button.textContent;
            var projectId = form.querySelector('[name="projectId"]').value;
            var payload = { idea: form.querySelector('[name="idea"]').value.trim() };
            if (projectId) { payload.projectId = Number(projectId); }
            if (!payload.idea) { return; }
            button.disabled = true;
            button.textContent = root.dataset.creating;
            feedback(result, '', false);
            try {
                var created = await request(root.dataset.createUrl, 'POST', payload);
                var roomId = Number(created.room && created.room.id);
                if (!Number.isInteger(roomId) || roomId < 1) { throw new Error(root.dataset.error); }
                window.location.assign(root.dataset.createUrl + '/' + roomId);
            } catch (error) {
                feedback(result, error.message || root.dataset.error, true);
                button.disabled = false;
                button.textContent = original;
            }
        });
    }

    function normalizePlan(value) {
        var source = value && typeof value === 'object' && !Array.isArray(value) ? value : {};
        var normalizeTask = function (task) {
            return {
                title: String(task && task.title || ''),
                description: String(task && task.description || '')
            };
        };
        return {
            projectName: String(source.projectName || ''),
            outcome: String(source.outcome || ''),
            milestones: Array.isArray(source.milestones) ? source.milestones.map(function (milestone) {
                return {
                    title: String(milestone && milestone.title || ''),
                    description: String(milestone && milestone.description || ''),
                    tasks: Array.isArray(milestone && milestone.tasks) ? milestone.tasks.map(normalizeTask) : []
                };
            }) : [],
            tasks: Array.isArray(source.tasks) ? source.tasks.map(normalizeTask) : [],
            assumptions: Array.isArray(source.assumptions) ? source.assumptions.map(String) : [],
            openQuestions: Array.isArray(source.openQuestions) ? source.openQuestions.map(String) : []
        };
    }

    function childItems(container) {
        return Array.from(container.children).filter(function (child) {
            return child.classList.contains('idea-room-edit-item');
        });
    }

    function initRoom(root) {
        if (root.dataset.initialized === '1') { return; }
        root.dataset.initialized = '1';
        var planScript = document.getElementById('idea-room-initial-plan');
        var plan;
        try {
            plan = normalizePlan(JSON.parse(planScript ? planScript.textContent : '{}'));
        } catch (error) {
            plan = normalizePlan({});
        }
        var canEdit = root.dataset.canEdit === '1';
        var canApprove = root.dataset.canApprove === '1';
        var status = root.dataset.status || 'active';
        var dirty = false;
        var form = root.querySelector('#idea-room-plan-form');
        var messageForm = root.querySelector('#idea-room-message-form');
        var approveButton = root.querySelector('#idea-room-approve');
        var saveButton = root.querySelector('#idea-room-save');
        var result = root.querySelector('#idea-room-feedback');
        var statusElement = root.querySelector('#idea-room-status');

        function setStatus(next) {
            status = next;
            root.dataset.status = status;
            if (statusElement) {
                statusElement.textContent = root.dataset['status' + status.replace(/(^|_)([a-z])/g, function (match, separator, letter) { return letter.toUpperCase(); })] || status;
                statusElement.className = 'idea-room-status idea-room-status--' + status;
            }
            if (approveButton) {
                approveButton.disabled = !canApprove || dirty || status !== 'ready_for_review';
            }
        }

        function markDirty() {
            if (!canEdit || status === 'approved' || status === 'archived') { return; }
            dirty = true;
            if (approveButton) { approveButton.disabled = true; }
            feedback(result, '', false);
        }

        function field(label, value, multiline) {
            var wrapper = document.createElement('label');
            wrapper.textContent = label;
            var input = document.createElement(multiline ? 'textarea' : 'input');
            if (!multiline) { input.type = 'text'; input.maxLength = 255; }
            input.value = value || '';
            input.readOnly = !canEdit;
            input.addEventListener('input', markDirty);
            wrapper.appendChild(input);
            return wrapper;
        }

        function item(kind, value) {
            var box = document.createElement('div');
            box.className = 'idea-room-edit-item';
            box.dataset.kind = kind;
            var head = document.createElement('div');
            head.className = 'idea-room-edit-item-head';
            var heading = document.createElement('strong');
            heading.textContent = kind === 'milestone' ? root.dataset.milestoneLabel :
                kind === 'task' ? root.dataset.taskLabel :
                    kind === 'assumption' ? root.querySelector('#idea-room-assumptions').parentElement.querySelector('h3').textContent :
                        root.querySelector('#idea-room-questions').parentElement.querySelector('h3').textContent;
            head.appendChild(heading);
            if (canEdit) {
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'idea-room-text-button';
                remove.dataset.remove = kind;
                remove.textContent = root.dataset.removeLabel;
                remove.setAttribute('aria-label', root.dataset.removeLabel + ' ' + heading.textContent);
                head.appendChild(remove);
            }
            box.appendChild(head);
            if (kind === 'assumption' || kind === 'question') {
                box.appendChild(field(heading.textContent, value, true));
            } else {
                box.appendChild(field(root.dataset.titleLabel, value.title, false));
                box.appendChild(field(root.dataset.descriptionLabel, value.description, true));
                if (kind === 'milestone') {
                    var taskHeading = document.createElement('div');
                    taskHeading.className = 'idea-room-section-heading';
                    var taskTitle = document.createElement('strong');
                    taskTitle.textContent = root.dataset.taskLabel;
                    taskHeading.appendChild(taskTitle);
                    if (canEdit) {
                        var addTask = document.createElement('button');
                        addTask.type = 'button';
                        addTask.className = 'idea-room-text-button';
                        addTask.dataset.add = 'milestone-task';
                        addTask.textContent = '+ ' + root.dataset.addTaskLabel;
                        taskHeading.appendChild(addTask);
                    }
                    box.appendChild(taskHeading);
                    var tasks = document.createElement('div');
                    tasks.className = 'idea-room-nested-tasks';
                    (value.tasks || []).forEach(function (task) { tasks.appendChild(item('task', task)); });
                    box.appendChild(tasks);
                }
            }
            return box;
        }

        function renderPlan() {
            var projectName = root.querySelector('#idea-room-project-name');
            if (projectName) { projectName.value = plan.projectName; }
            root.querySelector('#idea-room-outcome').value = plan.outcome;
            var milestones = root.querySelector('#idea-room-milestones');
            var tasks = root.querySelector('#idea-room-tasks');
            var assumptions = root.querySelector('#idea-room-assumptions');
            var questions = root.querySelector('#idea-room-questions');
            milestones.replaceChildren();
            tasks.replaceChildren();
            assumptions.replaceChildren();
            questions.replaceChildren();
            plan.milestones.forEach(function (value) { milestones.appendChild(item('milestone', value)); });
            plan.tasks.forEach(function (value) { tasks.appendChild(item('task', value)); });
            plan.assumptions.forEach(function (value) { assumptions.appendChild(item('assumption', value)); });
            plan.openQuestions.forEach(function (value) { questions.appendChild(item('question', value)); });
            dirty = false;
            setStatus(status);
        }

        function readItem(box) {
            var inputs = box.querySelectorAll(':scope > label > input, :scope > label > textarea');
            if (box.dataset.kind === 'assumption' || box.dataset.kind === 'question') {
                return inputs[0].value.trim();
            }
            var value = { title: inputs[0].value.trim(), description: inputs[1].value.trim() };
            if (box.dataset.kind === 'milestone') {
                var nested = box.querySelector('.idea-room-nested-tasks');
                value.tasks = childItems(nested).map(readItem);
            }
            return value;
        }

        function readPlan() {
            var projectName = root.querySelector('#idea-room-project-name');
            return {
                projectName: projectName ? projectName.value.trim() : plan.projectName,
                outcome: root.querySelector('#idea-room-outcome').value.trim(),
                milestones: childItems(root.querySelector('#idea-room-milestones')).map(readItem),
                tasks: childItems(root.querySelector('#idea-room-tasks')).map(readItem),
                assumptions: childItems(root.querySelector('#idea-room-assumptions')).map(readItem),
                openQuestions: childItems(root.querySelector('#idea-room-questions')).map(readItem)
            };
        }

        function appendMessage(message) {
            if (!message || !message.content) { return; }
            var list = root.querySelector('#idea-room-messages');
            var empty = list.querySelector('#idea-room-no-messages');
            if (empty) { empty.remove(); }
            var role = message.role === 'assistant' ? 'assistant' : 'user';
            var article = document.createElement('article');
            article.className = 'idea-room-message idea-room-message--' + role;
            var author = document.createElement('span');
            author.className = 'idea-room-message-author';
            author.textContent = role === 'assistant' ? root.dataset.assistantLabel : root.dataset.youLabel;
            var paragraph = document.createElement('p');
            paragraph.textContent = message.content;
            article.appendChild(author);
            article.appendChild(paragraph);
            list.appendChild(article);
            list.scrollTop = list.scrollHeight;
        }

        form.addEventListener('click', function (event) {
            var add = event.target.closest('[data-add]');
            var remove = event.target.closest('[data-remove]');
            if (!canEdit) { return; }
            if (remove) {
                remove.closest('.idea-room-edit-item').remove();
                markDirty();
            } else if (add) {
                var kind = add.dataset.add;
                var container = kind === 'milestone' ? root.querySelector('#idea-room-milestones') :
                    kind === 'task' ? root.querySelector('#idea-room-tasks') :
                        kind === 'assumption' ? root.querySelector('#idea-room-assumptions') :
                            kind === 'question' ? root.querySelector('#idea-room-questions') :
                                add.closest('.idea-room-edit-item').querySelector('.idea-room-nested-tasks');
                container.appendChild(item(kind === 'milestone-task' ? 'task' : kind, kind === 'assumption' || kind === 'question' ? '' : { title: '', description: '', tasks: [] }));
                markDirty();
                var firstField = container.lastElementChild.querySelector('input, textarea');
                if (firstField) { firstField.focus(); }
            }
        });

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!canEdit || saveButton.disabled) { return; }
            saveButton.disabled = true;
            saveButton.textContent = root.dataset.saving;
            feedback(result, '', false);
            try {
                var response = await request(root.dataset.planUrl, 'PUT', { plan: readPlan() });
                plan = normalizePlan(response.plan);
                status = response.status || 'ready_for_review';
                renderPlan();
                feedback(result, root.dataset.saved, false);
            } catch (error) {
                feedback(result, error.message || root.dataset.requestFailed, true);
            } finally {
                saveButton.disabled = false;
                saveButton.textContent = root.dataset.save;
            }
        });

        if (messageForm) {
            var messageInput = messageForm.querySelector('textarea');
            var sendButton = messageForm.querySelector('button[type="submit"]');
            messageForm.addEventListener('submit', async function (event) {
                event.preventDefault();
                if (!messageForm.reportValidity() || sendButton.disabled) { return; }
                if (dirty && !window.confirm(root.dataset.unsaved)) { return; }
                var content = messageInput.value.trim();
                if (!content) { return; }
                sendButton.disabled = true;
                sendButton.textContent = root.dataset.sending;
                feedback(result, '', false);
                try {
                    var response = await request(root.dataset.messagesUrl, 'POST', { content: content });
                    appendMessage(response.message || { role: 'user', content: content });
                    appendMessage(response.assistant);
                    messageInput.value = '';
                    plan = normalizePlan(response.plan);
                    status = response.status || 'active';
                    renderPlan();
                } catch (error) {
                    feedback(result, error.message || root.dataset.requestFailed, true);
                } finally {
                    sendButton.disabled = false;
                    sendButton.textContent = root.dataset.send;
                }
            });
        }

        if (approveButton) {
            approveButton.addEventListener('click', async function () {
                if (approveButton.disabled || !window.confirm(root.dataset.confirmApprove)) { return; }
                approveButton.disabled = true;
                approveButton.textContent = root.dataset.approving;
                feedback(result, '', false);
                try {
                    var response = await request(root.dataset.approveUrl, 'POST', {});
                    status = response.room && response.room.status || 'approved';
                    canEdit = false;
                    canApprove = false;
                    root.querySelectorAll('#idea-room-plan-form input, #idea-room-plan-form textarea').forEach(function (input) { input.readOnly = true; });
                    root.querySelectorAll('#idea-room-plan-form [data-add], #idea-room-plan-form [data-remove]').forEach(function (button) { button.hidden = true; });
                    if (messageForm) { messageForm.hidden = true; }
                    if (saveButton) { saveButton.hidden = true; }
                    approveButton.hidden = true;
                    setStatus(status);
                    feedback(result, root.dataset.approved, false);
                } catch (error) {
                    feedback(result, error.message || root.dataset.requestFailed, true);
                    setStatus(status);
                    approveButton.textContent = root.dataset.approveLabel;
                }
            });
        }

        renderPlan();
        var list = root.querySelector('#idea-room-messages');
        list.scrollTop = list.scrollHeight;
    }

    function init(root) {
        var scope = root || document;
        if (scope.matches && scope.matches('[data-idea-room-index]')) { initIndex(scope); }
        if (scope.matches && scope.matches('[data-idea-room]')) { initRoom(scope); }
        scope.querySelectorAll('[data-idea-room-index]').forEach(initIndex);
        scope.querySelectorAll('[data-idea-room]').forEach(initRoom);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }
    if (window.htmx && window.htmx.onLoad) {
        window.htmx.onLoad(init);
    }
}());
