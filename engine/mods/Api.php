<?php
declare(strict_types=1);

/**
 * The player API: read-only facts about the game as JSON, for a player's own tools (a stats tracker, a Discord
 * bot, a phone widget). Served by api.php.
 *
 * A player makes a key at apikey.php. The key is shown once; the game stores only a fingerprint of it, so a copy of
 * the database does not hand out working keys. A key reads; it can never spend, send or change anything.
 */
const MCC_API_PER_MINUTE = 60;        // requests a key may make each minute
const MCC_API_TOP = ['level' => 'Level', 'crimes' => 'Successful crimes', 'gym' => 'Gym sessions', 'fights_won' => 'Fights won', 'bounties' => 'Bounties collected',
                     'crafted' => 'Items crafted', 'missions' => 'Missions completed', 'streak' => 'Longest daily streak', 'achievements' => 'Achievements'];

/** Make (or replace) a player's key. Returns the key, which cannot be recovered afterwards. */
function api_key_create(int $userid): string
{
    global $db;
    $key = bin2hex(random_bytes(20));
    $db->run('INSERT INTO `v3_api_keys` (`userid`, `key_hash`, `created`, `last_used`, `calls`, `window_start`, `window_calls`) VALUES (?, ?, ?, 0, 0, 0, 0)
              ON DUPLICATE KEY UPDATE `key_hash` = VALUES(`key_hash`), `created` = VALUES(`created`), `last_used` = 0, `calls` = 0, `window_start` = 0, `window_calls` = 0',
        [$userid, hash('sha256', $key), time()]);
    return $key;
}

function api_key_revoke(int $userid): void
{
    global $db;
    $db->exec('DELETE FROM `v3_api_keys` WHERE `userid` = ?', [$userid]);
}

/** ['created' => , 'last_used' => , 'calls' => ] for a player's key, or null if they have none. */
function api_key_info(int $userid): ?array
{
    global $db;
    return $db->row('SELECT `created`, `last_used`, `calls` FROM `v3_api_keys` WHERE `userid` = ?', [$userid]);
}

/**
 * Whose key is this? Returns the player's id; 0 if the key is not one; -1 if it is but has used its allowance for
 * this minute. Counting the request and checking the allowance are one statement.
 */
