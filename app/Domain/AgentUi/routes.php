<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Leantime\Core\Middleware\VerifyCsrfToken;
use Leantime\Domain\AgentUi\Controllers\AgentUiController;
use Leantime\Domain\AgentUi\Controllers\AiSettingsController;

Route::get('/agent', [AgentUiController::class, 'show'])->name('agent.index');
Route::get('/agent/projects/{projectId}', [AgentUiController::class, 'project'])->whereNumber('projectId')->name('agent.project');
Route::get('/agent/ui/context', [AgentUiController::class, 'context']);
Route::get('/agent/archive', [AgentUiController::class, 'archive'])->name('agent.archive');
Route::get('/agent/archive/{roomId}', [AgentUiController::class, 'archivedRoom'])->whereNumber('roomId')->name('agent.archive.room');
Route::get('/agent/archive/{roomId}/revisions/{entryId}', [AgentUiController::class, 'archivedRevision'])
    ->whereNumber('roomId')->whereNumber('entryId');

Route::middleware([VerifyCsrfToken::class])->group(static function (): void {
    Route::get('/agent/settings', [AiSettingsController::class, 'show'])->name('agent.settings');
    Route::post('/agent/settings', [AiSettingsController::class, 'save']);
    Route::post('/agent/settings/test', [AiSettingsController::class, 'test']);
    Route::post('/agent/settings/models', [AiSettingsController::class, 'models']);
    Route::post('/agent/settings/clear', [AiSettingsController::class, 'clear']);
    Route::post('/agent/settings/web-search', [AiSettingsController::class, 'saveWebSearch']);
    Route::post('/agent/settings/web-search/clear', [AiSettingsController::class, 'clearWebSearch']);
});
