@if ($temporaryAiEnabled ?? false)
    <details class="idea-room-testing-ai" data-testing-ai data-config-url="{{ BASE_URL }}/idea-room/testing-ai" @if (! ($providerConfigured ?? false)) open @endif>
        <summary>{{ __('idea_room.testing_ai_title') }}</summary>
        <p>{{ __('idea_room.testing_ai_note') }}</p>
        @if ($temporaryAi ?? null)
            <p class="idea-room-testing-ai-current">{{ __('idea_room.testing_ai_active') }}: {{ $temporaryAi['provider'] }} / {{ $temporaryAi['model'] }}. {{ __('idea_room.testing_ai_expires') }} {{ date('H:i', (int) $temporaryAi['expiresAt']) }}.</p>
        @endif
        <form class="idea-room-testing-ai-form" autocomplete="off">
            @csrf
            <div>
                <label for="idea-room-testing-provider">{{ __('idea_room.testing_ai_provider') }}</label>
                <select id="idea-room-testing-provider" name="provider" required>
                    <option value="deepseek" @selected(($temporaryAi['provider'] ?? '') === 'deepseek')>DeepSeek</option>
                    <option value="kimi" @selected(($temporaryAi['provider'] ?? '') === 'kimi')>Kimi</option>
                    <option value="openai" @selected(($temporaryAi['provider'] ?? '') === 'openai')>OpenAI</option>
                    <option value="anthropic" @selected(($temporaryAi['provider'] ?? '') === 'anthropic')>Anthropic</option>
                </select>
            </div>
            <div>
                <label for="idea-room-testing-model">{{ __('idea_room.testing_ai_model') }}</label>
                <input id="idea-room-testing-model" name="model" type="text" maxlength="100" list="idea-room-testing-models" value="{{ $temporaryAi['model'] ?? 'deepseek-flash' }}" required autocomplete="off" spellcheck="false">
                <datalist id="idea-room-testing-models">
                    <option value="deepseek-flash"></option><option value="deepseek-v4-pro"></option>
                    <option value="kimi-k3"></option><option value="kimi-k2.6"></option>
                </datalist>
            </div>
            <div>
                <label for="idea-room-testing-key">{{ __('idea_room.testing_ai_key') }}</label>
                <input id="idea-room-testing-key" name="apiKey" type="password" maxlength="512" required autocomplete="off" spellcheck="false">
            </div>
            <div class="idea-room-testing-ai-actions">
                <button type="submit" class="btn btn-primary">{{ __('idea_room.testing_ai_save') }}</button>
                @if ($temporaryAi ?? null)<button type="button" class="btn btn-default" data-testing-ai-clear>{{ __('idea_room.testing_ai_clear') }}</button>@endif
            </div>
            <p class="idea-room-feedback" data-testing-ai-feedback role="status" aria-live="polite" hidden></p>
        </form>
    </details>
@endif
