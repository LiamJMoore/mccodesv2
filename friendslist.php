<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: the player's friends list. The work is in engine/mods/Contacts.php.
 */
global $ir, $h;
require_once('globals.php');
contact_page('friends', $ir);
$h->endpage();
