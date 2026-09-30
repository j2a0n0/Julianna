@extends($layout)

@section('content')
<div class="pageheader"><div class="pageicon"><span class="fa fa-box-archive" aria-hidden="true"></span></div><div class="pagetitle"><h5><a href="{{ BASE_URL }}/agent">{{ __('agent_ui.title') }}</a></h5><h1>{{ __('agent_ui.archive_title') }}</h1></div></div>
<main class="maincontent agent-page"><div class="maincontentinner">
    <div class="agent-archive-intro"><p>{{ __('agent_ui.archive_intro') }}</p><span class="agent-readonly">{{ __('agent_ui.read_only') }}</span></div>
    <section class="agent-card" aria-labelledby="agent-archive-list-heading">
        <h2 id="agent-archive-list-heading">{{ __('agent_ui.past_ideas') }}</h2>
        @forelse ($rooms as $room)
            <a class="agent-archive-row" href="{{ BASE_URL }}/agent/archive/{{ (int) $room['id'] }}"><span><strong>{{ $room['title'] ?? __('idea_room.untitled') }}</strong><small>{{ $room['project_name'] ?? __('agent_ui.no_project') }} · {{ $room['updated_at'] ?? '' }}</small></span><span class="agent-readonly">{{ __('agent_ui.view') }} →</span></a>
        @empty
            <p class="agent-empty">{{ __('agent_ui.archive_empty') }}</p>
        @endforelse
    </section>
    @if ($nextCursor)
        <p><a class="btn btn-default" href="{{ BASE_URL }}/agent/archive?beforeId={{ $nextCursor }}">{{ __('agent_ui.older_rooms') }}</a></p>
    @endif
</div></main>
@push('styles') @include('agentui::partials.styles') @endpush
@endsection
