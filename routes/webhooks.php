<?php

use App\Http\Controllers\Webhooks\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

// Registered outside the `web` group: no session, no CSRF (Pixelforge sends no token).
// Authenticity comes from the HMAC signature instead.
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');
