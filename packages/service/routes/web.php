<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Odden\Core\Http\Middleware\RequireApiToken;
use Odden\Core\Support\CsrfExemption;
use Odden\Core\Support\RouteGroup;
use Odden\Service\Http\Controllers\ChatWidgetController;
use Odden\Service\Http\Controllers\HelpCenterController;
use Odden\Service\Http\Controllers\InboundEmailWebhookController;
use Odden\Service\Http\Controllers\KnowledgeDeflectionController;
use Odden\Service\Http\Controllers\SupportPortalController;

Route::group(RouteGroup::attributes('odden-service.routes.web'), function (): void {
    // Knowledge Base / Help Center
    Route::get('/help', [HelpCenterController::class, 'index'])->name('odden.help.index');
    Route::get('/help/{slug}', [HelpCenterController::class, 'show'])->name('odden.help.show');
    Route::post('/help/{slug}/vote', [HelpCenterController::class, 'vote'])
        ->middleware('throttle:odden-public')
        ->name('odden.help.vote');

    // Customer Support Ticket Portal
    Route::get('/support', [SupportPortalController::class, 'create'])->name('odden.support.create');
    Route::post('/support', [SupportPortalController::class, 'store'])
        ->middleware('throttle:odden-public')
        ->name('odden.support.store');
    Route::get('/support/tickets/{token}', [SupportPortalController::class, 'show'])->name('odden.support.show');
    Route::post('/support/tickets/{token}/reply', [SupportPortalController::class, 'reply'])
        ->middleware('throttle:odden-public')
        ->name('odden.support.reply');
    Route::get('/support/rate/{token}', [SupportPortalController::class, 'rate'])->name('odden.support.rate');
    Route::post('/support/rate/{token}', [SupportPortalController::class, 'submitRating'])
        ->middleware('throttle:odden-public')
        ->name('odden.support.submitRating');
});

Route::group(RouteGroup::attributes('odden-service.routes.api'), function (): void {
    // Inbound Email-to-Ticket Webhook: server-to-server, requires the service API token.
    Route::post('/inbound-email', InboundEmailWebhookController::class)
        ->withoutMiddleware(CsrfExemption::middleware())
        ->middleware([RequireApiToken::class.':odden-service.api.token', 'throttle:odden-api'])
        ->name('odden.service.inbound-email');

    // AI Knowledge Deflection & Smart Suggestions
    Route::get('/knowledge/suggest', [KnowledgeDeflectionController::class, 'suggest'])
        ->middleware('throttle:odden-poll')
        ->name('odden.service.knowledge.suggest');
    Route::post('/knowledge/deflect', [KnowledgeDeflectionController::class, 'deflect'])
        ->withoutMiddleware(CsrfExemption::middleware())
        ->middleware('throttle:odden-public')
        ->name('odden.service.knowledge.deflect');

    // Embeddable Web Chat & Support Messenger
    Route::post('/chat/start', [ChatWidgetController::class, 'start'])
        ->withoutMiddleware(CsrfExemption::middleware())
        ->middleware('throttle:odden-public')
        ->name('odden.service.chat.start');
    Route::post('/chat/{token}/message', [ChatWidgetController::class, 'message'])
        ->withoutMiddleware(CsrfExemption::middleware())
        ->middleware('throttle:odden-public')
        ->name('odden.service.chat.message');
    Route::get('/chat/{token}/messages', [ChatWidgetController::class, 'messages'])
        ->middleware('throttle:odden-poll')
        ->name('odden.service.chat.messages');
});
