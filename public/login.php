<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (!Auth::hasUser()) {
    redirect('/setup');
}
if (Auth::check()) {
    redirect('/');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    same_origin_or_fail(false);
    $wait = Auth::throttleRemaining();
    if ($wait > 0) {
        http_response_code(429);
        $error = Auth::throttleMessage($wait);
    } else {
        $password = (string)($_POST['password'] ?? '');
        if ($password !== '' && Auth::verifyPassword($password)) {
            Auth::clearFailures();
            Auth::login(isset($_POST['remember']));
            redirect('/');
        }
        Auth::recordFailure();
        usleep(300000);
        http_response_code(401);
        $error = 'That password is not correct.';
    }
}

$fields = '<div class="field"><label for="password">Password</label>'
    . '<input type="password" id="password" name="password" autocomplete="current-password" autofocus required></div>'
    . '<label class="check"><input type="checkbox" name="remember" value="1" checked> Keep me signed in on this device</label>';
AuthPage::render('Sign in', 'Enter your password to open your shifts.', $fields, 'Sign in', $error);
