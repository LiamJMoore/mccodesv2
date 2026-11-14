<?php
declare(strict_types=1);

/**
 * Achievements: permanent badges for reaching a total, each paying a one-off reward.
 *
 * To add one, add a line. The key (left) is stored against players, so never change a key once the game is live;
 * everything else can be edited freely. 'stat' is a counter from engine/Progress.php.
 */
const MCC_ACHIEVEMENTS = [
    'crimes_10' => ['name' => 'Petty Thief', 'stat' => 'crimes', 'target' => 10, 'money' => 1000, 'crystals' => 0],
    'crimes_100' => ['name' => 'Career Criminal', 'stat' => 'crimes', 'target' => 100, 'money' => 10000, 'crystals' => 5],
    'crimes_1000' => ['name' => 'Crime Lord', 'stat' => 'crimes', 'target' => 1000, 'money' => 100000, 'crystals' => 25],
    'crimes_10000' => ['name' => 'Public Enemy', 'stat' => 'crimes', 'target' => 10000, 'money' => 1000000, 'crystals' => 100],
    'crime_money_100k' => ['name' => 'Ill-Gotten Gains', 'stat' => 'crime_money', 'target' => 100000, 'money' => 10000, 'crystals' => 5],
    'crime_money_10m' => ['name' => 'Crime Pays', 'stat' => 'crime_money', 'target' => 10000000, 'money' => 500000, 'crystals' => 50],
    'gym_100' => ['name' => 'Gym Member', 'stat' => 'gym', 'target' => 100, 'money' => 1000, 'crystals' => 0],
    'gym_1000' => ['name' => 'Gym Rat', 'stat' => 'gym', 'target' => 1000, 'money' => 10000, 'crystals' => 5],
    'gym_10000' => ['name' => 'Iron Body', 'stat' => 'gym', 'target' => 10000, 'money' => 100000, 'crystals' => 25],
    'gym_100000' => ['name' => 'Living Weapon', 'stat' => 'gym', 'target' => 100000, 'money' => 1000000, 'crystals' => 100],
    'fights_1' => ['name' => 'First Blood', 'stat' => 'fights_won', 'target' => 1, 'money' => 500, 'crystals' => 1],
    'fights_25' => ['name' => 'Brawler', 'stat' => 'fights_won', 'target' => 25, 'money' => 10000, 'crystals' => 5],
    'fights_250' => ['name' => 'Enforcer', 'stat' => 'fights_won', 'target' => 250, 'money' => 100000, 'crystals' => 25],
    'fights_2500' => ['name' => 'Warlord', 'stat' => 'fights_won', 'target' => 2500, 'money' => 1000000, 'crystals' => 100],
    'shop_10' => ['name' => 'Window Shopper', 'stat' => 'shop_buy', 'target' => 10, 'money' => 500, 'crystals' => 0],
    'shop_500' => ['name' => 'Big Spender', 'stat' => 'shop_buy', 'target' => 500, 'money' => 25000, 'crystals' => 10],
    'daily_7' => ['name' => 'Regular', 'stat' => 'streak', 'target' => 7, 'money' => 5000, 'crystals' => 5],
    'daily_30' => ['name' => 'Devoted', 'stat' => 'streak', 'target' => 30, 'money' => 50000, 'crystals' => 25],
    'daily_100' => ['name' => 'Never Misses', 'stat' => 'streak', 'target' => 100, 'money' => 250000, 'crystals' => 100],
    'level_5' => ['name' => 'Finding Your Feet', 'stat' => 'level', 'target' => 5, 'money' => 2500, 'crystals' => 2],
    'level_10' => ['name' => 'Made', 'stat' => 'level', 'target' => 10, 'money' => 10000, 'crystals' => 5],
    'level_25' => ['name' => 'Respected', 'stat' => 'level', 'target' => 25, 'money' => 100000, 'crystals' => 20],
    'level_50' => ['name' => 'Feared', 'stat' => 'level', 'target' => 50, 'money' => 500000, 'crystals' => 50],
    'level_100' => ['name' => 'Legend', 'stat' => 'level', 'target' => 100, 'money' => 2500000, 'crystals' => 150],
    'missions_10' => ['name' => 'Reliable', 'stat' => 'missions', 'target' => 10, 'money' => 5000, 'crystals' => 3],
    'missions_100' => ['name' => 'Professional', 'stat' => 'missions', 'target' => 100, 'money' => 100000, 'crystals' => 30],
    'bounties_1' => ['name' => 'Bounty Hunter', 'stat' => 'bounties', 'target' => 1, 'money' => 1000, 'crystals' => 1],
    'bounties_50' => ['name' => 'Manhunter', 'stat' => 'bounties', 'target' => 50, 'money' => 100000, 'crystals' => 25],
    'crafted_1' => ['name' => 'Tinkerer', 'stat' => 'crafted', 'target' => 1, 'money' => 500, 'crystals' => 1],
    'crafted_100' => ['name' => 'Master Craftsman', 'stat' => 'crafted', 'target' => 100, 'money' => 100000, 'crystals' => 25],
];

