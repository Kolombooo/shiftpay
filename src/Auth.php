<?php
declare(strict_types=1);

final class Auth
{
    private const COOKIE = 'shiftpay_session';
    private const RENEW_AFTER = 86400;
    private const MAX_FAILS = 5;
    private const WINDOW = 900;

    private static ?array $session = null;
    private static bool $checked = false;

    public static function sessionDays(): int
    {
        return max(1, (int)env('SESSION_DAYS', '365'));
    }

    public static function trustProxy(): bool
    {
        return strtolower(env('TRUST_PROXY', 'false')) === 'true';
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return self::trustProxy() && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public static function clientIp(): string
    {
        if (self::trustProxy() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function hasUser(): bool
    {
        return (bool)Db::get()->query('SELECT 1 FROM users LIMIT 1')->fetchColumn();
    }

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    public static function createUser(string $password): int
    {
        $pdo = Db::get();
        $pdo->prepare('INSERT INTO users (password_hash, created_at) VALUES (?, ?)')
            ->execute([self::hashPassword($password), now_iso()]);
        return (int)$pdo->lastInsertId();
    }

    public static function userId(): int
    {
        return (int)Db::get()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    }

    public static function verifyPassword(string $password): bool
    {
        $hash = Db::get()->query('SELECT password_hash FROM users ORDER BY id LIMIT 1')->fetchColumn();
        return is_string($hash) && password_verify($password, $hash);
    }

    /* ---- throttling ---- */

    /** Seconds the caller must still wait, 0 when allowed. */
    public static function throttleRemaining(): int
    {
        $pdo = Db::get();
        $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
            ->execute([gmdate('Y-m-d\TH:i:s\Z', time() - self::WINDOW)]);
        $st = $pdo->prepare('SELECT COUNT(*) AS n, MAX(attempted_at) AS last FROM login_attempts WHERE ip = ?');
        $st->execute([self::clientIp()]);
        $row = $st->fetch();
        if ((int)$row['n'] < self::MAX_FAILS) {
            return 0;
        }
        return max(1, strtotime((string)$row['last']) + self::WINDOW - time());
    }

    public static function recordFailure(): void
    {
        Db::get()->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)')
            ->execute([self::clientIp(), now_iso()]);
    }

    public static function clearFailures(): void
    {
        Db::get()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([self::clientIp()]);
    }

    public static function throttleMessage(int $seconds): string
    {
        return 'Too many attempts. Try again in ' . max(1, (int)ceil($seconds / 60)) . ' min.';
    }

    /* ---- sessions ---- */

    public static function deviceLabel(string $ua): string
    {
        $os = 'Unknown device';
        foreach ([
            '/Android/i' => 'Android', '/iPhone|iPad|iPod/i' => 'iOS', '/Windows/i' => 'Windows',
            '/Macintosh|Mac OS X/i' => 'macOS', '/CrOS/i' => 'ChromeOS', '/Linux/i' => 'Linux',
        ] as $re => $name) {
            if (preg_match($re, $ua)) {
                $os = $name;
                break;
            }
        }
        $browser = 'Browser';
        foreach ([
            '/Edg\//' => 'Edge', '/OPR\/|Opera/' => 'Opera', '/SamsungBrowser/' => 'Samsung Internet',
            '/Firefox|FxiOS/' => 'Firefox', '/Chrome|CriOS/' => 'Chrome', '/Safari/' => 'Safari',
        ] as $re => $name) {
            if (preg_match($re, $ua)) {
                $browser = $name;
                break;
            }
        }
        return $browser . ' on ' . $os;
    }

    private static function setCookie(string $token, bool $remember): void
    {
        setcookie(self::COOKIE, $token, [
            'expires' => $remember ? time() + self::sessionDays() * 86400 : 0,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function clearCookie(): void
    {
        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function expiry(bool $remember): string
    {
        $secs = $remember ? self::sessionDays() * 86400 : 86400;
        return gmdate('Y-m-d\TH:i:s\Z', time() + $secs);
    }

    /** Creates a fresh session token for this device and sets the cookie. */
    public static function login(bool $remember): void
    {
        $token = bin2hex(random_bytes(32));
        $now = now_iso();
        Db::get()->prepare('INSERT INTO device_sessions (user_id, token_hash, label, remember, created_at, last_seen_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([self::userId(), hash('sha256', $token), self::deviceLabel($_SERVER['HTTP_USER_AGENT'] ?? ''), $remember ? 1 : 0, $now, $now, self::expiry($remember)]);
        self::setCookie($token, $remember);
        self::$checked = false;
    }

    /** Current device session row or null. Renews it when last seen more than a day ago. */
    public static function check(): ?array
    {
        if (self::$checked) {
            return self::$session;
        }
        self::$checked = true;
        self::$session = null;
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $pdo = Db::get();
        $st = $pdo->prepare('SELECT * FROM device_sessions WHERE token_hash = ? AND expires_at > ?');
        $st->execute([hash('sha256', $token), now_iso()]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        if (time() - strtotime($row['last_seen_at']) > self::RENEW_AFTER) {
            $remember = (bool)$row['remember'];
            $row['last_seen_at'] = now_iso();
            $row['expires_at'] = self::expiry($remember);
            $pdo->prepare('UPDATE device_sessions SET last_seen_at = ?, expires_at = ? WHERE id = ?')
                ->execute([$row['last_seen_at'], $row['expires_at'], $row['id']]);
            self::setCookie($token, $remember);
        }
        return self::$session = $row;
    }

    public static function logout(): void
    {
        $s = self::check();
        if ($s) {
            Db::get()->prepare('DELETE FROM device_sessions WHERE id = ?')->execute([$s['id']]);
        }
        self::clearCookie();
        self::$session = null;
    }

    /** Sets a new password and signs out every device except the current one. */
    public static function changePassword(string $new, int $keepSessionId): void
    {
        $pdo = Db::get();
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([self::hashPassword($new), self::userId()]);
        $pdo->prepare('DELETE FROM device_sessions WHERE id != ?')->execute([$keepSessionId]);
    }

    public static function devices(int $currentId): array
    {
        $rows = Db::get()->query('SELECT id, label, last_seen_at, created_at FROM device_sessions WHERE expires_at > \'' . now_iso() . '\' ORDER BY last_seen_at DESC')->fetchAll();
        return array_map(fn($r) => [
            'id' => (int)$r['id'],
            'label' => $r['label'],
            'lastSeen' => $r['last_seen_at'],
            'current' => (int)$r['id'] === $currentId,
        ], $rows);
    }

    public static function revoke(int $id): bool
    {
        $st = Db::get()->prepare('DELETE FROM device_sessions WHERE id = ?');
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }
}
