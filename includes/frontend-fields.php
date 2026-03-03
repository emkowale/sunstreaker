<?php
if (!defined('ABSPATH')) exit;

add_action('wp_enqueue_scripts', function(){
  if (!function_exists('is_product') || !is_product()) return;
  $path = SUNSTREAKER_PATH.'assets/frontend.css';
  if (!file_exists($path)) return;
  $ver = (string) filemtime($path);
  wp_enqueue_style('sunstreaker-frontend', SUNSTREAKER_URL.'assets/frontend.css', [], $ver);
});

function sunstreaker_get_posted_name(): string {
  $name = isset($_POST['sunstreaker_name']) ? (string) wp_unslash($_POST['sunstreaker_name']) : '';
  $name = trim($name);
  $name = preg_replace('/\s+/', ' ', $name);
  return $name;
}

function sunstreaker_get_posted_number(): string {
  $num = isset($_POST['sunstreaker_number']) ? (string) wp_unslash($_POST['sunstreaker_number']) : '';
  $num = trim($num);
  return $num;
}

add_action('woocommerce_before_add_to_cart_button', function(){
  global $product;
  if (!$product || !is_a($product, 'WC_Product')) return;
  $product_id = $product->get_id();
  if (!sunstreaker_is_enabled_for_product($product_id)) return;

  $posted_name = esc_attr(sunstreaker_get_posted_name());
  $posted_num  = esc_attr(sunstreaker_get_posted_number());

  echo '<div class="sunstreaker-fields" style="margin:12px 0;">';
  echo '  <div class="sunstreaker-field">';
  echo '    <label for="sunstreaker_name">Name <span class="required">*</span></label>';
  echo '    <input type="text" id="sunstreaker_name" name="sunstreaker_name" value="'.$posted_name.'" maxlength="20" required />';
  echo '  </div>';
  echo '  <div class="sunstreaker-field">';
  echo '    <label for="sunstreaker_number">Number <span class="required">*</span></label>';
  echo '    <input type="text" id="sunstreaker_number" name="sunstreaker_number" value="'.$posted_num.'" inputmode="numeric" pattern="^[0-9]{2}$" maxlength="2" required placeholder="00-99" />';
  echo '  </div>';
  echo '</div>';
});
