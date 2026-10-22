<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../src/bootstrap.php';

if (!Auth::hasUser()) {
    fwrite(STDERR, "No account exists yet. Open /setup in the browser instead.\n");
    exit(1);
}

function read_hidden(string $prompt): string
{
    echo $prompt;
    $tty = stripos(PHP_OS, 'WIN') !== 0;
    if ($tty) {
        @shell_exec('stty -echo 2>/dev/null');
    }
    $line = trim((string)fgets(STDIN));
    if ($tty) {
        @shell_exec('stty echo 2>/dev/null');
    }
    echo "\n";
    return $line;
}

$pw = read_hidden('New password (at least 10 characters): ');
if (mb_strlen($pw) < 10) {
    fwrite(STDERR, "The password must be at least 10 characters.\n");
    exit(1);
}
if ($pw !== read_hidden('Repeat password: ')) {
    fwrite(STDERR, "The passwords don't match.\n");
    exit(1);
}

$pdo = Db::get();
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([Auth::hashPassword($pw), Auth::userId()]);
$pdo->exec('DELETE FROM device_sessions');
$pdo->exec('DELETE FROM login_attempts');
echo "Password changed. All devices were signed out.\n";
