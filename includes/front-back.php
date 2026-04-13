<?php
if (!defined('ABSPATH')) exit;

function sunstreaker_front_back_art_layers(array $source): array {
  $layers = [];

  foreach (['front', 'back'] as $field) {
    $url = isset($source[$field.'_art_url']) ? trim((string) $source[$field.'_art_url']) : '';
    if ($url === '') continue;
    if (function_exists('sunstreaker_is_allowed_front_back_art_url') && !sunstreaker_is_allowed_front_back_art_url($url)) continue;

    $transform = function_exists('sunstreaker_sanitize_front_back_transform')
      ? sunstreaker_sanitize_front_back_transform($source[$field.'_transform'] ?? '')
      : [];

    if (empty($transform)) {
      $transform = ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0];
    }

    $layers[$field] = [
      'url' => esc_url_raw($url),
      'transform' => $transform,
    ];
  }

  return $layers;
}

function sunstreaker_front_back_art_box(array $box, array $transform): array {
  $transform = function_exists('sunstreaker_sanitize_front_back_transform')
    ? sunstreaker_sanitize_front_back_transform($transform)
    : $transform;

  if (empty($transform)) {
    $transform = ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0];
  }

  return [
    'x' => $box['x'] + ($box['w'] * (float) $transform['x']),
    'y' => $box['y'] + ($box['h'] * (float) $transform['y']),
    'w' => $box['w'] * (float) $transform['w'],
    'h' => $box['h'] * (float) $transform['h'],
  ];
}

function sunstreaker_front_back_svg_markup(array $args): string {
  $layers = sunstreaker_front_back_art_layers($args);
  $boundaries = isset($args['boundaries']) && is_array($args['boundaries']) ? $args['boundaries'] : [];
  $reference = isset($args['reference']) && is_array($args['reference']) ? $args['reference'] : [];
  $reference_width = max(1.0, (float) ($reference['width'] ?? 1200));
  $reference_height = max(1.0, (float) ($reference['height'] ?? 1200));
  $markup = '';

  foreach (['back', 'front'] as $field) {
    $clip_id = '';
    $field_box = [];
    $art_box = [];

    if (empty($layers[$field]['url']) || empty($boundaries[$field])) continue;

    $field_box = function_exists('sunstreaker_cart_boundary_box')
      ? sunstreaker_cart_boundary_box($boundaries[$field], $reference_width, $reference_height)
      : [
        'x' => $boundaries[$field]['x'] * $reference_width,
        'y' => $boundaries[$field]['y'] * $reference_height,
        'w' => $boundaries[$field]['w'] * $reference_width,
        'h' => $boundaries[$field]['h'] * $reference_height,
      ];
    $art_box = sunstreaker_front_back_art_box($field_box, $layers[$field]['transform']);
    $clip_id = function_exists('wp_unique_id') ? wp_unique_id('sunstreaker-front-back-clip-') : uniqid('sunstreaker-front-back-clip-');

    $markup .= '<defs><clipPath id="'.esc_attr($clip_id).'">';
    $markup .= '<rect x="'.esc_attr(number_format($field_box['x'], 4, '.', '')).'" y="'.esc_attr(number_format($field_box['y'], 4, '.', '')).'" width="'.esc_attr(number_format($field_box['w'], 4, '.', '')).'" height="'.esc_attr(number_format($field_box['h'], 4, '.', '')).'" />';
    $markup .= '</clipPath></defs>';
    $markup .= '<image href="'.esc_attr($layers[$field]['url']).'" x="'.esc_attr(number_format($art_box['x'], 4, '.', '')).'" y="'.esc_attr(number_format($art_box['y'], 4, '.', '')).'" width="'.esc_attr(number_format(max(1.0, $art_box['w']), 4, '.', '')).'" height="'.esc_attr(number_format(max(1.0, $art_box['h']), 4, '.', '')).'" preserveAspectRatio="xMidYMid meet" clip-path="url(#'.esc_attr($clip_id).')" />';
  }

  return $markup;
}

