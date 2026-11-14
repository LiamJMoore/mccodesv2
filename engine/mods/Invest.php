<?php
declare(strict_types=1);

/**
 * Fixed-term investments. Lock money away for a week, a fortnight or a month and get it back with interest. Take
 * it out early and you get back exactly what you put in, with no interest.
 *
 * One investment at a time, and a ceiling that rises with level, so the interest paid into the game is bounded.
 */
const MCC_INVEST_TERMS = [7 => 30, 14 => 75, 30 => 180];      // days => interest in thousandths (30 = 3%)
const MCC_INVEST_MAX_PER_LEVEL = 100000;
const MCC_INVEST_MIN = 1000;

function invest_max(int $level): int
{
    return MCC_INVEST_MAX_PER_LEVEL * max(1, $level);
}

/** The player's investment with what it will pay, or null: ['amount', 'rate', 'started', 'matures', 'interest', 'ready']. */
function invest_of(int $userid, ?int $now = null): ?array
{
    global $db;
    $r = $db->row('SELECT `amount`, `rate`, `started`, `matures` FROM `v3_investments` WHERE `userid` = ?', [$userid]);
    if ($r === null) {
        return null;
    }
    $r = array_map('intval', $r);
    $r['interest'] = intdiv($r['amount'] * $r['rate'], 1000);
    $r['ready'] = ($now ?? time()) >= $r['matures'];
    return $r;
}

function invest_open(int $userid, int $amount, int $days, int $level, ?int $now = null): ?string
{
    global $db;
    $now ??= time();
    if (!isset(MCC_INVEST_TERMS[$days])) {
        return 'Choose one of the terms.';
    }
    if ($amount < MCC_INVEST_MIN || $amount > invest_max($level)) {
        return 'You can invest between ' . money_formatter(MCC_INVEST_MIN) . ' and ' . money_formatter(invest_max($level)) . ' at your level.';
    }
    $why = 'You already have an investment. Collect it first.';
    $ok = $db->attempt(function () use ($db, $userid, $amount, $days, $now, &$why) {
        if ($db->exec('INSERT IGNORE INTO `v3_investments` (`userid`, `amount`, `rate`, `started`, `matures`) VALUES (?, ?, ?, ?, ?)', [$userid, $amount, MCC_INVEST_TERMS[$days], $now, $now + $days * 86400]) !== 1) {
            return false;
        }
        if (!wallet_take($userid, 'money', $amount)) {
            $why = 'You do not have ' . money_formatter($amount) . ' in your pocket.';
            return false;
        }
        return true;
    });
    return $ok ? null : $why;
}

/** Close the investment: with interest if it has matured, without if not. Returns ['paid' => , 'interest' => ] or null if there is none. */
function invest_close(int $userid, ?int $now = null): ?array
{
    global $db;
    $now ??= time();
    $out = null;
    $db->attempt(function () use ($db, $userid, $now, &$out) {
        $inv = $db->row('SELECT `amount`, `rate`, `matures` FROM `v3_investments` WHERE `userid` = ? FOR UPDATE', [$userid]);
        // deleting the row is taking the money out: it can be done once
        if ($inv === null || $db->exec('DELETE FROM `v3_investments` WHERE `userid` = ?', [$userid]) !== 1) {
            return false;
        }
        $interest = $now >= (int)$inv['matures'] ? intdiv((int)$inv['amount'] * (int)$inv['rate'], 1000) : 0;
        wallet_give($userid, 'money', (int)$inv['amount'] + $interest);
        $out = ['paid' => (int)$inv['amount'] + $interest, 'interest' => $interest];
        return true;
    });
    return $out;
}
