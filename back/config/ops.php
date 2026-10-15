<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Remote artisan runner
    |--------------------------------------------------------------------------
    |
    | For hosts without shell access. The endpoint is DISABLED while the token is
    | empty (it answers 404). Only the commands listed below can run, each with
    | fixed arguments: nothing from the request ever reaches Artisan.
    |
    | Enable it only for the deploy window, then clear OPS_ARTISAN_TOKEN again.
    |
    */

    'artisan_token' => env('OPS_ARTISAN_TOKEN'),

    'artisan_commands' => [
        'alerts-schema-check' => ['alerts:schema-check', []],
        'permission-cache-reset' => ['permission:cache-reset', []],
        'optimize-clear' => ['optimize:clear', []],
        'config-clear' => ['config:clear', []],
        'config-cache' => ['config:cache', []],
        'cache-clear' => ['cache:clear', []],
        'view-clear' => ['view:clear', []],
        'route-clear' => ['route:clear', []],
    ],

];
