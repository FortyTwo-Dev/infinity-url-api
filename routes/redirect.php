<?php

declare(strict_types=1);

use App\Http\Controllers\RedirectController;
use App\Http\Middleware\LimitLinkNotFound;
use Illuminate\Support\Facades\Route;

Route::domain((string) config('redirect.host'))
    ->get('/{slug}', RedirectController::class)
    ->where('slug', '[A-Za-z0-9_-]{2,64}')
    ->middleware(LimitLinkNotFound::class)
    ->name('redirect');
