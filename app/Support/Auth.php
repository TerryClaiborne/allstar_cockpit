<?php
declare(strict_types=1);

namespace AllStarCockpit\Support;

final class Auth
{
    private const SESSION_NAME = 'ALLSTAR_COCKPIT_SESSID';
    private const SESSION_AUTH_KEY = 'allstar_cockpit_authenticated';
    private const SESSION_CSRF_KEY = 'allstar_cockpit_csrf_token';

    public function __construct(private Config $config)
    {
    }

    public function applySecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    }

    public function isEnabled(): bool
    {
        return $this->config->getBool('ALLSTAR_COCKPIT_AUTH_ENABLED', false);
    }

    public function adminUser(): string
    {
        $user = $this->config->getString('ALLSTAR_COCKPIT_ADMIN_USER', 'admin');
        return $user !== '' ? $user : 'admin';
    }

    public function passwordHash(): string
    {
        return $this->config->getString('ALLSTAR_COCKPIT_ADMIN_PASSWORD_HASH', '');
    }

    public function isAuthenticated(): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        $this->startSession();
        return ($_SESSION[self::SESSION_AUTH_KEY] ?? false) === true;
    }

    public function status(): array
    {
        $enabled = $this->isEnabled();
        $signedIn = $enabled ? $this->isAuthenticated() : true;

        if (!$enabled) {
            $label = 'No Login';
            $action = 'Normal';
            $mode = 'normal';
        } elseif ($signedIn) {
            $label = 'Signed In';
            $action = 'Logout';
            $mode = 'signed_in';
        } else {
            $label = 'View Only';
            $action = 'Login';
            $mode = 'login_required';
        }

        return [
            'enabled' => $enabled,
            'authenticated' => $signedIn,
            'mode' => $mode,
            'label' => $label,
            'action' => $action,
            'admin_user' => $this->adminUser(),
        ];
    }

    public function allowReadOnlyPage(): void
    {
        $this->applySecurityHeaders();
    }

    public function allowReadOnlyApi(): void
    {
        $this->applySecurityHeaders();
    }

    public function requireProtectedAccess(): void
    {
        $this->applySecurityHeaders();

        if (!$this->isEnabled() || $this->isAuthenticated()) {
            return;
        }

        JsonResponse::send([
            'ok' => false,
            'error' => 'Login required for this action.',
            'auth' => $this->status(),
        ], 403);
        exit;
    }

    public function requirePageAccess(): void
    {
        $this->allowReadOnlyPage();
    }

    public function requireApiAccess(): void
    {
        $this->allowReadOnlyApi();
    }

    public function verifyPassword(string $password): bool
    {
        $hash = $this->passwordHash();
        if ($hash === '') {
            return false;
        }
        return password_verify($password, $hash);
    }

    public function login(): void
    {
        $this->startSession();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_AUTH_KEY] = true;
    }

    public function logout(): void
    {
        $this->startSession();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
        }

        session_destroy();
    }

    public function csrfToken(): string
    {
        $this->startSession();
        $token = (string)($_SESSION[self::SESSION_CSRF_KEY] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_CSRF_KEY] = $token;
        }
        return $token;
    }

    public function requireValidCsrf(string $token): bool
    {
        $this->startSession();
        $known = (string)($_SESSION[self::SESSION_CSRF_KEY] ?? '');
        return $known !== '' && hash_equals($known, $token);
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    private function currentRelativePath(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? 'public/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : 'public/';

        $marker = '/allstar_cockpit/';
        $pos = strpos($path, $marker);
        if ($pos !== false) {
            $path = substr($path, $pos + strlen($marker));
        } else {
            $path = ltrim($path, '/');
        }

        $path = ltrim($path, '/');
        return $path !== '' ? $path : 'public/';
    }
}