function sunstreaker_front_back_base_svg(array $args): string {
  $reference = isset($args['reference']) && is_array($args['reference']) ? $args['reference'] : [];
  $reference_width = max(1.0, (float) ($reference['width'] ?? 1200));
  $reference_height = max(1.0, (float) ($reference['height'] ?? 1200));
  $image_url = isset($args['image_url']) ? trim((string) ($args['image_url'] ?? '')) : '';
  $svg_class = trim((string) ($args['svg_class'] ?? ''));
  $image_preserve = trim((string) ($args['image_preserve_aspect_ratio'] ?? 'none'));
  $aria_label = trim((string) ($args['aria_label'] ?? ''));
  $aria_hidden = !empty($args['aria_hidden']);
  $class_attr = $svg_class !== '' ? ' class="'.esc_attr($svg_class).'"' : '';
  $svg = '';

  if ($aria_hidden) {
    $svg = '<svg'.$class_attr.' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.(int) round($reference_width).' '.(int) round($reference_height).'" aria-hidden="true" focusable="false">';
  } else {
    if ($aria_label === '') $aria_label = esc_attr__('Customized product preview', 'sunstreaker');
    $svg = '<svg'.$class_attr.' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.(int) round($reference_width).' '.(int) round($reference_height).'" role="img" aria-label="'.esc_attr($aria_label).'">';
  }

  if ($image_url !== '') {
    $svg .= '<image href="'.esc_attr($image_url).'" x="0" y="0" width="'.esc_attr(number_format($reference_width, 4, '.', '')).'" height="'.esc_attr(number_format($reference_height, 4, '.', '')).'" preserveAspectRatio="'.esc_attr($image_preserve !== '' ? $image_preserve : 'none').'" />';
  }

  $svg .= '</svg>';
  return $svg;
}

function sunstreaker_render_front_back_composite_svg(array $args): string {
  $art_markup = sunstreaker_front_back_svg_markup($args);
  $svg = function_exists('sunstreaker_render_composite_svg') ? sunstreaker_render_composite_svg($args) : '';

  if ($art_markup === '') return $svg;
  if ($svg === '') $svg = sunstreaker_front_back_base_svg($args);
  if ($svg === '') return '';

  if (strpos($svg, '<image ') !== false) {
    $updated = preg_replace('/(<image\b[^>]*\/>)/', '$1'.$art_markup, $svg, 1);
    if (is_string($updated) && $updated !== '') return $updated;
  }

  $updated = preg_replace('/(<svg\b[^>]*>)/', '$1'.$art_markup, $svg, 1);
  return is_string($updated) && $updated !== '' ? $updated : str_replace('</svg>', $art_markup.'</svg>', $svg);
}

