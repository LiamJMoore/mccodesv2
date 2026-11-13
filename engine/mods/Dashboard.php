<?php
declare(strict_types=1);

/**
 * Figures for the staff dashboard, and the daily snapshot that gives it a history.
 *
 * Once a day (on the first page load after midnight) the game writes one row of totals to v3_snapshots: players,
 * how many were active, and how much money and how many crystals exist. The dashboard draws its trends from those
 * rows, so an owner can see inflation, or a sudden jump that means something was duplicated, without any tooling.
 */

/** Totals for the game as it stands now. */
function dash_totals(): array
{
    global $db;
    $now = time();
    $midnight = strtotime('today');
    $u = $db->row(
        'SELECT COUNT(*) AS `players`, SUM(`laston` > ?) AS `online`, SUM(`laston` > ?) AS `active_day`, SUM(`laston` > ?) AS `active_week`,
                SUM(`signedup` > ?) AS `new_today`, SUM(`signedup` > ?) AS `new_week`,
                COALESCE(SUM(`money`), 0) AS `money`, COALESCE(SUM(IF(`bankmoney` > 0, `bankmoney`, 0)), 0) AS `bank`, COALESCE(SUM(IF(`cybermoney` > 0, `cybermoney`, 0)), 0) AS `cyber`,
                COALESCE(SUM(`crystals`), 0) AS `crystals`, SUM(`fedjail` > 0) AS `fedjailed`, SUM(`hospital` > 0) AS `hospital`, SUM(`jail` > 0) AS `jail`,
                SUM(`money` < 0 OR `crystals` < 0 OR `bankmoney` < -1 OR `cybermoney` < -1 OR `energy` < 0 OR `brave` < 0) AS `broken`
         FROM `users` WHERE `user_level` > 0', [$now - 900, $now - 86400, $now - 7 * 86400, $midnight, $now - 7 * 86400]);
    $out = array_map('intval', $u);
    $out['vaults'] = (int)$db->value('SELECT COALESCE(SUM(`gangMONEY`), 0) FROM `gangs`');
    $out['bounty_pot'] = (int)$db->value("SELECT COALESCE(SUM(`amount`), 0) FROM `v3_bounties` WHERE `status` = 'open'");
    $out['all_money'] = $out['money'] + $out['bank'] + $out['cyber'] + $out['vaults'] + $out['bounty_pot'];
    $out['reports'] = (int)$db->value('SELECT COUNT(*) FROM `preports`');
    $out['gangs'] = (int)$db->value('SELECT COUNT(*) FROM `gangs`');
    $out['negative_inventory'] = (int)$db->value('SELECT COUNT(*) FROM `inventory` WHERE `inv_qty` < 0');
    $out['negative_vaults'] = (int)$db->value('SELECT COUNT(*) FROM `gangs` WHERE `gangMONEY` < 0 OR `gangCRYSTALS` < 0');
    return $out;
}

/** Write today's snapshot if it has not been written. One guarded statement decides which request does it. */
function dash_snapshot(?string $today = null): bool
{
    global $db, $set;
    $today ??= date('Y-m-d');
    if ($db->exec("UPDATE `settings` SET `conf_value` = ? WHERE `conf_name` = 'engine_snapshot_day' AND `conf_value` <> ?", [$today, $today]) !== 1) {
        $set['engine_snapshot_day'] = $today;
        return false;
    }
    $set['engine_snapshot_day'] = $today;
    $t = dash_totals();
    $db->run('INSERT IGNORE INTO `v3_snapshots` (`day`, `players`, `active`, `signups`, `money`, `crystals`) VALUES (?, ?, ?, ?, ?, ?)',
        [$today, $t['players'], $t['active_day'], $t['new_today'], $t['all_money'], $t['crystals']]);
    return true;
}

/** The last $days snapshots, oldest first. */
function dash_history(int $days = 30): array
{
    global $db;
    $days = max(2, min($days, 365));
    return array_reverse($db->all("SELECT `day`, `players`, `active`, `signups`, `money`, `crystals` FROM `v3_snapshots` ORDER BY `day` DESC LIMIT {$days}"));
}

/** Things a cheat or a fault leaves behind. Each list is short and newest or largest first. */
function dash_watch(): array
{
    global $db;
    $day = time() - 86400;
    return [
        'transfers' => $db->all(
            'SELECT `x`.`cxFROM`, `x`.`cxTO`, `x`.`cxAMOUNT`, `x`.`cxTIME`, (`x`.`cxFROMIP` = `x`.`cxTOIP`) AS `same_ip`, `f`.`username` AS `fname`, `t`.`username` AS `tname`
             FROM `cashxferlogs` AS `x` LEFT JOIN `users` AS `f` ON `f`.`userid` = `x`.`cxFROM` LEFT JOIN `users` AS `t` ON `t`.`userid` = `x`.`cxTO`
             WHERE `x`.`cxTIME` > ? ORDER BY `x`.`cxAMOUNT` DESC LIMIT 10', [$day]),
        'shared_ips' => $db->all(
            "SELECT `lastip`, COUNT(*) AS `accounts`, GROUP_CONCAT(CONCAT(`username`, ' [', `userid`, ']') ORDER BY `userid` SEPARATOR ', ') AS `who`
             FROM `users` WHERE `user_level` > 0 AND `lastip` <> '' AND `laston` > ? GROUP BY `lastip` HAVING COUNT(*) > 1 ORDER BY `accounts` DESC LIMIT 10", [time() - 7 * 86400]),
        'richest' => $db->all(
            'SELECT `userid`, `username`, `level`, (`money` + IF(`bankmoney` > 0, `bankmoney`, 0) + IF(`cybermoney` > 0, `cybermoney`, 0)) AS `worth`
             FROM `users` WHERE `user_level` = 1 ORDER BY `worth` DESC LIMIT 10'),
        'failed_logins' => $db->all('SELECT `ip`, COUNT(*) AS `tries` FROM `login_attempts` WHERE `at` > ? GROUP BY `ip` HAVING COUNT(*) >= 5 ORDER BY `tries` DESC LIMIT 10', [time() - 3600]),
    ];
}

/** How the timed jobs and the engine are doing. */
function dash_health(): array
{
    global $db, $set;
    $due = ['minute-1' => 60, 'minute-5' => 300, 'hour-1' => 3600, 'day-1' => 86400];
    $crons = [];
    foreach ($db->all('SELECT `name`, `last_run`, TIMESTAMPDIFF(SECOND, `last_run`, NOW()) AS `ago` FROM `cron_times` ORDER BY `id`') as $c) {
        $every = $due[$c['name']] ?? 0;
        $crons[] = ['name' => $c['name'], 'last_run' => $c['last_run'], 'ago' => (int)$c['ago'], 'late' => $every > 0 && (int)$c['ago'] > $every * 3];
    }
    return [
        'crons' => $crons,
        'cron_fails' => (int)$db->value('SELECT COUNT(*) FROM `logs_cron_fails` WHERE `time_started` > NOW() - INTERVAL 1 DAY'),
        'engine' => MCC_ENGINE_VERSION, 'schema' => (int)($set['engine_schema'] ?? 0), 'schema_latest' => schema_latest(),
        'php' => PHP_VERSION, 'database' => (string)$db->value('SELECT VERSION()'),
    ];
}

/** What the new features did today. */
function dash_mods(): array
{
    global $db;
    $today = date('Y-m-d');
    $midnight = strtotime('today');
    return [
        'Daily rewards claimed today' => (int)$db->value('SELECT COUNT(*) FROM `v3_daily` WHERE `last_claim` = ?', [$today]),
        'Missions collected today' => (int)$db->value('SELECT COUNT(*) FROM `v3_missions` WHERE `day` = ? AND `claimed` > 0', [$today]),
        'Achievements unlocked today' => (int)$db->value('SELECT COUNT(*) FROM `v3_achievements` WHERE `earned` >= ?', [$midnight]),
        'Bounties placed today' => (int)$db->value('SELECT COUNT(*) FROM `v3_bounties` WHERE `placed` >= ?', [$midnight]),
        'Bounties collected today' => (int)$db->value("SELECT COUNT(*) FROM `v3_bounties` WHERE `status` = 'collected' AND `closed` >= ?", [$midnight]),
        'Crafting recipes available' => (int)$db->value('SELECT COUNT(*) FROM `v3_recipes` WHERE `enabled` = 1'),
        'Players with an API key' => (int)$db->value('SELECT COUNT(*) FROM `v3_api_keys`'),
        'Fights today' => (int)$db->value('SELECT COUNT(*) FROM `attacklogs` WHERE `time` >= ?', [$midnight]),
    ];
}

/** 1234567 -> "1.23M": a figure short enough for a tile or an axis. */
function dash_short(int|float $n): string
{
    $a = abs($n);
    return match (true) {
        $a >= 1e12 => round($n / 1e12, 2) . 'T',
        $a >= 1e9 => round($n / 1e9, 2) . 'B',
        $a >= 1e6 => round($n / 1e6, 2) . 'M',
        $a >= 1e4 => round($n / 1e3, 1) . 'K',
        default => number_format($n),
    };
}

/**
 * One trend as an SVG line chart: a single series, its own axis, the latest value labelled, and every point
 * hoverable. $points is [['label' => '13 Nov', 'value' => 123], ...], oldest first.
 */
function dash_chart(string $title, array $points, string $colour, string $prefix = ''): string
{
    $w = 420;
    $ht = 150;
    $l = 48;
    $r = 14;
    $t = 12;
    $b = 24;
    $n = count($points);
    if ($n < 2) {
        return "<figure class='dash-chart'><figcaption>" . e($title) . "</figcaption><div class='dash-nodata'>A point is recorded each day. The trend appears after two days.</div></figure>";
    }
    $values = array_column($points, 'value');
    $max = max($values);
    $min = min($values);
    $min = $min > 0 && ($max - $min) / max($max, 1) > 0.25 ? 0 : $min;     // start at zero unless that would flatten the line
    $span = max($max - $min, 1);
    $x = fn (int $i): float => round($l + ($w - $l - $r) * $i / ($n - 1), 1);
    $y = fn (float $v): float => round($t + ($ht - $t - $b) * (1 - ($v - $min) / $span), 1);
    $path = '';
    $dots = '';
    foreach ($points as $i => $p) {
        $path .= ($i ? ' L' : 'M') . $x($i) . ' ' . $y((float)$p['value']);
        $tip = e($p['label'] . ': ' . $prefix . number_format((float)$p['value']));
        // a wide invisible target with the dot inside it, so a point is easy to hover; only the latest dot shows all the time
        $dots .= "<g class='dash-pt" . ($i === $n - 1 ? ' dash-last' : '') . "'><title>{$tip}</title><rect x='" . ($x($i) - 7) . "' y='{$t}' width='14' height='" . ($ht - $t - $b) . "' fill='transparent' />"
            . "<circle cx='{$x($i)}' cy='{$y((float)$p['value'])}' r='" . ($i === $n - 1 ? 4 : 2.5) . "' fill='{$colour}' stroke='#141B2D' stroke-width='2' /></g>";
    }
    $grid = '';
    foreach ([$min, $min + $span / 2, $max] as $g) {
        $gy = $y((float)$g);
        $grid .= "<line x1='{$l}' x2='" . ($w - $r) . "' y1='{$gy}' y2='{$gy}' stroke='rgba(148,163,203,.16)' stroke-width='1' /><text x='" . ($l - 6) . "' y='" . ($gy + 4) . "' text-anchor='end' class='dash-ax'>" . e($prefix . dash_short($g)) . '</text>';
    }
    $last = $points[$n - 1];
    $first = $points[0];
    $change = (float)$first['value'] != 0.0 ? ((float)$last['value'] - (float)$first['value']) / (float)$first['value'] * 100 : 0.0;
    $summary = e($title . ': ' . $prefix . number_format((float)$last['value']) . ' on ' . $last['label'] . ', ' . ($change >= 0 ? 'up ' : 'down ') . abs(round($change, 1)) . '% since ' . $first['label']);
    return "<figure class='dash-chart'><figcaption>" . e($title) . ' <b>' . e($prefix . dash_short((float)$last['value'])) . '</b> <small>' . ($change >= 0 ? '+' : '') . round($change, 1) . "% in {$n} days</small></figcaption>
<svg viewBox='0 0 {$w} {$ht}' role='img' aria-label='{$summary}' preserveAspectRatio='xMidYMid meet'>{$grid}
<path d='{$path}' fill='none' stroke='{$colour}' stroke-width='2' stroke-linejoin='round' stroke-linecap='round' />{$dots}
<text x='{$l}' y='" . ($ht - 6) . "' class='dash-ax'>" . e($first['label']) . "</text><text x='" . ($w - $r) . "' y='" . ($ht - 6) . "' text-anchor='end' class='dash-ax'>" . e($last['label']) . '</text></svg></figure>';
}
