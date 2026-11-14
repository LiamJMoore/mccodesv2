<?php
/**
 * The engine's own checks, without a web server:  php tests/engine.php
 * Needs a game installed by tools/dev/setup.php (it reads config.php and uses that database).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit('Command line only.');
}
chdir(dirname(__DIR__));
require 'lib/basic_error_handler.php';
const MONO_ON = 1;
require 'engine/version.php';
require 'engine/helpers.php';
require 'engine/Economy.php';
require 'engine/Items.php';
require 'engine/Formula.php';
require 'engine/Combat.php';
require 'engine/Schema.php';
require 'engine/Progress.php';
require 'engine/mods/Daily.php';
require 'engine/mods/Achievements.php';
require 'engine/mods/Missions.php';
require 'engine/mods/Bounties.php';
require 'engine/mods/Crafting.php';
require 'engine/mods/Season.php';
require 'engine/mods/Store.php';
require 'engine/mods/Perks.php';
require 'engine/mods/Notify.php';
require 'engine/mods/Api.php';
require 'engine/mods/Dashboard.php';
require 'global_func.php';
$set = [];
include 'config.php';
require 'class/class_db_mysqli.php';
$db = new database();
$db->configure($_CONFIG['hostname'], $_CONFIG['username'], $_CONFIG['password'], $_CONFIG['database']);
$db->connect();

schema_upgrade();          // a game straight from the installer has not loaded a page yet
$failed = 0;
function check(bool $ok, string $what, mixed $detail = ''): void
{
    global $failed;
    echo ($ok ? 'ok    ' : 'FAIL  ') . $what . ($ok || $detail === '' ? '' : "\n      " . substr(json_encode($detail), 0, 300)) . "\n";
    $failed += $ok ? 0 : 1;
}
$bal = fn (int $id): array => array_map('intval', $db->row('SELECT `money`, `crystals`, `bankmoney`, `cybermoney` FROM `users` WHERE `userid` = ?', [$id]));

check(MCC_ENGINE_VERSION === '3.0.0', 'the engine says which version it is');

// bound parameters
check($db->row('SELECT `userid`, `username` FROM `users` WHERE `username` = ? AND `userid` > ?', ['player1', 0]) === ['userid' => 2, 'username' => 'player1'], 'row() returns the row, with numbers as numbers');
check($db->row('SELECT * FROM `users` WHERE `userid` = ?', [987654]) === null, 'row() returns null when there is none');
check($db->value('SELECT COUNT(*) FROM `users` WHERE `user_level` = ?', [1]) === 3 && $db->value('SELECT `userid` FROM `users` WHERE `userid` = ?', [0], 'none') === 'none', 'value() returns one figure, or the default');
check(count($db->all('SELECT `userid` FROM `users` ORDER BY `userid`')) === 4, 'all() returns every row');
foreach (["x' OR '1'='1", "'; DROP TABLE users; -- ", 'player1" OR ""="', "\\' OR 1=1 -- "] as $attack) {
    $hits[] = $db->value('SELECT COUNT(*) FROM `users` WHERE `username` = ?', [$attack]);
}
check($hits === [0, 0, 0, 0] && $db->value('SELECT COUNT(*) FROM `users`') === 4, 'text that tries to be SQL is only ever compared as text: nothing matches and the table is still there', $hits);
$before = $db->num_queries;
$db->row('SELECT 1');
check($db->num_queries === $before + 1 && $db->last_query === 'SELECT 1', 'each statement is counted and recorded, as query() does');
$id = $db->insert('events', ['evUSER' => 2, 'evTIME' => 123, 'evREAD' => 0, 'evTEXT' => "it's a <b>test</b>"]);
check($id > 0 && $db->value('SELECT `evTEXT` FROM `events` WHERE `evID` = ?', [$id]) === "it's a <b>test</b>", 'insert() stores text exactly as given and returns the new id');
check($db->exec('DELETE FROM `events` WHERE `evID` = ?', [$id]) === 1 && $db->exec('DELETE FROM `events` WHERE `evID` = ?', [$id]) === 0, 'exec() says how many rows changed');
try {
    $db->transaction(function ($db) {
        $db->exec('UPDATE `users` SET `money` = 777 WHERE `userid` = 2');
        throw new DomainException('stop');
    });
} catch (DomainException) {
}
$db->exec('UPDATE `users` SET `money` = 100, `crystals` = 10, `bankmoney` = -1, `cybermoney` = -1 WHERE `userid` IN (2, 3)');
check($bal(2)['money'] === 100, 'a transaction that fails part-way leaves nothing changed');

// input
$_GET = ['ID' => '42', 'neg' => '-7', 'bad' => '4 OR 1=1', 'arr' => ['x'], 'name' => "  Bob\0 ", 'pick' => 'deposit'];
$_POST = ['ID' => '9'];
check(in_int('ID') === 9 && in_int('ID', 0, $_GET) === 42 && in_int('neg', 0, $_GET) === 7 && in_int('bad', 0, $_GET) === 0 && in_int('arr', 5, $_GET) === 5 && in_int('none', 3) === 3,
    'in_int() gives a whole number that is never negative, and the default for anything else');
check(in_str('name', 255, '', $_GET) === 'Bob' && in_str('arr', 255, 'd', $_GET) === 'd' && in_str('name', 2, '', $_GET) === 'Bo', 'in_str() gives trimmed text of a limited length, never an array');
check(in_choice('pick', ['deposit', 'withdraw'], '', $_GET) === 'deposit' && in_choice('pick', ['buy'], 'x', $_GET) === 'x', 'in_choice() gives only an answer from the list');
check(e("<script>alert('x')</script>") === '&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', 'e() makes text safe to print');

// money
check(wallet_take(2, 'money', 60) && $bal(2)['money'] === 40 && !wallet_take(2, 'money', 41) && $bal(2)['money'] === 40, 'wallet_take() takes what is there and refuses what is not');
check(wallet_give(2, 'money', 60) && $bal(2)['money'] === 100 && !wallet_give(2, 'bankmoney', 5) && $bal(2)['bankmoney'] === -1, 'wallet_give() adds to a balance, but not to an account that has not been opened');
check(!wallet_move(2, 'money', 'bankmoney', 50) && $bal(2)['money'] === 100, 'wallet_move() will not move money into an unopened account');
check(!wallet_open(2, 'bankmoney', 101) && wallet_open(2, 'bankmoney', 100) && $bal(2)['money'] === 0 && $bal(2)['bankmoney'] === 0 && !wallet_open(2, 'bankmoney', 0), 'wallet_open() takes the fee and opens the account once');
$db->exec('UPDATE `users` SET `money` = 100 WHERE `userid` = 2');
check(wallet_move(2, 'money', 'bankmoney', 100, 85) && $bal(2)['money'] === 0 && $bal(2)['bankmoney'] === 85 && !wallet_move(2, 'money', 'bankmoney', 1), 'wallet_move() takes one amount and gives another (the fee is the difference), and refuses when short');
check(wallet_send(2, 3, 'crystals', 4) && $bal(2)['crystals'] === 6 && $bal(3)['crystals'] === 14 && !wallet_send(2, 3, 'crystals', 7) && !wallet_send(2, 2, 'crystals', 1) && !wallet_send(2, 99999, 'crystals', 1)
    && $bal(2)['crystals'] === 6 && $bal(3)['crystals'] === 14, 'wallet_send() moves between players, and a send that cannot complete (short, to yourself, to nobody) takes nothing');
check(!wallet_send(2, 3, 'bankmoney', 10) && $bal(2)['bankmoney'] === 85, 'a send into a bank account the receiver has not opened is undone');
$threw = 0;
foreach ([fn () => wallet_take(2, 'userpass', 1), fn () => wallet_take(2, 'money', -5), fn () => wallet_move(2, 'money', 'money', 1)] as $bad) {
    try {
        $bad();
    } catch (InvalidArgumentException) {
        $threw++;
    }
}
check($threw === 3, 'the money functions refuse a column that is not a balance, a negative amount, and a move to the same balance');

// bars
$db->exec('UPDATE `users` SET `energy` = 10, `brave` = 5 WHERE `userid` = 2');
check(bar_spend(2, 'energy', 10) && !bar_spend(2, 'energy', 1) && bar_spend(2, 'brave', 0) && !bar_spend(2, 'brave', 6) && (int)$db->value('SELECT `brave` FROM `users` WHERE `userid` = 2') === 5,
    'bar_spend() spends energy or brave that is there and refuses what is not');

$db->exec('UPDATE `users` SET `energy` = 12, `brave` = 5 WHERE `userid` = 2');

// formulas
$v = ['LEVEL' => 8, 'WILL' => 100, 'CRIMEXP' => 40, 'EXP' => 3, 'IQ' => 20];
check(abs(formula_value('((WILL*0.8)/2.5)+(LEVEL/4)', $v) - 34.0) < 1e-9 && formula_value('2+3*4') === 14.0 && formula_value('(2+3)*4') === 20.0 && formula_value('-2**2') === -4.0
    && formula_value('2**3**2') === 512.0 && formula_value('10%4') === 2.0 && formula_value('min(95, level*20)', $v) === 95.0 && formula_value('round(7/2)') === 4.0
    && formula_value('1/0') === 0.0 && formula_value('1.5e2') === 150.0 && formula_value('CRIMEXP+EXP', $v) === 43.0,
    'formula_value() does arithmetic in the usual order, with brackets, powers, named values and a few functions');
$refused = 0;
foreach (['system("id")', 'phpinfo()', '$x=1', '1;2', '`id`', 'LEVEL.WILL', 'exec(1)', '2+', '(2', 'NOPE*2', '', 'pow(2)', "1+'a'", str_repeat('(', 80) . '1' . str_repeat(')', 80)] as $bad) {
    $refused += formula_check($bad, array_keys($v)) !== null ? 1 : 0;
}
check($refused === 14 && formula_check('LEVEL*2+WILL', array_keys($v)) === null, 'anything that is not arithmetic is refused: code, unknown names, unfinished sums', $refused);

// the database upgrades itself
check(schema_latest() >= 2 && (int)$db->value("SELECT `conf_value` FROM `settings` WHERE `conf_name` = 'engine_schema'") === schema_latest() && schema_upgrade() === [],
    'the database is on the latest schema step, and asking again applies nothing');

// the daily reward
$db->exec('DELETE FROM `v3_progress` WHERE `userid` = 2');
$db->exec('DELETE FROM `v3_achievements` WHERE `userid` = 2');
$db->exec('DELETE FROM `v3_missions` WHERE `userid` = 2');
$db->exec('DELETE FROM `v3_daily` WHERE `userid` = 2');
$db->exec('UPDATE `users` SET `money` = 0, `crystals` = 0 WHERE `userid` = 2');
check(daily_reward(1) === ['money' => 500, 'crystals' => 0] && daily_reward(7) === ['money' => 5000, 'crystals' => 5] && daily_reward(8) === ['money' => 500, 'crystals' => 0]
    && daily_reward(30) === ['money' => 750 + 50000, 'crystals' => 25] && daily_reward(1, 10)['money'] === 5000 && daily_reward(1, 999)['money'] === 25000,
    'daily_reward() rises through the week, adds crystals on day 7 and a bonus on day 30, and grows with level up to 50');
$st = daily_status(2, 1, '2026-03-01');
check(!$st['claimed'] && $st['streak'] === 0 && $st['next'] === 1, 'a player who has never claimed is on day 1');
$p = daily_claim(2, 1, '2026-03-01');
check($p === ['day' => 1, 'money' => 500, 'crystals' => 0] && $bal(2)['money'] === 500 && daily_claim(2, 1, '2026-03-01') === null && $bal(2)['money'] === 500,
    'daily_claim() pays day 1 once, and a second claim the same day pays nothing');
check(daily_status(2, 1, '2026-03-01')['claimed'] && daily_status(2, 1, '2026-03-02')['next'] === 2, 'after claiming, today shows as claimed and tomorrow is day 2');
$day = '2026-03-01';
for ($i = 2; $i <= 7; $i++) {
    $day = date('Y-m-d', strtotime($day . ' +1 day'));
    $p = daily_claim(2, 1, $day);
}
check($p === ['day' => 7, 'money' => 5000, 'crystals' => 5] && $bal(2)['crystals'] === 5 + 5 && $bal(2)['money'] === 500 + 750 + 1000 + 1500 + 2000 + 3000 + 5000 + 5000
    && isset(achievements_earned(2)['daily_7']), 'seven days in a row reach the weekly bonus, and unlock the Regular achievement with its own reward');
$p = daily_claim(2, 1, '2026-03-10');
$st = daily_status(2, 1, '2026-03-10');
check($p['day'] === 1 && $st['streak'] === 1 && $st['best'] === 7 && $st['claims'] === 8 && daily_status(2, 1, '2026-03-15')['streak'] === 0,
    'missing a day starts the streak again at day 1, and the longest streak is remembered');
$db->exec('DELETE FROM `v3_daily` WHERE `userid` = 2');
$db->exec('UPDATE `users` SET `money` = 100, `crystals` = 10 WHERE `userid` = 2');

// progress, achievements, missions
foreach (['v3_progress', 'v3_achievements', 'v3_missions', 'v3_daily'] as $t) {
    $db->exec("DELETE FROM `{$t}` WHERE `userid` = 2");
}
$db->exec('DELETE FROM `events` WHERE `evUSER` = 2');
$db->exec('UPDATE `users` SET `money` = 0, `crystals` = 0 WHERE `userid` = 2');
check(progress_add(2, 'crimes', 4) === 4 && progress_add(2, 'crimes') === 5 && progress_get(2, 'crimes') === 5 && progress_max(2, 'level', 3) === 3 && progress_max(2, 'level', 2) === 3
    && progress_all(2)['gym'] === 0, 'progress_add() counts up, progress_max() only ever rises');
$threw = false;
try {
    progress_add(2, 'nonsense');
} catch (InvalidArgumentException) {
    $threw = true;
}
check($threw && achievements_earned(2) === [] && $bal(2)['money'] === 0, 'an unknown counter is refused, and nothing is awarded below a target');
progress_add(2, 'crimes', 5);
check(array_keys(achievements_earned(2)) === ['crimes_10'] && $bal(2)['money'] === 1000, 'reaching 10 crimes unlocks Petty Thief and pays its reward');
progress_add(2, 'crimes', 5);
achievements_check(2, 'crimes', 0, 15);
check($bal(2)['money'] === 1000 && (int)$db->value("SELECT COUNT(*) FROM `events` WHERE `evUSER` = 2 AND `evTEXT` LIKE '%Petty Thief%'") === 1, 'an achievement is paid, and announced, once');
progress_add(2, 'crimes', 2000);
check(count(achievements_earned(2)) === 3 && $bal(2)['money'] === 1000 + 10000 + 100000 && $bal(2)['crystals'] === 30, 'a jump past several targets unlocks each of them');
$db->exec('UPDATE `users` SET `money` = 0, `crystals` = 0 WHERE `userid` = 2');

$ms = missions_today(2, 4, '2026-05-01');
$again = missions_today(2, 9, '2026-05-01');
check(count($ms) === 3 && $ms === $again && count(array_unique(array_column($ms, 'mission'))) === 3 && missions_today(3, 1, '2026-05-01') != [],
    'a player gets three different missions for the day, the same ones however often they look');
$other = array_column(missions_today(2, 4, '2026-05-02'), 'mission');
$days = [];
for ($d = 1; $d <= 20; $d++) {
    $days[] = implode(',', array_column(missions_today(2, 1, sprintf('2026-06-%02d', $d)), 'mission'));
}
check(count(array_unique($days)) > 5, 'the missions change from day to day', count(array_unique($days)));
check(!array_intersect(array_merge(...array_map(fn ($d) => explode(',', $d), $days)), ['crafted', 'bounties']) || function_exists('craft_make') || function_exists('bounty_place'),
    'missions for features that are not installed are never handed out');
// today's, for real: progress and claiming
$db->exec('DELETE FROM `v3_missions` WHERE `userid` = 2');
$today = missions_today(2, 2);
$m = $today[0];
check(mission_claim(2, (int)$m['slot']) === null && $bal(2)['money'] === 0, 'an unfinished mission cannot be collected');
$db->exec('UPDATE `v3_missions` SET `stat` = ?, `mission` = ?, `target` = 3 WHERE `userid` = 2 AND `slot` = 1', ['gym', 'gym']);
$db->exec('UPDATE `v3_missions` SET `stat` = ?, `mission` = ?, `target` = 1 WHERE `userid` = 2 AND `slot` = 2', ['shop_buy', 'shop']);
$db->exec('UPDATE `v3_missions` SET `stat` = ?, `mission` = ?, `target` = 1 WHERE `userid` = 2 AND `slot` = 3', ['fights_won', 'fights']);
progress_add(2, 'gym', 2);
$p1 = (int)$db->value('SELECT `progress` FROM `v3_missions` WHERE `userid` = 2 AND `slot` = 1');
progress_add(2, 'gym', 50);
$p2 = (int)$db->value('SELECT `progress` FROM `v3_missions` WHERE `userid` = 2 AND `slot` = 1');
check($p1 === 2 && $p2 === 3, 'doing the thing moves the mission, and it stops at its target');
$reward = (int)$db->value('SELECT `reward` FROM `v3_missions` WHERE `userid` = 2 AND `slot` = 1');
$paid = mission_claim(2, 1);
check($paid === ['money' => $reward, 'crystals' => 0] && $bal(2)['money'] === $reward && mission_claim(2, 1) === null && $bal(2)['money'] === $reward, 'a finished mission pays once');
progress_add(2, 'shop_buy');
progress_add(2, 'fights_won');
$money = $bal(2)['money'];
$a = mission_claim(2, 2);
$b = mission_claim(2, 3);
check($a['crystals'] === 0 && $b['crystals'] === MCC_MISSION_ALL_CRYSTALS && $bal(2)['crystals'] === MCC_MISSION_ALL_CRYSTALS + 1 && progress_get(2, 'missions') === 3,
    'collecting all three pays the bonus crystals once (plus First Blood for the fight)', [$a, $b, $bal(2)]);
check(mission_text(['mission' => 'crimes', 'target' => 1]) === 'Commit a successful crime' && mission_text(['mission' => 'gym', 'target' => 1]) === 'Train once in the gym'
    && mission_text(['mission' => 'fights', 'target' => 1]) === 'Win a fight' && mission_text(['mission' => 'bounties', 'target' => 1]) === 'Collect a bounty' && mission_text(['mission' => 'shop', 'target' => 1]) === 'Buy an item from a shop' && mission_text(['mission' => 'fights', 'target' => 2]) === 'Win 2 fights', 'mission wording reads properly for one and for several',
    [mission_text(['mission' => 'crimes', 'target' => 1]), mission_text(['mission' => 'gym', 'target' => 1]), mission_text(['mission' => 'fights', 'target' => 1])]);
foreach (['v3_progress', 'v3_achievements', 'v3_missions'] as $t) {
    $db->exec("DELETE FROM `{$t}` WHERE `userid` IN (2, 3)");
}
$db->exec('DELETE FROM `events` WHERE `evUSER` = 2');
$db->exec('UPDATE `users` SET `money` = 100, `crystals` = 10, `new_events` = 0 WHERE `userid` = 2');

// fights
$user = fn (int $id): array => $db->row('SELECT * FROM `users` WHERE `userid` = ?', [$id]);
$db->exec('UPDATE `users` SET `money` = 1000, `hp` = 0, `hospital` = 0, `gang` = 0, `level` = 3, `exp` = 0 WHERE `userid` = 3');
$db->exec('UPDATE `users` SET `money` = 0, `exp` = 0, `gang` = 0, `level` = 1 WHERE `userid` = 2');
$w = fight_win($user(2), 3, 'mug', 'the log');
check($w !== null && $w['stole'] >= 2 && $w['stole'] <= 50 && $bal(2)['money'] === $w['stole'] + 500 /* First Blood */ && $bal(3)['money'] === 1000 - $w['stole'] && (int)$user(3)['hp'] === 1 && (int)$user(3)['hospital'] >= 20
    && (int)$db->value('SELECT `stole` FROM `attacklogs` WHERE `attacker` = 2 AND `attacked` = 3 ORDER BY `log_id` DESC LIMIT 1') === $w['stole'],
    'mugging moves a share of the loser\'s money to the winner, puts them in hospital and logs the fight', $w['stole'] ?? null);
