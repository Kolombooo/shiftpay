<?php
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function dataDir(): string
    {
        return rtrim(env('DATA_DIR', '/data'), '/\\');
    }

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $dir = self::dataDir();
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $pdo = new PDO('sqlite:' . $dir . '/shiftpay.sqlite');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            self::migrate($pdo);
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
        $done = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob(ROOT . '/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $already = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE name = ?');
                $already->execute([$name]);
                if (!$already->fetchColumn()) {
                    $pdo->exec((string)file_get_contents($file));
                    $pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)')->execute([$name, now_iso()]);
                }
                $pdo->exec('COMMIT');
            } catch (Throwable $ex) {
                $pdo->exec('ROLLBACK');
                throw $ex;
            }
        }
    }
}
