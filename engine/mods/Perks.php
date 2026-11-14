<?php
declare(strict_types=1);

/**
 * The Crystal Shop: things to spend crystals on. Nothing here makes a player stronger than another in a way money
 * alone could: refills are limited to a few a day, and the rest is how you look and how much there is to do.
 *
 * Prices and limits are the constants below. To add a name colour or a badge, add a line.
 */
const MCC_PERK_REFILLS = [
    'brave' => ['name' => 'Brave refill', 'price' => 8, 'a_day' => 3, 'what' => 'Fills your brave bar.'],
    'will' => ['name' => 'Will refill', 'price' => 5, 'a_day' => 3, 'what' => 'Fills your will bar, so gym sessions gain more.'],
    'hp' => ['name' => 'Patch up', 'price' => 5, 'a_day' => 5, 'what' => 'Fills your health bar.'],
];
const MCC_PERK_COLOUR_PRICE = 60;         // once; changing colour afterwards is free
const MCC_PERK_COLOURS = ['Gold' => '#FBBF24', 'Emerald' => '#34D399', 'Sky' => '#38BDF8', 'Violet' => '#A78BFA', 'Rose' => '#FB7185', 'Orange' => '#FB923C', 'Lime' => '#A3E635', 'White' => '#FFFFFF'];
const MCC_PERK_BADGES = [
    'supporter' => ['name' => 'Supporter', 'price' => 25],
    'highroller' => ['name' => 'High Roller', 'price' => 50],
    'shark' => ['name' => 'Shark', 'price' => 50],
    'ghost' => ['name' => 'Ghost', 'price' => 75],
    'kingpin' => ['name' => 'Kingpin', 'price' => 150],
    'untouchable' => ['name' => 'Untouchable', 'price' => 300],
];
const MCC_PERK_MISSION_PRICE = 15;        // a fourth mission for today, once a day

/** What a player owns: ['name_colour' => '#hex', 'badge_shown' => 'key', 'badge:shark' => '1', ...]. Kept for the request. */
function perks_of(int $userid): array
{
    global $db;
    static $cache = [];
    if (!isset($cache[$userid]) || !empty($GLOBALS['mcc_perks_stale'][$userid])) {
        $cache[$userid] = [];
        foreach ($db->all('SELECT `perk`, `value` FROM `v3_perks` WHERE `userid` = ?', [$userid]) as $r) {
            $cache[$userid][$r['perk']] = $r['value'];
        }
        unset($GLOBALS['mcc_perks_stale'][$userid]);
    }
    return $cache[$userid];
}

/** A player's name as it should be shown: in their colour if they have one, with their badge if they show one. */
function perk_name(int $userid, string $username): string
{
    $p = perks_of($userid);
    $name = e($username);
    $colour = $p['name_colour'] ?? '';
    if (in_array($colour, MCC_PERK_COLOURS, true)) {
        $name = "<span style='color:{$colour};font-weight:bold;'>{$name}</span>";
    }
    $badge = $p['badge_shown'] ?? '';
    if ($badge !== '' && isset(MCC_PERK_BADGES[$badge], $p['badge:' . $badge])) {
        $name .= " <span class='perk-badge'>" . e(MCC_PERK_BADGES[$badge]['name']) . '</span>';
    }
    return $name;
}

/** How many of a daily-limited thing the player has used today. */
function perk_used_today(int $userid, string $perk, ?string $today = null): int
{
    global $db;
    return (int)$db->value('SELECT `uses` FROM `v3_perk_uses` WHERE `userid` = ? AND `day` = ? AND `perk` = ?', [$userid, $today ?? date('Y-m-d'), $perk], 0);
}

/** Count one use against a daily limit. True if there was one left. Call inside $db->attempt(). */
function perk_use(int $userid, string $perk, int $limit, ?string $today = null): bool
{
    global $db;
    $today ??= date('Y-m-d');
    $db->run('INSERT IGNORE INTO `v3_perk_uses` (`userid`, `day`, `perk`, `uses`) VALUES (?, ?, ?, 0)', [$userid, $today, $perk]);
    return $db->exec('UPDATE `v3_perk_uses` SET `uses` = `uses` + 1 WHERE `userid` = ? AND `day` = ? AND `perk` = ? AND `uses` < ?', [$userid, $today, $perk, $limit]) === 1;
}

