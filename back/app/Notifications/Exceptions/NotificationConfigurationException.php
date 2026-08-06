<?php

namespace App\Notifications\Exceptions;

use RuntimeException;

/**
 * A channel gateway cannot send because of missing or invalid configuration (credentials,
 * a sender id, a template mapping). Definitive by nature: unlike a 5xx or a timeout, no
 * amount of queue backoff will resolve it, so DeliverAlertJob must not retry on it.
 */
class NotificationConfigurationException extends RuntimeException {}
