<?php
declare(strict_types=1);

/**
 * The Season Pass. Each calendar month is a season. Everything a player does earns season points; points unlock
 * tiers; each tier has a free reward for everyone and a premium reward for players who bought that season's pass
 * with crystals. A new season starts by itself on the first of the month.
 *
 * It is fair by design: the premium track is bought with crystals (which players can also earn in the game), every
 * reward is shown before anything is bought, nothing is random, and a pass bought late still gives every premium
 * reward for the tiers already reached.
 */
const MCC_SEASON_TIERS = 30;
const MCC_SEASON_POINTS_PER_TIER = 400;
const MCC_SEASON_PREMIUM_PRICE = 150;      // crystals
/** Season points for one of each thing (counters from engine/Progress.php). */
const MCC_SEASON_POINTS = ['crimes' => 5, 'gym' => 1, 'shop_buy' => 2, 'fights_won' => 30, 'daily' => 60, 'missions' => 80, 'bounties' => 60, 'crafted' => 10];
const MCC_SEASON_GYM_DAILY_CAP = 300;      // gym points count up to this many a day, so the pass is not just a gym grind

function season_id(?int $now = null): string
{
    return date('Y-m', $now ?? time());
}

/** Seconds until the season ends. */
function season_left(?int $now = null): int
{
    $now ??= time();
    return max(0, (int)strtotime(date('Y-m-01 00:00:00', strtotime('+1 month', strtotime(date('Y-m-01', $now))))) - $now);
}

/** What a tier pays on each track: ['free' => ['money' => , 'crystals' => ], 'premium' => [...]]. */
function season_reward(int $tier): array
{
    $big = $tier % 5 === 0;
    $last = $tier === MCC_SEASON_TIERS;
    return [
        'free' => ['money' => 2500 * $tier, 'crystals' => $big ? 2 : 0],
        'premium' => ['money' => 5000 * $tier, 'crystals' => $last ? 50 : ($big ? 15 : 3)],
    ];
}

/** ['season', 'xp', 'tier' (reached), 'premium', 'into' (points into the next tier), 'claimed' => ['free' => [tiers], 'premium' => [tiers]]] */
function season_status(int $userid, ?string $season = null): array
{
    global $db;
    $season ??= season_id();
    $r = $db->row('SELECT `xp`, `premium` FROM `v3_season` WHERE `userid` = ? AND `season` = ?', [$userid, $season]);
    $xp = (int)($r['xp'] ?? 0);
    $claimed = ['free' => [], 'premium' => []];
    foreach ($db->all('SELECT `tier`, `track` FROM `v3_season_claims` WHERE `userid` = ? AND `season` = ?', [$userid, $season]) as $c) {
        $claimed[$c['track']][] = (int)$c['tier'];
    }
    $tier = min(MCC_SEASON_TIERS, intdiv($xp, MCC_SEASON_POINTS_PER_TIER));
    return ['season' => $season, 'xp' => $xp, 'tier' => $tier, 'premium' => (int)($r['premium'] ?? 0) === 1,
            'into' => $tier >= MCC_SEASON_TIERS ? 0 : $xp % MCC_SEASON_POINTS_PER_TIER, 'claimed' => $claimed];
}

function season_add(int $userid, int $points, ?string $season = null): void
{
    global $db;
    if ($points > 0) {
        $db->run('INSERT INTO `v3_season` (`userid`, `season`, `xp`, `premium`) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE `xp` = `xp` + VALUES(`xp`)', [$userid, $season ?? season_id(), $points]);
    }
}

