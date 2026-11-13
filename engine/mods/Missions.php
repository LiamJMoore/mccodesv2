<?php
declare(strict_types=1);

/**
 * Daily missions: three small jobs a day, each paying on completion, with a bonus for finishing all three.
 *
 * Each player gets their own three, drawn from the list below, and they change at midnight (server time). The draw
 * is worked out from the player and the date, so it is the same however many times the page is opened.
 *
 * 'stat' is a counter from engine/Progress.php; 'targets' are the easy, medium and hard versions; 'text' has {n}
 * where the number goes. Add a line to add a mission.
 */
const MCC_MISSIONS = [
    'crimes' => ['stat' => 'crimes', 'targets' => [3, 6, 12], 'text' => 'Commit {n} successful crimes'],
    'gym' => ['stat' => 'gym', 'targets' => [10, 25, 50], 'text' => 'Train {n} times in the gym'],
    'shop' => ['stat' => 'shop_buy', 'targets' => [1, 3, 5], 'text' => 'Buy {n} items from a shop'],
    'fights' => ['stat' => 'fights_won', 'targets' => [1, 2, 4], 'text' => 'Win {n} fights'],
    'daily' => ['stat' => 'daily', 'targets' => [1, 1, 1], 'text' => 'Claim your daily reward'],
    'crafted' => ['stat' => 'crafted', 'targets' => [1, 2, 3], 'text' => 'Craft {n} items'],
    'bounties' => ['stat' => 'bounties', 'targets' => [1, 1, 2], 'text' => 'Collect {n} bounties'],
];
const MCC_MISSION_CASH = [400, 900, 2000];          // easy, medium, hard; multiplied by the player's level (up to 50)
const MCC_MISSION_ALL_CRYSTALS = 3;                 // for finishing all three in a day
const MCC_MISSIONS_A_DAY = 3;

/** Which missions the game can hand out today: those whose feature is switched on. */
function missions_pool(): array
{
    $pool = MCC_MISSIONS;
    if (!function_exists('craft_make')) {
        unset($pool['crafted']);
    }
    if (!function_exists('bounty_place')) {
        unset($pool['bounties']);
    }
    return $pool;
}

function mission_text(array $m): string
{
    $def = MCC_MISSIONS[$m['mission']] ?? null;
    $text = str_replace('{n}', number_format((int)$m['target']), $def['text'] ?? 'Mission');
    return (int)$m['target'] === 1 ? preg_replace('/\b1 (\w+)s\b/', 'a $1', str_replace(['1 successful crimes', '1 times'], ['a successful crime', 'once'], $text)) : $text;
}

/**
 * Today's missions for a player, creating them on first asking. Each is
 * ['slot', 'mission', 'target', 'progress', 'reward', 'claimed'].
 */
function missions_today(int $userid, int $level = 1, ?string $today = null): array
{
    global $db;
    $today ??= date('Y-m-d');
    $rows = $db->all('SELECT `slot`, `mission`, `target`, `progress`, `reward`, `claimed` FROM `v3_missions` WHERE `userid` = ? AND `day` = ? ORDER BY `slot`', [$userid, $today]);
    if (count($rows) >= MCC_MISSIONS_A_DAY) {
        return $rows;
    }
    // the draw: shuffle the list with a seed made from the player and the date, take the first three
    $keys = array_keys(missions_pool());
    mt_srand(crc32($userid . '|' . $today));
    for ($i = count($keys) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];
    }
    $difficulty = [0, 1, 2];
    for ($i = 2; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$difficulty[$i], $difficulty[$j]] = [$difficulty[$j], $difficulty[$i]];
    }
    mt_srand();
    foreach (array_slice($keys, 0, MCC_MISSIONS_A_DAY) as $slot => $key) {
        $d = $difficulty[$slot % 3];
        // the primary key (player, day, slot) means two requests creating the day's missions make one set
        $db->run('INSERT IGNORE INTO `v3_missions` (`userid`, `day`, `slot`, `mission`, `stat`, `target`, `progress`, `reward`, `claimed`) VALUES (?, ?, ?, ?, ?, ?, 0, ?, 0)',
            [$userid, $today, $slot + 1, $key, MCC_MISSIONS[$key]['stat'], MCC_MISSIONS[$key]['targets'][$d], MCC_MISSION_CASH[$d] * max(1, min($level, 50))]);
    }
    return $db->all('SELECT `slot`, `mission`, `target`, `progress`, `reward`, `claimed` FROM `v3_missions` WHERE `userid` = ? AND `day` = ? ORDER BY `slot`', [$userid, $today]);
}

/** A counter moved: move today's missions that count the same thing. */
function missions_progress(int $userid, string $stat, int $added, int $total): void
{
    global $db, $ir;
    static $ready = [];
    if (in_array($stat, ['level', 'streak', 'missions'], true)) {
        return;
    }
    if (!isset($ready[$userid])) {
        // make sure today's missions exist, so work done before the player opens the page still counts
        missions_today($userid, isset($ir['userid']) && (int)$ir['userid'] === $userid ? (int)$ir['level'] : 1);
        $ready[$userid] = true;
    }
    $db->exec('UPDATE `v3_missions` SET `progress` = LEAST(`target`, `progress` + ?) WHERE `userid` = ? AND `day` = ? AND `stat` = ? AND `progress` < `target`',
        [$added, $userid, date('Y-m-d'), $stat]);
}

/**
 * Collect a finished mission's reward. Returns ['money' => , 'crystals' => bonus if that was the day's last] or
 * null if it is not finished or was already collected.
 */
function mission_claim(int $userid, int $slot, ?string $today = null): ?array
{
    global $db;
    $today ??= date('Y-m-d');
    $paid = null;
    $db->attempt(function () use ($db, $userid, $slot, $today, &$paid) {
        if ($db->exec('UPDATE `v3_missions` SET `claimed` = 1 WHERE `userid` = ? AND `day` = ? AND `slot` = ? AND `claimed` = 0 AND `progress` >= `target`', [$userid, $today, $slot]) !== 1) {
            return false;
        }
        $money = (int)$db->value('SELECT `reward` FROM `v3_missions` WHERE `userid` = ? AND `day` = ? AND `slot` = ?', [$userid, $today, $slot]);
        wallet_give($userid, 'money', $money);
        $paid = ['money' => $money, 'crystals' => 0];
        return true;
    });
    if ($paid === null) {
        return null;
    }
    // The bonus for the full set. Whichever claim finds all three collected marks slot 1 as "bonus paid"; that
    // statement can succeed once, so the bonus is paid once even if the last two are collected at the same moment.
    if ((int)$db->value('SELECT COUNT(*) FROM `v3_missions` WHERE `userid` = ? AND `day` = ? AND `claimed` >= 1', [$userid, $today]) >= MCC_MISSIONS_A_DAY
        && $db->exec('UPDATE `v3_missions` SET `claimed` = 2 WHERE `userid` = ? AND `day` = ? AND `slot` = 1 AND `claimed` = 1', [$userid, $today]) === 1) {
        wallet_give($userid, 'crystals', MCC_MISSION_ALL_CRYSTALS);
        $paid['crystals'] = MCC_MISSION_ALL_CRYSTALS;
    }
    progress_add($userid, 'missions');
    return $paid;
}

progress_listen('missions_progress');
