<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    $file = ROOT . '/src/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require ROOT . '/src/helpers.php';

if (!@date_default_timezone_set(env('TZ', 'UTC'))) {
    date_default_timezone_set('UTC');
}
ini_set('display_errors', '0');
send_security_headers();
