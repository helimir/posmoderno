-- ---------------------------------------------------------------------------
-- Migration: banners feature
--
-- The `banners`, `banner_to_store` and `banner_images` tables are NOT part of
-- the vendor dump (BASE DE DATOS/pos_moderno.sql) nor of the migrations
-- v2->v3 (update-3.0.sql). However _inc/model/banner.php uses them, and
-- admin/banner.php lists them, so the module was failing with
-- "Table 'pos_moderno.banners' doesn't exist".
--
-- The schema is derived from the model's own queries:
--   banners          -> INSERT (name, slug, created_at) / WHERE id / WHERE slug
--   banner_to_store  -> banner_id, store_id, status, sort_order
--   banner_images    -> banner_id, url, link, sort_order
--
-- It follows the convention of the rest of the schema: InnoDB, utf8/utf8_unicode_ci,
-- and the *_to_store tables with id, store_id, status and sort_order.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `banners` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(55) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `banner_to_store` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `banner_id` int(11) NOT NULL,
  `store_id` int(11) NOT NULL DEFAULT 1,
  `status` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `sort_order` int(10) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `banner_id` (`banner_id`),
  KEY `store_id` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `banner_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `banner_id` int(11) NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `sort_order` int(10) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `banner_id` (`banner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
