<?php
/**
 * Admin screens and AJAX endpoints.
 *
 * @package GFF_Scheduled_Sales
 */

defined( 'ABSPATH' ) || exit;

class GFF_SS_Admin {

	const SLUG       = 'gff-scheduled-sales';
	const AJAX_BATCH = 5;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GFF_SS_FILE ), array( __CLASS__, 'action_links' ) );

		foreach ( array( 'preview', 'create', 'batch', 'deactivate', 'delete' ) as $action ) {
			add_action( 'wp_ajax_gff_ss_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Manage Sales', 'gff-scheduled-sales' ) . '</a>' );
		return $links;
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Scheduled Sales', 'gff-scheduled-sales' ),
			__( 'Scheduled Sales', 'gff-scheduled-sales' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'gff-ss-admin', GFF_SS_URL . 'assets/admin.css', array(), GFF_SS_VERSION );
		wp_enqueue_script( 'gff-ss-admin', GFF_SS_URL . 'assets/admin.js', array( 'jquery', 'wc-enhanced-select' ), GFF_SS_VERSION, true );
		wp_localize_script(
			'gff-ss-admin',
			'GFF_SS',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'gff_ss' ),
				'listUrl' => self::url(),
				'viewUrl' => self::url( array( 'view' => 'rule' ) ),
				'now'      => current_time( 'Y-m-d\TH:i' ),
				'currency' => html_entity_decode( get_woocommerce_currency_symbol() ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	private static function guard() {
		check_ajax_referer( 'gff_ss', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gff-scheduled-sales' ) ), 403 );
		}
		wc_set_time_limit( 120 );
	}

	private static function posted_ids( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		return isset( $_POST[ $key ] ) ? array_map( 'absint', (array) wp_unslash( $_POST[ $key ] ) ) : array();
	}

	public static function ajax_preview() {
		self::guard();
		$ids    = GFF_SS_Engine::resolve_targets( self::posted_ids( 'product_ids' ), self::posted_ids( 'category_ids' ) );
		$sample = array();
		foreach ( array_slice( $ids, 0, 12 ) as $id ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				$sample[] = wp_strip_all_tags( $p->get_name() ) . ' — ' . GFF_SS_Engine::plain_price( $p->get_regular_price( 'edit' ) );
			}
		}
		wp_send_json_success(
			array(
				'count'  => count( $ids ),
				'sample' => $sample,
			)
		);
	}

	public static function ajax_create() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$input = array(
			'title'          => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'mode'           => isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '',
			'start_at'       => isset( $_POST['start_at'] ) ? sanitize_text_field( wp_unslash( $_POST['start_at'] ) ) : '',
			'end_at'         => isset( $_POST['end_at'] ) ? sanitize_text_field( wp_unslash( $_POST['end_at'] ) ) : '',
			'discount_type'  => isset( $_POST['discount_type'] ) ? sanitize_key( $_POST['discount_type'] ) : '',
			'discount_value' => isset( $_POST['discount_value'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_value'] ) ) : '',
			'product_ids'    => self::posted_ids( 'product_ids' ),
			'category_ids'   => self::posted_ids( 'category_ids' ),
		);
		// phpcs:enable

		$id = GFF_SS_Engine::create_rule( $input );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ) );
		}
		$rule = GFF_SS_Engine::get_rule( $id );
		wp_send_json_success(
			array(
				'rule_id' => $id,
				'mode'    => $rule->mode,
				'total'   => (int) $rule->queue_total,
				'title'   => $rule->title,
				'url'     => self::url(
					array(
						'view' => 'rule',
						'id'   => $id,
					)
				),
			)
		);
	}

	public static function ajax_batch() {
		self::guard();
		$id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! GFF_SS_Engine::get_rule( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Rule not found.', 'gff-scheduled-sales' ) ) );
		}
		wp_send_json_success( GFF_SS_Engine::process_batch( $id, self::AJAX_BATCH ) );
	}

	public static function ajax_deactivate() {
		self::guard();
		$id  = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$res = GFF_SS_Engine::deactivate( $id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		if ( 'cancelled' === $res['mode'] ) {
			$res['report'] = GFF_SS_Engine::report( $id );
		} else {
			$res['total'] = (int) GFF_SS_Engine::get_rule( $id )->queue_total;
		}
		wp_send_json_success( $res );
	}

	public static function ajax_delete() {
		self::guard();
		$id  = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$res = GFF_SS_Engine::delete_rule( $id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success();
	}

	/* ---------------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------------- */

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap gff-ss">';
		self::header( $view );
		if ( 'new' === $view ) {
			self::render_form();
		} elseif ( 'rule' === $view ) {
			self::render_rule( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} else {
			self::render_list();
		}
		self::render_modals();
		echo '</div>';
	}

	private static function header( $view ) {
		?>
		<div class="gff-ss-header">
			<div>
				<h1><?php esc_html_e( 'Scheduled Sales', 'gff-scheduled-sales' ); ?></h1>
				<p class="gff-ss-sub"><?php esc_html_e( 'Run immediate or scheduled discounts on products and categories.', 'gff-scheduled-sales' ); ?></p>
			</div>
			<div class="gff-ss-header-actions">
				<?php if ( 'list' !== $view ) : ?>
					<a class="button" href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'All rules', 'gff-scheduled-sales' ); ?></a>
				<?php endif; ?>
				<?php if ( 'new' !== $view ) : ?>
					<a class="button button-primary gff-ss-btn" href="<?php echo esc_url( self::url( array( 'view' => 'new' ) ) ); ?>">+ <?php esc_html_e( 'Add sale rule', 'gff-scheduled-sales' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function badge( $status ) {
		$labels = GFF_SS_Engine::statuses();
		return '<span class="gff-ss-badge gff-ss-badge--' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ?? $status ) . '</span>';
	}

	private static function fmt( $mysql_gmt ) {
		return $mysql_gmt ? wp_date( 'M j, Y g:i a', strtotime( $mysql_gmt . ' UTC' ) ) : '—';
	}

	private static function targets_label( $rule ) {
		$parts = array();
		if ( $rule->product_ids ) {
			/* translators: %d: number of products */
			$parts[] = sprintf( _n( '%d product', '%d products', count( $rule->product_ids ), 'gff-scheduled-sales' ), count( $rule->product_ids ) );
		}
		if ( $rule->category_ids ) {
			/* translators: %d: number of categories */
			$parts[] = sprintf( _n( '%d category', '%d categories', count( $rule->category_ids ), 'gff-scheduled-sales' ), count( $rule->category_ids ) );
		}
		return implode( ' + ', $parts );
	}

	private static function action_buttons( $rule ) {
		$out = '';
		if ( in_array( $rule->status, array( 'scheduled', 'active' ), true ) ) {
			$out .= sprintf(
				'<button type="button" class="button gff-ss-deactivate" data-id="%d" data-title="%s" data-status="%s">%s</button>',
				$rule->id,
				esc_attr( $rule->title ),
				esc_attr( $rule->status ),
				esc_html__( 'Deactivate', 'gff-scheduled-sales' )
			);
		} elseif ( in_array( $rule->status, array( 'processing', 'reverting' ), true ) ) {
			$out .= sprintf(
				'<button type="button" class="button gff-ss-resume" data-id="%d" data-title="%s" data-op="%s">%s</button>',
				$rule->id,
				esc_attr( $rule->title ),
				esc_attr( $rule->queue_op ),
				esc_html__( 'Show progress', 'gff-scheduled-sales' )
			);
		} elseif ( GFF_SS_Engine::is_deletable( $rule ) ) {
			$out .= sprintf(
				'<button type="button" class="button gff-ss-delete" data-id="%d" data-title="%s">%s</button>',
				$rule->id,
				esc_attr( $rule->title ),
				esc_html__( 'Delete', 'gff-scheduled-sales' )
			);
		}
		return $out;
	}

	private static function render_list() {
		global $wpdb;
		$rules_table = GFF_SS_Engine::table( 'rules' );
		$items_table = GFF_SS_Engine::table( 'items' );
		$paged       = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page    = 20;
		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rules_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids         = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$rules_table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, ( $paged - 1 ) * $per_page ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$counts = array();
		if ( $ids ) {
			$in   = implode( ',', array_map( 'intval', $ids ) );
			$rows = $wpdb->get_results( "SELECT rule_id, COUNT(*) AS c FROM {$items_table} WHERE rule_id IN ({$in}) AND status IN ('applied','reverted','revert_skipped') GROUP BY rule_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				$counts[ (int) $row->rule_id ] = (int) $row->c;
			}
		}

		$stats = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$rules_table} GROUP BY status", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stat  = function ( $s ) use ( $stats ) {
			return isset( $stats[ $s ] ) ? (int) $stats[ $s ]->c : 0;
		};
		?>
		<div class="gff-ss-stats">
			<div class="gff-ss-stat"><span><?php echo esc_html( $stat( 'active' ) ); ?></span><?php esc_html_e( 'Active', 'gff-scheduled-sales' ); ?></div>
			<div class="gff-ss-stat"><span><?php echo esc_html( $stat( 'scheduled' ) ); ?></span><?php esc_html_e( 'Scheduled', 'gff-scheduled-sales' ); ?></div>
			<div class="gff-ss-stat"><span><?php echo esc_html( $stat( 'ended' ) + $stat( 'deactivated' ) + $stat( 'cancelled' ) ); ?></span><?php esc_html_e( 'Finished', 'gff-scheduled-sales' ); ?></div>
			<div class="gff-ss-stat"><span><?php echo esc_html( $total ); ?></span><?php esc_html_e( 'Total rules', 'gff-scheduled-sales' ); ?></div>
		</div>

		<div class="gff-ss-card gff-ss-card--flush">
			<table class="widefat gff-ss-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Rule', 'gff-scheduled-sales' ); ?></th>
						<th><?php esc_html_e( 'Discount', 'gff-scheduled-sales' ); ?></th>
						<th><?php esc_html_e( 'Applies to', 'gff-scheduled-sales' ); ?></th>
						<th><?php esc_html_e( 'Schedule', 'gff-scheduled-sales' ); ?></th>
						<th><?php esc_html_e( 'Items', 'gff-scheduled-sales' ); ?></th>
						<th><?php esc_html_e( 'Status', 'gff-scheduled-sales' ); ?></th>
						<th class="gff-ss-col-actions"></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $ids ) : ?>
					<tr><td colspan="7" class="gff-ss-empty">
						<?php esc_html_e( 'No sale rules yet.', 'gff-scheduled-sales' ); ?>
						<a href="<?php echo esc_url( self::url( array( 'view' => 'new' ) ) ); ?>"><?php esc_html_e( 'Create your first rule', 'gff-scheduled-sales' ); ?></a>
					</td></tr>
				<?php endif; ?>
				<?php
				foreach ( $ids as $rid ) :
					$rule = GFF_SS_Engine::get_rule( $rid );
					$view = self::url(
						array(
							'view' => 'rule',
							'id'   => $rule->id,
						)
					);
					?>
					<tr>
						<td>
							<a class="gff-ss-title" href="<?php echo esc_url( $view ); ?>"><?php echo esc_html( $rule->title ); ?></a>
							<div class="gff-ss-meta">#<?php echo esc_html( $rule->id ); ?> · <?php echo 'schedule' === $rule->mode ? esc_html__( 'Scheduled', 'gff-scheduled-sales' ) : esc_html__( 'Immediate', 'gff-scheduled-sales' ); ?> · <?php echo esc_html( self::fmt( $rule->created_at ) ); ?></div>
						</td>
						<td><strong><?php echo esc_html( GFF_SS_Engine::discount_label( $rule ) ); ?></strong></td>
						<td><?php echo esc_html( self::targets_label( $rule ) ); ?></td>
						<td class="gff-ss-dates">
							<?php if ( 'schedule' === $rule->mode ) : ?>
								<div><span><?php esc_html_e( 'Start', 'gff-scheduled-sales' ); ?></span> <?php echo esc_html( self::fmt( $rule->start_at ) ); ?></div>
								<div><span><?php esc_html_e( 'End', 'gff-scheduled-sales' ); ?></span> <?php echo esc_html( self::fmt( $rule->end_at ) ); ?></div>
							<?php else : ?>
								<div><span><?php esc_html_e( 'Applied', 'gff-scheduled-sales' ); ?></span> <?php echo esc_html( self::fmt( $rule->applied_at ) ); ?></div>
								<?php if ( $rule->reverted_at ) : ?>
									<div><span><?php esc_html_e( 'Removed', 'gff-scheduled-sales' ); ?></span> <?php echo esc_html( self::fmt( $rule->reverted_at ) ); ?></div>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $counts[ (int) $rule->id ] ?? 0 ); ?></td>
						<td><?php echo self::badge( $rule->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="gff-ss-col-actions">
							<a class="button" href="<?php echo esc_url( $view ); ?>"><?php esc_html_e( 'Logs', 'gff-scheduled-sales' ); ?></a>
							<?php echo self::action_buttons( $rule ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'current' => $paged,
					'total'   => $pages,
				)
			);
			echo '</div></div>';
		}
	}

	private static function render_form() {
		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$tree = array();
		if ( ! is_wp_error( $cats ) ) {
			$by_parent = array();
			foreach ( $cats as $cat ) {
				$by_parent[ $cat->parent ][] = $cat;
			}
			$walk = function ( $parent, $depth ) use ( &$walk, &$tree, $by_parent ) {
				foreach ( $by_parent[ $parent ] ?? array() as $cat ) {
					$tree[] = array( $cat, $depth );
					$walk( $cat->term_id, $depth + 1 );
				}
			};
			$walk( 0, 0 );
		}
		?>
		<form id="gff-ss-form" class="gff-ss-form" novalidate>
			<div class="gff-ss-card">
				<h2><span class="gff-ss-step">1</span><?php esc_html_e( 'Rule details', 'gff-scheduled-sales' ); ?></h2>
				<div class="gff-ss-field">
					<label for="gff-ss-title"><?php esc_html_e( 'Title', 'gff-scheduled-sales' ); ?></label>
					<input type="text" id="gff-ss-title" name="title" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Black Friday – Fencing 15% off', 'gff-scheduled-sales' ); ?>" required>
				</div>
				<div class="gff-ss-field">
					<label><?php esc_html_e( 'When should the sale run?', 'gff-scheduled-sales' ); ?></label>
					<div class="gff-ss-choices">
						<label class="gff-ss-choice">
							<input type="radio" name="mode" value="immediate" checked>
							<span><strong><?php esc_html_e( 'Immediate', 'gff-scheduled-sales' ); ?></strong><em><?php esc_html_e( 'Apply sale prices now. Stays active until you deactivate it.', 'gff-scheduled-sales' ); ?></em></span>
						</label>
						<label class="gff-ss-choice">
							<input type="radio" name="mode" value="schedule">
							<span><strong><?php esc_html_e( 'Schedule', 'gff-scheduled-sales' ); ?></strong><em><?php esc_html_e( 'A background job applies and removes prices automatically.', 'gff-scheduled-sales' ); ?></em></span>
						</label>
					</div>
				</div>
				<div class="gff-ss-schedule" hidden>
					<div class="gff-ss-grid">
						<div class="gff-ss-field">
							<label for="gff-ss-start"><?php esc_html_e( 'Start date & time', 'gff-scheduled-sales' ); ?></label>
							<input type="datetime-local" id="gff-ss-start" name="start_at">
						</div>
						<div class="gff-ss-field">
							<label for="gff-ss-end"><?php esc_html_e( 'End date & time', 'gff-scheduled-sales' ); ?></label>
							<input type="datetime-local" id="gff-ss-end" name="end_at">
						</div>
					</div>
					<p class="description">
						<?php
						/* translators: %s: timezone */
						printf( esc_html__( 'Times are in the store timezone (%s).', 'gff-scheduled-sales' ), esc_html( wp_timezone_string() ) );
						?>
					</p>
				</div>
			</div>

			<div class="gff-ss-card">
				<h2><span class="gff-ss-step">2</span><?php esc_html_e( 'Products', 'gff-scheduled-sales' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Choose products, categories or both. Variable products include all their variations; categories include sub-categories.', 'gff-scheduled-sales' ); ?></p>
				<div class="gff-ss-field">
					<label for="gff-ss-products"><?php esc_html_e( 'Products', 'gff-scheduled-sales' ); ?></label>
					<select id="gff-ss-products" name="product_ids[]" class="wc-product-search" multiple style="width:100%" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'gff-scheduled-sales' ); ?>" data-action="woocommerce_json_search_products_and_variations"></select>
				</div>
				<div class="gff-ss-field">
					<label for="gff-ss-cats"><?php esc_html_e( 'Categories', 'gff-scheduled-sales' ); ?></label>
					<select id="gff-ss-cats" name="category_ids[]" class="wc-enhanced-select" multiple style="width:100%" data-placeholder="<?php esc_attr_e( 'Select categories…', 'gff-scheduled-sales' ); ?>">
						<?php foreach ( $tree as list( $cat, $depth ) ) : ?>
							<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( str_repeat( '— ', $depth ) . $cat->name . ' (' . $cat->count . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="gff-ss-preview">
					<button type="button" class="button" id="gff-ss-preview-btn"><?php esc_html_e( 'Preview affected items', 'gff-scheduled-sales' ); ?></button>
					<div id="gff-ss-preview-out"></div>
				</div>
			</div>

			<div class="gff-ss-card">
				<h2><span class="gff-ss-step">3</span><?php esc_html_e( 'Discount', 'gff-scheduled-sales' ); ?></h2>
				<div class="gff-ss-grid">
					<div class="gff-ss-field">
						<label><?php esc_html_e( 'Discount type', 'gff-scheduled-sales' ); ?></label>
						<div class="gff-ss-seg">
							<label><input type="radio" name="discount_type" value="percent" checked><span><?php esc_html_e( 'Percentage (%)', 'gff-scheduled-sales' ); ?></span></label>
							<label><input type="radio" name="discount_type" value="fixed"><span><?php echo esc_html( sprintf( /* translators: %s currency symbol */ __( 'Fixed amount (%s)', 'gff-scheduled-sales' ), html_entity_decode( get_woocommerce_currency_symbol() ) ) ); ?></span></label>
						</div>
					</div>
					<div class="gff-ss-field">
						<label for="gff-ss-value"><?php esc_html_e( 'Discount value', 'gff-scheduled-sales' ); ?></label>
						<div class="gff-ss-input-addon">
							<input type="number" id="gff-ss-value" name="discount_value" min="0" step="0.01" placeholder="10">
							<span id="gff-ss-unit">%</span>
						</div>
						<p class="description" id="gff-ss-example"></p>
					</div>
				</div>
			</div>

			<div class="gff-ss-errors" hidden></div>
			<div class="gff-ss-submit">
				<button type="submit" class="button button-primary button-hero gff-ss-btn" id="gff-ss-publish"><?php esc_html_e( 'Publish sale', 'gff-scheduled-sales' ); ?></button>
			</div>
		</form>
		<?php
	}

	private static function render_rule( $id ) {
		global $wpdb;
		$rule = GFF_SS_Engine::get_rule( $id );
		if ( ! $rule ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Rule not found.', 'gff-scheduled-sales' ) . '</p></div>';
			return;
		}
		$report = GFF_SS_Engine::report( $id );
		$items  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . GFF_SS_Engine::table( 'items' ) . ' WHERE rule_id = %d ORDER BY id ASC', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$logs   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . GFF_SS_Engine::table( 'logs' ) . ' WHERE rule_id = %d ORDER BY id DESC LIMIT 1000', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$cat_names = array();
		foreach ( $rule->category_ids as $cid ) {
			$term = get_term( $cid, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$cat_names[] = $term->name;
			}
		}
		$prod_names = array();
		foreach ( $rule->product_ids as $pid ) {
			$p            = wc_get_product( $pid );
			$prod_names[] = $p ? wp_strip_all_tags( $p->get_name() ) . ' (#' . $pid . ')' : '#' . $pid;
		}
		$creator     = get_userdata( $rule->created_by );
		$deactivator = $rule->deactivated_by ? get_userdata( $rule->deactivated_by ) : null;
		$item_labels = array(
			'applied'        => __( 'On sale', 'gff-scheduled-sales' ),
			'skipped'        => __( 'Skipped', 'gff-scheduled-sales' ),
			'error'          => __( 'Error', 'gff-scheduled-sales' ),
			'reverted'       => __( 'Restored', 'gff-scheduled-sales' ),
			'revert_skipped' => __( 'Left untouched', 'gff-scheduled-sales' ),
		);
		?>
		<div class="gff-ss-card gff-ss-rule-head">
			<div>
				<h2><?php echo esc_html( $rule->title ); ?> <?php echo self::badge( $rule->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
				<div class="gff-ss-meta">
					#<?php echo esc_html( $rule->id ); ?> ·
					<?php echo esc_html( GFF_SS_Engine::discount_label( $rule ) ); ?> ·
					<?php echo 'schedule' === $rule->mode ? esc_html__( 'Scheduled', 'gff-scheduled-sales' ) : esc_html__( 'Immediate', 'gff-scheduled-sales' ); ?>
				</div>
			</div>
			<div><?php echo self::action_buttons( $rule ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		</div>

		<div class="gff-ss-columns">
			<div class="gff-ss-card">
				<h3><?php esc_html_e( 'Details', 'gff-scheduled-sales' ); ?></h3>
				<dl class="gff-ss-dl">
					<?php if ( 'schedule' === $rule->mode ) : ?>
						<dt><?php esc_html_e( 'Starts', 'gff-scheduled-sales' ); ?></dt><dd><?php echo esc_html( self::fmt( $rule->start_at ) ); ?></dd>
						<dt><?php esc_html_e( 'Ends', 'gff-scheduled-sales' ); ?></dt><dd><?php echo esc_html( self::fmt( $rule->end_at ) ); ?></dd>
					<?php endif; ?>
					<dt><?php esc_html_e( 'Created', 'gff-scheduled-sales' ); ?></dt><dd><?php echo esc_html( self::fmt( $rule->created_at ) . ( $creator ? ' · ' . $creator->display_name : '' ) ); ?></dd>
					<dt><?php esc_html_e( 'Applied', 'gff-scheduled-sales' ); ?></dt><dd><?php echo esc_html( self::fmt( $rule->applied_at ) ); ?></dd>
					<dt><?php esc_html_e( 'Removed', 'gff-scheduled-sales' ); ?></dt><dd><?php echo esc_html( self::fmt( $rule->reverted_at ) . ( $deactivator ? ' · ' . $deactivator->display_name : '' ) ); ?></dd>
					<dt><?php esc_html_e( 'Products', 'gff-scheduled-sales' ); ?></dt><dd><?php echo esc_html( $prod_names ? implode( ', ', $prod_names ) : '—' ); ?></dd>
					<dt><?php esc_html_e( 'Categories', 'gff-scheduled-sales' ); ?></dt><dd><?php echo esc_html( $cat_names ? implode( ', ', $cat_names ) : '—' ); ?></dd>
				</dl>
			</div>
			<div class="gff-ss-card">
				<h3><?php esc_html_e( 'Report', 'gff-scheduled-sales' ); ?></h3>
				<div class="gff-ss-report">
					<div class="is-success"><span><?php echo esc_html( $report['updated'] ); ?></span><?php esc_html_e( 'Discounted', 'gff-scheduled-sales' ); ?></div>
					<div class="is-warning"><span><?php echo esc_html( $report['skipped'] ); ?></span><?php esc_html_e( 'Skipped', 'gff-scheduled-sales' ); ?></div>
					<div class="is-error"><span><?php echo esc_html( $report['error'] ); ?></span><?php esc_html_e( 'Errors', 'gff-scheduled-sales' ); ?></div>
					<div class="is-info"><span><?php echo esc_html( $report['reverted'] ); ?></span><?php esc_html_e( 'Restored', 'gff-scheduled-sales' ); ?></div>
				</div>
			</div>
		</div>

		<div class="gff-ss-card gff-ss-card--flush">
			<h3 class="gff-ss-card-title"><?php esc_html_e( 'Products', 'gff-scheduled-sales' ); ?></h3>
			<table class="widefat gff-ss-table">
				<thead><tr>
					<th><?php esc_html_e( 'Product', 'gff-scheduled-sales' ); ?></th>
					<th><?php esc_html_e( 'Regular', 'gff-scheduled-sales' ); ?></th>
					<th><?php esc_html_e( 'Previous sale', 'gff-scheduled-sales' ); ?></th>
					<th><?php esc_html_e( 'Sale price', 'gff-scheduled-sales' ); ?></th>
					<th><?php esc_html_e( 'Status', 'gff-scheduled-sales' ); ?></th>
					<th><?php esc_html_e( 'Note', 'gff-scheduled-sales' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $items ) : ?>
					<tr><td colspan="6" class="gff-ss-empty"><?php esc_html_e( 'No products processed yet.', 'gff-scheduled-sales' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $items as $item ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $item->parent_id ? $item->parent_id : $item->product_id ) ); ?>"><?php echo esc_html( $item->product_name ); ?></a></td>
						<td><?php echo '' !== $item->regular_price ? esc_html( GFF_SS_Engine::plain_price( $item->regular_price ) ) : '—'; ?></td>
						<td><?php echo '' !== $item->orig_sale_price ? esc_html( GFF_SS_Engine::plain_price( $item->orig_sale_price ) ) : '—'; ?></td>
						<td><?php echo '' !== $item->new_sale_price ? '<strong>' . esc_html( GFF_SS_Engine::plain_price( $item->new_sale_price ) ) . '</strong>' : '—'; ?></td>
						<td><span class="gff-ss-pill gff-ss-pill--<?php echo esc_attr( $item->status ); ?>"><?php echo esc_html( $item_labels[ $item->status ] ?? $item->status ); ?></span></td>
						<td class="gff-ss-note"><?php echo esc_html( (string) $item->message ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="gff-ss-card gff-ss-card--flush">
			<h3 class="gff-ss-card-title"><?php esc_html_e( 'Activity log', 'gff-scheduled-sales' ); ?></h3>
			<div class="gff-ss-logview">
				<?php foreach ( $logs as $log ) : ?>
					<div class="gff-ss-logline is-<?php echo esc_attr( $log->level ); ?>">
						<time><?php echo esc_html( self::fmt( $log->created_at ) ); ?></time>
						<span class="gff-ss-logop"><?php echo esc_html( $log->op ); ?></span>
						<span><?php echo esc_html( $log->message ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private static function render_modals() {
		?>
		<div class="gff-ss-modal" id="gff-ss-progress" hidden>
			<div class="gff-ss-modal__box">
				<div class="gff-ss-modal__head">
					<h2 class="gff-ss-progress-title"></h2>
					<span class="gff-ss-progress-count">0 / 0</span>
				</div>
				<div class="gff-ss-bar"><div class="gff-ss-bar__fill"></div><span class="gff-ss-bar__pct">0%</span></div>
				<div class="gff-ss-current"><span class="spinner is-active"></span><span class="gff-ss-current-text"><?php esc_html_e( 'Preparing…', 'gff-scheduled-sales' ); ?></span></div>
				<div class="gff-ss-console"></div>
				<div class="gff-ss-final" hidden>
					<div class="gff-ss-report"></div>
				</div>
				<div class="gff-ss-modal__foot">
					<span class="gff-ss-hint"><?php esc_html_e( 'Please keep this window open.', 'gff-scheduled-sales' ); ?></span>
					<a class="button button-primary gff-ss-btn gff-ss-done" href="#" hidden><?php esc_html_e( 'View report', 'gff-scheduled-sales' ); ?></a>
				</div>
			</div>
		</div>

		<div class="gff-ss-modal" id="gff-ss-confirm" hidden>
			<div class="gff-ss-modal__box gff-ss-modal__box--sm">
				<div class="gff-ss-warn-icon">!</div>
				<h2 class="gff-ss-confirm-heading"><?php esc_html_e( 'Deactivate this sale?', 'gff-scheduled-sales' ); ?></h2>
				<p class="gff-ss-confirm-title"></p>
				<ul class="gff-ss-confirm-list"></ul>
				<div class="gff-ss-modal__foot">
					<button type="button" class="button gff-ss-cancel"><?php esc_html_e( 'Keep it running', 'gff-scheduled-sales' ); ?></button>
					<button type="button" class="button button-primary gff-ss-danger gff-ss-confirm-yes"><?php esc_html_e( 'Yes, deactivate', 'gff-scheduled-sales' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}
}
