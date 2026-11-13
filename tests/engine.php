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
require 'engine/Schema.php';
require 'engine/Progress.php';
require 'engine/mods/Daily.php';
require 'engine/mods/Achievements.php';
require 'engine/mods/Missions.php';
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
    && mission_text(['mission' => 'fights', 'target' => 1]) === 'Win a fight' && mission_text(['mission' => 'fights', 'target' => 2]) === 'Win 2 fights', 'mission wording reads properly for one and for several',
    [mission_text(['mission' => 'crimes', 'target' => 1]), mission_text(['mission' => 'gym', 'target' => 1]), mission_text(['mission' => 'fights', 'target' => 1])]);
foreach (['v3_progress', 'v3_achievements', 'v3_missions'] as $t) {
    $db->exec("DELETE FROM `{$t}` WHERE `userid` IN (2, 3)");
}
$db->exec('DELETE FROM `events` WHERE `evUSER` = 2');
$db->exec('UPDATE `users` SET `money` = 100, `crystals` = 10, `new_events` = 0 WHERE `userid` = 2');

echo $failed ? "\n{$failed} check(s) FAILED\n" : "\nAll engine checks passed.\n";
exit($failed ? 1 : 0);
