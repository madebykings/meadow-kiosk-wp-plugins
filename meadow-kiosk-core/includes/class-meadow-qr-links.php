<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Meadow QR + Redirect Links
 *
 * Requires Ad post type with meta:
 *  - _meadow_qr_slug (required)
 *  - _meadow_qr_dest (required, full http/https URL)
 *
 * Optional meta (if you add later):
 *  - _meadow_qr_utm_campaign
 *  - _meadow_qr_utm_content
 *
 * Endpoints:
 *  - /go/{slug}                      -> logs + redirects to destination with UTMs
 *  - /meadow/qr.png?ad_id=123&size=  -> outputs QR PNG (encodes /go/{slug})
 */

final class Meadow_QR_Links {

  const DB_VERSION   = '1.0';
  const TABLE_SCANS  = 'meadow_qr_scans';

  // ✅ Your JetEngine Ad CPT slug
  const AD_POST_TYPE = 'ad';

  public static function init(): void {
    add_action('init', [__CLASS__, 'add_rewrite_rules']);
    add_filter('query_vars', [__CLASS__, 'add_query_vars']);
    add_action('template_redirect', [__CLASS__, 'handle_requests']);

    add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
  }

  public static function activate(): void {
    self::create_table();
    self::add_rewrite_rules();
    flush_rewrite_rules();
  }

  public static function deactivate(): void {
    flush_rewrite_rules();
  }

  public static function add_rewrite_rules(): void {
    add_rewrite_rule(
      '^go/([^/]+)/?$',
      'index.php?meadow_go=1&meadow_slug=$matches[1]',
      'top'
    );

    add_rewrite_rule(
      '^meadow/qr\.png$',
      'index.php?meadow_qr_png=1',
      'top'
    );
  }

  public static function add_query_vars(array $vars): array {
    $vars[] = 'meadow_go';
    $vars[] = 'meadow_slug';
    $vars[] = 'meadow_qr_png';
    return $vars;
  }

  public static function handle_requests(): void {
    if (get_query_var('meadow_qr_png') === '1') {
      self::serve_qr_png();
    }
    if (get_query_var('meadow_go') === '1') {
      self::handle_go_redirect();
    }
  }

  /**
   * Outputs QR as PNG.
   * Encodes: https://yoursite.com/go/{slug}
   *
   * Usage:
   *  /meadow/qr.png?ad_id=123&size=1200
   */
  private static function serve_qr_png(): void {
    $ad_id = isset($_GET['ad_id']) ? (int) $_GET['ad_id'] : 0;

    // size is a "hint" for output size; module size is derived safely
    $size  = isset($_GET['size']) ? (int) $_GET['size'] : 1200;
    $size  = max(200, min(2400, $size));

    if (!$ad_id) {
      status_header(400);
      header('Content-Type: text/plain; charset=utf-8');
      echo 'Missing ad_id';
      exit;
    }

    // Default: only editors of the ad can fetch PNG.
    // If you want public downloads, we can switch this to a signed token.
    if ( ! current_user_can('edit_post', $ad_id) ) {
      status_header(403);
      header('Content-Type: text/plain; charset=utf-8');
      echo 'Forbidden';
      exit;
    }

    $slug = (string) get_post_meta($ad_id, '_meadow_qr_slug', true);
    $slug = sanitize_title($slug);

    if ($slug === '') {
      status_header(400);
      header('Content-Type: text/plain; charset=utf-8');
      echo 'Missing _meadow_qr_slug on this Ad';
      exit;
    }

    $go_url = home_url('/go/' . rawurlencode($slug));

    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=3600');

    // 1) Preferred path: chillerlan if present (optional)
    if (class_exists('\chillerlan\QRCode\QRCode') && class_exists('\chillerlan\QRCode\QROptions')) {
      $scale = (int) round($size / 140);
      $scale = max(4, min(24, $scale));

      $options = new \chillerlan\QRCode\QROptions([
        'outputType'   => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
        'eccLevel'     => \chillerlan\QRCode\QRCode::ECC_M,
        'scale'        => $scale,
        'imageBase64'  => false,
      ]);

      echo (new \chillerlan\QRCode\QRCode($options))->render($go_url);
      exit;
    }

    // 2) Compliant fallback: bundled phpqrcode (LGPL)
    $phpqrcode = MEADOW_KIOSK_CORE_PATH . 'includes/lib/phpqrcode/qrlib.php';
    if (!file_exists($phpqrcode)) {
      status_header(500);
      header('Content-Type: text/plain; charset=utf-8');
      echo 'QR library missing. Expected: includes/lib/phpqrcode/qrlib.php';
      error_log('[Meadow_QR] Missing phpqrcode fallback at includes/lib/phpqrcode/qrlib.php');
      exit;
    }

    require_once $phpqrcode;

    // module size heuristic for print-ready output
    $module = (int) round($size / 180);
    $module = max(3, min(20, $module));
    $margin = 2;

    try {
      // phpqrcode signature: QRcode::png($text, $outfile=false, $level='L|M|Q|H', $size=3.., $margin=4..)
      \QRcode::png($go_url, false, 'M', $module, $margin);
      exit;
    } catch (\Throwable $e) {
      status_header(500);
      header('Content-Type: text/plain; charset=utf-8');
      echo 'QR generation failed (phpqrcode).';
      error_log('[Meadow_QR] phpqrcode failure: ' . $e->getMessage());
      exit;
    }
  }

