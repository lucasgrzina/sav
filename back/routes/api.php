<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['middleware' => ['auth:sanctum']]);

foreach (glob(__DIR__ . '/api/*.php') as $routeFile) {
    require $routeFile;
}
