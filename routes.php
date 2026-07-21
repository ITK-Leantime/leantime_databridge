<?php

use Illuminate\Support\Facades\Route;
use Leantime\Plugins\Databridge\Controllers\Api;
use Leantime\Plugins\Databridge\Middleware\ApiKeyAuth;

/*
 * Core auth is bypassed for the whole /api/databridge prefix (see register.php), so a route
 * declared outside an ApiKeyAuth group is PUBLIC. Always declare routes inside the group
 * matching the operation they require; add a new group for write/delete endpoints.
 */
Route::middleware([ApiKeyAuth::class.':read'])->group(function (): void {
    Route::match(['get', 'post'], '/api/databridge/tickets', function () {
        $controller = app()->make(Api::class);
        $controller->init(app()->make(\Leantime\Plugins\Databridge\Services\Databridge::class));

        $input = array_merge(request()->query(), request()->json()->all());

        return $controller->tickets($input);
    });
});
