<?php
declare(strict_types=1);

/** Pay rules. Money is integer cents; rounding happens once per shift. */
final class Calc
{
    public static function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return $h * 60 + $m;
    }

    public static function minutes(string $start, string $end, int $breakMinutes): int
    {
        $mins = self::toMinutes($end) - self::toMinutes($start);
        if ($mins <= 0) {
            $mins += 1440;
        }
        return max(0, $mins - $breakMinutes);
    }

    /** minutes × rate / 60, rounded half up to whole cents. */
    public static function baseCents(int $minutes, int $rateCents): int
    {
        return intdiv($minutes * $rateCents * 2 + 60, 120);
    }

    public static function totalCents(int $minutes, int $rateCents, int $bonusCents): int
    {
        return self::baseCents($minutes, $rateCents) + $bonusCents;
    }

    /**
     * @param array<int, array<string, mixed>> $shifts rows with start_time, end_time, break_minutes, rate_cents, bonus_cents, status
     */
    public static function summary(array $shifts): array
    {
        $out = [
            'worked' => ['cents' => 0, 'minutes' => 0],
            'planned' => ['cents' => 0, 'minutes' => 0],
            'bonus' => 0,
        ];
        foreach ($shifts as $s) {
            $min = self::minutes($s['start_time'], $s['end_time'], (int)$s['break_minutes']);
            $key = $s['status'] === 'planned' ? 'planned' : 'worked';
            $out[$key]['cents'] += self::totalCents($min, (int)$s['rate_cents'], (int)$s['bonus_cents']);
            $out[$key]['minutes'] += $min;
            $out['bonus'] += (int)$s['bonus_cents'];
        }
        return $out;
    }
}
