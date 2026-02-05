<?php
if ( ! defined('ABSPATH') ) exit;

class Meadow_Kiosk_Account {

  public function __construct() {
    add_action('init', [$this, 'add_endpoint']);
    add_filter('woocommerce_account_menu_items', [$this, 'add_menu_item']);
    add_action('woocommerce_account_kiosks_endpoint', [$this, 'render_kiosks_page']);

    // Load styling + JS only on the kiosks endpoint
    add_action('wp_enqueue_scripts', [$this, 'enqueue_inline_assets']);
  }

  public function add_endpoint() {
    add_rewrite_endpoint('kiosks', EP_ROOT | EP_PAGES);
  }

  public function add_menu_item($items) {
    $new = [];
    foreach ($items as $k => $label) {
      $new[$k] = $label;
      if ($k === 'dashboard') $new['kiosks'] = 'Kiosks';
    }
    return $new;
  }

public function enqueue_inline_assets() {
  if (!function_exists('is_account_page') || !is_account_page()) return;

  // Robust detection: match either Woo endpoint helper OR query var
  $is_kiosks = false;
  if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('kiosks')) {
    $is_kiosks = true;
  } elseif (!empty(get_query_var('kiosks'))) {
    $is_kiosks = true;
  } elseif (isset($_GET['kiosk_id'])) {
    // if you are using kiosk_id param while still on kiosks page
    $is_kiosks = true;
  }

  if (!$is_kiosks) return;

  // --- CSS polish (professional look) ---
  $css = <<<CSS
.meadow-account-kiosks { display: grid; gap: 16px; }
.meadow-account-kiosks h2 { margin: 0; }
.meadow-kiosk-list { display: grid; gap: 10px; margin: 0; padding: 0; }
.meadow-kiosk-link {
  display: block;
  padding: 12px 14px;
  border: 1px solid rgba(0,0,0,.10);
  border-radius: 12px;
  text-decoration: none;
  font-weight: 700;
}
.meadow-kiosk-link:hover { background: rgba(0,0,0,.03); }
.meadow-help { margin-top: -6px; opacity: .7; }
.meadow-card {
  border: 1px solid rgba(0,0,0,.10);
  border-radius: 14px;
  padding: 14px;
}
.meadow-kiosk-controls {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}
.meadow-kiosk-controls .button { border-radius: 10px; }
.meadow-kiosk-controls-msg { width: 100%; margin: 10px 0 0; opacity: .75; }
CSS;

  wp_register_style('meadow-account-inline', false);
  wp_enqueue_style('meadow-account-inline');
  wp_add_inline_style('meadow-account-inline', $css);

  // --- JS for control buttons ---
  $nonce = wp_create_nonce('wp_rest');
  $pi_control_url = esc_url_raw( rest_url('/meadow/v1/venue/pi/control') );

  $js = <<<JS
(function(){
  console.log('[Meadow] kiosks JS loaded');

  function \$(sel, root){ return (root||document).querySelector(sel); }
  function \$all(sel, root){ return Array.from((root||document).querySelectorAll(sel)); }

  async function postJson(url, body){
    const r = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': '{$nonce}'
      },
      body: JSON.stringify(body)
    });

    const j = await r.json().catch(()=> ({}));
    console.log('[Meadow] venue/pi/control response', r.status, j);

