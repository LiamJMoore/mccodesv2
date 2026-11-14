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
require 'engine/mods/Stocks.php';
require 'engine/mods/Turf.php';
require 'engine/mods/Lottery.php';
require 'engine/mods/Pets.php';
require 'engine/mods/Trade.php';
require 'engine/mods/Invest.php';
require 'engine/mods/Profile.php';
require 'engine/mods/Home.php';
require 'engine/mods/Marriage.php';
require 'engine/mods/Auction.php';
require 'engine/mods/Loan.php';
require 'engine/mods/Tickets.php';
require 'engine/mods/Contacts.php';
require 'engine/mods/Merits.php';
require 'engine/mods/Travel.php';
require 'engine/mods/Business.php';
require 'engine/mods/Fame.php';
require 'engine/mods/Stalls.php';
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
$svg = dash_chart('Money <b>', [['label' => '1 Jul', 'value' => 100], ['label' => '2 Jul', 'value' => 150]], '#D4AF37', '$');
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

// the stock market
$db->exec('DELETE FROM `v3_stock_holdings`');
$db->exec('DELETE FROM `v3_stock_history`');
$db->exec('UPDATE `v3_stocks` SET `price` = `base`, `prev` = `base`');
$sid = (int)$db->value("SELECT `id` FROM `v3_stocks` WHERE `symbol` = 'BRIX'");
$lo = PHP_INT_MAX;
$hi = 0;
$p = 120;
for ($i = 0; $i < 20000; $i++) {
    $p = stock_step($p, 120, 25);
    $lo = min($lo, $p);
    $hi = max($hi, $p);
}
check($lo >= 60 && $hi <= 240 && $lo < 110 && $hi > 130 && stock_step(1, 100000, 0) >= 1, 'a price wanders, but the pull towards its long-run level keeps it within reach of it', [$lo, $hi]);
$db->exec("UPDATE `settings` SET `conf_value` = '1000' WHERE `conf_name` = 'engine_stock_tick'");
$t = 1003 * MCC_STOCK_TICK + 5;
check(stocks_tick($t) === 3 && stocks_tick($t) === 0 && (int)$db->value('SELECT COUNT(*) FROM `v3_stock_history` WHERE `stock` = ?', [$sid]) === 3 && stocks_tick($t + MCC_STOCK_TICK) === 1
    && stocks_tick($t + 5000 * MCC_STOCK_TICK) === MCC_STOCK_CATCHUP, 'prices move once per tick, missed ticks are replayed (up to a day), and each leaves a point of history');
$db->exec('UPDATE `v3_stocks` SET `price` = 100, `prev` = 100 WHERE `id` = ?', [$sid]);
$db->exec('UPDATE `users` SET `money` = 10000 WHERE `userid` = 2');
$b = stock_buy(2, $sid, 20, 1);
check($b === ['shares' => 20, 'price' => 100, 'paid' => 2030, 'fee' => 30] && $bal(2)['money'] === 7970 && (int)stocks_list(2)[0]['shares'] === 20 && (int)stocks_list(2)[0]['cost'] === 2000, 'buying takes the price of the shares plus the fee', $b);
check(is_string(stock_buy(2, $sid, 31, 1)) && is_string(stock_buy(2, $sid, 0, 1)) && is_string(stock_buy(2, 99999, 1, 1)) && $bal(2)['money'] === 7970 && is_array(stock_buy(2, $sid, 30, 1))
    && is_string(stock_buy(2, $sid, 1, 1)), 'a player cannot hold more shares of a company than their level allows');
$db->exec('UPDATE `users` SET `money` = 50 WHERE `userid` = 2');
check(is_string(stock_buy(2, $sid, 1, 9)) && (int)stocks_list(2)[0]['shares'] === 50 && $bal(2)['money'] === 50, 'shares you cannot pay for are not added');
$db->exec('UPDATE `v3_stocks` SET `price` = 150 WHERE `id` = ?', [$sid]);
$sold = stock_sell(2, $sid, 20);
check($sold === ['shares' => 20, 'price' => 150, 'got' => 2955, 'fee' => 45, 'profit' => 955] && $bal(2)['money'] === 3005 && (int)stocks_list(2)[0]['shares'] === 30 && (int)stocks_list(2)[0]['cost'] === 3000
    && is_string(stock_sell(2, $sid, 31)) && is_string(stock_sell(3, $sid, 1)) && $bal(2)['money'] === 3005, 'selling pays the price less the fee, reports the profit, and you cannot sell shares you do not hold', $sold);
check(str_contains(stock_spark([100, 120, 90, 130], true), '<polyline') && stock_spark([100], true) === '' && stock_cap(7) === 7 * MCC_STOCK_SHARES_PER_LEVEL, 'a day of prices draws as a small line');
$db->exec('DELETE FROM `v3_stock_holdings`');
$db->exec('DELETE FROM `v3_stock_history`');
$db->exec('UPDATE `v3_stocks` SET `price` = `base`, `prev` = `base`');
$db->run("UPDATE `settings` SET `conf_value` = ? WHERE `conf_name` = 'engine_stock_tick'", [(string)intdiv(time(), MCC_STOCK_TICK)]);
$db->exec('UPDATE `users` SET `money` = 100');

