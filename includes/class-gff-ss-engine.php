<?php
/**
 * Rule storage, price apply/revert engine and background (Action Scheduler) jobs.
 *
 * @package GFF_Scheduled_Sales
 */

defined( 'ABSPATH' ) || exit;

class GFF_SS_Engine {

	const GROUP      = 'gff-scheduled-sales';
	const CRON_BATCH = 25;

	public static function init() {
		add_action( 'gff_ss_start_rule', array( __CLASS__, 'cron_start' ) );
		add_action( 'gff_ss_end_rule', array( __CLASS__, 'cron_end' ) );
		add_action( 'gff_ss_continue', array( __CLASS__, 'cron_continue' ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'gff_ss_' . $name;
	}

	public static function now() {
		return current_time( 'mysql', true );
	}

	public static function statuses() {
		return array(
			'scheduled'   => __( 'Scheduled', 'gff-scheduled-sales' ),
			'processing'  => __( 'Applying…', 'gff-scheduled-sales' ),
			'active'      => __( 'Active', 'gff-scheduled-sales' ),
			'reverting'   => __( 'Deactivating…', 'gff-scheduled-sales' ),
			'deactivated' => __( 'Deactivated', 'gff-scheduled-sales' ),
			'ended'       => __( 'Ended', 'gff-scheduled-sales' ),
			'cancelled'   => __( 'Cancelled', 'gff-scheduled-sales' ),
		);
	}

	public static function plain_price( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	public static function get_rule( $id ) {
		global $wpdb;
		$rule = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'rules' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $rule ) {
			return null;
		}
		$rule->product_ids  = array_map( 'absint', (array) json_decode( $rule->product_ids ? $rule->product_ids : '[]', true ) );
		$rule->category_ids = array_map( 'absint', (array) json_decode( $rule->category_ids ? $rule->category_ids : '[]', true ) );
		return $rule;
	}

	public static function update_rule( $id, array $data ) {
		global $wpdb;
		$wpdb->update( self::table( 'rules' ), $data, array( 'id' => $id ) );
	}

	public static function log( $rule_id, $op, $level, $message, $product_id = 0 ) {
		global $wpdb;
		$wpdb->insert(
			self::table( 'logs' ),
			array(
				'rule_id'    => $rule_id,
				'op'         => $op,
				'level'      => $level,
				'product_id' => $product_id,
				'message'    => $message,
				'created_at' => self::now(),
			)
		);
		return array(
			'level'   => $level,
			'message' => $message,
			'time'    => wp_date( 'H:i:s' ),
		);
	}

	public static function discount_label( $rule ) {
		return 'percent' === $rule->discount_type
			? rtrim( rtrim( number_format( (float) $rule->discount_value, 2 ), '0' ), '.' ) . '% off'
			: self::plain_price( $rule->discount_value ) . ' off';
	}

	/* ---------------------------------------------------------------------
	 * Rule creation
	 * ------------------------------------------------------------------- */

	/**
	 * Parse a datetime-local value (site timezone) into a UTC timestamp.
	 */
	public static function parse_local_datetime( $value ) {
		$value = trim( (string) $value );
		foreach ( array( 'Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i' ) as $format ) {
			$dt = DateTime::createFromFormat( $format, $value, wp_timezone() );
			if ( $dt ) {
				return $dt->getTimestamp();
			}
		}
		return false;
	}

	/**
	 * @return int|WP_Error Rule ID.
	 */
	public static function create_rule( array $in ) {
		global $wpdb;

		$title    = sanitize_text_field( $in['title'] ?? '' );
		$mode     = ( $in['mode'] ?? '' ) === 'schedule' ? 'schedule' : 'immediate';
		$type     = ( $in['discount_type'] ?? '' ) === 'fixed' ? 'fixed' : 'percent';
		$value    = (float) wc_format_decimal( $in['discount_value'] ?? 0 );
		$products = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $in['product_ids'] ?? array() ) ) ) ) );
		$cats     = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $in['category_ids'] ?? array() ) ) ) ) );
		$errors   = array();
		$start    = null;
		$end      = null;

		if ( '' === $title ) {
			$errors[] = __( 'Please enter a title.', 'gff-scheduled-sales' );
		}
		if ( ! $products && ! $cats ) {
			$errors[] = __( 'Select at least one product or category.', 'gff-scheduled-sales' );
		}
		if ( $value <= 0 ) {
			$errors[] = __( 'Discount must be greater than zero.', 'gff-scheduled-sales' );
		} elseif ( 'percent' === $type && $value >= 100 ) {
			$errors[] = __( 'Percentage discount must be less than 100.', 'gff-scheduled-sales' );
		}
		if ( 'schedule' === $mode ) {
			$start = self::parse_local_datetime( $in['start_at'] ?? '' );
			$end   = self::parse_local_datetime( $in['end_at'] ?? '' );
			if ( ! $start ) {
				$errors[] = __( 'Please choose a valid start date.', 'gff-scheduled-sales' );
			} elseif ( $start <= time() ) {
				$errors[] = __( 'Start date must be in the future.', 'gff-scheduled-sales' );
			}
			if ( ! $end ) {
				$errors[] = __( 'Please choose a valid end date.', 'gff-scheduled-sales' );
			} elseif ( $start && $end <= $start ) {
				$errors[] = __( 'End date must be after the start date.', 'gff-scheduled-sales' );
			}
		}
		if ( $errors ) {
			return new WP_Error( 'gff_ss_invalid', implode( ' ', $errors ) );
		}

		$wpdb->insert(
			self::table( 'rules' ),
			array(
				'title'          => $title,
				'mode'           => $mode,
				'start_at'       => $start ? gmdate( 'Y-m-d H:i:s', $start ) : null,
				'end_at'         => $end ? gmdate( 'Y-m-d H:i:s', $end ) : null,
				'product_ids'    => wp_json_encode( $products ),
				'category_ids'   => wp_json_encode( $cats ),
				'discount_type'  => $type,
				'discount_value' => $value,
				'status'         => 'schedule' === $mode ? 'scheduled' : 'processing',
				'created_by'     => get_current_user_id(),
				'created_at'     => self::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'gff_ss_db', __( 'Could not save the rule.', 'gff-scheduled-sales' ) );
		}

		$user = wp_get_current_user();
		self::log( $id, 'system', 'info', sprintf( 'Rule created by %s.', $user->display_name ? $user->display_name : 'system' ) );

		if ( 'schedule' === $mode ) {
			as_schedule_single_action( $start, 'gff_ss_start_rule', array( 'rule_id' => $id ), self::GROUP );
			as_schedule_single_action( $end, 'gff_ss_end_rule', array( 'rule_id' => $id ), self::GROUP );
			self::log(
				$id,
				'system',
				'info',
				sprintf( 'Background jobs scheduled: start %s, end %s.', wp_date( 'M j, Y g:i a', $start ), wp_date( 'M j, Y g:i a', $end ) )
			);
		} else {
			self::start_apply( $id );
			self::schedule_fallback( $id );
		}

		return $id;
	}

	/* ---------------------------------------------------------------------
	 * Targets
	 * ------------------------------------------------------------------- */

	/**
	 * Expand selected products + categories into priceable product IDs (simple products and variations).
	 */
	public static function resolve_targets( array $product_ids, array $category_ids ) {
		$ids = $product_ids;

		if ( $category_ids ) {
			$slugs = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'include'    => $category_ids,
					'hide_empty' => false,
					'fields'     => 'id=>slug',
				)
			);
			if ( $slugs && ! is_wp_error( $slugs ) ) {
				$ids = array_merge(
					$ids,
					wc_get_products(
						array(
							'category' => array_values( $slugs ),
							'limit'    => -1,
							'return'   => 'ids',
							'status'   => array( 'publish', 'private', 'draft', 'pending', 'future' ),
						)
					)
				);
			}
		}

		$out = array();
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child ) {
					$out[] = (int) $child;
				}
			} elseif ( ! $product->is_type( 'grouped' ) ) {
				$out[] = (int) $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/* ---------------------------------------------------------------------
	 * Queue lifecycle
	 * ------------------------------------------------------------------- */

	public static function start_apply( $id ) {
		$rule    = self::get_rule( $id );
		$targets = self::resolve_targets( $rule->product_ids, $rule->category_ids );
		self::update_rule(
			$id,
			array(
				'status'       => 'processing',
				'queue'        => wp_json_encode( $targets ),
				'queue_op'     => 'apply',
				'queue_total'  => count( $targets ),
				'final_status' => 'active',
			)
		);
		self::log( $id, 'apply', 'info', sprintf( 'Applying sale (%s) to %d product(s)/variation(s).', self::discount_label( $rule ), count( $targets ) ) );
	}

	public static function start_revert( $id, $final_status ) {
		global $wpdb;
		$ids = array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT product_id FROM ' . self::table( 'items' ) . " WHERE rule_id = %d AND status = 'applied'", $id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		self::update_rule(
			$id,
			array(
				'status'       => 'reverting',
				'queue'        => wp_json_encode( $ids ),
				'queue_op'     => 'revert',
				'queue_total'  => count( $ids ),
				'final_status' => $final_status,
			)
		);
		self::log( $id, 'revert', 'info', sprintf( 'Removing sale price from %d product(s)/variation(s).', count( $ids ) ) );
	}

	/**
	 * Deactivate a rule: cancel if it has not started yet, otherwise queue a revert.
	 *
	 * @return array|WP_Error
	 */
	public static function deactivate( $id ) {
		$rule = self::get_rule( $id );
		if ( ! $rule ) {
			return new WP_Error( 'gff_ss_missing', __( 'Rule not found.', 'gff-scheduled-sales' ) );
		}
		$user = wp_get_current_user();

		if ( 'scheduled' === $rule->status ) {
			self::unschedule( $id );
			self::update_rule(
				$id,
				array(
					'status'         => 'cancelled',
					'reverted_at'    => self::now(),
					'deactivated_by' => get_current_user_id(),
				)
			);
			$log = self::log( $id, 'revert', 'warning', sprintf( 'Schedule cancelled by %s before it started. No products were changed.', $user->display_name ) );
			return array(
				'mode' => 'cancelled',
				'logs' => array( $log ),
			);
		}

		if ( 'active' !== $rule->status ) {
			return new WP_Error( 'gff_ss_state', __( 'This rule is currently being processed. Please wait until it finishes.', 'gff-scheduled-sales' ) );
		}

		self::unschedule( $id );
		self::log( $id, 'revert', 'warning', sprintf( 'Deactivated by %s.', $user->display_name ) );
		self::update_rule( $id, array( 'deactivated_by' => get_current_user_id() ) );
		self::start_revert( $id, 'deactivated' );
		self::schedule_fallback( $id );
		return array( 'mode' => 'revert' );
	}

	public static function unschedule( $id ) {
		foreach ( array( 'gff_ss_start_rule', 'gff_ss_end_rule', 'gff_ss_continue' ) as $hook ) {
			as_unschedule_all_actions( $hook, array( 'rule_id' => (int) $id ), self::GROUP );
		}
	}

	/**
	 * If the browser is closed mid-run, Action Scheduler finishes the job.
	 */
	public static function schedule_fallback( $id ) {
		as_schedule_single_action( time() + 120, 'gff_ss_continue', array( 'rule_id' => (int) $id ), self::GROUP );
	}

	/* ---------------------------------------------------------------------
	 * Locking
	 * ------------------------------------------------------------------- */

	private static function lock( $id ) {
		$key = 'gff_ss_lock_' . $id;
		if ( add_option( $key, time(), '', 'no' ) ) {
			return true;
		}
		$since = (int) get_option( $key );
		if ( $since && $since < time() - 120 ) {
			delete_option( $key );
			return add_option( $key, time(), '', 'no' );
		}
		return false;
	}

	private static function unlock( $id ) {
		delete_option( 'gff_ss_lock_' . $id );
	}

	/* ---------------------------------------------------------------------
	 * Batch processing
	 * ------------------------------------------------------------------- */

	public static function process_batch( $id, $limit ) {
		if ( ! self::lock( $id ) ) {
			$rule = self::get_rule( $id );
			return self::progress( $rule, array(), array( 'busy' => true ) );
		}

		try {
			$rule = self::get_rule( $id );
			if ( ! $rule || ! $rule->queue_op ) {
				return self::progress( $rule, array(), array( 'done' => true ) );
			}

			$op      = $rule->queue_op;
			$queue   = (array) json_decode( $rule->queue ? $rule->queue : '[]', true );
			$batch   = array_splice( $queue, 0, $limit );
			$logs    = array();
			$parents = array();
			$current = '';

			foreach ( $batch as $pid ) {
				$result = 'apply' === $op ? self::apply_one( $rule, (int) $pid ) : self::revert_one( $rule, (int) $pid );
				$logs[] = $result['log'];
				if ( $result['parent_id'] ) {
					$parents[] = $result['parent_id'];
				}
				$current = $result['name'];
			}

			foreach ( array_unique( $parents ) as $parent_id ) {
				WC_Product_Variable::sync( $parent_id );
				wc_delete_product_transients( $parent_id );
			}

			self::update_rule( $id, array( 'queue' => wp_json_encode( array_values( $queue ) ) ) );

			$done = empty( $queue );
			if ( $done ) {
				$logs = array_merge( $logs, self::finalize( $id ) );
			}

			$rule = self::get_rule( $id );
			return self::progress(
				$rule,
				$logs,
				array(
					'done'      => $done,
					'current'   => $current,
					'op'        => $op,
					'remaining' => count( $queue ),
				)
			);
		} finally {
			self::unlock( $id );
		}
	}

	private static function progress( $rule, array $logs, array $extra ) {
		$total     = $rule ? (int) $rule->queue_total : 0;
		$remaining = isset( $extra['remaining'] ) ? (int) $extra['remaining'] : ( $rule && $rule->queue ? count( (array) json_decode( $rule->queue, true ) ) : 0 );
		$done      = ! empty( $extra['done'] );
		return array_merge(
			array(
				'busy'      => false,
				'done'      => $done,
				'total'     => $total,
				'processed' => $done ? $total : max( 0, $total - $remaining ),
				'logs'      => $logs,
				'current'   => '',
				'status'    => $rule ? $rule->status : '',
				'report'    => $done && $rule ? self::report( $rule->id ) : null,
			),
			$extra
		);
	}

	private static function finalize( $id ) {
		$rule = self::get_rule( $id );
		$logs = array();
		$data = array(
			'status'   => $rule->final_status,
			'queue'    => null,
			'queue_op' => '',
		);

		if ( 'apply' === $rule->queue_op ) {
			$data['applied_at'] = self::now();
			$r                  = self::report( $id );
			$logs[]             = self::log( $id, 'apply', 'success', sprintf( 'Sale is live. Updated: %d, skipped: %d, errors: %d.', $r['applied'], $r['skipped'], $r['error'] ) );
		} else {
			$data['reverted_at'] = self::now();
			$r                   = self::report( $id );
			$label               = 'ended' === $rule->final_status ? 'Scheduled sale ended.' : 'Sale deactivated.';
			$logs[]              = self::log( $id, 'revert', 'success', sprintf( '%s Restored: %d, left untouched: %d.', $label, $r['reverted'], $r['revert_skipped'] ) );
		}

		self::update_rule( $id, $data );
		self::purge_cache();

		// Late start: end time already passed while applying.
		if ( 'apply' === $rule->queue_op && 'schedule' === $rule->mode && $rule->end_at && strtotime( $rule->end_at . ' UTC' ) <= time() ) {
			self::start_revert( $id, 'ended' );
			as_enqueue_async_action( 'gff_ss_continue', array( 'rule_id' => (int) $id ), self::GROUP );
		}

		return $logs;
	}

	private static function purge_cache() {
		wc_delete_product_transients();
		if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
			sg_cachepress_purge_everything();
		}
		do_action( 'gff_ss_prices_changed' );
	}

	public static function report( $id ) {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS c FROM ' . self::table( 'items' ) . ' WHERE rule_id = %d GROUP BY status', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$counts = array(
			'applied'        => 0,
			'skipped'        => 0,
			'error'          => 0,
			'reverted'       => 0,
			'revert_skipped' => 0,
		);
		foreach ( $rows as $row ) {
			$counts[ $row->status ] = (int) $row->c;
		}
		// Reverted items were applied too.
		$counts['updated'] = $counts['applied'] + $counts['reverted'] + $counts['revert_skipped'];
		return $counts;
	}

	/* ---------------------------------------------------------------------
	 * Single product operations
	 * ------------------------------------------------------------------- */

	private static function result( $rule, $pid, $parent_id, $name, $level, $message ) {
		return array(
			'parent_id' => $parent_id,
			'name'      => $name,
			'log'       => self::log( $rule->id, $rule->queue_op, $level, $name . ' — ' . $message, $pid ),
		);
	}

	private static function save_item( $rule, $pid, $parent_id, $name, array $data ) {
		global $wpdb;
		$wpdb->insert(
			self::table( 'items' ),
			array_merge(
				array(
					'rule_id'      => $rule->id,
					'product_id'   => $pid,
					'parent_id'    => $parent_id,
					'product_name' => mb_substr( $name, 0, 250 ),
				),
				$data
			)
		);
	}

	private static function apply_one( $rule, $pid ) {
		global $wpdb;

		$product = wc_get_product( $pid );
		if ( ! $product ) {
			self::save_item( $rule, $pid, 0, '#' . $pid, array( 'status' => 'error', 'message' => 'Product not found.' ) );
			return self::result( $rule, $pid, 0, '#' . $pid, 'error', 'Product not found.' );
		}

		$name      = wp_strip_all_tags( $product->get_name() ) . ' (#' . $pid . ')';
		$parent_id = (int) $product->get_parent_id();
		$regular   = $product->get_regular_price( 'edit' );
		$skip      = function ( $message ) use ( $rule, $pid, $parent_id, $name, $regular ) {
			self::save_item(
				$rule,
				$pid,
				$parent_id,
				$name,
				array(
					'regular_price' => (string) $regular,
					'status'        => 'skipped',
					'message'       => $message,
				)
			);
			return self::result( $rule, $pid, 0, $name, 'warning', 'Skipped: ' . $message );
		};

		$conflict = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT rule_id FROM ' . self::table( 'items' ) . " WHERE product_id = %d AND status = 'applied' AND rule_id <> %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$pid,
				$rule->id
			)
		);
		if ( $conflict ) {
			return $skip( sprintf( 'already discounted by sale rule #%d.', $conflict ) );
		}
		if ( '' === $regular || (float) $regular <= 0 ) {
			return $skip( 'no regular price set.' );
		}

		$regular_f = (float) $regular;
		$value     = (float) $rule->discount_value;
		$new       = 'percent' === $rule->discount_type ? $regular_f * ( 1 - $value / 100 ) : $regular_f - $value;
		$new       = round( $new, wc_get_price_decimals() );

		if ( $new <= 0 ) {
			return $skip( sprintf( 'discount is larger than the price (%s).', self::plain_price( $regular_f ) ) );
		}
		if ( $new >= $regular_f ) {
			return $skip( 'discount does not lower the price.' );
		}

		$orig_sale = (string) $product->get_sale_price( 'edit' );
		$from      = $product->get_date_on_sale_from( 'edit' );
		$to        = $product->get_date_on_sale_to( 'edit' );

		try {
			$product->set_sale_price( wc_format_decimal( $new, wc_get_price_decimals() ) );
			$product->set_date_on_sale_from( null );
			$product->set_date_on_sale_to( null );
			$product->save();
			wc_delete_product_transients( $pid );
		} catch ( Exception $e ) {
			self::save_item( $rule, $pid, $parent_id, $name, array( 'status' => 'error', 'message' => $e->getMessage() ) );
			return self::result( $rule, $pid, 0, $name, 'error', 'Error: ' . $e->getMessage() );
		}

		self::save_item(
			$rule,
			$pid,
			$parent_id,
			$name,
			array(
				'regular_price'   => (string) $regular,
				'orig_sale_price' => $orig_sale,
				'orig_sale_from'  => $from ? $from->getTimestamp() : null,
				'orig_sale_to'    => $to ? $to->getTimestamp() : null,
				'new_sale_price'  => wc_format_decimal( $new, wc_get_price_decimals() ),
				'status'          => 'applied',
				'applied_at'      => self::now(),
			)
		);

		$message = sprintf( '%s → %s', self::plain_price( $regular_f ), self::plain_price( $new ) );
		if ( '' !== $orig_sale ) {
			$message .= sprintf( ' (replaced existing sale price %s; it will be restored later)', self::plain_price( $orig_sale ) );
		}
		return self::result( $rule, $pid, $parent_id, $name, 'success', $message );
	}

	private static function revert_one( $rule, $pid ) {
		global $wpdb;
		$items = self::table( 'items' );
		$item  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$items} WHERE rule_id = %d AND product_id = %d AND status = 'applied' ORDER BY id DESC LIMIT 1", $rule->id, $pid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $item ) {
			return self::result( $rule, $pid, 0, '#' . $pid, 'info', 'Nothing to restore.' );
		}

		$name    = $item->product_name;
		$product = wc_get_product( $pid );
		$mark    = function ( $status, $message ) use ( $wpdb, $items, $item ) {
			$wpdb->update(
				$items,
				array(
					'status'      => $status,
					'message'     => $message,
					'reverted_at' => self::now(),
				),
				array( 'id' => $item->id )
			);
		};

		if ( ! $product ) {
			$mark( 'revert_skipped', 'Product no longer exists.' );
			return self::result( $rule, $pid, 0, $name, 'warning', 'Product no longer exists — skipped.' );
		}

		$current = (string) $product->get_sale_price( 'edit' );
		if ( '' === $current || abs( (float) $current - (float) $item->new_sale_price ) > 0.0001 ) {
			$msg = '' === $current ? 'sale price was removed manually' : sprintf( 'sale price was changed manually to %s', self::plain_price( $current ) );
			$mark( 'revert_skipped', ucfirst( $msg ) . '; left untouched.' );
			return self::result( $rule, $pid, (int) $product->get_parent_id(), $name, 'warning', 'Left untouched: ' . $msg . '.' );
		}

		try {
			$product->set_sale_price( $item->orig_sale_price );
			$product->set_date_on_sale_from( $item->orig_sale_from ? (int) $item->orig_sale_from : null );
			$product->set_date_on_sale_to( $item->orig_sale_to ? (int) $item->orig_sale_to : null );
			$product->save();
			wc_delete_product_transients( $pid );
		} catch ( Exception $e ) {
			return self::result( $rule, $pid, 0, $name, 'error', 'Error: ' . $e->getMessage() );
		}

		$msg = '' === $item->orig_sale_price
			? sprintf( 'sale removed, back to %s', self::plain_price( $product->get_regular_price( 'edit' ) ) )
			: sprintf( 'previous sale price %s restored', self::plain_price( $item->orig_sale_price ) );
		$mark( 'reverted', ucfirst( $msg ) . '.' );
		return self::result( $rule, $pid, (int) $product->get_parent_id(), $name, 'success', ucfirst( $msg ) . '.' );
	}

	/* ---------------------------------------------------------------------
	 * Background jobs
	 * ------------------------------------------------------------------- */

	public static function cron_start( $rule_id ) {
		$rule = self::get_rule( $rule_id );
		if ( ! $rule || 'scheduled' !== $rule->status ) {
			return;
		}
		self::log( $rule_id, 'apply', 'info', 'Scheduled start reached (background job).' );
		self::start_apply( $rule_id );
		self::run_background( $rule_id );
	}

	public static function cron_end( $rule_id ) {
		$rule = self::get_rule( $rule_id );
		if ( ! $rule ) {
			return;
		}
		if ( 'processing' === $rule->status ) {
			// Still applying — finalize() will trigger the revert once done.
			return;
		}
		if ( 'active' !== $rule->status ) {
			return;
		}
		self::log( $rule_id, 'revert', 'info', 'Scheduled end reached (background job).' );
		self::start_revert( $rule_id, 'ended' );
		self::run_background( $rule_id );
	}

	public static function cron_continue( $rule_id ) {
		$rule = self::get_rule( $rule_id );
		if ( ! $rule || ! $rule->queue_op ) {
			return;
		}
		self::run_background( $rule_id );
	}

	private static function run_background( $rule_id ) {
		$res = self::process_batch( $rule_id, self::CRON_BATCH );
		if ( ! empty( $res['busy'] ) ) {
			as_schedule_single_action( time() + 30, 'gff_ss_continue', array( 'rule_id' => (int) $rule_id ), self::GROUP );
		} elseif ( empty( $res['done'] ) ) {
			as_enqueue_async_action( 'gff_ss_continue', array( 'rule_id' => (int) $rule_id ), self::GROUP );
		}
	}
}
