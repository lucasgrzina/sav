<?php

namespace App\Notifications\Contracts;

use App\Notifications\Data\MessageContent;
use App\Notifications\Data\Recipient;
use App\Notifications\Enums\AlertType;
use App\Notifications\Models\Alert;

interface AlertMessageBuilder
{
    public function type(): AlertType;

    /** @return MessageContent|null null si, al recalcular en destino, la alerta ya no debe enviarse (ver DeliverAlertJob). */
    public function build(Alert $alert, Recipient $recipient): ?MessageContent;
}
