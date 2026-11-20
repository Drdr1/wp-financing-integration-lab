<?php
declare(strict_types=1);

namespace FinancingBridge\Store;

final class Schema
{
    public const DB_VERSION = '1';

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $orders = $wpdb->prefix . 'finb_orders';
        $events = $wpdb->prefix . 'finb_events';
        $log = $wpdb->prefix . 'finb_log';

        // No approval code, delivery code or phone number is ever stored.
        dbDelta("CREATE TABLE {$orders} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  merchant_order_ref varchar(64) NOT NULL,
  state varchar(20) NOT NULL,
  amount_minor bigint(20) NOT NULL,
  currency char(3) NOT NULL,
  approval_id varchar(64) DEFAULT NULL,
  customer_ref varchar(64) DEFAULT NULL,
  po_id varchar(64) DEFAULT NULL,
  delivery_attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  review_reason varchar(255) DEFAULT NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY merchant_order_ref (merchant_order_ref),
  UNIQUE KEY po_id (po_id),
  KEY state (state)
) {$charset};");

        dbDelta("CREATE TABLE {$events} (
  event_id varchar(64) NOT NULL,
  type varchar(64) NOT NULL,
  po_id varchar(64) NOT NULL,
  outcome varchar(32) NOT NULL,
  received_at datetime NOT NULL,
  PRIMARY KEY  (event_id),
  KEY po_id (po_id)
) {$charset};");

        dbDelta("CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned DEFAULT NULL,
  level varchar(10) NOT NULL,
  message varchar(255) NOT NULL,
  context longtext NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id)
) {$charset};");

        update_option('finb_db_version', self::DB_VERSION, false);
    }
}