function sunstreaker_render_front_back_cart_thumbnail(array $cart_item): string {
  $ss = !empty($cart_item['sunstreaker']) && is_array($cart_item['sunstreaker']) ? $cart_item['sunstreaker'] : [];
  $layers = sunstreaker_front_back_art_layers($ss);
  $product = function_exists('sunstreaker_get_cart_thumbnail_product') ? sunstreaker_get_cart_thumbnail_product($cart_item) : null;
  $settings_product_id = function_exists('sunstreaker_get_settings_product_id')
    ? sunstreaker_get_settings_product_id((int) ($cart_item['variation_id'] ?: $cart_item['product_id']))
    : (int) ($cart_item['variation_id'] ?: $cart_item['product_id']);
  $image_url = function_exists('sunstreaker_get_cart_thumbnail_image_url') ? sunstreaker_get_cart_thumbnail_image_url($cart_item, $product, $settings_product_id) : '';
  $reference = function_exists('sunstreaker_get_cart_thumbnail_reference') ? sunstreaker_get_cart_thumbnail_reference($cart_item, $product, $settings_product_id) : ['width' => 1200, 'height' => 1200, 'aspect_ratio' => 1];
  $boundaries = function_exists('sunstreaker_get_preview_boundaries') ? sunstreaker_get_preview_boundaries($settings_product_id) : [];
  $svg = '';

  if (empty($layers) || empty($boundaries)) return '';

  $svg = sunstreaker_render_front_back_composite_svg([
    'image_url' => $image_url,
    'reference' => $reference,
    'boundaries' => $boundaries,
    'ink_color' => $ss['ink_color'] ?? 'White',
    'font_stack' => $ss['font_stack'] ?? '"Varsity Block","Freshman","College","Oswald","Arial Black",sans-serif',
    'right_chest_font_stack' => $ss['right_chest_font_stack'] ?? ($ss['font_stack'] ?? '"Source Sans 3","Helvetica Neue",Arial,sans-serif'),
    'name' => $ss['name'] ?? '',
    'number' => $ss['number'] ?? '',
    'right_chest_name_credentials' => $ss['right_chest_name_credentials'] ?? '',
    'right_chest_department' => $ss['right_chest_department'] ?? '',
    'logo_urls' => $ss['logo_urls'] ?? [],
    'logo_url' => $ss['logo_url'] ?? '',
    'front_art_url' => $ss['front_art_url'] ?? '',
    'front_transform' => $ss['front_transform'] ?? [],
    'back_art_url' => $ss['back_art_url'] ?? '',
    'back_transform' => $ss['back_transform'] ?? [],
    'svg_class' => 'sunstreaker-cart-thumb__svg',
    'aria_label' => esc_attr__('Customized product preview', 'sunstreaker'),
  ]);

  return $svg !== '' ? '<span class="sunstreaker-cart-thumb">'.$svg.'</span>' : '';
}

function sunstreaker_has_positioned_front_back_submission(int $product_id): bool {
  $has_art = false;

  if ($product_id <= 0 || !function_exists('sunstreaker_uses_front_back') || !sunstreaker_uses_front_back($product_id)) {
    return false;
  }

  foreach (['front', 'back'] as $field) {
    $url = function_exists('sunstreaker_get_posted_front_back_art_url')
      ? sunstreaker_get_posted_front_back_art_url($field)
      : '';
    if ($url === '') continue;

    $has_art = true;
    $transform = function_exists('sunstreaker_get_posted_front_back_transform')
      ? sunstreaker_get_posted_front_back_transform($field)
      : [];
    if (empty($transform)) return false;
  }

  return $has_art;
}

function sunstreaker_is_front_back_position_notice($notice): bool {
  $message = '';

  if (is_array($notice) && isset($notice['notice'])) {
    $message = (string) $notice['notice'];
  } elseif (is_scalar($notice)) {
    $message = (string) $notice;
  }

  $message = strtolower(trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($message))));
  if ($message === '') return false;

  return strpos($message, 'upload and position your artwork') !== false
    || strpos($message, 'position your artwork before adding this product to the cart') !== false;
}

function sunstreaker_restore_wc_notices(array $notices): void {
  if (!function_exists('wc_clear_notices') || !function_exists('wc_add_notice')) return;

  wc_clear_notices();

  foreach ($notices as $type => $entries) {
    if (!is_array($entries)) continue;

    foreach ($entries as $entry) {
      if (is_array($entry)) {
        $message = isset($entry['notice']) ? (string) $entry['notice'] : '';
        $data = !empty($entry['data']) && is_array($entry['data']) ? $entry['data'] : [];
      } else {
        $message = is_scalar($entry) ? (string) $entry : '';
        $data = [];
      }

      if ($message === '') continue;
      wc_add_notice($message, (string) $type, $data);
    }
  }
}

add_filter('woocommerce_add_to_cart_validation', function ($passed, $product_id, $qty) {
  $all_notices = [];
  $error_notices = [];
  $remaining_errors = [];
  $removed_notice = false;

  if ($passed || !function_exists('wc_get_notices')) return $passed;
  if (!sunstreaker_has_positioned_front_back_submission((int) $product_id)) return $passed;

  $all_notices = wc_get_notices();
  $error_notices = !empty($all_notices['error']) && is_array($all_notices['error']) ? $all_notices['error'] : [];
  if (empty($error_notices)) return $passed;

  foreach ($error_notices as $notice) {
    if (sunstreaker_is_front_back_position_notice($notice)) {
      $removed_notice = true;
      continue;
    }
    $remaining_errors[] = $notice;
  }

  if (!$removed_notice) return $passed;

  $all_notices['error'] = $remaining_errors;
  sunstreaker_restore_wc_notices($all_notices);

  return empty($remaining_errors);
}, 9999, 3);

