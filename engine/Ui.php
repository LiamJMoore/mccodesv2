<?php
declare(strict_types=1);

/**
 * The game's look: its icons, and the pieces of page the menu and the Explore hub are built from.
 * The icons are drawn here as simple line shapes, so the game needs no icon font and no image files.
 */
const MCC_ICONS = [
    'home' => 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
    'compass' => 'M12 3a9 9 0 100 18 9 9 0 000-18zM15.5 8.5l-2 5-5 2 2-5z',
    'bell' => 'M6 16v-5a6 6 0 1112 0v5l2 2H4zM10 20a2 2 0 004 0',
    'mail' => 'M3 6h18v12H3zM3 7l9 7 9-7',
    'bag' => 'M5 8h14l-1 12H6zM9 8V6a3 3 0 016 0v2',
    'dumbbell' => 'M4 9v6M7 7v10M17 7v10M20 9v6M7 12h10',
    'mask' => 'M3 8c3-2 6-2 9 0 3-2 6-2 9 0 0 5-2 9-5 9-2 0-3-2-4-2s-2 2-4 2c-3 0-5-4-5-9zM8 12h2M14 12h2',
    'briefcase' => 'M3 8h18v11H3zM9 8V5h6v3M3 13h18',
    'cap' => 'M2 9l10-5 10 5-10 5zM6 11v5c2 2 10 2 12 0v-5',
    'cross' => 'M9 3h6v6h6v6h-6v6H9v-6H3V9h6z',
    'bars' => 'M4 4h16v16H4zM9 4v16M15 4v16',
    'gift' => 'M4 10h16v10H4zM3 7h18v3H3zM12 7v13M12 7c-2-4-6-3-5 0M12 7c2-4 6-3 5 0',
    'target' => 'M12 3a9 9 0 100 18 9 9 0 000-18zM12 7a5 5 0 100 10 5 5 0 000-10zM12 11a1 1 0 100 2 1 1 0 000-2z',
    'star' => 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z',
    'medal' => 'M8 3l4 7 4-7M12 10a5 5 0 100 10 5 5 0 000-10z',
    'crosshair' => 'M12 4a8 8 0 100 16 8 8 0 000-16zM12 2v5M12 17v5M2 12h5M17 12h5',
    'hammer' => 'M14 4l6 6-3 3-6-6zM12.5 8.5L4 17l3 3 8.5-8.5',
    'chart' => 'M4 20V4M4 20h16M7 15l4-5 3 3 5-7',
    'flag' => 'M5 21V4M5 4h12l-2 4 2 4H5',
    'ticket' => 'M3 8a2 2 0 002-2h14a2 2 0 002 2v2a2 2 0 000 4v2a2 2 0 00-2 2H5a2 2 0 00-2-2v-2a2 2 0 000-4zM14 6v12',
    'paw' => 'M12 13c-3 0-5 2.5-5 5 0 1.5 1.5 2 5 2s5-.5 5-2c0-2.5-2-5-5-5zM6 9a1.6 2 0 100 4 1.6 2 0 000-4zM18 9a1.6 2 0 100 4 1.6 2 0 000-4zM9.5 4a1.6 2 0 100 4 1.6 2 0 000-4zM14.5 4a1.6 2 0 100 4 1.6 2 0 000-4z',
    'crate' => 'M3 8l9-5 9 5v8l-9 5-9-5zM3 8l9 5 9-5M12 13v8',
    'vault' => 'M4 5h16v14H4zM12 9a3 3 0 100 6 3 3 0 000-6zM12 12h3M7 19v2M17 19v2',
    'trophy' => 'M7 4h10v5a5 5 0 01-10 0zM7 6H4c0 3 1 4 3 4M17 6h3c0 3-1 4-3 4M12 14v4M8 20h8',
    'cart' => 'M3 4h2l2.5 11h10L20 7H6.5M9 20a1 1 0 100-2 1 1 0 000 2zM17 20a1 1 0 100-2 1 1 0 000 2z',
    'gem' => 'M6 4h12l3 5-9 11L3 9zM3 9h18M9 4l-2 5 5 11 5-11-2-5',
    'chat' => 'M4 5h16v11H9l-5 4z',
    'megaphone' => 'M4 10v4l10 4V6zM14 9a3 3 0 010 6M6 14l1 5h3l-1-4',
    'news' => 'M4 5h13v14H6a2 2 0 01-2-2zM17 9h3v8a2 2 0 01-2 2M7 9h7M7 13h7M7 16h4',
    'search' => 'M11 4a7 7 0 100 14 7 7 0 000-14zM20 20l-4-4',
    'users' => 'M8 11a3 3 0 100-6 3 3 0 000 6zM2 20c0-3 3-5 6-5s6 2 6 5M16 11a3 3 0 100-6M18 15c2 .5 4 2 4 5',
    'shield' => 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z',
    'gear' => 'M12 9a3 3 0 100 6 3 3 0 000-6zM12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2',
    'alert' => 'M12 3l10 17H2zM12 10v5M12 17.5v.5',
    'help' => 'M12 3a9 9 0 100 18 9 9 0 000-18zM9.5 9.5a2.5 2.5 0 114 2c-1 .7-1.5 1.2-1.5 2.5M12 17v.5',
    'book' => 'M5 4h13v16H7a2 2 0 01-2-2zM5 18a2 2 0 012-2h11',
    'user' => 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21c0-4 4-6 8-6s8 2 8 6',
    'logout' => 'M10 4H5v16h5M14 8l4 4-4 4M18 12H9',
    'heart' => 'M12 20S4 14.5 4 9a4 4 0 018-1 4 4 0 018 1c0 5.5-8 11-8 11z',
    'ban' => 'M12 3a9 9 0 100 18 9 9 0 000-18zM6 6l12 12',
    'bank' => 'M3 9l9-5 9 5M5 9v9M9 9v9M15 9v9M19 9v9M3 20h18',
    'dice' => 'M5 5h14v14H5zM9 9v.5M15 15v.5M15 9v.5M9 15v.5M12 12v.5',
    'plane' => 'M3 13l18-8-6 16-3-7z',
    'swords' => 'M5 4l10 10M4 20l4-4M19 4L9 14M20 20l-4-4M6 15l3 3M18 15l-3 3',
    'poll' => 'M5 20V10M12 20V4M19 20v-7',
    'list' => 'M8 6h12M8 12h12M8 18h12M4 6v.5M4 12v.5M4 18v.5',
    'menu' => 'M4 7h16M4 12h16M4 17h16',
    'key' => 'M14 10a4 4 0 10-3.5 4L4 20v-3h3v-3h3l.5-.5',
];

