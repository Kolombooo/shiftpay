<?php
declare(strict_types=1);

final class SettingsRepo
{
    public static function get(): array
    {
        $r = Db::get()->query('SELECT wage_cents, currency_symbol FROM settings WHERE id = 1')->fetch();
        return ['wage' => round((int)$r['wage_cents'] / 100, 2), 'symbol' => $r['currency_symbol']];
    }

    /** Returns an error message or null. */
    public static function validate(array $in, bool $wageRequired): ?string
    {
        if (array_key_exists('wage', $in)) {
            if (!is_numeric($in['wage']) || $in['wage'] < 0 || $in['wage'] > 100000) {
                return 'Enter your hourly wage.';
            }
            if ($wageRequired && $in['wage'] <= 0) {
                return 'Enter your hourly wage.';
            }
        } elseif ($wageRequired) {
            return 'Enter your hourly wage.';
        }
        if (array_key_exists('symbol', $in)) {
            $len = is_string($in['symbol']) ? mb_strlen(trim($in['symbol'])) : 0;
            if ($len > 4) {
                return 'The currency symbol can be at most 4 characters.';
            }
        }
        return null;
    }

    public static function save(array $in, bool $applyToPlanned): array
    {
        $pdo = Db::get();
        $cur = self::get();
        $wageCents = array_key_exists('wage', $in) ? ShiftRepo::cents($in['wage']) : (int)round($cur['wage'] * 100);
        $symbol = array_key_exists('symbol', $in) ? trim((string)$in['symbol']) : $cur['symbol'];
        if ($symbol === '') {
            $symbol = 'Kč';
        }
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE settings SET wage_cents = ?, currency_symbol = ?, updated_at = ? WHERE id = 1')
            ->execute([$wageCents, $symbol, now_iso()]);
        if ($applyToPlanned) {
            $pdo->prepare('UPDATE shifts SET rate_cents = ?, updated_at = ? WHERE status = \'planned\'')
                ->execute([$wageCents, now_iso()]);
        }
        $pdo->commit();
        return self::get();
    }
}
