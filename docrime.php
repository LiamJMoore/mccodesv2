<?php
declare(strict_types=1);
/**
 * MCCodes v2 by Dabomstew & ColdBlooded
 * 
 * Repository: https://github.com/davemacaulay/mccodesv2
 * License: MIT License
 */

if (!isset($_GET['c']))
{
    $_GET['c'] = 0;
}
$_GET['c'] = abs((int) $_GET['c']);
$macropage = "docrime.php?c={$_GET['c']}";
global $db, $ir, $userid, $h;
$sucrate = 0;
require_once('globals.php');
if ($ir['jail'] > 0 || $ir['hospital'] > 0)
{
    die('This page cannot be accessed while in jail or hospital.');
}
if ($_GET['c'] <= 0)
{
    echo 'Invalid crime';
    $h->endpage();
    exit;
}
$r = $db->row('SELECT * FROM `crimes` WHERE `crimeID` = ?', [$_GET['c']]);
if ($r === null)
{
    echo 'Invalid crime.';
    $h->endpage();
    exit;
}
// the chance of success is a sum staff typed in. v2 ran it as PHP code; v3 works it out as arithmetic and nothing else
try
{
    $sucrate = formula_value((string) $r['crimePERCFORM'],
            ['LEVEL' => $ir['level'], 'CRIMEXP' => $ir['crimexp'], 'EXP' => $ir['exp'], 'WILL' => $ir['will'], 'IQ' => $ir['IQ']]);
}
catch (FormulaError $e)
{
    echo 'This crime has not been set up correctly, so it cannot be attempted. Please tell a member of staff.';
    if ($ir['user_level'] == 2)
    {
        echo '<br />Staff note: ' . e($e->getMessage());
    }
    $h->endpage();
    exit;
}
// the brave is taken in one statement that refuses if it is not there, so one bar of brave is one attempt
if (!bar_spend($userid, 'brave', (int) $r['crimeBRAVE']))
{
    echo 'You do not have enough Brave to perform this crime.';
    $h->endpage();
    exit;
}
print $r['crimeITEXT'];
if (rand(1, 100) <= $sucrate)
{
    print str_replace('{money}', (string) $r['crimeSUCCESSMUNY'], $r['crimeSTEXT']);
    $db->exec('UPDATE `users` SET `money` = `money` + ?, `crystals` = `crystals` + ?, `exp` = `exp` + ?, `crimexp` = `crimexp` + ? WHERE `userid` = ?',
            [(int) $r['crimeSUCCESSMUNY'], (int) $r['crimeSUCCESSCRYS'], (int) ($r['crimeSUCCESSMUNY'] / 8), (int) $r['crimeXP'], $userid]);
    ledger_add($userid, 'money', (int) $r['crimeSUCCESSMUNY'], (string) $r['crimeNAME']);
    ledger_add($userid, 'crystals', (int) $r['crimeSUCCESSCRYS'], (string) $r['crimeNAME']);
    progress_add($userid, 'crimes');
    progress_add($userid, 'crime_money', (int) $r['crimeSUCCESSMUNY']);
    if ($r['crimeSUCCESSITEM'])
    {
        item_add($userid, (int) $r['crimeSUCCESSITEM'], 1);
    }
}
elseif (rand(1, 2) == 1)
{
    print $r['crimeFTEXT'];
}
else
{
    print $r['crimeJTEXT'];
    $db->exec('UPDATE `users` SET `jail` = ?, `jail_reason` = ? WHERE `userid` = ?', [(int) $r['crimeJAILTIME'], $r['crimeJREASON'], $userid]);
}
echo "<br /><a href='docrime.php?c={$_GET['c']}'>Try Again</a><br />
<a href='criminal.php'>Crimes</a>";
$h->endpage();
