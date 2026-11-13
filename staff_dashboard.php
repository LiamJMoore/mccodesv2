<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: the staff dashboard.
 */
global $db, $ir, $h, $set;
require_once('sglobals.php');
$admin = check_access('administrator');
$t = dash_totals();
$tile = fn (string $label, string $value, string $note = '', string $state = ''): string =>
    "<div class='dash-tile {$state}'><small>" . e($label) . '</small><b>' . e($value) . '</b>' . ($note !== '' ? '<span>' . e($note) . '</span>' : '') . '</div>';
echo '<h3>Dashboard</h3><div class="dash-tiles">'
    . $tile('Players', number_format($t['players']), '+' . number_format($t['new_today']) . ' today, +' . number_format($t['new_week']) . ' this week')
    . $tile('Online now', number_format($t['online']), 'in the last 15 minutes')
    . $tile('Active today', number_format($t['active_day']), number_format($t['active_week']) . ' this week')
    . $tile('Player reports', number_format($t['reports']), $t['reports'] > 0 ? 'waiting to be read' : 'none waiting', $t['reports'] > 0 ? 'warn' : '')
    . $tile('Money in the game', '$' . dash_short($t['all_money']), 'pockets, banks, vaults, bounties')
    . $tile('Crystals', dash_short($t['crystals']), 'in pockets')
    . $tile('In hospital / jail', $t['hospital'] . ' / ' . $t['jail'], $t['fedjailed'] . ' in federal jail')
    . $tile('Gangs', number_format($t['gangs']), '$' . dash_short($t['vaults']) . ' in vaults')
    . '</div>';
// integrity: figures that can only be wrong if something has gone wrong
$problems = $t['broken'] + $t['negative_inventory'] + $t['negative_vaults'];
echo "<div class='dash-check " . ($problems === 0 ? 'ok' : 'bad') . "'>" . ($problems === 0
        ? '<b>Books balance.</b> No player has a negative balance or bar, no inventory stack is below zero, no gang vault is overdrawn.'
        : "<b>{$problems} thing(s) need looking at:</b> {$t['broken']} player(s) with a negative balance or bar, {$t['negative_inventory']} inventory stack(s) below zero, {$t['negative_vaults']} overdrawn vault(s).") . '</div>';
$hist = dash_history(30);
$series = fn (string $col): array => array_map(fn ($r) => ['label' => date('j M', strtotime($r['day'])), 'value' => (float)$r[$col]], $hist);
echo "<h4>The last 30 days</h4><div class='dash-charts'>"
    . dash_chart('Money in the game', $series('money'), '#8B74FF', '$')
    . dash_chart('Players active each day', $series('active'), '#22D3EE')
    . dash_chart('Crystals in the game', $series('crystals'), '#8B74FF')
    . dash_chart('New players each day', $series('signups'), '#22D3EE')
    . '</div>';
