<?php

use Illuminate\Support\Facades\Route;
use Leantime\Plugins\Databridge\Controllers\Api;
use Leantime\Plugins\Databridge\Middleware\ApiKeyAuth;
use Leantime\Plugins\Databridge\Model\ApiUser;
use Leantime\Plugins\Databridge\Services\Databridge;

/**
 * Build the API controller and hand it plus the authenticated ApiUser to $handler.
 *
 * Fails loud if a route was wired without the ApiKeyAuth middleware: without an
 * authenticated ApiUser there is no project grant, and serving would be fail-open. Every
 * route below goes through here so that check can never be forgotten on a new endpoint.
 */
$databridge = function (callable $handler): callable {
    return function (string ...$routeParams) use ($handler) {
        $controller = app()->make(Api::class);
        $controller->init(app()->make(Databridge::class));

        $apiUser = request()->attributes->get(ApiKeyAuth::REQUEST_ATTRIBUTE);

        if (! $apiUser instanceof ApiUser) {
            throw new \RuntimeException('Databridge route reached without an authenticated ApiUser — ApiKeyAuth middleware missing on the route.');
        }

        return $handler($controller, $apiUser, ...$routeParams);
    };
};

/*
 * Core auth is bypassed for the whole /api/databridge prefix (see register.php), so a route
 * declared outside an ApiKeyAuth group is PUBLIC. Always declare routes inside the group
 * matching the operation they require; add a new group for delete endpoints.
 */
Route::middleware([ApiKeyAuth::class.':read'])->group(function () use ($databridge): void {
    Route::get('/api/databridge/tickets', $databridge(
        fn (Api $controller, ApiUser $apiUser) => $controller->tickets(request()->query(), $apiUser)
    ));

    Route::get('/api/databridge/tickets/{id}', $databridge(
        fn (Api $controller, ApiUser $apiUser, string $id) => $controller->ticket((int) $id, $apiUser)
    ))->whereNumber('id');

    Route::get('/api/databridge/tickets/{id}/comments', $databridge(
        fn (Api $controller, ApiUser $apiUser, string $id) => $controller->ticketComments((int) $id, $apiUser)
    ))->whereNumber('id');

    Route::get('/api/databridge/tickets/{id}/files', $databridge(
        fn (Api $controller, ApiUser $apiUser, string $id) => $controller->ticketFiles((int) $id, $apiUser)
    ))->whereNumber('id');

    Route::get('/api/databridge/projects', $databridge(
        fn (Api $controller, ApiUser $apiUser) => $controller->projects($apiUser)
    ));

    Route::get('/api/databridge/projects/{id}/progress', $databridge(
        fn (Api $controller, ApiUser $apiUser, string $id) => $controller->projectProgress((int) $id, $apiUser)
    ))->whereNumber('id');

    Route::get('/api/databridge/projects/{id}/statuses', $databridge(
        fn (Api $controller, ApiUser $apiUser, string $id) => $controller->projectStatuses((int) $id, $apiUser)
    ))->whereNumber('id');

    Route::get('/api/databridge/milestones', $databridge(
        fn (Api $controller, ApiUser $apiUser) => $controller->milestones(request()->query(), $apiUser)
    ));

    Route::get('/api/databridge/timesheets', $databridge(
        fn (Api $controller, ApiUser $apiUser) => $controller->timesheets(request()->query(), $apiUser)
    ));
});

Route::middleware([ApiKeyAuth::class.':write'])->group(function () use ($databridge): void {
    Route::post('/api/databridge/tickets', $databridge(
        fn (Api $controller, ApiUser $apiUser) => $controller->createTicket(request()->json()->all(), $apiUser)
    ));

    Route::patch('/api/databridge/tickets/{id}', $databridge(
        fn (Api $controller, ApiUser $apiUser, string $id) => $controller->updateTicket((int) $id, request()->json()->all(), $apiUser)
    ))->whereNumber('id');

    Route::post('/api/databridge/tickets/{id}/comments', $databridge(
        fn (Api $controller, ApiUser $apiUser, string $id) => $controller->createTicketComment((int) $id, request()->json()->all(), $apiUser)
    ))->whereNumber('id');

    Route::post('/api/databridge/timesheets', $databridge(
        fn (Api $controller, ApiUser $apiUser) => $controller->createTimesheet(request()->json()->all(), $apiUser)
    ));
});
