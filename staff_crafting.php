<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: staff, crafting recipes.
 */
global $db, $ir, $h;
require_once('sglobals.php');
if (!check_access('manage_items')) {
    echo 'You cannot access this area.<br />&gt; <a href="index.php">Go Home</a>';
    $h->endpage();
    exit;
}
echo '<h3>Crafting Recipes</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['save', 'delete'], '', $_POST);
    staff_csrf_stdverify('staff_crafting', 'staff_crafting.php');
    if ($do === 'save') {
        $parts = [];
        foreach ((array)($_POST['part_item'] ?? []) as $i => $item) {
            $item = (int)$item;
            $qty = (int)($_POST['part_qty'][$i] ?? 0);
            if ($item > 0 && $qty > 0) {
                $parts[$item] = ($parts[$item] ?? 0) + $qty;
            }
        }
        $id = in_int('id', 0, $_POST) ?: null;
        $saved = craft_save($id, in_str('name', 100, '', $_POST), in_int('result_item', 0, $_POST), in_int('result_qty', 0, $_POST), in_int('min_level', 0, $_POST), in_int('cost', 0, $_POST),
            !empty($_POST['enabled']), $parts);
        if (is_string($saved)) {
            echo '<p><b>Not saved.</b> ' . e($saved) . '</p>';
        } else {
            echo '<p><b>Recipe saved.</b></p>';
            stafflog_add(($id === null ? 'Created' : 'Edited') . ' crafting recipe ' . in_str('name', 100, '', $_POST));
        }
    } elseif ($do === 'delete') {
        craft_delete(in_int('id', 0, $_POST));
        echo '<p><b>Recipe deleted.</b></p>';
        stafflog_add('Deleted crafting recipe #' . in_int('id', 0, $_POST));
    }
}
$recipes = craft_recipes(false);
$items = $db->all('SELECT `itmid`, `itmname` FROM `items` ORDER BY `itmname`');
$token = request_csrf_html('staff_crafting');
$pick = function (string $name, int $selected) use ($items): string {
    $out = "<select name='{$name}'><option value='0'>-</option>";
    foreach ($items as $i) {
        $out .= "<option value='{$i['itmid']}'" . ((int)$i['itmid'] === $selected ? " selected='selected'" : '') . '>' . e($i['itmname']) . " [{$i['itmid']}]</option>";
    }
    return $out . '</select>';
};
$form = function (?array $r) use ($pick, $token): string {
    $out = "<form action='staff_crafting.php' method='post'>{$token}<input type='hidden' name='do' value='save' /><input type='hidden' name='id' value='" . ($r['id'] ?? 0) . "' />
    <table cellspacing='1' class='table' width='90%'>
    <tr><td>Name</td><td><input type='text' name='name' value='" . e((string)($r['name'] ?? '')) . "' size='40' maxlength='100' /></td></tr>
    <tr><td>Makes</td><td><input type='text' name='result_qty' value='" . ($r['result_qty'] ?? 1) . "' size='4' /> x " . $pick('result_item', (int)($r['result_item'] ?? 0)) . '</td></tr>
    <tr><td>Needs</td><td>';
    $parts = $r['parts'] ?? [];
    for ($i = 0; $i < max(4, count($parts) + 2); $i++) {
        $out .= "<input type='text' name='part_qty[]' value='" . ($parts[$i]['qty'] ?? '') . "' size='4' /> x " . $pick('part_item[]', (int)($parts[$i]['item'] ?? 0)) . '<br />';
    }
    return $out . "</td></tr>
    <tr><td>Fee</td><td>\$<input type='text' name='cost' value='" . ($r['cost'] ?? 0) . "' size='10' /></td></tr>
    <tr><td>Minimum level</td><td><input type='text' name='min_level' value='" . ($r['min_level'] ?? 0) . "' size='4' /></td></tr>
    <tr><td>Available to players</td><td><input type='checkbox' name='enabled' value='1'" . (($r['enabled'] ?? 1) ? " checked='checked'" : '') . " /></td></tr>
    <tr><td colspan='2' align='center'><input type='submit' value='" . ($r === null ? 'Add recipe' : 'Save changes') . "' /></td></tr></table></form>";
};
$edit = in_int('edit', 0, $_GET);
if ($edit && isset($recipes[$edit])) {
    echo '<h4>Edit: ' . e($recipes[$edit]['name']) . '</h4>' . $form($recipes[$edit]) . "<p>&gt; <a href='staff_crafting.php'>Back to all recipes</a></p>";
} else {
    if ($recipes) {
        echo "<table cellspacing='1' class='table' width='90%'><tr><th>Recipe</th><th>Makes</th><th>Needs</th><th>Fee</th><th>Level</th><th>On</th><th></th></tr>";
        foreach ($recipes as $r) {
            $needs = implode(', ', array_map(fn ($p) => "{$p['qty']} x " . e((string)$p['itmname']), $r['parts']));
            echo '<tr><td>' . e($r['name']) . "</td><td>{$r['result_qty']} x " . e((string)$r['result_name']) . "</td><td>{$needs}</td><td>" . money_formatter((int)$r['cost']) . "</td><td>{$r['min_level']}</td><td>"
                . ($r['enabled'] ? 'Yes' : 'No') . "</td><td><a href='staff_crafting.php?edit={$r['id']}'>Edit</a>
                <form action='staff_crafting.php' method='post' style='display:inline;'>{$token}<input type='hidden' name='do' value='delete' /><input type='hidden' name='id' value='{$r['id']}' /><input type='submit' value='Delete' /></form></td></tr>";
        }
        echo '</table>';
    } else {
        echo '<p>There are no recipes yet.</p>';
    }
    echo '<h4>Add a recipe</h4>' . $form(null);
}
$h->endpage();