function api_key_use(string $key, ?int $now = null): int
{
    global $db;
    if (!preg_match('/^[0-9a-f]{40}$/', $key)) {
        return 0;
    }
    $now ??= time();
    $minute = $now - ($now % 60);
    $hash = hash('sha256', $key);
    $userid = (int)$db->value('SELECT `userid` FROM `v3_api_keys` WHERE `key_hash` = ?', [$hash], 0);
    if ($userid === 0) {
        return 0;
    }
    $ok = $db->exec(
        'UPDATE `v3_api_keys` SET `window_calls` = IF(`window_start` = ?, `window_calls` + 1, 1), `window_start` = ?, `calls` = `calls` + 1, `last_used` = ?
         WHERE `key_hash` = ? AND (`window_start` <> ? OR `window_calls` < ?)', [$minute, $minute, $now, $hash, $minute, MCC_API_PER_MINUTE]);
    return $ok === 1 ? $userid : -1;
}

/** Everything about the player themselves. */
function api_me(int $userid): ?array
{
    global $db;
    $u = $db->row('SELECT `u`.*, `us`.`strength`, `us`.`agility`, `us`.`guard`, `us`.`labour`, `us`.`IQ`, `c`.`cityname`, `g`.`gangNAME`
                   FROM `users` AS `u` INNER JOIN `userstats` AS `us` ON `us`.`userid` = `u`.`userid`
                   LEFT JOIN `cities` AS `c` ON `c`.`cityid` = `u`.`location` LEFT JOIN `gangs` AS `g` ON `g`.`gangID` = `u`.`gang` WHERE `u`.`userid` = ?', [$userid]);
    if ($u === null) {
        return null;
    }
    $daily = function_exists('daily_status') ? daily_status($userid, (int)$u['level']) : null;
    return [
        'id' => (int)$u['userid'], 'name' => $u['username'], 'level' => (int)$u['level'], 'exp' => (float)$u['exp'],
        'money' => (int)$u['money'], 'crystals' => (int)$u['crystals'],
        'bank' => (int)$u['bankmoney'] >= 0 ? (int)$u['bankmoney'] : null, 'cyberbank' => (int)$u['cybermoney'] >= 0 ? (int)$u['cybermoney'] : null,
        'bars' => ['energy' => [(int)$u['energy'], (int)$u['maxenergy']], 'will' => [(int)$u['will'], (int)$u['maxwill']], 'brave' => [(int)$u['brave'], (int)$u['maxbrave']],
                   'health' => [(int)$u['hp'], (int)$u['maxhp']]],
        'stats' => ['strength' => (float)$u['strength'], 'agility' => (float)$u['agility'], 'guard' => (float)$u['guard'], 'labour' => (float)$u['labour'], 'iq' => (float)$u['IQ']],
        'city' => ['id' => (int)$u['location'], 'name' => $u['cityname']],
        'gang' => (int)$u['gang'] > 0 ? ['id' => (int)$u['gang'], 'name' => html_entity_decode((string)$u['gangNAME'], ENT_QUOTES, 'ISO-8859-1')] : null,
        'hospital_minutes' => (int)$u['hospital'], 'jail_minutes' => (int)$u['jail'],
        'unread' => ['events' => (int)$u['new_events'], 'mail' => (int)$u['new_mail']],
        'daily' => $daily === null ? null : ['claimed_today' => $daily['claimed'], 'streak' => $daily['streak'], 'best' => $daily['best']],
        'progress' => function_exists('progress_all') ? progress_all($userid) : [],
        'achievements' => function_exists('achievements_earned') ? array_keys(achievements_earned($userid)) : [],
        'bounty_on_you' => function_exists('bounty_on') ? bounty_on($userid) : 0,
    ];
}

/** What anyone may know about another player (what their profile page shows). */
function api_player(int $id): ?array
{
    global $db;
    $u = $db->row('SELECT `u`.`userid`, `u`.`username`, `u`.`level`, `u`.`gang`, `u`.`location`, `u`.`hospital`, `u`.`jail`, `u`.`laston`, `u`.`signedup`, `u`.`donatordays`, `u`.`user_level`,
                          `c`.`cityname`, `g`.`gangNAME`
                   FROM `users` AS `u` LEFT JOIN `cities` AS `c` ON `c`.`cityid` = `u`.`location` LEFT JOIN `gangs` AS `g` ON `g`.`gangID` = `u`.`gang` WHERE `u`.`userid` = ?', [$id]);
    if ($u === null) {
        return null;
    }
    return [
        'id' => (int)$u['userid'], 'name' => $u['username'], 'level' => (int)$u['level'],
        'city' => ['id' => (int)$u['location'], 'name' => $u['cityname']],
        'gang' => (int)$u['gang'] > 0 ? ['id' => (int)$u['gang'], 'name' => html_entity_decode((string)$u['gangNAME'], ENT_QUOTES, 'ISO-8859-1')] : null,
        'in_hospital' => (int)$u['hospital'] > 0, 'in_jail' => (int)$u['jail'] > 0,
        'last_seen' => (int)$u['laston'], 'joined' => (int)$u['signedup'], 'donator' => (int)$u['donatordays'] > 0, 'npc' => (int)$u['user_level'] === 0,
        'achievements' => function_exists('achievements_earned') ? count(achievements_earned($id)) : 0,
        'bounty' => function_exists('bounty_on') ? bounty_on($id) : 0,
    ];
}

/** The game as a whole. */
function api_game(): array
{
    global $db, $set;
    $u = $db->row('SELECT COUNT(*) AS `players`, COALESCE(SUM(`money`), 0) AS `money`, COALESCE(SUM(`crystals`), 0) AS `crystals`, COALESCE(MAX(`level`), 0) AS `top_level`,
                          SUM(`laston` > ?) AS `online`, SUM(`laston` > ?) AS `today` FROM `users`', [time() - 900, time() - 86400]);
    return [
        'name' => $set['game_name'] ?? '', 'engine' => MCC_ENGINE_VERSION, 'time' => time(),
        'players' => (int)$u['players'], 'online_now' => (int)$u['online'], 'active_today' => (int)$u['today'], 'highest_level' => (int)$u['top_level'],
        'money_in_pockets' => (int)$u['money'], 'crystals_in_pockets' => (int)$u['crystals'],
        'gangs' => (int)$db->value('SELECT COUNT(*) FROM `gangs`'),
        'open_bounties' => (int)$db->value("SELECT COALESCE(SUM(`amount`), 0) FROM `v3_bounties` WHERE `status` = 'open'"),
    ];
}

/** A leaderboard: [['rank' => 1, 'id' => , 'name' => , 'value' => ], ...]. Staff accounts and NPCs are left out. */
function api_top(string $by, int $limit = 10): array
{
    global $db;
    $limit = max(1, min($limit, 50));
    if (!isset(MCC_API_TOP[$by])) {
        return [];
    }
    if ($by === 'level') {
        $rows = $db->all("SELECT `userid`, `username`, `level` AS `value` FROM `users` WHERE `user_level` = 1 ORDER BY `level` DESC, `exp` DESC, `userid` LIMIT {$limit}");
    } elseif ($by === 'achievements') {
        $rows = $db->all("SELECT `u`.`userid`, `u`.`username`, COUNT(*) AS `value` FROM `v3_achievements` AS `a` INNER JOIN `users` AS `u` ON `u`.`userid` = `a`.`userid`
                          WHERE `u`.`user_level` = 1 GROUP BY `u`.`userid`, `u`.`username` ORDER BY `value` DESC, `u`.`userid` LIMIT {$limit}");
    } else {
        $rows = $db->all("SELECT `u`.`userid`, `u`.`username`, `p`.`amount` AS `value` FROM `v3_progress` AS `p` INNER JOIN `users` AS `u` ON `u`.`userid` = `p`.`userid`
                          WHERE `p`.`stat` = ? AND `p`.`amount` > 0 AND `u`.`user_level` = 1 ORDER BY `p`.`amount` DESC, `u`.`userid` LIMIT {$limit}", [$by]);
    }
    $out = [];
    foreach ($rows as $i => $r) {
        $out[] = ['rank' => $i + 1, 'id' => (int)$r['userid'], 'name' => $r['username'], 'value' => (int)$r['value']];
    }
    return $out;
}

/** Where a player stands for one counter: their rank among players who have any (1 is top), or null with none. */
function api_rank(int $userid, string $stat): ?int
{
    global $db;
    $mine = (int)$db->value('SELECT `amount` FROM `v3_progress` WHERE `userid` = ? AND `stat` = ?', [$userid, $stat], 0);
    if ($mine <= 0) {
        return null;
    }
    return 1 + (int)$db->value('SELECT COUNT(*) FROM `v3_progress` AS `p` INNER JOIN `users` AS `u` ON `u`.`userid` = `p`.`userid` WHERE `p`.`stat` = ? AND `p`.`amount` > ? AND `u`.`user_level` = 1', [$stat, $mine]);
}

/** What the sidebar shows, plus the bell: money, crystals, level, each bar as [now, max, percent, label]. */
function api_live(int $userid): ?array
{
    global $db;
    $u = $db->row('SELECT `money`, `crystals`, `level`, `exp`, `energy`, `maxenergy`, `will`, `maxwill`, `brave`, `maxbrave`, `hp`, `maxhp`, `hospital`, `jail` FROM `users` WHERE `userid` = ?', [$userid]);
    if ($u === null) {
        return null;
    }
    $pc = fn ($now, $max): int => (int)min(100, floor((float)$now / max(1, (float)$max) * 100));
    $need = (int)(((int)$u['level'] + 1) ** 3 * 2.2);
    $bars = [];
    foreach (['energy' => 'maxenergy', 'will' => 'maxwill', 'brave' => 'maxbrave', 'hp' => 'maxhp'] as $bar => $max) {
        $p = $pc($u[$bar], $u[$max]);
        $bars[$bar] = ['now' => (int)$u[$bar], 'max' => (int)$u[$max], 'percent' => $p, 'label' => $bar === 'brave' ? (int)$u[$bar] . '/' . (int)$u[$max] : $p . '%'];
    }
    $bars['exp'] = ['now' => (float)$u['exp'], 'max' => $need, 'percent' => $pc($u['exp'], $need), 'label' => $pc($u['exp'], $need) . '%'];
    return ['money' => (int)$u['money'], 'money_text' => html_entity_decode(money_formatter((int)$u['money']), ENT_QUOTES, 'ISO-8859-1'), 'crystals' => (int)$u['crystals'], 'level' => (int)$u['level'],
            'bars' => $bars, 'hospital' => (int)$u['hospital'], 'jail' => (int)$u['jail'], 'notifications' => notify_summary($userid)];
}