function ui_icon(string $name, int $size = 18): string
{
    $d = MCC_ICONS[$name] ?? MCC_ICONS['star'];
    return "<svg class='ic' viewBox='0 0 24 24' width='{$size}' height='{$size}' fill='none' stroke='currentColor' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'><path d='{$d}'/></svg>";
}

/** The page being shown, as "gym.php". */
function ui_here(): string
{
    return basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
}

/** One menu link: icon, words, and a small count or word beside it when there is something waiting. */
function ui_link(string $href, string $icon, string $label, string|int $badge = '', bool $hot = false): string
{
    $on = ui_here() === basename((string)parse_url($href, PHP_URL_PATH));
    $b = $badge !== '' && $badge !== 0 && $badge !== '0' ? "<em class='nav-badge'>" . e((string)$badge) . '</em>' : '';
    return "<a href='{$href}' class='nav-a" . ($on ? ' on' : '') . ($hot ? ' hot' : '') . "'" . ($on ? " aria-current='page'" : '') . '>' . ui_icon($icon) . "<span>{$label}</span>{$b}</a>";
}

/** A titled group of menu links. $links is a list of ui_link() strings. */
function ui_group(string $title, array $links): string
{
    $links = array_filter($links);
    return $links ? "<div class='nav-group'><b>" . e($title) . '</b>' . implode('', $links) . '</div>' : '';
}

/** A card on the Explore hub. */
function ui_card(string $href, string $icon, string $title, string $text, string $tone = ''): string
{
    return "<a class='hub-card {$tone}' href='{$href}'><span class='hub-ic'>" . ui_icon($icon, 22) . "</span><b>" . e($title) . '</b><small>' . e($text) . '</small></a>';
}
