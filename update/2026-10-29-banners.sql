-- ---------------------------------------------------------------------------
-- Migracion: feature de banners
--
-- Las tablas `banners`, `banner_to_store` y `banner_images` NO vienen en el
-- dump del proveedor (BASE DE DATOS/pos_moderno.sql) ni en las migraciones
-- v2->v3 (update-3.0.sql). Sin embargo _inc/model/banner.php las usa, y
-- admin/banner.php las lista, por lo que el modulo fallaba con
-- "Table 'pos_moderno.banners' doesn't exist".
--
-- El esquema se deduce de las consultas del propio modelo:
--   banners          -> INSERT (name, slug, created_at) / WHERE id / WHERE slug
--   banner_to_store  -> banner_id, store_id, status, sort_order
--   banner_images    -> banner_id, url, link, sort_order
--
-- Se sigue la convencion del resto del esquema: InnoDB, utf8/utf8_unicode_ci,
-- y las tablas *_to_store con id, store_id, status y sort_order.
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
