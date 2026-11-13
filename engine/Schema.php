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
