(function () {
    'use strict';

    function labels() {
        var element = document.getElementById('julianna-agent-i18n');
        try { return JSON.parse(element ? element.textContent : '{}'); } catch (error) { return {}; }
    }

    function label(key) { return labels()[key] || key; }

    function visibleError(message) {
        if (!document.documentElement.lang.toLowerCase().startsWith('fr')) { return message || label('requestFailed'); }
        var known = {
            'A server-side AI provider is not configured.': label('providerUnavailable'),
            'You are not allowed to perform this action.': label('notAllowed'),
            'The item could not be found.': label('notFound'),
            'This conversation already has a turn in progress.': label('alreadyWorking')
        };
        return known[message] || label('requestFailed');
    }

    function element(tag, className, content) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (content !== undefined) { node.textContent = String(content); }
        return node;
    }

    function empty(container, message) {
        container.replaceChildren(element('p', 'agent-empty', message));
    }

    function feedback(node, message, success) {
        if (!node) { return; }
        node.textContent = message || '';
        node.hidden = !message;
        node.classList.toggle('is-success', Boolean(success));
    }

    async function requestJson(url, method, payload) {
        var csrf = document.querySelector('meta[name="csrf-token"]');
        var response = await fetch(url, {
            method: method || 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf ? csrf.content : ''
            },
            body: method && method !== 'GET' ? JSON.stringify(payload || {}) : undefined
        });
        var result = {};
        try { result = await response.json(); } catch (error) { /* Treat malformed responses as failures. */ }
        if (!response.ok) { throw new Error(visibleError(result.error || result.message)); }
        return result;
    }

    function requestId() {
        if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
        return 'request-' + Date.now() + '-' + Math.random().toString(36).slice(2);
    }

    // Render a deliberately small Markdown subset with text nodes only. Model
    // output is untrusted, so never hand it to innerHTML or the page's rich
    // Markdown renderer (which also supports raw HTML).
    function appendInline(container, source) {
        var pattern = /\*\*([^*\n]+)\*\*|`([^`\n]+)`|\*([^*\n]+)\*/g;
        var position = 0;
        var match;
        while ((match = pattern.exec(source)) !== null) {
            if (match.index > position) { container.appendChild(document.createTextNode(source.slice(position, match.index))); }
            var tag = match[1] !== undefined ? 'strong' : match[2] !== undefined ? 'code' : 'em';
            container.appendChild(element(tag, '', match[1] || match[2] || match[3]));
            position = pattern.lastIndex;
        }
        if (position < source.length) { container.appendChild(document.createTextNode(source.slice(position))); }
    }

    function renderAssistantBody(container, content) {
        var text = String(content || '').replace(/\r\n?/g, '\n').trim();
        text = text.replace(/^(?:\*\*)?Julianna(?:\*\*)?\s*\n+/i, '');
        text = text.replace(/(?:\*\*)?NEEDS_INPUT:(?:\*\*)?\s*/g, '');
        var lines = text.split('\n');
        var index = 0;
        while (index < lines.length) {
            var line = lines[index].trim();
            if (!line) { index++; continue; }
            var heading = line.match(/^#{1,3}\s+(.+)$/);
            if (heading) {
                var title = element('h4', 'agent-turn-heading');
                appendInline(title, heading[1]);
                container.appendChild(title); index++; continue;
            }
            var listMatch = line.match(/^([-*]|\d+\.)\s+(.+)$/);
            if (listMatch) {
                var ordered = /\d/.test(listMatch[1]);
                var list = element(ordered ? 'ol' : 'ul', 'agent-turn-list');
                while (index < lines.length) {
                    var item = lines[index].trim().match(/^([-*]|\d+\.)\s+(.+)$/);
                    if (!item || /\d/.test(item[1]) !== ordered) { break; }
                    var child = element('li');
                    appendInline(child, item[2]);
                    list.appendChild(child); index++;
                }
                container.appendChild(list); continue;
            }
            var paragraph = [];
            while (index < lines.length && lines[index].trim()
                && !/^#{1,3}\s+/.test(lines[index].trim())
                && !/^([-*]|\d+\.)\s+/.test(lines[index].trim())) {
                paragraph.push(lines[index].trim()); index++;
            }
            var node = element('p');
            appendInline(node, paragraph.join(' '));
            container.appendChild(node);
        }
    }

    function renderTurns(container, turns, className) {
        container.replaceChildren();
        (turns || []).forEach(function (turn) {
            if (!turn || (turn.role !== 'user' && turn.role !== 'assistant')) { return; }
            var item = element('article', className + (turn.role === 'user' ? ' is-user' : ''));
            item.appendChild(element('strong', '', turn.role === 'user' ? label('you') : label('assistant')));
            var body = element('div', 'agent-turn-body');
            if (turn.role === 'user') { body.textContent = turn.content || ''; }
            else { renderAssistantBody(body, turn.content); }
            item.appendChild(body);
            container.appendChild(item);
        });
        if (!container.children.length) { empty(container, label('conversationEmpty')); }
        container.scrollTop = container.scrollHeight;
    }

    function renderEntries(container, records, none, renderer) {
        container.replaceChildren();
        if (!Array.isArray(records) || !records.length) { empty(container, none); return; }
        records.forEach(function (record) { container.appendChild(renderer(record)); });
    }

    function initCommandCenter(root) {
        var base = root.dataset.baseUrl || '';
        var projectId = Number(root.dataset.projectId || 0);
        var providerConfigured = root.dataset.providerConfigured === '1';
        var currentConversationId = 0;
        var busy = false;
        var pendingRequestId = '';
        var turns = document.getElementById('agent-turns');
        var form = document.getElementById('agent-compose');
        var message = document.getElementById('agent-message');
        var send = document.getElementById('agent-send');
        var notice = document.getElementById('agent-feedback');
        var selector = document.getElementById('agent-conversation-select');
        var status = document.getElementById('agent-status');

        function setStatus(text, kind) {
            status.lastChild.textContent = text;
            status.classList.remove('is-active', 'is-paused', 'is-unavailable');
            if (kind) { status.classList.add('is-' + kind); }
        }

        async function loadConversation(id) {
            if (!id) { currentConversationId = 0; selector.value = ''; empty(turns, label('conversationEmpty')); return; }
            var result = await requestJson(base + '/agent/api/conversations/' + id, 'GET');
            currentConversationId = Number(result.conversation && result.conversation.id) || 0;
            selector.value = String(currentConversationId);
            renderTurns(turns, result.turns, 'agent-turn');
        }

        async function loadConversations(preferredId) {
            var query = projectId ? '?projectId=' + projectId : '';
            var result = await requestJson(base + '/agent/api/conversations' + query, 'GET');
            var conversations = (Array.isArray(result.conversations) ? result.conversations : []).filter(function (conversation) {
                return Number(conversation.projectId || 0) === projectId;
            });
            selector.replaceChildren(new Option(label('newConversation'), ''));
            conversations.forEach(function (conversation) {
                selector.add(new Option(conversation.title || ('#' + conversation.id), String(conversation.id)));
            });
            var chosen = preferredId || currentConversationId || (conversations[0] && conversations[0].id);
            if (chosen && conversations.some(function (conversation) { return Number(conversation.id) === Number(chosen); })) {
                await loadConversation(chosen);
            } else { await loadConversation(0); }
        }

        function activityItem(activity) {
            var item = element('article', 'agent-list-item');
            var summary = activity.status === 'completed' && labels()['activity_' + activity.action]
                ? label('activity_' + activity.action) : activity.status === 'draft'
                    ? label('activityDraft') : activity.status === 'failed'
                        ? label('activityFailed') : activity.status === 'pending'
                            ? label('activityPending') : (activity.outcome || activity.action || label('done'));
            item.appendChild(element('strong', '', summary));
            var reason = activity.rationale === 'Requested in the agent conversation.'
                ? label('activityRequested') : activity.rationale === 'Routine project review selected this permitted action.'
                    ? label('activityUpkeep') : activity.rationale;
            if (reason) { item.appendChild(element('p', '', reason)); }
            item.appendChild(element('small', '', [activity.createdAt, activity.status ? label('status_' + activity.status) : ''].filter(Boolean).join(' · ')));
            if (projectId && activity.status === 'completed' && activity.recoveryAvailable) {
                var actions = element('div', 'agent-list-actions');
                var undo = element('button', 'btn btn-default', label('undo'));
                undo.type = 'button';
                undo.addEventListener('click', async function () {
                    undo.disabled = true;
                    try { await requestJson(base + '/agent/api/activities/' + Number(activity.id) + '/undo', 'POST', { projectId: projectId }); await loadProjectState(); }
                    catch (error) { feedback(notice, error.message, false); undo.disabled = false; }
                });
                actions.appendChild(undo);
                item.appendChild(actions);
            }
            return item;
        }

        function draftItem(draft) {
            var item = element('article', 'agent-list-item');
            item.appendChild(element('strong', '', draft.title || draft.subject || draft.reason || label('drafts')));
            var argumentsText = draft.arguments && typeof draft.arguments === 'object' ? JSON.stringify(draft.arguments, null, 2) : '';
            if (argumentsText) { item.appendChild(element('pre', 'agent-draft-arguments', argumentsText)); }
            item.appendChild(element('small', '', draft.status === 'publishing'
                ? label('publishingUncertain') : draft.status === 'failed'
                    ? label('status_failed') : (draft.createdAt || '')));
            if (draft.id && (draft.status === 'draft' || draft.status === 'failed')) {
                var actions = element('div', 'agent-list-actions');
                (draft.status === 'draft'
                    ? [['publish', label('publish')], ['discard', label('discard')]]
                    : [['discard', label('discard')]]).forEach(function (action) {
                    var button = element('button', 'btn btn-default', action[1]);
                    button.type = 'button';
                    button.addEventListener('click', async function () {
                        button.disabled = true;
                        try { await requestJson(base + '/agent/api/drafts/' + Number(draft.id) + '/' + action[0], 'POST', {}); await loadProjectState(); }
                        catch (error) { feedback(notice, error.message, false); button.disabled = false; }
                    });
                    actions.appendChild(button);
                });
                item.appendChild(actions);
            }
            return item;
        }

        function questionItem(question) {
            var item = element('article', 'agent-list-item');
            var questionBody = element('div', 'agent-turn-body');
            renderAssistantBody(questionBody, question.question || question.content || question.text || '');
            item.appendChild(questionBody);
            if (question.reason) { item.appendChild(element('p', '', question.reason)); }
            if (question.conversationId) {
                var button = element('button', 'agent-text-button', label('reply'));
                button.type = 'button';
                button.addEventListener('click', async function () {
                    try { await loadConversation(Number(question.conversationId)); message.focus(); }
                    catch (error) { feedback(notice, error.message, false); }
                });
                item.appendChild(button);
            }
            return item;
        }

        function runItem(run) {
            var item = element('article', 'agent-list-item');
            var summary = run.status === 'completed' ? label('reviewCompleted')
                : run.status === 'failed' ? label('reviewFailed')
                    : run.status === 'needs_setup' ? label('reviewNeedsSetup') : (run.summary || run.trigger || '');
            item.appendChild(element('strong', '', summary));
            item.appendChild(element('small', '', [run.createdAt, run.status ? label('status_' + run.status) : ''].filter(Boolean).join(' · ')));
            return item;
        }

        async function loadProjectState() {
            if (!projectId) {
                setStatus(providerConfigured ? label('ready') : label('unavailable'), providerConfigured ? 'active' : 'unavailable');
                var unscoped = await requestJson(base + '/agent/api/questions', 'GET');
                renderEntries(document.getElementById('agent-questions'), unscoped.questions, label('noQuestions'), questionItem);
                return;
            }
            var result = await requestJson(base + '/agent/api/projects/' + projectId, 'GET');
            var settings = result.settings || {};
            providerConfigured = Boolean(result.providerConfigured);
            setStatus(!providerConfigured ? label('unavailable') : !settings.enabled ? label('off') : settings.paused ? label('paused') : label('ready'),
                !providerConfigured || !settings.enabled ? 'unavailable' : settings.paused ? 'paused' : 'active');
            document.getElementById('agent-enable').hidden = Boolean(settings.enabled);
            document.getElementById('agent-pause').hidden = !settings.enabled || Boolean(settings.paused);
            document.getElementById('agent-resume').hidden = !settings.enabled || !settings.paused;
            document.getElementById('agent-enable').disabled = !providerConfigured;
            document.getElementById('agent-resume').disabled = !providerConfigured;
            renderEntries(document.getElementById('agent-activities'), result.activities, label('noActivities'), activityItem);
            renderEntries(document.getElementById('agent-runs'), result.runs, label('noRuns'), runItem);
            renderEntries(document.getElementById('agent-drafts'), result.drafts, label('noDrafts'), draftItem);
            renderEntries(document.getElementById('agent-questions'), result.questions, label('noQuestions'), questionItem);
        }

        [['agent-enable', true, false], ['agent-pause', true, true], ['agent-resume', true, false]].forEach(function (action) {
            var button = document.getElementById(action[0]);
            button.addEventListener('click', async function () {
                button.disabled = true;
                try { await requestJson(base + '/agent/api/projects/' + projectId + '/settings', 'POST', { enabled: action[1], paused: action[2] }); await loadProjectState(); feedback(notice, '', false); }
                catch (error) { feedback(notice, error.message, false); }
                button.disabled = false;
            });
        });

        document.getElementById('agent-project-select').addEventListener('change', function (event) {
            window.location.href = base + (event.target.value ? '/agent/projects/' + encodeURIComponent(event.target.value) : '/agent');
        });
        document.getElementById('agent-new-conversation').addEventListener('click', function () { loadConversation(0); message.focus(); });
        selector.addEventListener('change', function () { loadConversation(Number(selector.value)).catch(function (error) { feedback(notice, error.message, false); }); });
        message.addEventListener('input', function () { pendingRequestId = ''; });
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            var content = message.value.trim();
            if (busy || !content) { return; }
            busy = true; send.disabled = true; feedback(notice, label('working'), true);
            try {
                if (!currentConversationId) {
                    var created = await requestJson(base + '/agent/api/conversations', 'POST', { projectId: projectId || null });
                    currentConversationId = Number(created.conversation && created.conversation.id) || 0;
                    if (!currentConversationId) { throw new Error(label('requestFailed')); }
                }
                pendingRequestId = pendingRequestId || requestId();
                await requestJson(base + '/agent/api/conversations/' + currentConversationId + '/turns', 'POST', { message: content, clientRequestId: pendingRequestId });
                pendingRequestId = '';
                message.value = '';
                await loadConversations(currentConversationId);
                await loadProjectState();
                feedback(notice, label('done'), true);
            } catch (error) { feedback(notice, error.message, false); }
            busy = false; send.disabled = false;
        });
        Promise.all([loadConversations(0), loadProjectState()]).catch(function (error) {
            setStatus(label('unavailable'), 'unavailable'); feedback(notice, error.message, false);
        });
    }

    function initDrawer(drawer) {
        var base = drawer.dataset.baseUrl || '';
        var launcher = document.getElementById('julianna-agent-launcher');
        var scrim = document.getElementById('julianna-agent-scrim');
        var close = document.getElementById('julianna-agent-close');
        var select = document.getElementById('julianna-agent-scope');
        var turns = document.getElementById('julianna-agent-turns');
        var form = document.getElementById('julianna-agent-form');
        var message = document.getElementById('julianna-agent-message');
        var notice = document.getElementById('julianna-agent-feedback');
        var conversationId = 0;
        var busy = false;
        var pendingRequestId = '';

        function closeDrawer() {
            drawer.hidden = true; scrim.hidden = true; launcher.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = ''; launcher.focus();
        }

        async function openDrawer() {
            drawer.hidden = false; scrim.hidden = false; launcher.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden'; message.focus();
            conversationId = 0;
            pendingRequestId = '';
            empty(turns, label('conversationEmpty'));
            try {
                var context = await requestJson(base + '/agent/ui/context', 'GET');
                select.replaceChildren(new Option(label('allAccessible'), ''));
                (context.projects || []).forEach(function (project) { select.add(new Option(project.name, String(project.id))); });
                select.value = context.projectId ? String(context.projectId) : '';
                document.getElementById('julianna-agent-page').textContent = label('pageContext') + ': ' + (context.pagePath || '/');
                var stored = Number(window.sessionStorage.getItem('julianna-agent-conversation-' + (select.value || 'none')) || 0);
                if (stored) {
                    var loaded = await requestJson(base + '/agent/api/conversations/' + stored, 'GET');
                    if (Number(loaded.conversation && loaded.conversation.projectId || 0) === Number(select.value || 0)) {
                        conversationId = stored; renderTurns(turns, loaded.turns, 'agent-drawer-turn');
                    }
                }
            } catch (error) { feedback(notice, error.message, false); }
        }

        launcher.addEventListener('click', openDrawer);
        close.addEventListener('click', closeDrawer);
        scrim.addEventListener('click', closeDrawer);
        document.addEventListener('keydown', function (event) { if (!drawer.hidden && event.key === 'Escape') { closeDrawer(); } });
        select.addEventListener('change', function () { conversationId = 0; pendingRequestId = ''; empty(turns, label('conversationEmpty')); });
        message.addEventListener('input', function () { pendingRequestId = ''; });
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            var content = message.value.trim();
            if (busy || !content) { return; }
            busy = true; var send = form.querySelector('[type="submit"]'); send.disabled = true;
            feedback(notice, label('working'), true);
            try {
                if (!conversationId) {
                    var created = await requestJson(base + '/agent/api/conversations', 'POST', { projectId: Number(select.value) || null });
                    conversationId = Number(created.conversation && created.conversation.id) || 0;
                    if (!conversationId) { throw new Error(label('requestFailed')); }
                    window.sessionStorage.setItem('julianna-agent-conversation-' + (select.value || 'none'), String(conversationId));
                }
                pendingRequestId = pendingRequestId || requestId();
                await requestJson(base + '/agent/api/conversations/' + conversationId + '/turns', 'POST', { message: content, clientRequestId: pendingRequestId });
                pendingRequestId = '';
                var loaded = await requestJson(base + '/agent/api/conversations/' + conversationId, 'GET');
                renderTurns(turns, loaded.turns, 'agent-drawer-turn');
                message.value = ''; feedback(notice, '', true);
            } catch (error) { feedback(notice, error.message, false); }
            busy = false; send.disabled = false;
        });
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { renderAssistantBody: renderAssistantBody, renderTurns: renderTurns };
    }

    if (typeof document !== 'undefined') { document.addEventListener('DOMContentLoaded', function () {
        var command = document.querySelector('[data-agent-command-center]');
        if (command) { initCommandCenter(command); }
        var drawer = document.getElementById('julianna-agent-drawer');
        if (drawer) { initDrawer(drawer); }
    }); }
})();
