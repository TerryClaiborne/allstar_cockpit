<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap.php';

use AllStarCockpit\Support\Auth;
use AllStarCockpit\Support\AsteriskMonitor;
use AllStarCockpit\Support\Config;
use AllStarCockpit\Support\EventStore;
use AllStarCockpit\Support\JsonResponse;

$config = new Config(dirname(__DIR__));
$auth = new Auth($config);
$auth->allowReadOnlyApi();
$monitor = new AsteriskMonitor($config);
$store = new EventStore($config);

$snapshot = $monitor->snapshot();
$store->recordConnectionChanges($snapshot['current_connections'] ?? [], $snapshot['downstream_links'] ?? []);
$snapshot['history_preview'] = $store->readHistory(1000);
$snapshot['downstream_history_preview'] = $store->readDownstreamHistory(1000);

JsonResponse::send([
    'ok' => true,
    'data' => $snapshot,
]);
