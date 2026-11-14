<?php
declare(strict_types=1);

/**
 * Item crafting. A recipe turns a set of items (and, optionally, some money) into another item. Staff define the
 * recipes in the staff panel; players craft at craft.php.
 *
 * A craft is all or nothing: every ingredient and the fee are taken, and the result given, or nothing changes.
 */

/** Every recipe, each with its ingredients: [..., 'parts' => [['item' => , 'qty' => , 'itmname' => ], ...]]. */
function craft_recipes(bool $only_enabled = true): array
{
    global $db;
    $recipes = [];
    foreach ($db->all('SELECT `r`.*, `i`.`itmname` AS `result_name` FROM `v3_recipes` AS `r` LEFT JOIN `items` AS `i` ON `i`.`itmid` = `r`.`result_item`'
        . ($only_enabled ? ' WHERE `r`.`enabled` = 1' : '') . ' ORDER BY `r`.`min_level`, `r`.`name`') as $r) {
        $r['parts'] = [];
        $recipes[(int)$r['id']] = $r;
    }
    if ($recipes) {
        foreach ($db->all('SELECT `p`.`recipe`, `p`.`item`, `p`.`qty`, `i`.`itmname` FROM `v3_recipe_parts` AS `p` LEFT JOIN `items` AS `i` ON `i`.`itmid` = `p`.`item` ORDER BY `i`.`itmname`') as $p) {
            if (isset($recipes[(int)$p['recipe']])) {
                $recipes[(int)$p['recipe']]['parts'][] = $p;
            }
        }
    }
    return $recipes;
}

/** How many of each item a player holds: [item id => quantity]. */
function craft_holdings(int $userid): array
{
    global $db;
    $out = [];
    foreach ($db->all('SELECT `inv_itemid`, SUM(`inv_qty`) AS `qty` FROM `inventory` WHERE `inv_userid` = ? GROUP BY `inv_itemid`', [$userid]) as $r) {
        $out[(int)$r['inv_itemid']] = (int)$r['qty'];
    }
    return $out;
}

/**
 * Craft a recipe $times times. Returns null when made, or the reason it was not, in words for the player.
 */
function craft_make(int $userid, int $recipe_id, int $level, int $times = 1): ?string
{
    global $db;
    $times = max(1, min($times, 100));
    $recipe = craft_recipes()[$recipe_id] ?? null;
    if ($recipe === null || !$recipe['parts'] || $recipe['result_name'] === null) {
        return 'There is no such recipe.';
    }
    if ($level < (int)$recipe['min_level']) {
        return "You need to be level {$recipe['min_level']} to make that.";
    }
    $short = '';
    $made = $db->attempt(function () use ($userid, $recipe, $times, &$short) {
        if ((int)$recipe['cost'] > 0 && !wallet_take($userid, 'money', (int)$recipe['cost'] * $times)) {
            $short = 'You do not have ' . money_formatter((int)$recipe['cost'] * $times) . ' for the fee.';
            return false;
        }
        foreach ($recipe['parts'] as $p) {
            if (!item_take($userid, (int)$p['item'], (int)$p['qty'] * $times)) {
                $short = 'You do not have ' . ((int)$p['qty'] * $times) . ' x ' . $p['itmname'] . '.';
                return false;
            }
        }
        item_add($userid, (int)$recipe['result_item'], (int)$recipe['result_qty'] * $times);
        return true;
    });
    if (!$made) {
        return $short !== '' ? $short : 'That could not be made.';
    }
    if (function_exists('progress_add')) {
        progress_add($userid, 'crafted', (int)$recipe['result_qty'] * $times);
    }
    return null;
}

/**
 * Save a recipe (staff). $parts is [item id => quantity]. Returns the recipe's id, or a string saying what is wrong.
 */
function craft_save(?int $id, string $name, int $result_item, int $result_qty, int $min_level, int $cost, bool $enabled, array $parts): int|string
{
    global $db;
    $name = trim($name);
    $parts = array_filter(array_map('intval', $parts), fn ($q) => $q > 0);
    if ($name === '' || strlen($name) > 100) {
        return 'Give the recipe a name of up to 100 characters.';
    }
    if ($result_qty < 1 || $db->value('SELECT `itmid` FROM `items` WHERE `itmid` = ?', [$result_item]) === null) {
        return 'Choose the item the recipe makes, and how many (at least 1).';
    }
    if (!$parts) {
        return 'A recipe needs at least one ingredient.';
    }
    foreach (array_keys($parts) as $item) {
        if ($db->value('SELECT `itmid` FROM `items` WHERE `itmid` = ?', [(int)$item]) === null) {
            return 'One of the ingredients is not an item in the game.';
        }
    }
    if (isset($parts[$result_item]) && $parts[$result_item] <= $result_qty) {
        return 'That recipe makes at least as many of an item as it uses: it would create items from nothing.';
    }
    $db->transaction(function () use ($db, &$id, $name, $result_item, $result_qty, $min_level, $cost, $enabled, $parts) {
        $row = ['name' => $name, 'result_item' => $result_item, 'result_qty' => $result_qty, 'min_level' => max(0, $min_level), 'cost' => max(0, $cost), 'enabled' => $enabled ? 1 : 0];
        if ($id === null) {
            $id = $db->insert('v3_recipes', $row);
        } else {
            $db->exec('UPDATE `v3_recipes` SET `name` = ?, `result_item` = ?, `result_qty` = ?, `min_level` = ?, `cost` = ?, `enabled` = ? WHERE `id` = ?', [...array_values($row), $id]);
            $db->exec('DELETE FROM `v3_recipe_parts` WHERE `recipe` = ?', [$id]);
        }
        foreach ($parts as $item => $qty) {
            $db->insert('v3_recipe_parts', ['recipe' => $id, 'item' => (int)$item, 'qty' => $qty]);
        }
    });
    return (int)$id;
}

function craft_delete(int $id): void
{
    global $db;
    $db->transaction(function () use ($db, $id) {
        $db->exec('DELETE FROM `v3_recipe_parts` WHERE `recipe` = ?', [$id]);
        $db->exec('DELETE FROM `v3_recipes` WHERE `id` = ?', [$id]);
    });
}
