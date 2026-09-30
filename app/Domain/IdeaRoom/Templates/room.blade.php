@extends($layout)

@section('content')
@php
    $roomStatus = (string) ($room['status'] ?? 'active');
    $statusKey = in_array($roomStatus, ['active', 'ready_for_review', 'approved', 'archived'], true) ? $roomStatus : 'active';
    $roomId = (int) ($room['id'] ?? 0);
    $editable = (bool) ($canEdit ?? false) && ! in_array($roomStatus, ['approved', 'archived'], true);
    $chatEnabled = (bool) ($canChat ?? $editable) && (bool) ($providerConfigured ?? false) && $roomStatus !== 'archived';
    $selectedProjectId = (int) ($room['project_id'] ?? $room['projectId'] ?? 0);
@endphp
<div class="pageheader">
    <div class="pageicon"><span class="fa fa-compass" aria-hidden="true"></span></div>
    <div class="pagetitle">
        <h5><a href="{{ BASE_URL }}/idea-room">{{ __('idea_room.command_center') }}</a></h5>
        <h1>{{ $room['title'] ?? $room['idea'] ?? __('idea_room.untitled') }}</h1>
    </div>
</div>

<div class="maincontent idea-room-page idea-room-workspace" data-idea-room
     data-room-id="{{ $roomId }}"
     data-plan-version="{{ (int) ($room['plan_version'] ?? 0) }}"
     data-messages-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/messages"
     data-turns-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/turns"
     data-retry-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/retry"
     data-events-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/events"
     data-state-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/state"
     data-actions-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/actions"
     data-cancel-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/cancel"
     data-context-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/context"
     data-plan-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/plan"
     data-approve-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/approve"
     data-archive-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/archive"
     data-graph-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/graph"
     data-history-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/history"
     data-restore-nodes-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/nodes"
     data-research-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/research/search"
     data-sources-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/sources"
     data-agent-url-template="{{ BASE_URL }}/idea-room/projects/{projectId}/agent"
     data-draft-url-template="{{ BASE_URL }}/idea-room/{roomId}/drafts/{actionId}"
     data-stale-discard-url-template="{{ BASE_URL }}/idea-room/{roomId}/actions/{actionId}/reject"
     data-project-id="{{ $selectedProjectId }}"
     data-mode-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/mode"
     data-graph-enabled="{{ ($graphEnabled ?? true) ? '1' : '0' }}"
     data-search-configured="{{ ($searchConfigured ?? false) ? '1' : '0' }}"
     data-can-edit-graph="{{ ($canEditGraph ?? $canEdit ?? false) ? '1' : '0' }}"
     data-can-edit="{{ $editable ? '1' : '0' }}"
     data-can-chat="{{ $chatEnabled ? '1' : '0' }}"
     data-status="{{ $statusKey }}" data-view="overview">
    <div class="maincontentinner">
        <div class="idea-room-topline">
            <a href="{{ BASE_URL }}/idea-room" class="idea-room-back">&larr; {{ __('idea_room.all_rooms') }}</a>
            <span id="idea-room-status" class="idea-room-status idea-room-status--{{ $statusKey }}" hidden>{{ __('idea_room.status_'.$statusKey) }}</span>
        </div>
        @if (! ($providerConfigured ?? false))
            <div class="alert alert-info" role="status"><strong>{{ __('idea_room.setup_title') }}</strong> {{ ($temporaryAiEnabled ?? false) ? __('idea_room.setup_testing_body') : __('idea_room.setup_body') }}</div>
        @endif
        @include('idearoom::partials.temporaryAi')
        <section class="idea-room-command-hero" aria-labelledby="idea-room-command-heading">
            <div class="idea-room-command-intro">
                <span class="idea-room-eyebrow">{{ __('idea_room.command_center') }}</span>
                <h2 id="idea-room-command-heading">{{ __('idea_room.command_greeting') }}</h2>
                <p>{{ __('idea_room.command_hint') }}</p>
                <div class="idea-room-agent-state" id="idea-room-agent-state" role="status" aria-live="polite">
                    <span class="idea-room-agent-dot" aria-hidden="true"></span>
                    <strong id="idea-room-agent-status">{{ __('idea_room.agent_loading') }}</strong>
                    <span id="idea-room-agent-status-detail">{{ __('idea_room.agent_loading_hint') }}</span>
                </div>
            </div>
            <div class="idea-room-command-controls">
                <label for="idea-room-project-context">{{ __('idea_room.project_context') }}</label>
                <select id="idea-room-project-context" @if (! ($canChangeContext ?? false)) disabled @endif>
                    @if ($canCreateProject ?? false)<option value="" @if ($selectedProjectId === 0) selected @endif>{{ __('idea_room.blank_idea') }}</option>@endif
                    @foreach (($projects ?? []) as $project)
                        <option value="{{ (int) ($project['id'] ?? 0) }}" @if ($selectedProjectId === (int) ($project['id'] ?? 0)) selected @endif>{{ $project['name'] ?? '' }}</option>
                    @endforeach
                    @if ($selectedProjectId > 0 && ! collect($projects ?? [])->contains(fn ($project) => (int) ($project['id'] ?? 0) === $selectedProjectId))
                        <option value="{{ $selectedProjectId }}" selected>{{ $room['project_name'] ?? $room['projectName'] ?? __('idea_room.project') }}</option>
                    @endif
                </select>
                <details class="idea-room-agent-settings" id="idea-room-agent-settings">
                    <summary>{{ __('idea_room.agent_settings') }}</summary>
                    <p>{{ __('idea_room.agent_settings_hint') }}</p>
                    <div class="idea-room-agent-settings-actions">
                        <button type="button" class="btn btn-default" id="idea-room-agent-enable" disabled>{{ __('idea_room.agent_enable') }}</button>
                        <button type="button" class="btn btn-default" id="idea-room-agent-pause" disabled>{{ __('idea_room.agent_pause') }}</button>
                    </div>
                    <p class="idea-room-field-note" id="idea-room-agent-settings-feedback" role="status" aria-live="polite"></p>
                </details>
            </div>
        </section>
        <div class="idea-room-view-tabs" role="tablist" aria-label="{{ __('idea_room.command_views') }}">
            <button type="button" role="tab" aria-selected="true" aria-controls="idea-room-view-overview" id="idea-room-tab-overview" data-command-view="overview">{{ __('idea_room.view_overview') }}</button>
            <button type="button" role="tab" aria-selected="false" aria-controls="idea-room-view-activity" id="idea-room-tab-activity" data-command-view="activity">{{ __('idea_room.view_activity') }}</button>
            <button type="button" role="tab" aria-selected="false" aria-controls="idea-room-view-drafts" id="idea-room-tab-drafts" data-command-view="drafts">{{ __('idea_room.view_drafts') }} <span id="idea-room-draft-count" hidden></span></button>
            <button type="button" role="tab" aria-selected="false" aria-controls="idea-room-view-ideas" id="idea-room-tab-ideas" data-command-view="ideas">{{ __('idea_room.view_ideas') }}</button>
        </div>
        <div class="idea-room-layout">
            <section class="idea-room-card idea-room-canvas-panel" id="idea-room-view-ideas" role="tabpanel" aria-labelledby="idea-room-tab-ideas" hidden>
                <header class="idea-room-panel-heading">
                    <div><h2 id="idea-room-canvas-heading">{{ __('idea_room.canvas') }}</h2><p>{{ __('idea_room.canvas_hint') }}</p></div>
                    <span class="idea-room-graph-version" id="idea-room-graph-version" aria-live="polite"></span>
                </header>
                <div class="idea-room-canvas-tools">
                    <label class="sr-only" for="idea-room-node-filter">{{ __('idea_room.filter_nodes') }}</label>
                    <select id="idea-room-node-filter"><option value="all">{{ __('idea_room.all_nodes') }}</option><option value="idea">{{ __('idea_room.node_idea') }}</option><option value="question">{{ __('idea_room.node_question') }}</option><option value="insight">{{ __('idea_room.node_insight') }}</option><option value="source">{{ __('idea_room.node_source') }}</option><option value="decision">{{ __('idea_room.node_decision') }}</option><option value="next_step">{{ __('idea_room.node_next_step') }}</option></select>
                    <button type="button" class="btn btn-default" id="idea-room-add-node" @if (! ($canEditGraph ?? $canEdit ?? false)) disabled @endif>+ {{ __('idea_room.add_node') }}</button>
                    <button type="button" class="btn btn-default" id="idea-room-connect" @if (! ($canEditGraph ?? $canEdit ?? false)) disabled @endif>{{ __('idea_room.connect_nodes') }}</button>
                    <label class="sr-only" for="idea-room-link-type">{{ __('idea_room.link_type') }}</label>
                    <select id="idea-room-link-type" aria-label="{{ __('idea_room.link_type') }}"><option value="related_to">{{ __('idea_room.link_related_to') }}</option><option value="supports">{{ __('idea_room.link_supports') }}</option><option value="contradicts">{{ __('idea_room.link_contradicts') }}</option><option value="depends_on">{{ __('idea_room.link_depends_on') }}</option><option value="leads_to">{{ __('idea_room.link_leads_to') }}</option></select>
                    <button type="button" class="idea-room-text-button" id="idea-room-zoom-out" aria-label="{{ __('idea_room.zoom_out') }}">−</button>
                    <span id="idea-room-zoom-label" aria-live="polite">100%</span>
                    <button type="button" class="idea-room-text-button" id="idea-room-zoom-in" aria-label="{{ __('idea_room.zoom_in') }}">+</button>
                    <button type="button" class="idea-room-text-button" id="idea-room-center">{{ __('idea_room.center_canvas') }}</button>
                </div>
                <div class="idea-room-canvas-viewport" id="idea-room-canvas-viewport" tabindex="0" role="application" aria-label="{{ __('idea_room.canvas') }}">
                    <div class="idea-room-canvas-world" id="idea-room-canvas-world"><svg class="idea-room-canvas-links" id="idea-room-canvas-links" aria-hidden="true"></svg><div class="idea-room-canvas-nodes" id="idea-room-canvas-nodes"></div></div>
                    <p class="idea-room-canvas-empty" id="idea-room-canvas-empty">{{ __('idea_room.canvas_empty') }}</p>
                </div>
                <p class="idea-room-canvas-status" id="idea-room-canvas-status" role="status" aria-live="polite"></p>
                <div class="idea-room-recoverable-nodes" id="idea-room-recoverable-nodes" hidden></div>
            </section>
            <section class="idea-room-card idea-room-chat" id="idea-room-view-overview" role="tabpanel" aria-labelledby="idea-room-tab-overview">
                <header class="idea-room-panel-heading idea-room-chat-heading">
                    <div><h2 id="idea-room-chat-heading">{{ __('idea_room.ask_agent') }}</h2><p>{{ __('idea_room.command_chat_hint') }}</p></div>
                    <span class="idea-room-presence" id="idea-room-presence" role="status" aria-live="polite">{{ __('idea_room.ready_to_chat') }}</span>
                </header>
                <div class="idea-room-messages" id="idea-room-messages" role="log" aria-live="polite" aria-relevant="additions text"></div>
                <div class="idea-room-stream-state" id="idea-room-stream-state" role="status" aria-live="polite" hidden></div>
                <div class="idea-room-chat-error" id="idea-room-chat-error" role="alert" hidden>
                    <span id="idea-room-chat-error-text"></span>
                    <button type="button" class="idea-room-text-button" id="idea-room-clear-error">{{ __('idea_room.clear_error') }}</button>
                </div>
                <form id="idea-room-message-form" class="idea-room-compose" @if (! $chatEnabled) hidden @endif>
                    @csrf
                    <label class="sr-only" for="idea-room-message">{{ __('idea_room.your_message') }}</label>
                    <textarea id="idea-room-message" rows="3" maxlength="10000" required placeholder="{{ __('idea_room.command_prompt') }}" @if (! $chatEnabled) disabled @endif></textarea>
                    <div class="idea-room-compose-footer">
                        <span>{{ __('idea_room.command_chat_note') }}</span>
                        <div class="idea-room-compose-actions">
                            <button type="button" class="btn btn-default" id="idea-room-retry" hidden>{{ __('idea_room.retry') }}</button>
                            <button type="button" class="btn btn-default" id="idea-room-stop" hidden>{{ __('idea_room.stop') }}</button>
                            <button type="submit" class="btn btn-primary" id="idea-room-send" @if (! $chatEnabled) disabled @endif>{{ __('idea_room.send') }}</button>
                        </div>
                    </div>
                </form>
            </section>

            <aside class="idea-room-card idea-room-sidebar" aria-labelledby="idea-room-sidebar-heading" hidden>
                <div class="idea-room-panel-heading"><div><h2 id="idea-room-sidebar-heading">{{ __('idea_room.inspector') }}</h2><p>{{ __('idea_room.inspector_hint') }}</p></div></div>
                <div class="idea-room-sidebar-content">
                    <section class="idea-room-sidebar-section" aria-labelledby="idea-room-node-heading">
                        <h3 id="idea-room-node-heading">{{ __('idea_room.selected_node') }}</h3>
                        <p id="idea-room-node-empty" class="idea-room-muted">{{ __('idea_room.select_node_hint') }}</p>
                        <form id="idea-room-node-form" hidden>
                            <label for="idea-room-node-type">{{ __('idea_room.node_type') }}</label><select id="idea-room-node-type"><option value="idea">{{ __('idea_room.node_idea') }}</option><option value="question">{{ __('idea_room.node_question') }}</option><option value="insight">{{ __('idea_room.node_insight') }}</option><option value="source">{{ __('idea_room.node_source') }}</option><option value="decision">{{ __('idea_room.node_decision') }}</option><option value="next_step">{{ __('idea_room.node_next_step') }}</option></select>
                            <label for="idea-room-node-title">{{ __('idea_room.item_title') }}</label><input id="idea-room-node-title" type="text" maxlength="255" required>
                            <label for="idea-room-node-content">{{ __('idea_room.description') }}</label><textarea id="idea-room-node-content" rows="4" maxlength="10000"></textarea>
                            <label for="idea-room-node-reference">{{ __('idea_room.reference') }}</label><select id="idea-room-node-reference"><option value="">{{ __('idea_room.no_reference') }}</option><optgroup label="{{ __('idea_room.projects') }}">@foreach (($projects ?? []) as $project)<option value="project:{{ (int) ($project['id'] ?? 0) }}">{{ $project['name'] ?? '' }}</option>@endforeach</optgroup><optgroup label="{{ __('idea_room.all_rooms') }}">@foreach (($linkableRooms ?? []) as $linkableRoom)<option value="room:{{ (int) ($linkableRoom['id'] ?? 0) }}">{{ $linkableRoom['title'] ?? $linkableRoom['idea'] ?? '' }}</option>@endforeach</optgroup></select>
                            <h4>{{ __('idea_room.connections') }}</h4><div id="idea-room-node-links"></div>
                            <div class="idea-room-node-actions"><button type="submit" class="btn btn-primary">{{ __('idea_room.save_node') }}</button><button type="button" class="idea-room-text-button" id="idea-room-delete-node">{{ __('idea_room.delete_node') }}</button></div>
                        </form>
                    </section>
                    <section class="idea-room-sidebar-section" aria-labelledby="idea-room-research-heading">
                        <h3 id="idea-room-research-heading">{{ __('idea_room.research') }}</h3>
                        <p class="idea-room-field-note">{{ __('idea_room.research_hint') }}</p>
                        <form id="idea-room-research-form"><label class="sr-only" for="idea-room-research-query">{{ __('idea_room.research_query') }}</label><input id="idea-room-research-query" type="text" maxlength="300" placeholder="{{ __('idea_room.research_query') }}" required @if (! ($searchConfigured ?? false)) disabled @endif><button type="submit" class="btn btn-default" @if (! ($searchConfigured ?? false)) disabled @endif>{{ __('idea_room.search') }}</button></form>
                        @if (! ($searchConfigured ?? false))<p class="idea-room-field-note">{{ __('idea_room.search_setup') }}</p>@endif
                        <div id="idea-room-research-results" aria-live="polite"></div>
                    </section>
                    <section class="idea-room-sidebar-section" aria-labelledby="idea-room-history-heading">
                        <h3 id="idea-room-history-heading">{{ __('idea_room.history') }}</h3>
                        <p class="idea-room-field-note">{{ __('idea_room.history_hint') }}</p>
                        <div id="idea-room-history" aria-live="polite"><p class="idea-room-muted">{{ __('idea_room.history_loading') }}</p></div>
                    </section>
                    @if ($canArchive ?? false)<button type="button" id="idea-room-archive" class="idea-room-text-button idea-room-archive">{{ __('idea_room.archive') }}</button>@endif
                </div>
            </aside>
            <aside class="idea-room-overview-aside" aria-label="{{ __('idea_room.command_glance') }}">
                <section class="idea-room-card idea-room-glance-card">
                    <div class="idea-room-card-kicker">{{ __('idea_room.command_glance') }}</div>
                    <h2>{{ __('idea_room.agent_handled') }}</h2>
                    <p id="idea-room-agent-summary">{{ __('idea_room.agent_summary_loading') }}</p>
                    <button type="button" class="idea-room-text-button" data-open-command-view="activity">{{ __('idea_room.view_all_activity') }} &rarr;</button>
                </section>
                <section class="idea-room-card idea-room-needs-card" aria-labelledby="idea-room-needs-heading">
                    <div class="idea-room-card-kicker">{{ __('idea_room.only_when_needed') }}</div>
                    <h2 id="idea-room-needs-heading">{{ __('idea_room.needs_input') }}</h2>
                    <div id="idea-room-needs-input" aria-live="polite"><p class="idea-room-muted">{{ __('idea_room.needs_loading') }}</p></div>
                </section>
                <section class="idea-room-card idea-room-stale-card" id="idea-room-stale-section" aria-labelledby="idea-room-stale-heading" hidden>
                    <h2 id="idea-room-stale-heading">{{ __('idea_room.stale_requests') }}</h2>
                    <p>{{ __('idea_room.stale_requests_hint') }}</p>
                    <div id="idea-room-pending-actions" aria-live="polite"></div>
                </section>
            </aside>
            <section class="idea-room-card idea-room-activity-view" id="idea-room-view-activity" role="tabpanel" aria-labelledby="idea-room-tab-activity" hidden>
                <div class="idea-room-panel-heading"><div><h2>{{ __('idea_room.activity_heading') }}</h2><p>{{ __('idea_room.activity_hint') }}</p></div></div>
                <div id="idea-room-agent-runs" class="idea-room-run-list" aria-live="polite"></div>
                <div id="idea-room-agent-activity" class="idea-room-agent-activity-list" aria-live="polite"></div>
            </section>
            <section class="idea-room-card idea-room-drafts-view" id="idea-room-view-drafts" role="tabpanel" aria-labelledby="idea-room-tab-drafts" hidden>
                <div class="idea-room-panel-heading"><div><h2>{{ __('idea_room.drafts_heading') }}</h2><p>{{ __('idea_room.drafts_hint') }}</p></div></div>
                <div id="idea-room-agent-drafts" aria-live="polite"></div>
            </section>
        </div>
        <p class="idea-room-feedback" id="idea-room-feedback" role="status" aria-live="polite" hidden></p>
        <div class="idea-room-legacy-contract" hidden aria-hidden="true">
            <form id="idea-room-plan-form">
                @csrf
                @if ($selectedProjectId === 0)<input id="idea-room-project-name" name="projectName" type="text">@endif
                <textarea id="idea-room-outcome" name="outcome"></textarea>
                <div><h3>{{ __('idea_room.milestones') }}</h3><div id="idea-room-milestones"></div></div>
                <div><h3>{{ __('idea_room.next_actions') }}</h3><div id="idea-room-tasks"></div></div>
                <div><h3>{{ __('idea_room.assumptions') }}</h3><div id="idea-room-assumptions"></div></div>
                <div><h3>{{ __('idea_room.open_questions') }}</h3><div id="idea-room-questions"></div></div>
                <button type="submit" id="idea-room-save" disabled>{{ __('idea_room.save_for_review') }}</button>
            </form>
            <p id="idea-room-summary-outcome"></p>
            <ul id="idea-room-summary-milestones"></ul><ul id="idea-room-summary-tasks"></ul>
            <ul id="idea-room-summary-questions"></ul><ul id="idea-room-summary-assumptions"></ul>
        </div>
    </div>
