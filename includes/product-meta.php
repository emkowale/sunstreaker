<?php
if (!defined('ABSPATH')) exit;

/**
 * Per-product settings:
 * - _sunstreaker_enabled: yes|no
 * - _sunstreaker_addon_price: decimal string (e.g., 5.00)
 */

function sunstreaker_is_enabled_for_product($product_id): bool {
  $val = get_post_meta($product_id, '_sunstreaker_enabled', true);
  return ($val === 'yes');
}

function sunstreaker_get_addon_price($product_id): float {
  $raw = get_post_meta($product_id, '_sunstreaker_addon_price', true);
  if ($raw === '' || $raw === null) return 5.00;
  $raw = is_string($raw) ? $raw : (string)$raw;
  $raw = preg_replace('/[^0-9.]/', '', $raw);
  if ($raw === '') return 5.00;
  return max(0.0, (float)$raw);
}

// Toggle + addon price (General tab) — modeled after Frenzy's "Use with Frenzy"
add_action('woocommerce_product_options_general_product_data', function () {
  echo '<div class="options_group">';

  woocommerce_wp_checkbox([
    'id'          => '_sunstreaker_enabled',
    'label'       => __('Use with Sunstreaker', 'sunstreaker'),
    'desc_tip'    => true,
    'description' => __('Require Name and Number fields on the product page and store them with the order.', 'sunstreaker'),
  ]);

  echo '<div class="sunstreaker-addon-wrap" style="margin-top:8px;">';
  woocommerce_wp_text_input([
    'id'                => '_sunstreaker_addon_price',
    'label'             => __('Sunstreaker price add-on', 'sunstreaker'),
    'desc_tip'          => true,
    'description'       => __('Extra cost added to the product when Name/Number are used. Default: 5.00', 'sunstreaker'),
    'type'              => 'text',
    'custom_attributes' => [
      'inputmode' => 'decimal',
      'pattern'   => '^[0-9]+(\.[0-9]{1,2})?$',
    ],
  ]);
  echo '</div>';

  echo '</div>';
});

add_action('woocommerce_admin_process_product_object', function ($product) {
  if (!$product || !is_a($product, 'WC_Product')) return;

  $enabled = isset($_POST['_sunstreaker_enabled']) ? 'yes' : 'no';
  $product->update_meta_data('_sunstreaker_enabled', $enabled);

  $addon = isset($_POST['_sunstreaker_addon_price']) ? (string) wp_unslash($_POST['_sunstreaker_addon_price']) : '';
  $addon = preg_replace('/[^0-9.]/', '', $addon);
  if ($addon === '') $addon = '5.00';
  $addon_f = max(0.0, (float)$addon);
  $product->update_meta_data('_sunstreaker_addon_price', number_format($addon_f, 2, '.', ''));
});

add_action('admin_enqueue_scripts', function($hook){
  if ($hook !== 'post.php' && $hook !== 'post-new.php') return;
  $screen = function_exists('get_current_screen') ? get_current_screen() : null;
  if (!$screen || $screen->post_type !== 'product') return;

  $path = SUNSTREAKER_PATH.'assets/product.edit.js';
  $ver = SUNSTREAKER_VERSION;
  if (file_exists($path)) $ver = (string) filemtime($path);

  wp_enqueue_script('sunstreaker-product-edit', SUNSTREAKER_URL.'assets/product.edit.js', ['jquery'], $ver, true);
});
