<?php
declare(strict_types=1);

/**
 * The three lists a player keeps of other players: friends, enemies (the black list) and mail contacts.
 * They use the tables the game has always had. One piece of code serves all three pages.
 *
 * Also here: the mugger of the hour.
 */
const MCC_LISTS = [
    'friends' => ['table' => 'friendslist', 'p' => 'fl', 'counter' => 'friend_count', 'comment' => true, 'page' => 'friendslist.php', 'title' => 'Friends List', 'one' => 'friend', 'donator' => true],
    'enemies' => ['table' => 'blacklist', 'p' => 'bl', 'counter' => 'enemy_count', 'comment' => true, 'page' => 'blacklist.php', 'title' => 'Black List', 'one' => 'enemy', 'donator' => true],
    'contacts' => ['table' => 'contactlist', 'p' => 'cl', 'counter' => null, 'comment' => false, 'page' => 'contactlist.php', 'title' => 'Mail Contacts', 'one' => 'contact', 'donator' => false],
];
const MCC_LIST_MAX = 200;                 // entries in one list
const MCC_ONLINE_SECONDS = 900;

function contact_comment_clean(string $text): string
{
    return substr(trim((string)preg_replace('/\s+/', ' ', strip_tags($text))), 0, 255);
}

/** Add a player to a list. Null when added, or a string saying why not. */
function contact_add(string $list, int $userid, int $who, string $comment = ''): ?string
{
    global $db;
    $L = MCC_LISTS[$list];
    if ($who === $userid) {
        return 'You cannot add yourself.';
    }
    $why = 'There is no such player.';
    $db->attempt(function () use ($db, $L, $userid, $who, $comment, &$why) {
        // the player's own row is the lock: two adds at once take turns, so nobody is listed twice
        $db->value('SELECT `userid` FROM `users` WHERE `userid` = ? FOR UPDATE', [$userid]);
        if ($db->value('SELECT `userid` FROM `users` WHERE `userid` = ?', [$who]) === null) {
            return false;
        }
        $have = $db->all("SELECT `{$L['p']}_ADDED` AS `who` FROM `{$L['table']}` WHERE `{$L['p']}_ADDER` = ?", [$userid]);
        if (in_array($who, array_map('intval', array_column($have, 'who')), true)) {
            $why = 'They are already on this list.';
            return false;
        }
        if (count($have) >= MCC_LIST_MAX) {
            $why = 'This list is full (' . MCC_LIST_MAX . ').';
            return false;
        }
        $row = ["{$L['p']}_ADDER" => $userid, "{$L['p']}_ADDED" => $who];
        if ($L['comment']) {
            $row["{$L['p']}_COMMENT"] = contact_comment_clean($comment);
        }
        $db->insert($L['table'], $row);
        if ($L['counter'] !== null) {
            $db->exec("UPDATE `users` SET `{$L['counter']}` = `{$L['counter']}` + 1 WHERE `userid` = ?", [$who]);
        }
        $why = null;
        return true;
    });
    return $why;
}

/** Take an entry off the player's own list. True if it was there. */
function contact_remove(string $list, int $userid, int $entry): bool
{
    global $db;
    $L = MCC_LISTS[$list];
    return $db->attempt(function () use ($db, $L, $userid, $entry) {
        $who = $db->value("SELECT `{$L['p']}_ADDED` FROM `{$L['table']}` WHERE `{$L['p']}_ID` = ? AND `{$L['p']}_ADDER` = ? FOR UPDATE", [$entry, $userid]);
        if ($who === null || $db->exec("DELETE FROM `{$L['table']}` WHERE `{$L['p']}_ID` = ? AND `{$L['p']}_ADDER` = ?", [$entry, $userid]) !== 1) {
            return false;
        }
        if ($L['counter'] !== null) {
            $db->exec("UPDATE `users` SET `{$L['counter']}` = GREATEST(`{$L['counter']}` - 1, 0) WHERE `userid` = ?", [(int)$who]);
        }
        return true;
    });
}

function contact_note(string $list, int $userid, int $entry, string $comment): bool
{
    global $db;
    $L = MCC_LISTS[$list];
    return $L['comment'] && $db->value("SELECT 1 FROM `{$L['table']}` WHERE `{$L['p']}_ID` = ? AND `{$L['p']}_ADDER` = ?", [$entry, $userid]) !== null
        && $db->exec("UPDATE `{$L['table']}` SET `{$L['p']}_COMMENT` = ? WHERE `{$L['p']}_ID` = ? AND `{$L['p']}_ADDER` = ?", [contact_comment_clean($comment), $entry, $userid]) >= 0;
}