/** "Commit 100 successful crimes" and so on: what an achievement asks for, in words. */
function achievement_goal(array $a): string
{
    $n = number_format($a['target']);
    return match ($a['stat']) {
        'crimes' => "Commit {$n} successful crimes",
        'crime_money' => 'Make ' . money_formatter($a['target']) . ' from crimes',
        'gym' => "Train {$n} times in the gym",
        'fights_won' => $a['target'] === 1 ? 'Win a fight' : "Win {$n} fights",
        'shop_buy' => "Buy {$n} items from shops",
        'streak' => "Claim the daily reward {$n} days in a row",
        'level' => "Reach level {$n}",
        'missions' => "Complete {$n} missions",
        'bounties' => $a['target'] === 1 ? 'Collect a bounty' : "Collect {$n} bounties",
        'crafted' => $a['target'] === 1 ? 'Craft an item' : "Craft {$n} items",
        default => MCC_PROGRESS[$a['stat']] . ": {$n}",
    };
}

/** The keys of the achievements a player has, with when: ['crimes_10' => unix time]. */
function achievements_earned(int $userid): array
{
    global $db;
    $out = [];
    foreach ($db->all('SELECT `ach`, `earned` FROM `v3_achievements` WHERE `userid` = ?', [$userid]) as $r) {
        $out[$r['ach']] = (int)$r['earned'];
    }
    return $out;
}

/**
 * Award anything the player's total now qualifies for. Called whenever a counter moves. The row is the receipt:
 * its primary key means each achievement is awarded, and paid, once, whatever arrives at the same moment.
 */
function achievements_check(int $userid, string $stat, int $added, int $total): void
{
    global $db;
    $due = [];
    foreach (MCC_ACHIEVEMENTS as $key => $a) {
        // only those this change could have reached: the total was below the target before, or close to it
        if ($a['stat'] === $stat && $total >= $a['target']) {
            $due[$key] = $a;
        }
    }
    if (!$due) {
        return;
    }
    $have = achievements_earned($userid);
    foreach (array_diff_key($due, $have) as $key => $a) {
        $db->attempt(function () use ($db, $userid, $key, $a) {
            if ($db->exec('INSERT IGNORE INTO `v3_achievements` (`userid`, `ach`, `earned`) VALUES (?, ?, ?)', [$userid, $key, time()]) !== 1) {
                return false;
            }
            if ($a['money'] > 0) {
                wallet_give($userid, 'money', $a['money']);
            }
            if ($a['crystals'] > 0) {
                wallet_give($userid, 'crystals', $a['crystals']);
            }
            event_add($userid, "Achievement unlocked: <b>" . e($a['name']) . '</b> (' . e(achievement_goal($a)) . '). Reward: ' . money_formatter($a['money'])
                . ($a['crystals'] ? " and {$a['crystals']} crystals" : '') . ". <a href='achievements.php'>See your achievements</a>");
            return true;
        });
    }
}

progress_listen('achievements_check');
