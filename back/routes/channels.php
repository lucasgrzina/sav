<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Canal privado por usuario.
 * El frontend se suscribe a: private-app.user.{userId}
 * El callback recibe el User autenticado (via auth:sanctum) y el {userId} del canal.
 * Solo autoriza si el usuario autenticado ES ese userId.
 */
Broadcast::channel('app.user.{userId}', function (User $user, int $userId) {
    return (int) $user->id === $userId;
});
