<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    $root = dirname(__DIR__);
    $env  = $root . '/.env';
    if (!is_file($env)) {
        // REQ-DEMO-003: .env is local-only; fall back to committed .env.test for PHPUnit.
        $env = $root . '/.env.test';
    }
    (new Dotenv())->bootEnv($env);
}
