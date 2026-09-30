@extends($layout)

@section('content')
<div class="pageheader"><div class="pageicon"><span class="fa fa-box-archive" aria-hidden="true"></span></div><div class="pagetitle"><h5><a href="{{ BASE_URL }}/agent/archive">{{ __('agent_ui.archive_title') }}</a></h5><h1>{{ $room['title'] ?? __('idea_room.untitled') }}</h1></div></div>
<main class="maincontent agent-page"><div class="maincontentinner">
    <div class="agent-archive-intro"><p>{{ __('agent_ui.archive_room_intro') }}</p><span class="agent-readonly">{{ __('agent_ui.read_only') }}</span></div>
    <div class="agent-archive-grid">
        <section class="agent-card" aria-labelledby="archive-transcript-heading"><h2 id="archive-transcript-heading">{{ __('agent_ui.transcript') }}</h2>
            @forelse ($messages as $message)
                @if (in_array(($message['role'] ?? ''), ['user', 'assistant'], true))
                    <article class="agent-archive-message"><strong>{{ ($message['role'] ?? '') === 'assistant' ? __('agent_ui.assistant') : __('agent_ui.you') }}</strong><small>{{ $message['created_at'] ?? '' }}</small><p>{{ $message['content'] ?? '' }}</p></article>
                @endif
            @empty<p class="agent-empty">{{ __('agent_ui.no_transcript') }}</p>@endforelse
        </section>
        <div class="agent-side-stack">
            @include('agentui::partials.archive-plan', ['plan' => $room['plan'] ?? []])
            @include('agentui::partials.archive-canvas', ['canvas' => $graph])
            @if (! empty($sources))
                <section class="agent-card" aria-labelledby="archive-sources-heading"><h2 id="archive-sources-heading">{{ __('agent_ui.sources') }}</h2><ul>@foreach ($sources as $source)<li><a href="{{ $source['url'] ?? '#' }}" target="_blank" rel="noopener noreferrer">{{ $source['title'] ?? $source['url'] ?? '' }}</a></li>@endforeach</ul></section>
            @endif
            <section class="agent-card" aria-labelledby="archive-history-heading"><h2 id="archive-history-heading">{{ __('agent_ui.history') }}</h2>
                @forelse ($history as $entry)
                    <a class="agent-archive-row" href="{{ BASE_URL }}/agent/archive/{{ (int) $room['id'] }}/revisions/{{ (int) $entry['id'] }}"><span><strong>{{ $entry['summary'] ?? __('agent_ui.revision') }}</strong><small>{{ $entry['createdAt'] ?? '' }} · {{ __('agent_ui.revision') }} {{ $entry['graphVersion'] ?? '' }}</small></span><span aria-hidden="true">→</span></a>
                @empty<p class="agent-empty">{{ __('agent_ui.no_history') }}</p>@endforelse
            </section>
            @if (count($unexecutedActions ?? []) > 0)
                <section class="agent-card" aria-labelledby="archive-unexecuted-heading"><h2 id="archive-unexecuted-heading">{{ __('agent_ui.unexecuted') }}</h2><p>{{ __('agent_ui.unexecuted_note') }}</p><ul>@foreach ($unexecutedActions as $action)<li>{{ $action['tool_name'] ?? '' }} · {{ $action['created_at'] ?? '' }}</li>@endforeach</ul></section>
            @endif
        </div>
    </div>
</div></main>
@push('styles') @include('agentui::partials.styles') @endpush
@endsection
