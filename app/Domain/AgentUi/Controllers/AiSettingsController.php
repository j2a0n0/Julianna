<?php

declare(strict_types=1);

namespace Leantime\Domain\AgentUi\Controllers;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\UI\Template;
use Leantime\Domain\Agent\Services\AiConfiguration;
use Leantime\Domain\Agent\Services\WebSearchConfiguration;
use Leantime\Domain\Agent\Services\ProviderModelCatalog;
use Leantime\Domain\Auth\Models\Roles;
use Leantime\Domain\Auth\Services\Auth;
use Leantime\Domain\Auth\Support\SecureAuthRequest;
use Leantime\Domain\IdeaRoom\AI\ChatRequest;
use Leantime\Domain\Mcp\Services\WebSearch;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Owner-only, write-only website configuration for the deployment's AI provider. */
final class AiSettingsController
{
    public function __construct(
        private readonly Template $template,
        private readonly AiConfiguration $configuration,
        private readonly RateLimiter $rateLimiter,
    ) {}

    public function show(): Response
    {
        $this->assertOwner();
        $this->template->requireComponents([]);
        $this->template->assign('aiConfiguration', $this->configuration->publicState());
        $this->template->assign('webSearchConfiguration', (new WebSearchConfiguration)->publicState());
        $this->template->assign('webSearchConfigured', WebSearch::configured());
        $this->template->assign('aiSettingsStatus', (string) session('agent_ai_settings_status', ''));
        $this->template->assign('webSearchSettingsStatus', (string) session('agent_web_search_status', ''));

        return $this->noStore($this->template->display('agentui.ai-settings'));
    }

    public function save(Request $request): Response
    {
        $actorId = $this->assertOwner();
        if (! $this->allowsCredentialSubmission($request)) {
            return $this->redirectWithStatus('requires_https');
        }
        $provider = $request->input('provider');
        $model = $request->input('model_choice', $request->input('model'));
        if ($model === '__custom__') {
            $model = $request->input('model_custom');
        }
        $key = $request->input('api_key');

        if (! is_string($provider) || ! is_string($model) || (! is_string($key) && $key !== null)) {
            return $this->redirectWithStatus('invalid');
        }

        try {
            $this->configuration->save($provider, $model, $key === '' ? null : $key, $actorId);

            return $this->redirectWithStatus('saved');
        } catch (InvalidArgumentException) {
            return $this->redirectWithStatus('invalid');
        } catch (Throwable) {
            // Provider and credential details must not reach a page, flash session, or URL.
            return $this->redirectWithStatus('failed');
        }
    }

    public function clear(): Response
    {
        $actorId = $this->assertOwner();
        try {
            $this->configuration->clear($actorId);

            return $this->redirectWithStatus('cleared');
        } catch (Throwable) {
            return $this->redirectWithStatus('failed');
        }
    }

    public function saveWebSearch(Request $request): Response
    {
        $actorId = $this->assertOwner();
        if (! $this->allowsCredentialSubmission($request)) {
            return $this->redirectWithWebStatus('requires_https');
        }
        $key = $request->input('web_search_api_key');
        if (! is_string($key)) {
            return $this->redirectWithWebStatus('invalid');
        }
        try {
            (new WebSearchConfiguration)->save($key, $actorId);

            return $this->redirectWithWebStatus('saved');
        } catch (InvalidArgumentException) {
            return $this->redirectWithWebStatus('invalid');
        } catch (Throwable) {
            return $this->redirectWithWebStatus('failed');
        }
    }

    public function clearWebSearch(): Response
    {
        $actorId = $this->assertOwner();
        try {
            (new WebSearchConfiguration)->clear($actorId);

            return $this->redirectWithWebStatus('cleared');
        } catch (Throwable) {
            return $this->redirectWithWebStatus('failed');
        }
    }

