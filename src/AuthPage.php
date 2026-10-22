<?php
declare(strict_types=1);

/** Shared shell for the login and setup pages. */
final class AuthPage
{
    /**
     * @param string $fields inner HTML of the form (already escaped)
     */
    public static function render(string $title, string $intro, string $fields, string $button, string $error = ''): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#0E6B5C">
    <title><?= e($title) ?> · Shift Pay</title>
    <link rel="manifest" href="/assets/manifest.webmanifest">
    <link rel="icon" href="/assets/icons/icon-192.png">
    <link rel="stylesheet" href="/assets/fonts.css">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="auth">
<main class="auth-card">
    <h1><?= e($title) ?></h1>
    <p class="hint"><?= e($intro) ?></p>
    <form method="post" autocomplete="on">
        <?= $fields ?>
        <p class="err" role="alert"><?= e($error) ?></p>
        <div class="actions"><button class="btn primary wide" type="submit"><?= e($button) ?></button></div>
    </form>
</main>
</body>
</html>
<?php
    }
}
