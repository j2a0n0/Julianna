<section class="agent-card" aria-labelledby="archive-canvas-heading"><h2 id="archive-canvas-heading">{{ __('agent_ui.canvas') }}</h2>
    @if (! empty($canvas['nodes']))
        <div class="agent-archive-nodes">@foreach ($canvas['nodes'] as $node)<article class="agent-archive-node"><small>{{ $node['type'] ?? '' }}</small><strong>{{ $node['title'] ?? '' }}</strong>@if (! empty($node['content']))<p>{{ $node['content'] }}</p>@endif</article>@endforeach</div>
        @if (! empty($canvas['links']))<h3>{{ __('agent_ui.connections') }}</h3><ul>@foreach ($canvas['links'] as $link)<li>#{{ $link['sourceId'] ?? '' }} → #{{ $link['targetId'] ?? '' }} · {{ $link['type'] ?? '' }}</li>@endforeach</ul>@endif
    @else<p class="agent-empty">{{ __('agent_ui.canvas_empty') }}</p>@endif
</section>
