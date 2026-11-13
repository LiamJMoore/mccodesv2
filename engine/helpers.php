<?php
declare(strict_types=1);

/**
 * Reading what the player sent, and writing text back, in one place.
 *
 * v2 pages repeat lines like
 *     $_GET['ID'] = (isset($_GET['ID']) && is_numeric($_GET['ID'])) ? abs(intval($_GET['ID'])) : '';
 * on every page, each slightly different. These do the same jobs with one name each.
 */

/** A whole number from the form or the address, never negative. $default when it is missing or not a number. */
function in_int(string $key, int $default = 0, ?array $from = null): int
{
    $v = ($from ?? $_POST)[$key] ?? ($from === null ? ($_GET[$key] ?? null) : null);
    if (is_int($v)) {
        return abs($v);
    }
    if (!is_string($v) || !preg_match('/^\s*-?\d{1,18}\s*$/', $v)) {
        return $default;
    }
    return abs((int)$v);
}

/** Text from the form or the address: trimmed, cut to $max characters, never an array. */
function in_str(string $key, int $max = 255, string $default = '', ?array $from = null): string
{
    $v = ($from ?? $_POST)[$key] ?? ($from === null ? ($_GET[$key] ?? null) : null);
    if (!is_string($v)) {
        return $default;
    }
    $v = trim(str_replace("\0", '', $v));
    return $max > 0 ? mb_substr($v, 0, $max, '8bit') : $v;
}

/** One of a fixed list of answers, or $default. */
function in_choice(string $key, array $allowed, string $default = '', ?array $from = null): string
{
    $v = in_str($key, 64, $default, $from);
    return in_array($v, $allowed, true) ? $v : $default;
}

/** Text made safe to print inside HTML. The game's pages are ISO-8859-1, as v2 wrote them. */
function e(string|int|float|null $text): string
{
    return htmlentities((string)$text, ENT_QUOTES, 'ISO-8859-1');
}
