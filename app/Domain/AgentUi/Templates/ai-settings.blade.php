@extends($layout)

@section('content')
@php
    $provider = (string) ($aiConfiguration['provider'] ?? '');
    $model = (string) ($aiConfiguration['model'] ?? '');
    $customModel = $model !== '';
    $hasKey = (bool) ($aiConfiguration['hasKey'] ?? false);
    $source = (string) ($aiConfiguration['source'] ?? 'none');
    $sourceLabel = match ($source) {
        'website' => __('agent_ui.ai_source_website'),
        'environment' => __('agent_ui.ai_source_environment'),
        'disabled' => __('agent_ui.ai_source_disabled'),
        default => __('agent_ui.ai_source_none'),
    };
    $statusKey = match ($aiSettingsStatus) {
        'saved' => 'agent_ui.ai_saved',
        'cleared' => 'agent_ui.ai_cleared',
        'invalid' => 'agent_ui.ai_invalid',
        'failed' => 'agent_ui.ai_save_failed',
        'not_configured' => 'agent_ui.ai_not_configured',
        'test_ok' => 'agent_ui.ai_test_ok',
        'test_failed' => 'agent_ui.ai_test_failed',
        'test_limited' => 'agent_ui.ai_test_limited',
        'requires_https' => 'agent_ui.ai_requires_https',
        default => null,
    };
@endphp
<div class="pageheader">
    <div class="pageicon"><span class="fa fa-sliders" aria-hidden="true"></span></div>
    <div class="pagetitle"><h5><a href="{{ BASE_URL }}/agent">{{ __('agent_ui.title') }}</a></h5><h1>{{ __('agent_ui.ai_settings_title') }}</h1></div>
