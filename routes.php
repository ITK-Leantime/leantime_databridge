<?php

use Illuminate\Support\Facades\Route;
use Leantime\Plugins\Databridge\Controllers\Api;
use Leantime\Plugins\Databridge\Middleware\ApiKeyAuth;

Route::match(['get', 'post'], '/api/databridge/tickets', function () {
    $controller = app()->make(Api::class);
    $controller->init(app()->make(\Leantime\Plugins\Databridge\Services\Databridge::class));

    $input = array_merge(request()->query(), request()->json()->all());

    return $controller->tickets($input);
})->middleware(ApiKeyAuth::class.':read');
