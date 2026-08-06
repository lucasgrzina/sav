<?php

use App\Notifications\Gateways\Fake\FakeGateway;
use App\Notifications\Gateways\Kapso\KapsoWhatsappGateway;
use App\Notifications\Gateways\Mail\MailGateway;
use App\Notifications\Gateways\Twilio\TwilioWhatsappGateway;

$whatsappProvider = env('WHATSAPP_PROVIDER', 'twilio');

// Interchangeable gateways for the SAME Channel::Whatsapp. A provider is infrastructure;
// a channel is business (it drives contact lookup, opt-outs and the fallback chain), so a
// new provider is a new entry here, never a new Channel case.
//
// An unknown provider does NOT fail here at config-load time: this array is cached with
// `config:cache` and must stay serializable (no closures), and throwing while the config
// is being built would take down every request in the app, not just WhatsApp sending. An
// invalid WHATSAPP_PROVIDER instead resolves to a `null` gateway below, and GatewayRegistry
// turns that into a NotificationConfigurationException only when Channel::Whatsapp is
// actually resolved — degrading just that channel while the email fallback still works.
$whatsappGateways = [
    'twilio' => TwilioWhatsappGateway::class,
    'kapso' => KapsoWhatsappGateway::class,
    'fake' => FakeGateway::class,
];

return [

    'channels' => [
        'whatsapp' => [
            'provider' => $whatsappProvider,
            'gateway' => $whatsappGateways[$whatsappProvider] ?? null,
            'available' => array_keys($whatsappGateways),
        ],
        'email' => [
            'gateway' => MailGateway::class,
        ],
    ],

    // Channel(s) to try, in order, when a delivery ends in a definitive Failed result
    // (immediate 4xx / missing contact, or a transient failure that exhausted all queue
    // retries). Never triggered by Suppressed (e.g. opt-out) — an explicit opt-out of a
    // channel is respected as-is, it does not fall through to another channel.
    'fallback' => [
        'whatsapp' => ['email'],
    ],

    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'messaging_service' => env('TWILIO_TEMPLATE_MESSAGE_SERVICE'),

        // Optional override for local tunnels: when empty, resolved from route('webhooks.twilio').
        'status_callback_url' => env('TWILIO_STATUS_CALLBACK_URL'),

        // WhatsApp Content API template IDs (contentSid), one per AlertType. These are
        // account-scoped: a contentSid created in one Twilio account does not exist in
        // another, so there is deliberately no default — an unset value must surface as a
        // configuration error rather than as a 404 disguised as a delivery failure.
        'templates' => [
            'program.created' => env('TWILIO_TEMPLATE_PROGRAM_CREATED'),
            'program.cancelled' => env('TWILIO_TEMPLATE_PROGRAM_CANCELLED'),
            'program.task_due' => env('TWILIO_TEMPLATE_PROGRAM_TASK_DUE'),
        ],
    ],

    'kapso' => [
        'api_key' => env('KAPSO_API_KEY'),
        'phone_number_id' => env('KAPSO_PHONE_NUMBER_ID'),
        // Optional: only used to provision templates. When empty, kapso:create-templates
        // discovers it from the phone number through the platform API.
        'business_account_id' => env('KAPSO_BUSINESS_ACCOUNT_ID'),
        'base_url' => env('KAPSO_BASE_URL', 'https://api.kapso.ai'),
        'api_version' => env('KAPSO_API_VERSION', 'v24.0'),
        'timeout' => env('KAPSO_TIMEOUT', 10),

        // Shared secret for the HMAC-SHA256 signature on inbound webhooks. Unlike project
        // webhooks (whose secret the dashboard generates), a WhatsApp webhook is registered
        // through the API and the caller chooses this value — see kapso:register-webhook.
        'webhook_secret' => env('KAPSO_WEBHOOK_SECRET'),

        // Meta identifies a template by name + language, not by an opaque id, so unlike the
        // Twilio contentSids these defaults are portable across accounts: any Kapso project
        // whose templates follow this naming works without extra configuration.
        'templates' => [
            'program.created' => [
                'name' => env('KAPSO_TEMPLATE_PROGRAM_CREATED', 'sav_program_created'),
                'language' => env('KAPSO_TEMPLATE_LANGUAGE', 'es'),
            ],
            'program.cancelled' => [
                'name' => env('KAPSO_TEMPLATE_PROGRAM_CANCELLED', 'sav_program_cancelled'),
                'language' => env('KAPSO_TEMPLATE_LANGUAGE', 'es'),
            ],
            'program.task_due' => [
                'name' => env('KAPSO_TEMPLATE_PROGRAM_TASK_DUE', 'sav_program_task_due'),
                'language' => env('KAPSO_TEMPLATE_LANGUAGE', 'es'),
            ],
        ],
    ],

];
