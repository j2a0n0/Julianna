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
            body: method === 'GET' ? undefined : JSON.stringify(data || {})
        });
        var body;
        try {
            body = await response.json();
        } catch (error) {
            body = {};
        }
        if (!response.ok) {
            var failure = new Error(body.error || body.message || '');
            failure.status = response.status;
            throw failure;
        }
        return body;
    }

    function feedback(element, message, isError) {
        if (!element) { return; }
        element.textContent = message || '';
        element.hidden = !message;
        element.classList.toggle('is-error', Boolean(isError));
    }

    function scriptData(id, fallback) {
        var script = document.getElementById(id);
        if (!script) { return fallback; }
        try { return JSON.parse(script.textContent); } catch (error) { return fallback; }
    }

    function initTemporaryAi(root) {
        if (root.dataset.initialized === '1') { return; }
        root.dataset.initialized = '1';
        var form = root.querySelector('.idea-room-testing-ai-form');
        var provider = form.querySelector('[name="provider"]');
        var model = form.querySelector('[name="model"]');
        var key = form.querySelector('[name="apiKey"]');
        var result = root.querySelector('[data-testing-ai-feedback]');
        var clear = root.querySelector('[data-testing-ai-clear]');
        provider.addEventListener('change', function () {
            var suggested = { deepseek: 'deepseek-flash', kimi: 'kimi-k3' };
            if (!model.value || /^(deepseek-flash|deepseek-v4-pro|kimi-k3|kimi-k2\.6)$/.test(model.value)) {
                model.value = suggested[provider.value] || '';
            }
        });
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!form.reportValidity()) { return; }
            var submit = form.querySelector('[type="submit"]');
            submit.disabled = true;
            feedback(result, '', false);
            try {
                await request(root.dataset.configUrl, 'POST', {
                    provider: provider.value, model: model.value, apiKey: key.value
                });
                key.value = '';
                window.location.reload();
            } catch (error) {
                key.value = '';
                feedback(result, error.message, true);
                submit.disabled = false;
            }
        });
        if (clear) {
            clear.addEventListener('click', async function () {
                clear.disabled = true;
                feedback(result, '', false);
                try {
                    await request(root.dataset.configUrl, 'DELETE', {});
                    key.value = '';
                    window.location.reload();
                } catch (error) {
                    feedback(result, error.message, true);
                    clear.disabled = false;
                }
            });
        }
    }

    // Build a small Markdown subset with DOM nodes; provider text is never inserted as HTML.
    function inlineMarkdown(container, source) {
        var pattern = /\[([^\]]{1,200})\]\((https?:\/\/[^\s)]+)\)|\*\*([^*]+)\*\*|\*([^*]+)\*|`([^`]+)`/g;
        var offset = 0;
        var match;
        while ((match = pattern.exec(source)) !== null) {
            if (match.index > offset) { container.appendChild(document.createTextNode(source.slice(offset, match.index))); }
            var node;
            if (match[1] && match[2]) {
                node = document.createElement('a');
                node.textContent = match[1];
                node.href = match[2];
                node.target = '_blank';
                node.rel = 'noopener noreferrer';
            } else {
                node = document.createElement(match[3] ? 'strong' : match[4] ? 'em' : 'code');
                node.textContent = match[3] || match[4] || match[5] || '';
            }
            container.appendChild(node);
            offset = pattern.lastIndex;
        }
        if (offset < source.length) { container.appendChild(document.createTextNode(source.slice(offset))); }
    }

    function markdown(container, source) {
        container.replaceChildren();
        var lines = String(source || '').split(/\r?\n/);
        var index = 0;
        while (index < lines.length) {
            if (!lines[index].trim()) { index++; continue; }
            if (/^```/.test(lines[index])) {
                index++;
                var code = [];
                while (index < lines.length && !/^```/.test(lines[index])) { code.push(lines[index++]); }
                if (index < lines.length) { index++; }
                var pre = document.createElement('pre');
                var codeNode = document.createElement('code');
                codeNode.textContent = code.join('\n');
                pre.appendChild(codeNode);
                container.appendChild(pre);
                continue;
            }
            if (/^\s*[-*] /.test(lines[index])) {
                var list = document.createElement('ul');
                while (index < lines.length && /^\s*[-*] /.test(lines[index])) {
                    var item = document.createElement('li');
                    inlineMarkdown(item, lines[index++].replace(/^\s*[-*] /, ''));
                    list.appendChild(item);
                }
                container.appendChild(list);
                continue;
            }
            var paragraph = document.createElement('p');
            var text = [];
            while (index < lines.length && lines[index].trim() && !/^```|^\s*[-*] /.test(lines[index])) { text.push(lines[index++]); }
            inlineMarkdown(paragraph, text.join(' '));
            container.appendChild(paragraph);
        }
    }

    function parseSseFrame(frame) {
        var event = 'message';
        var id = '';
        var data = [];
        frame.split(/\r?\n/).forEach(function (line) {
            if (line.indexOf('event:') === 0) { event = line.slice(6).trim(); }
            if (line.indexOf('id:') === 0) { id = line.slice(3).trim(); }
            if (line.indexOf('data:') === 0) { data.push(line.slice(5).trimStart()); }
        });
        if (!data.length) { return null; }
        try { return { event: event, id: id, data: JSON.parse(data.join('\n')) }; }
        catch (error) { return null; }
    }

    async function readSse(response, onEvent) {
        var type = response.headers.get('Content-Type') || '';
        if (!response.ok) {
            var errorBody = await response.json().catch(function () { return {}; });
            throw new Error(errorBody.error || errorBody.message || '');
        }
        if (type.indexOf('text/event-stream') === -1 || !response.body) {
            var body = await response.json();
            onEvent({ event: 'legacy.response', id: '', data: body });
            return;
        }
        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';
        try {
            while (true) {
                var part = await reader.read();
                if (part.done) { break; }
                buffer += decoder.decode(part.value, { stream: true });
                var boundary;
                while ((boundary = buffer.search(/\r?\n\r?\n/)) >= 0) {
                    var frame = buffer.slice(0, boundary);
                    buffer = buffer.slice(boundary).replace(/^\r?\n\r?\n/, '');
                    var parsed = parseSseFrame(frame);
                    if (parsed) { onEvent(parsed); }
                }
            }
            buffer += decoder.decode();
            var tail = parseSseFrame(buffer.trim());
            if (tail) { onEvent(tail); }
        } finally {
            reader.releaseLock();
        }
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
                window.location.assign(root.dataset.createUrl + '/' + roomId + '?autostart=1');
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
        var plan = normalizePlan(scriptData('idea-room-initial-plan', {}));
        var planVersion = Number(root.dataset.planVersion) || 0;
        var labels = scriptData('idea-room-labels', {});
        var initialMessages = scriptData('idea-room-initial-messages', []);
        var initialActions = scriptData('idea-room-initial-actions', []);
        var canEdit = root.dataset.canEdit === '1';
        var canChat = root.dataset.canChat === '1';
        var status = root.dataset.status || 'active';
        var dirty = false;
        var lastEventId = 0;
        var activeRequest = null;
        var lastContent = '';
        var streaming = false;
        var form = root.querySelector('#idea-room-plan-form');
        var messageForm = root.querySelector('#idea-room-message-form');
        var approveButton = root.querySelector('#idea-room-approve');
        var archiveButton = root.querySelector('#idea-room-archive');
        var saveButton = root.querySelector('#idea-room-save');
        var result = root.querySelector('#idea-room-feedback');
        var statusElement = root.querySelector('#idea-room-status');
        var messagesElement = root.querySelector('#idea-room-messages');
        var pendingElement = root.querySelector('#idea-room-pending-actions');
        var contextSelect = root.querySelector('#idea-room-project-context');
        var presenceElement = root.querySelector('#idea-room-presence');
        var streamState = root.querySelector('#idea-room-stream-state');
        var chatError = root.querySelector('#idea-room-chat-error');
        var chatErrorText = root.querySelector('#idea-room-chat-error-text');
        var retryButton = root.querySelector('#idea-room-retry');
        var stopButton = root.querySelector('#idea-room-stop');
        var sendButton = root.querySelector('#idea-room-send');
        var canEditGraph = root.dataset.canEditGraph === '1';
        var graphEnabled = root.dataset.graphEnabled === '1';
        var searchConfigured = root.dataset.searchConfigured === '1';
        var graph = { version: 0, nodes: [], links: [], mode: 'explore' };
        var selectedNodeId = null;
        var connectSourceId = null;
        var connecting = false;
        var graphSaving = false;
        var canvasPan = { x: 220, y: 180, scale: 1 };
        var canvasStorageKey = 'julianna-idea-canvas-' + root.dataset.roomId;
        var viewRestored = false;
        try {
            var storedView = JSON.parse(window.localStorage.getItem(canvasStorageKey) || 'null');
            if (storedView && Number.isFinite(storedView.x) && Number.isFinite(storedView.y) && Number.isFinite(storedView.scale)) {
                canvasPan = { x: storedView.x, y: storedView.y, scale: Math.min(2.5, Math.max(.4, storedView.scale)) };
                viewRestored = true;
            }
        } catch (error) { /* Canvas still works when local storage is unavailable. */ }
        var canvasPointer = null;
        var suppressCanvasClick = false;
        var graphLoaded = false;
        var canvasViewport = root.querySelector('#idea-room-canvas-viewport');
        var canvasWorld = root.querySelector('#idea-room-canvas-world');
        var canvasNodes = root.querySelector('#idea-room-canvas-nodes');
        var canvasLinks = root.querySelector('#idea-room-canvas-links');
        var canvasStatus = root.querySelector('#idea-room-canvas-status');
        var nodeForm = root.querySelector('#idea-room-node-form');
        var researchResults = root.querySelector('#idea-room-research-results');
        var historyContainer = root.querySelector('#idea-room-history');
        var historyRefreshTimer = null;
        var historyRequestSequence = 0;
        var historyRestoring = false;
        var recoverableNodes = [];
        var recoverableContainer = root.querySelector('#idea-room-recoverable-nodes');
        var agentStatus = root.querySelector('#idea-room-agent-status');
        var agentStatusDetail = root.querySelector('#idea-room-agent-status-detail');
        var agentStateElement = root.querySelector('#idea-room-agent-state');
        var agentSummary = root.querySelector('#idea-room-agent-summary');
        var agentActivity = root.querySelector('#idea-room-agent-activity');
        var agentRuns = root.querySelector('#idea-room-agent-runs');
        var agentDrafts = root.querySelector('#idea-room-agent-drafts');
        var agentNeedsInput = root.querySelector('#idea-room-needs-input');
        var enableAgent = root.querySelector('#idea-room-agent-enable');
        var pauseAgent = root.querySelector('#idea-room-agent-pause');
        var agentSettingsFeedback = root.querySelector('#idea-room-agent-settings-feedback');
        var agentSettings = null;
        var agentRequestSequence = 0;
        var agentRefreshTimer = null;
        var activeView = 'overview';
        var ideasActivated = false;

        function currentProjectId() {
            var value = contextSelect ? contextSelect.value : root.dataset.projectId;
            var id = Number(value);
            return Number.isInteger(id) && id > 0 ? id : 0;
        }

        function agentUrl() {
            var projectId = currentProjectId();
            return projectId ? String(root.dataset.agentUrlTemplate || '').replace('{projectId}', String(projectId)) : '';
        }

        function humanTime(value) {
            if (!value) { return ''; }
            var date = new Date(String(value));
            return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString(document.documentElement.lang || undefined);
        }

        function sentence(template, replacements) {
            return String(template || '').replace(/:([a-zA-Z]+)/g, function (match, key) {
                return Object.prototype.hasOwnProperty.call(replacements, key) ? String(replacements[key]) : match;
            });
        }

        function emptyNote(message) {
            var p = document.createElement('p');
            p.className = 'idea-room-muted'; p.textContent = message;
            return p;
        }

        function setAgentStatus(kind, title, detail) {
            if (!agentStatus) { return; }
            agentStateElement.dataset.state = kind;
            agentStatus.textContent = title;
            agentStatusDetail.textContent = detail;
        }

        function setCommandView(view) {
            var allowed = ['overview', 'activity', 'drafts', 'ideas'];
            activeView = allowed.indexOf(view) >= 0 ? view : 'overview';
            root.dataset.view = activeView;
            root.querySelectorAll('[data-command-view]').forEach(function (tab) {
                var selected = tab.dataset.commandView === activeView;
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.tabIndex = selected ? 0 : -1;
            });
            allowed.forEach(function (name) {
                var panel = root.querySelector('#idea-room-view-' + name);
                if (panel) { panel.hidden = name !== activeView; }
            });
            var inspector = root.querySelector('.idea-room-sidebar');
            if (inspector) { inspector.hidden = activeView !== 'ideas'; }
            var overviewAside = root.querySelector('.idea-room-overview-aside');
            if (overviewAside) { overviewAside.hidden = activeView !== 'overview'; }
            if (activeView === 'ideas' && !ideasActivated) {
                ideasActivated = true;
                window.requestAnimationFrame(function () { if (!viewRestored) { centerCanvas(); } else { applyCanvasTransform(); } });
            }
            if (activeView === 'activity' || activeView === 'drafts') { refreshAgent(); }
        }

        function renderAgentSettings(settings) {
            agentSettings = settings;
            var projectId = currentProjectId();
            if (!projectId) {
                setAgentStatus('no-project', labels.agentSelectProject, labels.agentSelectProjectHint);
            } else if (!settings) {
                setAgentStatus('unavailable', labels.agentUnavailable, labels.agentUnavailableHint);
            } else if (!settings.enabled) {
                setAgentStatus('off', labels.agentOff, labels.agentOffHint);
            } else if (settings.paused) {
                setAgentStatus('paused', labels.agentPaused, labels.agentPausedHint);
            } else {
                setAgentStatus('active', labels.agentActive, labels.agentActiveHint);
            }
            if (enableAgent) {
                enableAgent.disabled = !projectId || !settings || !canEdit;
                enableAgent.textContent = settings && settings.enabled ? labels.agentDisable : labels.agentEnable;
            }
            if (pauseAgent) {
                pauseAgent.disabled = !projectId || !settings || !settings.enabled || !canEdit;
                pauseAgent.textContent = settings && settings.paused ? labels.agentResume : labels.agentPause;
            }
        }

        function renderAgentActivity(activities) {
            if (!agentActivity) { return; }
            agentActivity.replaceChildren();
            var list = Array.isArray(activities) ? activities : [];
            var completed = list.filter(function (entry) {
                return /^(completed|success|succeeded|undone)$/.test(String(entry.status || '').toLowerCase());
            });
            if (agentSummary) {
                agentSummary.textContent = completed.length
                    ? sentence(labels.agentSummaryCount, { count: completed.length, action: String(completed[0].action || completed[0].toolName || '') })
                    : labels.agentSummaryEmpty;
            }
            if (!list.length) { agentActivity.appendChild(emptyNote(labels.activityEmpty)); return; }
            list.forEach(function (entry) {
                var card = document.createElement('article'); card.className = 'idea-room-agent-activity';
                var status = String(entry.status || 'completed').toLowerCase();
                card.dataset.status = status;
                var heading = document.createElement('div'); heading.className = 'idea-room-agent-activity-heading';
                var title = document.createElement('h3');
                var action = String(entry.action || entry.toolName || entry.title || labels.agentAction);
                title.textContent = labels.toolNames && labels.toolNames[action] || action;
                var badge = document.createElement('span'); badge.className = 'idea-room-agent-activity-badge';
                badge.textContent = entry.undoneAt ? labels.activityUndone : status === 'failed' ? labels.toolFailed : labels.toolSuccess;
                heading.appendChild(title); heading.appendChild(badge); card.appendChild(heading);
                var rationale = entry.rationale || entry.reason;
                if (rationale) {
                    var why = document.createElement('p'); why.className = 'idea-room-agent-activity-reason';
                    why.textContent = sentence(labels.activityWhy, { reason: String(rationale) }); card.appendChild(why);
                }
                if (entry.outcome) {
                    var outcome = document.createElement('p'); outcome.className = 'idea-room-agent-activity-outcome';
                    outcome.textContent = String(entry.outcome); card.appendChild(outcome);
                }
                var footer = document.createElement('div'); footer.className = 'idea-room-agent-activity-footer';
                var time = document.createElement('time'); time.textContent = humanTime(entry.createdAt);
                if (entry.createdAt) { time.dateTime = String(entry.createdAt); } footer.appendChild(time);
                if ((entry.recoveryAvailable || entry.undoable) && !entry.undoneAt && entry.id && canEdit) {
                    var undo = document.createElement('button'); undo.type = 'button'; undo.className = 'idea-room-text-button';
                    undo.dataset.undoActivity = String(entry.id); undo.textContent = labels.activityUndo;
                    footer.appendChild(undo);
                }
                card.appendChild(footer); agentActivity.appendChild(card);
            });
        }

        function renderAgentRuns(runs) {
            if (!agentRuns) { return; }
            agentRuns.replaceChildren();
            var list = Array.isArray(runs) ? runs : [];
            if (!list.length) { return; }
            var heading = document.createElement('h3'); heading.textContent = labels.reviewRuns; agentRuns.appendChild(heading);
            list.slice(0, 4).forEach(function (run) {
                var row = document.createElement('div'); row.className = 'idea-room-run';
                var state = document.createElement('strong');
                var status = String(run.status || '');
                state.textContent = labels.runStatuses && labels.runStatuses[status] || status;
                var reason = document.createElement('span');
                reason.textContent = (labels.runTriggers && labels.runTriggers[run.trigger] || String(run.trigger || ''))
                    + (run.summary ? ' · ' + String(run.summary) : '');
                var time = document.createElement('time'); time.textContent = humanTime(run.startedAt || run.createdAt);
                row.appendChild(state); row.appendChild(reason); row.appendChild(time); agentRuns.appendChild(row);
            });
        }

        function renderDrafts(drafts) {
            if (!agentDrafts) { return; }
            agentDrafts.replaceChildren();
            var list = Array.isArray(drafts) ? drafts : [];
            var count = root.querySelector('#idea-room-draft-count');
            if (count) { count.hidden = !list.length; count.textContent = String(list.length); }
            if (!list.length) { agentDrafts.appendChild(emptyNote(labels.draftsEmpty)); return; }
            list.forEach(function (draft) {
                var card = document.createElement('article'); card.className = 'idea-room-draft-card';
                var heading = document.createElement('h3');
                heading.textContent = String(draft.title || draft.summary || (labels.toolNames && labels.toolNames[draft.toolName || draft.tool_name]) || labels.draftCommunication);
                card.appendChild(heading);
                var args = draft.arguments || draft.args || {};
                if (typeof args === 'string') {
                    try { args = JSON.parse(args); } catch (error) { args = {}; }
                }
                var draftText = draft.body || draft.text || draft.content || args.text || args.comment || args.description || '';
                if (draftText) {
                    var body = document.createElement('p'); body.className = 'idea-room-draft-body';
                    body.textContent = String(draftText); card.appendChild(body);
                }
                if (draft.reason || draft.rationale) {
                    var reason = document.createElement('p'); reason.className = 'idea-room-muted';
                    reason.textContent = sentence(labels.activityWhy, { reason: String(draft.reason || draft.rationale) }); card.appendChild(reason);
                }
                var time = document.createElement('time'); time.textContent = humanTime(draft.generatedAt || draft.createdAt || draft.created_at);
                card.appendChild(time);
                var actionId = Number(draft.actionId || draft.action_id || draft.id);
                var roomId = Number(draft.roomId || draft.room_id || root.dataset.roomId);
                if (Number.isInteger(actionId) && actionId > 0 && Number.isInteger(roomId) && roomId > 0) {
                    card.dataset.draftId = String(actionId);
                    card.dataset.draftRoomId = String(roomId);
                    var actions = document.createElement('div'); actions.className = 'idea-room-draft-actions';
                    var publish = document.createElement('button'); publish.type = 'button'; publish.className = 'btn btn-primary';
                    publish.dataset.draftAction = 'publish'; publish.textContent = labels.draftPublish;
                    var discard = document.createElement('button'); discard.type = 'button'; discard.className = 'btn btn-default';
                    discard.dataset.draftAction = 'discard'; discard.textContent = labels.draftDiscard;
                    actions.appendChild(publish); actions.appendChild(discard); card.appendChild(actions);
                }
                agentDrafts.appendChild(card);
            });
        }

        function renderNeedsInput(questions, drafts) {
            if (!agentNeedsInput) { return; }
            agentNeedsInput.replaceChildren();
            var list = Array.isArray(questions) ? questions : [];
            list.slice(0, 3).forEach(function (question) {
                var p = document.createElement('p'); p.className = 'idea-room-input-question';
                p.textContent = String(typeof question === 'string' ? question : question.question || question.text || '');
                if (p.textContent) { agentNeedsInput.appendChild(p); }
            });
            var draftCount = Array.isArray(drafts) ? drafts.length : 0;
            if (draftCount) {
                var open = document.createElement('button'); open.type = 'button'; open.className = 'idea-room-text-button';
                open.dataset.openCommandView = 'drafts';
                open.textContent = sentence(labels.needsDrafts, { count: draftCount }); agentNeedsInput.appendChild(open);
            }
            if (!agentNeedsInput.children.length) { agentNeedsInput.appendChild(emptyNote(labels.needsEmpty)); }
        }

        async function refreshAgent() {
            var url = agentUrl();
            var sequence = ++agentRequestSequence;
            if (!url) {
                renderAgentSettings(null); renderAgentActivity([]); renderAgentRuns([]); renderDrafts([]); renderNeedsInput([], []);
                return;
            }
            try {
                var response = await request(url, 'GET');
                if (sequence !== agentRequestSequence) { return; }
                renderAgentSettings(response.settings || null);
                renderAgentActivity(response.activities || []);
                renderAgentRuns(response.runs || []);
                renderDrafts(response.drafts || []);
                renderNeedsInput(response.questions || [], response.drafts || []);
                if (Array.isArray(response.staleItems)) { renderPending(response.staleItems); }
            } catch (error) {
                if (sequence !== agentRequestSequence) { return; }
                renderAgentSettings(null);
                if (agentSummary) { agentSummary.textContent = labels.agentSummaryUnavailable; }
                if (agentActivity) { agentActivity.replaceChildren(emptyNote(error.message || labels.agentUnavailableHint)); }
                if (agentDrafts) { agentDrafts.replaceChildren(emptyNote(labels.draftsUnavailable)); }
                renderNeedsInput([], []);
            }
        }

        function queueAgentRefresh() {
            if (agentRefreshTimer) { window.clearTimeout(agentRefreshTimer); }
            agentRefreshTimer = window.setTimeout(function () { agentRefreshTimer = null; refreshAgent(); }, 350);
        }

        function graphKey(record) { return String(record && (record.id || record.clientId || '') || ''); }

        function graphEndpoint(value) {
            return /^\d+$/.test(String(value)) ? Number(value) : value;
        }

        function newClientId() {
            return 'c-' + (window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : Date.now() + '-' + Math.random().toString(36).slice(2));
        }

        function normalizeGraph(value) {
            var source = value && value.graph ? value.graph : value || {};
            return {
                version: Number(source.version) || 0,
                mode: source.mode === 'execute' ? 'execute' : 'explore',
                nodes: (Array.isArray(source.nodes) ? source.nodes : []).map(function (node) {
                    return {
                        id: node.id || undefined, clientId: node.clientId || undefined,
                        type: String(node.type || 'idea'), title: String(node.title || ''),
                        content: String(node.content || ''), x: Number(node.x) || 0,
                        y: Number(node.y) || 0, metadata: node.metadata && typeof node.metadata === 'object' ? node.metadata : {}
                    };
                }),
                links: (Array.isArray(source.links) ? source.links : []).map(function (link) {
                    return {
                        id: link.id || undefined, clientId: link.clientId || undefined,
                        sourceId: link.sourceId || link.source_id || link.from_node_id,
                        targetId: link.targetId || link.target_id || link.to_node_id,
                        type: String(link.type || 'related_to')
                    };
                })
            };
        }

        function setGraphNotice(message, error) {
            if (!canvasStatus) { return; }
            canvasStatus.textContent = message || '';
            canvasStatus.classList.toggle('is-error', Boolean(error));
        }

        function setMode(mode) {
            graph.mode = mode === 'execute' ? 'execute' : 'explore';
            root.dataset.mode = graph.mode;
            root.querySelectorAll('.idea-room-mode-button').forEach(function (button) {
                button.setAttribute('aria-pressed', button.dataset.mode === graph.mode ? 'true' : 'false');
            });
            var hint = root.querySelector('#idea-room-mode-hint');
            if (hint) { hint.textContent = graph.mode === 'execute' ? labels.modeExecuteHint : labels.modeExploreHint; }
        }

        function applyCanvasTransform() {
            canvasWorld.style.transform = 'translate(' + canvasPan.x + 'px,' + canvasPan.y + 'px) scale(' + canvasPan.scale + ')';
            var zoom = root.querySelector('#idea-room-zoom-label');
            if (zoom) { zoom.textContent = Math.round(canvasPan.scale * 100) + '%'; }
            try { window.localStorage.setItem(canvasStorageKey, JSON.stringify(canvasPan)); } catch (error) { /* Optional view preference. */ }
        }

        function graphNode(id) {
            return graph.nodes.find(function (node) { return graphKey(node) === String(id); });
        }

        function renderInspector() {
            var node = graphNode(selectedNodeId);
            nodeForm.hidden = !node;
            root.querySelector('#idea-room-node-empty').hidden = Boolean(node);
            if (!node) { return; }
            root.querySelector('#idea-room-node-type').value = node.type;
            root.querySelector('#idea-room-node-title').value = node.title;
            root.querySelector('#idea-room-node-content').value = node.content;
            var reference = node.metadata && node.metadata.reference;
            var referenceValue = reference && reference.type && reference.id ? reference.type + ':' + reference.id : '';
            var referenceSelect = root.querySelector('#idea-room-node-reference');
            referenceSelect.value = referenceValue;
            if (referenceSelect.value !== referenceValue) { referenceSelect.value = ''; }
            nodeForm.querySelectorAll('input, textarea, select, button').forEach(function (control) { control.disabled = !canEditGraph; });
            var links = root.querySelector('#idea-room-node-links'); links.replaceChildren();
            graph.links.filter(function (link) { return String(link.sourceId) === String(selectedNodeId) || String(link.targetId) === String(selectedNodeId); }).forEach(function (link) {
                var other = graphNode(String(link.sourceId) === String(selectedNodeId) ? link.targetId : link.sourceId);
                if (!other) { return; }
                var row = document.createElement('div'); row.className = 'idea-room-inspector-link';
                var text = document.createElement('span'); text.textContent = (labels.linkTypes && labels.linkTypes[link.type] || link.type) + ' · ' + other.title;
                row.appendChild(text);
                if (canEditGraph) {
                    var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'idea-room-text-button';
                    remove.dataset.removeLink = graphKey(link); remove.textContent = labels.removeConnection;
                    row.appendChild(remove);
                }
                links.appendChild(row);
            });
        }

        function renderGraph() {
            canvasNodes.replaceChildren();
            canvasLinks.replaceChildren();
            var markerId = 'idea-room-arrow-' + root.dataset.roomId;
            var defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
            var marker = document.createElementNS('http://www.w3.org/2000/svg', 'marker');
            marker.setAttribute('id', markerId); marker.setAttribute('viewBox', '0 0 10 10');
            marker.setAttribute('refX', '9'); marker.setAttribute('refY', '5');
            marker.setAttribute('markerWidth', '7'); marker.setAttribute('markerHeight', '7');
            marker.setAttribute('orient', 'auto-start-reverse');
            var arrow = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            arrow.setAttribute('d', 'M 0 0 L 10 5 L 0 10 z'); arrow.setAttribute('fill', 'var(--accent1)');
            marker.appendChild(arrow); defs.appendChild(marker); canvasLinks.appendChild(defs);
            var filter = root.querySelector('#idea-room-node-filter').value;
            var visible = graph.nodes.filter(function (node) { return filter === 'all' || node.type === filter; });
            var visibleKeys = new Set(visible.map(graphKey));
            graph.links.forEach(function (link) {
                var source = graphNode(link.sourceId);
                var target = graphNode(link.targetId);
                if (!source || !target || !visibleKeys.has(graphKey(source)) || !visibleKeys.has(graphKey(target))) { return; }
                var sx = source.x + 92, sy = source.y + 39;
                var tx = target.x + 92, ty = target.y + 39;
                var distance = Math.max(1, Math.hypot(tx - sx, ty - sy));
                var ux = (tx - sx) / distance, uy = (ty - sy) / distance;
                var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                line.setAttribute('x1', sx + ux * 55); line.setAttribute('y1', sy + uy * 25);
                line.setAttribute('x2', tx - ux * 75); line.setAttribute('y2', ty - uy * 32);
                line.setAttribute('marker-end', 'url(#' + markerId + ')');
                canvasLinks.appendChild(line);
                var label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                label.setAttribute('x', (source.x + target.x) / 2 + 92);
                label.setAttribute('y', (source.y + target.y) / 2 + 34);
                label.setAttribute('text-anchor', 'middle');
                label.textContent = labels.linkTypes && labels.linkTypes[link.type] || link.type;
                canvasLinks.appendChild(label);
            });
            visible.forEach(function (node) {
                var button = document.createElement('button');
                button.type = 'button'; button.className = 'idea-room-canvas-node';
                button.dataset.nodeId = graphKey(node); button.dataset.type = node.type;
                button.style.left = node.x + 'px'; button.style.top = node.y + 'px';
                if (graphKey(node) === String(selectedNodeId)) { button.classList.add('is-selected'); }
                if (graphKey(node) === String(connectSourceId)) { button.classList.add('is-connect-source'); }
                var type = document.createElement('small'); type.textContent = labels.nodeTypes && labels.nodeTypes[node.type] || node.type;
                var title = document.createElement('strong'); title.textContent = node.title || labels.newNode;
                button.appendChild(type); button.appendChild(title);
                if (node.content) { var content = document.createElement('p'); content.textContent = node.content; button.appendChild(content); }
                canvasNodes.appendChild(button);
            });
            root.querySelector('#idea-room-canvas-empty').hidden = Boolean(graph.nodes.length);
            root.querySelector('#idea-room-graph-version').textContent = labels.graphVersion + ' ' + graph.version;
            renderInspector();
        }

        function loadGraphResponse(response) {
            if (!response || !response.graph) { return; }
            graph = normalizeGraph(response.graph);
            if (selectedNodeId && !graphNode(selectedNodeId)) { selectedNodeId = null; }
            setMode(graph.mode);
            renderGraph();
            if (!graphLoaded) { graphLoaded = true; if (!viewRestored) { centerCanvas(); } else { applyCanvasTransform(); } }
            if (Array.isArray(response.recoverableNodes)) { renderRecoverableNodes(response.recoverableNodes); }
            if (Array.isArray(response.sources) && !researchResults.children.length) { renderResearch(response.sources); }
            if (Array.isArray(response.history)) {
                ++historyRequestSequence;
                if (historyRefreshTimer) { window.clearTimeout(historyRefreshTimer); historyRefreshTimer = null; }
                renderHistory(response.history);
            } else { queueHistoryRefresh(); }
        }

        async function refreshGraph() {
            if (!graphEnabled) { setGraphNotice(labels.graphUnavailable, true); return; }
            try { loadGraphResponse(await request(root.dataset.graphUrl, 'GET')); }
            catch (error) { setGraphNotice(error.message || labels.graphUnavailable, true); }
        }

        async function saveGraph(mutator, successMessage) {
            if (!canEditGraph || !graphEnabled || graphSaving) { return; }
            var before = JSON.parse(JSON.stringify(graph));
            mutator(graph);
            renderGraph();
            graphSaving = true;
            try {
                var response = await request(root.dataset.graphUrl, 'PUT', {
                    expectedVersion: before.version, nodes: graph.nodes, links: graph.links
                });
                loadGraphResponse(response);
                setGraphNotice(successMessage || labels.graphSaved, false);
                return true;
            } catch (error) {
                graph = before;
                if (error.status === 409) {
                    await refreshGraph();
                    setGraphNotice(labels.graphConflict, true);
                } else {
                    renderGraph();
                    setGraphNotice(error.message || labels.requestFailed, true);
                }
                return false;
            } finally { graphSaving = false; }
        }

        function selectNode(id) {
            selectedNodeId = id;
            renderGraph();
            if (canvasViewport) { canvasViewport.focus({ preventScroll: true }); }
        }

        function centerCanvas() {
            if (!graph.nodes.length) { canvasPan.x = canvasViewport.clientWidth / 2; canvasPan.y = canvasViewport.clientHeight / 2; }
            else {
                var x = graph.nodes.reduce(function (sum, node) { return sum + node.x; }, 0) / graph.nodes.length;
                var y = graph.nodes.reduce(function (sum, node) { return sum + node.y; }, 0) / graph.nodes.length;
                canvasPan.x = canvasViewport.clientWidth / 2 - (x + 92) * canvasPan.scale;
                canvasPan.y = canvasViewport.clientHeight / 2 - (y + 40) * canvasPan.scale;
            }
            applyCanvasTransform();
        }

        function zoomCanvas(factor, clientX, clientY) {
            var rect = canvasViewport.getBoundingClientRect();
            var px = clientX == null ? rect.width / 2 : clientX - rect.left;
            var py = clientY == null ? rect.height / 2 : clientY - rect.top;
            var old = canvasPan.scale;
            canvasPan.scale = Math.min(2.5, Math.max(.4, old * factor));
            canvasPan.x = px - (px - canvasPan.x) * canvasPan.scale / old;
            canvasPan.y = py - (py - canvasPan.y) * canvasPan.scale / old;
            applyCanvasTransform();
        }

        function safeExternalUrl(value) {
            try {
                var url = new URL(String(value || ''));
                return /^(https?:)$/.test(url.protocol) ? url.href : '';
            } catch (error) { return ''; }
        }

        function renderResearch(sources) {
            researchResults.replaceChildren();
            if (!Array.isArray(sources) || !sources.length) {
                var empty = document.createElement('p'); empty.className = 'idea-room-muted';
                empty.textContent = labels.researchEmpty; researchResults.appendChild(empty); return;
            }
            sources.forEach(function (source) {
                var card = document.createElement('article'); card.className = 'idea-room-research-card';
                var title = document.createElement('strong'); title.textContent = String(source.title || source.url || ''); card.appendChild(title);
                var domain = document.createElement('small'); domain.textContent = String(source.domain || source.provider || ''); card.appendChild(domain);
                if (source.snippet) { var snippet = document.createElement('p'); snippet.textContent = String(source.snippet); card.appendChild(snippet); }
                var actions = document.createElement('div'); actions.className = 'idea-room-research-card-actions';
                var url = safeExternalUrl(source.url);
                if (url) { var open = document.createElement('a'); open.href = url; open.target = '_blank'; open.rel = 'noopener noreferrer'; open.textContent = labels.openSource; actions.appendChild(open); }
                if (canEditGraph && source.id) {
                    var keep = document.createElement('button'); keep.type = 'button'; keep.className = 'idea-room-text-button';
                    keep.dataset.keepSource = String(source.id); keep.textContent = labels.keepSource; actions.appendChild(keep);
                }
                card.appendChild(actions); researchResults.appendChild(card);
            });
        }

        function renderHistory(entries) {
            if (!historyContainer) { return; }
            historyContainer.replaceChildren();
            if (!Array.isArray(entries) || !entries.length) {
                var empty = document.createElement('p'); empty.className = 'idea-room-muted'; empty.textContent = labels.historyEmpty;
                historyContainer.appendChild(empty); return;
            }
            entries.forEach(function (entry) {
                if (!entry || !Number.isInteger(Number(entry.id)) || Number(entry.id) < 1) { return; }
                var card = document.createElement('article'); card.className = 'idea-room-history-card';
                if (entry.isCurrent) { card.classList.add('is-current'); }
                var heading = document.createElement('div'); heading.className = 'idea-room-history-heading';
                var version = document.createElement('strong');
                version.textContent = labels.historyVersion + ' ' + Number(entry.graphVersion || 0)
                    + ' · ' + labels.historyPlanVersion + ' ' + Number(entry.planVersion || 0);
                heading.appendChild(version);
                var origin = document.createElement('span'); origin.className = 'idea-room-history-origin';
                var originKey = { ai: 'historyOriginAi', chat: 'historyOriginAi', mcp: 'historyOriginMcp', manual: 'historyOriginManual', restore: 'historyOriginRestore', initial: 'historyOriginInitial' }[entry.origin];
                origin.textContent = originKey ? labels[originKey] : String(entry.origin || labels.historyOriginManual);
                heading.appendChild(origin); card.appendChild(heading);
                if (entry.summary) {
                    var summary = document.createElement('p'); summary.className = 'idea-room-history-summary';
                    var summaryKey = {
                        'Initial state': 'historySummaryInitial', 'Canvas edited': 'historySummaryCanvasEdited',
                        'Canvas mode changed': 'historySummaryModeChanged', 'Canvas nodes restored': 'historySummaryNodesRestored',
                        'Plan edited': 'historySummaryPlanEdited', 'AI canvas change': 'historySummaryAiChange'
                    }[entry.summary];
                    summary.textContent = summaryKey ? labels[summaryKey] : /^Restored history #\d+$/.test(entry.summary)
                        ? labels.historySummaryRestored : String(entry.summary);
                    card.appendChild(summary);
                }
                if (entry.createdAt) {
                    var time = document.createElement('time');
                    var parsed = new Date(entry.createdAt);
                    time.textContent = Number.isNaN(parsed.getTime()) ? String(entry.createdAt) : parsed.toLocaleString(document.documentElement.lang || undefined);
                    time.dateTime = String(entry.createdAt); card.appendChild(time);
                }
                if (entry.isCurrent) {
                    var current = document.createElement('p'); current.className = 'idea-room-muted'; current.textContent = labels.historyCurrent;
                    card.appendChild(current);
                } else if (canEditGraph) {
                    var restore = document.createElement('button'); restore.type = 'button'; restore.className = 'btn btn-default';
                    restore.dataset.restoreHistory = String(entry.id); restore.textContent = labels.historyRestore;
                    restore.setAttribute('aria-label', labels.historyRestore + ': ' + (entry.summary || version.textContent));
                    restore.disabled = historyRestoring; card.appendChild(restore);
                }
                historyContainer.appendChild(card);
            });
        }

        async function refreshHistory() {
            if (!historyContainer || !root.dataset.historyUrl) { return; }
            var sequence = ++historyRequestSequence;
            try {
                var response = await request(root.dataset.historyUrl, 'GET');
                if (sequence === historyRequestSequence) { renderHistory(response.history || []); }
            } catch (error) {
                if (sequence !== historyRequestSequence) { return; }
                historyContainer.replaceChildren();
                var notice = document.createElement('p'); notice.className = 'idea-room-history-feedback is-error';
                notice.textContent = error.message || labels.historyUnavailable; historyContainer.appendChild(notice);
                var retry = document.createElement('button'); retry.type = 'button'; retry.className = 'idea-room-text-button';
                retry.dataset.retryHistory = '1'; retry.textContent = labels.historyRetry; historyContainer.appendChild(retry);
            }
        }

        function queueHistoryRefresh() {
            if (!historyContainer || !root.dataset.historyUrl) { return; }
            if (historyRefreshTimer) { window.clearTimeout(historyRefreshTimer); }
            historyRefreshTimer = window.setTimeout(function () { historyRefreshTimer = null; refreshHistory(); }, 150);
        }

        function renderRecoverableNodes(next) {
            if (!recoverableContainer) { return; }
            recoverableNodes = Array.isArray(next) ? next : [];
            recoverableContainer.replaceChildren();
            recoverableContainer.hidden = !canEditGraph || !recoverableNodes.length;
            if (recoverableContainer.hidden) { return; }
            var details = document.createElement('details');
            var heading = document.createElement('summary'); heading.textContent = labels.deletedNodes + ' (' + recoverableNodes.length + ')';
            details.appendChild(heading);
            recoverableNodes.forEach(function (node) {
                var row = document.createElement('div'); row.className = 'idea-room-recoverable-node';
                var name = document.createElement('span'); name.textContent = String(node.title || labels.newNode);
                var restore = document.createElement('button'); restore.type = 'button'; restore.className = 'idea-room-text-button';
                restore.dataset.restoreNode = String(node.id); restore.textContent = labels.restoreNode;
                restore.setAttribute('aria-label', labels.restoreNode + ': ' + name.textContent);
                row.appendChild(name); row.appendChild(restore); details.appendChild(row);
            });
            recoverableContainer.appendChild(details);
        }

        function renderResearchSuggestion(query) {
            if (!query) { return; }
            var card = document.createElement('div'); card.className = 'idea-room-research-suggestion';
            var label = document.createElement('span'); label.textContent = labels.researchSuggestion + ': ' + String(query);
            var button = document.createElement('button'); button.type = 'button'; button.className = 'idea-room-text-button';
            button.dataset.searchSuggestion = String(query); button.textContent = labels.runSearch;
            button.disabled = !searchConfigured;
            card.appendChild(label); card.appendChild(button); messagesElement.appendChild(card);
        }

        function setStatus(next) {
            status = next;
            root.dataset.status = status;
            if (statusElement) {
                var labelKey = 'status' + status.replace(/(^|_)([a-z])/g, function (match, separator, letter) { return letter.toUpperCase(); });
                statusElement.textContent = labels[labelKey] || status;
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

        function setBusy(busy) {
            streaming = busy;
            if (sendButton) {
                sendButton.disabled = busy || !canChat;
                sendButton.textContent = busy ? labels.sending : labels.send;
            }
            if (stopButton) { stopButton.hidden = !busy; }
            if (presenceElement) { presenceElement.textContent = busy ? labels.processing : labels.ready; }
            if (streamState) { streamState.hidden = !busy; streamState.textContent = busy ? labels.processing : ''; }
        }

        function showChatError(message) {
            if (!chatError) { return; }
            chatErrorText.textContent = message || labels.requestFailed;
            chatError.hidden = false;
            if (retryButton) { retryButton.hidden = false; }
        }

        function clearChatError() {
            if (chatError) { chatError.hidden = true; }
            if (retryButton) { retryButton.hidden = true; }
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
            heading.textContent = kind === 'milestone' ? labels.milestone :
                kind === 'task' ? labels.task :
                    kind === 'assumption' ? root.querySelector('#idea-room-assumptions').parentElement.querySelector('h3').textContent :
                        root.querySelector('#idea-room-questions').parentElement.querySelector('h3').textContent;
            head.appendChild(heading);
            if (canEdit) {
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'idea-room-text-button';
                remove.dataset.remove = kind;
                remove.textContent = labels.remove;
                remove.setAttribute('aria-label', labels.remove + ' ' + heading.textContent);
                head.appendChild(remove);
            }
            box.appendChild(head);
            if (kind === 'assumption' || kind === 'question') {
                box.appendChild(field(heading.textContent, value, true));
            } else {
                box.appendChild(field(labels.title, value.title, false));
                box.appendChild(field(labels.description, value.description, true));
                if (kind === 'milestone') {
                    var taskHeading = document.createElement('div');
                    taskHeading.className = 'idea-room-section-heading';
                    var taskTitle = document.createElement('strong');
                    taskTitle.textContent = labels.task;
                    taskHeading.appendChild(taskTitle);
                    if (canEdit) {
                        var addTask = document.createElement('button');
                        addTask.type = 'button';
                        addTask.className = 'idea-room-text-button';
                        addTask.dataset.add = 'milestone-task';
                        addTask.textContent = '+ ' + labels.addTask;
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
            renderSummary();
            dirty = false;
            setStatus(status);
        }

        function summaryList(id, values, emptyLabel) {
            var list = root.querySelector(id);
            list.replaceChildren();
            if (!values.length) {
                var empty = document.createElement('li');
                empty.className = 'idea-room-muted';
                empty.textContent = emptyLabel;
                list.appendChild(empty);
                return;
            }
            values.forEach(function (value) {
                var li = document.createElement('li');
                li.textContent = typeof value === 'string' ? value : value.title || '';
                list.appendChild(li);
            });
        }

        function renderSummary() {
            root.querySelector('#idea-room-summary-outcome').textContent = plan.outcome || labels.noOutcome;
            summaryList('#idea-room-summary-milestones', plan.milestones, labels.noMilestones);
            summaryList('#idea-room-summary-tasks', plan.tasks, labels.noActions);
            summaryList('#idea-room-summary-questions', plan.openQuestions, labels.noQuestions);
            summaryList('#idea-room-summary-assumptions', plan.assumptions, labels.noAssumptions);
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

        function appendMessage(message, provisional) {
            if (!message || (!message.content && !provisional)) { return null; }
            var role = message.role === 'assistant' ? 'assistant' : 'user';
            var article = document.createElement('article');
            article.className = 'idea-room-message idea-room-message--' + role;
            if (message.id) { article.dataset.messageId = String(message.id); }
            if (provisional) { article.dataset.provisional = '1'; }
            var author = document.createElement('span');
            author.className = 'idea-room-message-author';
            author.textContent = role === 'assistant' ? labels.assistant : labels.you;
            var body = document.createElement('div');
            body.className = 'idea-room-message-body';
            if (role === 'assistant') { markdown(body, message.content || ''); }
            else { body.textContent = message.content || ''; }
            article.appendChild(author);
            article.appendChild(body);
            messagesElement.appendChild(article);
            messagesElement.scrollTop = messagesElement.scrollHeight;
            return article;
        }

        function renderMessages(messages) {
            messagesElement.replaceChildren();
            (Array.isArray(messages) ? messages : []).forEach(function (message) {
                if (message.role === 'assistant' || message.role === 'user') { appendMessage(message, false); }
            });
            if (!messagesElement.children.length) {
                var empty = document.createElement('p');
                empty.className = 'idea-room-empty idea-room-chat-empty';
                empty.textContent = labels.noMessages;
                messagesElement.appendChild(empty);
            }
        }

        function safeArguments(raw) {
            var args = raw;
            if (typeof args === 'string') {
                try { args = JSON.parse(args); } catch (error) { args = {}; }
            }
            if (!args || typeof args !== 'object' || Array.isArray(args)) { return []; }
            return Object.keys(args).filter(function (key) {
                return !/password|secret|token|api.?key|credential/i.test(key);
            }).slice(0, 6).map(function (key) {
                var value = args[key];
                if (value && typeof value === 'object') { value = JSON.stringify(value); }
                return [key, String(value == null ? '' : value).slice(0, 180)];
            });
        }

        function normalizeAction(payload, eventName) {
            var action = payload.action || payload.tool || payload.toolCall || payload;
            if (!action || typeof action !== 'object') { action = {}; }
            return {
                id: action.id || action.actionId || action.action_id || payload.actionId || payload.action_id || payload.toolCallId || '',
                name: action.tool_name || action.toolName || action.name || payload.toolName || payload.tool_name || '',
                title: action.title || action.description || action.summary || payload.summary || '',
                arguments: action.display_arguments || action.arguments || action.args || action.arguments_json || {},
                result: action.result_summary || action.resultSummary || payload.resultSummary || payload.result || '',
                destructive: Boolean(action.destructive),
                status: action.status || eventName || ''
            };
        }

        function activityStatus(status) {
            if (/awaiting|pending/.test(status)) { return labels.toolAwaiting; }
            if (/started|running/.test(status)) { return labels.toolRunning; }
            if (/result|success|completed|confirmed/.test(status)) { return labels.toolSuccess; }
            if (/failed|error|rejected|cancelled/.test(status)) { return labels.toolFailed; }
            return labels.toolRequested;
        }

        function activityCard(action) {
            var card = document.createElement('article');
            card.className = 'idea-room-activity-card';
            if (action.destructive) { card.classList.add('idea-room-activity-card--destructive'); }
            if (action.id) { card.dataset.actionId = String(action.id); }
            var heading = document.createElement('div');
            heading.className = 'idea-room-activity-heading';
            var title = document.createElement('strong');
            title.textContent = (labels.toolNames && labels.toolNames[action.name]) || action.title || action.name || labels.toolRequested;
            var state = document.createElement('span');
            state.className = 'idea-room-activity-status';
            state.textContent = activityStatus(action.status);
            heading.appendChild(title);
            heading.appendChild(state);
            card.appendChild(heading);
            if (action.name && action.title && action.name !== action.title) {
                var name = document.createElement('small');
                name.className = 'idea-room-tool-name';
                name.textContent = action.name;
                card.appendChild(name);
            }
            var pairs = safeArguments(action.arguments);
            if (pairs.length) {
                var dl = document.createElement('dl');
                dl.className = 'idea-room-activity-args';
                pairs.forEach(function (pair) {
                    var dt = document.createElement('dt'); dt.textContent = (labels.argNames && labels.argNames[pair[0]]) || pair[0];
                    var dd = document.createElement('dd'); dd.textContent = pair[1];
                    dl.appendChild(dt); dl.appendChild(dd);
                });
                card.appendChild(dl);
            }
            if (action.result) {
                var resultSummary = document.createElement('p');
                resultSummary.className = 'idea-room-activity-result';
                resultSummary.textContent = typeof action.result === 'string' ? action.result : JSON.stringify(action.result);
                card.appendChild(resultSummary);
            }
            return card;
        }

        function renderPending(actions) {
            if (!pendingElement) { return; }
            pendingElement.replaceChildren();
            var list = Array.isArray(actions) ? actions : [];
            var staleSection = root.querySelector('#idea-room-stale-section');
            if (staleSection) { staleSection.hidden = !list.length; }
            list.forEach(function (raw) {
                var action = normalizeAction({ action: raw }, 'stale');
                var roomId = Number(raw.roomId || raw.room_id || root.dataset.roomId);
                var id = Number(action.id);
                if (!Number.isInteger(id) || id < 1 || !Number.isInteger(roomId) || roomId < 1) { return; }
                var card = document.createElement('article'); card.className = 'idea-room-stale-request';
                card.dataset.staleActionId = String(id); card.dataset.staleRoomId = String(roomId);
                var title = document.createElement('strong');
                title.textContent = (labels.toolNames && labels.toolNames[action.name]) || action.title || action.name || labels.agentAction;
                card.appendChild(title);
                if (action.title && action.title !== title.textContent) {
                    var description = document.createElement('p'); description.textContent = String(action.title); card.appendChild(description);
                }
                var note = document.createElement('p'); note.className = 'idea-room-muted'; note.textContent = labels.staleNeverRan;
                card.appendChild(note);
                var pairs = safeArguments(action.arguments);
                if (pairs.length) {
                    var args = document.createElement('p'); args.className = 'idea-room-stale-arguments';
                    args.textContent = pairs.map(function (pair) { return ((labels.argNames && labels.argNames[pair[0]]) || pair[0]) + ': ' + pair[1]; }).join(' · ');
                    card.appendChild(args);
                }
                card.dataset.stalePrompt = [action.title || title.textContent, pairs.map(function (pair) { return pair[0] + ': ' + pair[1]; }).join('; ')].filter(Boolean).join(' — ');
                var controls = document.createElement('div'); controls.className = 'idea-room-stale-actions';
                var rerun = document.createElement('button'); rerun.type = 'button'; rerun.className = 'btn btn-primary';
                rerun.dataset.staleAction = 'rerun'; rerun.textContent = labels.staleRerun;
                var discard = document.createElement('button'); discard.type = 'button'; discard.className = 'btn btn-default';
                discard.dataset.staleAction = 'discard'; discard.textContent = labels.staleDiscard;
                controls.appendChild(rerun); controls.appendChild(discard); card.appendChild(controls);
                pendingElement.appendChild(card);
            });
            if (staleSection) { staleSection.hidden = !pendingElement.children.length; }
        }

        function upsertActivity(payload, eventName) {
            var action = normalizeAction(payload, eventName);
            var selector = action.id ? '[data-action-id="' + String(action.id).replace(/[^a-zA-Z0-9_-]/g, '') + '"]' : null;
            var existing = selector ? messagesElement.querySelector(selector) : null;
            var next = activityCard(action);
            if (existing) { existing.replaceWith(next); }
            else { messagesElement.appendChild(next); }
            messagesElement.scrollTop = messagesElement.scrollHeight;
        }

        function applyEvent(frame, replayOnly) {
            var payload = frame.data || {};
            if (frame.id && Number(frame.id) > lastEventId) { lastEventId = Number(frame.id); }
            if (/^(graph\.|history\.)/.test(frame.event)) {
                if (payload.graph) { loadGraphResponse(payload); }
                else if (!replayOnly && /^graph\./.test(frame.event)) { refreshGraph(); }
                if (!replayOnly) { queueHistoryRefresh(); }
            }
            if (frame.event === 'research.completed' && Array.isArray(payload.sources)) { renderResearch(payload.sources); }
            if (frame.event === 'research.started') { researchResults.textContent = labels.researchSearching; }
            if (frame.event === 'research.failed') { setGraphNotice(payload.message || labels.requestFailed, true); }
            if (frame.event === 'research.suggested' && payload.query && !replayOnly) { renderResearchSuggestion(payload.query); }
            if (/^tool\./.test(frame.event)) {
                var draftForTool = messagesElement.querySelector('[data-provisional="1"]');
                if (draftForTool) {
                    if (!draftForTool.dataset.content) { draftForTool.remove(); }
                    else { delete draftForTool.dataset.provisional; }
                }
                upsertActivity(payload, frame.event);
            }
            if (replayOnly) { return; }
            if (frame.event === 'message.started') {
                var started = payload.message || {};
                if (started.role === 'user') {
                    if (!messagesElement.querySelector('[data-message-id="' + started.id + '"]')) { appendMessage(started, false); }
                } else if (!messagesElement.querySelector('[data-provisional="1"]')) {
                    appendMessage({ role: 'assistant', content: '' }, true);
                }
            } else if (frame.event === 'message.delta') {
                var provisional = messagesElement.querySelector('[data-provisional="1"]') || appendMessage({ role: 'assistant', content: '' }, true);
                provisional.dataset.content = (provisional.dataset.content || '') + String(payload.delta || payload.text || payload.content || '');
                markdown(provisional.querySelector('.idea-room-message-body'), provisional.dataset.content);
            } else if (frame.event === 'message.completed') {
                var finalMessage = payload.message || payload.assistant;
                var draft = messagesElement.querySelector('[data-provisional="1"]');
                if (draft && finalMessage && finalMessage.content) {
                    markdown(draft.querySelector('.idea-room-message-body'), finalMessage.content);
                    delete draft.dataset.provisional;
                    if (finalMessage.id) { draft.dataset.messageId = String(finalMessage.id); }
                } else if (finalMessage && finalMessage.content) { appendMessage(finalMessage, false); }
                if (payload.plan) { plan = normalizePlan(payload.plan); renderPlan(); queueHistoryRefresh(); }
            } else if (frame.event === 'message.error') {
                showChatError(payload.error || payload.message || labels.requestFailed);
            } else if (frame.event === 'legacy.response') {
                if (payload.message) { appendMessage(payload.message, false); }
                if (payload.assistant) { appendMessage(payload.assistant, false); }
                if (payload.plan) { plan = normalizePlan(payload.plan); renderPlan(); }
                if (payload.status) { setStatus(payload.status); }
            }
        }

        function renderActivityHistory(events) {
            (Array.isArray(events) ? events : []).forEach(function (raw) {
                var eventName = raw.event || raw.event_type || raw.type || '';
                var data = raw.data || raw.payload || raw.payload_json || {};
                if (typeof data === 'string') {
                    try { data = JSON.parse(data); } catch (error) { data = {}; }
                }
                var frame = { event: eventName, id: raw.id || raw.event_id || '', data: data };
                if (/^tool\./.test(eventName)) { applyEvent(frame, true); }
                else if (frame.id && Number(frame.id) > lastEventId) { lastEventId = Number(frame.id); }
            });
        }

        async function refreshState() {
            var state = await request(root.dataset.stateUrl, 'GET');
            if (state.room) {
                if (state.room.plan) { plan = normalizePlan(state.room.plan); renderPlan(); }
                if (state.room.plan_version !== undefined) { planVersion = Number(state.room.plan_version) || 0; }
                if (state.room.status) { setStatus(state.room.status); }
                if (contextSelect && state.room.project_id !== undefined) { contextSelect.value = state.room.project_id || ''; }
            }
            renderMessages(state.messages || []);
            renderActivityHistory(state.events || state.activities || []);
            renderPending(state.pendingActions || state.pending_actions || []);
            if (state.lastEventId && Number(state.lastEventId) > lastEventId) { lastEventId = Number(state.lastEventId); }
            await refreshGraph();
            return state;
        }

        async function replayEvents() {
            var separator = root.dataset.eventsUrl.indexOf('?') === -1 ? '?' : '&';
            var response = await fetch(root.dataset.eventsUrl + separator + 'after=' + encodeURIComponent(lastEventId), {
                credentials: 'same-origin', headers: { Accept: 'text/event-stream' }
            });
            await readSse(response, function (frame) { applyEvent(frame, false); });
        }

        if (canvasViewport) {
            canvasViewport.addEventListener('pointerdown', function (event) {
                if (event.button !== 0) { return; }
                var nodeButton = event.target.closest('[data-node-id]');
                if (connecting && nodeButton) { return; }
                canvasPointer = {
                    id: event.pointerId, x: event.clientX, y: event.clientY,
                    panX: canvasPan.x, panY: canvasPan.y,
                    targetNodeId: nodeButton ? nodeButton.dataset.nodeId : null,
                    nodeId: canEditGraph && nodeButton ? nodeButton.dataset.nodeId : null,
                    originalX: 0, originalY: 0, moved: false
                };
                if (canvasPointer.nodeId) {
                    var moving = graphNode(canvasPointer.nodeId);
                    canvasPointer.originalX = moving.x; canvasPointer.originalY = moving.y;
                }
                canvasViewport.setPointerCapture(event.pointerId);
            });
            canvasViewport.addEventListener('pointermove', function (event) {
                if (!canvasPointer || canvasPointer.id !== event.pointerId) { return; }
                var dx = event.clientX - canvasPointer.x;
                var dy = event.clientY - canvasPointer.y;
                if (Math.abs(dx) + Math.abs(dy) > 4) { canvasPointer.moved = true; }
                if (!canvasPointer.moved) { return; }
                if (canvasPointer.nodeId) {
                    var moving = graphNode(canvasPointer.nodeId);
                    if (moving) {
                        moving.x = Math.round(canvasPointer.originalX + dx / canvasPan.scale);
                        moving.y = Math.round(canvasPointer.originalY + dy / canvasPan.scale);
                        renderGraph();
                    }
                } else {
                    canvasPan.x = canvasPointer.panX + dx;
                    canvasPan.y = canvasPointer.panY + dy;
                    applyCanvasTransform();
                }
            });
            canvasViewport.addEventListener('pointerup', function (event) {
                if (!canvasPointer || canvasPointer.id !== event.pointerId) { return; }
                var finished = canvasPointer; canvasPointer = null;
                suppressCanvasClick = true;
                window.setTimeout(function () { suppressCanvasClick = false; }, 0);
                if (!finished.moved) {
                    if (finished.targetNodeId) { handleCanvasNodeClick(finished.targetNodeId); }
                    return;
                }
                if (!finished.nodeId) { return; }
                var movedNode = graphNode(finished.nodeId);
                if (!movedNode) { return; }
                var x = movedNode.x, y = movedNode.y;
                movedNode.x = finished.originalX; movedNode.y = finished.originalY;
                saveGraph(function (snapshot) {
                    var node = snapshot.nodes.find(function (candidate) { return graphKey(candidate) === finished.nodeId; });
                    if (node) { node.x = x; node.y = y; }
                });
            });
            canvasViewport.addEventListener('pointercancel', function () { canvasPointer = null; refreshGraph(); });
            canvasViewport.addEventListener('wheel', function (event) {
                event.preventDefault();
                zoomCanvas(event.deltaY < 0 ? 1.12 : 1 / 1.12, event.clientX, event.clientY);
            }, { passive: false });
            function handleCanvasNodeClick(id) {
                if (!connecting) { selectNode(id); return; }
                if (!connectSourceId) {
                    connectSourceId = id; setGraphNotice(labels.selectSecond, false); renderGraph(); return;
                }
                if (connectSourceId !== id) {
                    var sourceId = connectSourceId;
                    var type = root.querySelector('#idea-room-link-type').value;
                    saveGraph(function (snapshot) {
                        snapshot.links.push({ clientId: newClientId(), sourceId: graphEndpoint(sourceId), targetId: graphEndpoint(id), type: type });
                    }, labels.linkCreated);
                }
                connecting = false; connectSourceId = null;
                root.querySelector('#idea-room-connect').classList.remove('is-active');
                renderGraph();
            }
            canvasNodes.addEventListener('click', function (event) {
                var button = event.target.closest('[data-node-id]');
                if (!button || suppressCanvasClick) { return; }
                handleCanvasNodeClick(button.dataset.nodeId);
            });
            canvasViewport.addEventListener('keydown', function (event) {
                if (!selectedNodeId || !canEditGraph || !/^Arrow(Up|Down|Left|Right)$/.test(event.key)) { return; }
                event.preventDefault();
                var delta = event.shiftKey ? 25 : 10;
                saveGraph(function (snapshot) {
                    var node = snapshot.nodes.find(function (candidate) { return graphKey(candidate) === String(selectedNodeId); });
                    if (!node) { return; }
                    if (event.key === 'ArrowUp') { node.y -= delta; }
                    if (event.key === 'ArrowDown') { node.y += delta; }
                    if (event.key === 'ArrowLeft') { node.x -= delta; }
                    if (event.key === 'ArrowRight') { node.x += delta; }
                });
            });
            root.querySelector('#idea-room-node-filter').addEventListener('change', renderGraph);
            root.querySelector('#idea-room-zoom-in').addEventListener('click', function () { zoomCanvas(1.2); });
            root.querySelector('#idea-room-zoom-out').addEventListener('click', function () { zoomCanvas(1 / 1.2); });
            root.querySelector('#idea-room-center').addEventListener('click', centerCanvas);
            root.querySelector('#idea-room-add-node').addEventListener('click', function () {
                if (!canEditGraph) { return; }
                var id = newClientId();
                var slot = graph.nodes.length;
                var x = Math.round((canvasViewport.clientWidth / 2 - canvasPan.x) / canvasPan.scale - 92
                    + (slot % 2 === 0 ? -100 : 100));
                var y = Math.round((canvasViewport.clientHeight / 2 - canvasPan.y) / canvasPan.scale - 40
                    + Math.floor(slot / 2) * 120);
                saveGraph(function (snapshot) {
                    snapshot.nodes.push({ clientId: id, type: 'idea', title: labels.newNode, content: '', x: x, y: y, metadata: {} });
                }).then(function (saved) {
                    if (!saved) { return; }
                    var created = graph.nodes.find(function (node) { return node.clientId === id || node.x === x && node.y === y && node.title === labels.newNode; });
                    if (created) { selectNode(graphKey(created)); }
                });
            });
            root.querySelector('#idea-room-connect').addEventListener('click', function (event) {
                if (!canEditGraph) { return; }
                connecting = !connecting; connectSourceId = null;
                event.currentTarget.classList.toggle('is-active', connecting);
                setGraphNotice(connecting ? labels.selectFirst : '', false);
                renderGraph();
            });
            nodeForm.addEventListener('submit', function (event) {
                event.preventDefault(); if (!selectedNodeId || !canEditGraph) { return; }
                var id = selectedNodeId;
                var type = root.querySelector('#idea-room-node-type').value;
                var title = root.querySelector('#idea-room-node-title').value.trim();
                var content = root.querySelector('#idea-room-node-content').value.trim();
                var referenceValue = root.querySelector('#idea-room-node-reference').value;
                if (!title) { return; }
                saveGraph(function (snapshot) {
                    var node = snapshot.nodes.find(function (candidate) { return graphKey(candidate) === String(id); });
                    if (node) {
                        node.type = type; node.title = title; node.content = content;
                        node.metadata = Object.assign({}, node.metadata);
                        if (referenceValue) {
                            var parts = referenceValue.split(':');
                            node.metadata.reference = { type: parts[0], id: Number(parts[1]) };
                        } else { delete node.metadata.reference; }
                    }
                });
            });
            root.querySelector('#idea-room-delete-node').addEventListener('click', function () {
                if (!selectedNodeId || !canEditGraph || !window.confirm(labels.confirmDeleteNode)) { return; }
                var id = selectedNodeId;
                saveGraph(function (snapshot) {
                    snapshot.nodes = snapshot.nodes.filter(function (node) { return graphKey(node) !== String(id); });
                    snapshot.links = snapshot.links.filter(function (link) { return String(link.sourceId) !== String(id) && String(link.targetId) !== String(id); });
                }).then(async function (saved) { if (saved) { selectedNodeId = null; await refreshGraph(); } });
            });
            root.querySelector('#idea-room-node-links').addEventListener('click', function (event) {
                var button = event.target.closest('[data-remove-link]');
                if (!button || !canEditGraph) { return; }
                var id = button.dataset.removeLink;
                saveGraph(function (snapshot) {
                    snapshot.links = snapshot.links.filter(function (link) { return graphKey(link) !== id; });
                });
            });
            applyCanvasTransform();
            root.querySelector('#idea-room-add-node').disabled = !canEditGraph || !graphEnabled;
            root.querySelector('#idea-room-connect').disabled = !canEditGraph || !graphEnabled;
        }

        root.querySelectorAll('.idea-room-mode-button').forEach(function (button) {
            button.disabled = !canEditGraph || !graphEnabled;
            button.addEventListener('click', async function () {
                if (!canEditGraph || button.dataset.mode === graph.mode) { return; }
                var previous = graph.mode; setMode(button.dataset.mode);
                try { loadGraphResponse(await request(root.dataset.modeUrl, 'PUT', { mode: button.dataset.mode })); setGraphNotice(labels.modeSaved, false); }
                catch (error) { setMode(previous); setGraphNotice(error.message || labels.requestFailed, true); }
            });
        });

        var researchForm = root.querySelector('#idea-room-research-form');
        async function runResearch(query) {
            if (!searchConfigured || !query.trim()) { return; }
            var submit = researchForm.querySelector('button[type="submit"]'); submit.disabled = true;
            researchResults.textContent = labels.researchSearching;
            try { var response = await request(root.dataset.researchUrl, 'POST', { query: query.trim() }); renderResearch(response.sources || response.results || []); }
            catch (error) { researchResults.textContent = error.message || labels.requestFailed; }
            finally { submit.disabled = false; }
        }
        researchForm.addEventListener('submit', function (event) {
            event.preventDefault(); runResearch(root.querySelector('#idea-room-research-query').value);
        });
        messagesElement.addEventListener('click', function (event) {
            var suggestion = event.target.closest('[data-search-suggestion]');
            if (!suggestion) { return; }
            root.querySelector('#idea-room-research-query').value = suggestion.dataset.searchSuggestion;
            runResearch(suggestion.dataset.searchSuggestion);
        });
        researchResults.addEventListener('click', async function (event) {
            var keep = event.target.closest('[data-keep-source]');
            if (!keep || keep.disabled || !canEditGraph) { return; }
            keep.disabled = true;
            try {
                loadGraphResponse(await request(root.dataset.sourcesUrl + '/' + encodeURIComponent(keep.dataset.keepSource) + '/keep', 'POST', { expectedVersion: graph.version }));
                setGraphNotice(labels.sourceKept, false);
            } catch (error) {
                if (error.status === 409) { await refreshGraph(); setGraphNotice(labels.graphConflict, true); }
                else { setGraphNotice(error.message || labels.requestFailed, true); }
                keep.disabled = false;
            }
        });
        if (recoverableContainer) {
            recoverableContainer.addEventListener('click', async function (event) {
                var button = event.target.closest('[data-restore-node]');
                if (!button || button.disabled || !canEditGraph) { return; }
                var id = Number(button.dataset.restoreNode);
                if (!Number.isInteger(id) || id < 1) { return; }
                button.disabled = true;
                try {
                    var response = await request(root.dataset.restoreNodesUrl + '/' + id + '/restore', 'POST', { expectedVersion: graph.version });
                    if (response.graph) { loadGraphResponse(response); }
                    await refreshGraph();
                    if (graphNode(id)) { selectNode(String(id)); }
                    setGraphNotice(labels.nodeRestored, false);
                } catch (error) {
                    if (error.status === 409) { await refreshGraph(); setGraphNotice(labels.graphConflict, true); }
                    else { setGraphNotice(error.message || labels.requestFailed, true); }
                    button.disabled = false;
                }
            });
        }
        if (historyContainer) {
            historyContainer.addEventListener('click', async function (event) {
                var retry = event.target.closest('[data-retry-history]');
                if (retry) { await refreshHistory(); return; }
                var button = event.target.closest('[data-restore-history]');
                if (!button || button.disabled || !canEditGraph || historyRestoring) { return; }
                if (dirty && !window.confirm(labels.historyUnsavedWarning)) { return; }
                historyRestoring = true;
                historyContainer.querySelectorAll('[data-restore-history]').forEach(function (control) { control.disabled = true; });
                button.textContent = labels.historyRestoring;
                try {
                    var response = await request(root.dataset.historyUrl + '/' + encodeURIComponent(button.dataset.restoreHistory) + '/restore', 'POST', {
                        expectedVersion: graph.version,
                        expectedPlanVersion: planVersion
                    });
                    if (response.graph) { loadGraphResponse(response); }
                    if (response.plan) { plan = normalizePlan(response.plan); renderPlan(); }
                    if (response.planVersion !== undefined) { planVersion = Number(response.planVersion) || 0; }
                    setGraphNotice(labels.historyRestored, false);
                } catch (error) {
                    if (error.status === 409) {
                        try { await refreshState(); } catch (ignored) { /* Show the conflict even if refresh fails. */ }
                        setGraphNotice(labels.graphConflict, true);
                    } else { setGraphNotice(error.message || labels.requestFailed, true); }
                } finally {
                    historyRestoring = false;
                    if (historyRefreshTimer) { window.clearTimeout(historyRefreshTimer); historyRefreshTimer = null; }
                    await refreshHistory();
                }
            });
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
            saveButton.textContent = labels.saving;
            feedback(result, '', false);
            try {
                var response = await request(root.dataset.planUrl, 'PUT', { plan: readPlan() });
                plan = normalizePlan(response.plan);
                status = response.status || 'ready_for_review';
                renderPlan();
                if (response.planVersion !== undefined) { planVersion = Number(response.planVersion) || 0; }
                queueHistoryRefresh();
                feedback(result, labels.saved, false);
            } catch (error) {
                feedback(result, error.message || labels.requestFailed, true);
            } finally {
                saveButton.disabled = false;
                saveButton.textContent = labels.save;
            }
        });

        async function streamTurn(content, alreadyPersisted, endpoint) {
            clearChatError();
            setBusy(true);
            activeRequest = new AbortController();
            try {
                var response = await fetch(endpoint || root.dataset.turnsUrl, {
                    method: 'POST', credentials: 'same-origin', signal: activeRequest.signal,
                    headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream', 'X-CSRF-TOKEN': csrfToken() },
                    body: JSON.stringify({ content: content })
                });
                if (response.ok && !alreadyPersisted) {
                    messageInput.value = '';
                    lastContent = content;
                    appendMessage({ role: 'user', content: content }, false);
                }
                await readSse(response, function (frame) { applyEvent(frame, false); });
                await refreshState();
            } catch (error) {
                if (error.name !== 'AbortError') {
                    showChatError(error.message || labels.streamInterrupted);
                    try { await replayEvents(); await refreshState(); } catch (ignored) { /* user can retry the connection */ }
                }
            } finally {
                activeRequest = null;
                setBusy(false);
            }
        }

        var messageInput = messageForm ? messageForm.querySelector('textarea') : null;
        if (messageForm) {
            messageForm.addEventListener('submit', async function (event) {
                event.preventDefault();
                if (!messageForm.reportValidity() || streaming || !canChat) { return; }
                if (dirty && !window.confirm(labels.unsaved)) { return; }
                var content = messageInput.value.trim();
                if (!content) { return; }
                await streamTurn(content, false);
            });
            messageInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    messageForm.requestSubmit();
                }
            });
        }

        if (stopButton) {
            stopButton.addEventListener('click', async function () {
                if (activeRequest) { activeRequest.abort(); }
                try { await request(root.dataset.cancelUrl, 'POST', {}); await refreshState(); }
                catch (error) { showChatError(error.message || labels.requestFailed); }
                setBusy(false);
            });
        }
        if (retryButton) {
            retryButton.addEventListener('click', async function () {
                clearChatError();
                try {
                    var state = await refreshState();
                    if (state.generation && /^(failed|cancelled)$/.test(state.generation.status)) {
                        await streamTurn('', true, root.dataset.retryUrl);
                    } else {
                        await replayEvents(); await refreshState();
                    }
                }
                catch (error) { showChatError(error.message || labels.streamInterrupted); }
            });
        }
        var clearErrorButton = root.querySelector('#idea-room-clear-error');
        if (clearErrorButton) { clearErrorButton.addEventListener('click', clearChatError); }

        if (pendingElement) {
            pendingElement.addEventListener('click', async function (event) {
                var button = event.target.closest('button[data-action][data-action-id]');
                if (!button || button.disabled) { return; }
                var action = button.dataset.action;
                var card = button.closest('[data-action-id]');
                if (action === 'confirm' && !window.confirm(card.classList.contains('idea-room-activity-card--destructive') ? labels.destructiveWarning : labels.confirmAction)) { return; }
                card.querySelectorAll('button').forEach(function (item) { item.disabled = true; });
                try {
                    var actionUrl = root.dataset.actionsUrl + '/' + encodeURIComponent(button.dataset.actionId) + '/' + action;
                    if (action === 'confirm') {
                        setBusy(true);
                        var response = await fetch(actionUrl, {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream', 'X-CSRF-TOKEN': csrfToken() },
                            body: '{}'
                        });
                        await readSse(response, function (frame) { applyEvent(frame, false); });
                    } else {
                        await request(actionUrl, 'POST', {});
                    }
                    await refreshState();
                } catch (error) {
                    showChatError(error.message || labels.requestFailed);
                    card.querySelectorAll('button').forEach(function (item) { item.disabled = false; });
                } finally { setBusy(false); }
            });
        }

        if (contextSelect) {
            contextSelect.addEventListener('change', async function () {
                var projectId = contextSelect.value ? Number(contextSelect.value) : null;
                contextSelect.disabled = true;
                try {
                    await request(root.dataset.contextUrl, 'PUT', { projectId: projectId });
                    await refreshState();
                    feedback(result, labels.contextSaved, false);
                } catch (error) {
                    await refreshState().catch(function () {});
                    feedback(result, error.message || labels.contextFailed, true);
                } finally { contextSelect.disabled = false; }
            });
        }

        if (approveButton) {
            approveButton.addEventListener('click', async function () {
                if (approveButton.disabled || !window.confirm(labels.confirmApprove)) { return; }
                approveButton.disabled = true;
                approveButton.textContent = labels.approving;
                feedback(result, '', false);
                try {
                    var response = await request(root.dataset.approveUrl, 'POST', {});
                    status = response.room && response.room.status || 'approved';
                    canEdit = false;
                    canApprove = false;
                    root.querySelectorAll('#idea-room-plan-form input, #idea-room-plan-form textarea').forEach(function (input) { input.readOnly = true; });
                    root.querySelectorAll('#idea-room-plan-form [data-add], #idea-room-plan-form [data-remove]').forEach(function (button) { button.hidden = true; });
                    if (saveButton) { saveButton.hidden = true; }
                    approveButton.hidden = true;
                    setStatus(status);
                    feedback(result, labels.approved, false);
                } catch (error) {
                    feedback(result, error.message || labels.requestFailed, true);
                    setStatus(status);
                    approveButton.textContent = labels.approve;
                }
            });
        }

        if (archiveButton) {
            archiveButton.addEventListener('click', async function () {
                if (!window.confirm(labels.confirmArchive)) { return; }
                archiveButton.disabled = true;
                feedback(result, '', false);
                try {
                    await request(root.dataset.archiveUrl, 'POST', {});
                    window.location.reload();
                } catch (error) {
                    feedback(result, error.message || labels.requestFailed, true);
                    archiveButton.disabled = false;
                }
            });
        }

        renderPlan();
        renderMessages(initialMessages);
        renderPending(initialActions);
        queueHistoryRefresh();
        refreshState().then(function (state) {
            if (state.generation && state.generation.status === 'running') { return replayEvents().then(refreshState); }
        }).catch(function () {});
        var params = new URLSearchParams(window.location.search);
        if (params.get('autostart') === '1' && canChat) {
            params.delete('autostart');
            window.history.replaceState(null, '', window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash);
            streamTurn('', true);
        }
    }

    function init(root) {
        var scope = root || document;
        if (scope.matches && scope.matches('[data-testing-ai]')) { initTemporaryAi(scope); }
        if (scope.matches && scope.matches('[data-idea-room-index]')) { initIndex(scope); }
        if (scope.matches && scope.matches('[data-idea-room]')) { initRoom(scope); }
        scope.querySelectorAll('[data-testing-ai]').forEach(initTemporaryAi);
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
