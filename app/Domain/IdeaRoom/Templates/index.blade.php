@extends($layout)

@section('content')
<div class="pageheader">
    <div class="pageicon"><span class="fa fa-lightbulb" aria-hidden="true"></span></div>
    <div class="pagetitle">
        <h5>{{ __('idea_room.workspace') }}</h5>
        <h1>{{ __('idea_room.title') }}</h1>
    </div>
</div>

<div class="maincontent idea-room-page" data-idea-room-index
     data-create-url="{{ BASE_URL }}/idea-room"
     data-error="{{ __('idea_room.request_failed') }}"
     data-creating="{{ __('idea_room.creating') }}">
    <div class="maincontentinner">
        <p class="idea-room-lead">{{ __('idea_room.intro') }}</p>

        @if (! ($providerConfigured ?? false))
            <div class="alert alert-info" role="status">
                <strong>{{ __('idea_room.setup_title') }}</strong>
                {{ __('idea_room.setup_body') }}
            </div>
        @endif

        <div class="idea-room-index-grid">
            <section class="idea-room-card" aria-labelledby="idea-room-start-heading">
                <h2 id="idea-room-start-heading">{{ __('idea_room.start') }}</h2>
                <p>{{ __('idea_room.start_hint') }}</p>
                <form id="idea-room-create-form">
                    @csrf
                    <label for="idea-room-idea">{{ __('idea_room.idea_label') }}</label>
                    <textarea id="idea-room-idea" name="idea" rows="5" maxlength="5000"
                              placeholder="{{ __('idea_room.idea_placeholder') }}" required
                              @if (! ($providerConfigured ?? false)) disabled @endif></textarea>

                    <label for="idea-room-project">{{ __('idea_room.project_label') }}</label>
                    <select id="idea-room-project" name="projectId"
                            @if (! ($canCreateProject ?? false)) required @endif
                            @if (! ($providerConfigured ?? false)) disabled @endif>
                        @if ($canCreateProject ?? false)
                            <option value="">{{ __('idea_room.blank_project') }}</option>
                        @else
                            <option value="" disabled selected>{{ __('idea_room.choose_project') }}</option>
                        @endif
                        @foreach (($projects ?? []) as $project)
                            <option value="{{ (int) ($project['id'] ?? 0) }}">{{ $project['name'] ?? '' }}</option>
                        @endforeach
                    </select>
                    @if (! ($canCreateProject ?? false))
                        <p class="idea-room-field-note">{{ __('idea_room.no_project_create') }}</p>
                    @endif

                    <p class="idea-room-field-note">{{ __('idea_room.approval_note') }}</p>
                    <button type="submit" class="btn btn-primary"
                            @if (! ($providerConfigured ?? false) || (! ($canCreateProject ?? false) && count($projects ?? []) === 0)) disabled @endif>
                        {{ __('idea_room.create_room') }}
                    </button>
                    <p class="idea-room-feedback" id="idea-room-create-feedback" role="alert" hidden></p>
                </form>
            </section>

            <section class="idea-room-card" aria-labelledby="idea-room-recent-heading">
                <h2 id="idea-room-recent-heading">{{ __('idea_room.your_rooms') }}</h2>
                @if (count($rooms ?? []) > 0)
                    <ul class="idea-room-room-list">
                        @foreach ($rooms as $room)
                            @php
                                $roomStatus = (string) ($room['status'] ?? 'active');
                                $statusKey = in_array($roomStatus, ['active', 'ready_for_review', 'approved', 'archived'], true)
                                    ? $roomStatus : 'active';
                            @endphp
                            <li>
                                <a href="{{ BASE_URL }}/idea-room/{{ (int) ($room['id'] ?? 0) }}">
                                    <span class="idea-room-room-name">{{ $room['title'] ?? $room['idea'] ?? __('idea_room.untitled') }}</span>
                                    <span class="idea-room-status idea-room-status--{{ $statusKey }}">{{ __('idea_room.status_'.$statusKey) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="idea-room-empty">{{ __('idea_room.no_rooms') }}</p>
                @endif
            </section>
        </div>
    </div>
</div>

@push('styles')
    @include('idearoom::partials.styles')
@endpush
@endsection
