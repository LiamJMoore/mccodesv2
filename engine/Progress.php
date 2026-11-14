<?php
declare(strict_types=1);

/**
 * What players have done, counted. The game's pages report things as they happen:
 *
 *     progress_add($userid, 'crimes');            // one more successful crime
 *     progress_add($userid, 'gym', 25);           // twenty-five more gym sessions
 *     progress_max($userid, 'level', 12);         // has reached level 12
 *
 * and anything that rewards players for doing things (achievements, missions) listens here, so a page never has to
 * know which rewards exist. Adding a counter is one line in the page where the thing happens, and its name below.
 */
const MCC_PROGRESS = [
    'crimes' => 'Successful crimes',
    'crime_money' => 'Money made from crimes',
    'gym' => 'Gym sessions',
    'shop_buy' => 'Items bought in shops',
    'fights_won' => 'Fights won',
    'daily' => 'Daily rewards claimed',
    'streak' => 'Longest daily streak',
    'level' => 'Level reached',
    'missions' => 'Missions completed',
    'bounties' => 'Bounties collected',
    'crafted' => 'Items crafted',
    'trade_profit' => 'Profit from trading',
];

/** Things to call whenever a counter moves: fn(int $userid, string $stat, int $added, int $total). */
$GLOBALS['mcc_progress_listeners'] ??= [];

function progress_listen(callable $listener): void
{
    $GLOBALS['mcc_progress_listeners'][] = $listener;
}

function progress_get(int $userid, string $stat): int
{
    global $db;
    return (int)$db->value('SELECT `amount` FROM `v3_progress` WHERE `userid` = ? AND `stat` = ?', [$userid, $stat], 0);
}

/** Every counter for a player: ['crimes' => 12, ...], with 0 for those not started. */
function progress_all(int $userid): array
{
    global $db;
    $out = array_fill_keys(array_keys(MCC_PROGRESS), 0);
    foreach ($db->all('SELECT `stat`, `amount` FROM `v3_progress` WHERE `userid` = ?', [$userid]) as $r) {
        $out[$r['stat']] = (int)$r['amount'];
    }
    return $out;
}

/** Count $amount more of something. Returns the new total. */
function progress_add(int $userid, string $stat, int $amount = 1): int
{
    global $db;
    if (!isset(MCC_PROGRESS[$stat])) {
        throw new InvalidArgumentException("Not a progress counter: {$stat}");
    }
    if ($amount <= 0) {
        return progress_get($userid, $stat);
    }
    $db->run('INSERT INTO `v3_progress` (`userid`, `stat`, `amount`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `amount` = `amount` + VALUES(`amount`)', [$userid, $stat, $amount]);
    $total = progress_get($userid, $stat);
    foreach ($GLOBALS['mcc_progress_listeners'] as $listener) {
        $listener($userid, $stat, $amount, $total);
    }
    return $total;
}

/** Record a high-water mark (a level reached, a longest streak): the counter only ever goes up to $value. */
function progress_max(int $userid, string $stat, int $value): int
{
    global $db;
    if (!isset(MCC_PROGRESS[$stat])) {
        throw new InvalidArgumentException("Not a progress counter: {$stat}");
    }
    $before = progress_get($userid, $stat);
    if ($value <= $before) {
        return $before;
    }
    $db->run('INSERT INTO `v3_progress` (`userid`, `stat`, `amount`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `amount` = GREATEST(`amount`, VALUES(`amount`))', [$userid, $stat, $value]);
    $total = progress_get($userid, $stat);
    foreach ($GLOBALS['mcc_progress_listeners'] as $listener) {
        $listener($userid, $stat, $total - $before, $total);
    }
    return $total;
}
