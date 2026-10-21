<?php

declare(strict_types=1);

use Zolta\Http\Response\Laravel\Resources\GenericResource;

return [
    'cqrs' => [
        'commands' => [
            [
                'path' => app_path('Services/UserManagementService'),
                'namespace' => 'App\\Services\\UserManagementService\\',
            ],
        ],
        'queries' => [
            [
                'path' => app_path('Services/UserManagementService'),
                'namespace' => 'App\\Services\\UserManagementService\\',
            ],
        ],
        'infrastructure_events' => [
            [
                'path' => app_path('Services/UserManagementService'),
                'namespace' => 'App\\Services\\UserManagementService\\',
            ],
        ],
        'cache' => [
            'command' => base_path('bootstrap/cache/command_map.php'),
            'query' => base_path('bootstrap/cache/query_map.php'),
            'event' => base_path('bootstrap/cache/event_map.php'),
        ],
        'map_keys' => [
            'command' => 'command.map',
            'query' => 'query.map',
            'event' => 'event.map',
        ],
        'options' => [
            'auto_detect_psr4' => false,
            'write_atomic' => true,
            'file_pattern' => '*.php',
            'exclude_paths' => [
                '**/Persistence/Seeders/**',
                '**/Persistence/Factories/**',
                '**/Persistence/Migrations/**',
                '**/Infrastructure/Persistence/Migrations/**',
                '**/Infrastructure/Repositories/**',
                '**/API/Routes/**',
                '**/Database/**',
                '**/vendor/**',
            ],
            'composer_autoload' => base_path('vendor/autoload.php'),
            'follow_symlinks' => false,
            'verbose_logging' => false,
        ],
    ],

    'http' => [
        'routes' => [
            'cache' => [
                'enabled' => env('ZOLTA_ATTR_ROUTE_CACHE', true),
                'ensure_fresh_on_boot' => env('ZOLTA_ATTR_ROUTE_ENSURE_FRESH_ON_BOOT', true),
                'skip_commands' => [
                    'package:discover',
                    'make:zolta-update-namespace',
                ],
            ],
            'paths' => [
                app_path('Services/UserManagementService/API/Controllers'),
            ],
            'documentation' => [
                'enabled' => env('ZOLTA_ROUTE_DOCS_ENABLED', true),
                'output_dir' => env('ZOLTA_ROUTE_DOCS_OUTPUT_DIR', base_path('bootstrap/cache')),
                'output_file' => env('ZOLTA_ROUTE_DOCS_OUTPUT_FILE', 'openapi.json'),
                'manifest_file' => env('ZOLTA_ROUTE_DOCS_MANIFEST_FILE', 'openapi_manifest.php'),
                'title' => env('ZOLTA_ROUTE_DOCS_TITLE', env('APP_NAME', 'Laravel').' API'),
                'version' => env('ZOLTA_ROUTE_DOCS_VERSION', env('APP_VERSION', '1.0.0')),
                'description' => env(
                    'ZOLTA_ROUTE_DOCS_DESCRIPTION',
                    'Auto-generated API documentation from Zolta HTTP route attributes.'
                ),
                'server_url' => env('ZOLTA_ROUTE_DOCS_SERVER_URL', '/'),
                'server_description' => env('ZOLTA_ROUTE_DOCS_SERVER_DESCRIPTION', 'Application server'),
            ],
            'default_response' => GenericResource::class,
        ],
    ],

    'identity' => [
        'recovery_key' => env('IDENTITY_RECOVERY_KEY'),
        'access_token_ttl_minutes' => (int) env('IDENTITY_ACCESS_TOKEN_TTL_MINUTES', 15),
        'refresh_token_ttl_days' => (int) env('IDENTITY_REFRESH_TOKEN_TTL_DAYS', 30),
        'invitation_ttl_hours' => (int) env('IDENTITY_INVITATION_TTL_HOURS', 72),
        'email_verification_ttl_minutes' => (int) env('IDENTITY_EMAIL_VERIFICATION_TTL_MINUTES', 15),
        'password_reset_ttl_minutes' => (int) env('IDENTITY_PASSWORD_RESET_TTL_MINUTES', 60),
        'expose_development_tokens' => (bool) env('IDENTITY_EXPOSE_DEVELOPMENT_TOKENS', false),
        'password_reset_url' => env('IDENTITY_PASSWORD_RESET_URL'),
        'project_deletion_grace_days' => (int) env('IDENTITY_PROJECT_DELETION_GRACE_DAYS', 30),
        'protected_project_slugs' => array_values(array_filter(array_map('trim', explode(',', (string) env('IDENTITY_PROTECTED_PROJECT_SLUGS', env('IDENTITY_PROJECT', '')))))),
        'hosted_applications' => [
            'internal_token' => env('IDENTITY_HOSTED_APPLICATIONS_TOKEN'),
            'branding_disk' => env('IDENTITY_BRANDING_DISK', 'public'),
            'page_sets' => array_values(array_unique(array_filter(array_map(
                'trim',
                explode(',', (string) env('IDENTITY_HOSTED_AUTH_PAGE_SETS', 'default')),
            )))),
        ],
    ],

    'identity_consumer' => [
        'base_url' => env('IDENTITY_API_URL'),
        'client_id' => env('IDENTITY_CLIENT_ID'),
        'client_secret' => env('IDENTITY_CLIENT_SECRET'),
        'local' => (bool) env('IDENTITY_INTROSPECTION_LOCAL', false),
        'introspection_cache_seconds' => (int) env('IDENTITY_INTROSPECTION_CACHE_SECONDS', 30),
    ],

    'demo' => [
        'accounts_enabled' => (bool) env('DEMO_ACCOUNTS_ENABLED', false),
        'account_lifetime_minutes' => (int) env('DEMO_ACCOUNT_LIFETIME_MINUTES', 60),
    ],
];