</div>
<script type="application/json" id="idea-room-initial-plan">@json($plan ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
<script type="application/json" id="idea-room-initial-messages">@json($messages ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
<script type="application/json" id="idea-room-initial-actions">@json($pendingActions ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
@php
    $ideaRoomLabels = [
    'requestFailed' => __('idea_room.request_failed'), 'confirmArchive' => __('idea_room.confirm_archive'),
    'send' => __('idea_room.send'), 'sending' => __('idea_room.sending'), 'saving' => __('idea_room.saving'),
    'save' => __('idea_room.save_for_review'), 'approving' => __('idea_room.approving'), 'confirmApprove' => __('idea_room.confirm_approve'),
    'unsaved' => __('idea_room.unsaved_warning'), 'saved' => __('idea_room.saved_for_review'), 'approved' => __('idea_room.approved_message'),
    'assistant' => __('idea_room.assistant'), 'you' => __('idea_room.you'), 'approve' => __('idea_room.approve_and_create'),
    'statusActive' => __('idea_room.status_active'), 'statusReadyForReview' => __('idea_room.status_ready_for_review'),
    'statusApproved' => __('idea_room.status_approved'), 'statusArchived' => __('idea_room.status_archived'),
    'title' => __('idea_room.item_title'), 'description' => __('idea_room.description'), 'milestone' => __('idea_room.milestone'),
    'task' => __('idea_room.task'), 'remove' => __('idea_room.remove'), 'addTask' => __('idea_room.add_task'),
    'toolRequested' => __('idea_room.tool_requested'), 'toolRunning' => __('idea_room.tool_running'),
    'toolAwaiting' => __('idea_room.tool_awaiting'), 'toolSuccess' => __('idea_room.tool_success'), 'toolFailed' => __('idea_room.tool_failed'),
    'confirmAction' => __('idea_room.confirm_action'), 'rejectAction' => __('idea_room.reject_action'),
    'destructiveWarning' => __('idea_room.destructive_warning'),
    'confirmingAction' => __('idea_room.confirming_action'), 'rejectingAction' => __('idea_room.rejecting_action'),
    'processing' => __('idea_room.processing'), 'streamInterrupted' => __('idea_room.stream_interrupted'),
    'ready' => __('idea_room.ready_to_chat'), 'retry' => __('idea_room.retry'), 'noPending' => __('idea_room.no_pending'),
    'noOutcome' => __('idea_room.no_outcome'), 'noMilestones' => __('idea_room.no_milestones'),
    'noActions' => __('idea_room.no_actions'), 'noQuestions' => __('idea_room.no_questions'),
    'noAssumptions' => __('idea_room.no_assumptions'), 'noMessages' => __('idea_room.no_messages_chat'),
    'contextSaved' => __('idea_room.context_saved'), 'contextFailed' => __('idea_room.context_failed'),
    'canvas' => __('idea_room.canvas'), 'graphVersion' => __('idea_room.graph_version'),
    'graphSaved' => __('idea_room.graph_saved'), 'graphConflict' => __('idea_room.graph_conflict'),
    'graphUnavailable' => __('idea_room.graph_unavailable'), 'graphEmpty' => __('idea_room.canvas_empty'),
    'nodeTypes' => [
        'idea' => __('idea_room.node_idea'), 'question' => __('idea_room.node_question'),
        'insight' => __('idea_room.node_insight'), 'source' => __('idea_room.node_source'),
        'decision' => __('idea_room.node_decision'), 'next_step' => __('idea_room.node_next_step'),
    ],
    'linkTypes' => [
        'related_to' => __('idea_room.link_related_to'), 'supports' => __('idea_room.link_supports'),
        'contradicts' => __('idea_room.link_contradicts'), 'depends_on' => __('idea_room.link_depends_on'),
        'leads_to' => __('idea_room.link_leads_to'),
    ],
    'newNode' => __('idea_room.new_node'), 'selectFirst' => __('idea_room.select_first_node'),
    'selectSecond' => __('idea_room.select_second_node'), 'linkCreated' => __('idea_room.link_created'),
    'connections' => __('idea_room.connections'), 'removeConnection' => __('idea_room.remove_connection'),
    'confirmDeleteNode' => __('idea_room.confirm_delete_node'), 'readOnlyGraph' => __('idea_room.read_only_graph'),
    'researchSearching' => __('idea_room.research_searching'), 'researchEmpty' => __('idea_room.research_empty'),
    'openSource' => __('idea_room.open_source'), 'keepSource' => __('idea_room.keep_source'),
    'sourceKept' => __('idea_room.source_kept'),
    'deletedNodes' => __('idea_room.deleted_nodes'), 'restoreNode' => __('idea_room.restore_node'),
    'nodeRestored' => __('idea_room.node_restored'),
    'historyEmpty' => __('idea_room.history_empty'), 'historyUnavailable' => __('idea_room.history_unavailable'),
    'historyRetry' => __('idea_room.history_retry'), 'historyRestore' => __('idea_room.history_restore'),
    'historyRestoring' => __('idea_room.history_restoring'), 'historyRestored' => __('idea_room.history_restored'),
    'historyUnsavedWarning' => __('idea_room.history_unsaved_warning'),
    'historyCurrent' => __('idea_room.history_current'), 'historyVersion' => __('idea_room.history_version'),
    'historyPlanVersion' => __('idea_room.history_plan_version'),
    'historyOriginAi' => __('idea_room.history_origin_ai'), 'historyOriginMcp' => __('idea_room.history_origin_mcp'),
    'historyOriginManual' => __('idea_room.history_origin_manual'), 'historyOriginRestore' => __('idea_room.history_origin_restore'),
    'historyOriginInitial' => __('idea_room.history_origin_initial'),
    'historySummaryInitial' => __('idea_room.history_summary_initial'),
    'historySummaryCanvasEdited' => __('idea_room.history_summary_canvas_edited'),
    'historySummaryModeChanged' => __('idea_room.history_summary_mode_changed'),
    'historySummaryNodesRestored' => __('idea_room.history_summary_nodes_restored'),
    'historySummaryPlanEdited' => __('idea_room.history_summary_plan_edited'),
    'historySummaryAiChange' => __('idea_room.history_summary_ai_change'),
    'historySummaryRestored' => __('idea_room.history_summary_restored'),
    'modeExploreHint' => __('idea_room.mode_explore_hint'),
    'modeExecuteHint' => __('idea_room.mode_execute_hint'), 'modeSaved' => __('idea_room.mode_saved'),
    'modeExplore' => __('idea_room.mode_explore'), 'modeExecute' => __('idea_room.mode_execute'),
    'researchSuggestion' => __('idea_room.research_suggestion'), 'runSearch' => __('idea_room.run_search'),
    'toolNames' => [
        'getAllProjects' => __('idea_room.tool_name_get_all_projects'),
        'findProject' => __('idea_room.tool_name_find_project'),
        'getProject' => __('idea_room.tool_name_get_project'),
        'addProject' => __('idea_room.tool_name_add_project'),
        'getAllGoals' => __('idea_room.tool_name_get_all_goals'),
        'getGoal' => __('idea_room.tool_name_get_goal'),
        'createGoalboard' => __('idea_room.tool_name_create_goalboard'),
        'createGoal' => __('idea_room.tool_name_create_goal'),
        'editGoal' => __('idea_room.tool_name_edit_goal'),
        'findTasks' => __('idea_room.tool_name_find_tasks'),
        'getTicket' => __('idea_room.tool_name_get_ticket'),
        'getMilestone' => __('idea_room.tool_name_get_milestone'),
        'addTask' => __('idea_room.tool_name_add_task'),
        'bulkAddTasks' => __('idea_room.tool_name_bulk_add_tasks'),
        'bulkEditTasks' => __('idea_room.tool_name_bulk_edit_tasks'),
        'bulkScheduleTasks' => __('idea_room.tool_name_bulk_schedule_tasks'),
        'addMilestone' => __('idea_room.tool_name_add_milestone'),
        'addSubtask' => __('idea_room.tool_name_add_subtask'),
        'editTask' => __('idea_room.tool_name_edit_task'),
        'editMilestone' => __('idea_room.tool_name_edit_milestone'),
        'getCalendar' => __('idea_room.tool_name_get_calendar'),
        'addEvent' => __('idea_room.tool_name_add_event'),
        'editEvent' => __('idea_room.tool_name_edit_event'),
        'deleteEvent' => __('idea_room.tool_name_delete_event'),
        'scheduleTaskOnCalendar' => __('idea_room.tool_name_schedule_task'),
        'getUserTimesheets' => __('idea_room.tool_name_get_timesheets'),
        'logTime' => __('idea_room.tool_name_log_time'),
        'startTimer' => __('idea_room.tool_name_start_timer'),
        'stopTimer' => __('idea_room.tool_name_stop_timer'),
        'getAllProjectComments' => __('idea_room.tool_name_get_comments'),
        'addComment' => __('idea_room.tool_name_add_comment'),
        'addProjectStatusUpdate' => __('idea_room.tool_name_add_status_update'),
    ],
    'argNames' => [
        'projectId' => __('idea_room.arg_project'), 'projectIds' => __('idea_room.arg_projects'),
        'id' => __('idea_room.arg_id'), 'ticketId' => __('idea_room.arg_task'),
        'taskId' => __('idea_room.arg_task'), 'parentTicket' => __('idea_room.arg_parent_task'),
        'headline' => __('idea_room.arg_title'), 'title' => __('idea_room.arg_title'),
        'name' => __('idea_room.arg_name'), 'description' => __('idea_room.arg_description'),
        'text' => __('idea_room.arg_text'), 'eventTitle' => __('idea_room.arg_title'),
        'status' => __('idea_room.arg_status'), 'tasks' => __('idea_room.arg_tasks'),
        'updates' => __('idea_room.arg_updates'), 'schedules' => __('idea_room.arg_schedules'),
        'date' => __('idea_room.arg_date'), 'dateFrom' => __('idea_room.arg_from'),
        'dateTo' => __('idea_room.arg_to'), 'editFrom' => __('idea_room.arg_from'),
        'editTo' => __('idea_room.arg_to'), 'hours' => __('idea_room.arg_hours'),
        'duration' => __('idea_room.arg_duration'), 'term' => __('idea_room.arg_search'),
    ],
    ];
@endphp
<script type="application/json" id="idea-room-labels">@json($ideaRoomLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>

@push('styles')
    @include('idearoom::partials.styles')
@endpush
@endsection
