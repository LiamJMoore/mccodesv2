<?php
declare(strict_types=1);

/**
 * Support tickets. A player raises a ticket; staff answer; the conversation stays in one place until it is closed.
 * Messages are plain text.
 */
const MCC_TICKET_TOPICS = ['account' => 'My account', 'payment' => 'A payment', 'bug' => 'Something is broken', 'player' => 'Another player', 'other' => 'Something else'];
const MCC_TICKET_OPEN_LIMIT = 3;          // open tickets one player may have
const MCC_TICKET_MAX = 2000;              // characters in a message
const MCC_TICKET_WAIT = 30;               // seconds between one person's messages

function ticket_clean(string $text): string
{
    return trim((string)preg_replace('/[ \t]+/', ' ', str_replace(["\r\n", "\r"], "\n", $text)));
}

/** Open a ticket. Returns its id, or a string saying why not. */
function ticket_open(int $userid, string $topic, string $subject, string $text): int|string
{
    global $db;
    $subject = trim((string)preg_replace('/\s+/', ' ', $subject));
    $text = ticket_clean($text);
    if (!isset(MCC_TICKET_TOPICS[$topic]) || $subject === '' || strlen($subject) > 100 || $text === '' || strlen($text) > MCC_TICKET_MAX) {
        return 'Choose a topic, and give a subject (up to 100 characters) and a message (up to ' . MCC_TICKET_MAX . ').';
    }
    if ((int)$db->value("SELECT COUNT(*) FROM `v3_tickets` WHERE `userid` = ? AND `status` <> 'closed'", [$userid]) >= MCC_TICKET_OPEN_LIMIT) {
        return 'You already have ' . MCC_TICKET_OPEN_LIMIT . ' tickets open. Add to one of those.';
    }
    $id = 0;
    $db->transaction(function () use ($db, $userid, $topic, $subject, $text, &$id) {
        $now = time();
        $id = $db->insert('v3_tickets', ['userid' => $userid, 'topic' => $topic, 'subject' => $subject, 'status' => 'waiting', 'opened' => $now, 'updated' => $now]);
        $db->insert('v3_ticket_posts', ['ticket' => $id, 'author' => $userid, 'staff' => 0, 'at' => $now, 'text' => $text]);
    });
    return $id;
}

/** The ticket if this person may read it (its owner, or staff), else null. */
function ticket_get(int $id, int $viewer, bool $staff): ?array
{
    global $db;
    $t = $db->row('SELECT `t`.*, `u`.`username` FROM `v3_tickets` AS `t` LEFT JOIN `users` AS `u` ON `u`.`userid` = `t`.`userid` WHERE `t`.`id` = ?', [$id]);
    return $t !== null && ($staff || (int)$t['userid'] === $viewer) ? $t : null;
}

function ticket_posts(int $id): array
{
    global $db;
    return $db->all('SELECT `p`.`author`, `p`.`staff`, `p`.`at`, `p`.`text`, `u`.`username` FROM `v3_ticket_posts` AS `p` LEFT JOIN `users` AS `u` ON `u`.`userid` = `p`.`author` WHERE `p`.`ticket` = ? ORDER BY `p`.`id`', [$id]);
}

/** Add a message. A staff reply marks the ticket answered; a player's marks it waiting. Null when added, or why not. */
function ticket_reply(int $id, int $author, bool $staff, string $text, ?int $now = null): ?string
{
    global $db;
    $now ??= time();
    $t = ticket_get($id, $author, $staff);
    $text = ticket_clean($text);
    if ($t === null) {
        return 'There is no such ticket.';
    }
    if ($t['status'] === 'closed') {
        return 'That ticket is closed.';
    }
    if ($text === '' || strlen($text) > MCC_TICKET_MAX) {
        return 'A message is 1 to ' . MCC_TICKET_MAX . ' characters.';
    }
    if ((int)$db->value('SELECT COALESCE(MAX(`at`), 0) FROM `v3_ticket_posts` WHERE `author` = ?', [$author]) > $now - MCC_TICKET_WAIT) {
        return 'Wait a moment between messages.';
    }
    $db->transaction(function () use ($db, $id, $author, $staff, $text, $now) {
        $db->insert('v3_ticket_posts', ['ticket' => $id, 'author' => $author, 'staff' => $staff ? 1 : 0, 'at' => $now, 'text' => $text]);
        $db->exec('UPDATE `v3_tickets` SET `status` = ?, `updated` = ? WHERE `id` = ?', [$staff ? 'answered' : 'waiting', $now, $id]);
    });
    if ($staff && (int)$t['userid'] !== $author) {
        event_add((int)$t['userid'], "Staff have replied to your ticket. <a href='tickets.php?id={$id}'>Read the reply</a>");
    }
    return null;
}

