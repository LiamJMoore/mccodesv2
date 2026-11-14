<?php
declare(strict_types=1);

/**
 * Fame: how well known a player is, as one number and a reputation that goes with it.
 *
 * Fame is not stored. It is worked out from what the player has done (the counters in engine/Progress.php) and the
 * achievements they hold, so it cannot be bought, sent, or drift. It buys nothing: it is for showing off.
 */
const MCC_FAME_POINTS = ['fights_won' => 5, 'bounties' => 20, 'missions' => 3, 'crimes' => 1, 'crafted' => 2, 'daily' => 2];
const MCC_FAME_PER_ACHIEVEMENT = 50;
/** fame reached => reputation. Edit freely. */
const MCC_REPUTATIONS = [0 => 'Unknown', 100 => 'Talked About', 500 => 'Known', 2000 => 'Notorious', 10000 => 'Infamous', 50000 => 'Legendary'];
const MCC_FAME_WHY = ['fights_won' => 'Fights won', 'bounties' => 'Bounties collected', 'missions' => 'Missions completed', 'crimes' => 'Successful crimes', 'crafted' => 'Items crafted', 'daily' => 'Daily rewards claimed'];

/** ['total' => fame, 'parts' => [[label, count, points each, fame], ...]] */
function fame_of(int $userid): array
{
    $done = progress_all($userid);
    $parts = [];
    $total = 0;
    foreach (MCC_FAME_POINTS as $stat => $each) {
        $n = (int)($done[$stat] ?? 0);
        $parts[] = [MCC_FAME_WHY[$stat], $n, $each, $n * $each];
        $total += $n * $each;
    }
    $ach = count(array_intersect_key(achievements_earned($userid), MCC_ACHIEVEMENTS));
    $parts[] = ['Achievements', $ach, MCC_FAME_PER_ACHIEVEMENT, $ach * MCC_FAME_PER_ACHIEVEMENT];
    return ['total' => $total + $ach * MCC_FAME_PER_ACHIEVEMENT, 'parts' => $parts];
}

function fame_reputation(int $fame): string
{
    $title = 'Unknown';
    foreach (MCC_REPUTATIONS as $at => $name) {
        if ($fame >= $at) {
            $title = $name;
        }
    }
    return $title;
}

/** The fame still needed for the next reputation, with its name, or null at the top. */
function fame_next(int $fame): ?array
{
    foreach (MCC_REPUTATIONS as $at => $name) {
        if ($fame < $at) {
            return ['at' => $at, 'name' => $name, 'need' => $at - $fame];
        }
    }
    return null;
}

/** The most famous players: [['userid', 'username', 'level', 'fame'], ...]. Staff accounts with user_level 0 (NPCs) are left out. */
function fame_top(int $limit = 20): array
{
    global $db;
    $case = '';
    $params = [];
    foreach (MCC_FAME_POINTS as $stat => $each) {
        $case .= ' WHEN ? THEN `amount` * ?';
        $params[] = $stat;
        $params[] = $each;
    }
    $keys = array_keys(MCC_ACHIEVEMENTS);
    $marks = implode(', ', array_fill(0, count($keys), '?'));
    return $db->all("SELECT `u`.`userid`, `u`.`username`, `u`.`level`, COALESCE(`p`.`f`, 0) + COALESCE(`a`.`n`, 0) * ? AS `fame`
        FROM `users` AS `u`
        LEFT JOIN (SELECT `userid`, SUM(CASE `stat`{$case} ELSE 0 END) AS `f` FROM `v3_progress` GROUP BY `userid`) AS `p` ON `p`.`userid` = `u`.`userid`
        LEFT JOIN (SELECT `userid`, COUNT(*) AS `n` FROM `v3_achievements` WHERE `ach` IN ({$marks}) GROUP BY `userid`) AS `a` ON `a`.`userid` = `u`.`userid`
        WHERE `u`.`user_level` <> 0 HAVING `fame` > 0 ORDER BY `fame` DESC, `u`.`userid` LIMIT " . max(1, min($limit, 100)),
        array_merge([MCC_FAME_PER_ACHIEVEMENT], $params, $keys));
}
