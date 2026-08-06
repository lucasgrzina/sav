<?php

namespace App\Notifications\Exceptions;

use RuntimeException;

/**
 * The webhook payload cannot be turned into a delivery-status change: an event type we do
 * not handle, or a body without a resolvable provider message id (a buffered batch, for
 * instance). Definitive — the same payload will never become processable, so the event is
 * closed with an explanation instead of retried.
 */
class UnsupportedWebhookEventException extends RuntimeException {}
