<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

set_exception_handler(function (Throwable $ex): void {
    error_log('shiftpay: ' . get_class($ex) . ': ' . $ex->getMessage());
    json_error(500, 'Something went wrong on the server.');
});

$method = $_SERVER['REQUEST_METHOD'];
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = '/' . trim(preg_replace('#^/api#', '', $path), '/');

$session = Auth::check();
if (!$session) {
    json_error(401, 'Sign in required.');
}

$body = [];
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (strpos($ct, 'application/json') !== 0) {
        json_error(400, 'Send JSON.');
    }
    same_origin_or_fail(true);
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            json_error(400, 'The request body is not valid JSON.');
        }
    }
}

function month_param(): string
{
    $m = $_GET['month'] ?? '';
    if (!is_string($m) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
        json_error(400, 'Use month=YYYY-MM.');
    }
    return $m;
}

function shift_or_404(string $id): array
{
    $row = ShiftRepo::find((int)$id);
    if (!$row) {
        json_error(404, 'Shift not found.');
    }
    return $row;
}

$r = new Router();

$r->add('GET', '/me', function () use ($session) {
    json_out(['settings' => SettingsRepo::get(), 'deviceLabel' => $session['label']]);
});

$r->add('GET', '/shifts', function () {
    json_out(['shifts' => array_map('ShiftRepo::toApi', ShiftRepo::month(month_param()))]);
});

$r->add('GET', '/summary', function () {
    json_out(ShiftRepo::summary(month_param()));
});

$r->add('POST', '/shifts', function () use ($body) {
    [$f, $err] = ShiftRepo::validate($body);
    if ($err !== null) {
        json_error(400, $err);
    }
    json_out(ShiftRepo::toApi(ShiftRepo::find(ShiftRepo::insert($f))), 201);
});

$r->add('PUT', '/shifts/(\d+)', function (string $id) use ($body) {
    shift_or_404($id);
    [$f, $err] = ShiftRepo::validate($body);
    if ($err !== null) {
        json_error(400, $err);
    }
    ShiftRepo::update((int)$id, $f);
    json_out(ShiftRepo::toApi(ShiftRepo::find((int)$id)));
});

$r->add('DELETE', '/shifts/(\d+)', function (string $id) {
    shift_or_404($id);
    ShiftRepo::delete((int)$id);
    json_out(['ok' => true]);
});

$r->add('POST', '/shifts/(\d+)/worked', function (string $id) {
    shift_or_404($id);
    ShiftRepo::markWorked((int)$id);
    json_out(ShiftRepo::toApi(ShiftRepo::find((int)$id)));
});

$r->add('PUT', '/settings', function () use ($body) {
    if (($err = SettingsRepo::validate($body, true)) !== null) {
        json_error(400, $err);
    }
    json_out(SettingsRepo::save($body, !empty($body['applyToPlanned'])));
});

$r->add('GET', '/export.csv', function () {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="shiftpay-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    echo ShiftRepo::exportCsv();
});

$r->add('GET', '/export.json', function () {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="shiftpay-backup-' . date('Y-m-d') . '.json"');
    header('Cache-Control: no-store');
    echo json_encode(ShiftRepo::exportJson(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

$r->add('POST', '/import', function () use ($body) {
    [$n, $err] = ShiftRepo::import($body);
    if ($err !== null) {
        json_error(400, $err);
    }
    json_out(['imported' => $n, 'settings' => SettingsRepo::get()]);
});

$r->add('GET', '/devices', function () use ($session) {
    json_out(['devices' => Auth::devices((int)$session['id'])]);
});

$r->add('DELETE', '/devices/(\d+)', function (string $id) use ($session) {
    if (!Auth::revoke((int)$id)) {
        json_error(404, 'Device not found.');
    }
    if ((int)$id === (int)$session['id']) {
        Auth::clearCookie();
    }
    json_out(['ok' => true]);
});

$r->add('POST', '/password', function () use ($body, $session) {
    $wait = Auth::throttleRemaining();
    if ($wait > 0) {
        json_error(429, Auth::throttleMessage($wait));
    }
    $cur = (string)($body['current'] ?? '');
    $new = (string)($body['new'] ?? '');
    if (!Auth::verifyPassword($cur)) {
        Auth::recordFailure();
        json_error(400, 'The current password is not correct.');
    }
    if (mb_strlen($new) < 10) {
        json_error(400, 'Use at least 10 characters for the new password.');
    }
    Auth::clearFailures();
    Auth::changePassword($new, (int)$session['id']);
    json_out(['ok' => true]);
});

$r->add('POST', '/logout', function () {
    Auth::logout();
    json_out(['ok' => true]);
});

$r->dispatch($method, $path);