    public function test(Request $request): Response
    {
        $actorId = $this->assertOwner();
        $rateKey = 'julianna-ai-settings-test:'.hash('sha256', $actorId.'|'.($request->ip() ?: 'unknown'));
        if ($this->rateLimiter->tooManyAttempts($rateKey, 3)) {
            return $this->redirectWithStatus('test_limited');
        }
        $this->rateLimiter->hit($rateKey, 3600);
        try {
            $provider = $this->configuration->provider();
            if ($provider === null) {
                return $this->redirectWithStatus('not_configured');
            }

            // A static no-tools request checks connectivity without sending any
            // project data, conversations, or credentials in the prompt.
            $provider->turn(new ChatRequest(
                messages: [['role' => 'user', 'content' => 'Reply with the word READY.']],
                locale: 'en-US',
                tools: [],
                systemPrompt: 'This is a connection check. Reply with READY. Do not request tools.',
            ));

            return $this->redirectWithStatus('test_ok');
        } catch (Throwable) {
            // Do not expose model responses or raw provider errors: they can
            // contain request metadata or credentials.
            return $this->redirectWithStatus('test_failed');
        }
    }

    public function models(Request $request, ProviderModelCatalog $catalog): Response
    {
        $actorId = $this->assertOwner();
        if (! $this->allowsCredentialSubmission($request)) {
            return $this->noStore(new JsonResponse(['error' => 'secure_connection_required'], 403));
        }
        $rateKey = 'julianna-ai-models:'.hash('sha256', $actorId.'|'.($request->ip() ?: 'unknown'));
        if ($this->rateLimiter->tooManyAttempts($rateKey, 30)) {
            return $this->noStore(new JsonResponse(['error' => 'rate_limited'], 429));
        }
        $this->rateLimiter->hit($rateKey, 3600);

        $provider = $request->input('provider');
        $submittedKey = $request->input('api_key');
        // The global ConvertEmptyStringsToNull middleware turns a blank key
        // field into null. A blank field means reuse this provider's saved key.
        if ($submittedKey === null) {
            $submittedKey = '';
        }
        if (! is_string($provider) || ! in_array($provider, ['openai', 'anthropic', 'deepseek', 'kimi'], true)
            || ! is_string($submittedKey) || strlen($submittedKey) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $submittedKey) === 1) {
            return $this->noStore(new JsonResponse(['error' => 'invalid_key'], 400));
        }
        try {
            $key = $submittedKey !== '' ? trim($submittedKey) : $this->configuration->apiKeyForProvider($provider);
            if (! is_string($key) || $key === '') {
                return $this->noStore(new JsonResponse(['error' => 'key_required'], 400));
            }

            return $this->noStore(new JsonResponse(['models' => $catalog->list($provider, $key)]));
        } catch (Throwable) {
            // Never return or log provider responses or credentials.
            return $this->noStore(new JsonResponse(['error' => 'model_list_unavailable'], 502));
        }
    }

    private function assertOwner(): int
    {
        $actorId = (int) session('userdata.id');
        if ($actorId <= 0
            || ! SecureAuthRequest::hasFullWebAuthentication()
            || ! Auth::userIsAtLeast(Roles::$owner, forceGlobalRoleCheck: true)) {
            throw new AuthorizationException;
        }

        return $actorId;
    }

    private function redirectWithStatus(string $status): Response
    {
        session()->flash('agent_ai_settings_status', $status);
        $response = new RedirectResponse(BASE_URL.'/agent/settings', Response::HTTP_FOUND);

        return $this->noStore($response);
    }

    private function redirectWithWebStatus(string $status): Response
    {
        session()->flash('agent_web_search_status', $status);

        return $this->noStore(new RedirectResponse(BASE_URL.'/agent/settings', Response::HTTP_FOUND));
    }

    private function allowsCredentialSubmission(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        // Loopback-only local development may use HTTP; public deployments
        // must terminate HTTPS (trusted proxy headers are handled by LoadConfig).
        $configuredHost = strtolower((string) parse_url(BASE_URL, PHP_URL_HOST));

        return in_array($configuredHost, ['localhost', '127.0.0.1', '::1'], true)
            && strtolower($request->getHost()) === $configuredHost;
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
