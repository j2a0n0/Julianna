<?php

use Illuminate\Support\Facades\Route;
use Leantime\Core\Middleware\VerifyCsrfToken;
use Leantime\Domain\IdeaRoom\Controllers\IdeaRoomController;

Route::middleware([VerifyCsrfToken::class])->group(function (): void {
    Route::get('/idea-room', [IdeaRoomController::class, 'index'])->name('idea-room.index');
    Route::post('/idea-room', [IdeaRoomController::class, 'create'])->name('idea-room.create');
    Route::post('/idea-room/{id}/messages', [IdeaRoomController::class, 'send'])->whereNumber('id');
    Route::put('/idea-room/{id}/plan', [IdeaRoomController::class, 'savePlan'])->whereNumber('id');
    Route::post('/idea-room/{id}/approve', [IdeaRoomController::class, 'approve'])->whereNumber('id');
    Route::get('/idea-room/{id}', [IdeaRoomController::class, 'show'])->whereNumber('id')->name('idea-room.show');
});
