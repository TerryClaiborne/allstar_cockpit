<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap.php';

use AllStarCockpit\Support\Auth;
use AllStarCockpit\Support\Config;
use AllStarCockpit\Support\JsonResponse;
use AllStarCockpit\Support\NodeLookup;

$config = new Config(dirname(__DIR__));
$auth = new Auth($config);
$auth->allowReadOnlyApi();
$lookup = new NodeLookup($config);
$node = (string)($_GET['node'] ?? '');

JsonResponse::send($lookup->lookup($node));
