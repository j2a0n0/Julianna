<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Existing installations cache their domain directory list. Requiring the new
// HTML route file here keeps /agent available immediately after an upgrade;
// require_once prevents duplicate registration once the cache is refreshed.
require_once APP_ROOT.'/app/Domain/AgentUi/routes.php';

/*
 * Idea Room is a preserved, read-only archive. The old agent, proposal,
 * confirmation, canvas and chat mutation endpoints must never be executable
 * after rollout, including when an old browser tab or MCP client retries.
 */
Route::get('/idea-room', static fn () => redirect(BASE_URL.'/agent/archive', 302));
Route::get('/idea-room/projects/{projectId}/agent', static fn (int $projectId) => redirect(BASE_URL.'/agent/projects/'.$projectId, 302))
    ->whereNumber('projectId');
Route::get('/idea-room/{id}', static fn (int $id) => redirect(BASE_URL.'/agent/archive/'.$id, 302))
    ->whereNumber('id');
Route::get('/idea-room/{id}/graph', static fn (int $id) => redirect(BASE_URL.'/agent/archive/'.$id.'#archive-canvas-heading', 302))
    ->whereNumber('id');
Route::get('/idea-room/{id}/history', static fn (int $id) => redirect(BASE_URL.'/agent/archive/'.$id.'#archive-history-heading', 302))
    ->whereNumber('id');

$ideaRoomRetired = static fn () => response()->json([
    'error' => 'Idea Room is now a read-only archive. Open /agent for new work.',
], 410);
Route::match(['POST', 'PUT', 'PATCH', 'DELETE'], '/idea-room', $ideaRoomRetired);
Route::any('/idea-room/{legacy}', $ideaRoomRetired)->where('legacy', '.*');
