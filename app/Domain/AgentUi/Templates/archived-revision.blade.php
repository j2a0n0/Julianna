@extends($layout)

@section('content')
<div class="pageheader"><div class="pageicon"><span class="fa fa-clock-rotate-left" aria-hidden="true"></span></div><div class="pagetitle"><h5><a href="{{ BASE_URL }}/agent/archive/{{ (int) $room['id'] }}">{{ $room['title'] ?? __('idea_room.untitled') }}</a></h5><h1>{{ __('agent_ui.revision') }} {{ (int) $revision['id'] }}</h1></div></div>
<main class="maincontent agent-page"><div class="maincontentinner">
    <div class="agent-archive-intro"><p>{{ $revision['summary'] ?? '' }} · {{ $revision['createdAt'] ?? '' }}</p><span class="agent-readonly">{{ __('agent_ui.read_only') }}</span></div>
    <div class="agent-archive-grid">
        @include('agentui::partials.archive-plan', ['plan' => $revision['plan'] ?? []])
        @include('agentui::partials.archive-canvas', ['canvas' => $revision['graph'] ?? []])
    </div>
</div></main>
@push('styles') @include('agentui::partials.styles') @endpush
@endsection
