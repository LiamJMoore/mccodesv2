<?php
declare(strict_types=1);

/**
 * The database changes engine v3 needs, applied by the game itself.
 *
 * v2 handed game owners .sql files to run by hand. v3 keeps a numbered list of steps here and remembers, in the
 * settings table, the last one applied. The start-up path compares the two on every page (one array lookup) and,
 * when the game is behind, applies what is missing once. A fresh install and a game that has run for years both
 * end up on the same schema with nobody opening phpMyAdmin.
 *
 * Rules for a step: it must be safe to run on a game that already has it (CREATE TABLE IF NOT EXISTS, and so on),
 * and once released a step is never edited: a correction is a new step.
 */
const MCC_SCHEMA_STEPS = [
    // 1: the tables money and items live in must be able to undo a half-finished change. Games first installed on
    //    very old MCCodes may still have them as MyISAM, which cannot. (Fresh installs are InnoDB already.)
    1 => ['@innodb users inventory itemmarket crystalmarket'],
    // 2: the daily reward
    2 => ["CREATE TABLE IF NOT EXISTS `v3_daily` (
              `userid` INT NOT NULL PRIMARY KEY,
              `last_claim` DATE NOT NULL,
              `streak` INT NOT NULL DEFAULT 0,
              `best` INT NOT NULL DEFAULT 0,
              `claims` INT NOT NULL DEFAULT 0
          ) ENGINE=InnoDB"],
    // 3: counters of what players have done, the achievements they hold, and each day's missions
    3 => ["CREATE TABLE IF NOT EXISTS `v3_progress` (
              `userid` INT NOT NULL,
              `stat` VARCHAR(32) NOT NULL,
              `amount` BIGINT NOT NULL DEFAULT 0,
              PRIMARY KEY (`userid`, `stat`),
              KEY `top` (`stat`, `amount`)
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_achievements` (
              `userid` INT NOT NULL,
              `ach` VARCHAR(32) NOT NULL,
              `earned` INT NOT NULL,
              PRIMARY KEY (`userid`, `ach`)
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_missions` (
              `userid` INT NOT NULL,
              `day` DATE NOT NULL,
              `slot` TINYINT NOT NULL,
              `mission` VARCHAR(32) NOT NULL,
              `stat` VARCHAR(32) NOT NULL,
              `target` INT NOT NULL,
              `progress` INT NOT NULL DEFAULT 0,
              `reward` BIGINT NOT NULL DEFAULT 0,
              `claimed` TINYINT NOT NULL DEFAULT 0,
              PRIMARY KEY (`userid`, `day`, `slot`),
              KEY `counting` (`userid`, `day`, `stat`)
          ) ENGINE=InnoDB"],
    // 4: bounties
    4 => ["CREATE TABLE IF NOT EXISTS `v3_bounties` (
              `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `target` INT NOT NULL,
              `placer` INT NOT NULL,
              `amount` BIGINT NOT NULL,
              `anonymous` TINYINT NOT NULL DEFAULT 0,
              `placed` INT NOT NULL,
              `status` ENUM('open', 'collected', 'withdrawn') NOT NULL DEFAULT 'open',
              `collector` INT NULL,
              `closed` INT NULL,
              KEY `open_on` (`status`, `target`),
              KEY `placed_by` (`placer`, `status`)
          ) ENGINE=InnoDB"],
    // 5: crafting recipes
    5 => ["CREATE TABLE IF NOT EXISTS `v3_recipes` (
              `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `name` VARCHAR(100) NOT NULL,
              `result_item` INT NOT NULL,
              `result_qty` INT NOT NULL DEFAULT 1,
              `min_level` INT NOT NULL DEFAULT 0,
              `cost` BIGINT NOT NULL DEFAULT 0,
              `enabled` TINYINT NOT NULL DEFAULT 1
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_recipe_parts` (
              `recipe` INT NOT NULL,
              `item` INT NOT NULL,
              `qty` INT NOT NULL,
              PRIMARY KEY (`recipe`, `item`)
          ) ENGINE=InnoDB"],
    // 6: player API keys (only a fingerprint of each key is kept)
    6 => ["CREATE TABLE IF NOT EXISTS `v3_api_keys` (
              `userid` INT NOT NULL PRIMARY KEY,
              `key_hash` CHAR(64) NOT NULL,
              `created` INT NOT NULL,
              `last_used` INT NOT NULL DEFAULT 0,
              `calls` BIGINT NOT NULL DEFAULT 0,
              `window_start` INT NOT NULL DEFAULT 0,
              `window_calls` INT NOT NULL DEFAULT 0,
              UNIQUE KEY `by_key` (`key_hash`)
          ) ENGINE=InnoDB"],
    // 7: one row of game totals a day, for the staff dashboard's trends
    7 => ["CREATE TABLE IF NOT EXISTS `v3_snapshots` (
              `day` DATE NOT NULL PRIMARY KEY,
              `players` INT NOT NULL DEFAULT 0,
              `active` INT NOT NULL DEFAULT 0,
              `signups` INT NOT NULL DEFAULT 0,
              `money` BIGINT NOT NULL DEFAULT 0,
              `crystals` BIGINT NOT NULL DEFAULT 0
          ) ENGINE=InnoDB",
          "INSERT INTO `settings` (`conf_name`, `conf_value`, `data_type`) SELECT 'engine_snapshot_day', '', 'string' FROM DUAL
           WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `conf_name` = 'engine_snapshot_day')"],
    // 8: the season pass
    8 => ["CREATE TABLE IF NOT EXISTS `v3_season` (
              `userid` INT NOT NULL,
              `season` CHAR(7) NOT NULL,
              `xp` INT NOT NULL DEFAULT 0,
              `premium` TINYINT NOT NULL DEFAULT 0,
              `gym_day` DATE NULL,
              `gym_points` INT NOT NULL DEFAULT 0,
              PRIMARY KEY (`userid`, `season`),
              KEY `top` (`season`, `xp`)
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_season_claims` (
              `userid` INT NOT NULL,
              `season` CHAR(7) NOT NULL,
              `tier` TINYINT NOT NULL,
              `track` ENUM('free', 'premium') NOT NULL,
              PRIMARY KEY (`userid`, `season`, `tier`, `track`)
          ) ENGINE=InnoDB"],
    // 9: payments taken by the store
    9 => ["CREATE TABLE IF NOT EXISTS `v3_payments` (
              `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `provider` VARCHAR(16) NOT NULL,
              `ref` VARCHAR(191) NOT NULL,
              `intent` VARCHAR(191) NOT NULL DEFAULT '',
              `userid` INT NOT NULL,
              `pack` VARCHAR(32) NOT NULL,
              `amount` INT NOT NULL,
              `currency` CHAR(3) NOT NULL,
              `crystals` INT NOT NULL DEFAULT 0,
              `donator_days` INT NOT NULL DEFAULT 0,
              `created` INT NOT NULL,
              `status` ENUM('paid', 'refunded') NOT NULL DEFAULT 'paid',
              UNIQUE KEY `once` (`provider`, `ref`),
              KEY `by_player` (`userid`),
              KEY `by_intent` (`intent`),
              KEY `by_time` (`created`)
          ) ENGINE=InnoDB"],
    // 10: what players have bought in the crystal shop, and what they have used of each day's limits
    10 => ["CREATE TABLE IF NOT EXISTS `v3_perks` (
              `userid` INT NOT NULL,
              `perk` VARCHAR(40) NOT NULL,
              `value` VARCHAR(40) NOT NULL DEFAULT '',
              `bought` INT NOT NULL,
              PRIMARY KEY (`userid`, `perk`)
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_perk_uses` (
              `userid` INT NOT NULL,
              `day` DATE NOT NULL,
              `perk` VARCHAR(40) NOT NULL,
              `uses` INT NOT NULL DEFAULT 0,
              PRIMARY KEY (`userid`, `day`, `perk`)
          ) ENGINE=InnoDB"],
    // 11: the stock market
    11 => ["CREATE TABLE IF NOT EXISTS `v3_stocks` (
              `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `symbol` VARCHAR(8) NOT NULL,
              `name` VARCHAR(60) NOT NULL,
              `price` INT NOT NULL,
              `prev` INT NOT NULL,
              `base` INT NOT NULL,
              `swing` INT NOT NULL,
              UNIQUE KEY `symbol` (`symbol`)
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_stock_holdings` (
              `userid` INT NOT NULL,
              `stock` INT NOT NULL,
              `shares` BIGINT NOT NULL DEFAULT 0,
              `cost` BIGINT NOT NULL DEFAULT 0,
              PRIMARY KEY (`userid`, `stock`)
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_stock_history` (
              `stock` INT NOT NULL,
              `at` INT NOT NULL,
              `price` INT NOT NULL,
              PRIMARY KEY (`stock`, `at`)
          ) ENGINE=InnoDB",
          "INSERT IGNORE INTO `v3_stocks` (`symbol`, `name`, `price`, `prev`, `base`, `swing`) VALUES ('BRIX', 'Brixton Scrap & Salvage', 120, 120, 120, 25), ('NEON', 'Neon Row Casinos', 850, 850, 850, 45), ('DOCK', 'Eastdock Freight', 310, 310, 310, 20), ('PAWN', 'Golden Pawn Holdings', 65, 65, 65, 35), ('VLT', 'Vault Street Security', 1400, 1400, 1400, 15), ('RUSH', 'Rush Courier Co', 40, 40, 40, 60), ('IRON', 'Ironworks Gym Group', 520, 520, 520, 30), ('SMOK', 'Smokestack Brewing', 215, 215, 215, 40)",
          "INSERT INTO `settings` (`conf_name`, `conf_value`, `data_type`) SELECT 'engine_stock_tick', '0', 'int' FROM DUAL
           WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `conf_name` = 'engine_stock_tick')"],
    // 12: gang territory
    12 => ["CREATE TABLE IF NOT EXISTS `v3_turf` (
              `city` INT NOT NULL,
              `gang` INT NOT NULL,
              `week` CHAR(8) NOT NULL,
              `points` INT NOT NULL DEFAULT 0,
              PRIMARY KEY (`city`, `gang`, `week`),
              KEY `standings` (`week`, `city`, `points`)
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_turf_wins` (
              `week` CHAR(8) NOT NULL,
              `city` INT NOT NULL,
              `gang` INT NOT NULL,
              `points` INT NOT NULL,
              `money` BIGINT NOT NULL,
              `crystals` INT NOT NULL,
              PRIMARY KEY (`week`, `city`)
          ) ENGINE=InnoDB",
          "INSERT INTO `settings` (`conf_name`, `conf_value`, `data_type`) SELECT 'engine_turf_week', '', 'string' FROM DUAL
           WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `conf_name` = 'engine_turf_week')"],
    // 13: the daily lottery
    13 => ["CREATE TABLE IF NOT EXISTS `v3_lotto_tickets` (
              `day` DATE NOT NULL,
              `userid` INT NOT NULL,
              `tickets` INT NOT NULL DEFAULT 0,
              PRIMARY KEY (`day`, `userid`)
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_lotto_draws` (
              `day` DATE NOT NULL PRIMARY KEY,
              `tickets` INT NOT NULL,
              `players` INT NOT NULL,
              `pot` BIGINT NOT NULL,
              `winner` INT NOT NULL
          ) ENGINE=InnoDB",
          "INSERT INTO `settings` (`conf_name`, `conf_value`, `data_type`) SELECT 'engine_lotto_day', '', 'string' FROM DUAL
           WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `conf_name` = 'engine_lotto_day')"],
    // 14: pets
    14 => ["CREATE TABLE IF NOT EXISTS `v3_pets` (
              `userid` INT NOT NULL PRIMARY KEY,
              `name` VARCHAR(20) NOT NULL,
              `type` VARCHAR(20) NOT NULL,
              `level` INT NOT NULL DEFAULT 1,
              `xp` INT NOT NULL DEFAULT 0,
              `speed` INT NOT NULL DEFAULT 10,
              `stamina` INT NOT NULL DEFAULT 10,
              `last_fed` INT NOT NULL DEFAULT 0,
              `last_trained` INT NOT NULL DEFAULT 0,
              `races` INT NOT NULL DEFAULT 0,
              `wins` INT NOT NULL DEFAULT 0,
              KEY `champions` (`wins`)
          ) ENGINE=InnoDB"],
    // 15: travel trading, fixed-term investments, profile comments
    15 => ["CREATE TABLE IF NOT EXISTS `v3_trade_stock` (
              `city` INT NOT NULL,
              `good` VARCHAR(20) NOT NULL,
              `stock` INT NOT NULL,
              `at` INT NOT NULL,
              PRIMARY KEY (`city`, `good`)
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_trade_cargo` (
              `userid` INT NOT NULL,
              `good` VARCHAR(20) NOT NULL,
              `qty` INT NOT NULL DEFAULT 0,
              `paid` BIGINT NOT NULL DEFAULT 0,
              PRIMARY KEY (`userid`, `good`)
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_investments` (
              `userid` INT NOT NULL PRIMARY KEY,
              `amount` BIGINT NOT NULL,
              `rate` INT NOT NULL,
              `started` INT NOT NULL,
              `matures` INT NOT NULL
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_profile_comments` (
              `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `profile` INT NOT NULL,
              `author` INT NOT NULL,
              `at` INT NOT NULL,
              `text` VARCHAR(255) NOT NULL,
              KEY `on_profile` (`profile`, `id`),
              KEY `by_author` (`author`, `at`)
          ) ENGINE=InnoDB"],
    // 16: the ledger of every change to money and crystals
    16 => ["CREATE TABLE IF NOT EXISTS `v3_ledger` (
              `id` BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `userid` INT NOT NULL,
              `wallet` VARCHAR(12) NOT NULL,
              `delta` BIGINT NOT NULL,
              `at` INT NOT NULL,
              `page` VARCHAR(40) NOT NULL DEFAULT '',
              `note` VARCHAR(80) NOT NULL DEFAULT '',
              KEY `by_player` (`userid`, `id`),
              KEY `by_time` (`at`)
          ) ENGINE=InnoDB"],
    // 17: marriage and the auction house
    17 => ["CREATE TABLE IF NOT EXISTS `v3_proposals` (
              `proposer` INT NOT NULL PRIMARY KEY,
              `target` INT NOT NULL,
              `at` INT NOT NULL,
              KEY `to_whom` (`target`)
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_spouses` (
              `userid` INT NOT NULL PRIMARY KEY,
              `spouse` INT NOT NULL,
              `since` INT NOT NULL,
              `own_will` INT NULL,
              UNIQUE KEY `spouse` (`spouse`)
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_joint` (
              `a` INT NOT NULL,
              `b` INT NOT NULL,
              `balance` BIGINT NOT NULL DEFAULT 0,
              PRIMARY KEY (`a`, `b`)
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_auctions` (
              `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `seller` INT NOT NULL,
              `item` INT NOT NULL,
              `qty` INT NOT NULL,
              `start_bid` BIGINT NOT NULL,
              `bid` BIGINT NOT NULL DEFAULT 0,
              `bidder` INT NOT NULL DEFAULT 0,
              `ends` INT NOT NULL,
              `status` ENUM('open', 'sold', 'expired', 'cancelled') NOT NULL DEFAULT 'open',
              `created` INT NOT NULL,
              KEY `closing` (`status`, `ends`),
              KEY `by_seller` (`seller`, `status`)
          ) ENGINE=InnoDB"],
    // 18: the loan shark and support tickets
    18 => ["CREATE TABLE IF NOT EXISTS `v3_loans` (
              `userid` INT NOT NULL PRIMARY KEY,
              `principal` BIGINT NOT NULL,
              `repaid` BIGINT NOT NULL DEFAULT 0,
              `taken` INT NOT NULL,
              `due` INT NOT NULL,
              `chased` INT NOT NULL DEFAULT 0,
              KEY `overdue` (`due`)
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_tickets` (
              `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `userid` INT NOT NULL,
              `topic` VARCHAR(20) NOT NULL,
              `subject` VARCHAR(100) NOT NULL,
              `status` ENUM('waiting', 'answered', 'closed') NOT NULL DEFAULT 'waiting',
              `opened` INT NOT NULL,
              `updated` INT NOT NULL,
              KEY `by_player` (`userid`, `status`),
              KEY `queue` (`status`, `updated`)
          ) ENGINE=InnoDB",
          "CREATE TABLE IF NOT EXISTS `v3_ticket_posts` (
              `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `ticket` INT NOT NULL,
              `author` INT NOT NULL,
              `staff` TINYINT NOT NULL DEFAULT 0,
              `at` INT NOT NULL,
              `text` TEXT NOT NULL,
              KEY `in_ticket` (`ticket`, `id`),
              KEY `by_author` (`author`, `at`)
          ) ENGINE=InnoDB"],
    // 19: the fight log is read by time (mugger of the hour); the three player lists are read by their owner
    19 => ['ALTER TABLE `attacklogs` ADD INDEX `v3_time` (`time`)',
           'ALTER TABLE `friendslist` ADD INDEX `v3_adder` (`fl_ADDER`)',
           'ALTER TABLE `blacklist` ADD INDEX `v3_adder` (`bl_ADDER`)',
           'ALTER TABLE `contactlist` ADD INDEX `v3_adder` (`cl_ADDER`)'],
    // 20: merit upgrades
    20 => ['CREATE TABLE IF NOT EXISTS `v3_merits` (
              `userid` INT NOT NULL,
              `perk` VARCHAR(20) NOT NULL,
              `level` TINYINT NOT NULL DEFAULT 0,
              PRIMARY KEY (`userid`, `perk`)
          ) ENGINE=InnoDB'],
    // 21: journeys and vehicles
    21 => ['CREATE TABLE IF NOT EXISTS `v3_travel` (
              `userid` INT NOT NULL PRIMARY KEY,
              `dest` INT NOT NULL,
              `left_at` INT NOT NULL,
              `arrives` INT NOT NULL
          ) ENGINE=InnoDB',
          'CREATE TABLE IF NOT EXISTS `v3_vehicles` (
              `userid` INT NOT NULL PRIMARY KEY,
              `vehicle` VARCHAR(20) NOT NULL,
              `bought` INT NOT NULL
          ) ENGINE=InnoDB'],
    // 22: businesses and market stalls
    22 => ['CREATE TABLE IF NOT EXISTS `v3_business` (
              `userid` INT NOT NULL PRIMARY KEY,
              `kind` VARCHAR(20) NOT NULL,
              `level` TINYINT NOT NULL DEFAULT 1,
              `bought` INT NOT NULL,
              `collected` INT NOT NULL
          ) ENGINE=InnoDB',
          'CREATE TABLE IF NOT EXISTS `v3_stalls` (
              `userid` INT NOT NULL PRIMARY KEY,
              `name` VARCHAR(40) NOT NULL,
              `opened` INT NOT NULL
          ) ENGINE=InnoDB',
          'CREATE TABLE IF NOT EXISTS `v3_stall_stock` (
              `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `userid` INT NOT NULL,
              `item` INT NOT NULL,
              `qty` INT NOT NULL,
              `price` BIGINT NOT NULL,
              UNIQUE KEY `one_line` (`userid`, `item`),
              KEY `by_item` (`item`, `price`)
          ) ENGINE=InnoDB'],
];

function schema_latest(): int
{
    return max(array_keys(MCC_SCHEMA_STEPS));
}

/** Apply every step the game has not had. Returns the steps applied. Safe to call from several requests at once. */
function schema_upgrade(): array
{
    global $db, $set;
    $done = [];
    // one request does the work; any other arriving meanwhile waits for it, then finds nothing left to do
    if ((int)$db->value("SELECT GET_LOCK('mcc_schema', 20)") !== 1) {
        return $done;
    }
    try {
        $at = (int)$db->value("SELECT `conf_value` FROM `settings` WHERE `conf_name` = 'engine_schema'", [], 0);
        foreach (MCC_SCHEMA_STEPS as $n => $statements) {
            if ($n <= $at) {
                continue;
            }
            foreach ($statements as $sql) {
                if (str_starts_with($sql, '@innodb ')) {
                    foreach (explode(' ', substr($sql, 8)) as $table) {
                        $engine = $db->value('SELECT `ENGINE` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?', [$table]);
                        if ($engine !== null && strcasecmp((string)$engine, 'InnoDB') !== 0) {
                            $db->run("ALTER TABLE `{$table}` ENGINE=InnoDB");
                        }
                    }
                } else {
                    $db->run($sql);
                }
            }
            if ($db->value("SELECT COUNT(*) FROM `settings` WHERE `conf_name` = 'engine_schema'") > 0) {
                $db->exec("UPDATE `settings` SET `conf_value` = ? WHERE `conf_name` = 'engine_schema'", [(string)$n]);
            } else {
                $db->run("INSERT INTO `settings` (`conf_name`, `conf_value`, `data_type`) VALUES ('engine_schema', ?, 'int')", [(string)$n]);
            }
            $done[] = $n;
            $at = $n;
        }
        $set['engine_schema'] = $at;
    } finally {
        $db->run("SELECT RELEASE_LOCK('mcc_schema')");
    }
    return $done;
}
