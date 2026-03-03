<?php
if (!defined('ABSPATH')) exit;

add_filter('woocommerce_add_to_cart_validation', function($passed, $product_id, $qty){
  if (!sunstreaker_is_enabled_for_product($product_id)) return $passed;

  $name = sunstreaker_get_posted_name();
  $num  = sunstreaker_get_posted_number();

  if ($name === '' || $num === '') {
    wc_add_notice(__('Name and Number are required.', 'sunstreaker'), 'error');
    return false;
  }
  if (mb_strlen($name) > 20) {
    wc_add_notice(__('Name must be 20 characters or less.', 'sunstreaker'), 'error');
    return false;
  }
  if (!preg_match('/^[0-9]{2}$/', $num)) {
    wc_add_notice(__('Number must be two digits (00–99).', 'sunstreaker'), 'error');
    return false;
  }
  return $passed;
}, 10, 3);

add_filter('woocommerce_add_cart_item_data', function($cart_item_data, $product_id, $variation_id){
  if (!sunstreaker_is_enabled_for_product($product_id)) return $cart_item_data;

  $name = sunstreaker_get_posted_name();
  $num  = sunstreaker_get_posted_number();

  $cart_item_data['sunstreaker'] = [
    'name'   => $name,
    'number' => $num,
    'addon'  => sunstreaker_get_addon_price($product_id),
  ];

  $cart_item_data['sunstreaker_key'] = md5($name.'|'.$num.'|'.microtime(true));

  return $cart_item_data;
}, 10, 3);

add_filter('woocommerce_get_item_data', function($item_data, $cart_item){
  if (empty($cart_item['sunstreaker']) || !is_array($cart_item['sunstreaker'])) return $item_data;

  $ss = $cart_item['sunstreaker'];
  $item_data[] = ['key' => 'Name', 'value' => wc_clean($ss['name'] ?? '')];
  $item_data[] = ['key' => 'Number', 'value' => wc_clean($ss['number'] ?? '')];

  return $item_data;
}, 10, 2);

add_action('woocommerce_before_calculate_totals', function($cart){
  if (is_admin() && !defined('DOING_AJAX')) return;
  if (!$cart || !is_a($cart, 'WC_Cart')) return;

  foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
    if (empty($cart_item['sunstreaker']) || empty($cart_item['data']) || !is_a($cart_item['data'], 'WC_Product')) continue;

    $ss = $cart_item['sunstreaker'];
    $addon = isset($ss['addon']) ? (float)$ss['addon'] : 0.0;
    if ($addon <= 0) continue;

    if (isset($cart_item['sunstreaker_price_applied']) && $cart_item['sunstreaker_price_applied'] === 'yes') continue;

    $product = $cart_item['data'];
    $base = (float) $product->get_price('edit');
    $product->set_price($base + $addon);

    $cart->cart_contents[$cart_item_key]['sunstreaker_price_applied'] = 'yes';
  }
}, 20, 1);

add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values, $order){
  if (empty($values['sunstreaker']) || !is_array($values['sunstreaker'])) return;

  $ss = $values['sunstreaker'];
  $item->add_meta_data('Name', wc_clean($ss['name'] ?? ''), true);
  $item->add_meta_data('Number', wc_clean($ss['number'] ?? ''), true);

  if (isset($ss['addon'])) {
    $item->add_meta_data('Sunstreaker Add-on', wc_clean(number_format((float)$ss['addon'], 2, '.', '')), true);
  }
}, 10, 4);
