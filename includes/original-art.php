<?php
if (!defined('ABSPATH')) exit;

add_action('woocommerce_checkout_order_processed', 'sunstreaker_generate_original_art_for_order', 5);
add_action('woocommerce_payment_complete', 'sunstreaker_generate_original_art_for_order', 5);
add_action('woocommerce_order_status_on-hold', 'sunstreaker_generate_original_art_for_order', 5);
add_action('woocommerce_order_status_processing', 'sunstreaker_generate_original_art_for_order', 5);
add_action('woocommerce_order_status_completed', 'sunstreaker_generate_original_art_for_order', 5);
add_action('woocommerce_checkout_order_processed', 'sunstreaker_generate_mockup_assets_for_order', 6);
add_action('woocommerce_payment_complete', 'sunstreaker_generate_mockup_assets_for_order', 6);
add_action('woocommerce_order_status_on-hold', 'sunstreaker_generate_mockup_assets_for_order', 6);
add_action('woocommerce_order_status_processing', 'sunstreaker_generate_mockup_assets_for_order', 6);
add_action('woocommerce_order_status_completed', 'sunstreaker_generate_mockup_assets_for_order', 6);

function sunstreaker_order_item_sync_print_location($item, array $required_locations): void {
  if (!$item || !is_a($item, 'WC_Order_Item_Product')) return;

  $raw = trim((string) $item->get_meta('Print Location', true));
  if ($raw === '') {
    $product = $item->get_product();
    if ($product && is_a($product, 'WC_Product')) {
      $raw = trim((string) $product->get_meta('Print Location', true));
      if ($raw === '' && method_exists($product, 'get_parent_id')) {
        $parent_id = (int) $product->get_parent_id();
        if ($parent_id > 0) {
          $raw = trim((string) get_post_meta($parent_id, 'Print Location', true));
        }
      }
    }
  }

  $locations = function_exists('sunstreaker_parse_print_locations')
    ? sunstreaker_parse_print_locations($raw)
    : array_values(array_filter(array_map('trim', preg_split('/\s*(?:,|;|\||\/|\band\b|&)\s*/i', $raw))));

  foreach ($required_locations as $location) {
    if (function_exists('sunstreaker_append_print_location')) {
      sunstreaker_append_print_location($locations, (string) $location);
      continue;
    }

    $location = trim((string) $location);
    if ($location === '') continue;
    $normalized = strtolower(preg_replace('/\s+/', ' ', $location));
    $exists = false;
    foreach ($locations as $existing) {
      if (strtolower(preg_replace('/\s+/', ' ', trim((string) $existing))) === $normalized) {
        $exists = true;
        break;
      }
    }
    if (!$exists) $locations[] = $location;
  }

  $formatted = implode(', ', array_values(array_filter($locations)));
  if ($formatted !== '') {
    $item->update_meta_data('Print Location', $formatted);
  }
}

function sunstreaker_sync_generated_original_art_meta($item, string $png_url): void {
  if (!$item || !is_a($item, 'WC_Order_Item_Product')) return;

  $item->delete_meta_data('Original Art - Name Number Back');

  $png_url = trim($png_url);
  if ($png_url !== '') {
    $png_url = esc_url_raw($png_url);
    $item->update_meta_data('Original Art Back', $png_url);
    $item->update_meta_data('Original Art - Back', $png_url);
    sunstreaker_order_item_sync_print_location($item, ['Back']);
    return;
  }

  $item->delete_meta_data('Original Art Back');
  $item->delete_meta_data('Original Art - Back');
}

function sunstreaker_preferred_mockup_display_url(string $svg_url, string $png_url = ''): string {
  $svg_url = trim($svg_url);
  if ($svg_url !== '') {
    return esc_url_raw($svg_url);
  }

  $png_url = trim($png_url);
  return $png_url !== '' ? esc_url_raw($png_url) : '';
}

function sunstreaker_sync_generated_mockup_meta($item, string $mockup_url): void {
  if (!$item || !is_a($item, 'WC_Order_Item_Product')) return;

  $mockup_url = trim($mockup_url);
  if ($mockup_url !== '') {
    $mockup_url = esc_url_raw($mockup_url);
    $item->update_meta_data('mockup_url', $mockup_url);
    $item->update_meta_data('Mockup', $mockup_url);
    return;
  }

  $item->delete_meta_data('mockup_url');
  $item->delete_meta_data('Mockup');
}

function sunstreaker_upload_url_from_path(string $path): string {
  $path = trim($path);
  if ($path === '') return '';

  $uploads = wp_upload_dir(null, false);
  $baseurl = isset($uploads['baseurl']) ? trailingslashit((string) $uploads['baseurl']) : '';
  $basedir = isset($uploads['basedir']) ? trailingslashit((string) $uploads['basedir']) : '';
  if ($baseurl === '' || $basedir === '') return '';

  $normalized_path = wp_normalize_path($path);
  $normalized_basedir = wp_normalize_path($basedir);
  if (strpos($normalized_path, $normalized_basedir) !== 0) return '';

  $relative = ltrim(substr($normalized_path, strlen($normalized_basedir)), '/');
  return $relative !== '' ? esc_url_raw($baseurl . str_replace(DIRECTORY_SEPARATOR, '/', $relative)) : '';
}

function sunstreaker_prepare_cart_mockup_paths(string $token) {
  $token = sanitize_key($token);
  if ($token === '') {
    return new WP_Error('sunstreaker_cart_mockup_token_missing', 'Cart mockup token is required.');
  }

  $uploads = wp_upload_dir(null, false);
  if (!empty($uploads['error'])) {
    return new WP_Error('sunstreaker_cart_mockup_upload_path_error', (string) $uploads['error']);
  }

  $dir = trailingslashit($uploads['basedir']).'sunstreaker-cart-mockups/'.gmdate('Y/m');
  if (!wp_mkdir_p($dir)) {
    return new WP_Error('sunstreaker_cart_mockup_mkdir_failed', 'Could not create cart mockup upload directory.');
  }

  $base = sanitize_file_name('cart-mockup-'.$token);
  $svg_path = trailingslashit($dir).$base.'.svg';

  return [
    'svg_path' => $svg_path,
  ];
}

function sunstreaker_cart_mockup_token(array $cart_item_data, int $product_id, int $variation_id): string {
  $ss = !empty($cart_item_data['sunstreaker']) && is_array($cart_item_data['sunstreaker']) ? $cart_item_data['sunstreaker'] : [];
  $source = [
    'key' => (string) ($cart_item_data['sunstreaker_key'] ?? ''),
    'product_id' => $product_id,
    'variation_id' => $variation_id,
    'name' => (string) ($ss['name'] ?? ''),
    'number' => (string) ($ss['number'] ?? ''),
    'font_choice' => (string) ($ss['font_choice'] ?? ''),
    'right_chest_name_credentials' => (string) ($ss['right_chest_name_credentials'] ?? ''),
    'right_chest_department' => (string) ($ss['right_chest_department'] ?? ''),
    'right_chest_font_choice' => (string) ($ss['right_chest_font_choice'] ?? ''),
    'logo_urls' => array_values(array_filter(array_map('strval', (array) ($ss['logo_urls'] ?? [])))),
    'logo_url' => (string) ($ss['logo_url'] ?? ''),
    'front_art_url' => (string) ($ss['front_art_url'] ?? ''),
    'front_transform' => $ss['front_transform'] ?? [],
    'back_art_url' => (string) ($ss['back_art_url'] ?? ''),
    'back_transform' => $ss['back_transform'] ?? [],
    'preview_image_url' => (string) ($ss['preview_image_url'] ?? ''),
    'preview_image_width' => (int) ($ss['preview_image_width'] ?? 0),
    'preview_image_height' => (int) ($ss['preview_image_height'] ?? 0),
  ];

  return md5(wp_json_encode($source));
}

function sunstreaker_get_cart_item_mockup_args(array $cart_item_data, int $product_id, int $variation_id): array {
  $ss = !empty($cart_item_data['sunstreaker']) && is_array($cart_item_data['sunstreaker']) ? $cart_item_data['sunstreaker'] : [];
  $product = null;
  if (!empty($cart_item_data['data']) && is_a($cart_item_data['data'], 'WC_Product')) {
    $product = $cart_item_data['data'];
  } elseif (function_exists('wc_get_product')) {
    $target_id = $variation_id > 0 ? $variation_id : $product_id;
    if ($target_id > 0) {
      $product = wc_get_product($target_id);
    }
  }

  $settings_product_id = $variation_id > 0 ? $variation_id : $product_id;
  if (function_exists('sunstreaker_get_settings_product_id') && $settings_product_id > 0) {
    $settings_product_id = sunstreaker_get_settings_product_id($settings_product_id);
  }
  if ($settings_product_id <= 0) {
    $settings_product_id = $product_id > 0 ? $product_id : $variation_id;
  }

  $reference = function_exists('sunstreaker_get_cart_thumbnail_reference')
    ? sunstreaker_get_cart_thumbnail_reference($cart_item_data, $product, $settings_product_id)
    : ['width' => 1200, 'height' => 1200, 'aspect_ratio' => 1];
  $boundaries = function_exists('sunstreaker_get_preview_boundaries')
    ? sunstreaker_get_preview_boundaries($settings_product_id)
    : (function_exists('sunstreaker_default_preview_boundaries') ? sunstreaker_default_preview_boundaries() : []);
  $ink_color = !empty($ss['ink_color'])
    ? (string) $ss['ink_color']
    : (function_exists('sunstreaker_get_ink_color') ? sunstreaker_get_ink_color($settings_product_id) : 'White');
  if (function_exists('sunstreaker_normalize_ink_color')) {
    $ink_color = sunstreaker_normalize_ink_color($ink_color);
  }
  $font_stack = !empty($ss['font_stack'])
    ? (string) $ss['font_stack']
    : (function_exists('sunstreaker_get_font_stack') ? sunstreaker_get_font_stack($settings_product_id) : '"Varsity Block","Freshman","College","Oswald","Arial Black",sans-serif');
  if (function_exists('sunstreaker_sanitize_font_stack')) {
    $font_stack = sunstreaker_sanitize_font_stack($font_stack);
  }
  $right_chest_font_stack = !empty($ss['right_chest_font_stack'])
    ? (string) $ss['right_chest_font_stack']
    : (function_exists('sunstreaker_get_right_chest_font_stack') ? sunstreaker_get_right_chest_font_stack($settings_product_id) : $font_stack);
  if (function_exists('sunstreaker_sanitize_font_stack')) {
    $right_chest_font_stack = sunstreaker_sanitize_font_stack($right_chest_font_stack);
  }

  return [
    'image_url' => function_exists('sunstreaker_get_cart_thumbnail_image_url')
      ? sunstreaker_get_cart_thumbnail_image_url($cart_item_data, $product, $settings_product_id)
      : '',
    'reference' => [
      'width' => max(1, (int) ($reference['width'] ?? 1200)),
      'height' => max(1, (int) ($reference['height'] ?? 1200)),
    ],
    'boundaries' => $boundaries,
    'ink_color' => $ink_color,
    'font_stack' => $font_stack,
    'right_chest_font_stack' => $right_chest_font_stack,
    'right_chest_font_choice' => sanitize_key((string) ($ss['right_chest_font_choice'] ?? '')),
    'name' => trim((string) ($ss['name'] ?? '')),
    'number' => trim((string) ($ss['number'] ?? '')),
    'right_chest_name_credentials' => trim((string) ($ss['right_chest_name_credentials'] ?? '')),
    'right_chest_department' => trim((string) ($ss['right_chest_department'] ?? '')),
    'logo_urls' => array_values(array_filter(array_map('esc_url_raw', array_map('strval', (array) ($ss['logo_urls'] ?? []))))),
    'logo_url' => esc_url_raw((string) ($ss['logo_url'] ?? '')),
    'front_art_url' => esc_url_raw((string) ($ss['front_art_url'] ?? '')),
    'front_transform' => function_exists('sunstreaker_sanitize_front_back_transform')
      ? sunstreaker_sanitize_front_back_transform($ss['front_transform'] ?? '')
      : (array) ($ss['front_transform'] ?? []),
    'back_art_url' => esc_url_raw((string) ($ss['back_art_url'] ?? '')),
    'back_transform' => function_exists('sunstreaker_sanitize_front_back_transform')
      ? sunstreaker_sanitize_front_back_transform($ss['back_transform'] ?? '')
      : (array) ($ss['back_transform'] ?? []),
    'svg_class' => '',
    'aria_hidden' => true,
  ];
}

