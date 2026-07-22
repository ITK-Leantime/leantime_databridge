<?php

use Illuminate\Support\Facades\Route;
use Leantime\Plugins\Databridge\Controllers\Api;
use Leantime\Plugins\Databridge\Middleware\ApiKeyAuth;
use Leantime\Plugins\Databridge\Model\ApiUser;

/*
 * Core auth is bypassed for the whole /api/databridge prefix (see register.php), so a route
 * declared outside an ApiKeyAuth group is PUBLIC. Always declare routes inside the group
 * matching the operation they require; add a new group for write/delete endpoints.
 */
Route::middleware([ApiKeyAuth::class.':read'])->group(function (): void {
    Route::get('/api/databridge/tickets', function () {
        $controller = app()->make(Api::class);
        $controller->init(app()->make(\Leantime\Plugins\Databridge\Services\Databridge::class));

        $apiUser = request()->attributes->get(ApiKeyAuth::REQUEST_ATTRIBUTE);

        // Fail loud if a route was wired without the ApiKeyAuth middleware: without an
        // authenticated ApiUser there is no project grant, and serving would be fail-open.
        if (! $apiUser instanceof ApiUser) {
            throw new \RuntimeException('Databridge route reached without an authenticated ApiUser — ApiKeyAuth middleware missing on the route.');
        }

        $input = request()->query();

        return $controller->tickets($input, $apiUser);
    });
});

Route::middleware([ApiKeyAuth::class.':write'])->group(function (): void {
    Route::post('/api/databridge/tickets', function () {
        $controller = app()->make(Api::class);
        $controller->init(app()->make(\Leantime\Plugins\Databridge\Services\Databridge::class));

        $apiUser = request()->attributes->get(ApiKeyAuth::REQUEST_ATTRIBUTE);

        // Fail loud if a route was wired without the ApiKeyAuth middleware: without an
        // authenticated ApiUser there is no project grant, and serving would be fail-open.
        if (! $apiUser instanceof ApiUser) {
            throw new \RuntimeException('Databridge route reached without an authenticated ApiUser — ApiKeyAuth middleware missing on the route.');
        }

        $input = request()->json()->all();

        return $controller->createTicket($input, $apiUser);
    });
});
