<?php
declare(strict_types=1);
/**
 * MCCodes v2 by Dabomstew & ColdBlooded
 *
 * Repository: https://github.com/davemacaulay/mccodesv2
 * License: MIT License
 */

if (str_contains($_SERVER['PHP_SELF'], 'globals_nonauth.php'))
{
    exit;
}
// Engine v3: the start-up code lives in engine/boot.php, shared by every page.
$MCC_BOOT = 'public';
require __DIR__ . '/engine/boot.php';
