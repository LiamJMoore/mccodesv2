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
include 'config.php';
require 'class/class_db_mysqli.php';
$db = new database();
$db->configure($_CONFIG['hostname'], $_CONFIG['username'], $_CONFIG['password'], $_CONFIG['database']);
$db->connect();

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

echo $failed ? "\n{$failed} check(s) FAILED\n" : "\nAll engine checks passed.\n";
exit($failed ? 1 : 0);
