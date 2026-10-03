<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | Define the database table names used by the Odden Service package.
    |
    */
    'tables' => [
        'tickets' => 'odden_service_tickets',
        'messages' => 'odden_service_ticket_messages',
        'sla_policies' => 'odden_service_sla_policies',
        'articles' => 'odden_service_articles',
        'canned_responses' => 'odden_service_canned_responses',
        'routing_rules' => 'odden_service_routing_rules',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticket Defaults
    |--------------------------------------------------------------------------
    |
    | Default configurations for new support tickets.
    |
    */
    'defaults' => [
        'priority' => 'medium',
        'source' => 'web_portal',
        'prefix' => 'TICK',
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Ticket URL
    |--------------------------------------------------------------------------
    |
    | Where the SLA breach alert email sends agents. {id} is replaced with the
    | ticket ID. Relative paths are resolved against the app URL. For anything
    | dynamic, call SlaBreachAlertNotification::resolveUrlUsing() from a service
    | provider instead.
    |
    */
    'admin_ticket_url' => env('ODDEN_SERVICE_ADMIN_TICKET_URL', '/admin/tickets/{id}/edit'),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The help center and customer support portal are registered in the "web"
    | group. The inbound email webhook, knowledge deflection and chat widget
    | APIs are registered in the "api" group. Each group accepts a domain,
    | prefix and middleware. Set "enabled" to false to register your own
    | routes instead; keep the odden.help.*, odden.support.* and
    | odden.service.* route names, since notifications link to them.
    |
    */
    'routes' => [
        'enabled' => (bool) env('ODDEN_SERVICE_ROUTES_ENABLED', true),

        'web' => [
            'domain' => env('ODDEN_SERVICE_DOMAIN'),
            'prefix' => env('ODDEN_SERVICE_PREFIX', ''),
            'middleware' => ['web'],
        ],

        'api' => [
            'domain' => env('ODDEN_SERVICE_DOMAIN'),
            'prefix' => env('ODDEN_SERVICE_API_PREFIX', 'api/service'),
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
        'token' => env('ODDEN_SERVICE_API_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound Email
    |--------------------------------------------------------------------------
    |
    | A reply is threaded onto an existing ticket only when it carries that
    | ticket's portal token and comes from the ticket's contact. When
    | require_authenticated_sender is true, the webhook request must also say
    | the sender passed authentication ('sender_authenticated' true, or
    | 'dmarc' set to 'pass'); otherwise the email opens a new ticket.
    |
    */
    'inbound_email' => [
        'require_authenticated_sender' => (bool) env('ODDEN_SERVICE_INBOUND_REQUIRE_AUTH', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer Replies
    |--------------------------------------------------------------------------
    |
    | When true, a customer reply (inbound email, portal or chat) to a resolved
    | or closed ticket reopens it: status becomes open and resolved_at and
    | closed_at are cleared. When false the reply is added and the status is
    | left alone. Replies to a merged ticket always go to its primary ticket.
    |
    */
    'reopen_on_customer_reply' => (bool) env('ODDEN_SERVICE_REOPEN_ON_CUSTOMER_REPLY', true),

    /*
    |--------------------------------------------------------------------------
    | Chat Widget
    |--------------------------------------------------------------------------
    |
    | Chat tickets are created like portal and email tickets, but the chat
    | endpoint is public and never verifies the visitor's email address, so
    | the "request received" email is off by default: anyone could otherwise
    | make your app email any address. Set confirmation_email to true to
    | send it; it carries the portal link and lets the customer continue
    | the conversation by email after closing the widget.
    |
    */
    'chat' => [
        'confirmation_email' => (bool) env('ODDEN_SERVICE_CHAT_CONFIRMATION_EMAIL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Ticket notifications (confirmation, agent reply, resolved/CSAT and SLA
    | breach alerts) are queued. Null uses the default queue connection and
    | that connection's default queue. Run a worker for it, e.g.
    | php artisan queue:work --queue=support-mail,default
    |
    */
    'notifications' => [
        'connection' => env('ODDEN_SERVICE_NOTIFICATIONS_CONNECTION'),
        'queue' => env('ODDEN_SERVICE_NOTIFICATIONS_QUEUE'),
    ],
];
