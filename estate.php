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
$mpq =
        $db->query(
                "SELECT *
				 FROM `houses`
				 WHERE `hWILL` = {$ir['maxwill']}
				 LIMIT 1");
$mp = $db->fetch_row($mpq);
$db->free_result($mpq);
if (isset($_GET['property']) && is_numeric($_GET['property']))
{
    $_GET['property'] = abs((int) $_GET['property']);
    $npq =
            $db->query(
                    "SELECT `hWILL`, `hPRICE`, `hNAME`
    				 FROM `houses`
    				 WHERE `hID` = {$_GET['property']}");
    if ($db->num_rows($npq) == 0)
    {
        $db->free_result($npq);
        echo "That house doesn't exist.";
        $h->endpage();
        exit;
    }
    $np = $db->fetch_row($npq);
    $db->free_result($npq);
    if ($np['hWILL'] <= $ir['maxwill'])
    {
        echo $np['hWILL'] == $ir['maxwill'] ? 'You already live there!' : 'You cannot go backwards in houses!';
    }
    // engine v3: paid for and moved into in one statement, which refuses if the money is not there or the
    // player's house changed in the meantime
    elseif ($db->exec('UPDATE `users` SET `money` = `money` - ?, `will` = 0, `maxwill` = ? WHERE `userid` = ? AND `money` >= ? AND `maxwill` = ?',
            [(int) $np['hPRICE'], (int) $np['hWILL'], $userid, (int) $np['hPRICE'], (int) $ir['maxwill']]) !== 1)
    {
        echo "You do not have enough money to buy the {$np['hNAME']}.";
    }
    else
    {
        ledger_add($userid, 'money', -(int) $np['hPRICE'], 'bought ' . $np['hNAME']);
        echo "Congrats, you bought the {$np['hNAME']} for "
                . money_formatter($np['hPRICE']) . '!';
    }
}
elseif (isset($_GET['sellhouse']))
{
    if ($ir['maxwill'] == 100 || !$mp)
    {
        echo 'You already live in the lowest property!';
    }
    // engine v3: the house is sold only if the player still owns it. v2 paid out for every request that arrived
    // before the first one finished: one house, sold many times.
    elseif ($db->exec('UPDATE `users` SET `money` = `money` + ?, `will` = 0, `maxwill` = 100 WHERE `userid` = ? AND `maxwill` = ?',
            [(int) $mp['hPRICE'], $userid, (int) $ir['maxwill']]) !== 1)
    {
        echo 'You have already sold that house.';
    }
    else
    {
        ledger_add($userid, 'money', (int) $mp['hPRICE'], 'sold ' . $mp['hNAME']);
        echo "You sold your {$mp['hNAME']} and went back to your shed.";
    }
}
else
{
    echo 'Your current property: <b>' . ($mp['hNAME'] ?? 'Shed') . "</b><br />
The houses you can buy are listed below. Click a house to buy it.<br />";
    if ($ir['maxwill'] > 100)
    {
        echo "<a href='estate.php?sellhouse'>Sell Your House</a><br />";
    }
    $hq =
            $db->query(
                    "SELECT *
                     FROM `houses`
                     WHERE `hWILL` > {$ir['maxwill']}
                     ORDER BY `hWILL` ASC");
    while ($r = $db->fetch_row($hq))
    {
        echo "<a href='estate.php?property={$r['hID']}'>{$r['hNAME']}</a>"
                . '&nbsp;&nbsp - Cost: ' . money_formatter($r['hPRICE'])
                . "&nbsp;&nbsp - Will Bar: {$r['hWILL']}<br />";
    }
    $db->free_result($hq);
}
$h->endpage();
