<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: item crafting.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>Workshop</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_code('craft_make', in_str('verf', 64, '', $_POST))) {
        echo '<p>That form had expired. Please try again.</p>';
    } else {
        $times = max(1, in_int('times', 1, $_POST));
        $recipe = craft_recipes()[in_int('recipe', 0, $_POST)] ?? null;
        $why = craft_make($userid, in_int('recipe', 0, $_POST), (int)$ir['level'], $times);
        echo $why === null
            ? '<p><b>Made!</b> You now have ' . ((int)$recipe['result_qty'] * min($times, 100)) . ' more x ' . e((string)$recipe['result_name']) . '.</p>'
            : '<p><b>Not made.</b> ' . e($why) . '</p>';
    }
}
$recipes = craft_recipes();
if (!$recipes) {
    echo '<p>The workshop has no recipes yet.</p>';
    $h->endpage();
    exit;
}
$have = craft_holdings($userid);
$money = (int)$db->value('SELECT `money` FROM `users` WHERE `userid` = ?', [$userid]);
$token = request_csrf_html('craft_make');
echo "<p>Turn items you hold into something better. Everything a recipe needs is used up when you make it.</p>
<table width='95%' cellspacing='1' class='table'><tr><th>Makes</th><th>Needs</th><th>Fee</th><th>Level</th><th></th></tr>";
foreach ($recipes as $r) {
    if (!$r['parts'] || $r['result_name'] === null) {
        continue;
    }
    $can = (int)$ir['level'] >= (int)$r['min_level'] && $money >= (int)$r['cost'];
    $needs = [];
    foreach ($r['parts'] as $p) {
        $held = $have[(int)$p['item']] ?? 0;
        $ok = $held >= (int)$p['qty'];
        $can = $can && $ok;
        $needs[] = "<span style='color:" . ($ok ? '#4caf50' : '#e57373') . ";'>{$p['qty']} x " . e((string)$p['itmname']) . "</span> (you have {$held})";
    }
    echo '<tr><td><b>' . e($r['name']) . "</b><br />{$r['result_qty']} x <a href='iteminfo.php?ID={$r['result_item']}'>" . e((string)$r['result_name']) . '</a></td><td>' . implode('<br />', $needs) . '</td><td>'
        . ((int)$r['cost'] > 0 ? money_formatter((int)$r['cost']) : 'Free') . "</td><td>{$r['min_level']}</td><td>"
        . ($can ? "<form action='craft.php' method='post' style='margin:0;'>{$token}<input type='hidden' name='recipe' value='{$r['id']}' /><input type='text' name='times' value='1' size='3' /> <input type='submit' value='Make' /></form>"
                : ((int)$ir['level'] < (int)$r['min_level'] ? 'Level too low' : 'Missing something')) . '</td></tr>';
}
echo '</table>';
$h->endpage();
