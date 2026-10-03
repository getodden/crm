<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Odden Core Configuration
    |--------------------------------------------------------------------------
    |
    | Database-agnostic configuration for core CRM models, table prefixes,
    | and custom properties.
    |
    */

    'tables' => [
        'contacts' => 'odden_contacts',
        'companies' => 'odden_companies',
        'properties' => 'odden_properties',
        'property_groups' => 'odden_property_groups',
        'associations' => 'odden_associations',
        'association_types' => 'odden_association_types',
        'activities' => 'odden_activities',
        'property_history' => 'odden_property_history',
        'lists' => 'odden_lists',
        'list_memberships' => 'odden_list_memberships',
        'lifecycle_stage_transitions' => 'odden_lifecycle_stage_transitions',
        'custom_object_definitions' => 'odden_custom_object_definitions',
        'custom_object_records' => 'odden_custom_object_records',
    ],

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model used for owners, assignees and authors across all
    | Odden packages. When null, the default auth provider's model is used
    | (auth.providers.users.model).
    |
    */
    'user_model' => env('ODDEN_USER_MODEL'),

    /*
    |--------------------------------------------------------------------------
    | Domain Auto-Association
    |--------------------------------------------------------------------------
    |
    | Automatically associate created contacts with matching companies by corporate
    | email domain. Excludes consumer freemail domains.
    |
    */
    'auto_associate_companies' => false,

    /*
    |--------------------------------------------------------------------------
    | Custom Freemail Domains
    |--------------------------------------------------------------------------
    |
    | Additional consumer domains to treat as freemail (never auto-create or associate).
    |
    */
    'freemail_domains' => [],

    /*
    |--------------------------------------------------------------------------
    | Lifecycle State Machine
    |--------------------------------------------------------------------------
    |
    | Controls whether strict forward progression through the lifecycle funnel
    | is enforced by default.
    |
    */
    'lifecycle' => [
        'strict_transitions' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Requests per minute, per IP address, for the public routes Odden packages
    | register. "public" covers browser-facing submissions (forms, chat, portal
    | replies); "poll" covers read endpoints clients call repeatedly (chat
    | polling, article suggestions); "api" covers token-authenticated webhooks
    | and sending APIs. Each module (odden.service, odden.help, ...) has its own
    | counter.
    |
    */
    'rate_limits' => [
        'public' => (int) env('ODDEN_PUBLIC_RATE_LIMIT', 30),
        'poll' => (int) env('ODDEN_POLL_RATE_LIMIT', 120),
        'api' => (int) env('ODDEN_API_RATE_LIMIT', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pluggable Enrichment Engine
    |--------------------------------------------------------------------------
    |
    | Configuration for automated domain and corporate data enrichment.
    | Available drivers: "heuristic" (zero-external-API cost default)
    |
    */
    'enrichment' => [
        'driver' => env('ODDEN_ENRICHMENT_DRIVER', 'heuristic'),
        'auto_enrich' => false,
    ],
];
