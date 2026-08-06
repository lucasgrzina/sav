<?php

namespace App\Notifications\Enums;

enum Channel: string
{
    case Whatsapp = 'whatsapp';
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';
}
