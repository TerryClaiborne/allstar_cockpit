<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Bootstrap.php';

use AllStarCockpit\Support\Auth;
use AllStarCockpit\Support\Config;

$config = new Config(__DIR__);
$auth = new Auth($config);
$auth->applySecurityHeaders();

if (!$auth->isEnabled()) {
    header('Location: public/', true, 302);
    exit;
}

if ($auth->isAuthenticated()) {
    header('Location: public/', true, 302);
    exit;
}

$error = '';
$next = (string)($_GET['next'] ?? 'public/');
if ($next === '' || str_contains($next, '://') || str_starts_with($next, '/') || str_contains($next, '..')) {
    $next = 'public/';
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $next = (string)($_POST['next'] ?? 'public/');
    if ($next === '' || str_contains($next, '://') || str_starts_with($next, '/') || str_contains($next, '..')) {
        $next = 'public/';
    }

    if (!$auth->requireValidCsrf($postedToken)) {
        $error = 'Session check failed. Refresh the login page and try again.';
    } elseif ($auth->passwordHash() === '') {
        $error = 'Web login password is not set. Run setup_allstar_cockpit.sh --set-admin-password.';
    } elseif ($auth->verifyPassword($password)) {
        $auth->login();
        header('Location: ' . $next, true, 302);
        exit;
    } else {
        $error = 'Invalid password.';
    }
}

$csrfToken = $auth->csrfToken();

function asc_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>AllStar Cockpit Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="public/assets/css/style.css?v=0.1.41-dev">
</head>
<body class="login-page" data-theme="dark">
<main class="login-shell">
    <section class="login-card panel">
        <h1>AllStar Cockpit</h1>
        <p class="login-subtitle">Sign in to leave View Only mode.</p>

        <?php if ($error !== ''): ?>
            <div class="login-error"><?= asc_e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= asc_e($csrfToken) ?>">
            <input type="hidden" name="next" value="<?= asc_e($next) ?>">

            <label for="password">Admin password</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                autofocus
                required
            >

            <button type="submit">Sign In</button>
        </form>

        <p class="login-note">
            Web login is enabled. Status can still be viewed while logged out; protected controls stay locked until sign-in.
        </p>
    </section>
</main>
</body>
</html>
