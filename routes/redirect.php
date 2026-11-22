<?php

declare(strict_types=1);

use App\Http\Controllers\RedirectController;
use App\Http\Controllers\ReportRedirectController;
use App\Http\Middleware\LimitLinkNotFound;
use Illuminate\Support\Facades\Route;

Route::domain((string) config('redirect.host'))
    ->get('/{slug}', RedirectController::class)
    ->where('slug', '[A-Za-z0-9_-]{2,64}')
    ->middleware(LimitLinkNotFound::class)
    ->name('redirect');

// `iurl.fr` stays 100 % redirects, including the report entry point. `report`
// is a reserved slug, and a two-segment path cannot collide with a slug.
Route::domain((string) config('redirect.host'))
    ->get('/report/{slug}', ReportRedirectController::class)
    ->where('slug', '[A-Za-z0-9_-]{2,64}')
    ->name('report.redirect');
