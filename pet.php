<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: your pet, and the races.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>Your Pet</h3>';
$ordinal = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th', 6 => '6th'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['adopt', 'feed', 'train', 'race'], '', $_POST);
    if (!verify_csrf_code('pet', in_str('verf', 64, '', $_POST)) || $do === '') {
        echo '<p>That form had expired. Please try again.</p>';
    } elseif ($do === 'adopt') {
        $why = pet_adopt($userid, in_str('type', 20, '', $_POST), in_str('name', 40, '', $_POST));
        echo $why === null ? '<p><b>Welcome home!</b> Look after it.</p>' : '<p><b>Not adopted.</b> ' . e($why) . '</p>';
    } elseif ($do === 'feed') {
        $why = pet_feed($userid);
        echo $why === null ? '<p><b>Fed.</b></p>' : '<p>' . e($why) . '</p>';
    } elseif ($do === 'train') {
        $r = pet_train($userid);
        echo is_string($r) ? '<p>' . e($r) . '</p>' : "<p><b>A good session.</b> +{$r['gain']} {$r['stat']}." . ($r['levelled'] ? ' <b>Your pet has gone up a level!</b>' : '') . '</p>';
    } elseif ($do === 'race') {
        $r = pet_race($userid);
        if (is_string($r)) {
            echo '<p>' . e($r) . '</p>';
        } else {
            echo '<p><b>' . ($r['place'] === 1 ? 'A win!' : 'Finished ' . $ordinal[$r['place']] . '.') . '</b> Entry ' . money_formatter($r['fee']) . ', prize ' . money_formatter($r['prize']) . '.</p><ol>';
            foreach ($r['field'] as $f) {
                echo '<li>' . ($f['you'] ? '<b>' . e($f['name']) . ' (yours)</b>' : e($f['name'])) . '</li>';
            }
            echo '</ol>';
        }
    }
}
$p = pet_of($userid);
$token = request_csrf_html('pet');
$button = fn (string $do, string $label): string => "<form action='pet.php' method='post' style='display:inline;margin:0 4px;'>{$token}<input type='hidden' name='do' value='{$do}' /><input type='submit' value='" . e($label) . "' /></form>";
if ($p === null) {
    echo '<p>Adopt an animal, feed it, train it, and race it. Adopting costs ' . money_formatter(MCC_PET_PRICE) . ". You can have one.</p>
    <form action='pet.php' method='post'>{$token}<input type='hidden' name='do' value='adopt' />
    <table cellspacing='1' class='table'><tr><td>Animal</td><td><select name='type'>";
    foreach (MCC_PET_TYPES as $k => $label) {
        echo "<option value='{$k}'>" . e($label) . '</option>';
    }
    echo "</select></td></tr><tr><td>Name</td><td><input type='text' name='name' maxlength='20' /></td></tr>
    <tr><td colspan='2' align='center'><input type='submit' value='Adopt for " . money_formatter(MCC_PET_PRICE) . "' /></td></tr></table></form>";
} else {
    $raced = perk_used_today($userid, 'pet_race');
    echo '<p><b>' . e($p['name']) . '</b> the ' . e(MCC_PET_TYPES[$p['type']] ?? 'animal') . "</p>
    <div class='season-head'><div><small>Level</small><b>{$p['level']}</b></div><div><small>Speed</small><b>{$p['speed']}</b></div><div><small>Stamina</small><b>{$p['stamina']}</b></div>
    <div><small>Fed</small><b>{$p['full']}%</b></div><div><small>Races won</small><b>{$p['wins']} of {$p['races']}</b></div></div>
    <div class='season-bar'><i style='width:" . min(100, (int)$p['xp']) . "%'></i></div><p><small>" . min(MCC_PET_XP_PER_LEVEL, (int)$p['xp']) . ' / ' . MCC_PET_XP_PER_LEVEL . ' experience to the next level</small></p><p>'
        . ($p['full'] >= 95 ? 'Not hungry' : $button('feed', 'Feed (' . html_entity_decode(money_formatter(MCC_PET_FEED_PRICE)) . ')'))
        . ($p['can_train_in'] > 0 ? ' Resting for ' . (int)ceil($p['can_train_in'] / 60) . ' more minutes ' : $button('train', 'Train (' . MCC_PET_TRAIN_ENERGY . ' energy)'))
        . ($raced >= MCC_PET_RACES_A_DAY ? " Today's races are run" : $button('race', 'Race (entry ' . html_entity_decode(money_formatter((int)$p['fee'])) . ')')) . "</p>
    <p><small>Races today: {$raced} of " . MCC_PET_RACES_A_DAY . '. Six run; first pays ' . MCC_PET_PRIZES[1] . ' times the entry, second ' . MCC_PET_PRIZES[2] . ', third ' . MCC_PET_PRIZES[3]
        . '. A pet that is at least 70% fed runs better. Below 30% it will not train or race.</small></p>';
}
$top = pets_top();
if ($top) {
    echo "<h4>Champions</h4><table width='80%' cellspacing='1' class='table'><tr><th>#</th><th>Pet</th><th>Owner</th><th>Level</th><th>Wins</th></tr>";
    foreach ($top as $i => $t) {
        echo '<tr><td>' . ($i + 1) . '</td><td>' . e($t['name']) . ' <small>' . e(MCC_PET_TYPES[$t['type']] ?? '') . "</small></td><td><a href='viewuser.php?u={$t['userid']}'>" . perk_name((int)$t['userid'], (string)$t['username'])
            . "</a></td><td>{$t['level']}</td><td>{$t['wins']} of {$t['races']}</td></tr>";
    }
    echo '</table>';
}
$h->endpage();
