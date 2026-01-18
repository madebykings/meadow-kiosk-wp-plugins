<?php
if ( ! defined('ABSPATH') ) exit;

class Meadow_Kiosk_Account {

  public function __construct() {
    add_action('init', [$this, 'add_endpoint']);
    add_filter('woocommerce_account_menu_items', [$this, 'add_menu_item']);
    add_action('woocommerce_account_kiosks_endpoint', [$this, 'render_kiosks_page']);
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

  public function render_kiosks_page() {
    if (!is_user_logged_in()) {
      echo '<p>Please log in.</p>';
      return;
    }

    $user_id = get_current_user_id();

    $kiosks = get_posts([
      'post_type'      => 'kiosk',
      'posts_per_page' => -1,
      'post_status'    => 'publish',
      'meta_key'       => '_meadow_venue_user_id',
      'meta_value'     => (string)$user_id,
      'orderby'        => 'title',
      'order'          => 'ASC',
    ]);

    if (!$kiosks) {
      echo '<p>No kiosks linked to your account.</p>';
      return;
    }

echo '<h3>Your kiosks</h3><ul>';

foreach ($kiosks as $k) {
  $url = esc_url(
    add_query_arg(['kiosk_id' => $k->ID], wc_get_account_endpoint_url('kiosks'))
  );

  $title   = get_the_title($k);
  $address = get_post_meta($k->ID, '_meadow_venue_address', true);

  $label = $title;
  if (!empty($address)) {
    $label .= ' – ' . $address;
  }

  echo '<li><a href="'.$url.'">'.esc_html($label).'</a></li>';
}

echo '</ul>';

    $kiosk_id = intval($_GET['kiosk_id'] ?? 0);
    if (!$kiosk_id) return;

    // Ensure kiosk belongs to this venue user
    $owner = get_post_meta($kiosk_id, '_meadow_venue_user_id', true);
    if ((string)$owner !== (string)$user_id) {
      echo '<p>That kiosk is not linked to your account.</p>';
      return;
    }

    $this->render_stock_table($kiosk_id);
  }

  private function render_stock_table(int $kiosk_id) {

    $slots = get_post_meta($kiosk_id, '_meadow_kiosk_slots', true);
    if (!is_array($slots)) $slots = [];

    $nonce = wp_create_nonce('wp_rest');
    ?>
    <h3>Stock – <?php echo esc_html(get_the_title($kiosk_id)); ?></h3>

    <table class="shop_table">
      <thead>
        <tr>
          <th>Motor</th>
          <th>Product</th>
          <th>Capacity</th>
          <th>Stock</th>
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
        ?>
          <tr>
            <td><?php echo esc_html($motor); ?></td>
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
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <button class="button" id="meadow-save-stock">Save stock</button>
    <p id="meadow-save-msg" style="margin-top:10px;"></p>

    <script>
      (function(){
        const btn = document.getElementById('meadow-save-stock');
        const msg = document.getElementById('meadow-save-msg');
        const nonce = <?php echo wp_json_encode($nonce); ?>;

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
  await fetch('/wp-json/meadow/v1/kiosk-screen', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce
    },
    body: JSON.stringify({
      kiosk_id: <?php echo (int)$kiosk_id; ?>,
      action: 'reload'
    })
  });
} catch (e) {
  // Non-fatal — stock is already saved
}

// Optional: refresh this page so UI matches kiosk
setTimeout(() => location.reload(), 600);

          } catch (e) {
            msg.textContent = 'Failed: ' + e.message;
          }
        });
      })();
    </script>
    <?php
  }
}