add_filter('woocommerce_add_cart_item_data', function ($cart_item_data, $product_id, $variation_id) {
  $front_art_url = function_exists('sunstreaker_get_posted_front_back_art_url') ? sunstreaker_get_posted_front_back_art_url('front') : '';
  $back_art_url = function_exists('sunstreaker_get_posted_front_back_art_url') ? sunstreaker_get_posted_front_back_art_url('back') : '';
  $front_transform = function_exists('sunstreaker_get_posted_front_back_transform') ? sunstreaker_get_posted_front_back_transform('front') : [];
  $back_transform = function_exists('sunstreaker_get_posted_front_back_transform') ? sunstreaker_get_posted_front_back_transform('back') : [];
  $use_front_back = function_exists('sunstreaker_uses_front_back') ? sunstreaker_uses_front_back($product_id) : false;
  $addon_total = 0.0;
  $front_back_addon = 0.0;

  if (!$use_front_back || ($front_art_url === '' && $back_art_url === '')) return $cart_item_data;

  if (empty($cart_item_data['sunstreaker']) || !is_array($cart_item_data['sunstreaker'])) {
    $cart_item_data['sunstreaker'] = [
      'name' => '',
      'number' => '',
      'right_chest_name_credentials' => '',
      'right_chest_department' => '',
      'font_choice' => function_exists('sunstreaker_get_font_choice_key') ? sunstreaker_get_font_choice_key($product_id) : 'varsity_block',
      'font_stack' => function_exists('sunstreaker_get_font_stack') ? sunstreaker_get_font_stack($product_id) : '"Varsity Block","Freshman","College","Oswald","Arial Black",sans-serif',
      'right_chest_font_choice' => function_exists('sunstreaker_get_right_chest_font_choice_key') ? sunstreaker_get_right_chest_font_choice_key($product_id) : 'clean_sans',
      'right_chest_font_stack' => function_exists('sunstreaker_get_right_chest_font_stack') ? sunstreaker_get_right_chest_font_stack($product_id) : '"Source Sans 3","Helvetica Neue",Arial,sans-serif',
      'ink_color' => function_exists('sunstreaker_get_ink_color') ? sunstreaker_get_ink_color($product_id) : 'White',
      'addon_total' => 0.0,
      'addon' => 0.0,
    ];
  }

  $addon_total = isset($cart_item_data['sunstreaker']['addon_total']) ? (float) $cart_item_data['sunstreaker']['addon_total'] : 0.0;
  $front_back_addon = function_exists('sunstreaker_get_front_back_price') ? sunstreaker_get_front_back_price($product_id) : 0.0;

  if ($front_art_url !== '') {
    $cart_item_data['sunstreaker']['front_art_url'] = $front_art_url;
    $cart_item_data['sunstreaker']['front_transform'] = !empty($front_transform) ? $front_transform : ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0];
  }
  if ($back_art_url !== '') {
    $cart_item_data['sunstreaker']['back_art_url'] = $back_art_url;
    $cart_item_data['sunstreaker']['back_transform'] = !empty($back_transform) ? $back_transform : ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0];
  }

  $cart_item_data['sunstreaker']['front_back_addon'] = $front_back_addon;
  $cart_item_data['sunstreaker']['addon_total'] = $addon_total + $front_back_addon;
  $cart_item_data['sunstreaker']['addon'] = $cart_item_data['sunstreaker']['addon_total'];
  if (!isset($cart_item_data['sunstreaker_base_price'])) {
    $cart_item_data['sunstreaker_base_price'] = function_exists('sunstreaker_get_cart_base_price') ? sunstreaker_get_cart_base_price((int) $product_id, (int) $variation_id) : 0.0;
  }
  if (function_exists('sunstreaker_apply_line_configuration')) {
    $cart_item_data = sunstreaker_apply_line_configuration($cart_item_data, (int) $product_id);
  }
  $cart_item_data['sunstreaker_key'] = md5(($cart_item_data['sunstreaker_key'] ?? '').'|'.$front_art_url.'|'.$back_art_url.'|'.wp_json_encode($front_transform).'|'.wp_json_encode($back_transform).'|'.microtime(true));

  return $cart_item_data;
}, 20, 3);

