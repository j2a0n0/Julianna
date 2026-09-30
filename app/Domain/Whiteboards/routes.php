<?php

use Illuminate\Support\Facades\Route;
use Leantime\Core\Middleware\VerifyCsrfToken;
use Leantime\Domain\Whiteboards\Controllers\WhiteboardController;

Route::middleware([VerifyCsrfToken::class])->group(static function (): void {
    Route::get('/whiteboards/projects/{projectId}', [WhiteboardController::class, 'index'])->whereNumber('projectId')->name('whiteboards.index');
    Route::post('/whiteboards/projects/{projectId}', [WhiteboardController::class, 'create'])->whereNumber('projectId');
    Route::get('/whiteboards/{boardId}', [WhiteboardController::class, 'show'])->whereNumber('boardId')->name('whiteboards.show');
    Route::get('/whiteboards/{boardId}/scene', [WhiteboardController::class, 'scene'])->whereNumber('boardId');
    Route::put('/whiteboards/{boardId}/scene', [WhiteboardController::class, 'saveScene'])->whereNumber('boardId');
    Route::get('/whiteboards/{boardId}/revisions', [WhiteboardController::class, 'revisions'])->whereNumber('boardId');
    Route::get('/whiteboards/{boardId}/revisions/{revision}', [WhiteboardController::class, 'revision'])->whereNumber('boardId')->whereNumber('revision');
    Route::post('/whiteboards/{boardId}/revisions/{revision}/restore', [WhiteboardController::class, 'restoreRevision'])->whereNumber('boardId')->whereNumber('revision');
});
