<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: the Crystal Shop.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>Crystal Shop</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['refill', 'colour', 'badge', 'show', 'mission'], '', $_POST);
    if (!verify_csrf_code('crystalshop', in_str('verf', 64, '', $_POST))) {
        echo '<p>That form had expired. Please try again.</p>';
    } else {
        $what = in_str('what', 32, '', $_POST);
        $why = match ($do) {
            'refill' => perk_refill($userid, $what),
            'colour' => perk_colour($userid, $what),
            'badge' => perk_badge_buy($userid, $what),
            'show' => perk_badge_show($userid, $what) ? null : 'You do not own that badge.',
            'mission' => perk_extra_mission($userid, (int)$ir['level']),
            default => 'Nothing was chosen.',
        };
        echo $why === null ? '<p><b>Done.</b></p>' : '<p><b>Not done.</b> ' . e($why) . '</p>';
    }
}
$me = $db->row('SELECT `crystals`, `brave`, `maxbrave`, `will`, `maxwill`, `hp`, `maxhp`, `username` FROM `users` WHERE `userid` = ?', [$userid]);
$GLOBALS['mcc_perks_stale'][$userid] = true;
$own = perks_of($userid);
$token = request_csrf_html('crystalshop');
$button = fn (string $do, string $what, string $label): string =>
    "<form action='crystalshop.php' method='post' style='margin:0;display:inline;'>{$token}<input type='hidden' name='do' value='{$do}' /><input type='hidden' name='what' value='" . e($what) . "' /><input type='submit' value='" . e($label) . "' /></form>";
echo "<p>You have <b>{$me['crystals']}</b> crystals. <a href='store.php'>Get more</a></p>
<p>You look like this: " . perk_name($userid, (string)$me['username']) . "</p>
<h4>Refills</h4><table width='90%' cellspacing='1' class='table'><tr><th>Refill</th><th>What it does</th><th>Price</th><th>Today</th><th></th></tr>";
foreach (MCC_PERK_REFILLS as $bar => $r) {
    $used = perk_used_today($userid, 'refill:' . $bar);
    $full = (int)$me[$bar] >= (int)$me['max' . $bar];
    echo '<tr><td>' . e($r['name']) . '</td><td>' . e($r['what']) . "</td><td>{$r['price']} crystals</td><td>{$used} of {$r['a_day']}</td><td>"
        . ($used >= $r['a_day'] ? 'More tomorrow' : ($full ? 'Bar is full' : $button('refill', $bar, 'Buy'))) . '</td></tr>';
}
echo "</table><h4>Name colour</h4><p>" . (isset($own['name_colour']) ? 'You own a name colour. Changing it is free.' : 'Pick a colour for your name, shown on your profile, the hitlist and the leaderboards. ' . MCC_PERK_COLOUR_PRICE . ' crystals, once; changing it afterwards is free.') . '</p><p>';
foreach (MCC_PERK_COLOURS as $name => $hex) {
    echo "<span style='display:inline-block;margin:4px 8px;'><span style='color:{$hex};font-weight:bold;'>" . e((string)$me['username']) . '</span><br />' . $button('colour', $name, $name) . '</span>';
}
echo "</p><h4>Badges</h4><table width='90%' cellspacing='1' class='table'><tr><th>Badge</th><th>Price</th><th></th></tr>";
foreach (MCC_PERK_BADGES as $key => $b) {
    $owned = isset($own['badge:' . $key]);
    $shown = ($own['badge_shown'] ?? '') === $key;
    echo "<tr><td><span class='perk-badge'>" . e($b['name']) . "</span></td><td>{$b['price']} crystals</td><td>"
        . ($owned ? ($shown ? 'Showing ' . $button('show', '', 'Hide') : $button('show', $key, 'Show this one')) : $button('badge', $key, 'Buy')) . '</td></tr>';
}
$today = missions_today($userid, (int)$ir['level']);
echo "</table><h4>Extra mission</h4><p>A fourth mission for today, with its own reward. " . MCC_PERK_MISSION_PRICE . ' crystals, once a day. '
    . (count($today) > MCC_MISSIONS_A_DAY ? "You have today's. <a href='missions.php'>Missions</a>" : $button('mission', '', 'Add a mission')) . '</p>';
$h->endpage();
