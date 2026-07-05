<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap.php';

use AllStarCockpit\Support\Auth;
use AllStarCockpit\Support\Config;
use AllStarCockpit\Support\EventStore;
use AllStarCockpit\Support\JsonResponse;

$config = new Config(dirname(__DIR__));
$auth = new Auth($config);
$auth->requireProtectedAccess();

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    JsonResponse::send([
        'ok' => false,
        'error' => 'POST required.',
    ], 405);
    exit;
}

$store = new EventStore($config);
$store->clearDownstreamHistory();

JsonResponse::send([
    'ok' => true,
    'data' => [
        'cleared' => true,
        'downstream_history' => [],
    ],
]);
