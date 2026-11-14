<?php
declare(strict_types=1);
/**
 * MCCodes v2 by Dabomstew & ColdBlooded
 *
 * Repository: https://github.com/davemacaulay/mccodesv2
 * License: MIT License
 */

if (str_contains($_SERVER['PHP_SELF'], 'sglobals.php'))
{
    exit;
}
function staff_csrf_error($goBackTo): void
{
    global $h;
    echo '<h3>Error</h3><hr />
    Your action has been blocked for security reasons.<br />
    &gt; <a href="' . $goBackTo . '">Try Again</a>';
    $h->endpage();
    exit;
}

/**
 * Check the CSRF code we received against the one that was registered for the form - using default code properties ($_POST['verf']).
 * If verification fails, end execution immediately.
 * If not, continue.
 * @param string $formid A unique string used to identify this form to match up its submission with the right token.
 * @param $goBackTo
 * @return bool Whether the user provided a valid code or not
 */
function staff_csrf_stdverify(string $formid, $goBackTo): bool
{
    if (!isset($_POST['verf'])
            || !verify_csrf_code($formid, stripslashes($_POST['verf'])))
    {
        staff_csrf_error($goBackTo);
    }
    return true;
}
// Engine v3: the start-up code lives in engine/boot.php, shared by every page.
$MCC_BOOT = 'staff';
require __DIR__ . '/engine/boot.php';
