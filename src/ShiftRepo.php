<?php
declare(strict_types=1);

final class ShiftRepo
{
    public static function cents($v): int
    {
        return (int)round(((float)$v) * 100);
    }

    /**
     * Validates one shift payload. Returns [clean row fields, error message|null].
     *
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    public static function validate(array $in, bool $requireMinutes = true): array
    {
        $date = $in['date'] ?? '';
        if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return [[], 'Choose a date.'];
        }
        $timeRe = '/^([01]\d|2[0-3]):[0-5]\d$/';
        $start = $in['start'] ?? '';
        $end = $in['end'] ?? '';
        if (!is_string($start) || !is_string($end) || !preg_match($timeRe, $start) || !preg_match($timeRe, $end)) {
            return [[], 'Enter a start and end time.'];
        }
        $brk = $in['break'] ?? ($in['brk'] ?? 0);
        if ($brk === '' || $brk === null) {
            $brk = 0;
        }
        if (!is_numeric($brk) || $brk < 0 || $brk > 1440 || floor((float)$brk) != $brk) {
            return [[], 'Enter the break in whole minutes.'];
        }
        $rate = $in['rate'] ?? null;
        if ($rate === null || $rate === '' || !is_numeric($rate) || $rate < 0 || $rate > 100000) {
            return [[], 'Enter an hourly rate.'];
        }
        $bonus = $in['bonus'] ?? 0;
        if ($bonus === '' || $bonus === null) {
            $bonus = 0;
        }
        if (!is_numeric($bonus) || $bonus < 0 || $bonus > 10000000) {
            return [[], 'Enter the bonus as a positive amount.'];
        }
        $note = $in['note'] ?? '';
        if (!is_string($note) || mb_strlen(trim($note)) > 80) {
            return [[], 'The note can be at most 80 characters.'];
        }
        $status = $in['status'] ?? '';
        if ($status !== 'worked' && $status !== 'planned') {
            return [[], 'Choose worked or planned.'];
        }
        if ($requireMinutes && Calc::minutes($start, $end, (int)$brk) <= 0) {
            return [[], 'The break is longer than the shift.'];
        }
        return [[
            'date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'break_minutes' => (int)$brk,
            'rate_cents' => self::cents($rate),
            'bonus_cents' => self::cents($bonus),
            'note' => trim($note),
            'status' => $status,
        ], null];
    }

    public static function toApi(array $r): array
    {
        $min = Calc::minutes($r['start_time'], $r['end_time'], (int)$r['break_minutes']);
        return [
            'id' => (int)$r['id'],
            'date' => $r['date'],
            'start' => $r['start_time'],
            'end' => $r['end_time'],
            'break' => (int)$r['break_minutes'],
            'rate' => round((int)$r['rate_cents'] / 100, 2),
            'bonus' => round((int)$r['bonus_cents'] / 100, 2),
            'note' => $r['note'],
            'status' => $r['status'],
            'minutes' => $min,
            'total' => round(Calc::totalCents($min, (int)$r['rate_cents'], (int)$r['bonus_cents']) / 100, 2),
        ];
    }

    public static function find(int $id): ?array
    {
        $st = Db::get()->prepare('SELECT * FROM shifts WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function month(string $ym): array
    {
        $st = Db::get()->prepare('SELECT * FROM shifts WHERE substr(date, 1, 7) = ? ORDER BY date, start_time, id');
        $st->execute([$ym]);
        return $st->fetchAll();
    }

    public static function all(): array
    {
        return Db::get()->query('SELECT * FROM shifts ORDER BY date, start_time, id')->fetchAll();
    }

    public static function summary(string $ym): array
    {
        $s = Calc::summary(self::month($ym));
        $w = $s['worked'];
        $p = $s['planned'];
        return [
            'worked' => ['pay' => $w['cents'] / 100, 'hours' => round($w['minutes'] / 60, 4), 'minutes' => $w['minutes']],
            'planned' => ['pay' => $p['cents'] / 100, 'hours' => round($p['minutes'] / 60, 4), 'minutes' => $p['minutes']],
            'payout' => ($w['cents'] + $p['cents']) / 100,
            'bonuses' => $s['bonus'] / 100,
        ];
    }

    public static function insert(array $f): int
    {
        $pdo = Db::get();
        $now = now_iso();
        $pdo->prepare('INSERT INTO shifts (date, start_time, end_time, break_minutes, rate_cents, bonus_cents, note, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$f['date'], $f['start_time'], $f['end_time'], $f['break_minutes'], $f['rate_cents'], $f['bonus_cents'], $f['note'], $f['status'], $now, $now]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(int $id, array $f): void
    {
        Db::get()->prepare('UPDATE shifts SET date=?, start_time=?, end_time=?, break_minutes=?, rate_cents=?, bonus_cents=?, note=?, status=?, updated_at=? WHERE id=?')
            ->execute([$f['date'], $f['start_time'], $f['end_time'], $f['break_minutes'], $f['rate_cents'], $f['bonus_cents'], $f['note'], $f['status'], now_iso(), $id]);
    }

    public static function delete(int $id): bool
    {
        $st = Db::get()->prepare('DELETE FROM shifts WHERE id = ?');
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    public static function markWorked(int $id): bool
    {
        $st = Db::get()->prepare('UPDATE shifts SET status = \'worked\', updated_at = ? WHERE id = ?');
        $st->execute([now_iso(), $id]);
        return $st->rowCount() > 0;
    }

    public static function exportJson(): array
    {
        return [
            'app' => 'shiftpay',
            'version' => 1,
            'exportedAt' => now_iso(),
            'settings' => SettingsRepo::get(),
            'shifts' => array_map(fn($r) => [
                'date' => $r['date'], 'start' => $r['start_time'], 'end' => $r['end_time'],
                'break' => (int)$r['break_minutes'], 'rate' => round((int)$r['rate_cents'] / 100, 2),
                'bonus' => round((int)$r['bonus_cents'] / 100, 2), 'note' => $r['note'], 'status' => $r['status'],
            ], self::all()),
        ];
    }

    public static function exportCsv(): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['date', 'start', 'end', 'break_minutes', 'hours', 'rate', 'bonus', 'total', 'status', 'note']);
        foreach (self::all() as $r) {
            $a = self::toApi($r);
            $note = $a['note'];
            if ($note !== '' && strpos("=+-@\t\r", $note[0]) !== false) {
                $note = "'" . $note;
            }
            fputcsv($fh, [
                $a['date'], $a['start'], $a['end'], $a['break'],
                number_format($a['minutes'] / 60, 2, '.', ''),
                number_format($a['rate'], 2, '.', ''), number_format($a['bonus'], 2, '.', ''),
                number_format($a['total'], 2, '.', ''), $a['status'], $note,
            ]);
        }
        rewind($fh);
        return "\xEF\xBB\xBF" . stream_get_contents($fh);
    }

    /**
     * Replaces all shifts (and settings, when present) from a JSON backup in one transaction.
     * Accepts both the server format and the prototype's localStorage backup.
     *
     * @return array{0: int, 1: ?string} imported count, error
     */
    public static function import(array $in): array
    {
        if (!isset($in['shifts']) || !is_array($in['shifts'])) {
            return [0, 'That doesn’t look like a backup.'];
        }
        $rows = [];
        foreach (array_values($in['shifts']) as $i => $s) {
            if (!is_array($s)) {
                return [0, 'Shift ' . ($i + 1) . ' is not valid.'];
            }
            [$clean, $err] = self::validate($s, false);
            if ($err !== null) {
                return [0, 'Shift ' . ($i + 1) . ': ' . $err];
            }
            $rows[] = $clean;
        }
        $set = isset($in['settings']) && is_array($in['settings']) ? $in['settings'] : $in;
        $setIn = [];
        if (isset($set['wage'])) {
            $setIn['wage'] = $set['wage'];
        }
        if (isset($set['symbol'])) {
            $setIn['symbol'] = $set['symbol'];
        }
        if (($err = SettingsRepo::validate($setIn, false)) !== null) {
            return [0, $err];
        }
        $pdo = Db::get();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM shifts');
            foreach ($rows as $f) {
                self::insert($f);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        if ($setIn) {
            SettingsRepo::save($setIn, false);
        }
        return [count($rows), null];
    }
}
