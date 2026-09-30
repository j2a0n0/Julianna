<section class="agent-card" aria-labelledby="archive-plan-heading"><h2 id="archive-plan-heading">{{ __('agent_ui.plan') }}</h2>
    @if (! empty($plan['outcome']))<p><strong>{{ __('agent_ui.outcome') }}</strong><br>{{ $plan['outcome'] }}</p>@endif
    @if (! empty($plan['milestones']))<h3>{{ __('agent_ui.milestones') }}</h3><ul>@foreach ($plan['milestones'] as $milestone)<li><strong>{{ $milestone['title'] ?? '' }}</strong>@if (! empty($milestone['tasks']))<ul>@foreach ($milestone['tasks'] as $task)<li>{{ $task['title'] ?? '' }}</li>@endforeach</ul>@endif</li>@endforeach</ul>@endif
    @if (! empty($plan['tasks']))<h3>{{ __('agent_ui.tasks') }}</h3><ul>@foreach ($plan['tasks'] as $task)<li>{{ $task['title'] ?? '' }}</li>@endforeach</ul>@endif
    @if (! empty($plan['assumptions']))<h3>{{ __('agent_ui.assumptions') }}</h3><ul>@foreach ($plan['assumptions'] as $item)<li>{{ $item }}</li>@endforeach</ul>@endif
    @if (! empty($plan['openQuestions']))<h3>{{ __('agent_ui.open_questions') }}</h3><ul>@foreach ($plan['openQuestions'] as $item)<li>{{ $item }}</li>@endforeach</ul>@endif
    @if (empty($plan['outcome']) && empty($plan['milestones']) && empty($plan['tasks']) && empty($plan['assumptions']) && empty($plan['openQuestions']))<p class="agent-empty">{{ __('agent_ui.plan_empty') }}</p>@endif
</section>