</div>
<main class="maincontent agent-page agent-ai-settings">
    <div class="maincontentinner">
        <div class="agent-ai-settings-intro">
            <h2>{{ __('agent_ui.ai_settings_heading') }}</h2>
            <p>{{ __('agent_ui.ai_settings_intro') }}</p>
        </div>

        @if ($statusKey !== null)
            <div class="alert {{ in_array($aiSettingsStatus, ['saved', 'cleared', 'test_ok'], true) ? 'alert-success' : 'alert-warning' }}" role="status">{{ __($statusKey) }}</div>
        @endif

        <section class="agent-card" aria-labelledby="agent-ai-current-heading">
            <h2 id="agent-ai-current-heading">{{ __('agent_ui.ai_current_heading') }}</h2>
            <dl class="agent-ai-settings-state">
                <div><dt>{{ __('agent_ui.ai_status') }}</dt><dd>{{ $hasKey ? __('agent_ui.ai_key_configured') : __('agent_ui.ai_key_missing') }}</dd></div>
                <div><dt>{{ __('agent_ui.ai_source') }}</dt><dd>{{ $sourceLabel }}</dd></div>
                @if ($provider !== '')
                    <div><dt>{{ __('agent_ui.ai_provider') }}</dt><dd>{{ ucfirst($provider) }}</dd></div>
                @endif
                @if ($model !== '')
                    <div><dt>{{ __('agent_ui.ai_model') }}</dt><dd>{{ $model }}</dd></div>
                @endif
            </dl>
            <p class="agent-muted">{{ __('agent_ui.ai_key_never_shown') }}</p>
        </section>

        <section class="agent-card" aria-labelledby="agent-ai-edit-heading">
            <h2 id="agent-ai-edit-heading">{{ __('agent_ui.ai_edit_heading') }}</h2>
            <form action="{{ BASE_URL }}/agent/settings" method="post" autocomplete="off" class="agent-ai-settings-form">
                @csrf
                <label for="agent-ai-provider">{{ __('agent_ui.ai_provider') }}</label>
                <select id="agent-ai-provider" name="provider" required>
                    <option value="" @if ($provider === '') selected @endif>{{ __('agent_ui.ai_choose_provider') }}</option>
                    <option value="openai" @if ($provider === 'openai') selected @endif>OpenAI</option>
                    <option value="anthropic" @if ($provider === 'anthropic') selected @endif>Anthropic</option>
                    <option value="deepseek" @if ($provider === 'deepseek') selected @endif>DeepSeek</option>
                    <option value="kimi" @if ($provider === 'kimi') selected @endif>Kimi</option>
                </select>

                <label for="agent-ai-model-choice">{{ __('agent_ui.ai_model') }}</label>
                <select id="agent-ai-model-choice" name="model_choice" required>
                    <option value="" @if ($model === '') selected @endif>{{ __('agent_ui.ai_choose_model') }}</option>
                    <option value="__custom__" @if ($customModel) selected @endif>{{ __('agent_ui.ai_custom_model') }}</option>
                </select>
                <div id="agent-ai-load-models-wrap" @if ($provider === '') hidden @endif>
                    <button type="button" class="btn btn-default" id="agent-ai-load-models">{{ __('agent_ui.ai_load_models') }}</button>
                    <span id="agent-ai-models-status" role="status" aria-live="polite"></span>
                </div>
                <div id="agent-ai-custom-model-wrap" @if (! $customModel) hidden @endif>
                    <label for="agent-ai-model-custom">{{ __('agent_ui.ai_custom_model') }}</label>
                    <input id="agent-ai-model-custom" name="model_custom" type="text" value="{{ $customModel ? $model : '' }}" maxlength="128" @if ($customModel) required @else disabled @endif autocapitalize="off" spellcheck="false" autocomplete="off">
                </div>
                <small>{{ __('agent_ui.ai_model_help') }}</small>

                <label for="agent-ai-api-key">{{ __('agent_ui.ai_api_key') }}</label>
                <input id="agent-ai-api-key" name="api_key" type="password" value="" maxlength="2048" autocomplete="new-password" autocapitalize="off" spellcheck="false">
                <small>{{ __('agent_ui.ai_key_help') }}</small>

                <button type="submit" class="btn btn-primary">{{ __('agent_ui.ai_save') }}</button>
            </form>
        </section>

        <section class="agent-card" aria-labelledby="agent-ai-test-heading">
            <h2 id="agent-ai-test-heading">{{ __('agent_ui.ai_test_heading') }}</h2>
            <p>{{ __('agent_ui.ai_test_intro') }}</p>
            <div class="agent-ai-settings-actions">
                @if ($hasKey)
                    <form action="{{ BASE_URL }}/agent/settings/test" method="post">@csrf<button type="submit" class="btn btn-default">{{ __('agent_ui.ai_test_button') }}</button></form>
                @endif
                @if (in_array($source, ['website', 'environment'], true))
                    <form action="{{ BASE_URL }}/agent/settings/clear" method="post" data-agent-ai-clear-form>@csrf<button type="submit" class="btn btn-default">{{ __('agent_ui.ai_clear_button') }}</button></form>
                @endif
            </div>
            <p class="agent-muted">{{ __('agent_ui.ai_environment_note') }}</p>
        </section>

        <section class="agent-card" aria-labelledby="agent-web-search-heading">
            <h2 id="agent-web-search-heading">{{ __('agent_ui.web_search_heading') }}</h2>
            <p>{{ ($webSearchConfigured ?? false) ? __('agent_ui.web_search_ready') : __('agent_ui.web_search_setup') }}</p>
            @php
                $webStatusKey = match ($webSearchSettingsStatus ?? '') {
                    'saved' => 'agent_ui.web_search_saved',
                    'cleared' => 'agent_ui.web_search_cleared',
                    'requires_https' => 'agent_ui.ai_requires_https',
                    'invalid' => 'agent_ui.web_search_invalid',
                    'failed' => 'agent_ui.web_search_failed',
                    default => null,
                };
            @endphp
            @if ($webStatusKey !== null)
                <div class="alert {{ in_array($webSearchSettingsStatus, ['saved', 'cleared'], true) ? 'alert-success' : 'alert-warning' }}" role="status">{{ __($webStatusKey) }}</div>
            @endif
            <form action="{{ BASE_URL }}/agent/settings/web-search" method="post" autocomplete="off" class="agent-ai-settings-form">
                @csrf
                <label for="agent-web-search-api-key">{{ __('agent_ui.web_search_key_label') }}</label>
                <input id="agent-web-search-api-key" name="web_search_api_key" type="password" value="" maxlength="2048" autocomplete="new-password" autocapitalize="off" spellcheck="false" required>
                <small>{{ __('agent_ui.web_search_key_help') }}</small>
                <button type="submit" class="btn btn-primary">{{ __('agent_ui.web_search_save') }}</button>
            </form>
            @if (($webSearchConfiguration['hasKey'] ?? false))
                <form action="{{ BASE_URL }}/agent/settings/web-search/clear" method="post" class="agent-ai-settings-actions" data-agent-web-clear-form>
                    @csrf
                    <button type="submit" class="btn btn-default">{{ __('agent_ui.web_search_clear') }}</button>
                </form>
            @endif
            <p class="agent-muted">{{ __('agent_ui.web_search_privacy') }}</p>
        </section>
    </div>
