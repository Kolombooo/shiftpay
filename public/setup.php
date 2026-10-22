<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (Auth::hasUser()) {
    not_found();
}

$token = env('SETUP_TOKEN');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    same_origin_or_fail(false);
    $wait = Auth::throttleRemaining();
    $pw = (string)($_POST['password'] ?? '');
    if ($wait > 0) {
        http_response_code(429);
        $error = Auth::throttleMessage($wait);
    } elseif ($token !== '' && !hash_equals($token, (string)($_POST['token'] ?? ''))) {
        Auth::recordFailure();
        http_response_code(403);
        $error = 'The setup token is not correct.';
    } elseif (mb_strlen($pw) < 10) {
        $error = 'Use at least 10 characters for the password.';
    } elseif (!hash_equals($pw, (string)($_POST['password2'] ?? ''))) {
        $error = 'The two passwords don’t match.';
    } else {
        Auth::createUser($pw);
        Auth::clearFailures();
        Auth::login(true);
        redirect('/');
    }
}

$fields = '';
if ($token !== '') {
    $fields .= '<div class="field"><label for="token">Setup token</label>'
        . '<input type="password" id="token" name="token" autocomplete="off" required></div>';
}
$fields .= '<div class="field"><label for="password">Password (at least 10 characters)</label>'
    . '<input type="password" id="password" name="password" autocomplete="new-password" minlength="10" required></div>'
    . '<div class="field"><label for="password2">Repeat password</label>'
    . '<input type="password" id="password2" name="password2" autocomplete="new-password" minlength="10" required></div>';
AuthPage::render('Set up Shift Pay', 'Choose the password that protects your shifts.', $fields, 'Create password', $error);
