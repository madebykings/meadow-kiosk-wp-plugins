<?php
/**
 * Plugin Name: Meadow Kiosk – Order Cleanup
 * Description: Cancels stale kiosk orders, trashes previously-cancelled kiosk orders after X days (no force delete), and optionally cleans up genuinely stuck meadow_payment posts. HPOS-safe. Includes Tools UI + hourly cron with locking.
 * Version: 1.0.0
 * Author: Meadow
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Meadow_Kiosk_Order_Cleanup' ) ) :

final class Meadow_Kiosk_Order_Cleanup {

	// ---- Assumptions / constants ----
	const ORDER_TYPE_META_KEY           = '_meadow_order_type';
	const ORDER_TYPE_KIOSK              = 'kiosk';
	const CANCELLED_AT_META_KEY         = '_meadow_cleanup_cancelled_at';
	const KIOSK_STATE_TABLE             = 'hhg_meadow_kiosk_state'; // explicit per your instruction
	const PAYMENT_POST_TYPE             = 'meadow_payment';

	// Cron + locking
	const CRON_HOOK                     = 'meadow_kiosk_order_cleanup_hourly';
	const LOCK_TRANSIENT_KEY            = 'meadow_kiosk_order_cleanup_lock';
	const LOCK_TTL_SECONDS              = 55 * 60; // 55 minutes (hourly cron safety)
	const DEFAULT_DRY_RUN               = true;

	// Options
	const OPT_KEY                       = 'meadow_kiosk_order_cleanup_options';
	const OPT_VERSION                   = 1;

	// Admin UI
	const ADMIN_PAGE_SLUG               = 'meadow-order-cleanup';
	const ADMIN_NONCE_ACTION            = 'meadow_order_cleanup_run';
	const ADMIN_NONCE_NAME              = 'meadow_order_cleanup_nonce';

	// Defaults (conservative)
	private static function defaults() : array {
		return [
			'version' => self::OPT_VERSION,

			// Stage 1
			'enable_stage1_cancel_stale' => 1,
			'stage1_stale_minutes'       => 60,   // cancel kiosk orders on-hold/pending older than 60 minutes
			'stage1_limit'               => 50,   // per run cap

			// Stage 2
			'enable_stage2_trash_cancelled' => 1,
			'stage2_trash_after_days'       => 14, // trash cancelled kiosk orders after 14 days since our cancelled-at tag
			'stage2_limit'                  => 100,

			// Payment cleanup (optional & conservative)
			'enable_payment_cleanup'        => 0,
			'payment_stuck_hours'           => 48,  // only consider *very* old payments
			'payment_limit'                 => 50,

			// Safety
			'dry_run_default'               => self::DEFAULT_DRY_RUN ? 1 : 0,
		];
	}

	private static function get_opts() : array {
		$opts = get_option( self::OPT_KEY, [] );
		if ( ! is_array( $opts ) ) $opts = [];
		return array_merge( self::defaults(), $opts );
	}

	private static function update_opts( array $new ) : void {
		$opts = array_merge( self::get_opts(), $new );
		$opts['version'] = self::OPT_VERSION;
		update_option( self::OPT_KEY, $opts, false );
	}

	// ---- Bootstrap ----
	public static function init() : void {
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_post_meadow_order_cleanup_run', [ __CLASS__, 'handle_admin_post_run' ] );

		add_action( 'init', [ __CLASS__, 'ensure_cron_scheduled' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'cron_runner' ] );

		// If WooCommerce absent, we still render the Tools page and keep everything non-fatal.
	}

	// ---- Cron scheduling (hourly) ----
	public static function ensure_cron_scheduled() : void {
		if ( wp_doing_ajax() ) return;

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Schedule to start in ~5 minutes to avoid stampede after deploy
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	// ---- Locking ----
	private static function acquire_lock() : bool {
		$existing = get_transient( self::LOCK_TRANSIENT_KEY );
		if ( $existing ) return false;
		set_transient( self::LOCK_TRANSIENT_KEY, 1, self::LOCK_TTL_SECONDS );
		return true;
	}

	private static function release_lock() : void {
		delete_transient( self::LOCK_TRANSIENT_KEY );
	}

	// ---- Admin UI ----
	public static function admin_menu() : void {
		add_management_page(
			'Meadow Order Cleanup',
			'Meadow Order Cleanup',
			'manage_woocommerce',
			self::ADMIN_PAGE_SLUG,
			[ __CLASS__, 'render_tools_page' ]
		);
	}

	public static function render_tools_page() : void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$opts = self::get_opts();
		$dry_run = isset( $_GET['dry_run'] ) ? (int) $_GET['dry_run'] : (int) $opts['dry_run_default'];

		// Pre-calc counters (non-fatal)
		$counters = self::get_counters( [
			'stage1_stale_minutes' => (int) $opts['stage1_stale_minutes'],
			'stage2_trash_after_days' => (int) $opts['stage2_trash_after_days'],
			'payment_stuck_hours' => (int) $opts['payment_stuck_hours'],
		] );

		$last_result = get_transient( 'meadow_kiosk_order_cleanup_last_result' );
		if ( ! is_array( $last_result ) ) $last_result = null;

		?>
		<div class="wrap">
			<h1>Meadow Order Cleanup</h1>

			<?php if ( $last_result ) : ?>
				<div class="notice notice-<?php echo esc_attr( $last_result['ok'] ? 'success' : 'warning' ); ?>">
					<p><strong>Last run:</strong> <?php echo esc_html( $last_result['when'] ); ?> —
						<?php echo esc_html( $last_result['summary'] ); ?>
					</p>
					<?php if ( ! empty( $last_result['notes'] ) ) : ?>
						<details>
							<summary>Details</summary>
							<pre style="white-space:pre-wrap;"><?php echo esc_html( implode( "\n", $last_result['notes'] ) ); ?></pre>
						</details>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ADMIN_NONCE_ACTION, self::ADMIN_NONCE_NAME ); ?>
				<input type="hidden" name="action" value="meadow_order_cleanup_run" />

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">Dry run</th>
							<td>
								<label>
									<input type="checkbox" name="dry_run" value="1" <?php checked( 1, $dry_run ); ?> />
									Do not change anything; only report what would happen.
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row">Stage 1: Cancel stale kiosk orders</th>
							<td>
								<label>
									<input type="checkbox" name="enable_stage1_cancel_stale" value="1" <?php checked( 1, (int) $opts['enable_stage1_cancel_stale'] ); ?> />
									Enable
								</label>
								<p class="description">
									Cancels kiosk-only orders that are <code>on-hold</code> or <code>pending</code>, unpaid, and older than the threshold.
									HPOS-safe. Adds <code><?php echo esc_html( self::CANCELLED_AT_META_KEY ); ?></code>.
								</p>
								<p>
									Stale threshold:
									<input type="number" min="5" step="1" name="stage1_stale_minutes" value="<?php echo esc_attr( (int) $opts['stage1_stale_minutes'] ); ?>" />
									minutes
									&nbsp;|&nbsp; Max per run:
									<input type="number" min="1" step="1" name="stage1_limit" value="<?php echo esc_attr( (int) $opts['stage1_limit'] ); ?>" />
								</p>
								<p><strong>Current eligible count (estimate):</strong> <?php echo esc_html( (string) $counters['stage1'] ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">Stage 2: Trash previously-cancelled kiosk orders</th>
							<td>
								<label>
									<input type="checkbox" name="enable_stage2_trash_cancelled" value="1" <?php checked( 1, (int) $opts['enable_stage2_trash_cancelled'] ); ?> />
									Enable
								</label>
								<p class="description">
									Only orders with <code>status=cancelled</code> and a prior
									<code><?php echo esc_html( self::CANCELLED_AT_META_KEY ); ?></code> older than the threshold are moved to trash.
									<strong>Not force-deleted.</strong>
								</p>
								<p>
									Trash after:
									<input type="number" min="1" step="1" name="stage2_trash_after_days" value="<?php echo esc_attr( (int) $opts['stage2_trash_after_days'] ); ?>" />
									days
									&nbsp;|&nbsp; Max per run:
									<input type="number" min="1" step="1" name="stage2_limit" value="<?php echo esc_attr( (int) $opts['stage2_limit'] ); ?>" />
								</p>
								<p><strong>Current eligible count (estimate):</strong> <?php echo esc_html( (string) $counters['stage2'] ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">Payment cleanup (optional, conservative)</th>
							<td>
								<label>
									<input type="checkbox" name="enable_payment_cleanup" value="1" <?php checked( 1, (int) $opts['enable_payment_cleanup'] ); ?> />
									Enable
								</label>
								<p class="description">
									Only touches <code><?php echo esc_html( self::PAYMENT_POST_TYPE ); ?></code> posts that look genuinely stuck and not referenced as active by <code><?php echo esc_html( self::KIOSK_STATE_TABLE ); ?></code>.
									This is deliberately conservative; if it can’t prove “stuck”, it skips.
								</p>
								<p>
									Consider “stuck” if older than:
									<input type="number" min="12" step="1" name="payment_stuck_hours" value="<?php echo esc_attr( (int) $opts['payment_stuck_hours'] ); ?>" />
									hours
									&nbsp;|&nbsp; Max per run:
									<input type="number" min="1" step="1" name="payment_limit" value="<?php echo esc_attr( (int) $opts['payment_limit'] ); ?>" />
								</p>
								<p><strong>Current eligible count (estimate):</strong> <?php echo esc_html( (string) $counters['payment'] ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( 'Run Cleanup Now' ); ?>
			</form>

			<hr />

			<h2>Safety Notes</h2>
			<ul>
				<li><strong>Kiosk-only:</strong> Orders must have meta <code><?php echo esc_html( self::ORDER_TYPE_META_KEY ); ?></code> = <code><?php echo esc_html( self::ORDER_TYPE_KIOSK ); ?></code>.</li>
				<li><strong>Unpaid-only:</strong> Stage 1 skips any paid order (even if status is odd).</li>
				<li><strong>Never fatal:</strong> Exceptions are caught and logged; cleanup continues.</li>
				<li><strong>Cron:</strong> Hourly with a lock; no double-runs.</li>
			</ul>
		</div>
		<?php
	}

	public static function handle_admin_post_run() : void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::ADMIN_NONCE_ACTION, self::ADMIN_NONCE_NAME );

		$opts = self::get_opts();

		// Update saved options from form (checkboxes + numbers)
		$new = [
			'enable_stage1_cancel_stale'   => isset( $_POST['enable_stage1_cancel_stale'] ) ? 1 : 0,
			'stage1_stale_minutes'         => max( 5, (int) ( $_POST['stage1_stale_minutes'] ?? $opts['stage1_stale_minutes'] ) ),
			'stage1_limit'                 => max( 1, (int) ( $_POST['stage1_limit'] ?? $opts['stage1_limit'] ) ),

			'enable_stage2_trash_cancelled'=> isset( $_POST['enable_stage2_trash_cancelled'] ) ? 1 : 0,
			'stage2_trash_after_days'      => max( 1, (int) ( $_POST['stage2_trash_after_days'] ?? $opts['stage2_trash_after_days'] ) ),
			'stage2_limit'                 => max( 1, (int) ( $_POST['stage2_limit'] ?? $opts['stage2_limit'] ) ),

			'enable_payment_cleanup'       => isset( $_POST['enable_payment_cleanup'] ) ? 1 : 0,
			'payment_stuck_hours'          => max( 12, (int) ( $_POST['payment_stuck_hours'] ?? $opts['payment_stuck_hours'] ) ),
			'payment_limit'                => max( 1, (int) ( $_POST['payment_limit'] ?? $opts['payment_limit'] ) ),
		];
		self::update_opts( $new );

		$dry_run = isset( $_POST['dry_run'] ) ? 1 : 0;

		$result = self::run_cleanup( [
			'context' => 'admin',
			'dry_run' => (bool) $dry_run,
			'opts'    => self::get_opts(),
		] );

		set_transient( 'meadow_kiosk_order_cleanup_last_result', $result, 6 * HOUR_IN_SECONDS );

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::ADMIN_PAGE_SLUG . '&dry_run=' . ( $dry_run ? '1' : '0' ) ) );
		exit;
	}

	// ---- Cron runner ----
	public static function cron_runner() : void {
		// Keep cron runs conservative: use saved opts and default dry-run setting (usually false for cron).
		$opts = self::get_opts();
		$dry_run = false; // cron should actually clean, otherwise it does nothing forever

		$result = self::run_cleanup( [
			'context' => 'cron',
			'dry_run' => $dry_run,
			'opts'    => $opts,
		] );

		// Store last result for visibility in Tools page
		set_transient( 'meadow_kiosk_order_cleanup_last_result', $result, 6 * HOUR_IN_SECONDS );
	}

	// ---- Main cleanup orchestrator ----
	private static function run_cleanup( array $args ) : array {
		$context = (string) ( $args['context'] ?? 'unknown' );
		$dry_run = (bool) ( $args['dry_run'] ?? self::DEFAULT_DRY_RUN );
		$opts    = (array) ( $args['opts'] ?? self::get_opts() );

		$notes = [];
		$ok = true;

		$started = gmdate( 'c' );

		if ( ! self::acquire_lock() ) {
			return [
				'ok'      => true,
				'when'    => $started,
				'summary' => "Skipped ($context): another cleanup run is in progress (lock held).",
				'notes'   => [ 'Lock was already set; no action taken.' ],
			];
		}

		try {
			// If WooCommerce is missing, we still exit cleanly.
			if ( ! function_exists( 'wc_get_orders' ) ) {
				$ok = false;
				$notes[] = 'WooCommerce functions not available (wc_get_orders missing). Skipping order cleanup safely.';
				return [
					'ok'      => $ok,
					'when'    => $started,
					'summary' => "Completed ($context): WooCommerce not available; nothing done.",
					'notes'   => $notes,
				];
			}

			// STAGE 1
			$stage1_done = 0;
			$stage1_skipped = 0;
			$stage1_seen = 0;

			if ( ! empty( $opts['enable_stage1_cancel_stale'] ) ) {
				$r = self::stage1_cancel_stale_kiosk_orders( [
					'dry_run'            => $dry_run,
					'stale_minutes'      => (int) $opts['stage1_stale_minutes'],
					'limit'              => (int) $opts['stage1_limit'],
				] );
				$stage1_done    = (int) $r['done'];
				$stage1_skipped = (int) $r['skipped'];
				$stage1_seen    = (int) $r['seen'];
				$notes = array_merge( $notes, $r['notes'] );
			} else {
				$notes[] = 'Stage 1 disabled.';
			}

			// STAGE 2
			$stage2_done = 0;
			$stage2_skipped = 0;
			$stage2_seen = 0;

			if ( ! empty( $opts['enable_stage2_trash_cancelled'] ) ) {
				$r = self::stage2_trash_old_cancelled_kiosk_orders( [
					'dry_run'        => $dry_run,
					'after_days'     => (int) $opts['stage2_trash_after_days'],
					'limit'          => (int) $opts['stage2_limit'],
				] );
				$stage2_done    = (int) $r['done'];
				$stage2_skipped = (int) $r['skipped'];
				$stage2_seen    = (int) $r['seen'];
				$notes = array_merge( $notes, $r['notes'] );
			} else {
				$notes[] = 'Stage 2 disabled.';
			}

			// PAYMENT CLEANUP (optional)
			$pay_done = 0;
			$pay_skipped = 0;
			$pay_seen = 0;

			if ( ! empty( $opts['enable_payment_cleanup'] ) ) {
				$r = self::payment_cleanup_conservative( [
					'dry_run'     => $dry_run,
					'stuck_hours' => (int) $opts['payment_stuck_hours'],
					'limit'       => (int) $opts['payment_limit'],
				] );
				$pay_done    = (int) $r['done'];
				$pay_skipped = (int) $r['skipped'];
				$pay_seen    = (int) $r['seen'];
				$notes = array_merge( $notes, $r['notes'] );
			} else {
				$notes[] = 'Payment cleanup disabled.';
			}

			$summary = sprintf(
				"Completed (%s)%s. Stage1: %d/%d cancelled (skipped %d). Stage2: %d/%d trashed (skipped %d). Payments: %d/%d cleaned (skipped %d).",
				$context,
				$dry_run ? ' [DRY RUN]' : '',
				$stage1_done, $stage1_seen, $stage1_skipped,
				$stage2_done, $stage2_seen, $stage2_skipped,
				$pay_done, $pay_seen, $pay_skipped
			);

			return [
				'ok'      => $ok,
				'when'    => $started,
				'summary' => $summary,
				'notes'   => $notes,
			];

		} catch ( \Throwable $e ) {
			$ok = false;
			$notes[] = 'Fatal caught (non-fatal to site): ' . $e->getMessage();
			self::log( 'Cleanup exception: ' . $e->getMessage() );

			return [
				'ok'      => $ok,
				'when'    => $started,
				'summary' => "Completed ($context) with errors" . ( $dry_run ? ' [DRY RUN]' : '' ) . ".",
				'notes'   => $notes,
			];
		} finally {
			self::release_lock();
		}
	}

	// ---- Stage 1: cancel stale on-hold/pending kiosk orders (HPOS-safe) ----
	private static function stage1_cancel_stale_kiosk_orders( array $args ) : array {
		$dry_run       = (bool) ( $args['dry_run'] ?? self::DEFAULT_DRY_RUN );
		$stale_minutes = max( 5, (int) ( $args['stale_minutes'] ?? 60 ) );
		$limit         = max( 1, (int) ( $args['limit'] ?? 50 ) );

		$notes = [];
		$done = 0; $skipped = 0; $seen = 0;

		$cutoff_ts = time() - ( $stale_minutes * 60 );
		$cutoff_gmt = gmdate( 'Y-m-d H:i:s', $cutoff_ts );

		// Only kiosk orders (meta), unpaid, and in pending/on-hold older than cutoff.
		// HPOS-safe: wc_get_orders uses WooCommerce data store.
		$query_args = [
			'status'         => [ 'on-hold', 'pending' ],
			'limit'          => $limit,
			'orderby'        => 'date_created',
			'order'          => 'ASC',
			'date_created'   => '<' . $cutoff_gmt,
			'meta_query'     => [
				[
					'key'     => self::ORDER_TYPE_META_KEY,
					'value'   => self::ORDER_TYPE_KIOSK,
					'compare' => '=',
				],
			],
			'return'         => 'objects',
		];

		$orders = [];
		try {
			$orders = wc_get_orders( $query_args );
		} catch ( \Throwable $e ) {
			self::log( 'Stage1 wc_get_orders failed: ' . $e->getMessage() );
			$notes[] = 'Stage 1 query failed (caught): ' . $e->getMessage();
			return compact( 'done', 'skipped', 'seen', 'notes' );
		}

		foreach ( $orders as $order ) {
			$seen++;

			try {
				if ( ! $order || ! is_a( $order, 'WC_Order' ) ) { $skipped++; continue; }

				// Safety: kiosk-only meta check again
				$otype = (string) $order->get_meta( self::ORDER_TYPE_META_KEY, true );
				if ( $otype !== self::ORDER_TYPE_KIOSK ) { $skipped++; continue; }

				// Safety: unpaid-only
				if ( method_exists( $order, 'is_paid' ) && $order->is_paid() ) {
					$skipped++;
					continue;
				}

				// If it was already cancelled and tagged, skip (idempotency)
				$already = (string) $order->get_meta( self::CANCELLED_AT_META_KEY, true );
				if ( $already !== '' ) { $skipped++; continue; }

				$order_id = $order->get_id();

				if ( $dry_run ) {
					$done++;
					$notes[] = "Stage1 DRY: would cancel order #{$order_id} (status={$order->get_status()}, created={$order->get_date_created()}).";
					continue;
				}

				// Cancel
				$order->update_status( 'cancelled', sprintf( 'Meadow cleanup: stale kiosk order (> %d min).', $stale_minutes ), true );
				$order->update_meta_data( self::CANCELLED_AT_META_KEY, gmdate( 'c' ) );
				$order->save();

				$done++;
				$notes[] = "Stage1: cancelled order #{$order_id} and tagged " . self::CANCELLED_AT_META_KEY . ".";
			} catch ( \Throwable $e ) {
				$skipped++;
				self::log( 'Stage1 order error: ' . $e->getMessage() );
				$notes[] = 'Stage1 caught error: ' . $e->getMessage();
				continue;
			}
		}

		return compact( 'done', 'skipped', 'seen', 'notes' );
	}

	// ---- Stage 2: trash previously-cancelled kiosk orders after X days (no force delete) ----
	private static function stage2_trash_old_cancelled_kiosk_orders( array $args ) : array {
		$dry_run    = (bool) ( $args['dry_run'] ?? self::DEFAULT_DRY_RUN );
		$after_days = max( 1, (int) ( $args['after_days'] ?? 14 ) );
		$limit      = max( 1, (int) ( $args['limit'] ?? 100 ) );

		$notes = [];
		$done = 0; $skipped = 0; $seen = 0;

		$cutoff_ts = time() - ( $after_days * DAY_IN_SECONDS );
		$cutoff_iso = gmdate( 'c', $cutoff_ts );

		// We only want orders:
		// - status cancelled
		// - kiosk meta
		// - have our cancelled_at meta older than cutoff
		// Note: meta values are ISO strings; we use compare by string. ISO 8601 sorts lexicographically.
		$query_args = [
			'status'     => [ 'cancelled' ],
			'limit'      => $limit,
			'orderby'    => 'date_modified',
			'order'      => 'ASC',
			'meta_query' => [
				[
					'key'     => self::ORDER_TYPE_META_KEY,
					'value'   => self::ORDER_TYPE_KIOSK,
					'compare' => '=',
				],
				[
					'key'     => self::CANCELLED_AT_META_KEY,
					'value'   => $cutoff_iso,
					'compare' => '<=',
				],
			],
			'return'     => 'objects',
		];

		$orders = [];
		try {
			$orders = wc_get_orders( $query_args );
		} catch ( \Throwable $e ) {
			self::log( 'Stage2 wc_get_orders failed: ' . $e->getMessage() );
			$notes[] = 'Stage 2 query failed (caught): ' . $e->getMessage();
			return compact( 'done', 'skipped', 'seen', 'notes' );
		}

		foreach ( $orders as $order ) {
			$seen++;

			try {
				if ( ! $order || ! is_a( $order, 'WC_Order' ) ) { $skipped++; continue; }

				$otype = (string) $order->get_meta( self::ORDER_TYPE_META_KEY, true );
				if ( $otype !== self::ORDER_TYPE_KIOSK ) { $skipped++; continue; }

				$cancelled_at = (string) $order->get_meta( self::CANCELLED_AT_META_KEY, true );
				if ( $cancelled_at === '' ) { $skipped++; continue; }

				$order_id = $order->get_id();

				// If already trashed, skip.
				// HPOS still has underlying post status; wc_get_orders generally won't return trashed.
				// But we remain defensive:
				$post_status = function_exists( 'get_post_status' ) ? get_post_status( $order_id ) : '';
				if ( $post_status === 'trash' ) { $skipped++; continue; }

				if ( $dry_run ) {
					$done++;
					$notes[] = "Stage2 DRY: would trash cancelled kiosk order #{$order_id} (cancelled_at={$cancelled_at}).";
					continue;
				}

				// Move to trash (NOT force delete)
				if ( function_exists( 'wc_trash_order' ) ) {
					wc_trash_order( $order_id );
				} else {
					// Fallback: trash underlying post id (safe, not force delete)
					wp_trash_post( $order_id );
				}

				$done++;
				$notes[] = "Stage2: trashed order #{$order_id} (not force deleted).";
			} catch ( \Throwable $e ) {
				$skipped++;
				self::log( 'Stage2 order error: ' . $e->getMessage() );
				$notes[] = 'Stage2 caught error: ' . $e->getMessage();
				continue;
			}
		}

		return compact( 'done', 'skipped', 'seen', 'notes' );
	}

	// ---- Payment cleanup: optional + conservative (only genuinely stuck meadow_payment posts) ----
	private static function payment_cleanup_conservative( array $args ) : array {
		global $wpdb;

		$dry_run    = (bool) ( $args['dry_run'] ?? self::DEFAULT_DRY_RUN );
		$stuck_hours= max( 12, (int) ( $args['stuck_hours'] ?? 48 ) );
		$limit      = max( 1, (int) ( $args['limit'] ?? 50 ) );

		$notes = [];
		$done = 0; $skipped = 0; $seen = 0;

		// Conservative definition of "stuck":
		// - meadow_payment post type
		// - older than stuck_hours
		// - NOT in trash
		// - NOT referenced as active by kiosk_state table (if we can infer active references)
		//
		// Action: move payment post to trash (NOT force delete).
		// Rationale: safest cleanup step; doesn’t delete data.

		$cutoff_ts = time() - ( $stuck_hours * HOUR_IN_SECONDS );

		$active = self::infer_active_kiosk_references( $cutoff_ts );
		$active_order_ids   = $active['order_ids'];    // ints
		$active_session_ids = $active['session_ids'];  // strings

		// Query candidates
		$q = new WP_Query( [
			'post_type'              => self::PAYMENT_POST_TYPE,
			'post_status'            => [ 'publish', 'pending', 'draft', 'private' ],
			'posts_per_page'         => $limit,
			'orderby'                => 'date',
			'order'                  => 'ASC',
			'date_query'             => [
				[
					'before' => gmdate( 'Y-m-d H:i:s', $cutoff_ts ),
					'inclusive' => true,
				]
			],
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		] );

		$ids = is_array( $q->posts ) ? $q->posts : [];

		foreach ( $ids as $pid ) {
			$seen++;

			try {
				$pid = (int) $pid;
				if ( $pid <= 0 ) { $skipped++; continue; }

				// Never touch if already trashed
				if ( get_post_status( $pid ) === 'trash' ) { $skipped++; continue; }

				// Extremely conservative skip rules:
				// If we can detect it’s tied to an active kiosk state (order/session), skip.
				$meta_order_id   = get_post_meta( $pid, '_meadow_order_id', true );
				$meta_session_id = get_post_meta( $pid, '_meadow_session_id', true );

				if ( $meta_order_id !== '' ) {
					$oid = (int) $meta_order_id;
					if ( $oid > 0 && in_array( $oid, $active_order_ids, true ) ) {
						$skipped++;
						continue;
					}
				}
				if ( is_string( $meta_session_id ) && $meta_session_id !== '' ) {
					if ( in_array( $meta_session_id, $active_session_ids, true ) ) {
						$skipped++;
						continue;
					}
				}

				// Also skip if it looks “final” by common meta flags (if present).
				// We do NOT require these metas to exist; we only use them to safely skip.
				$maybe_final_keys = [ '_meadow_payment_final', '_meadow_is_final', '_meadow_final', '_meadow_status' ];
				foreach ( $maybe_final_keys as $k ) {
					$v = get_post_meta( $pid, $k, true );
					if ( $k === '_meadow_status' && is_string( $v ) ) {
						$sv = strtolower( trim( $v ) );
						if ( in_array( $sv, [ 'paid', 'approved', 'completed', 'cancelled', 'declined', 'failed', 'refunded' ], true ) ) {
							$skipped++;
							continue 2;
						}
					}
					if ( $v === '1' || $v === 1 || $v === true || $v === 'true' ) {
						$skipped++;
						continue 2;
					}
				}

				if ( $dry_run ) {
					$done++;
					$notes[] = "Payment DRY: would trash meadow_payment #{$pid} (older than {$stuck_hours}h; not referenced as active).";
					continue;
				}

				wp_trash_post( $pid );
				$done++;
				$notes[] = "Payment: trashed meadow_payment #{$pid} (not force deleted).";

			} catch ( \Throwable $e ) {
				$skipped++;
				self::log( 'Payment cleanup error: ' . $e->getMessage() );
				$notes[] = 'Payment cleanup caught error: ' . $e->getMessage();
				continue;
			}
		}

		return compact( 'done', 'skipped', 'seen', 'notes' );
	}

	/**
	 * Infer “active” kiosk references from hhg_meadow_kiosk_state.
	 * We avoid assuming exact schema; we probe columns and only use those that exist.
	 *
	 * Returns:
	 *  [
	 *    'order_ids'   => int[],
	 *    'session_ids' => string[],
	 *  ]
	 */
	private static function infer_active_kiosk_references( int $active_cutoff_ts ) : array {
		global $wpdb;

		$order_ids   = [];
		$session_ids = [];

		$table = self::KIOSK_STATE_TABLE;
		// If table name is not prefixed, use as-is per instruction.
		// But also attempt prefixed variant as a fallback without breaking anything.
		$candidates = [ $table, $wpdb->prefix . ltrim( $table, $wpdb->prefix ) ];

		$found_table = null;
		foreach ( $candidates as $t ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $t ) );
			if ( $exists ) { $found_table = $t; break; }
		}
		if ( ! $found_table ) {
			// Table missing; return empty (most conservative: do not “prove active”, but still conservative via meta checks)
			return [ 'order_ids' => [], 'session_ids' => [] ];
		}

		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$found_table}", 0 );
		if ( ! is_array( $cols ) ) $cols = [];

		// Candidate columns we might use if present
		$col_order_id   = in_array( 'order_id', $cols, true ) ? 'order_id' : ( in_array( '_meadow_screen_order_id', $cols, true ) ? '_meadow_screen_order_id' : null );
		$col_session_id = in_array( 'session_id', $cols, true ) ? 'session_id' : ( in_array( '_meadow_session_id', $cols, true ) ? '_meadow_session_id' : null );
		$col_last_seen  = in_array( 'last_seen', $cols, true ) ? 'last_seen' : ( in_array( '_meadow_last_seen', $cols, true ) ? '_meadow_last_seen' : null );
		$col_mode       = in_array( 'screen_mode', $cols, true ) ? 'screen_mode' : ( in_array( '_meadow_screen_mode', $cols, true ) ? '_meadow_screen_mode' : null );

		// If we can't even find any useful columns, bail out safely.
		if ( ! $col_order_id && ! $col_session_id ) {
			return [ 'order_ids' => [], 'session_ids' => [] ];
		}

		// Active window: last_seen newer than cutoff OR screen_mode indicates payment/vending in progress.
		$where = [];
		if ( $col_last_seen ) {
			// last_seen may be unix int or datetime; try both safely
			$cutoff_iso = gmdate( 'Y-m-d H:i:s', $active_cutoff_ts );
			$where[] = $wpdb->prepare( "({$col_last_seen} >= %s OR {$col_last_seen} >= %d)", $cutoff_iso, $active_cutoff_ts );
		}
		if ( $col_mode ) {
			$where[] = "({$col_mode} IN ('payment','paid','vending'))";
		}

		$where_sql = '';
		if ( $where ) $where_sql = 'WHERE ' . implode( ' OR ', $where );

		$fields = [];
		if ( $col_order_id )   $fields[] = $col_order_id . ' AS order_id';
		if ( $col_session_id ) $fields[] = $col_session_id . ' AS session_id';

		$sql = "SELECT " . implode( ',', $fields ) . " FROM {$found_table} {$where_sql}";

		try {
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( is_array( $rows ) ) {
				foreach ( $rows as $r ) {
					if ( isset( $r['order_id'] ) ) {
						$oid = (int) $r['order_id'];
						if ( $oid > 0 ) $order_ids[] = $oid;
					}
					if ( isset( $r['session_id'] ) && is_string( $r['session_id'] ) ) {
						$sid = trim( $r['session_id'] );
						if ( $sid !== '' ) $session_ids[] = $sid;
					}
				}
			}
		} catch ( \Throwable $e ) {
			self::log( 'infer_active_kiosk_references error: ' . $e->getMessage() );
		}

		$order_ids = array_values( array_unique( $order_ids ) );
		$session_ids = array_values( array_unique( $session_ids ) );

		return [
			'order_ids'   => $order_ids,
			'session_ids' => $session_ids,
		];
	}

	// ---- Counters for UI (best-effort, never fatal) ----
	private static function get_counters( array $params ) : array {
		$stage1 = 0; $stage2 = 0; $payment = 0;

		if ( function_exists( 'wc_get_orders' ) ) {
			try {
				$stale_minutes = max( 5, (int) ( $params['stage1_stale_minutes'] ?? 60 ) );
				$cutoff_ts = time() - ( $stale_minutes * 60 );
				$cutoff_gmt = gmdate( 'Y-m-d H:i:s', $cutoff_ts );

				$stage1 = (int) wc_get_orders( [
					'status'       => [ 'on-hold', 'pending' ],
					'limit'        => 1,
					'paginate'     => true,
					'date_created' => '<' . $cutoff_gmt,
					'meta_query'   => [
						[
							'key'     => self::ORDER_TYPE_META_KEY,
							'value'   => self::ORDER_TYPE_KIOSK,
							'compare' => '=',
						],
						[
							'key'     => self::CANCELLED_AT_META_KEY,
							'compare' => 'NOT EXISTS',
						],
					],
					'return'       => 'ids',
				] )->total;
			} catch ( \Throwable $e ) {
				$stage1 = 0;
			}

			try {
				$after_days = max( 1, (int) ( $params['stage2_trash_after_days'] ?? 14 ) );
				$cutoff_ts = time() - ( $after_days * DAY_IN_SECONDS );
				$cutoff_iso = gmdate( 'c', $cutoff_ts );

				$stage2 = (int) wc_get_orders( [
					'status'     => [ 'cancelled' ],
					'limit'      => 1,
					'paginate'   => true,
					'meta_query' => [
						[
							'key'     => self::ORDER_TYPE_META_KEY,
							'value'   => self::ORDER_TYPE_KIOSK,
							'compare' => '=',
						],
						[
							'key'     => self::CANCELLED_AT_META_KEY,
							'value'   => $cutoff_iso,
							'compare' => '<=',
						],
					],
					'return'     => 'ids',
				] )->total;
			} catch ( \Throwable $e ) {
				$stage2 = 0;
			}
		}

		// Payment counter: a rough estimate (still conservative); does not attempt “active” exclusions here.
		try {
			$stuck_hours = max( 12, (int) ( $params['payment_stuck_hours'] ?? 48 ) );
			$cutoff_ts = time() - ( $stuck_hours * HOUR_IN_SECONDS );
			$q = new WP_Query( [
				'post_type'      => self::PAYMENT_POST_TYPE,
				'post_status'    => [ 'publish', 'pending', 'draft', 'private' ],
				'posts_per_page' => 1,
				'date_query'     => [
					[
						'before'    => gmdate( 'Y-m-d H:i:s', $cutoff_ts ),
						'inclusive' => true,
					]
				],
				'no_found_rows'  => false,
				'fields'         => 'ids',
			] );
			$payment = (int) $q->found_posts;
		} catch ( \Throwable $e ) {
			$payment = 0;
		}

		return [
			'stage1'  => $stage1,
			'stage2'  => $stage2,
			'payment' => $payment,
		];
	}

	// ---- Logging (never fatal) ----
	private static function log( string $msg ) : void {
		if ( function_exists( 'error_log' ) ) {
			error_log( '[Meadow Order Cleanup] ' . $msg );
		}
	}
}

Meadow_Kiosk_Order_Cleanup::init();

endif;
