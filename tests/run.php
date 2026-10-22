<?php
declare(strict_types=1);

// Run with: php tests/run.php
require __DIR__ . '/../src/Calc.php';

$fail = 0;
function check(string $name, $actual, $expected): void
{
    global $fail;
    if ($actual === $expected) {
        echo "ok   $name\n";
    } else {
        $fail++;
        echo "FAIL $name: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
    }
}

function shift(string $date, string $s, string $e, int $brk, int $rate, int $bonus, string $status): array
{
    return ['date' => $date, 'start_time' => $s, 'end_time' => $e, 'break_minutes' => $brk,
        'rate_cents' => $rate, 'bonus_cents' => $bonus, 'status' => $status];
}

// normal shift: 09:00-17:00, 30 min break = 7 h 30 min at 15.00
$m = Calc::minutes('09:00', '17:00', 30);
check('normal minutes', $m, 450);
check('normal total', Calc::totalCents($m, 1500, 0), 11250);

// overnight: 22:00-06:00 = 8 h
check('overnight minutes', Calc::minutes('22:00', '06:00', 0), 480);
check('overnight total', Calc::totalCents(480, 1500, 0), 12000);

// equal start and end runs 24 h
check('equal times', Calc::minutes('08:00', '08:00', 0), 1440);

// break longer than the shift clamps to 0
check('long break minutes', Calc::minutes('09:00', '10:00', 90), 0);
check('long break total', Calc::totalCents(0, 1500, 0), 0);

// rounding: 7 h 20 min at 13.37 = 98.0467 -> 98.05
$m = Calc::minutes('08:00', '15:20', 0);
check('rounding minutes', $m, 440);
check('rounding total', Calc::totalCents($m, 1337, 0), 9805);
check('half rounds up', Calc::baseCents(30, 101), 51); // 50.5 -> 51

// bonus only: zero-length work plus bonus
check('bonus only', Calc::totalCents(0, 1500, 2000), 2000);
check('bonus added after rounding', Calc::totalCents(440, 1337, 2000), 11805);

// month boundary + worked/planned split
$shifts = [
    shift('2026-10-31', '22:00', '06:00', 0, 1500, 0, 'worked'),   // October, 8 h = 120.00
    shift('2026-10-05', '09:00', '17:00', 30, 1500, 2000, 'planned'), // 112.50 + 20.00
    shift('2026-10-06', '09:00', '17:00', 30, 1500, 0, 'worked'),  // 112.50
];
$s = Calc::summary($shifts);
check('worked cents', $s['worked']['cents'], 12000 + 11250);
check('worked minutes', $s['worked']['minutes'], 480 + 450);
check('planned cents', $s['planned']['cents'], 11250 + 2000);
check('planned minutes', $s['planned']['minutes'], 450);
check('bonuses', $s['bonus'], 2000);
check('payout', $s['worked']['cents'] + $s['planned']['cents'], 12000 + 11250 + 13250);
check('overnight shift starts in october', substr($shifts[0]['date'], 0, 7), '2026-10');

echo $fail === 0 ? "\nAll tests passed.\n" : "\n$fail test(s) failed.\n";
exit($fail === 0 ? 0 : 1);