function sunstreaker_ensure_cart_item_mockup_data(array $cart_item_data, int $product_id = 0, int $variation_id = 0): array {
  if (empty($cart_item_data['sunstreaker']) || !is_array($cart_item_data['sunstreaker'])) {
    return $cart_item_data;
  }

  $ss = $cart_item_data['sunstreaker'];
  $existing_svg_url = trim((string) ($ss['mockup_svg_url'] ?? ''));
  if ($existing_svg_url !== '' && filter_var($existing_svg_url, FILTER_VALIDATE_URL)) {
    $cart_item_data['sunstreaker']['mockup_svg_url'] = esc_url_raw($existing_svg_url);
    $cart_item_data['sunstreaker']['mockup_url'] = sunstreaker_preferred_mockup_display_url($existing_svg_url, (string) ($ss['mockup_png_url'] ?? ''));
    return $cart_item_data;
  }

  $args = sunstreaker_get_cart_item_mockup_args($cart_item_data, $product_id, $variation_id);
  if (!sunstreaker_item_has_mockup_data($args)) {
    return $cart_item_data;
  }

  $width = max(1, (int) ($args['reference']['width'] ?? 1200));
  $height = max(1, (int) ($args['reference']['height'] ?? 1200));
  $svg = function_exists('sunstreaker_render_front_back_composite_svg')
    ? sunstreaker_render_front_back_composite_svg($args)
    : (function_exists('sunstreaker_render_composite_svg') ? sunstreaker_render_composite_svg($args) : '');
  if ($svg === '') {
    return $cart_item_data;
  }

  $svg = sunstreaker_embed_font_faces_in_svg($svg, [
    (string) ($args['font_stack'] ?? ''),
    (string) ($args['right_chest_font_stack'] ?? ''),
  ]);
  $svg = sunstreaker_set_svg_intrinsic_size($svg, $width, $height);
  $svg = sunstreaker_inline_svg_image_assets($svg);

  $token = sunstreaker_cart_mockup_token($cart_item_data, $product_id, $variation_id);
  $paths = sunstreaker_prepare_cart_mockup_paths($token);
  if (is_wp_error($paths)) {
    return $cart_item_data;
  }

  $svg_path = (string) ($paths['svg_path'] ?? '');
  if ($svg_path === '') {
    return $cart_item_data;
  }

  if (!file_exists($svg_path) || !is_readable($svg_path)) {
    $svg_written = @file_put_contents($svg_path, $svg);
    if ($svg_written === false) {
      return $cart_item_data;
    }
  }

  $svg_url = sunstreaker_upload_url_from_path($svg_path);
  if ($svg_url === '') {
    return $cart_item_data;
  }

  $cart_item_data['sunstreaker']['mockup_svg_url'] = esc_url_raw($svg_url);
  $cart_item_data['sunstreaker']['mockup_url'] = esc_url_raw($svg_url);
  unset($cart_item_data['sunstreaker']['mockup_png_url']);

  return $cart_item_data;
}

add_filter('woocommerce_add_cart_item_data', function($cart_item_data, $product_id, $variation_id) {
  return sunstreaker_ensure_cart_item_mockup_data($cart_item_data, (int) $product_id, (int) $variation_id);
}, 99, 3);

