<?php

declare(strict_types=1);

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'APP_CONFIG_CACHE' => '/tmp/identity-test-config.php',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'CACHE_STORE' => 'array',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require dirname(__DIR__).'/vendor/autoload.php';

$environment = getenv('APP_ENV') ?: '';
$connection = getenv('DB_CONNECTION') ?: '';
$database = getenv('DB_DATABASE') ?: '';

if ($environment !== 'testing' || $connection !== 'sqlite' || $database !== ':memory:') {
    fwrite(STDERR, "Refusing to run tests outside the isolated SQLite testing environment.\n");
    exit(1);
}
