<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: staff, the ledger of every change to money and crystals.
 */
global $db, $ir, $h;
require_once('sglobals.php');
if (!check_access(['administrator', 'manage_users'])) {
    echo 'You cannot access this area.<br />&gt; <a href="index.php">Go Home</a>';
    $h->endpage();
    exit;
}
$f = ['userid' => in_int('u', 0, $_GET), 'wallet' => in_choice('w', MCC_WALLETS, '', $_GET), 'min' => in_int('min', 0, $_GET), 'page' => preg_replace('/[^a-z0-9_.]/i', '', in_str('pg', 40, '', $_GET))];
$page = max(1, in_int('p', 1, $_GET));
$lines = ledger_lines($f, 101, ($page - 1) * 100);
$more = count($lines) > 100;
$q = http_build_query(['u' => $f['userid'] ?: '', 'w' => $f['wallet'], 'min' => $f['min'] ?: '', 'pg' => $f['page']]);
echo "<h3>Ledger</h3><p>Every change to a player's cash, bank or crystals for the last " . MCC_LEDGER_DAYS . " days, newest first.</p>
<form action='staff_ledger.php' method='get'>Player ID <input type='text' name='u' size='6' value='" . ($f['userid'] ?: '') . "' />
Account <select name='w'><option value=''>any</option>";
foreach (MCC_WALLETS as $w) {
    echo "<option value='{$w}'" . ($f['wallet'] === $w ? " selected='selected'" : '') . ">{$w}</option>";
}
echo "</select> At least <input type='text' name='min' size='8' value='" . ($f['min'] ?: '') . "' /> Page <input type='text' name='pg' size='14' value='" . e($f['page']) . "' placeholder='bank.php' /> <input type='submit' value='Filter' /></form>
<table width='100%' cellspacing='1' class='table'><tr><th>When</th><th>Player</th><th>Account</th><th>Change</th><th>Where</th><th>Note</th></tr>";
foreach (array_slice($lines, 0, 100) as $l) {
    $d = (int)$l['delta'];
    echo '<tr><td>' . date('j M, H:i:s', (int)$l['at']) . "</td><td><a href='staff_ledger.php?u={$l['userid']}'>" . e((string)$l['username']) . "</a> [{$l['userid']}]</td><td>" . e($l['wallet']) . '</td><td>'
        . ($d > 0 ? "<span class='amt-in'>+ " . number_format($d) . '</span>' : "<span class='amt-out'>&minus; " . number_format(-$d) . '</span>') . "</td><td><a href='staff_ledger.php?pg=" . e($l['page']) . "'>" . e($l['page']) . '</a></td><td>' . e($l['note']) . '</td></tr>';
}
if (!$lines) {
    echo "<tr><td colspan='6'>No lines match.</td></tr>";
}
echo '</table><p>' . ($page > 1 ? "<a href='staff_ledger.php?{$q}&amp;p=" . ($page - 1) . "'>&laquo; Newer</a> " : '') . ($more ? "<a href='staff_ledger.php?{$q}&amp;p=" . ($page + 1) . "'>Older &raquo;</a>" : '') . '</p>';
if ($f['userid']) {
    $sum = $db->all('SELECT `wallet`, SUM(IF(`delta` > 0, `delta`, 0)) AS `in`, SUM(IF(`delta` < 0, -`delta`, 0)) AS `out`, COUNT(*) AS `n` FROM `v3_ledger` WHERE `userid` = ? GROUP BY `wallet`', [$f['userid']]);
    echo "<h4>This player, last " . MCC_LEDGER_DAYS . " days</h4><table width='60%' cellspacing='1' class='table'><tr><th>Account</th><th>In</th><th>Out</th><th>Lines</th></tr>";
    foreach ($sum as $r) {
        echo '<tr><td>' . e($r['wallet']) . '</td><td>' . number_format((int)$r['in']) . '</td><td>' . number_format((int)$r['out']) . "</td><td>{$r['n']}</td></tr>";
    }
    echo '</table>';
}
$h->endpage();
