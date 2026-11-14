<?php
declare(strict_types=1);

/**
 * Businesses. A player can own one. It earns by the hour, and the takings wait in the till until they are collected.
 * The till holds at most a day's takings, so a business has to be visited to keep earning.
 *
 * The numbers are set so that a business pays for itself in a month or more, and each upgrade takes longer to pay
 * back than the last thing bought: it is a long-term investment, not a tap.
 */
const MCC_BUSINESSES = [
    'shop' => ['name' => 'Corner Shop', 'price' => 100000, 'hourly' => 125, 'level' => 5],
    'garage' => ['name' => 'Chop Shop', 'price' => 500000, 'hourly' => 550, 'level' => 15],
    'club' => ['name' => 'Nightclub', 'price' => 2500000, 'hourly' => 2400, 'level' => 30],
    'casino' => ['name' => 'Backroom Casino', 'price' => 10000000, 'hourly' => 8500, 'level' => 50],
];
const MCC_BUSINESS_MAX_LEVEL = 5;
const MCC_BUSINESS_UPGRADE_PERCENT = 50;  // of the purchase price, for each level
const MCC_BUSINESS_LEVEL_BONUS = 20;      // percent more of the base takings for each level above 1
const MCC_BUSINESS_TILL_HOURS = 24;
const MCC_BUSINESS_RESALE_PERCENT = 50;   // of the purchase price; upgrades are not refunded

function business_hourly(string $kind, int $level): int
{
    return intdiv(MCC_BUSINESSES[$kind]['hourly'] * (100 + MCC_BUSINESS_LEVEL_BONUS * ($level - 1)), 100);
}

/** The player's business, or null: ['kind', 'level', 'bought', 'collected', 'hourly', 'hours' => whole hours in the till, 'pending' => money in the till]. */
function business_of(int $userid, ?int $now = null): ?array
{
    global $db;
    $now ??= time();
    $b = $db->row('SELECT `kind`, `level`, `bought`, `collected` FROM `v3_business` WHERE `userid` = ?', [$userid]);
    if ($b === null || !isset(MCC_BUSINESSES[$b['kind']])) {
        return null;
    }
    $b['level'] = max(1, min(MCC_BUSINESS_MAX_LEVEL, (int)$b['level']));
    $b['collected'] = (int)$b['collected'];
    $b['hourly'] = business_hourly($b['kind'], $b['level']);
    $b['hours'] = min(MCC_BUSINESS_TILL_HOURS, intdiv(max(0, $now - $b['collected']), 3600));
    $b['pending'] = $b['hours'] * $b['hourly'];
    return $b;
}

function business_buy(int $userid, string $kind, int $player_level, ?int $now = null): ?string
{
    global $db;
    $now ??= time();
    if (!isset(MCC_BUSINESSES[$kind])) {
        return 'There is no such business.';
    }
    if ($player_level < MCC_BUSINESSES[$kind]['level']) {
        return 'You need to be level ' . MCC_BUSINESSES[$kind]['level'] . ' to run one.';
    }
    $why = 'You cannot afford it.';
    $db->attempt(function () use ($db, $userid, $kind, $now, &$why) {
        // one business a player: the primary key refuses a second
        if ($db->exec('INSERT IGNORE INTO `v3_business` (`userid`, `kind`, `level`, `bought`, `collected`) VALUES (?, ?, 1, ?, ?)', [$userid, $kind, $now, $now]) !== 1) {
            $why = 'You already own a business. Sell it first.';
            return false;
        }
        if (!wallet_take($userid, 'money', MCC_BUSINESSES[$kind]['price'])) {
            return false;
        }
        $why = null;
        return true;
    });
    return $why;
}

/** Empty the till into the player's pocket. Returns what was collected, or 0. */
function business_collect(int $userid, ?int $now = null): int
{
    global $db;
    $now ??= time();
    $paid = 0;
    $db->attempt(function () use ($db, $userid, $now, &$paid) {
        if ($db->value('SELECT `userid` FROM `v3_business` WHERE `userid` = ? FOR UPDATE', [$userid]) === null) {
            return false;
        }
        $b = business_of($userid, $now);
        if ($b === null || $b['hours'] < 1) {
            return false;
        }
        // a full till starts again from now; otherwise the part-hour already worked is kept
        $from = $b['hours'] >= MCC_BUSINESS_TILL_HOURS ? $now : $b['collected'] + $b['hours'] * 3600;
        $db->exec('UPDATE `v3_business` SET `collected` = ? WHERE `userid` = ?', [$from, $userid]);
        wallet_give($userid, 'money', $b['pending']);
        $paid = $b['pending'];
        return true;
    });
    return $paid;
}

function business_upgrade_price(string $kind): int
{
    return intdiv(MCC_BUSINESSES[$kind]['price'] * MCC_BUSINESS_UPGRADE_PERCENT, 100);
}

/** Raise the business a level. The till is emptied first so the new rate only applies from now. */
function business_upgrade(int $userid, ?int $now = null): ?string
{
    global $db;
    $now ??= time();
    $why = 'You cannot afford it.';
    $db->attempt(function () use ($db, $userid, $now, &$why) {
        if ($db->value('SELECT `userid` FROM `v3_business` WHERE `userid` = ? FOR UPDATE', [$userid]) === null) {
            $why = 'You do not own a business.';
            return false;
        }
        $b = business_of($userid, $now);
        if ($b['level'] >= MCC_BUSINESS_MAX_LEVEL) {
            $why = 'It is as good as it gets.';
            return false;
        }
        business_collect($userid, $now);
        if (!wallet_take($userid, 'money', business_upgrade_price($b['kind']))) {
            return false;
        }
        $db->exec('UPDATE `v3_business` SET `level` = `level` + 1 WHERE `userid` = ?', [$userid]);
        $why = null;
        return true;
    });
    return $why;
}

/** Sell up: the till is paid out, and half the purchase price comes back. Returns the total paid, or 0. */
function business_sell(int $userid, ?int $now = null): int
{
    global $db;
    $now ??= time();
    $paid = 0;
    $db->attempt(function () use ($db, $userid, $now, &$paid) {
        if ($db->value('SELECT `userid` FROM `v3_business` WHERE `userid` = ? FOR UPDATE', [$userid]) === null) {
            return false;
        }
        $b = business_of($userid, $now);
        if ($b === null || $db->exec('DELETE FROM `v3_business` WHERE `userid` = ?', [$userid]) !== 1) {
            return false;
        }
        $paid = $b['pending'] + intdiv(MCC_BUSINESSES[$b['kind']]['price'] * MCC_BUSINESS_RESALE_PERCENT, 100);
        return wallet_give($userid, 'money', $paid);
    });
    return $paid;
}
