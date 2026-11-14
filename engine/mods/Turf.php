<?php
declare(strict_types=1);

/**
 * Gang territory. Every city is turf. A gang's members earn influence for their gang in whichever city they are in:
 * a point for each successful crime, five for each fight won. Through the week the gang with the most influence in a
 * city holds it; when the week ends (Monday, 00:00 server time) each city pays its holder's vault, and the count
 * starts again.
 *
 * So turf is held by turning up and being active there, it can change hands any day, and a small gang can take a
 * quiet city from a big one that is busy elsewhere.
 */
const MCC_TURF_POINTS = ['crimes' => 1, 'fights_won' => 5];
const MCC_TURF_MIN_POINTS = 10;            // a city with less influence than this is held by nobody
const MCC_TURF_PAY_BASE = 50000;           // weekly payout to the holder's vault...
const MCC_TURF_PAY_PER_LEVEL = 5000;       // ...plus this for each level a player must be to enter the city
const MCC_TURF_PAY_CRYSTALS = 10;

/** The week a moment falls in, as "2026-W46" (weeks start on Monday). */
function turf_week(?int $now = null): string
{
    return date('o-\WW', $now ?? time());
}

function turf_week_left(?int $now = null): int
{
    $now ??= time();
    return max(0, (int)strtotime('next monday', strtotime(date('Y-m-d', $now))) - $now);
}

function turf_payout(int $min_level): array
{
    return ['money' => MCC_TURF_PAY_BASE + MCC_TURF_PAY_PER_LEVEL * max(1, $min_level), 'crystals' => MCC_TURF_PAY_CRYSTALS];
}

/** Add influence for a gang in a city this week. */
function turf_add(int $city, int $gang, int $points, ?string $week = null): void
{
    global $db;
    if ($city > 0 && $gang > 0 && $points > 0) {
        $db->run('INSERT INTO `v3_turf` (`city`, `gang`, `week`, `points`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `points` = `points` + VALUES(`points`)', [$city, $gang, $week ?? turf_week(), $points]);
    }
}

/** A counter moved: if it is one that earns influence and the player is in a gang, credit their gang where they stand. */
function turf_progress(int $userid, string $stat, int $added, int $total): void
{
    global $db, $ir;
    if (!isset(MCC_TURF_POINTS[$stat]) || $added <= 0) {
        return;
    }
    $who = isset($ir['userid']) && (int)$ir['userid'] === $userid ? $ir : $db->row('SELECT `gang`, `location` FROM `users` WHERE `userid` = ?', [$userid]);
    if ($who !== null && (int)$who['gang'] > 0) {
        turf_add((int)$who['location'], (int)$who['gang'], MCC_TURF_POINTS[$stat] * $added);
    }
}

/**
 * Every city with its standings for a week: [city id => ['name', 'min_level', 'payout', 'holder' => gang row or null,
 * 'top' => [['gang', 'name', 'points'], ...]]]. The holder is the top gang if it has enough influence; a tie goes to
 * the gang with the lower id (the older gang), so the answer is always the same.
 */
function turf_standings(?string $week = null, int $top = 3): array
{
    global $db;
    $week ??= turf_week();
    $out = [];
    foreach ($db->all('SELECT `cityid`, `cityname`, `cityminlevel` FROM `cities` ORDER BY `cityminlevel`, `cityid`') as $c) {
        $out[(int)$c['cityid']] = ['name' => $c['cityname'], 'min_level' => (int)$c['cityminlevel'], 'payout' => turf_payout((int)$c['cityminlevel']), 'holder' => null, 'top' => []];
    }
    foreach ($db->all('SELECT `t`.`city`, `t`.`gang`, `t`.`points`, `g`.`gangNAME` FROM `v3_turf` AS `t` INNER JOIN `gangs` AS `g` ON `g`.`gangID` = `t`.`gang`
                       WHERE `t`.`week` = ? ORDER BY `t`.`city`, `t`.`points` DESC, `t`.`gang`', [$week]) as $r) {
        $city = (int)$r['city'];
        if (!isset($out[$city])) {
            continue;
        }
        $row = ['gang' => (int)$r['gang'], 'name' => $r['gangNAME'], 'points' => (int)$r['points']];
        if (!$out[$city]['top'] && $row['points'] >= MCC_TURF_MIN_POINTS) {
            $out[$city]['holder'] = $row;
        }
        if (count($out[$city]['top']) < $top) {
            $out[$city]['top'][] = $row;
        }
    }
    return $out;
}

/**
 * Pay out the week that has ended, if it has not been paid. One request claims the job; the rest carry on.
 * Returns the number of cities paid.
 */
function turf_settle(?int $now = null): int
{
    global $db;
    $now ??= time();
    $this_week = turf_week($now);
    $last = (string)$db->value("SELECT `conf_value` FROM `settings` WHERE `conf_name` = 'engine_turf_week'", [], '');
    if ($last === $this_week) {
        return 0;
    }
    if ($db->exec("UPDATE `settings` SET `conf_value` = ? WHERE `conf_name` = 'engine_turf_week' AND `conf_value` = ?", [$this_week, $last]) !== 1) {
        return 0;
    }
    if ($last === '') {
        return 0;                      // the first week the game has this feature: nothing to pay yet
    }
    $paid = 0;
    foreach (turf_standings($last, 1) as $city => $c) {
        if ($c['holder'] === null) {
            continue;
        }
        $db->transaction(function () use ($db, $city, $c, $last, &$paid) {
            if ($db->exec('INSERT IGNORE INTO `v3_turf_wins` (`week`, `city`, `gang`, `points`, `money`, `crystals`) VALUES (?, ?, ?, ?, ?, ?)',
                    [$last, $city, $c['holder']['gang'], $c['holder']['points'], $c['payout']['money'], $c['payout']['crystals']]) !== 1) {
                return;
            }
            vault_give($c['holder']['gang'], $c['payout']['money'], $c['payout']['crystals']);
            $db->insert('gangevents', ['gevGANG' => $c['holder']['gang'], 'gevTIME' => time(),
                'gevTEXT' => 'The gang held ' . e($c['name']) . ' last week. ' . money_formatter($c['payout']['money']) . " and {$c['payout']['crystals']} crystals have been paid into the vault."]);
            $paid++;
        });
    }
    $db->exec('DELETE FROM `v3_turf` WHERE `week` < ?', [date('o-\WW', strtotime('-8 weeks'))]);
    return $paid;
}

progress_listen('turf_progress');
