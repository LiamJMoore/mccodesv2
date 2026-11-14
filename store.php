<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: the Store (crystal packs, paid for through Stripe Checkout: Apple Pay, Google Pay, cards).
 */
global $db, $ir, $userid, $h, $set;
require_once('globals.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pack = in_choice('pack', array_keys(MCC_STORE_PACKS), '', $_POST);
    if (verify_csrf_code('store_buy', in_str('verf', 64, '', $_POST)) && $pack !== '') {
        $url = store_checkout($userid, $pack, 'https://' . determine_game_urlbase());
        if ($url !== null) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Location: ' . $url, true, 303);
            exit;
        }
        echo '<h3>Store</h3><p><b>The payment page could not be opened.</b> Nothing has been charged. Please try again in a moment.</p>';
    } else {
        echo '<h3>Store</h3><p>That form had expired. Please try again.</p>';
    }
} else {
    echo '<h3>Store</h3>';
}
if (isset($_GET['paid'])) {
    echo "<div class='store-note ok'><b>Thank you!</b> Your payment is being confirmed. Your crystals arrive within a minute or so, and you will get an event when they do.</div>";
} elseif (isset($_GET['cancelled'])) {
    echo "<div class='store-note'>Payment cancelled. Nothing was charged.</div>";
}
if (!store_ready()) {
    echo '<p>The store is not open yet.</p>';
    if ($ir['user_level'] == 2) {
        echo "<p><b>Staff note:</b> add <code>\$_CONFIG['stripe_secret']</code> and <code>\$_CONFIG['stripe_webhook_secret']</code> to config.php. The steps are at the top of engine/mods/Store.php.</p>";
    }
    $h->endpage();
    exit;
}
$token = request_csrf_html('store_buy');
echo "<p>Crystals buy the Season Pass premium track, energy refills, IQ and more. Pay with <b>Apple Pay</b>, <b>Google Pay</b> or a card on a secure page run by Stripe; the game never sees your card.</p>
<div class='store-packs'>";
$base = MCC_STORE_PACKS['starter']['price'] / MCC_STORE_PACKS['starter']['crystals'];
foreach (MCC_STORE_PACKS as $key => $p) {
    $extra = (int)round((($base * $p['crystals']) / $p['price'] - 1) * 100);
    echo "<form class='store-pack' action='store.php' method='post'>{$token}<input type='hidden' name='pack' value='{$key}' />
    <small>" . e($p['name']) . "</small><b>" . number_format($p['crystals']) . " crystals</b>
    <span>+ {$p['donator_days']} days donator status</span>" . ($extra > 0 ? "<em>{$extra}% more crystals per pound</em>" : '<em>&nbsp;</em>') . "
    <input type='submit' value='Buy for " . store_price($p['price']) . "' /></form>";
}
echo "</div>
<p><small>Purchases are for use in this game only and have no cash value. If something goes wrong with a payment, mail a member of staff with the time you paid.</small></p>";
$mine = $db->all("SELECT `pack`, `amount`, `crystals`, `created`, `status` FROM `v3_payments` WHERE `userid` = ? ORDER BY `id` DESC LIMIT 10", [$userid]);
if ($mine) {
    echo "<h4>Your purchases</h4><table width='75%' cellspacing='1' class='table'><tr><th>When</th><th>Pack</th><th>Crystals</th><th>Paid</th><th></th></tr>";
    foreach ($mine as $m) {
        echo '<tr><td>' . date('j M Y, H:i', (int)$m['created']) . '</td><td>' . e(MCC_STORE_PACKS[$m['pack']]['name'] ?? $m['pack']) . '</td><td>' . number_format((int)$m['crystals']) . '</td><td>' . store_price((int)$m['amount'])
            . '</td><td>' . ($m['status'] === 'refunded' ? 'Refunded' : 'Delivered') . '</td></tr>';
    }
    echo '</table>';
}
$h->endpage();
