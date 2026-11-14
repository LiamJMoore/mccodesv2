<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: the core every page runs on.
 *
 * v2 gave each page its own copy of the start-up code and built every SQL statement by hand. v3 keeps the game
 * exactly as players know it and puts one core underneath: one start-up path (engine/boot.php), database queries
 * with bound parameters (engine/DbPrepared.php), and one place for reading input and writing output safely
 * (engine/helpers.php). Pages move onto it one at a time; a page that has not moved yet still works.
 */
const MCC_ENGINE_VERSION = '3.0.0';
