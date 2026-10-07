-- MCCodes 2026: indexes for the lookups every page makes.
-- New installs get these from dbdata.sql. For an existing game, run this
-- file once (phpMyAdmin > SQL, or: mysql yourdb < upgrades/2026-10-indexes.sql).
-- Without them, "my inventory", "my mail", "who is online", gang pages and
-- forum threads each scan the whole table.

ALTER TABLE `users`
  ADD INDEX `idx_login_name` (`login_name`),
  ADD INDEX `idx_username` (`username`),
  ADD INDEX `idx_gang` (`gang`),
  ADD INDEX `idx_location` (`location`),
  ADD INDEX `idx_laston` (`laston`),
  ADD INDEX `idx_hospital` (`hospital`),
  ADD INDEX `idx_jail` (`jail`);
ALTER TABLE `inventory` ADD INDEX `idx_user_item` (`inv_userid`, `inv_itemid`);
ALTER TABLE `events` ADD INDEX `idx_user_time` (`evUSER`, `evTIME`);
ALTER TABLE `mail` ADD INDEX `idx_to_time` (`mail_to`, `mail_time`), ADD INDEX `idx_from` (`mail_from`);
ALTER TABLE `forum_posts` ADD INDEX `idx_topic_time` (`fp_topic_id`, `fp_time`);
ALTER TABLE `forum_topics` ADD INDEX `idx_forum` (`ft_forum_id`);
ALTER TABLE `gangevents` ADD INDEX `idx_gang_time` (`gevGANG`, `gevTIME`);
ALTER TABLE `itemmarket` ADD INDEX `idx_item` (`imITEM`), ADD INDEX `idx_adder` (`imADDER`);
ALTER TABLE `crystalmarket` ADD INDEX `idx_adder` (`cmADDER`);
ALTER TABLE `shopitems` ADD INDEX `idx_shop` (`sitemSHOP`);
ALTER TABLE `friendslist` ADD INDEX `idx_adder` (`fl_ADDER`);
ALTER TABLE `blacklist` ADD INDEX `idx_adder` (`bl_ADDER`);
ALTER TABLE `contactlist` ADD INDEX `idx_adder` (`cl_ADDER`);
ALTER TABLE `attacklogs` ADD INDEX `idx_attacker` (`attacker`), ADD INDEX `idx_attacked` (`attacked`);
