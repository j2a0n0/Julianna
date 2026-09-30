@extends($layout)

@section('content')
<div class="pageheader">
    <div class="pageicon"><span class="fa fa-sparkles" aria-hidden="true"></span></div>
    <div class="pagetitle"><h5>{{ __('agent_ui.workspace') }}</h5><h1>{{ __('agent_ui.title') }}</h1></div>
</div>
<main class="maincontent agent-page" data-agent-command-center data-base-url="{{ BASE_URL }}" data-project-id="{{ (int) $selectedProjectId }}" data-provider-configured="{{ $providerConfigured ? '1' : '0' }}">
    <div class="maincontentinner">
        <header class="agent-hero">
            <div>
                <span class="agent-eyebrow">{{ __('agent_ui.eyebrow') }}</span>
                <h2>{{ __('agent_ui.greeting') }}</h2>
                <p>{{ __('agent_ui.intro') }}</p>
                <span class="agent-status" id="agent-status" role="status" aria-live="polite"><span class="agent-status-dot" aria-hidden="true"></span>{{ __('agent_ui.loading') }}</span>
            </div>
            <div class="agent-hero-controls">
                @if (\Leantime\Domain\Auth\Support\SecureAuthRequest::hasFullWebAuthentication() && session('userdata.role') === \Leantime\Domain\Auth\Models\Roles::$owner)
                    <a class="agent-whiteboard-link" href="{{ BASE_URL }}/agent/settings">⚙ {{ __('agent_ui.ai_settings_link') }} →</a>
                @endif
                <label for="agent-project-select">{{ __('agent_ui.scope') }}</label>
                <select id="agent-project-select">
                    <option value="" @if (! $selectedProjectId) selected @endif>{{ __('agent_ui.all_accessible') }}</option>
                    @foreach ($projects as $project)
                        <option value="{{ (int) $project['id'] }}" @if ($selectedProjectId === (int) $project['id']) selected @endif>{{ $project['name'] }}</option>
                    @endforeach
                </select>
                <p class="agent-muted" id="agent-scope-hint">{{ $selectedProjectId ? __('agent_ui.project_scope_hint') : __('agent_ui.unscoped_hint') }}</p>
                @if ($selectedProjectId)
                    <a class="agent-whiteboard-link" href="{{ BASE_URL }}/whiteboards/projects/{{ (int) $selectedProjectId }}">✎ {{ __('agent_ui.open_whiteboard') }} →</a>
                @endif
                <div class="agent-setting-buttons" id="agent-setting-buttons" @if (! $selectedProjectId || ! $canConfigureAgent) hidden @endif>
                    <button type="button" class="btn btn-default" id="agent-enable" hidden>{{ __('agent_ui.enable') }}</button>
                    <button type="button" class="btn btn-default" id="agent-pause" hidden>{{ __('agent_ui.pause') }}</button>
                    <button type="button" class="btn btn-default" id="agent-resume" hidden>{{ __('agent_ui.resume') }}</button>
                </div>
            </div>
        </header>

        @unless ($providerConfigured)
            <div class="alert alert-info" role="status"><strong>{{ __('agent_ui.setup_title') }}</strong> {{ __('agent_ui.setup_body') }}</div>
        @endunless

        <div class="agent-main-grid">
            <section class="agent-card agent-conversation" aria-labelledby="agent-ask-heading">
                <div class="agent-card-heading">
                    <div><span class="agent-eyebrow">{{ __('agent_ui.you_and_julianna') }}</span><h2 id="agent-ask-heading">{{ __('agent_ui.ask_heading') }}</h2></div>
                    <div class="agent-conversation-actions"><label class="sr-only" for="agent-conversation-select">{{ __('agent_ui.past_conversations') }}</label><select id="agent-conversation-select" aria-label="{{ __('agent_ui.past_conversations') }}"><option value="">{{ __('agent_ui.new_conversation') }}</option></select><button type="button" class="agent-text-button" id="agent-new-conversation">{{ __('agent_ui.new_conversation') }}</button></div>
                </div>
                <div id="agent-turns" class="agent-turns" role="log" aria-live="polite" aria-relevant="additions text">
                    <p class="agent-empty">{{ __('agent_ui.conversation_empty') }}</p>
                </div>
                <form id="agent-compose" class="agent-compose">
                    @csrf
                    <label for="agent-message" class="sr-only">{{ __('agent_ui.message_label') }}</label>
                    <textarea id="agent-message" rows="3" maxlength="10000" required placeholder="{{ __('agent_ui.prompt') }}"></textarea>
                    <div class="agent-compose-footer"><span>{{ __('agent_ui.no_approval_note') }}</span><button type="submit" class="btn btn-primary" id="agent-send">{{ __('agent_ui.send') }}</button></div>
                </form>
                <p class="agent-feedback" id="agent-feedback" role="alert" hidden></p>
            </section>

            <div class="agent-side-stack">
                <section class="agent-card" aria-labelledby="agent-input-heading">
                    <div class="agent-card-heading"><div><span class="agent-eyebrow">{{ __('agent_ui.judgment') }}</span><h2 id="agent-input-heading">{{ __('agent_ui.needs_input') }}</h2></div></div>
                    <div id="agent-questions"><p class="agent-empty">{{ __('agent_ui.no_questions') }}</p></div>
                </section>
                <section class="agent-card" aria-labelledby="agent-drafts-heading">
                    <div class="agent-card-heading"><div><span class="agent-eyebrow">{{ __('agent_ui.communications') }}</span><h2 id="agent-drafts-heading">{{ __('agent_ui.drafts') }}</h2></div></div>
                    <div id="agent-drafts"><p class="agent-empty">{{ __('agent_ui.no_drafts') }}</p></div>
                </section>
            </div>
        </div>

        <div class="agent-bottom-grid">
            <section class="agent-card" aria-labelledby="agent-activity-heading"><div class="agent-card-heading"><div><span class="agent-eyebrow">{{ __('agent_ui.handled') }}</span><h2 id="agent-activity-heading">{{ __('agent_ui.recent_activity') }}</h2></div></div><div id="agent-activities"><p class="agent-empty">{{ __('agent_ui.choose_project_activity') }}</p></div></section>
            <section class="agent-card" aria-labelledby="agent-runs-heading"><div class="agent-card-heading"><div><span class="agent-eyebrow">{{ __('agent_ui.upkeep') }}</span><h2 id="agent-runs-heading">{{ __('agent_ui.recent_runs') }}</h2></div></div><div id="agent-runs"><p class="agent-empty">{{ __('agent_ui.choose_project_activity') }}</p></div></section>
        </div>
        <p class="agent-archive-link"><a href="{{ BASE_URL }}/agent/archive">{{ __('agent_ui.open_archive') }}</a></p>
    </div>
</main>

@push('styles') @include('agentui::partials.styles') @endpush
@endsection
