<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: a player's support tickets.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>Support</h3>';
$id = in_int('id', 0, $_GET);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['open', 'reply', 'close'], '', $_POST);
    if (!verify_csrf_code('ticket', in_str('verf', 64, '', $_POST)) || $do === '') {
        echo '<p>That form had expired. Please try again.</p>';
    } elseif ($do === 'open') {
        $r = ticket_open($userid, in_str('topic', 20, '', $_POST), in_str('subject', 300, '', $_POST), in_str('text', 8000, '', $_POST));
        if (is_int($r)) {
            $id = $r;
            echo '<p><b>Ticket opened.</b> Staff will reply here, and you will get an event when they do.</p>';
        } else {
            echo '<p><b>Not opened.</b> ' . e($r) . '</p>';
        }
    } elseif ($do === 'reply') {
        $why = ticket_reply($id, $userid, false, in_str('text', 8000, '', $_POST));
        echo $why === null ? '' : '<p>' . e($why) . '</p>';
    } else {
        echo ticket_close($id, $userid, false) ? '<p><b>Ticket closed.</b></p>' : '';
    }
}
$t = $id ? ticket_get($id, $userid, false) : null;
if ($t !== null) {
    echo ticket_view($t, $userid, false, 'tickets.php') . "<p>&gt; <a href='tickets.php'>All your tickets</a></p>";
    $h->endpage();
    exit;
}
$mine = ticket_list($userid, true);
if ($mine) {
    echo "<table width='100%' cellspacing='1' class='table'><tr><th>#</th><th>Subject</th><th>About</th><th>Status</th><th>Last update</th></tr>";
    foreach ($mine as $m) {
        echo "<tr><td>{$m['id']}</td><td><a href='tickets.php?id={$m['id']}'>" . e($m['subject']) . '</a></td><td>' . e(MCC_TICKET_TOPICS[$m['topic']] ?? '') . '</td><td>'
            . ($m['status'] === 'answered' ? '<b>Staff have replied</b>' : ($m['status'] === 'waiting' ? 'Waiting for staff' : 'Closed')) . '</td><td>' . date('j M, H:i', (int)$m['updated']) . '</td></tr>';
    }
    echo '</table>';
}
echo "<h4>Open a ticket</h4><form action='tickets.php' method='post'>" . request_csrf_html('ticket') . "<input type='hidden' name='do' value='open' /><table cellspacing='1' class='table' width='90%'>
<tr><td>About</td><td><select name='topic'>";
foreach (MCC_TICKET_TOPICS as $k => $label) {
    echo "<option value='{$k}'>" . e($label) . '</option>';
}
echo "</select></td></tr><tr><td>Subject</td><td><input type='text' name='subject' maxlength='100' size='50' /></td></tr>
<tr><td>What happened</td><td><textarea name='text' rows='6' cols='60' maxlength='" . MCC_TICKET_MAX . "'></textarea><br /><small>Never put your password in a ticket. Staff will not ask for it.</small></td></tr>
<tr><td colspan='2' align='center'><input type='submit' value='Open ticket' /></td></tr></table></form>";
$h->endpage();
