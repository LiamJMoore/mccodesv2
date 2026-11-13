<?php
declare(strict_types=1);

/**
 * The notifications bell: what is waiting for a player (unread events and mail), newest first.
 *
 * The bell in the page header is drawn from this when the page loads, and js/notify.js asks api.php for it again
 * every half minute, so a mugging or a new message shows up without the player reloading anything.
 */

/** "just now", "5m", "3h", "2d": how long ago a time was. */
function notify_ago(int $time, ?int $now = null): string
{
    $s = max(0, ($now ?? time()) - $time);
    return match (true) {
        $s < 60 => 'just now',
        $s < 3600 => intdiv($s, 60) . 'm',
        $s < 86400 => intdiv($s, 3600) . 'h',
        default => intdiv($s, 86400) . 'd',
    };
}

/** The words of an event, without its HTML, cut to a length that fits the bell. */
function notify_plain(string $html, int $max = 110): string
{
    $text = trim((string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'ISO-8859-1')));
    return strlen($text) > $max ? rtrim(substr($text, 0, $max - 3)) . '...' : $text;
}

/**
 * ['events' => unread events, 'mail' => unread mail, 'total' => both,
 *  'items' => [['type' => 'event'|'mail', 'text' => plain words, 'time' => unix, 'ago' => '5m', 'url' => where to read it], ...]]
 */
function notify_summary(int $userid, int $limit = 6): array
{
    global $db;
    $limit = max(1, min($limit, 20));
    $items = [];
    foreach ($db->all("SELECT `evTIME`, `evTEXT` FROM `events` WHERE `evUSER` = ? AND `evREAD` = 0 ORDER BY `evTIME` DESC, `evID` DESC LIMIT {$limit}", [$userid]) as $r) {
        $items[] = ['type' => 'event', 'text' => notify_plain((string)$r['evTEXT']), 'time' => (int)$r['evTIME'], 'ago' => notify_ago((int)$r['evTIME']), 'url' => 'events.php'];
    }
    foreach ($db->all("SELECT `m`.`mail_time`, `m`.`mail_subject`, `u`.`username` FROM `mail` AS `m` LEFT JOIN `users` AS `u` ON `u`.`userid` = `m`.`mail_from`
                       WHERE `m`.`mail_to` = ? AND `m`.`mail_read` = 0 ORDER BY `m`.`mail_time` DESC, `m`.`mail_id` DESC LIMIT {$limit}", [$userid]) as $r) {
        $items[] = ['type' => 'mail', 'text' => notify_plain('Mail from ' . ($r['username'] ?? 'the system') . ': ' . $r['mail_subject']), 'time' => (int)$r['mail_time'],
                    'ago' => notify_ago((int)$r['mail_time']), 'url' => 'mailbox.php'];
    }
    usort($items, fn ($a, $b) => $b['time'] <=> $a['time']);
    $events = (int)$db->value('SELECT COUNT(*) FROM `events` WHERE `evUSER` = ? AND `evREAD` = 0', [$userid]);
    $mail = (int)$db->value('SELECT COUNT(*) FROM `mail` WHERE `mail_to` = ? AND `mail_read` = 0', [$userid]);
    return ['events' => $events, 'mail' => $mail, 'total' => $events + $mail, 'items' => array_slice($items, 0, $limit)];
}

/** The bell, as HTML for the page header. Works with no JavaScript (it is a <details> element); the script only keeps it fresh. */
function notify_bell(int $userid, int $waiting): string
{
    // the list costs two queries, so it is only fetched when the player row says something is waiting
    $sum = $waiting > 0 ? notify_summary($userid) : ['total' => 0, 'items' => []];
    $list = '';
    foreach ($sum['items'] as $i) {
        $list .= "<a class='bell-item bell-{$i['type']}' href='{$i['url']}'><span>" . e($i['text']) . "</span><small>{$i['ago']}</small></a>";
    }
    if ($list === '') {
        $list = "<div class='bell-empty'>Nothing new.</div>";
    }
    $n = $sum['total'];
    return "<details class='bell' id='mcc-bell' data-url='api.php?do=notifications'>
<summary aria-label='Notifications' title='Notifications'><svg viewBox='0 0 24 24' width='18' height='18' aria-hidden='true'><path fill='currentColor' d='M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-6v-5a7 7 0 0 0-5.5-6.84V3.5a1.5 1.5 0 0 0-3 0v.66A7 7 0 0 0 5 11v5l-1.7 1.7a.75.75 0 0 0 .53 1.3h16.34a.75.75 0 0 0 .53-1.3Z'/></svg><b class='bell-n'"
        . ($n > 0 ? '' : ' hidden') . '>' . ($n > 99 ? '99+' : $n) . "</b></summary>
<div class='bell-list'><div class='bell-items'>{$list}</div><div class='bell-foot'><a href='events.php'>All events</a><a href='mailbox.php'>Mailbox</a></div></div>
</details>";
}
