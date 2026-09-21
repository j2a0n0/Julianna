@extends($layout)

@section('content')
@php
    $roomStatus = (string) ($room['status'] ?? 'active');
    $statusKey = in_array($roomStatus, ['active', 'ready_for_review', 'approved', 'archived'], true)
        ? $roomStatus : 'active';
    $roomId = (int) ($room['id'] ?? 0);
    $editable = (bool) ($canEdit ?? false) && ! in_array($roomStatus, ['approved', 'archived'], true);
    $approvable = (bool) ($canApprove ?? false) && ! in_array($roomStatus, ['approved', 'archived'], true);
@endphp
<div class="pageheader">
    <div class="pageicon"><span class="fa fa-lightbulb" aria-hidden="true"></span></div>
    <div class="pagetitle">
        <h5><a href="{{ BASE_URL }}/idea-room">{{ __('idea_room.title') }}</a></h5>
        <h1>{{ $room['title'] ?? $room['idea'] ?? __('idea_room.untitled') }}</h1>
    </div>
</div>

<div class="maincontent idea-room-page idea-room-workspace" data-idea-room
     data-room-id="{{ $roomId }}"
     data-messages-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/messages"
     data-plan-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/plan"
     data-approve-url="{{ BASE_URL }}/idea-room/{{ $roomId }}/approve"
     data-can-edit="{{ $editable ? '1' : '0' }}"
     data-can-approve="{{ $approvable ? '1' : '0' }}"
     data-status="{{ $statusKey }}"
     data-request-failed="{{ __('idea_room.request_failed') }}"
     data-send="{{ __('idea_room.send') }}"
     data-sending="{{ __('idea_room.sending') }}"
     data-saving="{{ __('idea_room.saving') }}"
     data-save="{{ __('idea_room.save_for_review') }}"
     data-approving="{{ __('idea_room.approving') }}"
     data-confirm-approve="{{ __('idea_room.confirm_approve') }}"
     data-unsaved="{{ __('idea_room.unsaved_warning') }}"
     data-saved="{{ __('idea_room.saved_for_review') }}"
     data-approved="{{ __('idea_room.approved_message') }}"
     data-assistant-label="{{ __('idea_room.assistant') }}"
     data-you-label="{{ __('idea_room.you') }}"
     data-approve-label="{{ __('idea_room.approve_and_create') }}"
     data-status-active="{{ __('idea_room.status_active') }}"
     data-status-ready-for-review="{{ __('idea_room.status_ready_for_review') }}"
     data-status-approved="{{ __('idea_room.status_approved') }}"
     data-project-name-label="{{ __('idea_room.project_name') }}"
     data-outcome-label="{{ __('idea_room.outcome') }}"
     data-title-label="{{ __('idea_room.item_title') }}"
     data-description-label="{{ __('idea_room.description') }}"
     data-milestone-label="{{ __('idea_room.milestone') }}"
     data-task-label="{{ __('idea_room.task') }}"
     data-remove-label="{{ __('idea_room.remove') }}"
     data-add-task-label="{{ __('idea_room.add_task') }}">
    <div class="maincontentinner">
        <div class="idea-room-topline">
            <a href="{{ BASE_URL }}/idea-room" class="idea-room-back">&larr; {{ __('idea_room.all_rooms') }}</a>
            <span id="idea-room-status" class="idea-room-status idea-room-status--{{ $statusKey }}">{{ __('idea_room.status_'.$statusKey) }}</span>
        </div>

        @if (! ($providerConfigured ?? false))
            <div class="alert alert-info" role="status">
                <strong>{{ __('idea_room.setup_title') }}</strong> {{ __('idea_room.setup_body') }}
            </div>
        @endif

        <div class="idea-room-columns">
            <section class="idea-room-card idea-room-chat" aria-labelledby="idea-room-chat-heading">
                <div class="idea-room-panel-heading">
                    <div>
                        <h2 id="idea-room-chat-heading">{{ __('idea_room.conversation') }}</h2>
                        <p>{{ __('idea_room.conversation_hint') }}</p>
                    </div>
                </div>
                <div class="idea-room-messages" id="idea-room-messages" aria-live="polite" aria-relevant="additions text">
                    @forelse (($messages ?? []) as $message)
                        @php $messageRole = ($message['role'] ?? '') === 'assistant' ? 'assistant' : 'user'; @endphp
                        <article class="idea-room-message idea-room-message--{{ $messageRole }}">
                            <span class="idea-room-message-author">{{ $messageRole === 'assistant' ? __('idea_room.assistant') : __('idea_room.you') }}</span>
                            <p>{{ $message['content'] ?? '' }}</p>
                        </article>
                    @empty
                        <p id="idea-room-no-messages" class="idea-room-empty">{{ __('idea_room.no_messages') }}</p>
                    @endforelse
                </div>
                @if ($editable)
                    <form id="idea-room-message-form" class="idea-room-compose">
                        @csrf
                        <label for="idea-room-message">{{ __('idea_room.your_message') }}</label>
                        <textarea id="idea-room-message" rows="3" maxlength="10000" required
                                  placeholder="{{ __('idea_room.message_placeholder') }}"
                                  @if (! ($providerConfigured ?? false)) disabled @endif></textarea>
                        <div class="idea-room-compose-footer">
                            <span>{{ __('idea_room.chat_note') }}</span>
                            <button type="submit" class="btn btn-primary" @if (! ($providerConfigured ?? false)) disabled @endif>{{ __('idea_room.send') }}</button>
                        </div>
                    </form>
                @endif
            </section>

            <section class="idea-room-card idea-room-plan" aria-labelledby="idea-room-plan-heading">
                <div class="idea-room-panel-heading">
                    <div>
                        <h2 id="idea-room-plan-heading">{{ __('idea_room.action_plan') }}</h2>
                        <p>{{ __('idea_room.plan_hint') }}</p>
                    </div>
                </div>
                <p class="idea-room-target">
                    @if (! empty($room['project_id'] ?? $room['projectId'] ?? null))
                        {{ __('idea_room.existing_project') }}: <strong>{{ $room['project_name'] ?? $room['projectName'] ?? __('idea_room.project') }}</strong>
                    @else
                        {{ __('idea_room.new_project_on_approval') }}
                    @endif
                </p>
                <form id="idea-room-plan-form">
                    @csrf
                    @if (empty($room['project_id'] ?? $room['projectId'] ?? null))
                        <div class="idea-room-field">
                            <label for="idea-room-project-name">{{ __('idea_room.project_name') }}</label>
                            <input id="idea-room-project-name" name="projectName" type="text" maxlength="255" @if (! $editable) readonly @endif>
                        </div>
                    @endif
                    <div class="idea-room-field">
                        <label for="idea-room-outcome">{{ __('idea_room.outcome') }}</label>
                        <textarea id="idea-room-outcome" name="outcome" rows="3" @if (! $editable) readonly @endif></textarea>
                    </div>

                    <div class="idea-room-plan-section">
                        <div class="idea-room-section-heading"><h3>{{ __('idea_room.milestones') }}</h3>
                            @if ($editable)<button type="button" class="idea-room-text-button" data-add="milestone">+ {{ __('idea_room.add_milestone') }}</button>@endif
                        </div>
                        <div id="idea-room-milestones" class="idea-room-edit-list"></div>
                    </div>
                    <div class="idea-room-plan-section">
                        <div class="idea-room-section-heading"><h3>{{ __('idea_room.next_actions') }}</h3>
                            @if ($editable)<button type="button" class="idea-room-text-button" data-add="task">+ {{ __('idea_room.add_task') }}</button>@endif
                        </div>
                        <div id="idea-room-tasks" class="idea-room-edit-list"></div>
                    </div>
                    <div class="idea-room-plan-section">
                        <div class="idea-room-section-heading"><h3>{{ __('idea_room.assumptions') }}</h3>
                            @if ($editable)<button type="button" class="idea-room-text-button" data-add="assumption">+ {{ __('idea_room.add_assumption') }}</button>@endif
                        </div>
                        <div id="idea-room-assumptions" class="idea-room-edit-list"></div>
                    </div>
                    <div class="idea-room-plan-section">
                        <div class="idea-room-section-heading"><h3>{{ __('idea_room.open_questions') }}</h3>
                            @if ($editable)<button type="button" class="idea-room-text-button" data-add="question">+ {{ __('idea_room.add_question') }}</button>@endif
                        </div>
                        <div id="idea-room-questions" class="idea-room-edit-list"></div>
                    </div>

                    <div class="idea-room-review">
                        <p>{{ __('idea_room.review_note') }}</p>
                        @if ($editable)
                            <button type="submit" class="btn btn-default" id="idea-room-save">{{ __('idea_room.save_for_review') }}</button>
                        @endif
                        @if ($approvable)
                            <button type="button" class="btn btn-primary" id="idea-room-approve"
                                    @if ($statusKey !== 'ready_for_review') disabled @endif>{{ __('idea_room.approve_and_create') }}</button>
                        @endif
                    </div>
                </form>
            </section>
        </div>
        <p class="idea-room-feedback" id="idea-room-feedback" role="status" aria-live="polite" hidden></p>
    </div>
</div>
<script type="application/json" id="idea-room-initial-plan">@json($plan ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>

@push('styles')
    @include('idearoom::partials.styles')
@endpush
@endsection
