<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap.php';

use AllStarCockpit\Support\Auth;
use AllStarCockpit\Support\Config;
use AllStarCockpit\Support\JsonResponse;

$config = new Config(dirname(__DIR__));
$auth = new Auth($config);
$auth->applySecurityHeaders();

JsonResponse::send([
    'ok' => true,
    'auth' => $auth->status(),
]);
