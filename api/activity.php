<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap.php';

use AllStarCockpit\Support\Auth;
use AllStarCockpit\Support\Config;
use AllStarCockpit\Support\EventStore;
use AllStarCockpit\Support\JsonResponse;

$config = new Config(dirname(__DIR__));
$auth = new Auth($config);
$auth->allowReadOnlyApi();
$store = new EventStore($config);
$limit = max(1, min(1000, (int)($_GET['limit'] ?? $config->getInt('HISTORY_LIMIT', 250))));

JsonResponse::send([
    'ok' => true,
    'history' => $store->readHistory($limit),
]);
