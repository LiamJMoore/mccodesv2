<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: a player's own money and crystal history.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
$wallet = in_choice('w', MCC_WALLETS, '', $_GET);
$page = max(1, in_int('p', 1, $_GET));
$lines = ledger_lines(['userid' => $userid, 'wallet' => $wallet], 51, ($page - 1) * 50);
$more = count($lines) > 50;
$names = ['money' => 'Cash', 'crystals' => 'Crystals', 'bankmoney' => 'Bank', 'cybermoney' => 'Cyber Bank'];
echo '<h3>Your Statement</h3><p>Every change to your cash, bank and crystals for the last ' . MCC_LEDGER_DAYS . ' days. Only you and staff can see it.</p><p>';
echo $wallet === '' ? '<b>Everything</b>' : "<a href='ledger.php'>Everything</a>";
foreach ($names as $k => $label) {
    echo ' &middot; ' . ($wallet === $k ? "<b>{$label}</b>" : "<a href='ledger.php?w={$k}'>{$label}</a>");
}
echo "</p><table width='100%' cellspacing='1' class='table'><tr><th>When</th><th>What</th><th>Account</th><th>Change</th></tr>";
foreach (array_slice($lines, 0, 50) as $l) {
    $d = (int)$l['delta'];
    $amount = $l['wallet'] === 'crystals' ? number_format(abs($d)) . ' crystals' : money_formatter(abs($d));
    echo '<tr><td>' . date('j M, H:i', (int)$l['at']) . '</td><td>' . e(ledger_label($l['page'], $l['note'])) . '</td><td>' . $names[$l['wallet']] . '</td><td>'
        . ($d > 0 ? "<span class='amt-in'>+ {$amount}</span>" : "<span class='amt-out'>&minus; {$amount}</span>") . '</td></tr>';
}
if (!$lines) {
    echo "<tr><td colspan='4'>Nothing yet.</td></tr>";
}
echo '</table><p>' . ($page > 1 ? "<a href='ledger.php?w={$wallet}&amp;p=" . ($page - 1) . "'>&laquo; Newer</a> " : '') . ($more ? "<a href='ledger.php?w={$wallet}&amp;p=" . ($page + 1) . "'>Older &raquo;</a>" : '') . '</p>';
$h->endpage();
