<?php
/**
 * A development install from the command line: the same steps as installer.php (write config.php, load dbdata.sql,
 * create the first admin and the game's settings), with nothing to click, so tests can build a fresh game.
 *
 *   php tools/dev/setup.php --host=127.0.0.1 --user=mcc --pass=mccpass --db=mccv3test [--players=3]
 *
 * It DROPS every table in the named database first. Never point it at a live game: it refuses a database that
 * already has players unless --force is given.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit('Command line only.');
}
$o = getopt('', ['host:', 'user:', 'pass:', 'db:', 'players::', 'force']);
foreach (['host', 'user', 'pass', 'db'] as $k) {
    if (!isset($o[$k])) {
        fwrite(STDERR, "Missing --{$k}\n");
        exit(2);
    }
}
$root = dirname(__DIR__, 2);
chdir($root);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$my = new mysqli($o['host'], $o['user'], $o['pass'], $o['db']);
$my->set_charset('latin1');
$has = $my->query("SHOW TABLES LIKE 'users'")->num_rows;
if ($has && !isset($o['force']) && (int)$my->query('SELECT COUNT(*) FROM `users`')->fetch_row()[0] > 0) {
    fwrite(STDERR, "The database {$o['db']} already holds a game with players. Use --force to wipe it.\n");
    exit(2);
}
$my->query('SET FOREIGN_KEY_CHECKS=0');
foreach ($my->query('SHOW TABLES')->fetch_all() as $t) {
    $my->query("DROP TABLE `{$t[0]}`");
}
// the schema, read the way installer.php reads it
$query = '';
foreach (explode("\n", file_get_contents('dbdata.sql')) as $line) {
    if (!str_starts_with($line, '--') && trim($line) !== '') {
        $query .= $line;
        if (str_contains($line, ';')) {
            $my->query($query);
            $query = '';
        }
    }
}
$code = bin2hex(random_bytes(16));
$cfg = "<?php\n\$_CONFIG = array(\n\t'hostname' => " . var_export($o['host'], true) . ",\n\t'username' => " . var_export($o['user'], true)
    . ",\n\t'password' => " . var_export($o['pass'], true) . ",\n\t'database' => " . var_export($o['db'], true)
    . ",\n\t'persistent' => 0,\n\t'driver' => 'mysqli',\n\t'code' => '{$code}',\n);\n";
file_put_contents('config.php', $cfg);
require 'global_func.php';

function make_user(mysqli $my, string $name, string $password, int $user_level): int
{
    $salt = generate_pass_salt();
    $pass = encode_password($password, $salt);
    $st = $my->prepare("INSERT INTO `users` (`username`, `login_name`, `userpass`, `level`, `money`, `crystals`, `donatordays`, `user_level`, `energy`,
        `maxenergy`, `will`, `maxwill`, `brave`, `maxbrave`, `hp`, `maxhp`, `location`, `gender`, `signedup`, `email`, `bankmoney`, `lastip`,
        `lastip_signup`, `pass_salt`, `display_pic`, `staffnotes`, `voted`, `user_notepad`)
        VALUES (?, ?, ?, 1, 100000, 100, 0, ?, 12, 12, 100, 100, 5, 5, 100, 100, 1, 'Male', ?, ?, -1, '127.0.0.1', '127.0.0.1', ?, '', '', '', '')");
    $now = time();
    $email = strtolower($name) . '@example.test';
    $st->bind_param('sssiiss', $name, $name, $pass, $user_level, $now, $email, $salt);
    $st->execute();
    $id = $my->insert_id;
    $my->query("INSERT INTO `userstats` VALUES ({$id}, 10, 10, 10, 10, 10)");
    return $id;
}

$admin = make_user($my, 'admin', 'adminpass1', 2);
$players = (int)($o['players'] ?? 3);
for ($i = 1; $i <= $players; $i++) {
    make_user($my, "player{$i}", "playerpass{$i}", 1);
}
foreach (['game_name' => 'MCCodes v3 dev', 'game_owner' => 'Dev', 'paypal' => 'dev@example.test', 'game_description' => 'A development game.'] as $k => $v) {
    $st = $my->prepare("INSERT INTO `settings` VALUES (NULL, ?, ?, 'string')");
    $st->bind_param('ss', $k, $v);
    $st->execute();
}
echo "Installed a fresh game in {$o['db']}: admin (password adminpass1) and {$players} players (player1 / playerpass1, ...).\n";
echo "config.php written. Cron code: {$code}\n";
