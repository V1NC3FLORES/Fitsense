<?php
/**
 * Shared authentication and login lockout helpers.
 */

if (!function_exists('app_login_attempt_limit')) {
    function app_login_attempt_limit(): int
    {
        return 5;
    }
}

if (!function_exists('app_login_lock_window_seconds')) {
    function app_login_lock_window_seconds(): int
    {
        return 900; // 15 minutes
    }
}

if (!function_exists('app_user_login_locked')) {
    function app_user_login_locked(array $user): bool
    {
        if (empty($user['last_failed_login'])) {
            return false;
        }

        $failedAttempts = (int)($user['failed_attempts'] ?? 0);
        if ($failedAttempts < app_login_attempt_limit()) {
            return false;
        }

        $lockedAt = strtotime((string)$user['last_failed_login']);
        if ($lockedAt === false) {
            return false;
        }

        return (time() - $lockedAt) <= app_login_lock_window_seconds();
    }
}

if (!function_exists('app_record_failed_login')) {
    function app_record_failed_login($conn, int $userId): void
    {
        $stmt = $conn->prepare("SELECT failed_attempts, last_failed_login FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$user) {
            return;
        }

        $attempts = (int)($user['failed_attempts'] ?? 0) + 1;
        $update = $conn->prepare("UPDATE users SET failed_attempts = ?, last_failed_login = NOW() WHERE id = ?");
        $update->bind_param('ii', $attempts, $userId);
        $update->execute();
        $update->close();
    }
}

if (!function_exists('app_clear_failed_login')) {
    function app_clear_failed_login($conn, int $userId): void
    {
        $stmt = $conn->prepare("UPDATE users SET failed_attempts = 0, last_failed_login = NULL WHERE id = ?");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('app_validate_session_user')) {
    function app_validate_session_user($conn, $requiredRoles = null)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $userId = $_SESSION['user_id'] ?? null;
        if ($userId === null || (int)$userId <= 0) {
            return null;
        }

        $stmt = $conn->prepare("SELECT id, role, account_status, status, failed_attempts, last_failed_login FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            session_unset();
            session_destroy();
            return null;
        }

        $accountStatus = $user['account_status'] ?? $user['status'] ?? null;
        if ($accountStatus !== null && $accountStatus !== 'Active') {
            session_unset();
            session_destroy();
            return null;
        }

        if ($requiredRoles !== null) {
            $allowed = is_array($requiredRoles) ? $requiredRoles : [$requiredRoles];
            if (!in_array($user['role'], $allowed, true)) {
                return null;
            }
        }

        return $user;
    }
}

if (!function_exists('app_default_login_page')) {
    function app_default_login_page(): string
    {
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($scriptPath, '/Admin/') !== false || strpos($requestUri, '/Admin/') !== false || strpos($scriptPath, '\\Admin\\') !== false) {
            return 'admin-login.php';
        }

        return 'login.php';
    }
}

if (!function_exists('app_require_session_user')) {
    function app_require_session_user($conn, $requiredRoles = null, $loginPage = null, bool $redirect = true)
    {
        $user = app_validate_session_user($conn, $requiredRoles);
        if ($user !== null) {
            return $user;
        }

        if (!$redirect) {
            return null;
        }

        $target = $loginPage ?: app_default_login_page();
        header('Location: ' . $target, true, 303);
        exit;
    }
}

if (!function_exists('app_csrf_token')) {
    function app_csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }

        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION['csrf_token'];
    }
}

if (!function_exists('app_csrf_field')) {
    function app_csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(app_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('app_validate_csrf_token')) {
    function app_validate_csrf_token($token = null): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $expected = $_SESSION['csrf_token'] ?? '';
        if (!is_string($expected) || $expected === '') {
            return false;
        }

        if ($token === null) {
            $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        }

        if (!is_string($token)) {
            return false;
        }

        return hash_equals($expected, $token);
    }
}
