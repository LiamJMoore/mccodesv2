<?php
declare(strict_types=1);

/**
 * The dashboard's brain: what a player could usefully do right now, most pressing first. Each suggestion is read
 * from the player's actual state, so the home page changes through the day instead of listing the same links.
 */

/** [['href', 'icon', 'title', 'text', 'urgent' => bool], ...] for the player row $ir. */
function home_suggestions(array $ir): array
{
    $id = (int)$ir['userid'];
    $level = (int)$ir['level'];
    $out = [];
    $add = function (int $weight, string $href, string $icon, string $title, string $text, bool $urgent = false) use (&$out) {
        $out[] = ['weight' => $weight, 'href' => $href, 'icon' => $icon, 'title' => $title, 'text' => $text, 'urgent' => $urgent];
    };
    if ((int)$ir['hp'] < (int)$ir['maxhp'] / 3) {
        $add(100, 'crystalshop.php', 'cross', 'You are badly hurt', 'Health ' . (int)$ir['hp'] . ' of ' . (int)$ir['maxhp'] . '. Patch up before someone finds you.', true);
    }
    $bounty = bounty_on($id);
    if ($bounty > 0) {
        $add(95, 'bounties.php', 'crosshair', 'There is a price on your head', money_formatter($bounty) . ' to whoever puts you in hospital.', true);
    }
    $daily = once('daily', fn () => daily_status($id, $level));
    if (!$daily['claimed']) {
        $add(90, 'daily.php', 'gift', 'Claim your daily reward', 'Day ' . $daily['next'] . ' pays ' . money_formatter($daily['reward']['money']) . ($daily['reward']['crystals'] ? " and {$daily['reward']['crystals']} crystals" : '') . '.', true);
    }
    $season = season_waiting($id, once('season', fn () => season_status($id)));
    if ($season > 0) {
        $add(85, 'season.php', 'star', 'Season rewards are waiting', $season . ' to collect.', true);
    }
    $missions = once('missions', fn () => missions_today($id, $level));
    $ready = count(array_filter($missions, fn ($m) => (int)$m['claimed'] === 0 && (int)$m['progress'] >= (int)$m['target']));
    $open = count(array_filter($missions, fn ($m) => (int)$m['progress'] < (int)$m['target']));
    if ($ready > 0) {
        $add(84, 'missions.php', 'target', 'A mission is finished', $ready . ' reward' . ($ready === 1 ? '' : 's') . ' to collect.', true);
    } elseif ($open > 0) {
        $next = array_values(array_filter($missions, fn ($m) => (int)$m['progress'] < (int)$m['target']))[0];
        $add(40, 'missions.php', 'target', 'Today\'s missions', mission_text($next) . " ({$next['progress']} of {$next['target']}). {$open} still open.");
    }
    $inv = invest_of($id);
    if ($inv !== null && $inv['ready']) {
        $add(83, 'invest.php', 'vault', 'Your investment has matured', money_formatter($inv['amount'] + $inv['interest']) . ' is ready to collect.', true);
    }
    $pet = pet_of($id);
    if ($pet !== null && $pet['full'] < 30) {
        $add(70, 'pet.php', 'paw', e($pet['name']) . ' is hungry', 'Fed ' . $pet['full'] . '%. It will not train or race until it eats.', true);
    } elseif ($pet !== null && $pet['can_train_in'] === 0 && (int)$ir['energy'] >= MCC_PET_TRAIN_ENERGY) {
        $add(35, 'pet.php', 'paw', e($pet['name']) . ' is ready to train', 'A session costs ' . MCC_PET_TRAIN_ENERGY . ' energy.');
    }
    if ((int)$ir['brave'] > 0) {
        $add(60, 'criminal.php', 'mask', 'You have brave to spend', (int)$ir['brave'] . ' of ' . (int)$ir['maxbrave'] . '. Crimes pay cash and experience.');
    }
    if ((int)$ir['energy'] >= (int)$ir['maxenergy'] / 2) {
        $add(55, 'gym.php', 'dumbbell', 'You have energy to train', (int)$ir['energy'] . ' of ' . (int)$ir['maxenergy'] . '. Will is at ' . (int)($ir['will'] / max(1, $ir['maxwill']) * 100) . '%.');
    }
    if ((int)$ir['money'] >= 25000 && (int)$ir['bankmoney'] >= 0) {
        $add(50, 'bank.php', 'bank', 'You are carrying a lot of cash', money_formatter((int)$ir['money']) . ' in your pocket can be mugged. The bank is safe.');
    } elseif ((int)$ir['money'] >= 25000) {
        $add(50, 'bank.php', 'bank', 'Open a bank account', money_formatter((int)$ir['money']) . ' in your pocket can be mugged.');
    }
    if ((int)$ir['gang'] === 0 && $level >= 3) {
        $add(20, 'gangcentral.php', 'users', 'Join a gang', 'Gangs hold territory and get paid for it every Monday.');
    }
    if ($inv === null && (int)$ir['money'] >= 5000) {
        $add(15, 'invest.php', 'vault', 'Put money to work', 'A week in the investment bank pays ' . (MCC_INVEST_TERMS[7] / 10) . '%.');
    }
    usort($out, fn ($a, $b) => $b['weight'] <=> $a['weight']);
    return $out;
}

