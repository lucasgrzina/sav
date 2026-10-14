<?php

use App\Notifications\Enums\Channel;
use App\Notifications\Gateways\Fake\FakeGateway;
use App\Notifications\Gateways\Kapso\KapsoWhatsappGateway;
use App\Notifications\Gateways\Mail\MailGateway;
use App\Notifications\Gateways\Twilio\TwilioWhatsappGateway;
use App\Notifications\Gateways\WebPush\WebPushGateway;

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
        'push' => [
            'gateway' => WebPushGateway::class,
        ],
    ],

    // Channel(s) to try, in order, when a delivery ends in a definitive Failed result
    // (immediate 4xx / missing contact, or a transient failure that exhausted all queue
    // retries). Never triggered by Suppressed (e.g. opt-out) — an explicit opt-out of a
    // channel is respected as-is, it does not fall through to another channel.
    'fallback' => [
        'whatsapp' => ['email'],
    ],

    // Canales en los que se registra un AlertRecipient por defecto para cada destinatario
    // resuelto por roles (ver AlertRecipientFactory). Push es adicional/paralelo a Whatsapp,
    // no un fallback (ver DEC-08) — no participa de 'fallback' más arriba.
    'default_channels' => [Channel::Whatsapp, Channel::Push],

    // Operator mailbox for service-misconfiguration alerts (max 1 e-mail per hour). Empty = log only.
    'ops_email' => env('NOTIFICATIONS_OPS_EMAIL'),

    // Provider error code => App\Notifications\Enums\FailureCode value. Codes not listed here
    // resolve to `unknown` and are logged (FailureClassifier). Keep in sync with each
    // provider's current error-code documentation; extend when new codes show up in logs.
    // Must stay free of closures/enums so `config:cache` can serialize it.
    'failure_map' => [
        // Meta Cloud API (through Kapso): `error.code` / webhook `message.errors.0.code`.
        'meta' => [
            131026 => 'invalid_number',
            133010 => 'invalid_number',
            131047 => 'provider_rejected',
            131049 => 'provider_rejected',
            131050 => 'provider_rejected',
            131051 => 'provider_rejected',
            131053 => 'provider_rejected',
            131056 => 'provider_rejected',
            132000 => 'service_misconfigured',
            132001 => 'service_misconfigured',
            132005 => 'service_misconfigured',
            132007 => 'service_misconfigured',
            132012 => 'service_misconfigured',
            132015 => 'service_misconfigured',
            132016 => 'service_misconfigured',
            131008 => 'service_misconfigured',
            131009 => 'service_misconfigured',
            131005 => 'service_misconfigured',
            131031 => 'service_misconfigured',
            190 => 'service_misconfigured',
            100 => 'service_misconfigured',
            368 => 'service_misconfigured',
        ],
        // Twilio: RestException::getCode() / status callback `ErrorCode`.
        'twilio' => [
            21211 => 'invalid_number',
            21214 => 'invalid_number',
            21217 => 'invalid_number',
            21614 => 'invalid_number',
            63003 => 'invalid_number',
            63024 => 'invalid_number',
            21610 => 'opted_out',
            63016 => 'provider_rejected',
            63049 => 'provider_rejected',
            63038 => 'provider_rejected',
            63018 => 'provider_rejected',
            63032 => 'provider_rejected',
            20003 => 'service_misconfigured',
            20404 => 'service_misconfigured',
            63007 => 'service_misconfigured',
            21606 => 'service_misconfigured',
            21608 => 'service_misconfigured',
        ],
    ],

    // Program alert reconciler. `enabled` is a kill switch for the AUTOMATIC triggers only
    // (protocol_updated, nightly). User-driven triggers (program create/update/cancel, manual
    // rebuild, manager unlink) always run: they are the only way alerts get generated.
    'reconciler' => [
        'enabled' => (bool) env('ALERTS_RECONCILER_ENABLED', true),

        // Mass-cancel valve (automatic triggers only): refuse when more than `mass_cancel_ratio`
        // of the pending alerts would be cancelled, but only for programs with at least
        // `mass_cancel_min_pending` pending alerts (small programs legitimately lose most of theirs).
        'mass_cancel_ratio' => (float) env('ALERTS_MASS_CANCEL_RATIO', 0.5),
        'mass_cancel_min_pending' => (int) env('ALERTS_MASS_CANCEL_MIN_PENDING', 4),
    ],

    // Program alert warnings (HIGH_VOLUME_FOR_RECIPIENT): more than this many alerts to the same
    // person on the same local day raises a warning. Advisory only, never blocks.
    'high_volume_per_day' => (int) env('ALERTS_HIGH_VOLUME_PER_DAY', 3),

    // Program preview: above this many items the response is cut (`truncated: true`) while
    // `summary` and `warnings` stay computed over the full projection.
    'preview_max_items' => (int) env('ALERTS_PREVIEW_MAX_ITEMS', 1000),

    // Locale used for day names / relative dates in messages and the alerts UI, by country ISO code.
    // Explicit on purpose: never rely on the server locale. Add pt_BR etc. once those messages exist.
    'locale_by_country_iso' => [
        'default' => 'es',
    ],

    // Feature flags for WhatsApp template versions. Keep OFF until the provider (Meta/Twilio)
    // has APPROVED the template, which can take up to ~48h.
    'templates' => [
        'program_task_due_v2' => (bool) env('WHATSAPP_TASK_DUE_V2_ENABLED', false),
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
            // twilio/media (or twilio/document) template: variable {{3}} feeds the PDF header.
            'program.pdf_shared' => env('TWILIO_TEMPLATE_PROGRAM_PDF_SHARED'),
            // v2 (opt-out footer). No default: stays unresolved until Twilio approves it.
            'program.task_due@v2' => env('TWILIO_TEMPLATE_PROGRAM_TASK_DUE_V2'),
            'health_plan.month' => env('TWILIO_TEMPLATE_HEALTH_PLAN_MONTH'),
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
            // v2 (DU-06): same copy plus opt-out footer. Not sent until
            // notifications.templates.program_task_due_v2 is enabled.
            'program.task_due@v2' => [
                'name' => env('KAPSO_TEMPLATE_PROGRAM_TASK_DUE_V2', 'sav_program_task_due_v2'),
                'language' => env('KAPSO_TEMPLATE_LANGUAGE', 'es'),
            ],
            // DEC-07: a diferencia de los otros, este template lleva un media/document header
            // en Twilio Content Composer — kapso:create-templates (BODY-only) no lo provisiona
            // correctamente todavía. El nombre/idioma se declara igual para no violar el
            // invariante 1:1 entre WhatsappTemplateCatalog::definitions() y este array (ver
            // KapsoCreateTemplatesCommand::payloads()); la creación real del template de media
            // queda pendiente como paso operativo (ver plan, paso 7).
            'program.pdf_shared' => [
                'name' => env('KAPSO_TEMPLATE_PROGRAM_PDF_SHARED', 'sav_program_pdf_shared'),
                'language' => env('KAPSO_TEMPLATE_LANGUAGE', 'es'),
            ],
            'health_plan.month' => [
                'name' => env('KAPSO_TEMPLATE_HEALTH_PLAN_MONTH', 'sav_health_plan_month'),
                'language' => env('KAPSO_TEMPLATE_LANGUAGE', 'es'),
            ],
        ],
    ],

];