    if(!r.ok){
      throw new Error(j.error || j.message || ('HTTP ' + r.status));
    }
    return j;
  }

  \$all('.meadow-kiosk-controls').forEach((wrap) => {
    const kioskId = Number(wrap.dataset.kiosk || 0);
    const msg = \$('.meadow-kiosk-controls-msg', wrap);

    \$all('button[data-action]', wrap).forEach((btn) => {
      btn.addEventListener('click', async () => {
        const action = btn.dataset.action;
        console.log('[Meadow] clicked', { action, kioskId });

        if (msg) msg.textContent = 'Working…';
        \$all('button', wrap).forEach(b => b.disabled = true);

        try {
          const res = await postJson('{$pi_control_url}', {
            kiosk_post_id: kioskId,
            action: action,
            payload: {}
          });
          if (msg) msg.textContent = (res && res.message) ? res.message : 'Done ✓';
        } catch (e) {
          if (msg) msg.textContent = 'Failed: ' + (e.message || 'error');
        } finally {
          \$all('button', wrap).forEach(b => b.disabled = false);
        }
      });
    });
  });

})();
JS;

  wp_register_script('meadow-account-inline', '', [], '1.0.0', true);
  wp_enqueue_script('meadow-account-inline');
  wp_add_inline_script('meadow-account-inline', $js);
}


  public function render_kiosks_page() {
    if (!is_user_logged_in()) {
      echo '<p>Please log in.</p>';
      return;
    }

    $user_id = get_current_user_id();

    // Ownership: kiosk meta _meadow_venue_user_id == current user id
    $kiosks = get_posts([
      'post_type'      => 'kiosk',
      'posts_per_page' => -1,
      'post_status'    => 'publish',
      'meta_key'       => '_meadow_venue_user_id',
      'meta_value'     => (string)$user_id,
      'orderby'        => 'title',
      'order'          => 'ASC',
    ]);

    echo '<div class="meadow-account-kiosks">';
    echo '<h2>Kiosks</h2>';

    if (!$kiosks) {
      echo '<p>No kiosks linked to your account.</p>';
      echo '</div>';
      return;
    }

    echo '<p class="meadow-help">Click a kiosk to update stock, then use the controls below if you need to refresh the screen.</p>';
    echo '<div class="meadow-kiosk-list">';

    foreach ($kiosks as $k) {
      $url = esc_url(
        add_query_arg(['kiosk_id' => $k->ID], wc_get_account_endpoint_url('kiosks'))
      );

      $title   = get_the_title($k);
      $address = get_post_meta($k->ID, '_meadow_venue_address', true);

      $label = $title;
      if (!empty($address)) $label .= ' – ' . $address;

      echo '<a class="meadow-kiosk-link" href="'.$url.'">'.esc_html($label).'</a>';
    }

    echo '</div>';

    $kiosk_id = intval($_GET['kiosk_id'] ?? 0);
    if (!$kiosk_id) {
      echo '</div>';
      return;
    }

    // Ensure kiosk belongs to this venue user
    $owner = get_post_meta($kiosk_id, '_meadow_venue_user_id', true);
    if ((string)$owner !== (string)$user_id) {
      echo '<p>That kiosk is not linked to your account.</p>';
      echo '</div>';
      return;
    }

    $this->render_stock_table($kiosk_id);
    $this->render_kiosk_controls($kiosk_id);

    echo '</div>';
  }

  private function render_kiosk_controls(int $kiosk_id) {
    ?>
    <div class="meadow-card">
      <h3 style="margin-top:0;">Kiosk controls</h3>

      <div class="meadow-kiosk-controls" data-kiosk="<?php echo (int)$kiosk_id; ?>">
        <button type="button" class="button button-primary" data-action="enter_kiosk">Enter kiosk</button>
        <button type="button" class="button" data-action="exit_kiosk">Exit kiosk</button>
        <button type="button" class="button" data-action="reload_kiosk">Reload</button>
        <button type="button" class="button" data-action="restart_kiosk_service"
          onclick="return confirm('Restart kiosk service? This will interrupt the screen briefly.')"
        >Restart service</button>

        <p class="meadow-kiosk-controls-msg" aria-live="polite"></p>
      </div>
    </div>
    <?php
  }

  private function render_stock_table(int $kiosk_id) {

    $slots = get_post_meta($kiosk_id, '_meadow_kiosk_slots', true);
    if (!is_array($slots)) $slots = [];

    // Determine top/bottom motor labels
    $motors = [];
    foreach ($slots as $row) {
      $motor = intval($row['_meadow_motor_number'] ?? 0);
      if ($motor > 0) $motors[] = $motor;
    }
    $min_motor = $motors ? min($motors) : null;
    $max_motor = $motors ? max($motors) : null;

    $nonce = wp_create_nonce('wp_rest');
    ?>
    <div class="meadow-card">
      <h3 style="margin-top:0;">Stock – <?php echo esc_html(get_the_title($kiosk_id)); ?></h3>

      <table class="shop_table">
        <thead>
          <tr>
            <th>Motor</th>
            <th>Product</th>
            <th>Capacity</th>
            <th>Stock</th>
            <th>Remaining</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($slots as $row):
            $motor = intval($row['_meadow_motor_number'] ?? 0);
            $pid   = intval($row['_meadow_wc_product_id'] ?? 0);
            $cap   = intval($row['_meadow_capacity'] ?? 0);
            $stock = intval($row['_meadow_current_stock'] ?? 0);

            $product = $pid ? wc_get_product($pid) : null;
            $name = $product ? $product->get_name() : '—';

            // Motor label (top/bottom)
            $motor_label = (string)$motor;
            if ($motor > 0 && $min_motor !== null && $max_motor !== null) {
              if ($motor === $min_motor) $motor_label .= ' (top)';
              if ($motor === $max_motor && $max_motor !== $min_motor) $motor_label .= ' (bottom)';
            }

            // Remaining = WC product stock
            $remaining_display = '—';
            if ($product && $product->managing_stock()) {
              $wc_stock = $product->get_stock_quantity();
              $remaining_display = ($wc_stock === null) ? '—' : (string) intval($wc_stock);
            }
          ?>
            <tr>
              <td><?php echo esc_html($motor_label); ?></td>
              <td><?php echo esc_html($name); ?></td>
              <td><?php echo esc_html($cap); ?></td>
              <td>
                <input
                  type="number"
                  min="0"
                  class="meadow-stock-input"
                  data-motor="<?php echo esc_attr($motor); ?>"
                  value="<?php echo esc_attr($stock); ?>"
                  style="width:90px"
                />
              </td>
              <td><?php echo esc_html($remaining_display); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <button class="button" id="meadow-save-stock">Save stock</button>
      <p id="meadow-save-msg" style="margin-top:10px;"></p>
    </div>

    <script>
      (function(){
        const btn = document.getElementById('meadow-save-stock');
        const msg = document.getElementById('meadow-save-msg');
        const nonce = <?php echo wp_json_encode($nonce); ?>;

        if (!btn) return;

        btn.addEventListener('click', async () => {
          msg.textContent = 'Saving...';

          const updates = Array.from(document.querySelectorAll('.meadow-stock-input'))
            .map(i => ({ motor: Number(i.dataset.motor || 0), stock: Number(i.value || 0) }))
            .filter(u => u.motor > 0);

          try {
            const r = await fetch('/wp-json/meadow/v1/venue/restock', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
              },
              body: JSON.stringify({ updates })
            });

            const j = await r.json();
            if (!r.ok || !j.ok) {
              msg.textContent = 'Failed: ' + (j.error || r.status);
              return;
            }

            msg.textContent =
              'Saved ✓ (' + (j.changed ? j.changed.length : 0) + ' rows) — reloading kiosk…';

            try {
              // Venue-safe Pi route (added in Pi Bridge below)
              const rPi = await fetch('/wp-json/meadow/v1/venue/pi/control', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-WP-Nonce': nonce
                },
                body: JSON.stringify({
                  kiosk_post_id: <?php echo (int)$kiosk_id; ?>,
                  action: 'reload_kiosk',
                  payload: {}
                })
              });

              const jPi = await rPi.json().catch(() => ({}));
              console.log('venue/pi/control reload_kiosk:', rPi.status, jPi);

            } catch (e) {
              console.log('reload failed:', e);
            }

            setTimeout(() => location.reload(), 800);

          } catch (e) {
            msg.textContent = 'Failed: ' + e.message;
          }
        });
      })();
    </script>
    <?php
  }
}
