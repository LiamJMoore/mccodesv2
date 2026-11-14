<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: fame and reputation.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
$f = fame_of($userid);
$next = fame_next($f['total']);
echo "<h3>Fame</h3><p>Fame is how well known you are. It comes from what you do, cannot be bought or sent, and buys nothing: it is for showing off.</p>
<div class='season-head'><div><small>Your fame</small><b>" . number_format($f['total']) . '</b></div><div><small>Reputation</small><b>' . e(fame_reputation($f['total'])) . '</b></div><div><small>Next</small><b>'
    . ($next === null ? 'At the top' : e($next['name']) . ' in ' . number_format($next['need'])) . "</b></div></div>
<table width='100%' cellspacing='1' class='table'><tr><th>From</th><th>You have</th><th>Fame each</th><th>Fame</th></tr>";
foreach ($f['parts'] as [$label, $n, $each, $fame]) {
    echo '<tr><td>' . e($label) . '</td><td>' . number_format($n) . "</td><td>{$each}</td><td>" . number_format($fame) . '</td></tr>';
}
echo "</table><h4>The most famous</h4><table width='100%' cellspacing='1' class='table'><tr><th>#</th><th>Player</th><th>Level</th><th>Fame</th><th>Reputation</th></tr>";
$top = fame_top(20);
foreach ($top as $i => $t) {
    echo '<tr' . ((int)$t['userid'] === $userid ? " class='me'" : '') . '><td>' . ($i + 1) . "</td><td><a href='viewuser.php?u={$t['userid']}'>" . e((string)$t['username']) . "</a></td><td>{$t['level']}</td><td>" . number_format((int)$t['fame'])
        . '</td><td>' . e(fame_reputation((int)$t['fame'])) . '</td></tr>';
}
echo ($top ? '' : "<tr><td colspan='5'>Nobody is famous yet.</td></tr>") . '</table>';
$h->endpage();