function ticket_close(int $id, int $viewer, bool $staff): bool
{
    global $db;
    return ticket_get($id, $viewer, $staff) !== null && $db->exec("UPDATE `v3_tickets` SET `status` = 'closed', `updated` = ? WHERE `id` = ? AND `status` <> 'closed'", [time(), $id]) === 1;
}

/** Tickets for a list: a player's own, or (for staff) everyone's, those waiting on staff first. */
function ticket_list(?int $userid, bool $include_closed = false, int $limit = 50): array
{
    global $db;
    $where = [];
    $params = [];
    if ($userid !== null) {
        $where[] = '`t`.`userid` = ?';
        $params[] = $userid;
    }
    if (!$include_closed) {
        $where[] = "`t`.`status` <> 'closed'";
    }
    return $db->all('SELECT `t`.`id`, `t`.`userid`, `t`.`topic`, `t`.`subject`, `t`.`status`, `t`.`opened`, `t`.`updated`, `u`.`username` FROM `v3_tickets` AS `t` LEFT JOIN `users` AS `u` ON `u`.`userid` = `t`.`userid`'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY FIELD(`t`.`status`, 'waiting', 'answered', 'closed'), `t`.`updated` LIMIT " . max(1, min($limit, 200)), $params);
}

/** How many tickets are waiting on staff (for the staff menu and dashboard). */
function ticket_waiting(): int
{
    global $db;
    return (int)$db->value("SELECT COUNT(*) FROM `v3_tickets` WHERE `status` = 'waiting'");
}

/** One ticket's conversation and its reply form, shared by the player page and the staff page. */
function ticket_view(array $t, int $viewer, bool $staff, string $page): string
{
    $out = '<h4>#' . (int)$t['id'] . ': ' . e($t['subject']) . '</h4><p><small>' . e(MCC_TICKET_TOPICS[$t['topic']] ?? $t['topic']) . ' &middot; opened ' . date('j M Y, H:i', (int)$t['opened'])
        . ($staff ? " by <a href='viewuser.php?u={$t['userid']}'>" . e((string)$t['username']) . "</a> [{$t['userid']}]" : '') . ' &middot; ' . e(ucfirst($t['status'])) . '</small></p>';
    foreach (ticket_posts((int)$t['id']) as $p) {
        $out .= "<div class='profile-comment" . ((int)$p['staff'] ? ' from-staff' : '') . "'><b>" . ((int)$p['staff'] ? 'Staff' . ($staff ? ' (' . e((string)$p['username']) . ')' : '') : e((string)$p['username'])) . '</b> <small>' . date('j M, H:i', (int)$p['at'])
            . '</small><br />' . nl2br(e($p['text'])) . '</div>';
    }
    if ($t['status'] === 'closed') {
        return $out . '<p>This ticket is closed.</p>';
    }
    $token = request_csrf_html('ticket');
    return $out . "<form action='{$page}?id={$t['id']}' method='post'>{$token}<input type='hidden' name='do' value='reply' /><textarea name='text' rows='4' cols='60' maxlength='" . MCC_TICKET_MAX . "' aria-label='Your message'></textarea><br /><input type='submit' value='Send' /></form>
    <form action='{$page}?id={$t['id']}' method='post' style='margin-top:8px;'>{$token}<input type='hidden' name='do' value='close' /><button type='submit' class='linkish'>Close this ticket</button></form>";
}