add_filter('woocommerce_get_item_data', function ($item_data, $cart_item) {
  $ss = !empty($cart_item['sunstreaker']) && is_array($cart_item['sunstreaker']) ? $cart_item['sunstreaker'] : [];

  if (!empty($ss['front_art_url'])) {
    $front_label = '';
    $front_display = function_exists('sunstreaker_render_cart_item_media_html')
      ? sunstreaker_render_cart_item_media_html([[
          'url' => esc_url_raw((string) $ss['front_art_url']),
          'label' => $front_label,
        ]])
      : '';
    $entry = ['key' => 'Front Graphic', 'value' => $front_label];
    if ($front_display !== '') $entry['display'] = $front_display;
    $item_data[] = $entry;
  }
  if (!empty($ss['back_art_url'])) {
    $back_label = '';
    $back_display = function_exists('sunstreaker_render_cart_item_media_html')
      ? sunstreaker_render_cart_item_media_html([[
          'url' => esc_url_raw((string) $ss['back_art_url']),
          'label' => $back_label,
        ]])
      : '';
    $entry = ['key' => 'Back Graphic', 'value' => $back_label];
    if ($back_display !== '') $entry['display'] = $back_display;
    $item_data[] = $entry;
  }

  return $item_data;
}, 20, 2);

add_filter('woocommerce_cart_item_thumbnail', function ($thumbnail, $cart_item, $cart_item_key) {
  $ss = !empty($cart_item['sunstreaker']) && is_array($cart_item['sunstreaker']) ? $cart_item['sunstreaker'] : [];
  $mockup = '';

  if (empty($ss['front_art_url']) && empty($ss['back_art_url'])) return $thumbnail;

  $mockup = sunstreaker_render_front_back_cart_thumbnail($cart_item);
  return $mockup !== '' ? $mockup : $thumbnail;
}, 25, 3);

add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values, $order) {
  $ss = !empty($values['sunstreaker']) && is_array($values['sunstreaker']) ? $values['sunstreaker'] : [];

  if (!empty($ss['front_art_url'])) {
    $front_label = __('Uploaded artwork', 'sunstreaker');
    $item->add_meta_data('Front Graphic', $front_label, true);
    $item->add_meta_data('_sunstreaker_front_art_url', esc_url_raw((string) $ss['front_art_url']), true);
    $item->add_meta_data('_sunstreaker_front_art_label', wc_clean($front_label), true);
  }
  if (!empty($ss['back_art_url'])) {
    $back_label = __('Uploaded artwork', 'sunstreaker');
    $item->add_meta_data('Back Graphic', $back_label, true);
    $item->add_meta_data('_sunstreaker_back_art_url', esc_url_raw((string) $ss['back_art_url']), true);
    $item->add_meta_data('_sunstreaker_back_art_label', wc_clean($back_label), true);
  }
  if (!empty($ss['front_transform'])) {
    $item->add_meta_data('_sunstreaker_front_transform', wp_json_encode($ss['front_transform']), true);
  }
  if (!empty($ss['back_transform'])) {
    $item->add_meta_data('_sunstreaker_back_transform', wp_json_encode($ss['back_transform']), true);
  }
  if (isset($ss['front_back_addon']) && (float) $ss['front_back_addon'] > 0) {
    $item->add_meta_data('Front/Back Add-on', wc_clean(number_format((float) $ss['front_back_addon'], 2, '.', '')), true);
  }
}, 20, 4);
