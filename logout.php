<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Bootstrap.php';

use AllStarCockpit\Support\Auth;
use AllStarCockpit\Support\Config;

$config = new Config(__DIR__);
$auth = new Auth($config);
$auth->applySecurityHeaders();
$auth->logout();

header('Location: public/', true, 302);
exit;
