<?php
declare(strict_types=1);

/**
 * Merits. Every achievement unlocked is worth one merit. Merits are spent on small permanent upgrades.
 *
 * Nothing is kept as a balance: merits earned is the number of achievements a player holds, merits spent is worked
 * out from the upgrades they have, so the two can never drift apart. Level n of an upgrade costs n merits.
 */
const MCC_MERIT_MAX_LEVEL = 5;
const MCC_MERITS = [
    'daily' => ['name' => 'Creature of Habit', 'does' => 'Daily reward money', 'step' => 5, 'unit' => '% more'],
    'missions' => ['name' => 'Dependable', 'does' => 'Mission pay', 'step' => 5, 'unit' => '% more'],
    'auction' => ['name' => 'Friend of the House', 'does' => 'The auction house\'s cut of your sales', 'step' => 0.5, 'unit' => ' points lower'],
    'loan' => ['name' => 'Good Customer', 'does' => 'The loan shark\'s daily interest', 'step' => 0.5, 'unit' => ' points lower'],
];

/** ['daily' => 2, ...] for the upgrades a player has. */
function merit_levels(int $userid): array
{
    global $db;
    $out = [];
    foreach ($db->all('SELECT `perk`, `level` FROM `v3_merits` WHERE `userid` = ?', [$userid]) as $r) {
        if (isset(MCC_MERITS[$r['perk']])) {
            $out[$r['perk']] = min(MCC_MERIT_MAX_LEVEL, (int)$r['level']);
        }
    }
    return $out;
}

function merit_level(int $userid, string $perk): int
{
    return function_exists('merit_levels') ? (merit_levels($userid)[$perk] ?? 0) : 0;
}

/** Merits a set of upgrade levels has cost: 1 + 2 + ... + n for each. */
function merit_spent(array $levels): int
{
    return array_sum(array_map(fn (int $n) => intdiv($n * ($n + 1), 2), $levels));
}

function merit_earned(int $userid): int
{
    return count(array_intersect_key(achievements_earned($userid), MCC_ACHIEVEMENTS));
}

/** Raise an upgrade by one level. Null when done, or a string saying why not. */
function merit_buy(int $userid, string $perk): ?string
{
    global $db;
    if (!isset(MCC_MERITS[$perk])) {
        return 'There is no such upgrade.';
    }
    $why = null;
    $db->attempt(function () use ($db, $userid, $perk, &$why) {
        // the player's own row is the lock, so two purchases at once cannot both spend the same merits
        $db->value('SELECT `userid` FROM `users` WHERE `userid` = ? FOR UPDATE', [$userid]);
        $levels = merit_levels($userid);
        $now = $levels[$perk] ?? 0;
        if ($now >= MCC_MERIT_MAX_LEVEL) {
            $why = 'That upgrade is as high as it goes.';
            return false;
        }
        if (merit_earned($userid) - merit_spent($levels) < $now + 1) {
            $why = 'You need ' . ($now + 1) . ' merits for the next level. Unlock more achievements.';
            return false;
        }
        $db->exec('INSERT INTO `v3_merits` (`userid`, `perk`, `level`) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE `level` = `level` + 1', [$userid, $perk]);
        return true;
    });
    return $why;
}

/** Money with the player's percentage upgrade applied (daily, missions). */
function merit_boost(int $userid, string $perk, int $amount): int
{
    return $amount + intdiv($amount * (int)(MCC_MERITS[$perk]['step'] * merit_level($userid, $perk)), 100);
}

/** A rate in thousandths, lowered by the player's upgrade (auction cut, loan interest). Never below half the base. */
function merit_rate(int $userid, string $perk, int $base_permille): int
{
    return max(intdiv($base_permille, 2), $base_permille - (int)round(MCC_MERITS[$perk]['step'] * 10) * merit_level($userid, $perk));
}