function contact_rows(string $list, int $userid): array
{
    global $db;
    $L = MCC_LISTS[$list];
    $comment = $L['comment'] ? "`l`.`{$L['p']}_COMMENT`" : "''";
    return $db->all("SELECT `l`.`{$L['p']}_ID` AS `entry`, {$comment} AS `comment`, `u`.`userid`, `u`.`username`, `u`.`level`, `u`.`laston`, `u`.`hospital`, `u`.`jail`
        FROM `{$L['table']}` AS `l` INNER JOIN `users` AS `u` ON `u`.`userid` = `l`.`{$L['p']}_ADDED` WHERE `l`.`{$L['p']}_ADDER` = ? ORDER BY `u`.`username`", [$userid]);
}

/** A whole list page: handles its forms and prints the list. */
function contact_page(string $list, array $ir): void
{
    global $db;
    $L = MCC_LISTS[$list];
    $userid = (int)$ir['userid'];
    $page = $L['page'];
    echo '<h3>' . e($L['title']) . '</h3>';
    if ($L['donator'] && (int)$ir['donatordays'] === 0) {
        echo '<p>This feature is for donators only.</p>';
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $do = in_choice('do', ['add', 'remove', 'note'], '', $_POST);
        if (!verify_csrf_code('list_' . $list, in_str('verf', 64, '', $_POST)) || $do === '') {
            echo '<p>That form had expired. Please try again.</p>';
        } elseif ($do === 'add') {
            $why = contact_add($list, $userid, in_int('who', 0, $_POST), in_str('comment', 1000, '', $_POST));
            echo $why === null ? '<p><b>Added.</b></p>' : '<p><b>Not added.</b> ' . e($why) . '</p>';
        } elseif ($do === 'remove') {
            echo contact_remove($list, $userid, in_int('entry', 0, $_POST)) ? '<p><b>Removed.</b></p>' : '<p>That entry is not on your list.</p>';
        } else {
            echo contact_note($list, $userid, in_int('entry', 0, $_POST), in_str('comment', 1000, '', $_POST)) ? '<p><b>Comment saved.</b></p>' : '<p>That entry is not on your list.</p>';
        }
    }
    $token = request_csrf_html('list_' . $list);
    if ($L['counter'] !== null) {
        $count = (int)$db->value("SELECT `{$L['counter']}` FROM `users` WHERE `userid` = ?", [$userid]);
        $top = $db->all("SELECT `userid`, `username` FROM `users` WHERE `{$L['counter']}` > 0 ORDER BY `{$L['counter']}` DESC, `userid` LIMIT 5");
        echo '<p>' . number_format($count) . ($count === 1 ? ' player has' : ' players have') . ' you on their ' . ($list === 'friends' ? 'friends list' : 'black list') . '.';
        if ($top) {
            echo ' ' . ($list === 'friends' ? 'Most liked' : 'Most hated') . ': ' . implode(', ', array_map(fn ($t) => "<a href='viewuser.php?u={$t['userid']}'>" . e((string)$t['username']) . '</a>', $top)) . '.';
        }
        echo '</p>';
    }
    $rows = contact_rows($list, $userid);
    echo "<table width='100%' cellspacing='1' class='table'><tr><th>Player</th><th>Level</th><th>Status</th>" . ($L['comment'] ? '<th>Comment</th>' : '') . '<th>Do</th><th></th></tr>';
    $now = time();
    foreach ($rows as $r) {
        $status = (int)$r['hospital'] > 0 ? 'In hospital' : ((int)$r['jail'] > 0 ? 'In jail' : ((int)$r['laston'] > $now - MCC_ONLINE_SECONDS ? "<b class='up'>Online</b>" : 'Offline'));
        echo "<tr><td><a href='viewuser.php?u={$r['userid']}'>" . e((string)$r['username']) . "</a> [{$r['userid']}]</td><td>{$r['level']}</td><td>{$status}</td>";
        if ($L['comment']) {
            echo "<td><form action='{$page}' method='post'>{$token}<input type='hidden' name='do' value='note' /><input type='hidden' name='entry' value='{$r['entry']}' /><input type='text' name='comment' maxlength='255' size='24' value='"
                . e((string)$r['comment']) . "' aria-label='Comment' /> <input type='submit' value='Save' /></form></td>";
        }
        echo "<td><a href='mailbox.php?action=compose&amp;ID={$r['userid']}'>Mail</a>" . ($list === 'contacts' ? '' : " &middot; <a href='attack.php?ID={$r['userid']}'>Attack</a>") . "</td>
        <td><form action='{$page}' method='post'>{$token}<input type='hidden' name='do' value='remove' /><input type='hidden' name='entry' value='{$r['entry']}' /><button type='submit' class='linkish'>Remove</button></form></td></tr>";
    }
    echo ($rows ? '' : "<tr><td colspan='6'>Nobody yet.</td></tr>") . '</table>';
    $prefill = in_int('ID', 0, $_GET);
    echo '<h4>Add ' . ($L['one'] === 'enemy' ? 'an' : 'a') . " {$L['one']}</h4><form action='{$page}' method='post'>{$token}<input type='hidden' name='do' value='add' />Player ID: <input type='text' name='who' size='8' value='" . ($prefill ?: '') . "' /> "
        . ($L['comment'] ? "Comment (optional): <input type='text' name='comment' maxlength='255' size='30' /> " : '') . "<input type='submit' value='Add' /></form>";
}

/**
 * The mugger of the hour: who has taken the most money in muggings over the last hour.
 * Returns ['userid', 'username', 'total', 'muggings'] or null. Worked out at most once a page.
 */
function mugger_of_the_hour(): ?array
{
    return once('mugger_hour', function () {
        global $db;
        return $db->row("SELECT `a`.`attacker` AS `userid`, `u`.`username`, SUM(`a`.`stole`) AS `total`, COUNT(*) AS `muggings` FROM `attacklogs` AS `a` INNER JOIN `users` AS `u` ON `u`.`userid` = `a`.`attacker`
            WHERE `a`.`time` > ? AND `a`.`result` = 'won' AND `a`.`stole` > 0 GROUP BY `a`.`attacker`, `u`.`username` ORDER BY `total` DESC, `a`.`attacker` LIMIT 1", [time() - 3600]);
    });
}

function mugger_banner(): string
{
    $m = mugger_of_the_hour();
    return $m === null ? '' : "<div class='store-note'><b>Mugger of the hour:</b> <a href='viewuser.php?u={$m['userid']}'>" . e((string)$m['username']) . '</a> has taken ' . money_formatter((int)$m['total']) . ' in '
        . (int)$m['muggings'] . ((int)$m['muggings'] === 1 ? ' mugging' : ' muggings') . ". <a href='bounties.php'>Put a price on their head</a></div>";
}