if ($hist) {
    echo "<details class='dash-table'><summary>The same figures as a table</summary><table width='90%' cellspacing='1' class='table'><tr><th>Day</th><th>Players</th><th>Active</th><th>New</th><th>Money</th><th>Crystals</th></tr>";
    foreach (array_reverse($hist) as $r) {
        echo '<tr><td>' . date('j M Y', strtotime($r['day'])) . '</td><td>' . number_format((int)$r['players']) . '</td><td>' . number_format((int)$r['active']) . '</td><td>' . number_format((int)$r['signups'])
            . '</td><td>' . money_formatter((int)$r['money']) . '</td><td>' . number_format((int)$r['crystals']) . '</td></tr>';
    }
    echo '</table></details>';
}
echo "<h4>Today</h4><table width='70%' cellspacing='1' class='table'>";
foreach (dash_mods() as $label => $value) {
    echo '<tr><td>' . e($label) . '</td><td>' . number_format($value) . '</td></tr>';
}
echo '</table>';
if ($admin) {
    $w = dash_watch();
    echo "<h4>Worth a look</h4><p>Nothing here is proof of anything; these are the places cheating usually shows first.</p>
    <h5>Largest cash transfers in the last 24 hours</h5><table width='90%' cellspacing='1' class='table'><tr><th>From</th><th>To</th><th>Amount</th><th>When</th><th></th></tr>";
    foreach ($w['transfers'] as $x) {
        echo "<tr><td><a href='viewuser.php?u={$x['cxFROM']}'>" . e((string)$x['fname']) . "</a></td><td><a href='viewuser.php?u={$x['cxTO']}'>" . e((string)$x['tname']) . '</a></td><td>' . money_formatter((int)$x['cxAMOUNT'])
            . '</td><td>' . date('j M, H:i', (int)$x['cxTIME']) . '</td><td>' . ((int)$x['same_ip'] ? "<b class='dash-flag'>Same IP address</b>" : '') . '</td></tr>';
    }
    echo ($w['transfers'] ? '' : "<tr><td colspan='5'>None.</td></tr>") . "</table>
    <h5>Accounts active this week from the same IP address</h5><table width='90%' cellspacing='1' class='table'><tr><th>IP address</th><th>Accounts</th><th>Who</th></tr>";
    foreach ($w['shared_ips'] as $x) {
        echo '<tr><td>' . e($x['lastip']) . "</td><td>{$x['accounts']}</td><td>" . e((string)$x['who']) . '</td></tr>';
    }
    echo ($w['shared_ips'] ? '' : "<tr><td colspan='3'>None.</td></tr>") . "</table>
    <h5>Richest players</h5><table width='90%' cellspacing='1' class='table'><tr><th>Player</th><th>Level</th><th>Pocket and banks</th><th>Share of all money</th></tr>";
    foreach ($w['richest'] as $x) {
        $share = $t['all_money'] > 0 ? round((int)$x['worth'] / $t['all_money'] * 100, 1) : 0;
        echo "<tr><td><a href='viewuser.php?u={$x['userid']}'>" . e($x['username']) . "</a> [{$x['userid']}]</td><td>{$x['level']}</td><td>" . money_formatter((int)$x['worth']) . '</td><td>' . ($share >= 25 ? "<b class='dash-flag'>{$share}%</b>" : "{$share}%") . '</td></tr>';
    }
    echo "</table>
    <h5>Five or more failed logins in the last hour</h5><table width='60%' cellspacing='1' class='table'><tr><th>IP address</th><th>Failed tries</th></tr>";
    foreach ($w['failed_logins'] as $x) {
        echo '<tr><td>' . e($x['ip']) . "</td><td>{$x['tries']}</td></tr>";
    }
    echo ($w['failed_logins'] ? '' : "<tr><td colspan='2'>None.</td></tr>") . '</table>';
    $hl = dash_health();
    echo "<h4>System</h4><table width='90%' cellspacing='1' class='table'><tr><th>Timed job</th><th>Last ran</th><th></th></tr>";
    foreach ($hl['crons'] as $c) {
        echo '<tr><td>' . e($c['name']) . '</td><td>' . e((string)$c['last_run']) . '</td><td>' . ($c['late'] ? "<b class='dash-flag'>Overdue</b>" : 'On time') . '</td></tr>';
    }
    echo "</table><br /><table width='70%' cellspacing='1' class='table'>
    <tr><th>Timed jobs that failed in the last 24 hours</th><td>" . ($hl['cron_fails'] > 0 ? "<b class='dash-flag'>{$hl['cron_fails']}</b>" : '0') . "</td></tr>
    <tr><th>Engine</th><td>v{$hl['engine']}</td></tr>
    <tr><th>Database schema</th><td>step {$hl['schema']} of {$hl['schema_latest']}" . ($hl['schema'] < $hl['schema_latest'] ? " <b class='dash-flag'>behind</b>" : '') . "</td></tr>
    <tr><th>PHP</th><td>" . e($hl['php']) . '</td></tr><tr><th>Database</th><td>' . e($hl['database']) . '</td></tr></table>';
}
$h->endpage();