/** The player's last few events, newest first: [['time', 'text' (HTML, as the game wrote it), 'unread'], ...]. */
function home_events(int $userid, int $limit = 6): array
{
    global $db;
    return $db->all('SELECT `evTIME` AS `time`, `evTEXT` AS `text`, (`evREAD` = 0) AS `unread` FROM `events` WHERE `evUSER` = ? ORDER BY `evTIME` DESC, `evID` DESC LIMIT ' . max(1, min($limit, 20)), [$userid]);
}

/** Everything the player owns that has a cash value the game knows: pocket, banks, shares at today's price, cargo at cost, a running investment. One query. */
function home_net_worth(array $ir): int
{
    global $db;
    $id = (int)$ir['userid'];
    return (int)$ir['money'] + max(0, (int)$ir['bankmoney']) + max(0, (int)$ir['cybermoney']) + (int)$db->value(
        'SELECT (SELECT COALESCE(SUM(`h`.`shares` * `s`.`price`), 0) FROM `v3_stock_holdings` AS `h` INNER JOIN `v3_stocks` AS `s` ON `s`.`id` = `h`.`stock` WHERE `h`.`userid` = ?)
              + (SELECT COALESCE(SUM(`paid`), 0) FROM `v3_trade_cargo` WHERE `userid` = ?)
              + (SELECT COALESCE(SUM(`amount`), 0) FROM `v3_investments` WHERE `userid` = ?)', [$id, $id, $id]);
}

/**
 * Where the player ranks for each battle stat and for all five together, in one query (the dashboard used to ask
 * six separate questions). Returns ['strength' => rank, ..., 'total' => rank]; 1 is top. NPCs are left out.
 */
function home_ranks(array $ir): array
{
    global $db;
    $cols = ['strength', 'agility', 'guard', 'labour', 'IQ'];
    $select = [];
    $params = [];
    $total = 0.0;
    foreach ($cols as $c) {
        $select[] = "COALESCE(SUM(`us`.`{$c}` > ?), 0) AS `{$c}`";
        $params[] = (float)$ir[$c];
        $total += (float)$ir[$c];
    }
    $select[] = 'COALESCE(SUM(`us`.`strength` + `us`.`agility` + `us`.`guard` + `us`.`labour` + `us`.`IQ` > ?), 0) AS `total`';
    $params[] = $total;
    $params[] = (int)$ir['userid'];
    $r = $db->row('SELECT ' . implode(', ', $select) . ' FROM `userstats` AS `us` INNER JOIN `users` AS `u` ON `u`.`userid` = `us`.`userid` WHERE `us`.`userid` <> ? AND `u`.`user_level` <> 0', $params);
    return array_map(fn ($n) => (int)$n + 1, $r);
}
