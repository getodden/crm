<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | Define the database table names used by the Odden Marketing package.
    |
    */
    'tables' => [
        'templates' => 'odden_marketing_templates',
        'forms' => 'odden_marketing_forms',
        'form_submissions' => 'odden_marketing_form_submissions',
        'campaigns' => 'odden_marketing_campaigns',
        'recipients' => 'odden_marketing_campaign_recipients',
        'subscriptions' => 'odden_marketing_subscriptions',
        'scoring_rules' => 'odden_marketing_lead_scoring_rules',
        'score_logs' => 'odden_marketing_lead_score_logs',
        'workflows' => 'odden_marketing_workflows',
        'workflow_steps' => 'odden_marketing_workflow_steps',
        'workflow_enrollments' => 'odden_marketing_workflow_enrollments',
        'workflow_logs' => 'odden_marketing_workflow_logs',
        'visitor_sessions' => 'odden_marketing_visitor_sessions',
        'page_views' => 'odden_marketing_page_views',
        'landing_pages' => 'odden_marketing_landing_pages',
        'esp_events' => 'odden_marketing_esp_events',
        'sms_messages' => 'odden_marketing_sms_messages',
        'nps_surveys' => 'odden_marketing_nps_surveys',
        'nps_responses' => 'odden_marketing_nps_responses',
        'assets' => 'odden_marketing_assets',
        'asset_downloads' => 'odden_marketing_asset_downloads',
        'events' => 'odden_marketing_events',
        'event_registrations' => 'odden_marketing_event_registrations',
        'custom_behavioral_events' => 'odden_custom_behavioral_events',
        'ad_audience_syncs' => 'odden_ad_audience_syncs',
        'subscription_topics' => 'odden_marketing_subscription_topics',
        'contact_topics' => 'odden_marketing_contact_topics',
        'suppressions' => 'odden_marketing_suppressions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sender Defaults
    |--------------------------------------------------------------------------
    |
    | Default sender identity for broadcast email campaigns.
    |
    */
    'defaults' => [
        'sender_name' => env('MARKETING_FROM_NAME', 'Odden Marketing'),
        'sender_email' => env('MARKETING_FROM_EMAIL', 'newsletter@odden.test'),
        'reply_to' => env('MARKETING_REPLY_TO', 'support@odden.test'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail Delivery
    |--------------------------------------------------------------------------
    |
    | Campaign messages, workflow emails, proofs and transactional API emails
    | are queued, never sent during the request: run a queue worker. Leave
    | "queue" and "connection" empty to use the default queue connection and
    | its default queue. "mailer" names a mailer from config/mail.php (for
    | example a dedicated bulk-sending mailer); empty uses the default mailer.
    |
    */
    'mail' => [
        'mailer' => env('ODDEN_MARKETING_MAILER'),
        'connection' => env('ODDEN_MARKETING_MAIL_CONNECTION'),
        'queue' => env('ODDEN_MARKETING_MAIL_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Send Frequency Capping & Fatigue Protection
    |--------------------------------------------------------------------------
    |
    | Limits communication frequency per contact to prevent email fatigue.
    |
    */
    'fatigue_protection' => [
        'enabled' => (bool) env('MARKETING_FATIGUE_PROTECTION_ENABLED', false),
        'max_emails_per_7_days' => (int) env('MARKETING_MAX_EMAILS_7_DAYS', 2),
        'min_hours_between_sends' => (int) env('MARKETING_MIN_HOURS_BETWEEN_SENDS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Marketing-to-Sales Instant Hand-off
    |--------------------------------------------------------------------------
    |
    | Automatically create high-priority pipeline deals and tasks when leads
    | reach MQL/SQL thresholds or submit high-intent forms.
    |
    */
    'sales_handoff' => [
        'auto_handoff_on_sql' => (bool) env('MARKETING_AUTO_HANDOFF_ON_SQL', true),
        'mql_score_threshold' => (int) env('MARKETING_MQL_THRESHOLD', 50),
        'sql_score_threshold' => (int) env('MARKETING_SQL_THRESHOLD', 100),
        'default_deal_amount' => (float) env('MARKETING_HANDOFF_DEAL_AMOUNT', 10000.00),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Public pages (hosted forms, landing pages, tracking, unsubscribe and
    | preference center) are registered in the "web" group. Webhooks and JSON
    | APIs are registered in the "api" group. Each group accepts a domain,
    | prefix and middleware. Set "enabled" to false to register your own
    | routes instead; keep the odden.marketing.* route names, since emails
    | and models generate links from them.
    |
    */
    'routes' => [
        'enabled' => (bool) env('ODDEN_MARKETING_ROUTES_ENABLED', true),

        'web' => [
            'domain' => env('ODDEN_MARKETING_DOMAIN'),
            'prefix' => env('ODDEN_MARKETING_PREFIX', ''),
            'middleware' => ['web'],
        ],

        'api' => [
            'domain' => env('ODDEN_MARKETING_DOMAIN'),
            'prefix' => env('ODDEN_MARKETING_API_PREFIX', 'api/marketing'),
            'middleware' => ['web'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | API Token
    |--------------------------------------------------------------------------
    |
    | Shared secret for this package's server-to-server endpoints (webhooks and
    | sending APIs). Send it as 'Authorization: Bearer <token>', an
    | 'X-Odden-Token' header, or a '?token=' query parameter. While empty, those
    | endpoints are disabled. Generate one with: php -r 'echo bin2hex(random_bytes(32));'
    |
    */
    'api' => [
        'token' => env('ODDEN_MARKETING_API_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | ESP Webhook Authentication
    |--------------------------------------------------------------------------
    |
    | Mailgun cannot send an API token header, so set its HTTP webhook signing key
    | (Mailgun dashboard > Webhooks) and the Mailgun webhook is authenticated by
    | Mailgun's HMAC signature instead. Signatures older than "tolerance" seconds
    | are rejected. With no key, Mailgun webhooks use the API token like any other
    | provider.
    |
    */
    'esp' => [
        'mailgun' => [
            'signing_key' => env('ODDEN_MARKETING_MAILGUN_SIGNING_KEY'),
            'tolerance' => (int) env('ODDEN_MARKETING_MAILGUN_SIGNATURE_TOLERANCE', 900),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AMP for Email
    |--------------------------------------------------------------------------
    |
    | Origins allowed to call the in-email AMP form endpoints (feedback and
    | RSVP). Requests from any other Origin get a 403 with no CORS headers.
    | Set a comma-separated list in ODDEN_MARKETING_AMP_ALLOWED_ORIGINS to
    | override the defaults (the AMP for Email clients' origins).
    |
    */
    'amp' => [
        'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'ODDEN_MARKETING_AMP_ALLOWED_ORIGINS',
            'https://mail.google.com,https://outlook.live.com,https://mail.yahoo.com,https://mail.aol.com'
        ))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound Webhooks
    |--------------------------------------------------------------------------
    |
    | Default endpoint and signing secret for outbound event webhooks (such as
    | template.email.sent). A webhook is only sent when a secret is available,
    | either here or as the request's webhook_secret; there is no fallback.
    |
    */
    'webhooks' => [
        'outbound_url' => env('ODDEN_MARKETING_WEBHOOK_URL'),
        'secret' => env('ODDEN_MARKETING_WEBHOOK_SECRET'),
    ],
];
