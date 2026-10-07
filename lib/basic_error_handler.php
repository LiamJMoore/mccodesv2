<?php
declare(strict_types=1);
/**
 * MCCodes v2 by Dabomstew & ColdBlooded
 * 
 * Repository: https://github.com/davemacaulay/mccodesv2
 * License: MIT License
 */

/**
 * A very basic error handler, essentially just killing execution whenever
 * something goes wrong, or telling us more info if debug mode is on.
 * Can easily be replaced by a more robust one,
 * which e.g. logs raw error data somewhere for admin assessment
 */

// Change to true to show the user more information (for development)
const DEBUG = false;

/**
 * @param $human_error
 * @param $debug_error
 * @param $action
 * @return void
 */
function error_critical($human_error, $debug_error, $action): void
{
    // Clear anything that was going to be shown
    ob_get_clean();
    // Setup a new error
    header('HTTP/1.1 500 Internal Server Error');
    // If we can, gracefully show them an error message
    // including the game's name. If not, just say
    // "Internal Server Error"
    global $set;
    if (isset($set) && is_array($set) && array_key_exists('game_name', $set))
    {
        echo '<h1>' . $set['game_name'] . ' - Critical Error</h1>';
    }
    else
    {
        echo '<h1>Internal Server Error</h1>';
    }
    if (DEBUG)
    {
        echo 'A critical error has occurred, and page execution has stopped. '
                . 'Below are the details:<br />' . $debug_error
                . '<br /><br />' . '<strong>Action taken:</strong> ' . $action
                . '<br /><br />';
        // Only uncomment the below if you know what you're doing,
        // for debug purposes.
        //if (is_array($context) && count($context) > 0)
        //{
        //    echo '<strong>Context at error time:</strong> ' . '<br /><br />'
        //            . nl2br(print_r($context, true));
        //}
    }
    else
    {
        echo 'A critical error has occurred, and this page cannot be displayed. '
                . 'Please try again later.';
        if (!empty($human_error))
        {
            echo '<br />' . $human_error;
        }
    }
    exit;
}

/**
 * @param $errno
 * @param $errstr
 * @param string $errfile
 * @param int $errline
 * @param array $errcontext
 * @return void
 */
/**
 * Warnings that were notices before PHP 8.
 */
function mcc_is_legacy_notice(string $errstr): bool
{
    foreach (['Undefined array key', 'Undefined variable', 'Undefined property',
                 'Trying to access array offset on value of type null',
                 'Trying to access array offset on null',
                 'Undefined offset', 'Undefined index'] as $prefix)
    {
        if (str_starts_with($errstr, $prefix))
        {
            return true;
        }
    }
    return false;
}

function error_php($errno, $errstr, string $errfile = '', int $errline = 0,
                   array $errcontext = []): void
{
    // What's happened?
    // PHP 8 promoted several PHP 7 *notices* to warnings ("Undefined array
    // key", "Undefined variable"...). This engine was written to ignore
    // notices, so treating these as fatal killed whole pages over a missing
    // ?action= or ID. They are logged and the page carries on, as on PHP 7;
    // every other warning still stops the page.
    if ($errno == E_WARNING && mcc_is_legacy_notice((string)$errstr))
    {
        error_log("MCCodes notice: {$errstr} in {$errfile}:{$errline}");
        return;
    }
    // If it's a PHP warning or user error/warning, don't go further - indicates bad code, unsafe
    if ($errno == E_WARNING)
    {
        error_critical('<strong>PHP Warning:</strong> ' . $errstr . ' (' . $errno
            . ')', 'Line executed: ' . $errfile . ':' . $errline,
            $errcontext);
    }
    elseif ($errno == E_RECOVERABLE_ERROR)
    {
        error_critical('<strong>PHP Recoverable Error:</strong> ' . $errstr . ' ('
            . $errno . ')',
            'Line executed: ' . $errfile . ':' . $errline, $errcontext);
    }
    elseif ($errno == E_USER_ERROR)
    {
        error_critical('<strong>Engine Error:</strong> ' . $errstr . ' (' . $errno
            . ')', 'Line executed: ' . $errfile . ':' . $errline,
            $errcontext);
    }
    elseif ($errno == E_USER_WARNING)
    {
        error_critical('<strong>Engine Warning:</strong> ' . $errstr . ' (' . $errno
            . ')', 'Line executed: ' . $errfile . ':' . $errline,
            $errcontext);
    } elseif (DEBUG) {
        // Determine the name to display from the error type
        // Only do anything if DEBUG is on, now
        $errname = 'Unknown Error';
        switch ($errno) {
            case E_NOTICE:
                $errname = 'PHP Notice';
                break;
            case E_USER_NOTICE:
                $errname = 'User Notice';
                break;
            case 8192:
                $errname = 'PHP Deprecation Notice';
                break; // E_DEPRECATED [since 5.3]
            case 16384:
                $errname = 'User Deprecation Notice';
                break; // E_USER_DEPRECATED [since 5.3]
        }
        echo 'A non-critical error has occurred. Page execution will continue. '
            . 'Below are the details:<br /><strong>' . $errname
            . '</strong>: ' . $errstr . ' (' . $errno . ')'
            . '<br /><br />' . '<strong>Line executed</strong>: '
            . $errfile . ':' . $errline . '<br /><br />';
        // Only uncomment the below if you know what you're doing,
        // for debug purposes.
        //if (is_array($errcontext) && count($errcontext) > 0)
        //{
        //    echo '<strong>Context at error time:</strong> '
        //            . '<br /><br />' . nl2br(print_r($errcontext, true));
        //}
    }
}