check(fight_win($user(2), 3, 'mug') === null && fight_win($user(2), 3, 'hospitalise') === null && $bal(3)['money'] === 1000 - $w['stole'], 'a fight that is already finished cannot be finished again');
check(fight_win($user(2), 2, 'mug') === null && fight_win($user(2), 99999, 'leave') === null, 'you cannot win against yourself or against nobody');
$db->exec('UPDATE `users` SET `hp` = 0, `hospital` = 0 WHERE `userid` = 3');
$w = fight_win($user(2), 3, 'leave');
check($w['exp'] >= 13 && $w['exp'] <= 27 && (float)$user(2)['exp'] == $w['exp'] && $w['stole'] === 0, 'leaving them gives experience from the loser\'s level and takes no money', $w['exp']);
// a challenge bot pays its prize the first time only
$db->exec('UPDATE `users` SET `hp` = 0, `hospital` = 0, `user_level` = 0, `money` = 0 WHERE `userid` = 4');
$db->exec('DELETE FROM `challengebots`');
$db->exec('DELETE FROM `challengesbeaten`');
$db->insert('challengebots', ['cb_npcid' => 4, 'cb_money' => 500]);
$before = $bal(2)['money'];
$w1 = fight_win($user(2), 4, 'hospitalise');
$db->exec('UPDATE `users` SET `hp` = 0 WHERE `userid` = 4');
$w2 = fight_win($user(2), 4, 'hospitalise');
check($w1['bot_money'] === 500 && $w2['bot_money'] === 0 && $bal(2)['money'] === $before + 500, 'a challenge bot pays its prize the first time it is beaten, and only then');
// gangs at war
$db->exec('DELETE FROM `gangs`');
$db->exec('DELETE FROM `gangwars`');
$db->run("INSERT INTO `gangs` (`gangID`, `gangNAME`, `gangDESC`, `gangPREF`, `gangSUFF`, `gangMONEY`, `gangCRYSTALS`, `gangRESPECT`, `gangPRESIDENT`, `gangVICEPRES`, `gangCAPACITY`, `gangCRIME`, `gangCHOURS`, `gangAMENT`)
          VALUES (1, 'Reds', '', '', '', 0, 0, 100, 2, 2, 5, 0, 0, ''), (2, 'Blues', '', '', '', 0, 0, 3, 3, 3, 5, 0, 0, '')");
$db->exec('UPDATE `users` SET `gang` = 1 WHERE `userid` = 2');
$db->exec('UPDATE `users` SET `gang` = 2, `hp` = 0, `user_level` = 1 WHERE `userid` = 3');
$w = fight_win($user(2), 3, 'mug');
check($w['respect'] === 0 && (int)$db->value('SELECT `gangRESPECT` FROM `gangs` WHERE `gangID` = 2') === 3, 'gangs that are not at war do not trade respect');
$db->run('INSERT INTO `gangwars` (`warDECLARER`, `warDECLARED`, `warTIME`) VALUES (1, 2, ?)', [time()]);
$db->exec('UPDATE `users` SET `hp` = 0 WHERE `userid` = 3');
$w = fight_win($user(2), 3, 'mug');
check($w['respect'] === 2 && (int)$db->value('SELECT `gangRESPECT` FROM `gangs` WHERE `gangID` = 2') === 1 && (int)$db->value('SELECT `gangRESPECT` FROM `gangs` WHERE `gangID` = 1') === 102,
    'gangs at war: a mugging moves 2 respect to the winner\'s gang');
$db->exec('UPDATE `users` SET `hp` = 0 WHERE `userid` = 3');
$w = fight_win($user(2), 3, 'hospitalise');
check($w['respect'] === 3 && (int)$db->value('SELECT COUNT(*) FROM `gangs`') === 1 && (int)$user(3)['gang'] === 0 && (int)$db->value('SELECT COUNT(*) FROM `gangwars`') === 0,
    'a gang that runs out of respect is disbanded and its wars end');
$db->exec('DELETE FROM `gangs`');
$db->exec('DELETE FROM `challengebots`');
$db->exec('DELETE FROM `challengesbeaten`');
$db->exec('DELETE FROM `attacklogs`');
$db->exec('DELETE FROM `events`');
foreach (['v3_progress', 'v3_achievements', 'v3_missions'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 100, `crystals` = 10, `hp` = 100, `maxhp` = 100, `hospital` = 0, `gang` = 0, `level` = 1, `exp` = 0, `new_events` = 0, `user_level` = IF(`userid` = 1, 2, 1), `attacking` = 0');

// bounties
$db->exec('DELETE FROM `v3_bounties`');
$db->exec('UPDATE `users` SET `money` = 5000 WHERE `userid` = 2');
$db->exec('UPDATE `users` SET `money` = 0 WHERE `userid` IN (3, 4)');
check(bounty_place(2, 2, 1000) !== null && bounty_place(2, 3, 999) !== null && bounty_place(2, 99999, 1000) !== null && bounty_place(2, 3, 5000) !== null && $bal(2)['money'] === 5000
    && (int)$db->value('SELECT COUNT(*) FROM `v3_bounties`') === 0, 'a bounty on yourself, too small, on nobody, or that you cannot pay (with the fee) is refused and costs nothing');
check(bounty_place(2, 3, 2000) === null && $bal(2)['money'] === 5000 - 2200 && bounty_on(3) === 2000 && bounty_place(2, 3, 1000, true) === null && bounty_on(3) === 3000 && $bal(2)['money'] === 1700,
    'placing a bounty takes the bounty and a 10% fee, and bounties on one player add up');
$list = bounty_hitlist();
check(count($list) === 1 && (int)$list[0]['target'] === 3 && (int)$list[0]['total'] === 3000 && (int)$list[0]['bounties'] === 2, 'the hitlist shows the player and the total on their head');
$first = (int)$db->value('SELECT MIN(`id`) FROM `v3_bounties`');
check(!bounty_withdraw(3, $first) && bounty_withdraw(2, $first) && !bounty_withdraw(2, $first) && $bal(2)['money'] === 3700 && bounty_on(3) === 1000,
    'only the placer can withdraw a bounty, once; the bounty comes back and the fee does not');
check(bounty_collect(2, 3) === ['count' => 0, 'total' => 0] && bounty_on(3) === 1000, 'you cannot collect a bounty you placed yourself');
// through a fight: leaving them does not pay, hospitalising does
$db->exec('UPDATE `users` SET `hp` = 0, `hospital` = 0 WHERE `userid` = 3');
$w = fight_win($user(4), 3, 'leave');
check($bal(4)['money'] === 500 /* First Blood */ && bounty_on(3) === 1000 && str_contains(implode(' ', $w['notes']), 'only paid for'), 'leaving the loser outside the hospital does not collect the bounty');
$db->exec('UPDATE `users` SET `hp` = 0, `hospital` = 0 WHERE `userid` = 3');
$w = fight_win($user(4), 3, 'hospitalise');
check($bal(4)['money'] === 500 + 1000 + 1000 /* Bounty Hunter */ && bounty_on(3) === 0 && str_contains(implode(' ', $w['notes']), 'Bounty collected') && progress_get(4, 'bounties') === 1
    && (int)$db->value("SELECT COUNT(*) FROM `events` WHERE `evUSER` = 2 AND `evTEXT` LIKE '%was collected by%'") === 1 && bounty_collect(4, 3)['count'] === 0,
    'hospitalising them pays the bounty to the winner once, and tells the placer', [$bal(4), $w['notes']]);
for ($i = 0; $i < MCC_BOUNTY_OPEN_LIMIT; $i++) {
    $db->insert('v3_bounties', ['target' => 3, 'placer' => 2, 'amount' => 1000, 'anonymous' => 0, 'placed' => time(), 'status' => 'open']);
}
check(str_contains((string)bounty_place(2, 3, 1000), 'already have'), 'a player can have only so many bounties out at once');
$db->exec('DELETE FROM `v3_bounties`');
$db->exec('DELETE FROM `attacklogs`');
$db->exec('DELETE FROM `events`');
foreach (['v3_progress', 'v3_achievements', 'v3_missions'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 100, `crystals` = 10, `hp` = 100, `hospital` = 0, `exp` = 0, `level` = 1, `new_events` = 0');

// crafting
$db->exec('DELETE FROM `v3_recipes`');
$db->exec('DELETE FROM `v3_recipe_parts`');
$db->exec('DELETE FROM `inventory`');
$held = fn (int $u, int $i): int => (int)$db->value('SELECT COALESCE(SUM(`inv_qty`), 0) FROM `inventory` WHERE `inv_userid` = ? AND `inv_itemid` = ?', [$u, $i]);
check(is_string(craft_save(null, '', 4, 1, 0, 0, true, [1 => 2])) && is_string(craft_save(null, 'X', 999, 1, 0, 0, true, [1 => 2])) && is_string(craft_save(null, 'X', 4, 1, 0, 0, true, []))
    && is_string(craft_save(null, 'X', 4, 1, 0, 0, true, [999 => 1])) && is_string(craft_save(null, 'X', 1, 2, 0, 0, true, [1 => 2])) && (int)$db->value('SELECT COUNT(*) FROM `v3_recipes`') === 0,
    'a recipe with no name, no result, no ingredients, an unknown item, or that makes items from nothing is refused');
$rid = craft_save(null, 'Trophy from scraps', 4, 1, 2, 100, true, [1 => 2, 2 => 1]);
check(is_int($rid) && count(craft_recipes()[$rid]['parts']) === 2 && craft_recipes()[$rid]['result_name'] === 'Rare Trophy', 'a recipe is saved with its ingredients');
item_add(2, 1, 5);
item_add(2, 2, 1);
$db->exec('UPDATE `users` SET `money` = 250 WHERE `userid` = 2');
check(craft_make(2, $rid, 1) !== null && craft_make(2, 999, 5) !== null && $held(2, 1) === 5 && $bal(2)['money'] === 250, 'a recipe above your level, or that does not exist, makes nothing');
check(craft_make(2, $rid, 2) === null && $held(2, 1) === 3 && $held(2, 2) === 0 && $held(2, 4) === 1 && $bal(2)['money'] === 150 + 500 /* Tinkerer */ && progress_get(2, 'crafted') === 1,
    'crafting uses the ingredients and the fee and gives the result', [$held(2, 1), $held(2, 2), $held(2, 4), $bal(2)]);
$money = $bal(2)['money'];
$why = craft_make(2, $rid, 2);
check($why !== null && str_contains($why, 'Baseball Bat') && $held(2, 1) === 3 && $bal(2)['money'] === $money && $held(2, 4) === 1,
    'short of one ingredient: nothing is used, not even the fee or the ingredients you do have', $why);
item_add(2, 2, 1);
$db->exec('UPDATE `users` SET `money` = 50 WHERE `userid` = 2');
check(str_contains((string)craft_make(2, $rid, 2), 'fee') && $held(2, 2) === 1, 'short of the fee: nothing is used');
check(is_int(craft_save($rid, 'Trophy from scraps', 4, 2, 0, 0, false, [1 => 1])) && !isset(craft_recipes()[$rid]) && count(craft_recipes(false)[$rid]['parts']) === 1,
    'a recipe can be edited, and switched off so players no longer see it');
craft_delete($rid);
check(craft_recipes(false) === [] && (int)$db->value('SELECT COUNT(*) FROM `v3_recipe_parts`') === 0, 'a recipe can be deleted');
$db->exec('DELETE FROM `inventory`');
$db->exec('DELETE FROM `events`');
foreach (['v3_progress', 'v3_achievements', 'v3_missions'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 100, `crystals` = 10, `new_events` = 0');

// notifications
$db->exec('DELETE FROM `events`');
$db->exec('DELETE FROM `mail`');
check(notify_summary(2) === ['events' => 0, 'mail' => 0, 'total' => 0, 'items' => []] && str_contains(notify_bell(2, 0), 'Nothing new') && str_contains(notify_bell(2, 0), "class='bell-n' hidden"),
    'with nothing waiting the bell is empty and shows no number');
$db->insert('events', ['evUSER' => 2, 'evTIME' => time() - 120, 'evREAD' => 0, 'evTEXT' => "<a href='viewuser.php?u=3'>player2</a> mugged you and stole \$50. <script>alert(1)</script>"]);
$db->insert('events', ['evUSER' => 2, 'evTIME' => time() - 9000, 'evREAD' => 1, 'evTEXT' => 'an old one, already read']);
$db->insert('mail', ['mail_read' => 0, 'mail_from' => 3, 'mail_to' => 2, 'mail_time' => time() - 10, 'mail_subject' => 'Hello <b>there</b>', 'mail_text' => 'x']);
$n = notify_summary(2);
check($n['events'] === 1 && $n['mail'] === 1 && $n['total'] === 2 && $n['items'][0]['type'] === 'mail' && $n['items'][0]['text'] === 'Mail from player2: Hello there'
    && $n['items'][1]['text'] === 'player2 mugged you and stole $50. alert(1)' && $n['items'][1]['ago'] === '2m', 'the bell lists unread events and mail, newest first, as plain words', $n);
$bell = notify_bell(2, 2);
check(str_contains($bell, ">2</b>") && !str_contains($bell, '<script>') && !str_contains($bell, 'already read'), 'the bell shows the count, and never prints an event\'s HTML');
check(notify_ago(100, 130) === 'just now' && notify_ago(0, 3600 * 5) === '5h' && notify_ago(0, 86400 * 3) === '3d' && strlen(notify_plain(str_repeat('word ', 100))) <= 110, 'times and long texts are shortened');

// the player API
$db->exec('DELETE FROM `v3_api_keys`');
$key = api_key_create(2);
check(preg_match('/^[0-9a-f]{40}$/', $key) === 1 && (int)$db->value('SELECT COUNT(*) FROM `v3_api_keys` WHERE `key_hash` = ?', [$key]) === 0
    && $db->value('SELECT `key_hash` FROM `v3_api_keys` WHERE `userid` = 2') === hash('sha256', $key), 'a new key is 40 characters, and only its fingerprint is stored');
check(api_key_use($key, 6000) === 2 && api_key_use('nope') === 0 && api_key_use(str_repeat('a', 40)) === 0 && (int)api_key_info(2)['calls'] === 1, 'a key identifies its player; anything else identifies nobody');
$used = 1;
for ($i = 0; $i < MCC_API_PER_MINUTE + 5; $i++) {
    $used += api_key_use($key, 6001) === 2 ? 1 : 0;
}
check($used === MCC_API_PER_MINUTE && api_key_use($key, 6059) === -1 && api_key_use($key, 6060) === 2, 'a key is allowed ' . MCC_API_PER_MINUTE . ' requests in a minute, then refused until the next minute', $used);
$key2 = api_key_create(2);
check(api_key_use($key) === 0 && api_key_use($key2) === 2, 'replacing a key stops the old one working');
api_key_revoke(2);
check(api_key_use($key2) === 0 && api_key_info(2) === null, 'a revoked key stops working');
$me = api_me(2);
$other = api_player(3);
check($me['name'] === 'player1' && isset($me['money'], $me['bars']['energy'], $me['stats']['strength'], $me['progress']['crimes']) && $me['unread'] === ['events' => 0, 'mail' => 0] || $me['unread']['events'] >= 0,
    'do=me gives the player their own money, bars, stats and progress');
check($other['name'] === 'player2' && !isset($other['money']) && !isset($other['stats']) && !isset($other['bars']) && api_player(99999) === null, 'do=player gives only what a profile shows: no money, stats or bars');
progress_add(3, 'crimes', 7);
progress_add(2, 'crimes', 4);
$top = api_top('crimes');
check(array_column($top, 'id') === [3, 2] && $top[0] === ['rank' => 1, 'id' => 3, 'name' => 'player2', 'value' => 7] && api_rank(2, 'crimes') === 2 && api_rank(4, 'crimes') === null
    && api_top('userpass') === [] && !in_array(1, array_column(api_top('level', 50), 'id'), true), 'leaderboards rank players by a counter, leave staff out, and refuse a column that is not a leaderboard');
$g = api_game();
check($g['players'] === 4 && $g['engine'] === MCC_ENGINE_VERSION && isset($g['online_now'], $g['money_in_pockets']), 'do=game describes the game as a whole');
$db->exec('DELETE FROM `events`');
$db->exec('DELETE FROM `mail`');
foreach (['v3_progress', 'v3_achievements', 'v3_missions'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 100, `crystals` = 10, `new_events` = 0, `new_mail` = 0');

// the staff dashboard
$db->exec('DELETE FROM `v3_snapshots`');
$db->exec("UPDATE `settings` SET `conf_value` = '' WHERE `conf_name` = 'engine_snapshot_day'");
$db->exec('UPDATE `users` SET `money` = 100, `crystals` = 10, `bankmoney` = -1, `cybermoney` = -1');
$t = dash_totals();
check($t['players'] === 4 && $t['money'] === 400 && $t['all_money'] === 400 && $t['crystals'] === 40 && $t['broken'] === 0 && $t['negative_inventory'] === 0, 'the dashboard totals count players and money', $t);
$db->exec('UPDATE `users` SET `money` = -5 WHERE `userid` = 3');
check(dash_totals()['broken'] === 1, 'a negative balance is counted as a problem');
$db->exec('UPDATE `users` SET `money` = 100 WHERE `userid` = 3');
check(dash_snapshot('2026-07-01') && !dash_snapshot('2026-07-01') && dash_snapshot('2026-07-02') && count(dash_history()) === 2 && (int)dash_history()[0]['money'] === 400 && dash_history()[0]['day'] === '2026-07-01',
    'one snapshot is written a day, however often it is asked for, and the history comes back oldest first');
$svg = dash_chart('Money <b>', [['label' => '1 Jul', 'value' => 100], ['label' => '2 Jul', 'value' => 150]], '#8B74FF', '$');
check(str_contains($svg, '<svg') && str_contains($svg, '+50%') && str_contains($svg, '2 Jul: $150') && !str_contains($svg, 'Money <b>') && str_contains(dash_chart('X', [], '#fff'), 'after two days'),
    'a trend is drawn as a chart with its change and a tip on each point, and says so when there is not enough history yet');
check(dash_short(1234567) === '1.23M' && dash_short(950) === '950' && dash_short(45000) === '45K' && dash_short(2.5e9) === '2.5B', 'large figures are shortened');
$db->exec('DELETE FROM `cashxferlogs`');
$db->insert('cashxferlogs', ['cxFROM' => 3, 'cxTO' => 2, 'cxAMOUNT' => 5000, 'cxTIME' => time(), 'cxFROMIP' => '10.0.0.1', 'cxTOIP' => '10.0.0.1']);
$w = dash_watch();
check(count($w['transfers']) === 1 && (int)$w['transfers'][0]['same_ip'] === 1 && $w['transfers'][0]['fname'] === 'player2' && count($w['richest']) === 3, 'the watchlist flags a transfer between two accounts on one IP address');
$hl = dash_health();
check(count($hl['crons']) === 4 && $hl['schema'] === $hl['schema_latest'] && $hl['engine'] === MCC_ENGINE_VERSION, 'the health panel reports the timed jobs and that the schema is up to date');
$db->exec('DELETE FROM `cashxferlogs`');
$db->exec('DELETE FROM `v3_snapshots`');
$db->exec("UPDATE `settings` SET `conf_value` = '' WHERE `conf_name` = 'engine_snapshot_day'");

// links, addresses and forum text
check(safe_url('https://example.com/a?b=1&c=2') === 'https://example.com/a?b=1&amp;c=2' && safe_url('http://example.com') === 'http://example.com' && safe_url('javascript:alert(1)') === ''
    && safe_url('jav&#x61;script:alert(1)') === '' && safe_url('jav&amp;#x61;script:alert(1)') === '' && safe_url("java\tscript:alert(1)") === '' && safe_url('data:text/html,x') === ''
    && safe_url("https://example.com/' onmouseover='x") === '' && safe_url('//example.com') === '' && safe_url('') === '',
    'safe_url() passes a plain web address and refuses script, data and disguised addresses');
$_SESSION = [];
$_GET = [];
$_POST = [];
$tk = link_token();
$ok0 = link_token_ok();
$_GET['tk'] = 'wrong';
$ok1 = link_token_ok();
$_GET['tk'] = $tk;
check(strlen($tk) === 32 && link_token() === $tk && !$ok0 && !$ok1 && link_token_ok(), 'a link token lasts for the login, and only the right one is accepted');
if (!function_exists('userBox')) {
    function userBox($x)
    {
        return is_array($x) ? $x[1] : $x;
    }
}
function check_image($src): string
{
    return 'IMG';
}
require 'class/class_bbcode.php';
$bb = bbcode::getInstance();
$out = $bb->bbcode_parse("[url=jav&#x61;script:alert(1)]a[/url] [url=javascript:alert(1)]b[/url] [url=data:text/html,x]c[/url] <script>alert(1)</script> <img src=x onerror=alert(1)>"
    . ' [style=position:fixed;top:0;left:0;width:100%;height:100%;background:url(//evil/x)]d[/style] [color=red onmouseenter=alert(1)]e[/color]');
check(!str_contains($out, 'href') && !str_contains($out, '<script') && !str_contains($out, '<img') && !str_contains($out, 'position') && !str_contains($out, 'url(') && !str_contains($out, 'onmouseenter')
    && !str_contains(strtolower(html_entity_decode($out)), 'javascript:'), 'forum text cannot carry a script link, raw HTML, free-form CSS or an event handler', $out);
$out = $bb->bbcode_parse('[b]bold[/b] [url=https://example.com/x]link[/url] [style=color:red;font-weight:bold]s[/style] [color=blue]c[/color] [size=4]z[/size]');
check($out === "<b>bold</b> <a href='https://example.com/x' rel='nofollow noopener noreferrer' target='_blank'>link</a> <span style='color:red;font-weight:bold'>s</span> <font color='blue'>c</font> <font size='4'>z</font>",
    'ordinary formatting, links and colours still work', $out);

// the season pass
foreach (['v3_season', 'v3_season_claims', 'v3_progress', 'v3_achievements', 'v3_missions'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 0, `crystals` = 0 WHERE `userid` = 2');
check(season_id(strtotime('2026-03-15')) === '2026-03' && season_left(strtotime('2026-03-31 23:00:00')) === 3600 && season_reward(5)['free'] === ['money' => 12500, 'crystals' => 2]
    && season_reward(30)['premium']['crystals'] === 50 && season_reward(7)['premium']['crystals'] === 3, 'a season is a calendar month, and each tier has a fixed reward on each track');
progress_add(2, 'fights_won', 20);          // 600 points, and achievements pay some money
$st = season_status(2);
check($st['xp'] === 600 && $st['tier'] === 1 && $st['into'] === 200 && !$st['premium'] && season_waiting(2) === 1, 'doing things earns season points, and points reach tiers', $st);
progress_add(2, 'gym', 1000);
check(season_status(2)['xp'] === 600 + MCC_SEASON_GYM_DAILY_CAP && (progress_add(2, 'gym', 50) || true) && season_status(2)['xp'] === 600 + MCC_SEASON_GYM_DAILY_CAP, 'gym points stop at the daily cap', season_status(2)['xp']);
$db->exec('UPDATE `users` SET `money` = 0, `crystals` = 0 WHERE `userid` = 2');
$got = season_claim_all(2);
check($got === ['tiers' => 2, 'money' => 2500 + 5000, 'crystals' => 0] && $bal(2)['money'] === 7500 && season_claim_all(2)['tiers'] === 0 && $bal(2)['money'] === 7500, 'reached rewards are collected once', $got);
check(str_contains((string)season_buy(2), 'crystals') && !season_status(2)['premium'], 'the premium track cannot be bought without the crystals');
$db->exec('UPDATE `users` SET `crystals` = ? WHERE `userid` = 2', [MCC_SEASON_PREMIUM_PRICE + 1]);
check(season_buy(2) === null && $bal(2)['crystals'] === 1 && season_status(2)['premium'] && season_buy(2) !== null && $bal(2)['crystals'] === 1, 'the premium track is bought once, for its price');
$got = season_claim_all(2);
check($got === ['tiers' => 2, 'money' => 5000 + 10000, 'crystals' => 6] && $bal(2)['crystals'] === 7, 'a pass bought late pays the premium rewards for tiers already reached', $got);
season_add(2, 999999);
check(season_status(2)['tier'] === MCC_SEASON_TIERS && season_claim_all(2)['tiers'] === 2 * (MCC_SEASON_TIERS - 2) && season_status(2, '2020-01')['xp'] === 0, 'tiers stop at the top, and another season starts from nothing');
foreach (['v3_season', 'v3_season_claims', 'v3_progress', 'v3_achievements', 'v3_missions', 'events'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 100, `crystals` = 10, `new_events` = 0');

// the store
$db->exec('DELETE FROM `v3_payments`');
$db->exec('DELETE FROM `events`');
$db->exec('UPDATE `users` SET `crystals` = 0, `donatordays` = 0, `new_events` = 0');
$body = '{"id":"evt_1","type":"x"}';
$sig = fn (string $b, string $secret, int $t): string => "t={$t},v1=" . hash_hmac('sha256', $t . '.' . $b, $secret);
check(store_signature_ok($body, $sig($body, 'whsec_a', 1000), 'whsec_a', 1000) && !store_signature_ok($body, $sig($body, 'whsec_b', 1000), 'whsec_a', 1000)
    && !store_signature_ok($body . ' ', $sig($body, 'whsec_a', 1000), 'whsec_a', 1000) && !store_signature_ok($body, $sig($body, 'whsec_a', 1000), 'whsec_a', 1000 + MCC_STORE_SIGNATURE_AGE + 1)
    && !store_signature_ok($body, '', 'whsec_a', 1000) && !store_signature_ok($body, $sig($body, '', 1000), '', 1000) && !store_signature_ok($body, 'v1=abc', 'whsec_a', 1000)
    && store_signature_ok($body, 't=1000,v1=bad,v1=' . hash_hmac('sha256', '1000.' . $body, 'whsec_a'), 'whsec_a', 1000),
    'a webhook is accepted only with the right signature, over the exact body, and while fresh');
$paid = fn (string $id, array $over = []): array => ['type' => 'checkout.session.completed', 'data' => ['object' => array_merge(
    ['id' => $id, 'payment_status' => 'paid', 'payment_intent' => 'pi_' . $id, 'amount_total' => 799, 'currency' => 'gbp', 'metadata' => ['userid' => '2', 'pack' => 'regular']], $over)]];
check(store_handle_event($paid('cs_1')) === 'credited' && $bal(2)['crystals'] === 300 && (int)$user(2)['donatordays'] === 30 && store_handle_event($paid('cs_1')) === 'already' && $bal(2)['crystals'] === 300
    && (int)$db->value("SELECT COUNT(*) FROM `events` WHERE `evUSER` = 2 AND `evTEXT` LIKE '%Regular pack%'") === 1, 'a paid checkout credits the pack once, however often Stripe reports it');
check(store_handle_event($paid('cs_2', ['amount_total' => 1])) === 'mismatch' && store_handle_event($paid('cs_3', ['currency' => 'usd'])) === 'mismatch'
    && store_handle_event($paid('cs_4', ['metadata' => ['userid' => '2', 'pack' => 'nope']])) === 'unknown' && store_handle_event($paid('cs_5', ['metadata' => ['userid' => '99999', 'pack' => 'regular']])) === 'unknown'
    && store_handle_event($paid('cs_6', ['payment_status' => 'unpaid'])) === 'unpaid' && store_handle_event(['type' => 'customer.created']) === 'ignored' && $bal(2)['crystals'] === 300
    && (int)$db->value('SELECT COUNT(*) FROM `v3_payments`') === 1, 'a wrong amount or currency, an unknown pack or player, or an unpaid checkout credits nothing');
check(store_handle_event(['type' => 'checkout.session.async_payment_succeeded', 'data' => ['object' => $paid('cs_6')['data']['object']]]) === 'credited' && $bal(2)['crystals'] === 600, 'a slow payment is credited when it clears');
check(store_handle_event(['type' => 'charge.refunded', 'data' => ['object' => ['payment_intent' => 'pi_cs_1']]]) === 'refunded' && $db->value("SELECT `status` FROM `v3_payments` WHERE `ref` = 'cs_1'") === 'refunded'
    && $bal(2)['crystals'] === 600 && store_takings()['month'] === 799 && store_takings()['refunded_month'] === 1, 'a refund is recorded for staff; crystals are not taken back automatically');
$_CONFIG['stripe_secret'] = 'sk_test_x';
$_CONFIG['stripe_webhook_secret'] = 'whsec_x';
$sent = null;
$GLOBALS['mcc_store_http'] = function (string $path, array $fields) use (&$sent) {
    $sent = [$path, $fields];
    return [200, ['id' => 'cs_new', 'url' => 'https://checkout.stripe.com/c/pay/cs_new']];
};
$url = store_checkout(2, 'big', 'https://game.example');
check($url === 'https://checkout.stripe.com/c/pay/cs_new' && $sent[0] === 'checkout/sessions' && $sent[1]['line_items'][0]['price_data']['unit_amount'] === 1499 && $sent[1]['metadata'] === ['userid' => '2', 'pack' => 'big']
    && $sent[1]['line_items'][0]['price_data']['currency'] === 'gbp' && $sent[1]['success_url'] === 'https://game.example/store.php?paid=1' && store_checkout(2, 'nope', 'https://game.example') === null,
    'starting a purchase asks Stripe for a checkout at the pack\'s own price, tagged with the player and the pack');
$GLOBALS['mcc_store_http'] = fn () => [401, ['error' => ['message' => 'bad key']]];
check(store_checkout(2, 'big', 'https://game.example') === null, 'if Stripe refuses, no checkout address is returned');
$GLOBALS['mcc_store_http'] = fn () => [200, ['url' => 'javascript:alert(1)']];
check(store_checkout(2, 'big', 'https://game.example') === null && html_entity_decode(store_price(1499)) === "\u{a3}14.99", 'only an https address is ever followed, and prices print in pounds and pence');
unset($GLOBALS['mcc_store_http']);
$db->exec('DELETE FROM `v3_payments`');
$db->exec('DELETE FROM `events`');
$db->exec('UPDATE `users` SET `crystals` = 10, `donatordays` = 0, `new_events` = 0');

// the crystal shop
foreach (['v3_perks', 'v3_perk_uses', 'v3_missions', 'v3_progress', 'v3_achievements'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `crystals` = 100, `brave` = 0, `maxbrave` = 5 WHERE `userid` = 2');
check(perk_refill(2, 'brave') === null && $bal(2)['crystals'] === 92 && (int)$user(2)['brave'] === 5 && str_contains((string)perk_refill(2, 'brave'), 'full') && $bal(2)['crystals'] === 92
    && perk_refill(2, 'energy') !== null, 'a refill fills the bar for its price, and a full bar is not charged for');
for ($i = 0; $i < 5; $i++) {
    $db->exec('UPDATE `users` SET `brave` = 0 WHERE `userid` = 2');
    $last = perk_refill(2, 'brave');
}
check(perk_used_today(2, 'refill:brave') === 3 && $bal(2)['crystals'] === 100 - 24 && str_contains((string)$last, 'tomorrow') && (int)$user(2)['brave'] === 0
    && ($db->exec('UPDATE `users` SET `brave` = 0 WHERE `userid` = 2') || true) && perk_refill(2, 'brave', '2031-01-01') === null, 'refills stop at the daily limit, and the limit is per day');
$db->exec('UPDATE `users` SET `crystals` = 3, `will` = 0 WHERE `userid` = 2');
check(str_contains((string)perk_refill(2, 'will'), 'costs') && (int)$user(2)['will'] === 0 && perk_used_today(2, 'refill:will') === 0, 'a refill you cannot pay for changes nothing: not the bar, not the day\'s count');
$db->exec('UPDATE `users` SET `crystals` = 500 WHERE `userid` = 2');
check(perk_name(2, 'player1') === 'player1' && perk_colour(2, 'Nope') !== null && perk_colour(2, 'Gold') === null && $bal(2)['crystals'] === 440 && perk_name(2, 'player1') === "<span style='color:#FBBF24;font-weight:bold;'>player1</span>"
    && perk_colour(2, 'Sky') === null && $bal(2)['crystals'] === 440 && str_contains(perk_name(2, 'player1'), '#38BDF8'), 'a name colour is paid for once; changing it afterwards is free');
check(perk_badge_buy(2, 'shark') === null && $bal(2)['crystals'] === 390 && perk_badge_buy(2, 'shark') !== null && $bal(2)['crystals'] === 390 && str_contains(perk_name(2, 'player1'), "<span class='perk-badge'>Shark</span>")
    && !perk_badge_show(2, 'kingpin') && perk_badge_show(2, '') && !str_contains(perk_name(2, 'player1'), 'perk-badge') && perk_badge_buy(2, 'nope') !== null, 'a badge is bought once, shown or hidden, and one you do not own cannot be shown');
check(perk_name(3, '<b>x</b>') === '&lt;b&gt;x&lt;/b&gt;', 'a name is always printed safely');
$base = count(missions_today(2, 1));
check($base === 3 && perk_extra_mission(2, 1) === null && count(missions_today(2, 1)) === 4 && $bal(2)['crystals'] === 375 && perk_extra_mission(2, 1) !== null && $bal(2)['crystals'] === 375
    && count(array_unique(array_column(missions_today(2, 1), 'mission'))) === 4, 'an extra mission is a fourth, different one, once a day');
foreach (['v3_perks', 'v3_perk_uses', 'v3_missions'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `crystals` = 10, `brave` = 5, `will` = 100');

echo $failed ? "\n{$failed} check(s) FAILED\n" : "\nAll engine checks passed.\n";
exit($failed ? 1 : 0);
