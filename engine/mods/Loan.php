<?php
declare(strict_types=1);

/**
 * The loan shark. Cash now, more back later. One loan at a time, up to a ceiling that rises with level.
 *
 * Interest is added each day on what was borrowed. Pay back any amount at any time. A loan left unpaid past its
 * term is collected the hard way: the shark's men take what they can find in the player's pocket and bank, and if
 * that does not clear the debt the player goes to hospital and the debt stands.
 *
 * More always comes back than went out, so the loan shark takes money out of the game.
 */
const MCC_LOAN_MAX_PER_LEVEL = 5000;
const MCC_LOAN_MIN = 500;
const MCC_LOAN_DAILY_PERMILLE = 50;       // 5% of the amount borrowed, added each day
const MCC_LOAN_TERM_DAYS = 7;
const MCC_LOAN_HOSPITAL = 60;             // minutes, when the collectors leave unpaid

function loan_max(int $level): int
{
    return MCC_LOAN_MAX_PER_LEVEL * max(1, $level);
}

/** The player's loan, or null: ['principal', 'repaid', 'taken', 'due', 'interest', 'owed', 'overdue']. */
function loan_of(int $userid, ?int $now = null): ?array
{
    global $db;
    $now ??= time();
    $l = $db->row('SELECT `principal`, `repaid`, `taken`, `due`, `chased` FROM `v3_loans` WHERE `userid` = ?', [$userid]);
    if ($l === null) {
        return null;
    }
    $l = array_map('intval', $l);
    $days = 1 + intdiv(max(0, $now - $l['taken']), 86400);                  // the first day's interest is charged at once
    $l['interest'] = intdiv($l['principal'] * loan_rate_permille($userid) * $days, 1000);
    $l['owed'] = max(0, $l['principal'] + $l['interest'] - $l['repaid']);
    $l['overdue'] = $now > $l['due'];
    return $l;
}

function loan_take(int $userid, int $amount, int $level, ?int $now = null): ?string
{
    global $db;
    $now ??= time();
    if ($amount < MCC_LOAN_MIN || $amount > loan_max($level)) {
        return 'He will lend you between ' . money_formatter(MCC_LOAN_MIN) . ' and ' . money_formatter(loan_max($level)) . '.';
    }
    $ok = $db->attempt(function () use ($db, $userid, $amount, $now) {
        // one loan a player: the primary key refuses a second
        return $db->exec('INSERT IGNORE INTO `v3_loans` (`userid`, `principal`, `repaid`, `taken`, `due`, `chased`) VALUES (?, ?, 0, ?, ?, 0)', [$userid, $amount, $now, $now + MCC_LOAN_TERM_DAYS * 86400]) === 1
            && wallet_give($userid, 'money', $amount);
    });
    return $ok ? null : 'You already owe him. Pay that back first.';
}

/** Pay some or all of it back from the pocket. Returns what was paid, or 0. Never takes more than is owed. */
function loan_repay(int $userid, int $amount, ?int $now = null): int
{
    global $db;
    $paid = 0;
    $db->attempt(function () use ($db, $userid, $amount, $now, &$paid) {
        if ($db->value('SELECT `userid` FROM `v3_loans` WHERE `userid` = ? FOR UPDATE', [$userid]) === null) {
            return false;
        }
        $l = loan_of($userid, $now);
        $pay = min($amount, $l['owed']);
        if ($pay < 1 || !wallet_take($userid, 'money', $pay)) {
            return false;
        }
        if ($pay >= $l['owed']) {
            $db->exec('DELETE FROM `v3_loans` WHERE `userid` = ?', [$userid]);
        } else {
            $db->exec('UPDATE `v3_loans` SET `repaid` = `repaid` + ? WHERE `userid` = ?', [$pay, $userid]);
        }
        $paid = $pay;
        return true;
    });
    return $paid;
}

/**
 * The collectors call on a player whose loan is past its term, at most once a day. They take what is in the pocket,
 * then the bank, up to what is owed. Returns ['took' => , 'cleared' => bool] or null if there was nothing to do.
 */
function loan_collect(int $userid, ?int $now = null): ?array
{
    global $db;
    $now ??= time();
    $out = null;
    // the cheap look first: almost every page load ends here without opening a transaction
    if ($db->value('SELECT 1 FROM `v3_loans` WHERE `userid` = ? AND `due` < ? AND `chased` <= ?', [$userid, $now, $now - 86400]) === null) {
        return null;
    }
    $db->attempt(function () use ($db, $userid, $now, &$out) {
        if ($db->value('SELECT `userid` FROM `v3_loans` WHERE `userid` = ? AND `due` < ? AND `chased` <= ? FOR UPDATE', [$userid, $now, $now - 86400]) === null) {
            return false;
        }
        $l = loan_of($userid, $now);
        $u = $db->row('SELECT `money`, `bankmoney` FROM `users` WHERE `userid` = ? FOR UPDATE', [$userid]);
        $from_pocket = min($l['owed'], max(0, (int)$u['money']));
        $from_bank = min($l['owed'] - $from_pocket, max(0, (int)$u['bankmoney']));
        if ($from_pocket > 0) {
            wallet_take($userid, 'money', $from_pocket);
        }
        if ($from_bank > 0) {
            wallet_take($userid, 'bankmoney', $from_bank);
        }
        $took = $from_pocket + $from_bank;
        if ($took >= $l['owed']) {
            $db->exec('DELETE FROM `v3_loans` WHERE `userid` = ?', [$userid]);
            event_add($userid, 'The loan shark\'s men came round and took ' . money_formatter($took) . '. Your debt is cleared.');
        } else {
            $db->exec('UPDATE `v3_loans` SET `repaid` = `repaid` + ?, `chased` = ? WHERE `userid` = ?', [$took, $now, $userid]);
            $db->exec('UPDATE `users` SET `hp` = 1, `hospital` = GREATEST(`hospital`, ?), `hospreason` = ? WHERE `userid` = ?', [MCC_LOAN_HOSPITAL, 'Behind on a debt to the loan shark', $userid]);
            event_add($userid, 'The loan shark\'s men came round. They took ' . money_formatter($took) . ' and left you in hospital. You still owe ' . money_formatter($l['owed'] - $took) . '.');
        }
        $out = ['took' => $took, 'cleared' => $took >= $l['owed']];
        return true;
    });
    return $out;
}

/** The daily interest this player is charged, in thousandths: the standard rate, less their merit upgrade. */
function loan_rate_permille(int $userid): int
{
    return function_exists('merit_rate') ? merit_rate($userid, 'loan', MCC_LOAN_DAILY_PERMILLE) : MCC_LOAN_DAILY_PERMILLE;
}
