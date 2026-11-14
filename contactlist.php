<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: the player's contacts list. The work is in engine/mods/Contacts.php.
 */
global $ir, $h;
require_once('globals.php');
contact_page('contacts', $ir);
echo "<p>&gt; <a href='mailbox.php'>Back to your mailbox</a></p>";
$h->endpage();
