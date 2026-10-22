<?php
declare(strict_types=1);

function env(string $key, string $default = ''): string
{
    $v = getenv($key);
    return $v === false || $v === '' ? $default : $v;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(int $status, string $message): void
{
    json_out(['error' => $message], $status);
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

/** Rejects cross-origin requests: Origin (when sent) must match the Host header. */
function same_origin_or_fail(bool $json): void
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    $ok = true;
    if ($origin !== '') {
        $oh = parse_url($origin, PHP_URL_HOST);
        $op = parse_url($origin, PHP_URL_PORT);
        $o = is_string($oh) ? $oh . ($op ? ':' . $op : '') : '';
        $ok = $o !== '' && strcasecmp($o, $host) === 0;
    } elseif ($site !== '' && $site !== 'same-origin' && $site !== 'none') {
        $ok = false;
    }
    if (!$ok) {
        if ($json) {
            json_error(403, 'Cross-origin request blocked.');
        }
        http_response_code(403);
        exit('Cross-origin request blocked.');
    }
}
