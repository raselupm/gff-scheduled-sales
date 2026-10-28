<?php
/**
 * Database tables.
 *
 * @package GFF_Scheduled_Sales
 */

defined( 'ABSPATH' ) || exit;

class GFF_SS_Install {

	const DB_VERSION = '1.0.0';

	public static function maybe_upgrade() {
		if ( get_option( 'gff_ss_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$rules   = $wpdb->prefix . 'gff_ss_rules';
		$items   = $wpdb->prefix . 'gff_ss_items';
		$logs    = $wpdb->prefix . 'gff_ss_logs';

		dbDelta(
			"CREATE TABLE {$rules} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL DEFAULT '',
			mode varchar(20) NOT NULL DEFAULT 'immediate',
			start_at datetime NULL DEFAULT NULL,
			end_at datetime NULL DEFAULT NULL,
			product_ids longtext NULL,
			category_ids longtext NULL,
			discount_type varchar(20) NOT NULL DEFAULT 'percent',
			discount_value decimal(19,4) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'scheduled',
			queue longtext NULL,
			queue_op varchar(20) NOT NULL DEFAULT '',
			queue_total int(11) NOT NULL DEFAULT 0,
			final_status varchar(20) NOT NULL DEFAULT '',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			deactivated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			applied_at datetime NULL DEFAULT NULL,
			reverted_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$collate};

		CREATE TABLE {$items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rule_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_name varchar(255) NOT NULL DEFAULT '',
			regular_price varchar(32) NOT NULL DEFAULT '',
			orig_sale_price varchar(32) NOT NULL DEFAULT '',
			orig_sale_from bigint(20) NULL DEFAULT NULL,
			orig_sale_to bigint(20) NULL DEFAULT NULL,
			new_sale_price varchar(32) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			message text NULL,
			applied_at datetime NULL DEFAULT NULL,
			reverted_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY rule_id (rule_id),
			KEY product_status (product_id,status)
		) {$collate};

		CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rule_id bigint(20) unsigned NOT NULL,
			op varchar(20) NOT NULL DEFAULT '',
			level varchar(20) NOT NULL DEFAULT 'info',
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY rule_id (rule_id)
		) {$collate};"
		);

		update_option( 'gff_ss_db_version', self::DB_VERSION );
	}
}
