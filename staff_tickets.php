<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: staff, support tickets.
 */
global $db, $ir, $userid, $h;
require_once('sglobals.php');
echo '<h3>Support Tickets</h3>';
$id = in_int('id', 0, $_GET);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['reply', 'close'], '', $_POST);
    staff_csrf_stdverify('ticket', 'staff_tickets.php');
    if ($do === 'reply') {
        $why = ticket_reply($id, $userid, true, in_str('text', 8000, '', $_POST));
        echo $why === null ? '<p><b>Reply sent.</b></p>' : '<p>' . e($why) . '</p>';
        if ($why === null) {
            stafflog_add("Replied to support ticket #{$id}");
        }
    } elseif ($do === 'close' && ticket_close($id, $userid, true)) {
        echo '<p><b>Ticket closed.</b></p>';
        stafflog_add("Closed support ticket #{$id}");
    }
}
$t = $id ? ticket_get($id, $userid, true) : null;
if ($t !== null) {
    echo ticket_view($t, $userid, true, 'staff_tickets.php') . "<p>&gt; <a href='staff_tickets.php'>All tickets</a> &middot; <a href='staff_ledger.php?u={$t['userid']}'>This player's ledger</a></p>";
    $h->endpage();
    exit;
}
$all = ticket_list(null, isset($_GET['closed']));
echo "<p>Tickets waiting on staff come first. <a href='staff_tickets.php?closed=1'>Include closed tickets</a></p>
<table width='100%' cellspacing='1' class='table'><tr><th>#</th><th>Player</th><th>Subject</th><th>About</th><th>Status</th><th>Last update</th></tr>";
foreach ($all as $m) {
    echo "<tr><td>{$m['id']}</td><td>" . e((string)$m['username']) . " [{$m['userid']}]</td><td><a href='staff_tickets.php?id={$m['id']}'>" . e($m['subject']) . '</a></td><td>' . e(MCC_TICKET_TOPICS[$m['topic']] ?? '') . '</td><td>'
        . ($m['status'] === 'waiting' ? "<b class='dash-flag'>Waiting on staff</b>" : e(ucfirst($m['status']))) . '</td><td>' . date('j M, H:i', (int)$m['updated']) . '</td></tr>';
}
echo ($all ? '' : "<tr><td colspan='6'>No tickets.</td></tr>") . '</table>';
$h->endpage();