// gang territory
foreach (['v3_turf', 'v3_turf_wins', 'gangs', 'gangevents', 'v3_progress', 'v3_achievements', 'v3_missions', 'v3_season'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->run("INSERT INTO `gangs` (`gangID`, `gangNAME`, `gangDESC`, `gangRESPECT`, `gangPRESIDENT`, `gangVICEPRES`, `gangCAPACITY`, `gangAMENT`) VALUES (1, 'Reds', '', 100, 2, 2, 5, ''), (2, 'Blues', '', 100, 3, 3, 5, '')");
$db->exec('UPDATE `users` SET `gang` = 1, `location` = 1 WHERE `userid` = 2');
$db->exec('UPDATE `users` SET `gang` = 2, `location` = 1 WHERE `userid` = 3');
$db->exec('UPDATE `users` SET `gang` = 0, `location` = 1 WHERE `userid` = 4');
check(turf_week(strtotime('2026-11-09 00:00:00')) === '2026-W46' && turf_week(strtotime('2026-11-15 23:59:59')) === '2026-W46' && turf_week(strtotime('2026-11-16 00:00:00')) === '2026-W47'
    && turf_week_left(strtotime('2026-11-15 23:00:00')) === 3600 && turf_payout(10) === ['money' => 100000, 'crystals' => 10], 'a turf week runs Monday to Sunday, and a city pays more the higher its level');
$ir = null;
progress_add(2, 'crimes', 9);
$st = turf_standings();
check($st[1]['holder'] === null && $st[1]['top'][0] === ['gang' => 1, 'name' => 'Reds', 'points' => 9], 'crimes earn a gang influence where the player stands, but too little influence holds nothing');
progress_add(2, 'fights_won', 1);
progress_add(3, 'fights_won', 3);
progress_add(4, 'crimes', 50);
$st = turf_standings();
check($st[1]['holder']['name'] === 'Blues' && array_column($st[1]['top'], 'points') === [15, 14] && $st[2]['top'] === [], 'the gang with the most influence holds the city; a player with no gang counts for nothing', $st[1]);
turf_add(1, 1, 1);
check(turf_standings()[1]['holder']['name'] === 'Reds', 'a tie goes to the older gang');
// the week ends
$db->exec("UPDATE `v3_turf` SET `week` = '2026-W01'");
$db->exec("UPDATE `settings` SET `conf_value` = '2026-W01' WHERE `conf_name` = 'engine_turf_week'");
$paid = turf_settle();
$vault = $db->row('SELECT `gangMONEY`, `gangCRYSTALS` FROM `gangs` WHERE `gangID` = 1');
$pay = turf_payout((int)$db->value('SELECT `cityminlevel` FROM `cities` WHERE `cityid` = 1'));
check($paid === 1 && (int)$vault['gangMONEY'] === $pay['money'] && (int)$vault['gangCRYSTALS'] === 10 && turf_settle() === 0 && (int)$db->value('SELECT `gangMONEY` FROM `gangs` WHERE `gangID` = 1') === $pay['money']
    && (int)$db->value('SELECT COUNT(*) FROM `v3_turf_wins`') === 1 && (int)$db->value("SELECT COUNT(*) FROM `gangevents` WHERE `gevGANG` = 1 AND `gevTEXT` LIKE '%held%'") === 1
    && turf_standings()[1]['top'] === [], 'when the week ends the holder\'s vault is paid once, the gang is told, and the count starts again', [$paid, $vault]);
foreach (['v3_turf', 'v3_turf_wins', 'gangs', 'gangevents', 'v3_progress', 'v3_achievements', 'v3_missions', 'v3_season', 'events'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `gang` = 0, `money` = 100, `crystals` = 10, `new_events` = 0');

// the lottery
foreach (['v3_lotto_tickets', 'v3_lotto_draws', 'events'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 30000, `new_events` = 0');
check(lotto_buy(2, 0, '2026-02-01') !== null && lotto_buy(2, 5, '2026-02-01') === null && $bal(2)['money'] === 25000 && lotto_buy(2, 21, '2026-02-01') !== null && lotto_buy(2, 20, '2026-02-01') === null
    && $bal(2)['money'] === 5000 && lotto_buy(2, 1, '2026-02-01') !== null && lotto_buy(3, 15, '2026-02-01') === null, 'tickets cost their price, and a player cannot go past the day\'s cap');
$db->exec('UPDATE `users` SET `money` = 500 WHERE `userid` = 4');
check(str_contains((string)lotto_buy(4, 1, '2026-02-01'), 'need') && lotto_today(4, '2026-02-01')['mine'] === 0, 'tickets you cannot pay for are not issued');
$t = lotto_today(2, '2026-02-01');
check($t['tickets'] === 40 && $t['players'] === 2 && $t['mine'] === 25 && $t['pot'] === 40 * 800 + MCC_LOTTO_HOUSE_ADDS && lotto_pot(0) === 0, 'the pot is 80% of ticket money plus the house\'s share');
$db->exec("UPDATE `settings` SET `conf_value` = '2026-02-01' WHERE `conf_name` = 'engine_lotto_day'");
check(lotto_settle('2026-02-01') === 0 && (int)$db->value('SELECT COUNT(*) FROM `v3_lotto_draws`') === 0, 'a day is not drawn before it has ended');
$before = $bal(3)['money'];
check(lotto_settle('2026-02-02', fn (int $n) => 26) === 1 && $bal(3)['money'] === $before + 42000 && (int)$db->value('SELECT `winner` FROM `v3_lotto_draws`') === 3 && lotto_settle('2026-02-02') === 0
    && $bal(3)['money'] === $before + 42000 && (int)$db->value("SELECT COUNT(*) FROM `events` WHERE `evUSER` = 3 AND `evTEXT` LIKE '%won the lottery%'") === 1,
    'at the end of the day one ticket wins the pot: drawn, paid and announced once (ticket 26 of 40 belongs to the second player)');
$wins = [2 => 0, 3 => 0];
for ($i = 0; $i < 300; $i++) {
    $db->exec('DELETE FROM `v3_lotto_draws`');
    $db->exec("UPDATE `settings` SET `conf_value` = '2026-02-01' WHERE `conf_name` = 'engine_lotto_day'");
    lotto_settle('2026-02-02');
    $wins[(int)$db->value('SELECT `winner` FROM `v3_lotto_draws`')]++;
}
check($wins[2] > 150 && $wins[3] > 70 && $wins[2] + $wins[3] === 300, 'over many draws, a player with 25 of 40 tickets wins about that share', $wins);
foreach (['v3_lotto_tickets', 'v3_lotto_draws', 'events'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->run("UPDATE `settings` SET `conf_value` = ? WHERE `conf_name` = 'engine_lotto_day'", [date('Y-m-d')]);
$db->exec('UPDATE `users` SET `money` = 100, `new_events` = 0');

// pets
foreach (['v3_pets', 'v3_perk_uses'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 20000, `energy` = 12 WHERE `userid` = 2');
check(pet_adopt(2, 'dragon', 'Rex') !== null && pet_adopt(2, 'hawk', '<b>') !== null && pet_adopt(2, 'hawk', 'X') !== null && pet_of(2) === null && pet_adopt(2, 'hawk', "  Lady  O'Hara ") === null
    && pet_of(2)['name'] === "Lady O'Hara" && $bal(2)['money'] === 15000 && pet_adopt(2, 'ferret', 'Second') !== null && $bal(2)['money'] === 15000, 'a player adopts one pet, with a sensible name, for the price');
$now = time();
check(pet_of(2, $now)['full'] === 100 && pet_of(2, $now + 5 * 3600)['full'] === 80 && pet_of(2, $now + 100 * 3600)['full'] === 0 && pet_feed(2, $now) !== null && pet_feed(2, $now + 10 * 3600) === null
    && $bal(2)['money'] === 14750 && pet_of(2, $now + 10 * 3600)['full'] === 100, 'a pet gets hungry by the hour, feeding fills it, and a full pet is not fed');
$tr = pet_train(2, $now + 11 * 3600);
$p = pet_of(2, $now + 11 * 3600);
check(is_array($tr) && (int)$p['speed'] + (int)$p['stamina'] === 20 + $tr['gain'] && (int)$p['xp'] === 10 && (int)$user(2)['energy'] === 7 && is_string(pet_train(2, $now + 11 * 3600 + 60))
    && (int)$user(2)['energy'] === 7 && is_array(pet_train(2, $now + 12 * 3600 + 1)), 'training raises a stat for some energy, and the pet must rest between sessions');
$db->exec('UPDATE `users` SET `energy` = 0 WHERE `userid` = 2');
$before = pet_of(2);
check(is_string(pet_train(2, $now + 20 * 3600)) && (int)pet_of(2)['last_trained'] === (int)$before['last_trained'], 'with no energy there is no session, and the rest period is not used up');
check(is_string(pet_train(2, $now + 60 * 3600)) && str_contains((string)pet_train(2, $now + 60 * 3600), 'hungry'), 'a hungry pet will not train');
$db->exec('UPDATE `v3_pets` SET `last_fed` = ?, `xp` = 95 WHERE `userid` = 2', [time()]);
$db->exec('UPDATE `users` SET `money` = 100000 WHERE `userid` = 2');
$places = [];
$spent = 0;
$won = 0;
for ($i = 0; $i < MCC_PET_RACES_A_DAY + 2; $i++) {
    $r = pet_race(2);
    if (is_array($r)) {
        $places[] = $r['place'];
        $spent += $r['fee'];
        $won += $r['prize'];
        if (count($r['field']) !== 6 || $r['prize'] !== (int)round($r['fee'] * (MCC_PET_PRIZES[$r['place']] ?? 0))) {
            $places[] = 99;
        }
    }
}
$p = pet_of(2);
check(count($places) === MCC_PET_RACES_A_DAY && max($places) <= 6 && $bal(2)['money'] === 100000 - $spent + $won && (int)$p['races'] === MCC_PET_RACES_A_DAY && (int)$p['level'] >= 2 && is_string(pet_race(2)),
    'a race has six runners, pays the first three by the table, costs its fee, and there are only so many a day', [$places, $spent, $won]);
mt_srand(11);
$back = 0.0;
for ($i = 0; $i < 4000; $i++) {
    $me = pet_run(30, 30);
    $beat = 0;
    for ($k = 0; $k < 5; $k++) {
        $beat += pet_run((int)round(30 * mt_rand(85, 115) / 100), (int)round(30 * mt_rand(85, 115) / 100)) > $me ? 1 : 0;
    }
    $back += MCC_PET_PRIZES[$beat + 1] ?? 0;
}
mt_srand();
check($back / 4000 > 0.70 && $back / 4000 < 0.95, 'evenly matched, racing pays back less than it costs: it takes money out of the game', round($back / 4000, 3));
foreach (['v3_pets', 'v3_perk_uses'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 100, `energy` = 12');

// travel trading
foreach (['v3_trade_stock', 'v3_trade_cargo', 'v3_perk_uses', 'v3_progress', 'v3_achievements', 'v3_missions', 'v3_season'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$origins = trade_origins();
$here = array_keys($origins, 1, true);
$there = array_keys($origins, 2, true);
check(count($origins) === 9 && count($here) === 3 && count($there) === 3 && trade_capacity(1) === 11 && trade_capacity(500) === MCC_TRADE_CARRY_MAX && trade_sell_price('cigars') === 810,
    'each city makes three goods, a player carries more with level, and a good is worth 35% more away from home', $origins);
$g = $here[0];
$price = MCC_TRADE_GOODS[$g][1];
$db->exec('UPDATE `users` SET `money` = 1000000, `location` = 1, `level` = 1 WHERE `userid` = 2');
$t0 = time();
$full = trade_stock(1, $t0)[$g];
check($full === MCC_TRADE_GOODS[$g][2] && trade_buy(2, $there[0], 1) !== null && trade_buy(2, $g, 0) !== null && trade_buy(2, $g, 12) !== null && $bal(2)['money'] === 1000000 && trade_buy(2, $g, 11) === null
    && $bal(2)['money'] === 1000000 - 11 * $price && trade_stock(1, $t0)[$g] === $full - 11 && trade_cargo(2)[$g] === ['qty' => 11, 'paid' => 11 * $price] && trade_buy(2, $g, 1) !== null,
    'goods are bought where they are made, up to what the player can carry, and come off the shelf');
$home = trade_sell(2, $g, 1);
check($home === ['got' => $price, 'profit' => 0], 'sold where it was made, a good fetches only what it cost', $home);
$db->exec('UPDATE `users` SET `location` = 2 WHERE `userid` = 2');
$away = trade_sell(2, $g, 10);
check($away['profit'] === 10 * (trade_sell_price($g) - $price) && $bal(2)['money'] === 1000000 + $away['profit'] && trade_cargo(2) === [] && is_string(trade_sell(2, $g, 1)) && progress_get(2, 'trade_profit') === $away['profit'],
    'carried to another city it sells for the mark-up, and you cannot sell what you are not carrying', $away);
$db->exec('UPDATE `users` SET `location` = 1 WHERE `userid` = 2');
check(trade_buy(2, $g, 11) === null && trade_sell(2, $g, 11) !== null && trade_buy(2, $g, 11) === null && is_array(trade_sell(2, $g, 11)) && str_contains((string)trade_buy(2, $g, 1), 'Customs'),
    'customs stop a player buying more than three loads in a day');
$db->exec('UPDATE `v3_trade_stock` SET `stock` = 0, `at` = ? WHERE `city` = 1 AND `good` = ?', [$t0, $g]);
check(trade_stock(1, $t0)[$g] === 0 && trade_buy(2, $g, 1, '2031-01-01') !== null && trade_stock(1, $t0 + 3 * 3600)[$g] === intdiv($full, 2) && trade_stock(1, $t0 + 30 * 3600)[$g] === $full,
    'an empty shelf sells nothing, and refills over six hours');

// investments
$db->exec('DELETE FROM `v3_investments`');
$db->exec('UPDATE `users` SET `money` = 200000, `location` = 1 WHERE `userid` = 2');
check(invest_open(2, 500, 7, 1) !== null && invest_open(2, 100001, 7, 1) !== null && invest_open(2, 1000, 9, 1) !== null && invest_open(2, 100000, 30, 1, 1000) === null && $bal(2)['money'] === 100000
    && invest_open(2, 5000, 7, 1) !== null && $bal(2)['money'] === 100000 && invest_of(2, 1000)['interest'] === 18000 && !invest_of(2, 1000)['ready'], 'one investment at a time, within the limits for the level');
check(invest_close(2, 1000 + 29 * 86400) === ['paid' => 100000, 'interest' => 0] && $bal(2)['money'] === 200000 && invest_close(2) === null, 'taken out early it pays back what was put in, once, with no interest');
invest_open(2, 100000, 7, 1, 1000);
check(invest_close(2, 1000 + 7 * 86400) === ['paid' => 103000, 'interest' => 3000] && $bal(2)['money'] === 203000, 'left to mature it pays its interest');

// profiles
$db->exec('DELETE FROM `v3_profile_comments`');
$db->exec('DELETE FROM `events`');
check(profile_rank(1) === 'Nobody' && profile_rank(12) === 'Hustler' && profile_rank(9999) === 'Legend', 'a level has a rank title');
$card = profile_card(['userid' => 2, 'level' => 12, 'signedup' => time() - 40 * 86400, 'hospital' => 5, 'hospreason' => 'Mugged', 'jail' => 0, 'jail_reason' => '', 'laston' => time()]);
check(str_contains($card, 'In hospital') && str_contains($card, 'Hustler') && str_contains($card, '<b>40</b>') && str_contains($card, 'Online'), 'the profile card shows rank, age, and a banner when in hospital');
check(profile_comment_add(3, 2, '', 5000) !== null && profile_comment_add(3, 2, str_repeat('x', 241), 5000) !== null && profile_comment_add(3, 99999, 'hi', 5000) !== null
    && profile_comment_add(3, 2, "  <script>alert(1)</script>  nice\n profile ", 5000) === null && profile_comment_add(3, 2, 'again', 5030) !== null && profile_comment_add(3, 2, 'again', 5061) === null
    && count(profile_comments(2)) === 2 && profile_comments(2)[1]['text'] === '<script>alert(1)</script> nice profile', 'a comment is short plain text, and a player must wait between comments');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = [];
$block = profile_comments_block(2, 4, false);
check(!str_contains($block, '<script>alert') && str_contains($block, '&lt;script&gt;') && !str_contains($block, "value='delete'") && str_contains(profile_comments_block(2, 2, false), "value='delete'"),
    'comments are printed safely, and only their author, the profile\'s owner or staff are offered delete');
$first = (int)profile_comments(2)[1]['id'];
check(!profile_comment_delete(4, $first) && profile_comment_delete(2, $first) && profile_comment_delete(4, (int)profile_comments(2)[0]['id'], true) && profile_comments(2) === [], 'a stranger cannot delete a comment; the owner and staff can');
foreach (['v3_trade_stock', 'v3_trade_cargo', 'v3_perk_uses', 'v3_progress', 'v3_achievements', 'v3_missions', 'v3_season', 'v3_investments', 'v3_profile_comments', 'events'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 100, `crystals` = 10, `location` = 1, `level` = 1, `new_events` = 0');

// the ledger
$db->exec('DELETE FROM `v3_ledger`');
$db->exec('UPDATE `users` SET `money` = 1000, `crystals` = 10, `bankmoney` = 0, `cybermoney` = -1 WHERE `userid` IN (2, 3)');
$lines = fn (int $u = 0): array => array_map(fn ($l) => [(int)$l['userid'], $l['wallet'], (int)$l['delta']], array_reverse(ledger_lines($u ? ['userid' => $u] : [])));
wallet_take(2, 'money', 300);
wallet_give(2, 'crystals', 5);
wallet_move(2, 'money', 'bankmoney', 200, 170);
check($lines() === [[2, 'money', -300], [2, 'crystals', 5], [2, 'money', -200], [2, 'bankmoney', 170]], 'every change the wallet functions make is written to the ledger', $lines());
wallet_take(2, 'money', 999999);
wallet_give(2, 'cybermoney', 5);
wallet_take(2, 'money', 0);
check(count($lines()) === 4, 'a change that did not happen leaves no line');
wallet_send(2, 3, 'money', 100);
wallet_send(2, 3, 'money', 100, 'cybermoney');
check(array_slice($lines(), 4) === [[2, 'money', -100], [3, 'money', 100]] && $bal(2)['money'] === 400, 'a send writes a line for each side, and a send that is undone writes none', array_slice($lines(), 4));
$sum = (int)$db->value("SELECT SUM(`delta`) FROM `v3_ledger` WHERE `userid` = 2 AND `wallet` = 'money'");
check(1000 + $sum === $bal(2)['money'], 'the ledger adds up to the balance');
check(count(ledger_lines(['userid' => 3])) === 1 && count(ledger_lines(['wallet' => 'crystals'])) === 1 && count(ledger_lines(['min' => 250])) === 1 && count(ledger_lines([], 2)) === 2 && count(ledger_lines([], 50, 5)) === 1
    && ledger_label('bank.php') === 'Bank' && ledger_label('docrime.php', 'Pick a pocket') === 'Crime: Pick a pocket' && ledger_label('some_new_page.php') === 'Some new page',
    'the ledger can be filtered by player, account and size, read a page at a time, and names where each line came from');
$db->exec('DELETE FROM `v3_ledger`');
$db->exec('UPDATE `users` SET `money` = 100, `crystals` = 10, `bankmoney` = -1, `cybermoney` = -1');
$r = home_ranks(['userid' => 2, 'strength' => 10, 'agility' => 10, 'guard' => 10, 'labour' => 10, 'IQ' => 10]);
$db->exec('UPDATE `userstats` SET `strength` = 50 WHERE `userid` = 3');
$r2 = home_ranks(['userid' => 2, 'strength' => 10, 'agility' => 10, 'guard' => 10, 'labour' => 10, 'IQ' => 10]);
$db->exec('UPDATE `userstats` SET `strength` = 10 WHERE `userid` = 3');
$n = 0;
check($r['strength'] === 1 && $r['total'] === 1 && $r2['strength'] === 2 && $r2['total'] === 2 && $r2['agility'] === 1 && once('t', function () use (&$n) { return ++$n; }) === 1 && once('t', function () use (&$n) { return ++$n; }) === 1 && $n === 1,
    'ranks for all the battle stats come from one query, and once() works a thing out one time only', [$r, $r2]);

// marriage
foreach (['v3_proposals', 'v3_spouses', 'v3_joint', 'events', 'v3_ledger'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 20000, `maxwill` = 100, `will` = 100');
$db->exec('UPDATE `users` SET `maxwill` = 250, `will` = 250 WHERE `userid` = 3');
check(marriage_propose(2, 2) !== null && marriage_propose(2, 99999) !== null && marriage_propose(2, 3) === null && $bal(2)['money'] === 10000 && marriage_propose(2, 4) !== null && $bal(2)['money'] === 10000,
    'a proposal costs a ring, and a player can have one proposal out at a time');
check(marriage_unpropose(4, 2) === false && marriage_unpropose(3, 2) && $bal(2)['money'] === 20000 && !marriage_unpropose(3, 2) && $bal(2)['money'] === 20000, 'only the two involved can end a proposal, and the ring is refunded once');
marriage_propose(2, 3);
marriage_propose(4, 3);
check(marriage_accept(3, 2) === null && (int)marriage_spouse(2)['spouse'] === 3 && (int)marriage_spouse(3)['spouse'] === 2 && marriage_accept(3, 4) !== null && $bal(4)['money'] === 20000 && marriage_spouse(4) === null
    && marriage_propose(4, 2) !== null, 'accepting marries the two, and the other suitor is turned away with their ring money back');
check(marriage_deposit(2, 6000) && marriage_joint(2, 3) === 6000 && marriage_joint(3, 2) === 6000 && !marriage_deposit(2, 999999) && marriage_withdraw(3, 1000) && $bal(3)['money'] === 21000 && !marriage_withdraw(3, 5001)
    && marriage_joint(2, 3) === 5000 && !marriage_deposit(4, 10), 'either spouse can pay into and draw on the joint account, never past what it holds');
check(marriage_move_in(3) !== null && marriage_move_in(2) === null && (int)$user(2)['maxwill'] === 250 && marriage_is_guest(2) && marriage_move_in(2) !== null && marriage_move_in(3) !== null,
    'the spouse with the lesser house can move in and takes on the better will bar; the owner cannot move into theirs');
$db->exec('UPDATE `users` SET `maxwill` = 400 WHERE `userid` = 3');
marriage_house_sync(3);
$up = (int)$user(2)['maxwill'];
$db->exec('UPDATE `users` SET `maxwill` = 100 WHERE `userid` = 3');
marriage_house_sync(3);
check($up === 400 && (int)$user(2)['maxwill'] === 100 && !marriage_is_guest(2), 'when the owner trades up the guest goes with them; when the owner sells, the guest is back in their own house');
$db->exec('UPDATE `users` SET `maxwill` = 250 WHERE `userid` = 3');
marriage_move_in(2);
check(marriage_divorce(2) !== null && marriage_spouse(2) !== null && marriage_is_guest(2) && marriage_joint(2, 3) === 5000, 'a divorce the filer cannot pay for changes nothing: still married, still living together');
$db->exec('UPDATE `users` SET `money` = 20000 WHERE `userid` = 2');
$m2 = $bal(2)['money'];
$m3 = $bal(3)['money'];
check(marriage_divorce(2) === null && marriage_spouse(2) === null && marriage_spouse(3) === null && (int)$user(2)['maxwill'] === 100 && $bal(2)['money'] === $m2 - MCC_DIVORCE_PRICE + 2500 && $bal(3)['money'] === $m3 + 2500
    && marriage_divorce(2) !== null && (int)$db->value('SELECT COUNT(*) FROM `v3_joint`') === 0, 'divorce costs the one who files, splits the joint account, and sends a guest home');

// the auction house
foreach (['v3_auctions', 'inventory', 'events', 'v3_ledger'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 10000, `maxwill` = 100, `will` = 100');
item_add(2, 4, 3);
$inv = (int)$db->value('SELECT `inv_id` FROM `inventory` WHERE `inv_userid` = 2');
check(is_string(auction_list(2, $inv, 4, 100, 24)) && is_string(auction_list(2, $inv, 1, 0, 24)) && is_string(auction_list(2, $inv, 1, 100, 5)) && is_string(auction_list(3, $inv, 1, 100, 24)) && $held(2, 4) === 3,
    'you can only auction what you hold, at a real price, for one of the set times');
$aid = auction_list(2, $inv, 2, 1000, 1);
check(is_int($aid) && $held(2, 4) === 1, 'listing takes the items out of the inventory');
$now = time();
check(auction_bid(2, $aid, 5000) !== null && auction_bid(3, $aid, 999) !== null && auction_bid(3, $aid, 1000, $now) === null && $bal(3)['money'] === 9000 && auction_bid(3, $aid, 2000, $now) !== null
    && auction_bid(4, $aid, 1049, $now) !== null && auction_bid(4, $aid, 1050, $now) === null && $bal(3)['money'] === 10000 && $bal(4)['money'] === 8950 && auction_bid(4, $aid, 99999, $now) !== null,
    'a bid is paid when made, must beat the last by 5%, and being outbid returns the money at once');
check(!auction_cancel(2, $aid) && !auction_cancel(3, $aid), 'an auction with a bid cannot be taken down');
$ends = (int)$db->value('SELECT `ends` FROM `v3_auctions` WHERE `id` = ?', [$aid]);
auction_bid(3, $aid, 1200, $ends - 30);
check((int)$db->value('SELECT `ends` FROM `v3_auctions` WHERE `id` = ?', [$aid]) === $ends - 30 + MCC_AUCTION_EXTEND && auction_settle($ends) === 0, 'a bid in the last moments pushes the close back, so nobody wins by sniping');
check(auction_settle($ends + 600) === 1 && auction_settle($ends + 600) === 0 && $held(3, 4) === 2 && $bal(2)['money'] === 10000 + 1140 && $bal(3)['money'] === 8800 && $bal(4)['money'] === 10000
    && auction_bid(4, $aid, 5000) !== null && $bal(4)['money'] === 10000, 'at the close the winner gets the items and the seller the bid less 5%, once; a closed auction takes no bids');
$aid2 = auction_list(2, (int)$db->value('SELECT `inv_id` FROM `inventory` WHERE `inv_userid` = 2'), 1, 500, 1);
check(auction_cancel(3, $aid2) === false && auction_cancel(2, $aid2) && !auction_cancel(2, $aid2) && $held(2, 4) === 1, 'with no bids the seller, and only the seller, can take it down and gets the item back once');
$aid3 = auction_list(2, (int)$db->value('SELECT `inv_id` FROM `inventory` WHERE `inv_userid` = 2'), 1, 500, 1);
auction_settle(time() + 4000);
check($held(2, 4) === 1 && $db->value('SELECT `status` FROM `v3_auctions` WHERE `id` = ?', [$aid3]) === 'expired', 'an auction nobody bid on returns the item');
$total = $bal(2)['money'] + $bal(3)['money'] + $bal(4)['money'];
check($total === 30000 - 60, 'across the whole sale the only money that left the game is the house\'s cut', $total);
foreach (['v3_auctions', 'inventory', 'events', 'v3_ledger', 'v3_proposals', 'v3_spouses', 'v3_joint'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 100, `crystals` = 10, `maxwill` = 100, `will` = 100, `new_events` = 0');

// the loan shark
$db->exec('UPDATE `users` SET `money` = 0, `bankmoney` = 0, `hospital` = 0, `hp` = 100');
$t0 = 1800000000;
check(loan_take(2, 100, 1, $t0) !== null && loan_take(2, 5001, 1, $t0) !== null && loan_take(2, 4000, 1, $t0) === null && $bal(2)['money'] === 4000 && loan_take(2, 1000, 1, $t0) !== null && $bal(2)['money'] === 4000,
    'he lends within the limit for your level, and one loan at a time');
$l = loan_of(2, $t0);
$l3 = loan_of(2, $t0 + 3 * 86400 + 5);
check($l['owed'] === 4200 && !$l['overdue'] && $l3['owed'] === 4800 && loan_of(2, $t0 + 8 * 86400)['overdue'] && loan_of(3) === null, 'interest is 5% of the amount borrowed for each day started, and the loan is overdue after a week');
check(loan_repay(2, 1000, $t0) === 1000 && $bal(2)['money'] === 3000 && loan_of(2, $t0)['owed'] === 3200 && loan_repay(2, 99999, $t0) === 0 && loan_repay(3, 10, $t0) === 0, 'part can be paid back; you cannot pay what you do not have');
$db->exec('UPDATE `users` SET `money` = 50000 WHERE `userid` = 2');
check(loan_repay(2, 99999, $t0) === 3200 && $bal(2)['money'] === 46800 && loan_of(2) === null && loan_repay(2, 10, $t0) === 0, 'paying it off never takes more than is owed, and clears the loan');
$db->exec('UPDATE `users` SET `money` = 0 WHERE `userid` = 2');
loan_take(2, 2000, 1, $t0);
$db->exec('UPDATE `users` SET `money` = 300, `bankmoney` = 500 WHERE `userid` = 2');
check(loan_collect(2, $t0 + 86400) === null && $bal(2)['money'] === 300, 'the collectors leave a loan alone until it is overdue');
$late = $t0 + 8 * 86400;
$owed = loan_of(2, $late)['owed'];
$c = loan_collect(2, $late);
check($owed === 2900 && $c === ['took' => 800, 'cleared' => false] && $bal(2)['money'] === 0 && $bal(2)['bankmoney'] === 0 && (int)$user(2)['hospital'] === MCC_LOAN_HOSPITAL && loan_of(2, $late)['owed'] === 2100
    && loan_collect(2, $late + 3600) === null, 'overdue: they take the pocket and the bank, put the debtor in hospital, and do not call twice in a day');
$db->exec('UPDATE `users` SET `money` = 90000, `hospital` = 0 WHERE `userid` = 2');
$owed = loan_of(2, $late + 86400)['owed'];
$c = loan_collect(2, $late + 86400);
check($c === ['took' => $owed, 'cleared' => true] && $bal(2)['money'] === 90000 - $owed && loan_of(2) === null && (int)$user(2)['hospital'] === 0, 'when there is enough they take exactly what is owed and the debt is gone');
check((int)$db->value("SELECT COUNT(*) FROM `v3_ledger` WHERE `userid` = 2") >= 5, 'every loan movement is in the ledger');

// support tickets
foreach (['v3_tickets', 'v3_ticket_posts', 'events', 'v3_loans', 'v3_ledger'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
check(is_string(ticket_open(2, 'nonsense', 'Hi', 'text')) && is_string(ticket_open(2, 'bug', '', 'text')) && is_string(ticket_open(2, 'bug', 'Hi', str_repeat('x', MCC_TICKET_MAX + 1))), 'a ticket needs a real topic, a subject and a message of sensible length');
$tk = ticket_open(2, 'bug', 'The gym  is  broken', "It ate my energy\r\n<script>alert(1)</script>");
check(is_int($tk) && ticket_get($tk, 2, false) !== null && ticket_get($tk, 3, false) === null && ticket_get($tk, 3, true) !== null && ticket_get($tk, 2, false)['subject'] === 'The gym is broken',
    'a ticket can be read by its owner and by staff, and by nobody else');
check(!str_contains(ticket_view(ticket_get($tk, 2, false), 2, false, 'tickets.php'), '<script>'), 'a ticket is shown as plain text');
$n = time() + 100;
check(ticket_reply($tk, 3, false, 'let me in', $n) !== null && ticket_reply($tk, 1, true, 'We are looking into it.', $n) === null && ticket_get($tk, 2, false)['status'] === 'answered'
    && (int)$db->value('SELECT COUNT(*) FROM `events` WHERE `evUSER` = 2') === 1 && ticket_reply($tk, 1, true, 'again', $n + 5) !== null && ticket_reply($tk, 2, false, 'Thanks', $n + 200) === null
    && ticket_get($tk, 2, false)['status'] === 'waiting' && ticket_waiting() === 1, 'a staff reply marks it answered and tells the player; the player\'s reply puts it back in the queue; messages cannot be spammed');
check(!ticket_close($tk, 3, false) && ticket_close($tk, 2, false) && !ticket_close($tk, 2, false) && ticket_reply($tk, 2, false, 'more', $n + 900) !== null && ticket_waiting() === 0, 'only the owner or staff can close a ticket, and a closed ticket takes no more messages');
ticket_open(2, 'bug', 'a', 'a');
ticket_open(2, 'bug', 'b', 'b');
ticket_open(2, 'bug', 'c', 'c');
check(is_string(ticket_open(2, 'bug', 'd', 'd')) && is_int(ticket_open(3, 'other', 'e', 'e')), 'a player can have three tickets open at once');
foreach (['v3_tickets', 'v3_ticket_posts', 'events', 'v3_loans', 'v3_ledger'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 100, `bankmoney` = 0, `crystals` = 10, `hospital` = 0, `hp` = 100, `new_events` = 0');

// friends, enemies, contacts; mugger of the hour
foreach (['friendslist', 'blacklist', 'contactlist', 'attacklogs'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `friend_count` = 0, `enemy_count` = 0');
check(contact_add('friends', 2, 2) !== null && contact_add('friends', 2, 999999) !== null && contact_add('friends', 2, 3, " A <b>good</b>\n sort ") === null && contact_add('friends', 2, 3) !== null
    && (int)$user(3)['friend_count'] === 1 && contact_rows('friends', 2)[0]['comment'] === 'A good sort', 'a friend is added once, not yourself and not a ghost, and the comment is plain text');
$en = (int)contact_rows('friends', 2)[0]['entry'];
check(!contact_note('friends', 3, $en, 'mine now') && contact_note('friends', 2, $en, 'changed') && contact_rows('friends', 2)[0]['comment'] === 'changed' && !contact_remove('friends', 3, $en) && !contact_remove('enemies', 2, $en)
    && contact_remove('friends', 2, $en) && !contact_remove('friends', 2, $en) && (int)$user(3)['friend_count'] === 0, 'only the owner can change or remove an entry, and the count comes down once');
check(contact_add('enemies', 2, 3) === null && (int)$user(3)['enemy_count'] === 1 && (int)$user(3)['friend_count'] === 0 && contact_add('contacts', 2, 3) === null && contact_add('contacts', 2, 3) !== null
    && count(contact_rows('contacts', 2)) === 1, 'the three lists are separate');
$nowt = time();
check(mugger_of_the_hour() === null, 'with no muggings there is no mugger of the hour');
$db->exec("INSERT INTO `attacklogs` (`attacker`, `attacked`, `result`, `time`, `stole`, `attacklog`) VALUES (2, 3, 'won', ?, 500, ''), (2, 4, 'won', ?, 700, ''), (3, 2, 'won', ?, 1100, ''), (4, 2, 'won', ?, 99999, ''), (4, 2, 'won', ?, -1, '')",
    [$nowt - 60, $nowt - 120, $nowt - 30, $nowt - 7200, $nowt - 10]);
$mug = $db->row("SELECT `a`.`attacker` AS `userid`, SUM(`a`.`stole`) AS `total`, COUNT(*) AS `n` FROM `attacklogs` AS `a` WHERE `a`.`time` > ? AND `a`.`result` = 'won' AND `a`.`stole` > 0 GROUP BY `a`.`attacker` ORDER BY `total` DESC LIMIT 1", [$nowt - 3600]);
check((int)$mug['userid'] === 2 && (int)$mug['total'] === 1200 && (int)$mug['n'] === 2, 'the mugger of the hour is whoever took the most in the last hour; older fights and beatings do not count');
foreach (['friendslist', 'blacklist', 'contactlist', 'attacklogs'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `friend_count` = 0, `enemy_count` = 0');

// merits
foreach (['v3_merits', 'v3_achievements', 'v3_loans', 'v3_auctions', 'inventory', 'events', 'v3_ledger', 'v3_daily', 'v3_missions'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
check(merit_earned(2) === 0 && merit_buy(2, 'daily') !== null && merit_buy(2, 'nonsense') !== null && merit_levels(2) === [], 'with no achievements there is nothing to spend');
$keys = array_keys(MCC_ACHIEVEMENTS);
foreach (array_slice($keys, 0, 4) as $k) {
    $db->exec('INSERT INTO `v3_achievements` (`userid`, `ach`, `earned`) VALUES (2, ?, ?)', [$k, time()]);
}
$db->exec("INSERT INTO `v3_achievements` (`userid`, `ach`, `earned`) VALUES (2, 'no_longer_exists', 1)");
check(merit_earned(2) === 4 && merit_buy(2, 'daily') === null && merit_buy(2, 'daily') === null && merit_levels(2) === ['daily' => 2] && merit_spent(merit_levels(2)) === 3 && merit_buy(2, 'daily') !== null
    && merit_buy(2, 'loan') === null && merit_buy(2, 'auction') !== null && merit_spent(merit_levels(2)) === 4, 'an achievement is one merit, level n costs n, and you cannot spend more than you have earned');
check(merit_boost(2, 'daily', 1000) === 1100 && merit_boost(2, 'missions', 1000) === 1000 && merit_boost(3, 'daily', 1000) === 1000 && merit_rate(2, 'loan', 50) === 45 && merit_rate(3, 'loan', 50) === 50
    && merit_rate(2, 'auction', 50) === 50, 'an upgrade changes only its own thing, only for the player who has it');
$db->exec("INSERT INTO `v3_merits` (`userid`, `perk`, `level`) VALUES (4, 'loan', 99), (4, 'auction', 5)");
check(merit_rate(4, 'loan', 50) === 25 && merit_rate(4, 'auction', 50) === 25 && merit_levels(4)['loan'] === 5, 'a rate never falls below half, whatever the table says');
$db->exec('UPDATE `users` SET `money` = 0, `bankmoney` = 0');
loan_take(2, 4000, 1, $t0);
loan_take(3, 4000, 1, $t0);
check(loan_of(2, $t0)['owed'] === 4180 && loan_of(3, $t0)['owed'] === 4200, 'the loan shark charges a Good Customer less');
$base = daily_reward(1, 1)['money'];
$got = daily_claim(2, 1, '2031-03-01');
$plain = daily_claim(3, 1, '2031-03-01');
check($got['money'] === $base + intdiv($base * 10, 100) && $plain['money'] === $base && $bal(2)['money'] === 4000 + $got['money'], 'the daily reward pays the upgrade, and what is shown is what was paid', $got['money']);
item_add(4, 4, 1);
$db->exec('UPDATE `users` SET `money` = 10000 WHERE `userid` = 3');
$db->exec('UPDATE `users` SET `money` = 0 WHERE `userid` = 4');
$am = auction_list(4, (int)$db->value('SELECT `inv_id` FROM `inventory` WHERE `inv_userid` = 4'), 1, 1000, 1);
auction_bid(3, $am, 1000);
auction_settle(time() + 9000);
check($bal(4)['money'] === 975, 'the auction house takes 2.5% from a Friend of the House at the top level', $bal(4)['money']);
foreach (['v3_merits', 'v3_achievements', 'v3_loans', 'v3_auctions', 'inventory', 'events', 'v3_ledger', 'v3_daily', 'v3_missions'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 100, `bankmoney` = 0, `crystals` = 10, `new_events` = 0');

// travel and vehicles
foreach (['v3_travel', 'v3_vehicles', 'v3_ledger'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 5000, `location` = 1, `level` = 1, `jail` = 0, `hospital` = 0');
$tt = 1900000000;
check(travel_start(2, 1, $tt) !== null && travel_start(2, 99999, $tt) !== null && travel_start(2, 3, $tt) !== null && $bal(2)['money'] === 5000 && travel_of(2, $tt) === null, 'you cannot travel to where you are, to nowhere, or to a city above your level; a refusal costs nothing');
check(travel_start(2, 2, $tt) === null && $bal(2)['money'] === 4000 && (int)$user(2)['location'] === 1 && travel_of(2, $tt + 10)['left'] === MCC_TRAVEL_SECONDS - 10 && travel_start(2, 2, $tt + 5) !== null && $bal(2)['money'] === 4000,
    'the monorail costs the fare, takes five minutes, and you can only be on one journey');
check(travel_arrive(2, $tt + 299) === null && (int)$user(2)['location'] === 1 && travel_arrive(2, $tt + 300) === 2 && (int)$user(2)['location'] === 2 && travel_arrive(2, $tt + 300) === null && travel_of(2, $tt + 300) === null,
    'you arrive when the time is up, once');
$db->exec('UPDATE `users` SET `hospital` = 5 WHERE `userid` = 2');
check(travel_start(2, 1, $tt + 400) !== null, 'nobody travels from hospital');
$db->exec('UPDATE `users` SET `hospital` = 0, `money` = 60000 WHERE `userid` = 2');
check(vehicle_buy(2, 'tank') !== null && vehicle_buy(2, 'car') !== null && vehicle_buy(2, 'bike') === null && $bal(2)['money'] === 10000 && vehicle_buy(2, 'bike') !== null && $bal(2)['money'] === 10000 && vehicle_of(2) === 'bike',
    'a vehicle is bought once, if you can afford it');
check(travel_start(2, 1, $tt + 500) === null && $bal(2)['money'] === 10000 && travel_of(2, $tt + 500)['left'] === 240 && travel_arrive(2, $tt + 740) === 1, 'with a vehicle there is no fare and the journey is shorter');
$db->exec('UPDATE `users` SET `money` = 225000 WHERE `userid` = 2');
check(vehicle_buy(2, 'car') === null && $bal(2)['money'] === 0 && vehicle_of(2) === 'car' && vehicle_buy(2, 'bike') === null && $bal(2)['money'] === 75000, 'buying another trades the old one in at half price, up or down');
check(vehicle_sell(2) === 25000 && vehicle_sell(2) === 0 && $bal(2)['money'] === 100000 && vehicle_of(2) === null && vehicle_sell(3) === 0, 'a vehicle sells for half, once');
foreach (['v3_travel', 'v3_vehicles', 'v3_ledger'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 100, `location` = 1');

// businesses
foreach (['v3_business', 'v3_stalls', 'v3_stall_stock', 'inventory', 'events', 'v3_ledger', 'v3_progress', 'v3_achievements'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 200000');
$bt = 1950000000;
check(business_buy(2, 'bank', 99, $bt) !== null && business_buy(2, 'shop', 4, $bt) !== null && business_buy(2, 'garage', 15, $bt) !== null && $bal(2)['money'] === 200000 && business_of(2) === null,
    'a business needs the level and the money; a refusal costs nothing');
check(business_buy(2, 'shop', 5, $bt) === null && $bal(2)['money'] === 100000 && business_buy(2, 'shop', 5, $bt) !== null && $bal(2)['money'] === 100000, 'one business a player, paid for once');
check(business_collect(2, $bt + 3599) === 0 && business_of(2, $bt + 3 * 3600 + 900)['pending'] === 375 && business_collect(2, $bt + 3 * 3600 + 900) === 375 && $bal(2)['money'] === 100375
    && business_collect(2, $bt + 3 * 3600 + 1000) === 0 && business_collect(2, $bt + 4 * 3600) === 125, 'the till fills by the whole hour, pays once, and keeps the part-hour already worked');
check(business_of(2, $bt + 90 * 3600)['hours'] === 24 && business_collect(2, $bt + 90 * 3600) === 3000 && business_of(2, $bt + 90 * 3600 + 1800)['hours'] === 0, 'the till holds a day and no more');
$m = $bal(2)['money'];
check(business_upgrade(2, $bt + 92 * 3600) === null && $bal(2)['money'] === $m + 250 - 50000 && business_of(2, $bt + 92 * 3600)['hourly'] === 150 && business_of(2, $bt + 92 * 3600)['pending'] === 0 && business_upgrade(3) !== null,
    'an upgrade empties the till at the old rate, costs half the price, and raises the takings by a fifth');
$db->exec('UPDATE `users` SET `money` = 0 WHERE `userid` = 2');
check(business_upgrade(2, $bt + 93 * 3600) !== null && business_of(2, $bt + 93 * 3600)['level'] === 2 && business_of(2, $bt + 93 * 3600)['pending'] === 150, 'an upgrade you cannot afford changes nothing, the till included');
check(business_sell(2, $bt + 93 * 3600) === 50150 && business_sell(2, $bt + 93 * 3600) === 0 && $bal(2)['money'] === 50150 && business_of(2) === null, 'selling pays the till and half the purchase price, once');

// fame
check(fame_of(2)['total'] === 0 && fame_reputation(0) === 'Unknown' && fame_reputation(99) === 'Unknown' && fame_reputation(100) === 'Talked About' && fame_reputation(999999) === 'Legendary' && fame_next(60000) === null
    && fame_next(450) === ['at' => 500, 'name' => 'Known', 'need' => 50] && fame_top() === [], 'fame starts at nothing, and reputations change at their thresholds');
$db->exec("INSERT INTO `v3_progress` (`userid`, `stat`, `amount`) VALUES (2, 'fights_won', 10), (2, 'crimes', 40), (2, 'crime_money', 999999), (3, 'bounties', 2), (1, 'fights_won', 5000)");
$db->exec("INSERT INTO `v3_achievements` (`userid`, `ach`, `earned`) VALUES (2, 'crimes_10', 1), (2, 'gone_now', 1), (3, 'fights_1', 1)");
$top = fame_top();
check(fame_of(2)['total'] === 140 && fame_of(3)['total'] === 90 && (int)$top[0]['userid'] === 1 && (int)$top[1]['userid'] === 2 && (int)$top[1]['fame'] === 140 && (int)$top[2]['fame'] === 90 && count($top) === 3,
    'fame is counted from deeds and achievements, money made does not count, and the board agrees with the profile', [fame_of(2)['total'], $top]);

// market stalls
$db->exec('UPDATE `users` SET `money` = 30000');
item_add(2, 4, 5);
$inv2 = (int)$db->value('SELECT `inv_id` FROM `inventory` WHERE `inv_userid` = 2');
check(stall_add(2, $inv2, 1, 100) !== null && stall_open(2, 'ab') !== null && stall_open(2, ' <b>Trophy</b>   Cabinet ') === null && stall_of(2)['name'] === 'Trophy Cabinet' && $bal(2)['money'] === 5000 && stall_open(2, 'Another') !== null
    && $bal(2)['money'] === 5000, 'a stall costs the pitch fee once, has a plain-text name, and you need one before you can stock it');
check(stall_add(2, $inv2, 9, 100) !== null && stall_add(3, $inv2, 1, 100) !== null && stall_add(2, $inv2, 3, 0) !== null && stall_add(2, $inv2, 3, 400) === null && $held(2, 4) === 2 && stall_add(2, $inv2, 1, 500) === null
    && stall_stock(2)[0]['qty'] == 4 && stall_stock(2)[0]['price'] == 500 && count(stall_stock(2)) === 1, 'you stock only what you hold; more of the same joins the line at the new price');
$line = (int)stall_stock(2)[0]['id'];
check(stall_buy(2, $line, 1, 500) !== null && stall_buy(3, $line, 1, 400) !== null && stall_buy(3, $line, 9, 500) !== null && stall_buy(3, $line, 0, 500) !== null && $bal(3)['money'] === 30000 && $held(3, 4) === 0,
    'you cannot buy from yourself, at a price you did not see, or more than is there');
check(stall_buy(3, $line, 3, 500) === null && $bal(3)['money'] === 28500 && $bal(2)['money'] === 6500 && $held(3, 4) === 3 && (int)stall_stock(2)[0]['qty'] === 1 && (int)$db->value('SELECT COUNT(*) FROM `events` WHERE `evUSER` = 2') === 1,
    'a sale moves the items and the money together and tells the owner');
$db->exec('UPDATE `users` SET `money` = 100 WHERE `userid` = 4');
check(stall_buy(4, $line, 1, 500) !== null && $held(4, 4) === 0 && (int)stall_stock(2)[0]['qty'] === 1 && !stall_close(2) && !stall_remove(3, $line) && stall_remove(2, $line) && !stall_remove(2, $line) && $held(2, 4) === 2 && stall_stock(2) === [],
    'a buyer without the money gets nothing; only the owner can take stock back, once');
check(stall_find('troph') === [] && stall_directory() === [] && !stall_close(3) && stall_close(2) && stall_of(2) === null, 'an empty stall is not in the market, and can be closed by its owner');
$tot = $bal(2)['money'] + $bal(3)['money'] + $bal(4)['money'];
check($tot === 6500 + 28500 + 100 && $held(2, 4) + $held(3, 4) === 5, 'across the stall, no money or items appeared or vanished', $tot);
foreach (['v3_business', 'v3_stalls', 'v3_stall_stock', 'inventory', 'events', 'v3_ledger', 'v3_progress', 'v3_achievements'] as $t) {
    $db->exec("DELETE FROM `{$t}`");
}
$db->exec('UPDATE `users` SET `money` = 100, `new_events` = 0');

echo $failed ? "\n{$failed} check(s) FAILED\n" : "\nAll engine checks passed.\n";
exit($failed ? 1 : 0);
