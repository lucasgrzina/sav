<?php

namespace App\Notifications\Exceptions;

/**
 * A channel gateway has no provider-specific template identifier configured for an
 * AlertType — a Twilio contentSid, a Meta template name.
 */
class TemplateNotConfiguredException extends NotificationConfigurationException {}