/** Buy a refill. Null when done, or why not. The limit, the crystals and the bar change together or not at all. */
function perk_refill(int $userid, string $bar, ?string $today = null): ?string
{
    global $db;
    $r = MCC_PERK_REFILLS[$bar] ?? null;
    if ($r === null) {
        return 'There is no such refill.';
    }
    $why = 'That could not be bought.';
    $ok = $db->attempt(function () use ($db, $userid, $bar, $r, $today, &$why) {
        if ($db->exec("UPDATE `users` SET `{$bar}` = `max{$bar}` WHERE `userid` = ? AND `{$bar}` < `max{$bar}`", [$userid]) !== 1) {
            $why = 'That bar is already full.';
            return false;
        }
        if (!perk_use($userid, 'refill:' . $bar, $r['a_day'], $today)) {
            $why = "You have had today's {$r['a_day']}. More tomorrow.";
            return false;
        }
        if (!wallet_take($userid, 'crystals', $r['price'])) {
            $why = "That costs {$r['price']} crystals.";
            return false;
        }
        return true;
    });
    return $ok ? null : $why;
}

function perk_set(int $userid, string $perk, string $value): void
{
    global $db;
    $db->run('INSERT INTO `v3_perks` (`userid`, `perk`, `value`, `bought`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$userid, $perk, $value, time()]);
    $GLOBALS['mcc_perks_stale'][$userid] = true;
}

/** Buy something kept for good (once), for a price. Null when bought, or why not. */
function perk_buy_once(int $userid, string $perk, string $value, int $price): ?string
{
    global $db;
    $why = 'You already have that.';
    $ok = $db->attempt(function () use ($db, $userid, $perk, $value, $price, &$why) {
        // the primary key (player, perk) lets it be bought once
        if ($db->exec('INSERT IGNORE INTO `v3_perks` (`userid`, `perk`, `value`, `bought`) VALUES (?, ?, ?, ?)', [$userid, $perk, $value, time()]) !== 1) {
            return false;
        }
        if (!wallet_take($userid, 'crystals', $price)) {
            $why = "That costs {$price} crystals.";
            return false;
        }
        return true;
    });
    $GLOBALS['mcc_perks_stale'][$userid] = true;
    return $ok ? null : $why;
}

/** Choose a name colour: the first time costs crystals, changing it later is free. */
function perk_colour(int $userid, string $colour_name): ?string
{
    $hex = MCC_PERK_COLOURS[$colour_name] ?? null;
    if ($hex === null) {
        return 'That is not one of the colours.';
    }
    if (isset(perks_of($userid)['name_colour'])) {
        perk_set($userid, 'name_colour', $hex);
        return null;
    }
    return perk_buy_once($userid, 'name_colour', $hex, MCC_PERK_COLOUR_PRICE);
}

function perk_badge_buy(int $userid, string $key): ?string
{
    if (!isset(MCC_PERK_BADGES[$key])) {
        return 'There is no such badge.';
    }
    $why = perk_buy_once($userid, 'badge:' . $key, '1', MCC_PERK_BADGES[$key]['price']);
    if ($why === null) {
        perk_set($userid, 'badge_shown', $key);
    }
    return $why;
}

/** Show a badge the player owns ('' to show none). */
function perk_badge_show(int $userid, string $key): bool
{
    if ($key !== '' && !isset(perks_of($userid)['badge:' . $key])) {
        return false;
    }
    perk_set($userid, 'badge_shown', $key);
    return true;
}

/** A fourth mission for today. Null when added, or why not. */
function perk_extra_mission(int $userid, int $level, ?string $today = null): ?string
{
    global $db;
    $today ??= date('Y-m-d');
    $have = missions_today($userid, $level, $today);
    $free = array_values(array_diff(array_keys(missions_pool()), array_column($have, 'mission')));
    if (count($have) > MCC_MISSIONS_A_DAY) {
        return 'You already have an extra mission today.';
    }
    if (!$free) {
        return 'There is no other mission to give you today.';
    }
    $why = 'You already have an extra mission today.';
    $ok = $db->attempt(function () use ($db, $userid, $level, $today, $free, &$why) {
        $key = $free[crc32($userid . '|x|' . $today) % count($free)];
        $m = MCC_MISSIONS[$key];
        if ($db->exec('INSERT IGNORE INTO `v3_missions` (`userid`, `day`, `slot`, `mission`, `stat`, `target`, `progress`, `reward`, `claimed`) VALUES (?, ?, ?, ?, ?, ?, 0, ?, 0)',
                [$userid, $today, MCC_MISSIONS_A_DAY + 1, $key, $m['stat'], $m['targets'][1], MCC_MISSION_CASH[1] * max(1, min($level, 50))]) !== 1) {
            return false;
        }
        if (!wallet_take($userid, 'crystals', MCC_PERK_MISSION_PRICE)) {
            $why = 'That costs ' . MCC_PERK_MISSION_PRICE . ' crystals.';
            return false;
        }
        return true;
    });
    return $ok ? null : $why;
}
