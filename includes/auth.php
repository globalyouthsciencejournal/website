<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** @var array{id:int,name:string,email:string,role:string,admin_role:string|null}|null */
function auth_current_user(): ?array
{
    static $cached = null;
    static $loaded = false;

    if ($loaded) {
        return $cached;
    }
    $loaded = true;

    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
        $cached = null;
        return $cached;
    }

    $userId = (int) $userId;
    if ($userId <= 0) {
        $cached = null;
        return $cached;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, name, email, role, admin_role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            $cached = null;
            return $cached;
        }

        $cached = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'role' => (string) $row['role'],
            'admin_role' => $row['admin_role'] !== null ? (string) $row['admin_role'] : 'reviewer',
        ];

        return $cached;
    } catch (Throwable $e) {
        // If DB is unavailable, treat as logged out to avoid breaking public pages.
        $cached = null;
        return $cached;
    }
}

function auth_is_logged_in(): bool
{
    return auth_current_user() !== null;
}

function auth_require_login(?string $redirectTo = null): void
{
    if (auth_is_logged_in()) {
        return;
    }

    $redirectTo = $redirectTo ?: ($_SERVER['REQUEST_URI'] ?? '/');
    $redirectTo = is_string($redirectTo) ? $redirectTo : '/';

    redirect('login.php?redirect=' . urlencode($redirectTo));
}

function auth_require_admin(): void
{
    auth_require_login();
    $user = auth_current_user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }
}

function auth_login_user(int $userId): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session not started; cannot login.');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;

    // Bust current-user cache in this request.
    $GLOBALS['__auth_current_user_cache_bust__'] = microtime(true);
}

function auth_logout_user(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
    }

    session_destroy();
}

function auth_redirect_dashboard(array $user): void
{
    if (($user['role'] ?? '') === 'admin') {
        redirect('admin-dashboard.php');
    }

    redirect('user-dashboard.php');
}