add_filter('woocommerce_add_cart_item', function($cart_item) {
  return sunstreaker_ensure_cart_item_mockup_data(
    $cart_item,
    isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0,
    isset($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0
  );
}, 15, 1);

add_filter('woocommerce_get_cart_item_from_session', function($cart_item) {
  return sunstreaker_ensure_cart_item_mockup_data(
    $cart_item,
    isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0,
    isset($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0
  );
}, 15, 1);

function sunstreaker_generate_original_art_for_order($order_id): void {
  if (!function_exists('wc_get_order')) return;
  $order = wc_get_order($order_id);
  if (!$order || !is_a($order, 'WC_Order')) return;

  foreach ($order->get_items('line_item') as $item_id => $item) {
    if (!$item || !is_a($item, 'WC_Order_Item_Product')) continue;
    $existing_png_url = trim((string) $item->get_meta('_sunstreaker_original_art_png_url', true));
    if ($item->get_meta('_sunstreaker_original_art_generated', true) === 'yes' && $existing_png_url !== '') {
      sunstreaker_sync_generated_original_art_meta($item, $existing_png_url);
      $item->save();
      continue;
    }

    $name = trim((string) $item->get_meta('Name', true));
    $number = trim((string) $item->get_meta('Number', true));
    if ($name === '' || $number === '') continue;

    $result = sunstreaker_generate_original_art_for_item($order, (int) $item_id, $item, $name, $number);
    if (is_wp_error($result)) {
      $item->update_meta_data('_sunstreaker_original_art_error', $result->get_error_message());
      $item->save();
      $order->add_order_note(
        sprintf(
          'Sunstreaker: failed to generate Original Art Back for item %d (%s).',
          (int) $item_id,
          $result->get_error_message()
        )
      );
      continue;
    }

    $item->update_meta_data('_sunstreaker_original_art_generated', 'yes');
    $item->update_meta_data('_sunstreaker_original_art_generated_at', current_time('mysql'));
    $item->update_meta_data('_sunstreaker_original_art_svg_id', (int) ($result['svg_id'] ?? 0));
    $item->update_meta_data('_sunstreaker_original_art_png_id', (int) ($result['png_id'] ?? 0));
    $item->update_meta_data('_sunstreaker_original_art_size', (string) ($result['size_label'] ?? ''));
    $item->update_meta_data('_sunstreaker_original_art_font', (string) ($result['font_family'] ?? ''));
    $item->update_meta_data('_sunstreaker_original_art_width_in', (string) ($result['design_width_in'] ?? ''));
    if (!empty($result['svg_url'])) $item->update_meta_data('_sunstreaker_original_art_svg_url', esc_url_raw($result['svg_url']));
    if (!empty($result['png_url'])) {
      $item->update_meta_data('_sunstreaker_original_art_png_url', esc_url_raw($result['png_url']));
      sunstreaker_sync_generated_original_art_meta($item, (string) $result['png_url']);
    } else {
      $item->delete_meta_data('_sunstreaker_original_art_png_url');
      sunstreaker_sync_generated_original_art_meta($item, '');
    }
    $item->save();

    $order->add_order_note(
      sprintf(
        'Sunstreaker: Original Art Back generated for item %d. SVG: %s',
        (int) $item_id,
        (string) ($result['svg_url'] ?? 'n/a')
      )
    );
  }
}

function sunstreaker_generate_original_art_for_item($order, int $item_id, $item, string $name, string $number) {
  $product = $item->get_product();
  $product_id = $product ? (int) $product->get_id() : (int) $item->get_product_id();
  $settings_product_id = function_exists('sunstreaker_get_settings_product_id')
    ? sunstreaker_get_settings_product_id($product_id)
    : $product_id;

  $size_label = sunstreaker_get_order_item_size_label($item);
  $profile = sunstreaker_design_profile_for_size($size_label);
  $ink_color = sunstreaker_get_order_item_ink_color($item, $product_id);
  $font_choice_key = trim((string) $item->get_meta('_sunstreaker_font_choice', true));
  $font_choice = function_exists('sunstreaker_get_font_choice_data')
    ? sunstreaker_get_font_choice_data($font_choice_key, function_exists('sunstreaker_get_name_number_font_choice_key') ? sunstreaker_get_name_number_font_choice_key($settings_product_id) : 'varsity_block')
    : [];
  $font_family = isset($font_choice['family']) ? trim((string) $font_choice['family']) : '';
  if ($font_family === '') {
    $font_family = sunstreaker_get_original_art_font_family($product, $settings_product_id);
  }
  $font_stack = isset($font_choice['stack']) ? trim((string) $font_choice['stack']) : '';
  if ($font_stack === '') {
    $font_stack = function_exists('sunstreaker_get_font_stack')
      ? sunstreaker_get_font_stack($settings_product_id)
      : "\"Varsity Block\",\"Freshman\",\"College\",\"Oswald\",\"Arial Black\",sans-serif";
  }
  $boundaries = function_exists('sunstreaker_get_preview_boundaries')
    ? sunstreaker_get_preview_boundaries($settings_product_id)
    : [
      'name' => ['x' => 0.22, 'y' => 0.26, 'w' => 0.56, 'h' => 0.12],
      'number' => ['x' => 0.30, 'y' => 0.41, 'w' => 0.40, 'h' => 0.24],
    ];
  $preview_reference = function_exists('sunstreaker_get_product_preview_reference')
    ? sunstreaker_get_product_preview_reference($product, $settings_product_id)
    : ['width' => 1200, 'height' => 1200, 'aspect_ratio' => 1];

  $design_width_in = (float) $profile['width_in'];
  if ($design_width_in <= 0) $design_width_in = 10.0;

  $svg_bundle = sunstreaker_render_original_art_svg([
    'name' => $name,
    'number' => $number,
    'ink_color' => $ink_color,
    'font_family' => $font_family,
    'font_stack' => $font_stack,
    'design_width_in' => $design_width_in,
    'boundaries' => $boundaries,
    'preview_reference' => $preview_reference,
    'mockup_svg_url' => trim((string) $item->get_meta('_sunstreaker_mockup_svg_url', true)),
    'dpi' => 600,
  ]);
  if (is_wp_error($svg_bundle)) return $svg_bundle;

  $paths = sunstreaker_prepare_original_art_paths((int) $order->get_id(), $item_id);
  if (is_wp_error($paths)) return $paths;

  $svg_contents = (string) $svg_bundle['svg'];
  $svg_written = @file_put_contents($paths['svg_path'], $svg_contents);
  if ($svg_written === false) {
    return new WP_Error('sunstreaker_original_art_write_svg_failed', 'Could not write SVG art file.');
  }

  $png_result = sunstreaker_convert_svg_to_png($paths['svg_path'], $paths['png_path'], 600);
  if (is_wp_error($png_result)) {
    return $png_result;
  }

  $order_title = function_exists('sanitize_text_field') ? sanitize_text_field($item->get_name()) : $item->get_name();
  $svg_title = 'Original Art Back SVG - Order '.$order->get_id().' - '.$order_title;
  $png_title = 'Original Art Back PNG - Order '.$order->get_id().' - '.$order_title;

  $svg_id = sunstreaker_insert_generated_attachment($paths['svg_path'], $svg_title, (int) $order->get_id(), 'image/svg+xml', false);
  if (is_wp_error($svg_id)) return $svg_id;
  $png_id = sunstreaker_insert_generated_attachment($paths['png_path'], $png_title, (int) $order->get_id(), 'image/png', false);
  if (is_wp_error($png_id)) return $png_id;

  $svg_url = wp_get_attachment_url((int) $svg_id);
  $png_url = wp_get_attachment_url((int) $png_id);
  if (!$svg_url) $svg_url = sunstreaker_upload_url_from_path($paths['svg_path']);
  if (!$png_url) $png_url = sunstreaker_upload_url_from_path($paths['png_path']);

  return [
    'svg_id' => (int) $svg_id,
    'svg_url' => $svg_url ? $svg_url : '',
    'png_id' => (int) $png_id,
    'png_url' => $png_url ? $png_url : '',
    'design_width_in' => number_format($design_width_in, 2, '.', ''),
    'font_family' => $font_family,
    'size_label' => $size_label,
  ];
}

function sunstreaker_prepare_original_art_paths(int $order_id, int $item_id) {
  $uploads = wp_upload_dir(null, false);
  if (!empty($uploads['error'])) {
    return new WP_Error('sunstreaker_original_art_upload_path_error', (string) $uploads['error']);
  }

  $dir = trailingslashit($uploads['basedir']).'sunstreaker-original-art/'.gmdate('Y/m');
  if (!wp_mkdir_p($dir)) {
    return new WP_Error('sunstreaker_original_art_mkdir_failed', 'Could not create original art upload directory.');
  }

  $base = sanitize_file_name('original-art-back-order-'.$order_id.'-item-'.$item_id);
  $svg_filename = wp_unique_filename($dir, $base.'.svg');
  $svg_path = trailingslashit($dir).$svg_filename;
  $png_filename = preg_replace('/\.svg$/i', '.png', $svg_filename);
  $png_path = trailingslashit($dir).$png_filename;

  return [
    'svg_path' => $svg_path,
    'png_path' => $png_path,
  ];
}

function sunstreaker_prepare_mockup_paths(int $order_id, int $item_id) {
  $uploads = wp_upload_dir(null, false);
  if (!empty($uploads['error'])) {
    return new WP_Error('sunstreaker_mockup_upload_path_error', (string) $uploads['error']);
  }

  $dir = trailingslashit($uploads['basedir']).'sunstreaker-mockups/'.gmdate('Y/m');
  if (!wp_mkdir_p($dir)) {
    return new WP_Error('sunstreaker_mockup_mkdir_failed', 'Could not create mockup upload directory.');
  }

  $base = sanitize_file_name('mockup-order-'.$order_id.'-item-'.$item_id);
  $svg_filename = wp_unique_filename($dir, $base.'.svg');
  $svg_path = trailingslashit($dir).$svg_filename;
  $png_filename = preg_replace('/\.svg$/i', '.png', $svg_filename);
  $png_path = trailingslashit($dir).$png_filename;

  return [
    'svg_path' => $svg_path,
    'png_path' => $png_path,
  ];
}

function sunstreaker_find_binary_command(array $candidates): string {
  foreach ($candidates as $candidate) {
    $candidate = trim((string) $candidate);
    if ($candidate === '') continue;

    if (strpos($candidate, DIRECTORY_SEPARATOR) !== false) {
      if (is_file($candidate) && is_executable($candidate)) return $candidate;
      continue;
    }

    if (function_exists('shell_exec')) {
      $resolved = trim((string) @shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null'));
      if ($resolved !== '' && is_file($resolved) && is_executable($resolved)) return $resolved;
    }
  }

  return '';
}

function sunstreaker_convert_svg_to_png(string $svg_path, string $png_path, int $dpi = 0) {
  if (!file_exists($svg_path)) {
    return new WP_Error('sunstreaker_svg_missing', 'SVG source not found for PNG conversion.');
  }

  $binary = sunstreaker_find_binary_command(['/usr/bin/convert', '/usr/bin/magick', 'convert', 'magick']);
  if ($binary === '') {
    return new WP_Error('sunstreaker_convert_missing', 'ImageMagick convert is not available.');
  }

  $command = escapeshellcmd($binary);
  if (preg_match('~/magick$~', $binary)) {
    $command .= ' convert';
  }
  if ($dpi > 0) {
    $command .= ' -units PixelsPerInch -density '.(int) $dpi;
  }
  $command .= ' -background none -alpha set -define png:color-type=6 '.escapeshellarg($svg_path).' '.escapeshellarg($png_path).' 2>&1';

  $output = [];
  $code = 0;
  @exec($command, $output, $code);
  if ($code !== 0 || !file_exists($png_path)) {
    return new WP_Error(
      'sunstreaker_convert_failed',
      trim(implode("\n", array_filter(array_map('strval', $output)))) ?: 'Could not convert SVG to PNG.'
    );
  }

  return $png_path;
}

function sunstreaker_measure_trimmed_png_bounds(string $png_path) {
  if (!file_exists($png_path)) {
    return new WP_Error('sunstreaker_trim_png_missing', 'PNG source not found for trim measurement.');
  }

  $binary = sunstreaker_find_binary_command(['/usr/bin/convert', '/usr/bin/magick', 'convert', 'magick']);
  if ($binary === '') {
    return new WP_Error('sunstreaker_trim_convert_missing', 'ImageMagick convert is not available.');
  }

  $command = escapeshellcmd($binary);
  if (preg_match('~/magick$~', $binary)) {
    $command .= ' convert';
  }
  $command .= ' '.escapeshellarg($png_path).' -alpha extract -trim -format '.escapeshellarg('%w %h %X %Y').' info: 2>&1';

  $output = [];
  $code = 0;
  @exec($command, $output, $code);
  if ($code !== 0) {
    return new WP_Error(
      'sunstreaker_trim_measure_failed',
      trim(implode("\n", array_filter(array_map('strval', $output)))) ?: 'Could not measure trimmed PNG bounds.'
    );
  }

  $raw = trim(implode("\n", array_filter(array_map('strval', $output))));
  if (!preg_match('/^(\d+)\s+(\d+)\s+([+\-]?\d+)\s+([+\-]?\d+)$/', $raw, $matches)) {
    return new WP_Error('sunstreaker_trim_measure_parse_failed', 'Could not parse trimmed PNG bounds.');
  }

  return [
    'width' => max(1, (int) $matches[1]),
    'height' => max(1, (int) $matches[2]),
    'x' => (int) $matches[3],
    'y' => (int) $matches[4],
  ];
}

function sunstreaker_apply_svg_trimmed_viewbox(string $svg, array $bounds, float $width_in): string {
  $crop_width = max(1, (int) ($bounds['width'] ?? 0));
  $crop_height = max(1, (int) ($bounds['height'] ?? 0));
  $crop_x = (int) ($bounds['x'] ?? 0);
  $crop_y = (int) ($bounds['y'] ?? 0);
  $width_in = max(0.01, $width_in);
  $height_in = $width_in * ($crop_height / $crop_width);

  $view_box = $crop_x.' '.$crop_y.' '.$crop_width.' '.$crop_height;
  $width_attr = number_format($width_in, 4, '.', '').'in';
  $height_attr = number_format($height_in, 4, '.', '').'in';

  $updated = preg_replace_callback('/<svg\b([^>]*)>/', static function(array $matches) use ($view_box, $width_attr, $height_attr): string {
    $attrs = isset($matches[1]) ? (string) $matches[1] : '';
    $attrs = preg_replace('/\swidth="[^"]*"/i', '', $attrs);
    $attrs = preg_replace('/\sheight="[^"]*"/i', '', $attrs);
    $attrs = preg_replace('/\sviewBox="[^"]*"/i', '', $attrs);
    $attrs .= ' width="'.$width_attr.'"';
    $attrs .= ' height="'.$height_attr.'"';
    $attrs .= ' viewBox="'.sunstreaker_xml_escape($view_box).'"';
    return '<svg'.$attrs.'>';
  }, $svg, 1);

  return is_string($updated) && $updated !== '' ? $updated : $svg;
}

function sunstreaker_local_path_from_url(string $url): string {
  $url = trim($url);
  if ($url === '' || strpos($url, 'data:') === 0) return '';

  $uploads = wp_upload_dir(null, false);
  $baseurl = isset($uploads['baseurl']) ? trailingslashit((string) $uploads['baseurl']) : '';
  $basedir = isset($uploads['basedir']) ? trailingslashit((string) $uploads['basedir']) : '';
  if ($baseurl !== '' && $basedir !== '' && strpos($url, $baseurl) === 0) {
    $relative = ltrim(substr($url, strlen($baseurl)), '/');
    $path = $basedir.$relative;
    if (file_exists($path)) return $path;
  }

  $site_url = trailingslashit((string) site_url('/'));
  if ($site_url !== '' && strpos($url, $site_url) === 0) {
    $path_part = (string) wp_parse_url($url, PHP_URL_PATH);
    $relative = ltrim($path_part, '/');
    if ($relative !== '') {
      $path = trailingslashit(ABSPATH).$relative;
      if (file_exists($path)) return $path;
    }
  }

  return '';
}

function sunstreaker_svg_binary_asset_from_url(string $url) {
  $url = trim($url);
  if ($url === '') {
    return new WP_Error('sunstreaker_asset_url_missing', 'Missing asset URL.');
  }

  $local_path = sunstreaker_local_path_from_url($url);
  if ($local_path !== '') {
    $contents = @file_get_contents($local_path);
    if ($contents !== false) {
      $mime = '';
      if (function_exists('wp_check_filetype')) {
        $mime = (string) (wp_check_filetype($local_path)['type'] ?? '');
      }
      if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string) @mime_content_type($local_path);
      }
      if ($mime === '') $mime = 'application/octet-stream';
      return ['body' => $contents, 'mime' => $mime];
    }
  }

  $response = wp_remote_get($url, ['timeout' => 20]);
  if (is_wp_error($response)) {
    return $response;
  }

  $code = (int) wp_remote_retrieve_response_code($response);
  if ($code !== 200) {
    return new WP_Error('sunstreaker_asset_http_error', 'HTTP '.$code.' while fetching asset.');
  }

  $body = (string) wp_remote_retrieve_body($response);
  if ($body === '') {
    return new WP_Error('sunstreaker_asset_empty', 'Asset response was empty.');
  }

  $mime = wp_remote_retrieve_header($response, 'content-type');
  if (is_array($mime)) $mime = reset($mime);
  $mime = is_string($mime) ? trim($mime) : '';
  if ($mime === '') $mime = 'application/octet-stream';

  return ['body' => $body, 'mime' => $mime];
}

function sunstreaker_svg_data_uri_for_url(string $url): string {
  if (strpos($url, 'data:') === 0) return $url;

  $asset = sunstreaker_svg_binary_asset_from_url($url);
  if (is_wp_error($asset)) return '';

  $body = isset($asset['body']) ? (string) $asset['body'] : '';
  $mime = isset($asset['mime']) ? trim((string) $asset['mime']) : 'application/octet-stream';
  if ($body === '') return '';

  return 'data:'.$mime.';base64,'.base64_encode($body);
}

function sunstreaker_inline_svg_image_assets(string $svg): string {
  if (strpos($svg, '<image') === false || strpos($svg, 'href=') === false) return $svg;

  $updated = preg_replace_callback('/\bhref="([^"]+)"/', function(array $matches): string {
    $raw_url = html_entity_decode((string) ($matches[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $data_uri = sunstreaker_svg_data_uri_for_url($raw_url);
    if ($data_uri === '') return $matches[0];
    return 'href="'.esc_attr($data_uri).'"';
  }, $svg);

  return is_string($updated) && $updated !== '' ? $updated : $svg;
}

function sunstreaker_set_svg_intrinsic_size(string $svg, int $width, int $height): string {
  $width = max(1, $width);
  $height = max(1, $height);

  $updated = preg_replace_callback('/<svg\b([^>]*)>/', function(array $matches) use ($width, $height): string {
    $attrs = isset($matches[1]) ? (string) $matches[1] : '';
    if (stripos($attrs, ' width=') === false) {
      $attrs .= ' width="'.(int) $width.'"';
    }
    if (stripos($attrs, ' height=') === false) {
      $attrs .= ' height="'.(int) $height.'"';
    }
    return '<svg'.$attrs.'>';
  }, $svg, 1);

  return is_string($updated) && $updated !== '' ? $updated : $svg;
}

function sunstreaker_insert_generated_attachment(
  string $file_path,
  string $title,
  int $parent_post_id,
  string $mime_type,
  bool $generate_metadata
) {
  if (!file_exists($file_path)) {
    return new WP_Error('sunstreaker_original_art_missing_file', 'Generated art file not found: '.$file_path);
  }

  $attachment = [
    'post_mime_type' => $mime_type,
    'post_title' => $title,
    'post_content' => '',
    'post_status' => 'inherit',
    'post_parent' => $parent_post_id,
  ];

  $attachment_id = wp_insert_attachment($attachment, $file_path, $parent_post_id, true);
  if (is_wp_error($attachment_id)) return $attachment_id;

  if ($generate_metadata) {
    if (!function_exists('wp_generate_attachment_metadata')) {
      require_once ABSPATH.'wp-admin/includes/image.php';
    }
    $metadata = wp_generate_attachment_metadata((int) $attachment_id, $file_path);
    if (!is_wp_error($metadata) && is_array($metadata)) {
      wp_update_attachment_metadata((int) $attachment_id, $metadata);
    }
  }

  return (int) $attachment_id;
}

function sunstreaker_decode_order_item_json_array(string $raw): array {
  $raw = trim($raw);
  if ($raw === '') return [];
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

function sunstreaker_get_order_item_mockup_args($item, $product, int $settings_product_id): array {
  $preview_reference = function_exists('sunstreaker_get_product_preview_reference')
    ? sunstreaker_get_product_preview_reference($product, $settings_product_id)
    : ['width' => 1200, 'height' => 1200, 'aspect_ratio' => 1];

  $preview_image_url = trim((string) $item->get_meta('_sunstreaker_preview_image_url', true));
  if ($preview_image_url === '' && function_exists('sunstreaker_get_product_preview_image_id')) {
    $image_id = (int) sunstreaker_get_product_preview_image_id($product, $settings_product_id);
    if ($image_id > 0) {
      $preview_image_url = (string) wp_get_attachment_image_url($image_id, 'full');
      if ($preview_image_url === '') {
        $preview_image_url = (string) wp_get_attachment_url($image_id);
      }
    }
  }

  $font_choice = trim((string) $item->get_meta('_sunstreaker_font_choice', true));
  $right_chest_font_choice = trim((string) $item->get_meta('_sunstreaker_right_chest_font_choice', true));
  $default_font_choice = function_exists('sunstreaker_get_name_number_font_choice_key') ? sunstreaker_get_name_number_font_choice_key($settings_product_id) : 'varsity_block';
  $default_right_chest_font_choice = function_exists('sunstreaker_get_right_chest_font_choice_key') ? sunstreaker_get_right_chest_font_choice_key($settings_product_id) : 'montserrat';

  $logo_urls = [];
  foreach (sunstreaker_decode_order_item_json_array((string) $item->get_meta('_sunstreaker_logo_urls', true)) as $logo_url) {
    $logo_url = esc_url_raw((string) $logo_url);
    if ($logo_url !== '') $logo_urls[] = $logo_url;
  }
  if (empty($logo_urls)) {
    $single_logo_url = esc_url_raw((string) $item->get_meta('_sunstreaker_logo_url', true));
    if ($single_logo_url !== '') $logo_urls[] = $single_logo_url;
  }

  return [
    'image_url' => $preview_image_url,
    'reference' => [
      'width' => max(1, (int) $item->get_meta('_sunstreaker_preview_image_width', true) ?: (int) ($preview_reference['width'] ?? 1200)),
      'height' => max(1, (int) $item->get_meta('_sunstreaker_preview_image_height', true) ?: (int) ($preview_reference['height'] ?? 1200)),
    ],
    'boundaries' => function_exists('sunstreaker_get_preview_boundaries')
      ? sunstreaker_get_preview_boundaries($settings_product_id)
      : (function_exists('sunstreaker_default_preview_boundaries') ? sunstreaker_default_preview_boundaries() : []),
    'ink_color' => trim((string) $item->get_meta('_sunstreaker_ink_color', true)) ?: sunstreaker_get_order_item_ink_color($item, $settings_product_id),
    'font_stack' => function_exists('sunstreaker_get_font_stack_from_choice_key')
      ? sunstreaker_get_font_stack_from_choice_key($font_choice, $default_font_choice)
      : (function_exists('sunstreaker_get_font_stack') ? sunstreaker_get_font_stack($settings_product_id) : '"Varsity Block","Freshman","College","Oswald","Arial Black",sans-serif'),
    'right_chest_font_stack' => function_exists('sunstreaker_get_font_stack_from_choice_key')
      ? sunstreaker_get_font_stack_from_choice_key($right_chest_font_choice, $default_right_chest_font_choice)
      : (function_exists('sunstreaker_get_right_chest_font_stack') ? sunstreaker_get_right_chest_font_stack($settings_product_id) : '"Montserrat","Helvetica Neue",Helvetica,Arial,sans-serif'),
    'right_chest_font_choice' => sanitize_key($right_chest_font_choice !== '' ? $right_chest_font_choice : $default_right_chest_font_choice),
    'name' => trim((string) $item->get_meta('Name', true)),
    'number' => trim((string) $item->get_meta('Number', true)),
    'right_chest_name_credentials' => trim((string) $item->get_meta('Right Chest Name/Credentials', true)),
    'right_chest_department' => trim((string) $item->get_meta('Right Chest Department', true)),
    'logo_urls' => array_values(array_unique(array_filter($logo_urls))),
    'front_art_url' => esc_url_raw((string) $item->get_meta('_sunstreaker_front_art_url', true)),
    'front_transform' => function_exists('sunstreaker_sanitize_front_back_transform')
      ? sunstreaker_sanitize_front_back_transform((string) $item->get_meta('_sunstreaker_front_transform', true))
      : [],
    'back_art_url' => esc_url_raw((string) $item->get_meta('_sunstreaker_back_art_url', true)),
    'back_transform' => function_exists('sunstreaker_sanitize_front_back_transform')
      ? sunstreaker_sanitize_front_back_transform((string) $item->get_meta('_sunstreaker_back_transform', true))
      : [],
    'svg_class' => '',
    'aria_hidden' => true,
  ];
}

function sunstreaker_item_has_mockup_data(array $args): bool {
  if (!empty($args['front_art_url']) || !empty($args['back_art_url'])) {
    return true;
  }

  return function_exists('sunstreaker_has_renderable_preview_data')
    ? sunstreaker_has_renderable_preview_data($args)
    : false;
}

function sunstreaker_generate_mockup_assets_for_order($order_id): void {
  if (!function_exists('wc_get_order')) return;
  $order = wc_get_order($order_id);
  if (!$order || !is_a($order, 'WC_Order')) return;

  foreach ($order->get_items('line_item') as $item_id => $item) {
    if (!$item || !is_a($item, 'WC_Order_Item_Product')) continue;
    $existing_svg_url = trim((string) $item->get_meta('_sunstreaker_mockup_svg_url', true));
    $existing_png_url = trim((string) $item->get_meta('_sunstreaker_mockup_png_url', true));
    if ($item->get_meta('_sunstreaker_mockup_generated', true) === 'yes' && ($existing_svg_url !== '' || $existing_png_url !== '')) {
      sunstreaker_sync_generated_mockup_meta($item, sunstreaker_preferred_mockup_display_url($existing_svg_url, $existing_png_url));
      $item->save();
      continue;
    }

    $product = $item->get_product();
    $product_id = $product ? (int) $product->get_id() : (int) $item->get_product_id();
    $settings_product_id = function_exists('sunstreaker_get_settings_product_id')
      ? sunstreaker_get_settings_product_id($product_id)
      : $product_id;
    $args = sunstreaker_get_order_item_mockup_args($item, $product, $settings_product_id);
    if (!sunstreaker_item_has_mockup_data($args)) continue;

    $result = sunstreaker_generate_mockup_asset_for_item($order, (int) $item_id, $item, $args);
    if (is_wp_error($result)) {
      $item->update_meta_data('_sunstreaker_mockup_error', $result->get_error_message());
      $item->save();
      continue;
    }

    $item->update_meta_data('_sunstreaker_mockup_generated', 'yes');
    $item->update_meta_data('_sunstreaker_mockup_generated_at', current_time('mysql'));
    $item->update_meta_data('_sunstreaker_mockup_svg_id', (int) ($result['svg_id'] ?? 0));
    $item->update_meta_data('_sunstreaker_mockup_png_id', (int) ($result['png_id'] ?? 0));
    if (!empty($result['svg_url'])) $item->update_meta_data('_sunstreaker_mockup_svg_url', esc_url_raw($result['svg_url']));
    if (!empty($result['png_url'])) {
      $item->update_meta_data('_sunstreaker_mockup_png_url', esc_url_raw($result['png_url']));
    } else {
      $item->delete_meta_data('_sunstreaker_mockup_png_url');
    }
    sunstreaker_sync_generated_mockup_meta(
      $item,
      sunstreaker_preferred_mockup_display_url((string) ($result['svg_url'] ?? ''), (string) ($result['png_url'] ?? ''))
    );
    $item->save();
  }
}

function sunstreaker_generate_mockup_asset_for_item($order, int $item_id, $item, array $args) {
  $width = max(1, (int) ($args['reference']['width'] ?? 1200));
  $height = max(1, (int) ($args['reference']['height'] ?? 1200));
  $svg = function_exists('sunstreaker_render_front_back_composite_svg')
    ? sunstreaker_render_front_back_composite_svg($args)
    : (function_exists('sunstreaker_render_composite_svg') ? sunstreaker_render_composite_svg($args) : '');
  if ($svg === '') {
    return new WP_Error('sunstreaker_mockup_empty', 'Mockup SVG could not be generated.');
  }

  $svg = sunstreaker_embed_font_faces_in_svg($svg, [
    (string) ($args['font_stack'] ?? ''),
    (string) ($args['right_chest_font_stack'] ?? ''),
  ]);
  $svg = sunstreaker_set_svg_intrinsic_size($svg, $width, $height);
  $svg = sunstreaker_inline_svg_image_assets($svg);

  $paths = sunstreaker_prepare_mockup_paths((int) $order->get_id(), $item_id);
  if (is_wp_error($paths)) return $paths;

  $svg_written = @file_put_contents($paths['svg_path'], $svg);
  if ($svg_written === false) {
    return new WP_Error('sunstreaker_mockup_write_svg_failed', 'Could not write mockup SVG file.');
  }

  $png_result = sunstreaker_convert_svg_to_png($paths['svg_path'], $paths['png_path']);
  if (is_wp_error($png_result)) return $png_result;

  $order_title = function_exists('sanitize_text_field') ? sanitize_text_field($item->get_name()) : $item->get_name();
  $svg_title = 'Mockup SVG - Order '.$order->get_id().' - '.$order_title;
  $png_title = 'Mockup PNG - Order '.$order->get_id().' - '.$order_title;

  $svg_id = sunstreaker_insert_generated_attachment($paths['svg_path'], $svg_title, (int) $order->get_id(), 'image/svg+xml', false);
  if (is_wp_error($svg_id)) return $svg_id;
  $png_id = sunstreaker_insert_generated_attachment($paths['png_path'], $png_title, (int) $order->get_id(), 'image/png', false);
  if (is_wp_error($png_id)) return $png_id;

  return [
    'svg_id' => (int) $svg_id,
    'svg_url' => ($svg_url = (string) wp_get_attachment_url((int) $svg_id)) !== '' ? $svg_url : sunstreaker_upload_url_from_path($paths['svg_path']),
    'png_id' => (int) $png_id,
    'png_url' => ($png_url = (string) wp_get_attachment_url((int) $png_id)) !== '' ? $png_url : sunstreaker_upload_url_from_path($paths['png_path']),
  ];
}

function sunstreaker_render_original_art_svg(array $args) {
  $name = trim((string) ($args['name'] ?? ''));
  $number = trim((string) ($args['number'] ?? ''));
  if ($name === '' || $number === '') {
    return new WP_Error('sunstreaker_original_art_missing_text', 'Name and Number are required for art generation.');
  }

  $dpi = (int) ($args['dpi'] ?? 600);
  if ($dpi <= 0) $dpi = 600;
  $design_width_in = (float) ($args['design_width_in'] ?? 10.0);
  if ($design_width_in <= 0) $design_width_in = 10.0;

  $font_family = sunstreaker_sanitize_font_family((string) ($args['font_family'] ?? 'Varsity Block'));
  if ($font_family === '') $font_family = 'Varsity Block';
  $font_stack = '';
  if (function_exists('sunstreaker_get_font_stack_from_family')) {
    $font_stack = sunstreaker_get_font_stack_from_family($font_family);
  }
  $raw_font_stack = isset($args['font_stack']) ? (string) $args['font_stack'] : '';
  if ($raw_font_stack !== '') {
    $font_stack = sunstreaker_sanitize_font_stack($raw_font_stack);
  }
  if ($font_stack === '') {
    $font_stack = "\"".$font_family."\",sans-serif";
  }

  $ink_color = sunstreaker_normalize_ink_color((string) ($args['ink_color'] ?? 'White'));
  $defaults = function_exists('sunstreaker_default_preview_boundaries')
    ? sunstreaker_default_preview_boundaries()
    : [
      'name' => ['x' => 0.22, 'y' => 0.26, 'w' => 0.56, 'h' => 0.12],
      'number' => ['x' => 0.30, 'y' => 0.41, 'w' => 0.40, 'h' => 0.24],
    ];
  $boundaries = isset($args['boundaries']) && is_array($args['boundaries']) ? $args['boundaries'] : [];
  $name_bounds = sunstreaker_resolve_original_art_boundary($boundaries['name'] ?? null, $defaults['name']);
  $number_bounds = sunstreaker_resolve_original_art_boundary($boundaries['number'] ?? null, $defaults['number']);
  $preview_reference = isset($args['preview_reference']) && is_array($args['preview_reference']) ? $args['preview_reference'] : [];
  $reference_width = max(1.0, (float) ($preview_reference['width'] ?? 1200));
  $reference_height = max(1.0, (float) ($preview_reference['height'] ?? 1200));

  $width_px = max(1, (int) round($design_width_in * $dpi));
  $layers = sunstreaker_build_original_art_text_layers([
    'name' => $name,
    'number' => $number,
    'name_bounds' => $name_bounds,
    'number_bounds' => $number_bounds,
    'reference_width' => $reference_width,
    'reference_height' => $reference_height,
  ]);
  if (empty($layers)) {
    $layers = sunstreaker_extract_original_art_layers_from_mockup_svg(
      (string) ($args['mockup_svg_url'] ?? ''),
      $name,
      $number
    );
  }
  if (empty($layers)) {
    return new WP_Error('sunstreaker_original_art_empty', 'Original art layers could not be generated.');
  }

  $crop = sunstreaker_calculate_original_art_design_crop_from_layers($layers, $reference_width, $reference_height);
  if ($crop['width'] <= 1.0 || $crop['height'] <= 1.0) {
    $crop = sunstreaker_calculate_original_art_design_crop($name_bounds, $number_bounds, $reference_width, $reference_height);
  }
  if ($crop['width'] <= 1.0 || $crop['height'] <= 1.0) {
    $crop = sunstreaker_calculate_original_art_crop($layers, $reference_width, $reference_height);
  }
  $scale = $width_px / max(1.0, (float) $crop['width']);
  $height_px = max(1, (int) round($crop['height'] * $scale));
  $height_in = $height_px / $dpi;

  $svg_font_family = $font_family !== '' ? $font_family : 'Graduate';
  $svg = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
  $svg .= '<svg xmlns="http://www.w3.org/2000/svg" width="'.number_format($design_width_in, 4, '.', '').'in" height="'.number_format($height_in, 4, '.', '').'in" viewBox="0 0 '.$width_px.' '.$height_px.'">'."\n";
  $svg .= '  <title>Original Art Back</title>'."\n";
  $svg .= '  <desc>Generated by Sunstreaker for WooCommerce order personalization.</desc>'."\n";
  $svg .= '  <rect x="0" y="0" width="'.$width_px.'" height="'.$height_px.'" fill="none" />'."\n";
  foreach ($layers as $layer) {
    $layer_fit = $layer['fit'];
    $layer_x = ($layer['center_x'] - $crop['left']) * $scale;
    $layer_y = ($layer['center_y'] - $crop['top']) * $scale;
    $layer_scale_x = (float) ($layer_fit['scale_x'] ?? 1.0) * $scale;
    $layer_scale_y = (float) ($layer_fit['scale_y'] ?? 1.0) * $scale;
    $svg .= '  <g transform="translate('.number_format($layer_x, 4, '.', '').' '.number_format($layer_y, 4, '.', '').') scale('.number_format($layer_scale_x, 6, '.', '').' '.number_format($layer_scale_y, 6, '.', '').')">'."\n";
    $svg .= '    <text id="'.sunstreaker_xml_escape((string) $layer['id']).'" x="0" y="0" text-anchor="middle" dominant-baseline="middle" fill="'.sunstreaker_xml_escape($ink_color).'" font-family="'.sunstreaker_xml_escape($svg_font_family).'" font-size="'.number_format((float) ($layer_fit['font_size'] ?? 100), 4, '.', '').'px" font-weight="'.(int) ($layer['font_weight'] ?? 700).'" letter-spacing="'.number_format((float) ($layer_fit['letter_spacing'] ?? 0), 4, '.', '').'px">'.sunstreaker_xml_escape((string) $layer['text']).'</text>'."\n";
    $svg .= '  </g>'."\n";
  }
  $svg .= '</svg>'."\n";
  $svg = sunstreaker_embed_font_faces_in_svg($svg, [$font_stack]);

  return [
    'svg' => $svg,
    'width_px' => $width_px,
    'height_px' => $height_px,
  ];
}

function sunstreaker_original_art_reference_box(array $rect, float $reference_width, float $reference_height): array {
  if (function_exists('sunstreaker_cart_boundary_box')) {
    return sunstreaker_cart_boundary_box($rect, $reference_width, $reference_height);
  }

  return [
    'x' => ((float) ($rect['x'] ?? 0.0)) * $reference_width,
    'y' => ((float) ($rect['y'] ?? 0.0)) * $reference_height,
    'w' => ((float) ($rect['w'] ?? 0.0)) * $reference_width,
    'h' => ((float) ($rect['h'] ?? 0.0)) * $reference_height,
  ];
}

function sunstreaker_original_art_uppercase(string $text): string {
  if (function_exists('sunstreaker_cart_uppercase')) {
    return sunstreaker_cart_uppercase($text);
  }

  return function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
}

function sunstreaker_original_art_text_metrics(
  string $text,
  array $box,
  float $char_width_ratio,
  float $letter_spacing_ratio,
  float $visible_height_ratio,
  float $visible_width_ratio = 1.0,
  float $visible_box_height_ratio = 1.0
): array {
  $fit = sunstreaker_calculate_svg_text_fit(
    $text,
    $box,
    $char_width_ratio,
    $letter_spacing_ratio,
    $visible_height_ratio,
    $visible_width_ratio,
    $visible_box_height_ratio
  );

  $font_size = (float) ($fit['font_size'] ?? 100);
  $visible_width_ratio = max(1.0, $visible_width_ratio);
  $visible_height_ratio = max(1.0, $visible_height_ratio);
  $visible_box_height_ratio = max(1.0, $visible_box_height_ratio);

  $base_width = max(1.0, (float) sunstreaker_estimate_text_width_with_tracking(
    $text,
    (int) round($font_size),
    $char_width_ratio,
    $letter_spacing_ratio
  ) * $visible_width_ratio * 1.08);
  $base_height = max(1.0, $font_size * $visible_height_ratio * $visible_box_height_ratio * 1.06);

  return [
    'fit' => $fit,
    'width' => $base_width * (float) ($fit['scale_x'] ?? 1.0),
    'height' => $base_height * (float) ($fit['scale_y'] ?? 1.0),
  ];
}

function sunstreaker_original_art_scaled_text_bounds(
  string $text,
  float $center_x,
  float $center_y,
  float $scale_x,
  float $scale_y,
  float $char_width_ratio,
  float $letter_spacing_ratio,
  float $visible_height_ratio,
  float $visible_width_ratio = 1.0,
  float $visible_box_height_ratio = 1.0
): array {
  $font_size = 100.0;
  $visible_width_ratio = max(1.0, $visible_width_ratio);
  $visible_height_ratio = max(1.0, $visible_height_ratio);
  $visible_box_height_ratio = max(1.0, $visible_box_height_ratio);

  $base_width = max(1.0, (float) sunstreaker_estimate_text_width_with_tracking(
    $text,
    (int) round($font_size),
    $char_width_ratio,
    $letter_spacing_ratio
  ) * $visible_width_ratio * 1.08);
  $base_height = max(1.0, $font_size * $visible_height_ratio * $visible_box_height_ratio * 1.06);

  $width = $base_width * $scale_x;
  $height = $base_height * $scale_y;

  return [
    'left' => $center_x - ($width / 2),
    'top' => $center_y - ($height / 2),
    'right' => $center_x + ($width / 2),
    'bottom' => $center_y + ($height / 2),
  ];
}

function sunstreaker_original_art_infer_design_box(
  string $text,
  float $center_x,
  float $center_y,
  float $scale_x,
  float $scale_y,
  float $char_width_ratio,
  float $letter_spacing_ratio,
  float $visible_height_ratio,
  float $visible_width_ratio = 1.0,
  float $visible_box_height_ratio = 1.0
): array {
  $font_size = 100.0;
  $visible_width_ratio = max(0.01, $visible_width_ratio);
  $visible_box_height_ratio = max(0.01, $visible_box_height_ratio);

  $base_width = max(1.0, (float) sunstreaker_estimate_text_width_with_tracking(
    $text,
    (int) round($font_size),
    $char_width_ratio,
    $letter_spacing_ratio
  ) * max(1.0, $visible_width_ratio) * 1.08);
  $base_height = max(1.0, $font_size * max(1.0, $visible_height_ratio) * max(1.0, $visible_box_height_ratio) * 1.06);

  $visible_width = $base_width * $scale_x;
  $visible_height = $base_height * $scale_y;
  $target_width = $visible_width * $visible_width_ratio;
  $target_height = $visible_height * $visible_box_height_ratio;
  $box_width = $target_width / 0.94;
  $box_height = $target_height / 0.94;

  return [
    'x' => $center_x - ($box_width / 2),
    'y' => $center_y - ($box_height / 2),
    'w' => $box_width,
    'h' => $box_height,
  ];
}

function sunstreaker_extract_original_art_layers_from_mockup_svg(string $mockup_svg_url, string $name, string $number): array {
  $mockup_svg_url = trim($mockup_svg_url);
  if ($mockup_svg_url === '') {
    return [];
  }

  $mockup_svg_path = sunstreaker_local_path_from_url($mockup_svg_url);
  if ($mockup_svg_path === '' || !is_readable($mockup_svg_path)) {
    return [];
  }

  $svg = @file_get_contents($mockup_svg_path);
  if ($svg === false || trim($svg) === '') {
    return [];
  }

  $expected_name = sunstreaker_original_art_uppercase(trim((string) preg_replace('/\s+/', ' ', $name)));
  $expected_number = preg_replace('/[^0-9]/', '', $number);
  if ($expected_number === '') {
    $expected_number = trim($number);
  }

  $layers = [];
  $pattern = '~<g\b[^>]*transform="translate\(\s*([-0-9.]+)\s+([-0-9.]+)\s*\)\s*scale\(\s*([-0-9.]+)\s+([-0-9.]+)\s*\)"[^>]*>\s*<text\b[^>]*>(.*?)</text>\s*</g>~is';
  if (!preg_match_all($pattern, $svg, $matches, PREG_SET_ORDER)) {
    return [];
  }

  foreach ($matches as $match) {
    $text = html_entity_decode(trim(strip_tags((string) ($match[5] ?? ''))), ENT_QUOTES | ENT_XML1, 'UTF-8');
    if ($text === '') {
      continue;
    }
    $normalized_upper = sunstreaker_original_art_uppercase(trim((string) preg_replace('/\s+/', ' ', $text)));
    $normalized_number = preg_replace('/[^0-9]/', '', $text);
    if ($normalized_number === '') {
      $normalized_number = trim($text);
    }

    $center_x = (float) ($match[1] ?? 0.0);
    $center_y = (float) ($match[2] ?? 0.0);
    $scale_x = (float) ($match[3] ?? 1.0);
    $scale_y = (float) ($match[4] ?? 1.0);

    if ($expected_name !== '' && $normalized_upper === $expected_name && empty($layers['name'])) {
      $layers['name'] = [
        'id' => 'sunstreaker-name',
        'text' => $normalized_upper,
        'fit' => [
          'font_size' => 100.0,
          'letter_spacing' => 3.0,
          'scale_x' => $scale_x,
          'scale_y' => $scale_y,
        ],
        'font_weight' => 700,
        'center_x' => $center_x,
        'center_y' => $center_y,
        'design_box' => sunstreaker_original_art_infer_design_box($normalized_upper, $center_x, $center_y, $scale_x, $scale_y, 0.56, 0.03, 0.70, 1.0, 1.0),
        'bounds' => sunstreaker_original_art_scaled_text_bounds($normalized_upper, $center_x, $center_y, $scale_x, $scale_y, 0.56, 0.03, 0.70, 1.0, 1.0),
      ];
      continue;
    }

    if ($expected_number !== '' && $normalized_number === $expected_number && empty($layers['number'])) {
      $layers['number'] = [
        'id' => 'sunstreaker-number',
        'text' => $expected_number,
        'fit' => [
          'font_size' => 100.0,
          'letter_spacing' => 2.0,
          'scale_x' => $scale_x,
          'scale_y' => $scale_y,
        ],
        'font_weight' => 700,
        'center_x' => $center_x,
        'center_y' => $center_y,
        'design_box' => sunstreaker_original_art_infer_design_box($expected_number, $center_x, $center_y, $scale_x, $scale_y, 0.60, 0.02, 0.74, 0.90, 0.68),
        'bounds' => sunstreaker_original_art_scaled_text_bounds($expected_number, $center_x, $center_y, $scale_x, $scale_y, 0.60, 0.02, 0.74, 0.90, 0.68),
      ];
    }
  }

  return array_values($layers);
}

function sunstreaker_build_original_art_text_layers(array $args): array {
  $reference_width = max(1.0, (float) ($args['reference_width'] ?? 1200));
  $reference_height = max(1.0, (float) ($args['reference_height'] ?? 1200));
  $name_bounds = isset($args['name_bounds']) && is_array($args['name_bounds']) ? $args['name_bounds'] : [];
  $number_bounds = isset($args['number_bounds']) && is_array($args['number_bounds']) ? $args['number_bounds'] : [];

  $name_text = trim((string) preg_replace('/\s+/', ' ', (string) ($args['name'] ?? '')));
  $number_text = preg_replace('/[^0-9]/', '', (string) ($args['number'] ?? ''));
  if ($number_text === '') {
    $number_text = trim((string) ($args['number'] ?? ''));
  }

  $layers = [];

  if ($name_text !== '') {
    $name_text = sunstreaker_original_art_uppercase($name_text);
    $name_box = sunstreaker_original_art_reference_box($name_bounds, $reference_width, $reference_height);
    $name_metrics = sunstreaker_original_art_text_metrics($name_text, $name_box, 0.56, 0.03, 0.70, 1.0, 1.0);
    $name_center_x = $name_box['x'] + ($name_box['w'] / 2);
    $name_center_y = $name_box['y'] + ($name_box['h'] / 2);
    $layers[] = [
      'id' => 'sunstreaker-name',
      'text' => $name_text,
      'fit' => $name_metrics['fit'],
      'font_weight' => 700,
      'center_x' => $name_center_x,
      'center_y' => $name_center_y,
      'design_box' => $name_box,
      'bounds' => [
        'left' => $name_center_x - ($name_metrics['width'] / 2),
        'top' => $name_center_y - ($name_metrics['height'] / 2),
        'right' => $name_center_x + ($name_metrics['width'] / 2),
        'bottom' => $name_center_y + ($name_metrics['height'] / 2),
      ],
    ];
  }

  if ($number_text !== '') {
    $number_box = sunstreaker_original_art_reference_box($number_bounds, $reference_width, $reference_height);
    $number_metrics = sunstreaker_original_art_text_metrics(
      sunstreaker_number_fit_measurement_text($number_text),
      $number_box,
      0.60,
      0.02,
      0.74,
      0.90,
      0.68
    );
    $number_center_x = $number_box['x'] + ($number_box['w'] / 2);
    $number_center_y = $number_box['y'] + ($number_box['h'] / 2);
    $layers[] = [
      'id' => 'sunstreaker-number',
      'text' => $number_text,
      'fit' => $number_metrics['fit'],
      'font_weight' => 700,
      'center_x' => $number_center_x,
      'center_y' => $number_center_y,
      'design_box' => $number_box,
      'bounds' => [
        'left' => $number_center_x - ($number_metrics['width'] / 2),
        'top' => $number_center_y - ($number_metrics['height'] / 2),
        'right' => $number_center_x + ($number_metrics['width'] / 2),
        'bottom' => $number_center_y + ($number_metrics['height'] / 2),
      ],
    ];
  }

  return $layers;
}

function sunstreaker_calculate_original_art_crop(array $layers, float $reference_width, float $reference_height): array {
  $left = $reference_width;
  $top = $reference_height;
  $right = 0.0;
  $bottom = 0.0;

  foreach ($layers as $layer) {
    $bounds = isset($layer['bounds']) && is_array($layer['bounds']) ? $layer['bounds'] : [];
    $left = min($left, (float) ($bounds['left'] ?? $reference_width));
    $top = min($top, (float) ($bounds['top'] ?? $reference_height));
    $right = max($right, (float) ($bounds['right'] ?? 0.0));
    $bottom = max($bottom, (float) ($bounds['bottom'] ?? 0.0));
  }

  if ($right <= $left || $bottom <= $top) {
    return [
      'left' => 0.0,
      'top' => 0.0,
      'width' => $reference_width,
      'height' => $reference_height,
    ];
  }

  $padding_x = max(12.0, ($right - $left) * 0.035);
  $padding_y = max(12.0, ($bottom - $top) * 0.045);
  $left = max(0.0, $left - $padding_x);
  $top = max(0.0, $top - $padding_y);
  $right = min($reference_width, $right + $padding_x);
  $bottom = min($reference_height, $bottom + $padding_y);

  return [
    'left' => $left,
    'top' => $top,
    'width' => max(1.0, $right - $left),
    'height' => max(1.0, $bottom - $top),
  ];
}

function sunstreaker_calculate_original_art_design_crop(array $name_bounds, array $number_bounds, float $reference_width, float $reference_height): array {
  $boxes = [];

  if (!empty($name_bounds)) {
    $boxes[] = sunstreaker_original_art_reference_box($name_bounds, $reference_width, $reference_height);
  }
  if (!empty($number_bounds)) {
    $boxes[] = sunstreaker_original_art_reference_box($number_bounds, $reference_width, $reference_height);
  }

  if (empty($boxes)) {
    return [
      'left' => 0.0,
      'top' => 0.0,
      'width' => 0.0,
      'height' => 0.0,
    ];
  }

  $left = $reference_width;
  $top = $reference_height;
  $right = 0.0;
  $bottom = 0.0;

  foreach ($boxes as $box) {
    $left = min($left, (float) ($box['x'] ?? $reference_width));
    $top = min($top, (float) ($box['y'] ?? $reference_height));
    $right = max($right, (float) ($box['x'] ?? 0.0) + (float) ($box['w'] ?? 0.0));
    $bottom = max($bottom, (float) ($box['y'] ?? 0.0) + (float) ($box['h'] ?? 0.0));
  }

  return [
    'left' => max(0.0, $left),
    'top' => max(0.0, $top),
    'width' => max(1.0, $right - $left),
    'height' => max(1.0, $bottom - $top),
  ];
}

function sunstreaker_calculate_original_art_design_crop_from_layers(array $layers, float $reference_width, float $reference_height): array {
  $left = $reference_width;
  $top = $reference_height;
  $right = 0.0;
  $bottom = 0.0;
  $found = false;

  foreach ($layers as $layer) {
    $box = isset($layer['design_box']) && is_array($layer['design_box']) ? $layer['design_box'] : [];
    if (empty($box)) {
      continue;
    }
    $found = true;
    $left = min($left, (float) ($box['x'] ?? $reference_width));
    $top = min($top, (float) ($box['y'] ?? $reference_height));
    $right = max($right, (float) ($box['x'] ?? 0.0) + (float) ($box['w'] ?? 0.0));
    $bottom = max($bottom, (float) ($box['y'] ?? 0.0) + (float) ($box['h'] ?? 0.0));
  }

  if (!$found) {
    return [
      'left' => 0.0,
      'top' => 0.0,
      'width' => 0.0,
      'height' => 0.0,
    ];
  }

  return [
    'left' => max(0.0, $left),
    'top' => max(0.0, $top),
    'width' => max(1.0, $right - $left),
    'height' => max(1.0, $bottom - $top),
  ];
}

function sunstreaker_resolve_original_art_boundary($boundary, array $fallback): array {
  if (function_exists('sunstreaker_sanitize_boundary_rect')) {
    return sunstreaker_sanitize_boundary_rect($boundary, $fallback);
  }

  if (!is_array($boundary)) return $fallback;

  $x = isset($boundary['x']) ? (float) $boundary['x'] : (float) $fallback['x'];
  $y = isset($boundary['y']) ? (float) $boundary['y'] : (float) $fallback['y'];
  $w = isset($boundary['w']) ? (float) $boundary['w'] : (float) $fallback['w'];
  $h = isset($boundary['h']) ? (float) $boundary['h'] : (float) $fallback['h'];

  $w = max(0.05, min(1.0, $w));
  $h = max(0.05, min(1.0, $h));
  $x = max(0.0, min(1.0 - $w, $x));
  $y = max(0.0, min(1.0 - $h, $y));

  return [
    'x' => $x,
    'y' => $y,
    'w' => $w,
    'h' => $h,
  ];
}

function sunstreaker_calculate_original_art_layout(
  array $name_bounds,
  array $number_bounds,
  float $reference_width,
  float $reference_height,
  int $canvas_width_px
): array {
  $reference_width = max(1.0, $reference_width);
  $reference_height = max(1.0, $reference_height);
  $canvas_width_px = max(1, $canvas_width_px);

  $left = $reference_width;
  $top = $reference_height;
  $right = 0.0;
  $bottom = 0.0;

  foreach ([$name_bounds, $number_bounds] as $rect) {
    $rect_left = $rect['x'] * $reference_width;
    $rect_top = $rect['y'] * $reference_height;
    $rect_right = ($rect['x'] + $rect['w']) * $reference_width;
    $rect_bottom = ($rect['y'] + $rect['h']) * $reference_height;
    if ($rect_left < $left) $left = $rect_left;
    if ($rect_top < $top) $top = $rect_top;
    if ($rect_right > $right) $right = $rect_right;
    if ($rect_bottom > $bottom) $bottom = $rect_bottom;
  }

  if ($right <= $left || $bottom <= $top) {
    $left = 0.0;
    $top = 0.0;
    $right = $reference_width;
    $bottom = $reference_height;
  }

  $padding_x = max(24.0, ($right - $left) * 0.04);
  $padding_y = max(24.0, ($bottom - $top) * 0.06);
  $left = max(0.0, $left - $padding_x);
  $top = max(0.0, $top - $padding_y);
  $right = min($reference_width, $right + $padding_x);
  $bottom = min($reference_height, $bottom + $padding_y);

  $union_width = max(1.0, $right - $left);
  $union_height = max(1.0, $bottom - $top);
  $scale = $canvas_width_px / $union_width;

  $map_box = static function (array $rect) use ($reference_width, $reference_height, $left, $top, $scale): array {
    return [
      'x' => (($rect['x'] * $reference_width) - $left) * $scale,
      'y' => (($rect['y'] * $reference_height) - $top) * $scale,
      'w' => ($rect['w'] * $reference_width) * $scale,
      'h' => ($rect['h'] * $reference_height) * $scale,
    ];
  };

  return [
    'height_px' => max(1, (int) round($union_height * $scale)),
    'name_box' => $map_box($name_bounds),
    'number_box' => $map_box($number_bounds),
  ];
}

function sunstreaker_fit_font_size_to_box(
  string $text,
  int $start_size,
  int $min_size,
  int $max_width_px,
  int $max_height_px,
  float $char_width_ratio,
  float $letter_spacing_ratio = 0.0,
  float $visible_height_ratio = 1.0
): int {
  $text = trim($text);
  if ($text === '') return max(1, $min_size);

  $max_width_px = max(1, $max_width_px);
  $max_height_px = max(1, $max_height_px);
  $visible_height_ratio = max(0.1, $visible_height_ratio);
  $max_font_size = (int) ceil($max_height_px / $visible_height_ratio);
  $high = min(max(1, $start_size), max($max_height_px, $max_font_size));
  $low = min($high, max(1, $min_size));
  $best = $low;

  while ($low <= $high) {
    $font_size = (int) floor(($low + $high) / 2);
    $estimated_width = sunstreaker_estimate_text_width_with_tracking(
      $text,
      $font_size,
      $char_width_ratio,
      $letter_spacing_ratio
    );
    if (($font_size * $visible_height_ratio) <= $max_height_px && $estimated_width <= $max_width_px) {
      $best = $font_size;
      $low = $font_size + 1;
    } else {
      $high = $font_size - 1;
    }
  }

  if ($best > $max_height_px) $best = $max_height_px;
  if ($best < $min_size) $best = $min_size;
  return $best;
}

function sunstreaker_number_fit_measurement_text(string $text): string {
  $text = trim($text);
  if ($text === '') {
    return '';
  }

  $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
  if ($length === 1) {
    return $text . $text;
  }

  return $text;
}

function sunstreaker_calculate_svg_text_fit(
  string $text,
  array $box,
  float $char_width_ratio,
  float $letter_spacing_ratio,
  float $visible_height_ratio,
  float $visible_width_ratio = 1.0,
  float $visible_box_height_ratio = 1.0
): array {
  $base_font_size = 100;
  $target_width = max(1.0, (float) ($box['w'] ?? 0) * 0.94);
  $target_height = max(1.0, (float) ($box['h'] ?? 0) * 0.94);
  $visible_width_ratio = max(1.0, $visible_width_ratio);
  $visible_height_ratio = max(1.0, $visible_height_ratio);
  $visible_box_height_ratio = max(1.0, $visible_box_height_ratio);
  $base_width = max(1.0, (float) sunstreaker_estimate_text_width_with_tracking(
    $text,
    $base_font_size,
    $char_width_ratio,
    $letter_spacing_ratio
  ) * $visible_width_ratio * 1.08);
  $base_height = max(1.0, $base_font_size * $visible_height_ratio * $visible_box_height_ratio * 1.06);

  return [
    'font_size' => $base_font_size,
    'letter_spacing' => $base_font_size * max(0.0, $letter_spacing_ratio),
    'scale_x' => $target_width / ($base_width * $visible_width_ratio),
    'scale_y' => $target_height / ($base_height * $visible_box_height_ratio),
  ];
}

function sunstreaker_fit_font_size(string $text, int $start_size, int $min_size, int $max_width_px, float $char_width_ratio): int {
  $text = trim($text);
  if ($text === '') return $min_size;

  $font_size = $start_size;
  $min_size = max(1, $min_size);
  $max_width_px = max(1, $max_width_px);

  while ($font_size > $min_size) {
    $estimated = sunstreaker_estimate_text_width($text, $font_size, $char_width_ratio);
    if ($estimated <= $max_width_px) break;
    $font_size -= 4;
  }

  if ($font_size < $min_size) $font_size = $min_size;
  return $font_size;
}

function sunstreaker_estimate_text_width(string $text, int $font_size, float $char_width_ratio): int {
  $len = max(1, function_exists('mb_strlen') ? mb_strlen($text) : strlen($text));
  $spaces = substr_count($text, ' ');
  $effective_chars = max(1.0, $len - ($spaces * 0.45));
  return (int) round($effective_chars * $font_size * $char_width_ratio);
}

function sunstreaker_estimate_text_width_with_tracking(
  string $text,
  int $font_size,
  float $char_width_ratio,
  float $letter_spacing_ratio
): int {
  $base_width = sunstreaker_estimate_text_width($text, $font_size, $char_width_ratio);
  $len = max(1, function_exists('mb_strlen') ? mb_strlen($text) : strlen($text));
  $tracking_chars = max(0, $len - 1);
  $tracking_width = $tracking_chars * $font_size * max(0.0, $letter_spacing_ratio);
  return (int) round($base_width + $tracking_width);
}

function sunstreaker_get_order_item_size_label($item): string {
  $keys = ['_sunstreaker_size', 'Size', 'size', 'pa_size', 'attribute_pa_size', 'attribute_size'];
  foreach ($keys as $key) {
    $val = trim((string) $item->get_meta($key, true));
    if ($val !== '') return $val;
  }

  foreach ($item->get_meta_data() as $meta) {
    $key = isset($meta->key) ? (string) $meta->key : '';
    if ($key === '' || stripos($key, 'size') === false) continue;
    $val = isset($meta->value) ? trim((string) $meta->value) : '';
    if ($val !== '') return $val;
  }

  $product = $item->get_product();
  if ($product && is_a($product, 'WC_Product_Variation')) {
    $val = trim((string) $product->get_attribute('pa_size'));
    if ($val !== '') return $val;
    $val = trim((string) $product->get_attribute('size'));
    if ($val !== '') return $val;
  }

  $label = trim((string) $item->get_name());
  if (preg_match('/,\s*([A-Za-z0-9\- ]{1,20})$/', $label, $m)) {
    $guess = trim((string) $m[1]);
    if ($guess !== '') return $guess;
  }

  return '';
}

function sunstreaker_get_order_item_ink_color($item, int $product_id): string {
  $ink = trim((string) $item->get_meta('_sunstreaker_ink_color', true));
  if ($ink !== '') return $ink;
  if (function_exists('sunstreaker_get_ink_color')) {
    return (string) sunstreaker_get_ink_color($product_id);
  }
  return 'White';
}

function sunstreaker_design_profile_for_size(string $size): array {
  $raw = trim($size);
  $normalized = strtolower($raw);
  $normalized = str_replace(["\u{2013}", "\u{2014}"], '-', $normalized);
  $normalized = preg_replace('/\s+/', ' ', $normalized);
  $normalized = trim((string) $normalized);

  $profile = [
    'width_in' => 10.0,
    'placement_in' => 3.25,
    'group' => 'adult',
  ];

  // Infant
  if (preg_match('/\b0\s*-\s*3\b|\b0\s*to\s*3\b|\bnewborn\b|\bnb\b/', $normalized)) return ['width_in'=>4.0, 'placement_in'=>1.0, 'group'=>'infant'];
  if (preg_match('/\b3\s*-\s*6\b|\b3\s*to\s*6\b/', $normalized)) return ['width_in'=>4.0, 'placement_in'=>1.0, 'group'=>'infant'];
  if (preg_match('/\b6\s*-\s*9\b|\b6\s*to\s*9\b/', $normalized)) return ['width_in'=>5.0, 'placement_in'=>1.0, 'group'=>'infant'];
  if (preg_match('/\b12\s*months?\b/', $normalized)) return ['width_in'=>6.0, 'placement_in'=>1.0, 'group'=>'infant'];

  // Toddler
  if (preg_match('/\b18\s*months?\b/', $normalized)) return ['width_in'=>5.0, 'placement_in'=>1.5, 'group'=>'toddler'];
  if (preg_match('/\b2t\b/', $normalized)) return ['width_in'=>5.0, 'placement_in'=>1.5, 'group'=>'toddler'];
  if (preg_match('/\b3t\b/', $normalized)) return ['width_in'=>6.0, 'placement_in'=>1.5, 'group'=>'toddler'];
  if (preg_match('/\b4t\b/', $normalized)) return ['width_in'=>6.0, 'placement_in'=>1.5, 'group'=>'toddler'];

  // Youth
  if (strpos($normalized, 'youth') !== false || preg_match('/\byxs\b|\bys\b|\bym\b|\byl\b|\byxl\b/', $normalized)) {
    if (preg_match('/\bx-?small\b|^xs$|\byxs\b/', $normalized)) return ['width_in'=>6.5, 'placement_in'=>2.25, 'group'=>'youth'];
    if (preg_match('/\bsmall\b|^s$|\bys\b/', $normalized)) return ['width_in'=>7.0, 'placement_in'=>2.25, 'group'=>'youth'];
    if (preg_match('/\bmedium\b|^m$|\bym\b/', $normalized)) return ['width_in'=>7.5, 'placement_in'=>2.25, 'group'=>'youth'];
    if (preg_match('/\blarge\b|^l$|\byl\b/', $normalized)) return ['width_in'=>8.0, 'placement_in'=>2.25, 'group'=>'youth'];
    if (preg_match('/\bx-?large\b|^xl$|\byxl\b/', $normalized)) return ['width_in'=>8.5, 'placement_in'=>2.25, 'group'=>'youth'];
  }

  // Adult
  if (preg_match('/\b4xl\b|\b4x\b/', $normalized)) return ['width_in'=>11.5, 'placement_in'=>3.25, 'group'=>'adult'];
  if (preg_match('/\b3xl\b|\b3x\b/', $normalized)) return ['width_in'=>11.0, 'placement_in'=>3.25, 'group'=>'adult'];
  if (preg_match('/\b2xl\b|\bxxl\b|\b2x\b/', $normalized)) return ['width_in'=>10.5, 'placement_in'=>3.25, 'group'=>'adult'];
  if (preg_match('/\bx-?small\b|^xs$/', $normalized)) return ['width_in'=>8.5, 'placement_in'=>3.25, 'group'=>'adult'];
  if (preg_match('/\bx-?large\b|^xl$/', $normalized)) return ['width_in'=>10.0, 'placement_in'=>3.25, 'group'=>'adult'];
  if (preg_match('/\blarge\b|^l$/', $normalized)) return ['width_in'=>9.5, 'placement_in'=>3.25, 'group'=>'adult'];
  if (preg_match('/\bmedium\b|^m$/', $normalized)) return ['width_in'=>9.25, 'placement_in'=>3.25, 'group'=>'adult'];
  if (preg_match('/\bsmall\b|^s$/', $normalized)) return ['width_in'=>9.0, 'placement_in'=>3.25, 'group'=>'adult'];

  return $profile;
}

function sunstreaker_get_original_art_font_family($product, int $product_id): string {
  $default = 'Varsity Block';
  if ($product_id <= 0) return $default;

  if (function_exists('sunstreaker_get_font_family')) {
    $selected = trim((string) sunstreaker_get_font_family($product_id));
    if ($selected !== '') return $selected;
  }

  $override = trim((string) get_post_meta($product_id, '_sunstreaker_original_art_font_override', true));
  if ($override !== '') return sunstreaker_sanitize_font_family($override);

  $cached = trim((string) get_post_meta($product_id, '_sunstreaker_original_art_font_cached', true));
  if ($cached !== '') return sunstreaker_sanitize_font_family($cached);

  $image_url = sunstreaker_get_product_image_url_for_font_detect($product, $product_id);
  $detected = trim((string) apply_filters('sunstreaker_detect_font_family', '', $product_id, $image_url));
  if ($detected === '') {
    $detected = sunstreaker_detect_font_family_with_bumblebee($product_id, $image_url);
  }

  $font = sunstreaker_sanitize_font_family($detected);
  if ($font === '') $font = $default;

  update_post_meta($product_id, '_sunstreaker_original_art_font_cached', $font);
  return $font;
}

function sunstreaker_get_product_image_url_for_font_detect($product, int $product_id): string {
  $image_id = function_exists('sunstreaker_get_product_preview_image_id')
    ? sunstreaker_get_product_preview_image_id($product, $product_id)
    : 0;
  if ($image_id <= 0) return '';

  $url = wp_get_attachment_image_url($image_id, 'full');
  return $url ? (string) $url : '';
}

function sunstreaker_detect_font_family_with_bumblebee(int $product_id, string $image_url): string {
  if ($image_url === '') return '';
  if (!defined('BEE_OPT_OPENAI_KEY_PRIMARY') || !defined('BEE_OPT_OPENAI_KEY_SECONDARY')) return '';
  if (defined('BEE_OPT_AI_DISABLED') && !empty(get_option(BEE_OPT_AI_DISABLED, ''))) return '';

  $primary = trim((string) get_option(BEE_OPT_OPENAI_KEY_PRIMARY, ''));
  $secondary = trim((string) get_option(BEE_OPT_OPENAI_KEY_SECONDARY, ''));
  $keys = array_values(array_filter([$primary, $secondary]));
  if (!$keys) return '';

  $system = 'You are a font-style classifier for sports apparel artwork. Return strict JSON only.';
  $user = [
    ['type' => 'text', 'text' => 'From this garment-front image, choose the closest jersey text style font family. Return JSON keys: font_family, confidence. Prefer one of: Varsity Block, Freshman, College, Oswald, Impact. If uncertain, return Varsity Block with low confidence.'],
    ['type' => 'image_url', 'image_url' => ['url' => $image_url]],
  ];
  $payload = [
    'model' => 'gpt-4o-mini',
    'response_format' => ['type' => 'json_object'],
    'messages' => [
      ['role' => 'system', 'content' => $system],
      ['role' => 'user', 'content' => $user],
    ],
    'max_tokens' => 120,
    'temperature' => 0.1,
  ];

  foreach ($keys as $key) {
    $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
      'timeout' => 8,
      'headers' => [
        'Authorization' => 'Bearer '.$key,
        'Content-Type' => 'application/json',
      ],
      'body' => wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    if (is_wp_error($res)) continue;

    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) continue;

    $json = json_decode((string) wp_remote_retrieve_body($res), true);
    if (!is_array($json)) continue;

    $content = $json['choices'][0]['message']['content'] ?? '';
    $data = is_array($content) ? $content : json_decode((string) $content, true);
    if (!is_array($data)) continue;

    $confidence = isset($data['confidence']) ? (float) $data['confidence'] : 0.0;
    $font_family = sunstreaker_sanitize_font_family((string) ($data['font_family'] ?? ''));
    if ($font_family === '') continue;
    if ($confidence < 0.45) continue;

    return $font_family;
  }

  return '';
}

function sunstreaker_sanitize_font_family(string $font): string {
  $font = sanitize_text_field($font);
  $font = trim($font);
  if ($font === '') return '';
  return preg_replace('/[^a-zA-Z0-9\-\s]+/', '', $font);
}

function sunstreaker_sanitize_font_stack(string $stack): string {
  $stack = trim($stack);
  if ($stack === '') return '';
  return preg_replace('/[^a-zA-Z0-9\-\s,"\']+/', '', $stack);
}

function sunstreaker_normalize_ink_color(string $color): string {
  $color = trim(sanitize_text_field($color));
  if ($color === '') return 'White';

  if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) return strtoupper($color);

  $named = preg_replace('/[^a-zA-Z0-9\-\s(),.%#]+/', '', $color);
  $named = trim((string) $named);
  if ($named === '') return 'White';
  return $named;
}

function sunstreaker_xml_escape(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function sunstreaker_supported_google_font_queries(): array {
  return [
    'Graduate' => 'family=Graduate',
    'Anton' => 'family=Anton',
    'Teko' => 'family=Teko:wght@400;500;600;700',
    'Source Sans 3' => 'family=Source+Sans+3:wght@400;600;700',
    'Cormorant Garamond' => 'family=Cormorant+Garamond:wght@400;600;700',
    'Great Vibes' => 'family=Great+Vibes',
    'Montserrat' => 'family=Montserrat:wght@400;500;600;700',
    'Averia Serif Libre' => 'family=Averia+Serif+Libre:wght@400;700',
    'Baloo 2' => 'family=Baloo+2:wght@400;600;700',
    'Original Surfer' => 'family=Original+Surfer',
    'Caveat Brush' => 'family=Caveat+Brush',
    'Ravi Prakash' => 'family=Ravi+Prakash',
    'Alex Brush' => 'family=Alex+Brush',
    'Allura' => 'family=Allura',
  ];
}

function sunstreaker_supported_font_binary_sources(): array {
  $birds_local = [];
  if (defined('SUNSTREAKER_PATH')) {
    $fonts_base = rtrim((string) SUNSTREAKER_PATH, '/').'/assets/fonts/';
    $birds_local = [
      ['path' => $fonts_base.'birds-of-paradise.woff2', 'format' => 'woff2', 'mime' => 'font/woff2'],
      ['path' => $fonts_base.'birds-of-paradise.woff', 'format' => 'woff', 'mime' => 'font/woff'],
      ['path' => $fonts_base.'birds-of-paradise.otf', 'format' => 'opentype', 'mime' => 'font/otf'],
      ['path' => $fonts_base.'birds-of-paradise.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
    ];
  }

  return [
    'Graduate' => [
      'remote' => [
        ['url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/graduate/Graduate-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['url' => 'https://github.com/google/fonts/raw/main/ofl/graduate/Graduate-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
      'fallback_local' => [
        ['path' => '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/liberation/LiberationSerif-Bold.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/noto/NotoSerif-Bold.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/gfonts/alegreya/alegreya-v13-latin-700.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
    ],
    'Anton' => [
      'remote' => [
        ['url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/anton/Anton-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['url' => 'https://github.com/google/fonts/raw/main/ofl/anton/Anton-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
      'fallback_local' => [
        ['path' => '/usr/share/fonts/truetype/gfonts/montserrat/montserrat-v14-latin-700.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
    ],
    'Teko' => [
      'remote' => [
        ['url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/teko/Teko%5Bwght%5D.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['url' => 'https://github.com/google/fonts/raw/main/ofl/teko/Teko%5Bwght%5D.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
      'fallback_local' => [
        ['path' => '/usr/share/fonts/truetype/gfonts/montserrat/montserrat-v14-latin-700.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed-Bold.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
    ],
    'Source Sans 3' => [
      'remote' => [
        ['url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/sourcesans3/SourceSans3%5Bwght%5D.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['url' => 'https://github.com/google/fonts/raw/main/ofl/sourcesans3/SourceSans3%5Bwght%5D.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
      'fallback_local' => [
        ['path' => '/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
    ],
    'Cormorant Garamond' => [
      'remote' => [
        ['url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/cormorantgaramond/CormorantGaramond-Bold.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['url' => 'https://github.com/google/fonts/raw/main/ofl/cormorantgaramond/CormorantGaramond-Bold.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
      'fallback_local' => [
        ['path' => '/usr/share/fonts/truetype/gfonts/alegreya/alegreya-v13-latin-700.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/liberation/LiberationSerif-Bold.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
    ],
    'Great Vibes' => [
      'remote' => [
        ['url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/greatvibes/GreatVibes-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['url' => 'https://github.com/google/fonts/raw/main/ofl/greatvibes/GreatVibes-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
      'fallback_local' => [
        ['path' => '/usr/share/fonts/truetype/gfonts/alegreya/alegreya-v13-latin-italic.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/liberation/LiberationSerif-Italic.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Italic.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
    ],
    'Montserrat' => [
      'remote' => [
        ['url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/montserrat/Montserrat%5Bwght%5D.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['url' => 'https://github.com/google/fonts/raw/main/ofl/montserrat/Montserrat%5Bwght%5D.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
      'fallback_local' => [
        ['path' => '/usr/share/fonts/truetype/gfonts/montserrat/montserrat-v14-latin-regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/gfonts/montserrat/montserrat-v14-latin-700.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
    ],
    'Arial' => [
      'remote' => [],
      'fallback_local' => [
        ['path' => '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
    ],
    'Averia Serif Libre' => [
      'remote' => [
        ['url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/averiaseriflibre/AveriaSerifLibre-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['url' => 'https://github.com/google/fonts/raw/main/ofl/averiaseriflibre/AveriaSerifLibre-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
      'fallback_local' => [
        ['path' => '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
    ],
    'Baloo 2' => [
      'remote' => [
        ['url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/baloo2/Baloo2%5Bwght%5D.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['url' => 'https://github.com/google/fonts/raw/main/ofl/baloo2/Baloo2%5Bwght%5D.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
      'fallback_local' => [
        ['path' => '/usr/share/fonts/truetype/gfonts/baloo/baloo-2-v1-latin-regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
    ],
    'Original Surfer' => [
      'remote' => [
        ['url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/originalsurfer/OriginalSurfer-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['url' => 'https://github.com/google/fonts/raw/main/ofl/originalsurfer/OriginalSurfer-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
      'fallback_local' => [
        ['path' => '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
    ],
    'Caveat Brush' => [
      'remote' => [
        ['url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/caveatbrush/CaveatBrush-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['url' => 'https://github.com/google/fonts/raw/main/ofl/caveatbrush/CaveatBrush-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
      'fallback_local' => [
        ['path' => '/usr/share/fonts/truetype/liberation/LiberationSerif-Italic.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Italic.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
    ],
    'Ravi Prakash' => [
      'remote' => [
        ['url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/raviprakash/RaviPrakash-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['url' => 'https://github.com/google/fonts/raw/main/ofl/raviprakash/RaviPrakash-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
      'fallback_local' => [
        ['path' => '/usr/share/fonts/truetype/liberation/LiberationSerif-Italic.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Italic.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
    ],
    'Alex Brush' => [
      'remote' => [
        ['url' => 'https://raw.githubusercontent.com/google/fonts/main/apache/alexbrush/AlexBrush-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['url' => 'https://github.com/google/fonts/raw/main/apache/alexbrush/AlexBrush-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
      'fallback_local' => [
        ['path' => '/usr/share/fonts/truetype/liberation/LiberationSerif-Italic.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Italic.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
    ],
    'Allura' => [
      'remote' => [
        ['url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/allura/Allura-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['url' => 'https://github.com/google/fonts/raw/main/ofl/allura/Allura-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
      'fallback_local' => [
        ['path' => '/usr/share/fonts/truetype/liberation/LiberationSerif-Italic.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
        ['path' => '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Italic.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
      ],
    ],
    'Birds of Paradise' => [
      'local' => $birds_local,
      'remote' => [],
      'fallback_local' => [],
    ],
  ];
}

function sunstreaker_font_file_as_data_uri(string $path, string $mime = 'font/ttf', string $format = 'truetype'): array {
  $path = trim($path);
  if ($path === '' || !is_file($path) || !is_readable($path)) return [];

  $bytes = @file_get_contents($path);
  if ($bytes === false || $bytes === '') return [];

  return [
    'data_uri' => 'data:'.$mime.';base64,'.base64_encode($bytes),
    'format' => $format,
  ];
}

function sunstreaker_fetch_font_face_source_from_remote(string $url, string $mime = 'font/ttf', string $format = 'truetype'): array {
  $url = trim($url);
  if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return [];

  $response = wp_remote_get($url, [
    'timeout' => 20,
    'redirection' => 5,
    'headers' => [
      'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122 Safari/537.36',
    ],
  ]);
  if (!sunstreaker_font_response_is_ok($response)) return [];

  $body = (string) wp_remote_retrieve_body($response);
  if ($body === '') return [];

  return [
    'data_uri' => 'data:'.$mime.';base64,'.base64_encode($body),
    'format' => $format,
  ];
}

function sunstreaker_font_face_source_for_family(string $family): array {
  static $runtime_cache = [];

  $family = trim($family);
  if ($family === '') return [];
  if (isset($runtime_cache[$family])) return $runtime_cache[$family];

  $transient_key = 'sunstreaker_svg_font_face_src_'.md5($family);
  $cached = get_transient($transient_key);
  if (is_array($cached) && !empty($cached['data_uri']) && !empty($cached['format'])) {
    $runtime_cache[$family] = $cached;
    return $cached;
  }

  $sources = sunstreaker_supported_font_binary_sources();
  $definition = $sources[$family] ?? [];
  foreach ((array) ($definition['local'] ?? []) as $candidate) {
    $asset = sunstreaker_font_file_as_data_uri((string) ($candidate['path'] ?? ''), (string) ($candidate['mime'] ?? 'font/ttf'), (string) ($candidate['format'] ?? 'truetype'));
    if (!empty($asset['data_uri']) && !empty($asset['format'])) {
      set_transient($transient_key, $asset, 30 * DAY_IN_SECONDS);
      $runtime_cache[$family] = $asset;
      return $asset;
    }
  }

  foreach ((array) ($definition['remote'] ?? []) as $candidate) {
    $asset = sunstreaker_fetch_font_face_source_from_remote((string) ($candidate['url'] ?? ''), (string) ($candidate['mime'] ?? 'font/ttf'), (string) ($candidate['format'] ?? 'truetype'));
    if (!empty($asset['data_uri']) && !empty($asset['format'])) {
      set_transient($transient_key, $asset, 30 * DAY_IN_SECONDS);
      $runtime_cache[$family] = $asset;
      return $asset;
    }
  }

  foreach ((array) ($definition['fallback_local'] ?? []) as $candidate) {
    $asset = sunstreaker_font_file_as_data_uri((string) ($candidate['path'] ?? ''), (string) ($candidate['mime'] ?? 'font/ttf'), (string) ($candidate['format'] ?? 'truetype'));
    if (!empty($asset['data_uri']) && !empty($asset['format'])) {
      set_transient($transient_key, $asset, 30 * DAY_IN_SECONDS);
      $runtime_cache[$family] = $asset;
      return $asset;
    }
  }

  $runtime_cache[$family] = [];
  return [];
}

function sunstreaker_font_family_candidates_from_stack(string $stack): array {
  $stack = trim($stack);
  if ($stack === '') return [];

  $supported_lookup = [];
  foreach ([sunstreaker_supported_google_font_queries(), sunstreaker_supported_font_binary_sources()] as $supported) {
    foreach (array_keys($supported) as $family) {
      $supported_lookup[strtolower($family)] = $family;
    }
  }

  $families = [];
  foreach (preg_split('/\s*,\s*/', $stack) ?: [] as $part) {
    $family = trim((string) $part, " \t\n\r\0\x0B\"'");
    if ($family === '') continue;

    $lookup = strtolower($family);
    if (!isset($supported_lookup[$lookup])) continue;
    $canonical = $supported_lookup[$lookup];
    if (isset($families[$canonical])) continue;
    $families[$canonical] = $canonical;
  }

  return array_values($families);
}

function sunstreaker_font_response_is_ok($response): bool {
  if (is_wp_error($response)) return false;
  $code = (int) wp_remote_retrieve_response_code($response);
  return $code >= 200 && $code < 300;
}

function sunstreaker_fetch_font_url_as_data_uri(string $url): string {
  static $cache = [];

  $url = trim($url, " \t\n\r\0\x0B\"'");
  if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return '';
  if (isset($cache[$url])) return $cache[$url];

  $response = wp_remote_get($url, [
    'timeout' => 15,
    'redirection' => 3,
    'headers' => [
      'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122 Safari/537.36',
    ],
  ]);
  if (!sunstreaker_font_response_is_ok($response)) {
    $cache[$url] = '';
    return '';
  }

  $body = (string) wp_remote_retrieve_body($response);
  if ($body === '') {
    $cache[$url] = '';
    return '';
  }

  $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
  $content_type = trim(strtok($content_type, ';'));
  if ($content_type === '') {
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    if (preg_match('/\.woff2$/i', $path)) {
      $content_type = 'font/woff2';
    } elseif (preg_match('/\.woff$/i', $path)) {
      $content_type = 'font/woff';
    } elseif (preg_match('/\.ttf$/i', $path)) {
      $content_type = 'font/ttf';
    } elseif (preg_match('/\.otf$/i', $path)) {
      $content_type = 'font/otf';
    } else {
      $content_type = 'application/octet-stream';
    }
  }

  $cache[$url] = 'data:'.$content_type.';base64,'.base64_encode($body);
  return $cache[$url];
}

function sunstreaker_google_font_css_for_family(string $family): string {
  static $runtime_cache = [];

  $family = trim($family);
  if ($family === '') return '';
  if (isset($runtime_cache[$family])) return $runtime_cache[$family];

  $transient_key = 'sunstreaker_svg_font_css_v2_'.md5($family);
  $cached = get_transient($transient_key);
  if (is_string($cached) && $cached !== '') {
    $runtime_cache[$family] = $cached;
    return $cached;
  }

  $font_face = sunstreaker_font_face_source_for_family($family);
  if (!empty($font_face['data_uri']) && !empty($font_face['format'])) {
    $quoted_family = str_replace("'", "\\'", $family);
    $quoted_format = str_replace("'", "\\'", (string) $font_face['format']);
    $css = "@font-face {\n";
    $css .= "  font-family: '".$quoted_family."';\n";
    $css .= "  font-style: normal;\n";
    $css .= "  font-weight: 400;\n";
    $css .= "  font-display: swap;\n";
    $css .= '  src: url("'.$font_face['data_uri'].'") format(\''.$quoted_format."');\n";
    $css .= "}\n";
    $css .= "@font-face {\n";
    $css .= "  font-family: '".$quoted_family."';\n";
    $css .= "  font-style: normal;\n";
    $css .= "  font-weight: 700;\n";
    $css .= "  font-display: swap;\n";
    $css .= '  src: url("'.$font_face['data_uri'].'") format(\''.$quoted_format."');\n";
    $css .= "}\n";

    set_transient($transient_key, $css, 30 * DAY_IN_SECONDS);
    $runtime_cache[$family] = $css;
    return $css;
  }

  $queries = sunstreaker_supported_google_font_queries();
  if (empty($queries[$family])) {
    $runtime_cache[$family] = '';
    return '';
  }

  $css_url = 'https://fonts.googleapis.com/css2?'.$queries[$family].'&display=swap';
  $response = wp_remote_get($css_url, [
    'timeout' => 15,
    'redirection' => 3,
    'headers' => [
      'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122 Safari/537.36',
    ],
  ]);
  if (!sunstreaker_font_response_is_ok($response)) {
    $runtime_cache[$family] = '';
    return '';
  }

  $css = trim((string) wp_remote_retrieve_body($response));
  if ($css === '') {
    $runtime_cache[$family] = '';
    return '';
  }

  $embedded_css = preg_replace_callback('/url\(([^)]+)\)/i', static function(array $matches): string {
    $url = trim((string) ($matches[1] ?? ''), " \t\n\r\0\x0B\"'");
    if ($url === '') return $matches[0];

    $data_uri = sunstreaker_fetch_font_url_as_data_uri($url);
    if ($data_uri === '') return $matches[0];

    return 'url("'.$data_uri.'")';
  }, $css);

  $embedded_css = is_string($embedded_css) ? trim($embedded_css) : '';
  if ($embedded_css === '') {
    $runtime_cache[$family] = '';
    return '';
  }

  set_transient($transient_key, $embedded_css, 30 * DAY_IN_SECONDS);
  $runtime_cache[$family] = $embedded_css;
  return $embedded_css;
}

function sunstreaker_svg_embedded_font_css(array $font_stacks): string {
  $css_blocks = [];
  $seen = [];

  foreach ($font_stacks as $stack) {
    foreach (sunstreaker_font_family_candidates_from_stack((string) $stack) as $family) {
      if (isset($seen[$family])) continue;
      $seen[$family] = true;

      $css = sunstreaker_google_font_css_for_family($family);
      if ($css !== '') {
        $css_blocks[] = $css;
      }
    }
  }

  return implode("\n", $css_blocks);
}

function sunstreaker_embed_font_faces_in_svg(string $svg, array $font_stacks): string {
  $svg = trim($svg);
  if ($svg === '') return '';

  $font_css = sunstreaker_svg_embedded_font_css($font_stacks);
  if ($font_css === '') return $svg;

  $style_block = "<style><![CDATA[\n".$font_css."\n]]></style>\n";
  $updated = preg_replace('/(<svg\b[^>]*>)/i', '$1'."\n".$style_block, $svg, 1);

  return is_string($updated) && $updated !== '' ? $updated : $svg;
}