</main>
@push('styles')
@include('agentui::partials.styles')
<style>
.agent-ai-settings .maincontentinner{max-width:800px}.agent-ai-settings-intro{margin:0 0 1rem}.agent-ai-settings .agent-card{margin-bottom:1rem}.agent-ai-settings-state{display:grid;gap:.6rem;margin:0}.agent-ai-settings-state div{display:grid;grid-template-columns:minmax(100px,150px) 1fr;gap:1rem}.agent-ai-settings-state dt{font-weight:700}.agent-ai-settings-state dd{margin:0;overflow-wrap:anywhere}.agent-ai-settings-form{display:grid;gap:.55rem}.agent-ai-settings-form label{font-weight:700;margin-top:.5rem}.agent-ai-settings-form input,.agent-ai-settings-form select{width:100%;max-width:100%;box-sizing:border-box;padding:.65rem;border:1px solid #bfcecf;border-radius:.5rem;background:#fff;color:#173744}.agent-ai-settings-form small{color:#55717b}.agent-ai-settings-form button{justify-self:start;margin-top:.8rem}.agent-ai-settings-actions{display:flex;flex-wrap:wrap;gap:.6rem;margin:1rem 0}.agent-ai-settings-actions form{margin:0}@media(max-width:600px){.agent-ai-settings-state div{grid-template-columns:1fr;gap:.1rem}}
</style>
@endpush
@push('scripts')
<script>
(() => {
    const provider = document.getElementById('agent-ai-provider');
    const modelChoice = document.getElementById('agent-ai-model-choice');
    const customWrap = document.getElementById('agent-ai-custom-model-wrap');
    const customModel = document.getElementById('agent-ai-model-custom');
    const apiKey = document.getElementById('agent-ai-api-key');
    const loadWrap = document.getElementById('agent-ai-load-models-wrap');
    const loadButton = document.getElementById('agent-ai-load-models');
    const loadStatus = document.getElementById('agent-ai-models-status');
    const csrf = document.querySelector('.agent-ai-settings-form input[name="_token"]');
    const chooseModel = @json(__('agent_ui.ai_choose_model'));
    const customLabel = @json(__('agent_ui.ai_custom_model'));
    const loadingLabel = @json(__('agent_ui.ai_loading_models'));
    const loadedLabel = @json(__('agent_ui.ai_models_loaded'));
    const failedLabel = @json(__('agent_ui.ai_models_failed'));
    const keyRequiredLabel = @json(__('agent_ui.ai_models_key_required'));

    function updateCustom() {
        const selected = modelChoice.value === '__custom__';
        customWrap.hidden = !selected;
        customModel.disabled = !selected;
        customModel.required = selected;
        if (selected) customModel.focus();
    }

    function resetModels() {
        modelChoice.replaceChildren(new Option(chooseModel, ''));
        modelChoice.add(new Option(customLabel, '__custom__'));
        modelChoice.value = '';
        customModel.value = '';
        updateCustom();
        loadWrap.hidden = provider.value === '';
        loadStatus.textContent = '';
    }

    provider.addEventListener('change', () => {
        resetModels();
    });
    modelChoice.addEventListener('change', updateCustom);

    loadButton.addEventListener('click', async () => {
        if (!provider.value) return;
        const requestedProvider = provider.value;
        loadButton.disabled = true;
        loadStatus.textContent = loadingLabel;
        try {
            const body = new FormData();
            body.set('_token', csrf.value);
            body.set('provider', requestedProvider);
            body.set('api_key', apiKey.value);
            const response = await fetch(@json(BASE_URL.'/agent/settings/models'), {
                method: 'POST', body, credentials: 'same-origin', cache: 'no-store',
                headers: {'Accept': 'application/json'},
            });
            const result = await response.json();
            if (!response.ok || !Array.isArray(result.models)) {
                throw new Error(result.error === 'key_required' ? 'key_required' : 'unavailable');
            }
            if (provider.value !== requestedProvider) return;
            const selected = modelChoice.value === '__custom__' ? customModel.value : modelChoice.value;
            modelChoice.replaceChildren(new Option(chooseModel, ''));
            for (const id of result.models) {
                if (typeof id === 'string') modelChoice.add(new Option(id, id));
            }
            modelChoice.add(new Option(customLabel, '__custom__'));
            if (selected && result.models.includes(selected)) {
                modelChoice.value = selected;
                customModel.value = '';
            } else if (selected) {
                modelChoice.value = '__custom__';
                customModel.value = selected;
            }
            updateCustom();
            loadStatus.textContent = loadedLabel;
        } catch (error) {
            loadStatus.textContent = error.message === 'key_required' ? keyRequiredLabel : failedLabel;
        } finally {
            loadButton.disabled = false;
        }
    });
    if (provider.value && @json($hasKey)) loadButton.click();

    const form = document.querySelector('[data-agent-ai-clear-form]');
    if (form) form.addEventListener('submit', event => {
        if (!window.confirm(@json(__('agent_ui.ai_clear_confirm')))) event.preventDefault();
    });
    const webClearForm = document.querySelector('[data-agent-web-clear-form]');
    if (webClearForm) webClearForm.addEventListener('submit', event => {
        if (!window.confirm(@json(__('agent_ui.web_search_clear_confirm')))) event.preventDefault();
    });
})();
</script>
@endpush
@endsection