/** A counter moved: turn it into season points. */
function season_progress(int $userid, string $stat, int $added, int $total): void
{
    global $db;
    if (!isset(MCC_SEASON_POINTS[$stat]) || $added <= 0) {
        return;
    }
    $points = MCC_SEASON_POINTS[$stat] * $added;
    if ($stat === 'gym') {
        // the day's gym points are kept beside the season row, and topped up only as far as the cap
        $today = date('Y-m-d');
        $db->run('INSERT INTO `v3_season` (`userid`, `season`, `xp`, `premium`, `gym_day`, `gym_points`) VALUES (?, ?, 0, 0, ?, 0) ON DUPLICATE KEY UPDATE `gym_points` = IF(`gym_day` = VALUES(`gym_day`), `gym_points`, 0), `gym_day` = VALUES(`gym_day`)',
            [$userid, season_id(), $today]);
        $before = (int)$db->value('SELECT `gym_points` FROM `v3_season` WHERE `userid` = ? AND `season` = ?', [$userid, season_id()]);
        $db->exec('UPDATE `v3_season` SET `xp` = `xp` + LEAST(?, GREATEST(0, ? - `gym_points`)), `gym_points` = LEAST(?, `gym_points` + ?) WHERE `userid` = ? AND `season` = ?',
            [$points, MCC_SEASON_GYM_DAILY_CAP, MCC_SEASON_GYM_DAILY_CAP, $points, $userid, season_id()]);
        return;
    }
    season_add($userid, $points);
}

/** Buy this season's premium track. Null when bought, or why not. */
function season_buy(int $userid, ?string $season = null): ?string
{
    global $db;
    $season ??= season_id();
    $db->run('INSERT IGNORE INTO `v3_season` (`userid`, `season`, `xp`, `premium`) VALUES (?, ?, 0, 0)', [$userid, $season]);
    $why = null;
    $bought = $db->attempt(function () use ($db, $userid, $season, &$why) {
        // not-premium -> premium can happen once, so the pass is paid for once
        if ($db->exec('UPDATE `v3_season` SET `premium` = 1 WHERE `userid` = ? AND `season` = ? AND `premium` = 0', [$userid, $season]) !== 1) {
            $why = 'You already have this season\'s pass.';
            return false;
        }
        if (!wallet_take($userid, 'crystals', MCC_SEASON_PREMIUM_PRICE)) {
            $why = 'The pass costs ' . MCC_SEASON_PREMIUM_PRICE . ' crystals, and you do not have that many.';
            return false;
        }
        return true;
    });
    return $bought ? null : $why;
}

/**
 * Collect every reward the player has reached and not yet taken. Returns ['tiers' => how many rewards, 'money' => ,
 * 'crystals' => ]. Each reward has a row of its own as its receipt, so it is paid once.
 */
function season_claim_all(int $userid, ?string $season = null): array
{
    global $db;
    $season ??= season_id();
    $st = season_status($userid, $season);
    $got = ['tiers' => 0, 'money' => 0, 'crystals' => 0];
    foreach ($st['premium'] ? ['free', 'premium'] : ['free'] as $track) {
        for ($tier = 1; $tier <= $st['tier']; $tier++) {
            if (in_array($tier, $st['claimed'][$track], true)) {
                continue;
            }
            $reward = season_reward($tier)[$track];
            $db->attempt(function () use ($db, $userid, $season, $tier, $track, $reward, &$got) {
                if ($db->exec('INSERT IGNORE INTO `v3_season_claims` (`userid`, `season`, `tier`, `track`) VALUES (?, ?, ?, ?)', [$userid, $season, $tier, $track]) !== 1) {
                    return false;
                }
                wallet_give($userid, 'money', $reward['money']);
                if ($reward['crystals'] > 0) {
                    wallet_give($userid, 'crystals', $reward['crystals']);
                }
                $got['tiers']++;
                $got['money'] += $reward['money'];
                $got['crystals'] += $reward['crystals'];
                return true;
            });
        }
    }
    return $got;
}

/** How many rewards are waiting to be collected (for the menu). */
function season_waiting(int $userid): int
{
    $st = season_status($userid);
    return max(0, $st['tier'] - count($st['claimed']['free'])) + ($st['premium'] ? max(0, $st['tier'] - count($st['claimed']['premium'])) : 0);
}

progress_listen('season_progress');
