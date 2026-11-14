<?php
declare(strict_types=1);
/**
 * MCCodes v2 by Dabomstew & ColdBlooded
 * 
 * Repository: https://github.com/davemacaulay/mccodesv2
 * License: MIT License
 */

$atkpage = 1;
global $db, $ir, $userid, $h;
require_once('globals.php');
$_GET['ID'] = in_int('ID', 0, $_GET);
$_SESSION['attacking'] = 0;
$ir['attacking'] = 0;
$db->exec('UPDATE `users` SET `attacking` = 0 WHERE `userid` = ?', [$userid]);
if (!isset($_SESSION['attackwon']) || $_SESSION['attackwon'] != $_GET['ID'] || $_GET['ID'] === 0)
{
    die("Cheaters don't get anywhere.");
}
// engine v3: the fight is finished in one locked step (engine/Combat.php), so it can be finished once
$won = fight_win($ir, $_GET['ID'], 'mug', (string) ($_SESSION['attacklog'] ?? ''));
$_SESSION['attackwon'] = 0;
if ($won === null)
{
    echo 'What a cheater you are.';
    $h->endpage();
    exit;
}
$r = $won['loser'];
echo "You beat {$r['username']}!!<br />
You knock {$r['username']} on the floor a few times to make sure he is unconscious, "
        . 'then open his wallet, snatch ' . money_formatter($won['stole'])
        . ', and run home happily.';
echo fight_win_footer($won);
$h->endpage();
