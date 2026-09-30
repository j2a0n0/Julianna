<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Leantime\Core\Middleware\VerifyCsrfToken;
use Leantime\Domain\Agent\Controllers\AgentApiController;

Route::middleware([VerifyCsrfToken::class])->prefix('agent/api')->group(function (): void {
    Route::get('/conversations', [AgentApiController::class, 'listConversations']);
    Route::get('/questions', [AgentApiController::class, 'questions']);
    Route::post('/conversations', [AgentApiController::class, 'createConversation']);
    Route::get('/conversations/{conversationId}', [AgentApiController::class, 'showConversation'])->whereNumber('conversationId');
    Route::post('/conversations/{conversationId}/turns', [AgentApiController::class, 'turn'])->whereNumber('conversationId');
    Route::get('/projects/{projectId}', [AgentApiController::class, 'project'])->whereNumber('projectId');
    Route::post('/projects/{projectId}/settings', [AgentApiController::class, 'configureProject'])->whereNumber('projectId');
    Route::post('/activities/{activityId}/undo', [AgentApiController::class, 'undo'])->whereNumber('activityId');
    Route::post('/drafts/{draftId}/publish', [AgentApiController::class, 'publishDraft'])->whereNumber('draftId');
    Route::post('/drafts/{draftId}/discard', [AgentApiController::class, 'discardDraft'])->whereNumber('draftId');
});