  /**
   * /go/{slug} -> look up Ad by _meadow_qr_slug -> log scan -> redirect to _meadow_qr_dest with UTMs
   */
  private static function handle_go_redirect(): void {
    $slug = (string) get_query_var('meadow_slug');
    $slug = sanitize_title($slug);

    if ($slug === '') {
      status_header(404);
      header('Content-Type: text/plain; charset=utf-8');
      echo 'Not Found';
      exit;
    }

    $ad_id = self::find_ad_id_by_slug($slug);

    if (!$ad_id) {
      status_header(404);
      header('Content-Type: text/plain; charset=utf-8');
      echo 'Not Found';
      exit;
    }

    $dest = (string) get_post_meta($ad_id, '_meadow_qr_dest', true);
    $dest = trim($dest);

    if ($dest === '' || ! self::is_safe_http_url($dest)) {
      status_header(404);
      header('Content-Type: text/plain; charset=utf-8');
      echo 'Not Found';
      exit;
    }

    $utm_campaign = (string) get_post_meta($ad_id, '_meadow_qr_utm_campaign', true);
    $utm_content  = (string) get_post_meta($ad_id, '_meadow_qr_utm_content', true);

    $utm = [
      'utm_source'   => 'qr',
      'utm_medium'   => 'offline',
      'utm_campaign' => $utm_campaign !== '' ? $utm_campaign : $slug,
    ];
    if ($utm_content !== '') {
      $utm['utm_content'] = $utm_content;
    }

    $final = add_query_arg($utm, $dest);

    self::log_scan($slug, $ad_id, $final);

    wp_redirect($final, 302);
    exit;
  }

  private static function find_ad_id_by_slug(string $slug): int {
    $q = new \WP_Query([
      'post_type'              => self::AD_POST_TYPE,
      'post_status'            => 'publish',
      'posts_per_page'         => 1,
      'fields'                 => 'ids',
      'no_found_rows'          => true,
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
      'meta_query'             => [[
        'key'     => '_meadow_qr_slug',
        'value'   => $slug,
        'compare' => '='
      ]]
    ]);

    if (empty($q->posts)) return 0;
    return (int) $q->posts[0];
  }

  private static function is_safe_http_url(string $url): bool {
    return (bool) preg_match('~^https?://~i', $url);
  }

  private static function log_scan(string $slug, int $ad_id, string $final_url): void {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE_SCANS;

    $ip      = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ip_hash = $ip !== '' ? hash('sha256', $ip . NONCE_SALT) : '';

    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    try {
      $wpdb->insert(
        $table,
        [
          'scanned_at_utc' => gmdate('Y-m-d H:i:s'),
          'slug'           => $slug,
          'ad_id'          => $ad_id,
          'ip_hash'        => $ip_hash,
          'user_agent'     => $ua,
          'dest_url'       => substr($final_url, 0, 1000),
        ],
        ['%s','%s','%d','%s','%s','%s']
      );
    } catch (\Throwable $e) {
      // best-effort only
    }
  }

  private static function create_table(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table   = $wpdb->prefix . self::TABLE_SCANS;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      scanned_at_utc DATETIME NOT NULL,
      slug VARCHAR(120) NOT NULL,
      ad_id BIGINT UNSIGNED NOT NULL,
      ip_hash CHAR(64) NULL,
      user_agent VARCHAR(255) NULL,
      dest_url VARCHAR(1000) NULL,
      PRIMARY KEY (id),
      KEY slug (slug),
      KEY ad_id (ad_id),
      KEY scanned_at_utc (scanned_at_utc)
    ) {$charset};";

    dbDelta($sql);
  }

  // Admin: Meta box with Download QR PNG link + the /go/ URL
  public static function add_meta_box(): void {
    add_meta_box(
      'meadow_qr_links_box',
      'Meadow QR',
      [__CLASS__, 'render_meta_box'],
      self::AD_POST_TYPE,
      'side',
      'high'
    );
  }

  public static function render_meta_box(\WP_Post $post): void {
    $ad_id = (int) $post->ID;

    $slug = (string) get_post_meta($ad_id, '_meadow_qr_slug', true);
    $slug = sanitize_title($slug);

    if ($slug === '') {
      echo '<p><em>Add <code>_meadow_qr_slug</code> to enable QR generation.</em></p>';
      return;
    }

    $go_url  = home_url('/go/' . rawurlencode($slug));
    $png_url = add_query_arg(['ad_id' => $ad_id, 'size' => 1200], home_url('/meadow/qr.png'));

    echo '<p><strong>Go URL</strong><br><code style="word-break:break-all;">' . esc_html($go_url) . '</code></p>';
    echo '<p><a class="button button-primary" target="_blank" href="' . esc_url($png_url) . '">Download QR PNG</a></p>';
    echo '<p style="margin-top:8px;"><small>Scans are logged and redirected to <code>_meadow_qr_dest</code>.</small></p>';
  }
}
