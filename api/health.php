<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap.php';

use AllStarCockpit\Support\Auth;
use AllStarCockpit\Support\Config;
use AllStarCockpit\Support\JsonResponse;

$config = new Config(dirname(__DIR__));
$auth = new Auth($config);
$auth->allowReadOnlyApi();
$public = $config->publicConfig();

$checks = [
    'config_present' => is_file($config->root() . '/config.ini'),
    'data_writable' => is_writable($config->path('DATA_DIR', 'data')),
    'logs_writable' => is_writable($config->path('LOGS_DIR', 'logs')),
    'run_writable' => is_writable($config->path('RUN_DIR', 'run')),
    'node_configured' => $public['configured'],
];

JsonResponse::send([
    'ok' => !in_array(false, $checks, true),
    'checks' => $checks,
    'config' => $public,
]);
