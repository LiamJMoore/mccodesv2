<?php
declare(strict_types=1);
/**
 * MCCodes v2 by Dabomstew & ColdBlooded
 * 
 * Repository: https://github.com/davemacaulay/mccodesv2
 * License: MIT License
 */

global $db, $ir, $userid, $h;
require_once('globals.php');
if ($ir['energy'] < 10)
{
    echo 'Sorry, it costs 10 energy to bust someone. '
            . "You only have {$ir['energy']} energy. " . 'Come back later.';
    $h->endpage();
    exit;
}
if ($ir['jail'])
{
    echo 'You cannot bust out people while in jail.';
    $h->endpage();
    exit;
}
$_GET['ID'] =
        (isset($_GET['ID']) && is_numeric($_GET['ID']))
                ? abs(intval($_GET['ID'])) : 0;
$jail_q =
        $db->query(
                "SELECT `userid`, `jail`, `level`, `username`
				 FROM `users`
				 WHERE `userid` = {$_GET['ID']}");
if ($db->num_rows($jail_q) == 0)
{
    $db->free_result($jail_q);
    echo 'Invalid user';
    $h->endpage();
    exit;
}
$r = $db->fetch_row($jail_q);
$db->free_result($jail_q);
if (!$r['jail'])
{
    echo 'That user is not in jail!';
    $h->endpage();
    exit;
}
// engine v3: the 10 energy is taken first, in one statement that refuses if it is not there
if (!bar_spend($userid, 'energy', 10))
{
    echo 'Sorry, it costs 10 energy to bust someone. Come back later.';
    $h->endpage();
    exit;
}
$mult = max(1, $r['level'] * $r['level']);
$chance = min(($ir['crimexp'] / $mult) * 50 + 1, 95);
if (rand(1, 100) < $chance)
{
    $gain = $r['level'] * 5;
    if ($db->exec('UPDATE `users` SET `jail` = 0 WHERE `userid` = ? AND `jail` > 0', [(int) $r['userid']]) !== 1)
    {
        echo "By the time you get there, {$r['username']} is already out.<br />
  	&gt; <a href='jail.php'>Back</a>";
        $h->endpage();
        exit;
    }
    $db->exec('UPDATE `users` SET `crimexp` = `crimexp` + ? WHERE `userid` = ?', [(int) $gain, $userid]);
    echo "You successfully busted {$r['username']} out of jail.<br />
  	&gt; <a href='jail.php'>Back</a>";
    event_add($r['userid'],
        "<a href='viewuser.php?u={$ir['userid']}'>{$ir['username']}</a> busted you out of jail.");
}
else
{
    echo "While trying to bust out your friend, a guard spotted you and dragged you into jail yourself. Unlucky!<br />
  	&gt; <a href='jail.php'>Back</a>";
    $time = min($mult, 100);
    $db->exec('UPDATE `users` SET `jail` = ?, `jail_reason` = ? WHERE `userid` = ?', [(int) $time, "Caught trying to bust out {$r['username']}", $userid]);
    event_add($r['userid'],
        "<a href='viewuser.php?u={$ir['userid']}'>{$ir['username']}</a> was caught trying to bust you out of jail.");
}
$h->endpage();
