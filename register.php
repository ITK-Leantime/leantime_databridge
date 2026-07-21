<?php

use Leantime\Core\Events\EventDispatcher;

/*
 * Exempt /api/databridge/* from core AuthCheck: the plugin's ApiKeyAuth route middleware
 * replaces core auth. Core matches public routes on the first two URL segments, so
 * 'api.databridge' covers every plugin route — which is why every route in routes.php
 * MUST attach ApiKeyAuth. Fail-closed: when the plugin is disabled this file never loads
 * and core auth rejects the requests instead.
 */
EventDispatcher::add_filter_listener(
    'leantime.core.middleware.authcheck.__construct.publicActions',
    function (array $publicActions): array {
        if (! in_array('api.databridge', $publicActions, true)) {
            $publicActions[] = 'api.databridge';
        }

        return $publicActions;
    }
);
