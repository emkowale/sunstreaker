<?php
if (!defined('ABSPATH')) exit;

function sunstreaker_get_cart_base_price(int $product_id, int $variation_id): float {
  if (!function_exists('wc_get_product')) return 0.0;
  $target_id = $variation_id > 0 ? $variation_id : $product_id;
  if ($target_id <= 0) return 0.0;
  $p = wc_get_product($target_id);
  if (!$p || !is_a($p, 'WC_Product')) return 0.0;
  return (float) $p->get_price('edit');
}

function sunstreaker_get_checkout_size_from_values(array $values): string {
  if (!empty($values['variation']) && is_array($values['variation'])) {
    foreach ($values['variation'] as $attr_key => $attr_val) {
      $key = is_string($attr_key) ? $attr_key : '';
      if ($key === '' || stripos($key, 'size') === false) continue;
      $val = is_scalar($attr_val) ? trim((string)$attr_val) : '';
      if ($val === '') continue;

      // Variation values can be term slugs; convert to term name when possible.
      if (strpos($key, 'attribute_pa_') === 0) {
        $tax = substr($key, strlen('attribute_'));
        if ($tax !== '' && taxonomy_exists($tax)) {
          $term = get_term_by('slug', $val, $tax);
          if ($term && !is_wp_error($term) && !empty($term->name)) {
            return (string) $term->name;
          }
        }
      }
      return $val;
    }
  }

  $variation_id = isset($values['variation_id']) ? (int) $values['variation_id'] : 0;
  if ($variation_id > 0 && function_exists('wc_get_product')) {
    $variation = wc_get_product($variation_id);
    if ($variation && is_a($variation, 'WC_Product_Variation')) {
      $val = trim((string) $variation->get_attribute('pa_size'));
      if ($val !== '') return $val;
      $val = trim((string) $variation->get_attribute('size'));
      if ($val !== '') return $val;
    }
  }

  return '';
}

function sunstreaker_cart_addon_total(array $ss): float {
  $total = 0.0;
  foreach (['name_number_addon', 'logo_addon', 'right_chest_addon', 'addon_total', 'addon'] as $key) {
    if (!isset($ss[$key])) continue;
    $value = (float) $ss[$key];
    if ($key === 'addon_total') return max(0.0, $value);
    $total += max(0.0, $value);
  }
  return max(0.0, $total);
}

function sunstreaker_media_label_from_url(string $url, string $fallback = 'Uploaded artwork'): string {
  $url = trim($url);
  if ($url === '') return $fallback;

  $path = parse_url($url, PHP_URL_PATH);
  $label = $path ? wp_basename($path) : wp_basename($url);
  $label = rawurldecode((string) $label);
  $label = sanitize_text_field($label);

  return $label !== '' ? $label : $fallback;
}

function sunstreaker_prepare_media_entries(array $urls, array $labels = [], string $fallback = 'Uploaded artwork'): array {
  $entries = [];
  $normalized_labels = [];

  foreach ($labels as $label) {
    if (!is_scalar($label)) continue;
    $label = sanitize_text_field((string) $label);
    if ($label === '') continue;
    $normalized_labels[] = $label;
  }

  foreach ($urls as $index => $url) {
    if (!is_scalar($url)) continue;
    $url = esc_url_raw((string) $url);
    if ($url === '') continue;

    $label = isset($normalized_labels[$index]) ? $normalized_labels[$index] : '';
    if ($label === '') $label = sunstreaker_media_label_from_url($url, $fallback);

    $entries[] = [
      'url' => $url,
      'label' => $label,
    ];
  }

  return $entries;
}

function sunstreaker_render_cart_item_media_html(array $entries): string {
  if (empty($entries)) return '';

  $html = '<span class="sunstreaker-item-media-list">';
  foreach ($entries as $entry) {
    $url = !empty($entry['url']) ? esc_url((string) $entry['url']) : '';
    $label = !empty($entry['label']) ? esc_html((string) $entry['label']) : '';
    if ($url === '') continue;

    $html .= '<span class="sunstreaker-item-media">';
    $html .= '<img class="sunstreaker-item-media__thumb" src="'.$url.'" alt="" loading="lazy" decoding="async" />';
    if ($label !== '') {
      $html .= '<span class="sunstreaker-item-media__label">'.$label.'</span>';
    }
    $html .= '</span>';
  }
  $html .= '</span>';

  return $html;
}

function sunstreaker_decode_string_list($value): array {
  if (is_array($value)) {
    return array_values(array_filter(array_map(static function($entry){
      return is_scalar($entry) ? trim((string) $entry) : '';
    }, $value), static function($entry){
      return $entry !== '';
    }));
  }

  $raw = trim((string) $value);
  if ($raw === '') return [];

  $decoded = json_decode($raw, true);
  if (is_array($decoded)) {
    return array_values(array_filter(array_map(static function($entry){
      return is_scalar($entry) ? trim((string) $entry) : '';
    }, $decoded), static function($entry){
      return $entry !== '';
    }));
  }

  return [$raw];
}

function sunstreaker_get_name_number_font_label(array $ss, int $product_id): string {
  $font_choice_key = isset($ss['font_choice']) ? sanitize_key((string) $ss['font_choice']) : '';
  $fallback = function_exists('sunstreaker_get_name_number_font_choice_key')
    ? sunstreaker_get_name_number_font_choice_key($product_id)
    : 'varsity_block';

  if (!function_exists('sunstreaker_get_font_label_from_choice_key')) return '';
  return trim((string) sunstreaker_get_font_label_from_choice_key($font_choice_key, $fallback));
}

function sunstreaker_get_right_chest_font_label(array $ss, int $product_id): string {
  $font_choice_key = isset($ss['right_chest_font_choice']) ? sanitize_key((string) $ss['right_chest_font_choice']) : '';
  $fallback = function_exists('sunstreaker_get_right_chest_font_choice_key')
    ? sunstreaker_get_right_chest_font_choice_key($product_id)
    : 'montserrat';

  if (!function_exists('sunstreaker_get_font_label_from_choice_key')) return '';
  return trim((string) sunstreaker_get_font_label_from_choice_key($font_choice_key, $fallback));
}

function sunstreaker_get_email_context(): array {
  $context = $GLOBALS['sunstreaker_email_context'] ?? [];
  return is_array($context) ? $context : [];
}

function sunstreaker_get_order_item_meta_value(WC_Order_Item $item, array $keys): string {
  foreach ($keys as $key) {
    $value = $item->get_meta($key, true);
    if (is_scalar($value) && trim((string) $value) !== '') {
      return trim((string) $value);
    }

    foreach ($item->get_meta_data() as $meta_data) {
      $meta = method_exists($meta_data, 'get_data') ? (array) $meta_data->get_data() : [];
      $meta_key = isset($meta['key']) ? (string) $meta['key'] : '';
      $meta_value = $meta['value'] ?? '';
      if ($meta_key === '' || strcasecmp($meta_key, (string) $key) !== 0) continue;
      if (!is_scalar($meta_value) || trim((string) $meta_value) === '') continue;
      return trim((string) $meta_value);
    }
  }

  return '';
}

function sunstreaker_get_order_item_media_rows(WC_Order_Item_Product $item): array {
  $rows = [];

  $logo_urls = sunstreaker_decode_string_list(sunstreaker_get_order_item_meta_value($item, ['_sunstreaker_logo_urls', '_sunstreaker_logo_url']));
  $logo_labels = sunstreaker_decode_string_list(sunstreaker_get_order_item_meta_value($item, ['_sunstreaker_logo_labels']));
  if (empty($logo_labels)) {
    $combined = sunstreaker_get_order_item_meta_value($item, ['Logos', 'Logo']);
    if ($combined !== '') {
      $logo_labels = preg_split('/\s*,\s*/', $combined) ?: [];
    }
  }
  $logo_group = count($logo_urls) > 1 ? 'Logos' : 'Logo';
  foreach (sunstreaker_prepare_media_entries($logo_urls, $logo_labels, $logo_group) as $entry) {
    $rows[] = [
      'group' => $logo_group,
      'label' => $entry['label'],
      'url' => $entry['url'],
    ];
  }

  foreach ([
    ['group' => 'Front Graphic', 'url_keys' => ['_sunstreaker_front_art_url'], 'label_keys' => ['_sunstreaker_front_art_label', 'Front Graphic']],
    ['group' => 'Back Graphic', 'url_keys' => ['_sunstreaker_back_art_url'], 'label_keys' => ['_sunstreaker_back_art_label', 'Back Graphic']],
  ] as $config) {
    $url = sunstreaker_get_order_item_meta_value($item, $config['url_keys']);
    if ($url === '') continue;

    $entries = sunstreaker_prepare_media_entries(
      [$url],
      [sunstreaker_get_order_item_meta_value($item, $config['label_keys'])],
      $config['group']
    );
    if (empty($entries)) continue;

    $rows[] = [
      'group' => $config['group'],
      'label' => $entries[0]['label'],
      'url' => $entries[0]['url'],
    ];
  }

  return $rows;
}

function sunstreaker_render_order_item_media_meta_html(WC_Order_Item_Product $item): string {
  $rows = sunstreaker_get_order_item_media_rows($item);
  if (empty($rows)) return '';

  $html = '<span class="sunstreaker-item-media-list">';
  foreach ($rows as $row) {
    $group = !empty($row['group']) ? esc_html((string) $row['group']) : esc_html__('Graphic', 'sunstreaker');
    $url = !empty($row['url']) ? esc_url((string) $row['url']) : '';
    if ($url === '') continue;

    $html .= '<span class="sunstreaker-item-media">';
    $html .= '<span class="sunstreaker-item-media__group">'.$group.':</span>';
    $html .= '<img class="sunstreaker-item-media__thumb" src="'.$url.'" alt="" loading="lazy" decoding="async" />';
    $html .= '</span>';
  }
  $html .= '</span>';

  return $html;
}

function sunstreaker_render_email_work_order_details(WC_Order_Item_Product $item, bool $plain_text = false): string {
  $rows = [];
  foreach ([
    'Production' => 'Production',
    'Print Location' => 'Print Location',
    'Special Instructions for production' => 'Special Instructions',
  ] as $meta_key => $label) {
    $value = sunstreaker_get_order_item_meta_value($item, [$meta_key]);
    if ($value === '' && $meta_key === 'Special Instructions for production') {
      $value = sunstreaker_get_order_item_meta_value($item, ['Special Instructions']);
    }
    if ($value === '') continue;

    $rows[] = [
      'label' => $label,
      'value' => $value,
    ];
  }

  $media_rows = sunstreaker_get_order_item_media_rows($item);
  if (empty($rows) && empty($media_rows)) return '';

  if ($plain_text) {
    $output = "\nWork Order Details\n";
    foreach ($rows as $row) {
      $output .= sprintf("%s: %s\n", $row['label'], $row['value']);
    }
    foreach ($media_rows as $media_row) {
      $output .= sprintf("%s: %s (%s)\n", $media_row['group'], $media_row['label'], $media_row['url']);
    }
    return $output;
  }

  $html = '<div style="margin-top:8px;padding-top:8px;border-top:1px solid #e5e5e5;">';
  $html .= '<div style="font-size:12px;font-weight:600;margin-bottom:6px;">Work Order Details</div>';

  foreach ($rows as $row) {
    $html .= '<div style="font-size:12px;line-height:1.45;margin:0 0 4px;">';
    $html .= '<strong>'.esc_html((string) $row['label']).':</strong> ';
    $html .= esc_html((string) $row['value']);
    $html .= '</div>';
  }

  if (!empty($media_rows)) {
    $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:8px;border-collapse:collapse;">';
    foreach ($media_rows as $media_row) {
      $url = esc_url((string) ($media_row['url'] ?? ''));
      if ($url === '') continue;

      $html .= '<tr>';
      $html .= '<td style="padding:0 8px 6px 0;width:48px;vertical-align:top;">';
      $html .= '<a href="'.$url.'" target="_blank" rel="noopener noreferrer">';
      $html .= '<img src="'.$url.'" alt="" style="display:block;width:40px;height:40px;border:1px solid #dcdcde;border-radius:4px;object-fit:cover;background:#fff;" />';
      $html .= '</a>';
      $html .= '</td>';
      $html .= '<td style="padding:0 0 6px;vertical-align:middle;">';
      $html .= '<div style="font-size:11px;font-weight:600;line-height:1.2;">'.esc_html((string) ($media_row['group'] ?? 'Graphic')).'</div>';
      $html .= '<div style="font-size:12px;line-height:1.35;word-break:break-word;">'.esc_html((string) ($media_row['label'] ?? 'Uploaded artwork')).'</div>';
      $html .= '</td>';
      $html .= '</tr>';
    }
    $html .= '</table>';
  }

  $html .= '</div>';
  return $html;
}

function sunstreaker_get_product_special_instructions($product_id): string {
  $product_id = function_exists('sunstreaker_get_settings_product_id')
    ? sunstreaker_get_settings_product_id($product_id)
    : (int) $product_id;
  if ($product_id <= 0) return '';

  foreach (['Special Instructions for production', 'Special Instructions'] as $meta_key) {
    $value = get_post_meta($product_id, $meta_key, true);
    $value = is_scalar($value) ? trim((string) $value) : '';
    if ($value !== '') return preg_replace('/\s+/', ' ', $value);
  }

  return '';
}

function sunstreaker_parse_print_locations(string $raw): array {
  $raw = trim($raw);
  if ($raw === '') return [];

  $parts = preg_split('/\s*(?:,|;|\||\/|\band\b|&)\s*/i', $raw);
  if (!is_array($parts) || empty($parts)) {
    $parts = [$raw];
  }

  $out = [];
  $seen = [];
  foreach ($parts as $part) {
    $value = trim((string) $part);
    if ($value === '') continue;

    $normalized = strtolower(preg_replace('/\s+/', ' ', $value));
    if (isset($seen[$normalized])) continue;
    $seen[$normalized] = true;
    $out[] = $value;
  }

  return $out;
}

function sunstreaker_append_print_location(array &$locations, string $location): void {
  $location = trim($location);
  if ($location === '') return;

  $normalized = strtolower(preg_replace('/\s+/', ' ', $location));
  foreach ($locations as $existing) {
    $existing_normalized = strtolower(preg_replace('/\s+/', ' ', trim((string) $existing)));
    if ($existing_normalized === $normalized) return;
  }

  $locations[] = $location;
}

function sunstreaker_get_line_print_locations(int $product_id, array $ss): array {
  $settings_product_id = function_exists('sunstreaker_get_settings_product_id')
    ? sunstreaker_get_settings_product_id($product_id)
    : $product_id;

  $raw = '';
  foreach ([(int) $settings_product_id, (int) $product_id] as $candidate_id) {
    if ($candidate_id <= 0) continue;
    $value = get_post_meta($candidate_id, 'Print Location', true);
    $value = is_scalar($value) ? trim((string) $value) : '';
    if ($value !== '') {
      $raw = $value;
      break;
    }
  }

  $locations = sunstreaker_parse_print_locations($raw);

  if (!empty($ss['name']) || !empty($ss['number']) || !empty($ss['back_art_url'])) {
    sunstreaker_append_print_location($locations, 'Back');
  }
  if (!empty($ss['front_art_url'])) {
    sunstreaker_append_print_location($locations, 'Front');
  }
  if (!empty($ss['right_chest_name_credentials']) || !empty($ss['right_chest_department'])) {
    sunstreaker_append_print_location($locations, 'Right Chest');
  }
  if (!empty($ss['logo_locations']) && is_array($ss['logo_locations'])) {
    foreach ($ss['logo_locations'] as $logo_location) {
      $label = is_array($logo_location) && !empty($logo_location['location_label'])
        ? trim((string) $logo_location['location_label'])
        : '';
      if ($label === '') continue;
      sunstreaker_append_print_location($locations, $label);
    }
  }

  return $locations;
}

function sunstreaker_collect_line_configuration(array $ss, int $product_id): array {
  $lines = [];
  $methods = [];
  $original_art = [];
  $print_locations = sunstreaker_get_line_print_locations($product_id, $ss);
  $scrub_attributes = !empty($ss['scrub_attributes']) && is_array($ss['scrub_attributes'])
    ? $ss['scrub_attributes']
    : [];

  $has_name_number = !empty($ss['name']) || !empty($ss['number']);
  if ($has_name_number) {
    $method = function_exists('sunstreaker_get_feature_production_method')
      ? sunstreaker_get_feature_production_method($product_id, 'name_number')
      : 'DTF';
    $methods[$method] = $method;
    if (!empty($ss['name'])) {
      $lines[] = sprintf('Back Name: %s', trim((string) $ss['name']));
    }
    if (!empty($ss['number'])) {
      $lines[] = sprintf('Back Number: %s', trim((string) $ss['number']));
    }

    $font_label = sunstreaker_get_name_number_font_label($ss, $product_id);
    if ($font_label !== '') {
      $lines[] = sprintf('Back Font: %s', $font_label);
    }
  }

  if (!empty($ss['logo_locations']) && is_array($ss['logo_locations'])) {
    foreach ($ss['logo_locations'] as $logo_location) {
      if (!is_array($logo_location)) continue;
      $logo_label = !empty($logo_location['logo_label']) ? trim((string) $logo_location['logo_label']) : '';
      $location_label = !empty($logo_location['location_label']) ? trim((string) $logo_location['location_label']) : '';
      $method = !empty($logo_location['production'])
        ? trim((string) $logo_location['production'])
        : (function_exists('sunstreaker_get_feature_production_method')
          ? sunstreaker_get_feature_production_method($product_id, 'logos')
          : 'Embroidery');
      if ($logo_label === '') continue;
      if ($method !== '') {
        $methods[$method] = $method;
      }
      $line_label = $location_label !== '' ? 'Logo - '.$location_label : 'Logo';
      $lines[] = sprintf('%s [%s]: %s', $line_label, $method, $logo_label);
    }
  } else {
    $logo_label = isset($ss['logo_label']) ? trim((string) $ss['logo_label']) : '';
    if ($logo_label !== '') {
      $method = function_exists('sunstreaker_get_feature_production_method')
        ? sunstreaker_get_feature_production_method($product_id, 'logos')
        : 'Embroidery';
      $methods[$method] = $method;
      $label = !empty($ss['logo_ids']) && count((array) $ss['logo_ids']) > 1 ? 'Logos' : 'Logo';
      $lines[] = sprintf('%s [%s]: %s', $label, $method, $logo_label);
    }
  }

  $right_chest_parts = [];
  if (!empty($ss['right_chest_name_credentials'])) $right_chest_parts[] = trim((string) $ss['right_chest_name_credentials']);
  if (!empty($ss['right_chest_department'])) $right_chest_parts[] = trim((string) $ss['right_chest_department']);
  if (!empty($right_chest_parts)) {
    $method = function_exists('sunstreaker_get_feature_production_method')
      ? sunstreaker_get_feature_production_method($product_id, 'right_chest')
      : 'Embroidery';
    $methods[$method] = $method;
    $lines[] = sprintf('Right Chest [%s]: %s', $method, implode(' / ', $right_chest_parts));

    $right_chest_font_label = sunstreaker_get_right_chest_font_label($ss, $product_id);
    if ($right_chest_font_label !== '') {
      $lines[] = sprintf('Right Chest Font: %s', $right_chest_font_label);
    }
  }

  if (!empty($scrub_attributes) && function_exists('sunstreaker_scrub_attribute_definitions')) {
    foreach (sunstreaker_scrub_attribute_definitions() as $field_key => $definition) {
      $value = isset($scrub_attributes[$field_key]) ? trim((string) $scrub_attributes[$field_key]) : '';
      if ($value === '') continue;

      $label = isset($definition['label']) ? trim((string) $definition['label']) : '';
      if ($label === '') {
        $label = ucwords(str_replace('_', ' ', (string) $field_key));
      }

      $lines[] = sprintf('%s: %s', $label, $value);
    }
  }

  foreach ([
    'front' => 'Front Art',
    'back' => 'Back Art',
  ] as $field => $label) {
    $url = isset($ss[$field.'_art_url']) ? trim((string) $ss[$field.'_art_url']) : '';
    if ($url === '') continue;

    $method = function_exists('sunstreaker_get_feature_production_method')
      ? sunstreaker_get_feature_production_method($product_id, 'front_back')
      : 'DTF';
    $methods[$method] = $method;
    $art_label = sunstreaker_media_label_from_url($url, __('Uploaded artwork', 'sunstreaker'));
    $lines[] = sprintf('%s [%s]: %s', $label, $method, $art_label);
    $original_art['Original Art '.ucfirst($field)] = $url;
  }

  $product_note = sunstreaker_get_product_special_instructions($product_id);
  if ($product_note !== '' && (!empty($lines) || !empty($original_art))) {
    $lines[] = 'Product Note: '.$product_note;
  }

  return [
    'production' => implode(' + ', array_values($methods)),
    'print_location' => implode(', ', $print_locations),
    'special_instructions' => implode("\n", $lines),
    'original_art' => $original_art,
  ];
}

function sunstreaker_apply_line_configuration(array $cart_item_data, int $product_id): array {
  if (empty($cart_item_data['sunstreaker']) || !is_array($cart_item_data['sunstreaker'])) {
    return $cart_item_data;
  }

  $configuration = sunstreaker_collect_line_configuration($cart_item_data['sunstreaker'], $product_id);
  if ($configuration['production'] !== '') {
    $cart_item_data['sunstreaker']['production'] = $configuration['production'];
  } else {
    unset($cart_item_data['sunstreaker']['production']);
  }

  if ($configuration['print_location'] !== '') {
    $cart_item_data['sunstreaker']['print_location'] = $configuration['print_location'];
  } else {
    unset($cart_item_data['sunstreaker']['print_location']);
  }

  if ($configuration['special_instructions'] !== '') {
    $cart_item_data['sunstreaker']['special_instructions'] = $configuration['special_instructions'];
  } else {
    unset($cart_item_data['sunstreaker']['special_instructions']);
  }

  if (!empty($configuration['original_art'])) {
    $cart_item_data['sunstreaker']['original_art'] = $configuration['original_art'];
  } else {
    unset($cart_item_data['sunstreaker']['original_art']);
  }

  return $cart_item_data;
}

function sunstreaker_get_cart_thumbnail_product(array $cart_item) {
  if (!empty($cart_item['data']) && is_a($cart_item['data'], 'WC_Product')) {
    return $cart_item['data'];
  }

  if (!function_exists('wc_get_product')) return null;

  $variation_id = isset($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0;
  $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
  $target_id = $variation_id > 0 ? $variation_id : $product_id;
  if ($target_id <= 0) return null;

  return wc_get_product($target_id);
}

function sunstreaker_get_cart_thumbnail_image_url(array $cart_item, $product, int $settings_product_id): string {
  $ss = !empty($cart_item['sunstreaker']) && is_array($cart_item['sunstreaker']) ? $cart_item['sunstreaker'] : [];
  $url = isset($ss['preview_image_url']) ? trim((string) $ss['preview_image_url']) : '';
  if ($url !== '') return $url;

  if (function_exists('sunstreaker_get_product_preview_image_id')) {
    $image_id = (int) sunstreaker_get_product_preview_image_id($product, $settings_product_id);
    if ($image_id > 0) {
      $image_url = wp_get_attachment_image_url($image_id, 'full');
      if ($image_url) return (string) $image_url;
    }
  }

  return '';
}

function sunstreaker_get_cart_thumbnail_reference(array $cart_item, $product, int $settings_product_id): array {
  $ss = !empty($cart_item['sunstreaker']) && is_array($cart_item['sunstreaker']) ? $cart_item['sunstreaker'] : [];
  $width = isset($ss['preview_image_width']) ? max(0, (int) $ss['preview_image_width']) : 0;
  $height = isset($ss['preview_image_height']) ? max(0, (int) $ss['preview_image_height']) : 0;

  if ($width > 0 && $height > 0) {
    return [
      'width' => $width,
      'height' => $height,
      'aspect_ratio' => $height / max(1, $width),
    ];
  }

  if (function_exists('sunstreaker_get_product_preview_reference')) {
    return sunstreaker_get_product_preview_reference($product, $settings_product_id);
  }

  return [
    'width' => 1200,
    'height' => 1200,
    'aspect_ratio' => 1,
  ];
}

function sunstreaker_cart_boundary_box(array $rect, float $reference_width, float $reference_height): array {
  return [
    'x' => $rect['x'] * $reference_width,
    'y' => $rect['y'] * $reference_height,
    'w' => $rect['w'] * $reference_width,
    'h' => $rect['h'] * $reference_height,
  ];
}

function sunstreaker_cart_uppercase(string $text): string {
  return function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
}

function sunstreaker_cart_logo_slots(array $box, int $count): array {
  $count = max(1, $count);
  $cols = max(1, (int) ceil(sqrt($count)));
  $rows = max(1, (int) ceil($count / $cols));
  $gap_ratio = 0.04;
  $gap_x = $box['w'] * $gap_ratio;
  $gap_y = $box['h'] * $gap_ratio;
  $cell_w = max(1.0, ($box['w'] - ($gap_x * max(0, $cols - 1))) / $cols);
  $cell_h = max(1.0, ($box['h'] - ($gap_y * max(0, $rows - 1))) / $rows);
  $slots = [];

  for ($index = 0; $index < $count; $index += 1) {
    $row = (int) floor($index / $cols);
    $col = $index % $cols;
    $slots[] = [
      'x' => $box['x'] + (($cell_w + $gap_x) * $col),
      'y' => $box['y'] + (($cell_h + $gap_y) * $row),
      'w' => $cell_w,
      'h' => $cell_h,
    ];
  }

  return $slots;
}

function sunstreaker_cart_right_chest_boxes(array $box, bool $has_name, bool $has_department): array {
  $padding_x = $box['w'] * 0.06;
  $padding_y = $box['h'] * 0.08;
  $inner = [
    'x' => $box['x'] + $padding_x,
    'y' => $box['y'] + $padding_y,
    'w' => max(1.0, $box['w'] - ($padding_x * 2)),
    'h' => max(1.0, $box['h'] - ($padding_y * 2)),
  ];

  if ($has_name && $has_department) {
    $gap = max(1.0, $inner['h'] * 0.08);
    $usable_height = max(1.0, $inner['h'] - $gap);
    $name_h = $usable_height * 0.56;
    $department_h = max(1.0, $usable_height - $name_h);
    return [
      'name' => [
        'x' => $inner['x'],
        'y' => $inner['y'],
        'w' => $inner['w'],
        'h' => $name_h,
      ],
      'department' => [
        'x' => $inner['x'],
        'y' => $inner['y'] + $name_h + $gap,
        'w' => $inner['w'],
        'h' => $department_h,
      ],
    ];
  }

  if ($has_name) {
    return ['name' => $inner];
  }

  if ($has_department) {
    return ['department' => $inner];
  }

  return [];
}

function sunstreaker_preview_default_values(): array {
  return [
    'name' => 'YOUR NAME',
    'number' => '26',
    'right_chest_name_credentials' => 'Name & Credentials',
    'right_chest_department' => 'Department',
  ];
}

function sunstreaker_prepare_logo_urls(array $args): array {
  $logo_urls = [];

  if (!empty($args['logo_urls']) && is_array($args['logo_urls'])) {
    $logo_urls = array_values(array_filter(array_map('strval', $args['logo_urls'])));
  } elseif (!empty($args['logo_url'])) {
    $logo_urls = [trim((string) $args['logo_url'])];
  }

  return $logo_urls;
}

function sunstreaker_has_renderable_preview_data(array $args): bool {
  $name = trim((string) ($args['name'] ?? ''));
  $number = trim((string) ($args['number'] ?? ''));
  $right_chest_name = trim((string) ($args['right_chest_name_credentials'] ?? ''));
  $right_chest_department = trim((string) ($args['right_chest_department'] ?? ''));
  $logo_urls = sunstreaker_prepare_logo_urls($args);

  return !($name === '' && $number === '' && $right_chest_name === '' && $right_chest_department === '' && empty($logo_urls));
}

function sunstreaker_render_composite_svg(array $args): string {
  $image_url = isset($args['image_url']) ? trim((string) $args['image_url']) : '';
  $name = trim((string) ($args['name'] ?? ''));
  $number = trim((string) ($args['number'] ?? ''));
  $right_chest_name = trim((string) ($args['right_chest_name_credentials'] ?? ''));
  $right_chest_department = trim((string) ($args['right_chest_department'] ?? ''));
  $has_right_chest_text = ($right_chest_name !== '' || $right_chest_department !== '');
  $logo_urls = sunstreaker_prepare_logo_urls($args);

  if (!sunstreaker_has_renderable_preview_data([
    'name' => $name,
    'number' => $number,
    'right_chest_name_credentials' => $right_chest_name,
    'right_chest_department' => $right_chest_department,
    'logo_urls' => $logo_urls,
  ])) {
    return '';
  }

  $reference = isset($args['reference']) && is_array($args['reference']) ? $args['reference'] : [];
  $reference_width = max(1.0, (float) ($reference['width'] ?? 1200));
  $reference_height = max(1.0, (float) ($reference['height'] ?? 1200));
  $boundaries = isset($args['boundaries']) && is_array($args['boundaries']) ? $args['boundaries'] : [];
  $ink_color = isset($args['ink_color']) ? (string) $args['ink_color'] : 'White';
  $font_stack = isset($args['font_stack']) ? (string) $args['font_stack'] : '"Varsity Block","Freshman","College","Oswald","Arial Black",sans-serif';
  $right_chest_font_stack = isset($args['right_chest_font_stack']) ? (string) $args['right_chest_font_stack'] : $font_stack;
  $right_chest_font_choice = isset($args['right_chest_font_choice']) ? sanitize_key((string) $args['right_chest_font_choice']) : '';
  $svg_class = trim((string) ($args['svg_class'] ?? ''));
  $image_preserve = trim((string) ($args['image_preserve_aspect_ratio'] ?? 'none'));
  $aria_label = trim((string) ($args['aria_label'] ?? ''));
  $aria_hidden = !empty($args['aria_hidden']);

  $class_attr = $svg_class !== '' ? ' class="'.esc_attr($svg_class).'"' : '';
  if ($aria_hidden) {
    $svg = '<svg'.$class_attr.' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.(int) round($reference_width).' '.(int) round($reference_height).'" aria-hidden="true" focusable="false">';
  } else {
    if ($aria_label === '') $aria_label = esc_attr__('Customized product preview', 'sunstreaker');
    $svg = '<svg'.$class_attr.' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.(int) round($reference_width).' '.(int) round($reference_height).'" role="img" aria-label="'.esc_attr($aria_label).'">';
  }

  if ($image_url !== '') {
    $svg .= '<image href="'.esc_attr($image_url).'" x="0" y="0" width="'.esc_attr(number_format($reference_width, 4, '.', '')).'" height="'.esc_attr(number_format($reference_height, 4, '.', '')).'" preserveAspectRatio="'.esc_attr($image_preserve !== '' ? $image_preserve : 'none').'" />';
  }

  $name_number_layers = [];
  $name_number_layer_map = [];

  if (function_exists('sunstreaker_build_original_art_text_layers')) {
    $name_number_layers = sunstreaker_build_original_art_text_layers([
      'name' => $name,
      'number' => $number,
      'name_bounds' => $boundaries['name'] ?? [],
      'number_bounds' => $boundaries['number'] ?? [],
      'reference_width' => $reference_width,
      'reference_height' => $reference_height,
    ]);
    foreach ($name_number_layers as $layer) {
      $layer_id = isset($layer['id']) ? trim((string) $layer['id']) : '';
      if ($layer_id !== '') $name_number_layer_map[$layer_id] = $layer;
    }
  }

  $render_name_number_layer = static function(array $layer, string $font_stack, string $ink_color): string {
    $layer_fit = isset($layer['fit']) && is_array($layer['fit']) ? $layer['fit'] : [];
    $layer_text = isset($layer['text']) ? (string) $layer['text'] : '';
    if ($layer_text === '' || empty($layer_fit)) return '';

    $font_size = (float) ($layer_fit['font_size'] ?? 0.0);
    $scale_x = (float) ($layer_fit['scale_x'] ?? 1.0);
    $scale_y = (float) ($layer_fit['scale_y'] ?? 1.0);
    $letter_spacing = (float) ($layer_fit['letter_spacing'] ?? 0.0);
    $center_x = (float) ($layer['center_x'] ?? 0.0);
    $center_y = (float) ($layer['center_y'] ?? 0.0);
    $font_weight = (int) ($layer['font_weight'] ?? 700);
    $layer_style = 'font-family:'.$font_stack.';font-size:'.$font_size.'px;font-weight:'.$font_weight.';letter-spacing:'.$letter_spacing.'px;';

    $markup = '<g transform="translate('.number_format($center_x, 4, '.', '').' '.number_format($center_y, 4, '.', '').') scale('.number_format($scale_x, 6, '.', '').' '.number_format($scale_y, 6, '.', '').')">';
    $markup .= '<text x="0" y="0" text-anchor="middle" dominant-baseline="middle" fill="'.esc_attr($ink_color).'" style="'.esc_attr($layer_style).'">'.esc_html($layer_text).'</text>';
    $markup .= '</g>';
    return $markup;
  };

  if (!empty($name_number_layer_map['sunstreaker-name'])) {
    $svg .= $render_name_number_layer($name_number_layer_map['sunstreaker-name'], $font_stack, $ink_color);
  } elseif ($name !== '' && !empty($boundaries['name']) && function_exists('sunstreaker_calculate_svg_text_fit')) {
    $name_text = sunstreaker_cart_uppercase(preg_replace('/\s+/', ' ', $name));
    $name_box = sunstreaker_cart_boundary_box($boundaries['name'], $reference_width, $reference_height);
    $name_fit = sunstreaker_calculate_svg_text_fit($name_text, $name_box, 0.56, 0.03, 0.70, 1.0, 1.0);
    $name_style = 'font-family:'.$font_stack.';font-size:'.$name_fit['font_size'].'px;font-weight:700;letter-spacing:'.$name_fit['letter_spacing'].'px;';
    $svg .= '<g transform="translate('.number_format($name_box['x'] + ($name_box['w'] / 2), 4, '.', '').' '.number_format($name_box['y'] + ($name_box['h'] / 2), 4, '.', '').') scale('.number_format($name_fit['scale_x'], 6, '.', '').' '.number_format($name_fit['scale_y'], 6, '.', '').')">';
    $svg .= '<text x="0" y="0" text-anchor="middle" dominant-baseline="middle" fill="'.esc_attr($ink_color).'" style="'.esc_attr($name_style).'">'.esc_html($name_text).'</text>';
    $svg .= '</g>';
  }

  if (!empty($name_number_layer_map['sunstreaker-number'])) {
    $svg .= $render_name_number_layer($name_number_layer_map['sunstreaker-number'], $font_stack, $ink_color);
  } elseif ($number !== '' && !empty($boundaries['number']) && function_exists('sunstreaker_calculate_svg_text_fit')) {
    $number_text = preg_replace('/[^0-9]/', '', $number);
    if ($number_text === '') $number_text = $number;
    $number_box = sunstreaker_cart_boundary_box($boundaries['number'], $reference_width, $reference_height);
    $number_fit = sunstreaker_calculate_svg_text_fit(
      function_exists('sunstreaker_number_fit_measurement_text')
        ? sunstreaker_number_fit_measurement_text($number_text)
        : $number_text,
      $number_box,
      0.60,
      0.02,
      0.74,
      0.90,
      0.68
    );
    $number_style = 'font-family:'.$font_stack.';font-size:'.$number_fit['font_size'].'px;font-weight:700;letter-spacing:'.$number_fit['letter_spacing'].'px;';
    $svg .= '<g transform="translate('.number_format($number_box['x'] + ($number_box['w'] / 2), 4, '.', '').' '.number_format($number_box['y'] + ($number_box['h'] / 2), 4, '.', '').') scale('.number_format($number_fit['scale_x'], 6, '.', '').' '.number_format($number_fit['scale_y'], 6, '.', '').')">';
    $svg .= '<text x="0" y="0" text-anchor="middle" dominant-baseline="middle" fill="'.esc_attr($ink_color).'" style="'.esc_attr($number_style).'">'.esc_html($number_text).'</text>';
    $svg .= '</g>';
  }

  if (!empty($logo_urls) && !empty($boundaries['logo'])) {
    $logo_box = sunstreaker_cart_boundary_box($boundaries['logo'], $reference_width, $reference_height);
    $slots = sunstreaker_cart_logo_slots($logo_box, count($logo_urls));
    foreach ($logo_urls as $index => $logo_url) {
      if ($logo_url === '' || empty($slots[$index])) continue;
      $slot = $slots[$index];
      $pad_x = $slot['w'] * 0.02;
      $pad_y = $slot['h'] * 0.02;
      $svg .= '<image href="'.esc_attr($logo_url).'" x="'.esc_attr(number_format($slot['x'] + $pad_x, 4, '.', '')).'" y="'.esc_attr(number_format($slot['y'] + $pad_y, 4, '.', '')).'" width="'.esc_attr(number_format(max(1.0, $slot['w'] - ($pad_x * 2)), 4, '.', '')).'" height="'.esc_attr(number_format(max(1.0, $slot['h'] - ($pad_y * 2)), 4, '.', '')).'" preserveAspectRatio="xMidYMid meet" />';
    }
  }

  if ($has_right_chest_text && !empty($boundaries['right_chest']) && function_exists('sunstreaker_calculate_svg_text_fit')) {
    $right_chest_box = sunstreaker_cart_boundary_box($boundaries['right_chest'], $reference_width, $reference_height);
    $right_chest_boxes = sunstreaker_cart_right_chest_boxes($right_chest_box, $right_chest_name !== '', $right_chest_department !== '');
    $right_chest_fit_profile = function_exists('sunstreaker_right_chest_font_fit_profile')
      ? sunstreaker_right_chest_font_fit_profile($right_chest_font_stack, $right_chest_font_choice)
      : [
        'char_width_ratio' => 0.52,
        'letter_spacing_ratio' => 0.008,
        'visible_height_ratio' => 1.0,
        'visible_width_ratio' => 1.0,
        'visible_box_height_ratio' => 1.0,
        'name_font_weight' => 600,
        'department_font_weight' => 500,
      ];

    if ($right_chest_name !== '' && !empty($right_chest_boxes['name'])) {
      $name_visible_height_ratio = (float) ($right_chest_fit_profile['visible_height_ratio'] ?? 1.0);
      $name_visible_box_height_ratio = (float) ($right_chest_fit_profile['visible_box_height_ratio'] ?? 1.0);
      $name_fit = sunstreaker_calculate_svg_text_fit(
        $right_chest_name,
        $right_chest_boxes['name'],
        (float) ($right_chest_fit_profile['char_width_ratio'] ?? 0.52),
        (float) ($right_chest_fit_profile['letter_spacing_ratio'] ?? 0.008),
        $name_visible_height_ratio,
        (float) ($right_chest_fit_profile['visible_width_ratio'] ?? 1.0),
        $name_visible_box_height_ratio
      );
      $name_uniform_scale = max(0.01, min((float) ($name_fit['scale_x'] ?? 1.0), (float) ($name_fit['scale_y'] ?? 1.0)));
      $name_min_height_px = function_exists('sunstreaker_right_chest_min_text_height_px')
        ? (float) sunstreaker_right_chest_min_text_height_px((float) ($right_chest_boxes['name']['w'] ?? 0.0))
        : max(1.0, ((float) ($right_chest_boxes['name']['w'] ?? 0.0)) * 0.0625);
      $name_base_height = max(1.0, 100.0 * max(1.0, $name_visible_height_ratio) * max(1.0, $name_visible_box_height_ratio) * 1.06);
      $name_uniform_scale = max($name_uniform_scale, $name_min_height_px / $name_base_height);
      $name_font_size = (float) ($name_fit['font_size'] ?? 100.0) * $name_uniform_scale;
      $name_letter_spacing = (float) ($name_fit['letter_spacing'] ?? 0.0) * $name_uniform_scale;
      $name_font_weight = (int) ($right_chest_fit_profile['name_font_weight'] ?? 600);
      $name_style = 'font-family:'.$right_chest_font_stack.';font-size:'.$name_font_size.'px;font-weight:'.$name_font_weight.';letter-spacing:'.$name_letter_spacing.'px;';
      $svg .= '<g transform="translate('.number_format($right_chest_boxes['name']['x'] + ($right_chest_boxes['name']['w'] / 2), 4, '.', '').' '.number_format($right_chest_boxes['name']['y'] + ($right_chest_boxes['name']['h'] / 2), 4, '.', '').')">';
      $svg .= '<text x="0" y="0" text-anchor="middle" dominant-baseline="middle" fill="'.esc_attr($ink_color).'" style="'.esc_attr($name_style).'">'.esc_html($right_chest_name).'</text>';
      $svg .= '</g>';
    }

    if ($right_chest_department !== '' && !empty($right_chest_boxes['department'])) {
      $department_visible_height_ratio = (float) ($right_chest_fit_profile['visible_height_ratio'] ?? 1.0);
      $department_visible_box_height_ratio = (float) ($right_chest_fit_profile['visible_box_height_ratio'] ?? 1.0);
      $department_fit = sunstreaker_calculate_svg_text_fit(
        $right_chest_department,
        $right_chest_boxes['department'],
        (float) ($right_chest_fit_profile['char_width_ratio'] ?? 0.52),
        (float) ($right_chest_fit_profile['letter_spacing_ratio'] ?? 0.008),
        $department_visible_height_ratio,
        (float) ($right_chest_fit_profile['visible_width_ratio'] ?? 1.0),
        $department_visible_box_height_ratio
      );
      $department_uniform_scale = max(0.01, min((float) ($department_fit['scale_x'] ?? 1.0), (float) ($department_fit['scale_y'] ?? 1.0)));
      $department_min_height_px = function_exists('sunstreaker_right_chest_min_text_height_px')
        ? (float) sunstreaker_right_chest_min_text_height_px((float) ($right_chest_boxes['department']['w'] ?? 0.0))
        : max(1.0, ((float) ($right_chest_boxes['department']['w'] ?? 0.0)) * 0.0625);
      $department_base_height = max(1.0, 100.0 * max(1.0, $department_visible_height_ratio) * max(1.0, $department_visible_box_height_ratio) * 1.06);
      $department_uniform_scale = max($department_uniform_scale, $department_min_height_px / $department_base_height);
      $department_font_size = (float) ($department_fit['font_size'] ?? 100.0) * $department_uniform_scale;
      $department_letter_spacing = (float) ($department_fit['letter_spacing'] ?? 0.0) * $department_uniform_scale;
      $department_font_weight = (int) ($right_chest_fit_profile['department_font_weight'] ?? 500);
      $department_style = 'font-family:'.$right_chest_font_stack.';font-size:'.$department_font_size.'px;font-weight:'.$department_font_weight.';letter-spacing:'.$department_letter_spacing.'px;';
      $svg .= '<g transform="translate('.number_format($right_chest_boxes['department']['x'] + ($right_chest_boxes['department']['w'] / 2), 4, '.', '').' '.number_format($right_chest_boxes['department']['y'] + ($right_chest_boxes['department']['h'] / 2), 4, '.', '').')">';
      $svg .= '<text x="0" y="0" text-anchor="middle" dominant-baseline="middle" fill="'.esc_attr($ink_color).'" style="'.esc_attr($department_style).'">'.esc_html($right_chest_department).'</text>';
      $svg .= '</g>';
    }
  }

  $svg .= '</svg>';

  return $svg;
}

function sunstreaker_get_product_thumbnail_image_data($product, int $settings_product_id, $size = 'woocommerce_thumbnail'): array {
  $image_id = function_exists('sunstreaker_get_product_preview_image_id')
    ? (int) sunstreaker_get_product_preview_image_id($product, $settings_product_id)
    : 0;

  if ($image_id <= 0) {
    return [
      'id' => 0,
      'url' => '',
      'width' => 0,
      'height' => 0,
      'alt' => '',
    ];
  }

  $url = '';
  $width = 0;
  $height = 0;
  $src = wp_get_attachment_image_src($image_id, $size);
  if (is_array($src) && !empty($src[0])) {
    $url = (string) $src[0];
    $width = isset($src[1]) ? max(0, (int) $src[1]) : 0;
    $height = isset($src[2]) ? max(0, (int) $src[2]) : 0;
  }

  if ($url === '') {
    $url = (string) wp_get_attachment_image_url($image_id, 'full');
  }

  return [
    'id' => $image_id,
    'url' => $url,
    'width' => $width,
    'height' => $height,
    'alt' => trim((string) get_post_meta($image_id, '_wp_attachment_image_alt', true)),
  ];
}

function sunstreaker_get_product_thumbnail_preview_data(int $settings_product_id): array {
  $defaults = sunstreaker_preview_default_values();
  $data = [
    'name' => '',
    'number' => '',
    'right_chest_name_credentials' => '',
    'right_chest_department' => '',
    'logo_urls' => [],
  ];

  if (function_exists('sunstreaker_uses_name_number') && sunstreaker_uses_name_number($settings_product_id)) {
    $data['name'] = (string) $defaults['name'];
    $data['number'] = (string) $defaults['number'];
  }

  return $data;
}

function sunstreaker_render_product_thumbnail($product, $size = 'woocommerce_thumbnail'): string {
  if (!$product || !is_a($product, 'WC_Product')) return '';

  $product_id = (int) $product->get_id();
  if ($product_id <= 0) return '';

  $settings_product_id = function_exists('sunstreaker_get_settings_product_id')
    ? sunstreaker_get_settings_product_id($product_id)
    : $product_id;
  if ($settings_product_id <= 0 || !sunstreaker_is_enabled_for_product($settings_product_id)) return '';

  $preview_data = sunstreaker_get_product_thumbnail_preview_data($settings_product_id);
  if (!sunstreaker_has_renderable_preview_data($preview_data)) return '';

  $image_data = sunstreaker_get_product_thumbnail_image_data($product, $settings_product_id, $size);
  if ($image_data['url'] === '') return '';

  $reference = function_exists('sunstreaker_get_product_preview_reference')
    ? sunstreaker_get_product_preview_reference($product, $settings_product_id)
    : ['width' => 1200, 'height' => 1200, 'aspect_ratio' => 1];
  if ($image_data['width'] > 0 && $image_data['height'] > 0) {
    $reference['width'] = (int) $image_data['width'];
    $reference['height'] = (int) $image_data['height'];
    $reference['aspect_ratio'] = $image_data['height'] / max(1, $image_data['width']);
  }

  $boundaries = function_exists('sunstreaker_get_preview_boundaries')
    ? sunstreaker_get_preview_boundaries($settings_product_id)
    : [
      'name' => ['x' => 0.22, 'y' => 0.26, 'w' => 0.56, 'h' => 0.12],
      'number' => ['x' => 0.30, 'y' => 0.41, 'w' => 0.40, 'h' => 0.24],
      'logo' => ['x' => 0.18, 'y' => 0.20, 'w' => 0.20, 'h' => 0.20],
      'right_chest' => ['x' => 0.18, 'y' => 0.20, 'w' => 0.34, 'h' => 0.12],
    ];
  $ink_color = function_exists('sunstreaker_get_ink_color') ? sunstreaker_get_ink_color($settings_product_id) : 'White';
  if (function_exists('sunstreaker_normalize_ink_color')) {
    $ink_color = sunstreaker_normalize_ink_color($ink_color);
  }
  $font_stack = function_exists('sunstreaker_get_font_stack')
    ? sunstreaker_get_font_stack($settings_product_id)
    : '"Varsity Block","Freshman","College","Oswald","Arial Black",sans-serif';
  if (function_exists('sunstreaker_sanitize_font_stack')) {
    $font_stack = sunstreaker_sanitize_font_stack($font_stack);
  }
  $right_chest_font_stack = function_exists('sunstreaker_get_right_chest_font_stack')
    ? sunstreaker_get_right_chest_font_stack($settings_product_id)
    : $font_stack;
  if (function_exists('sunstreaker_sanitize_font_stack')) {
    $right_chest_font_stack = sunstreaker_sanitize_font_stack($right_chest_font_stack);
  }

  $label_source = $image_data['alt'] !== '' ? $image_data['alt'] : $product->get_name();
  $svg = sunstreaker_render_composite_svg([
    'image_url' => $image_data['url'],
    'reference' => $reference,
    'boundaries' => $boundaries,
    'ink_color' => $ink_color,
    'font_stack' => $font_stack,
    'right_chest_font_stack' => $right_chest_font_stack,
    'right_chest_font_choice' => function_exists('sunstreaker_get_right_chest_font_choice_key') ? sunstreaker_get_right_chest_font_choice_key($settings_product_id) : 'montserrat',
    'name' => $preview_data['name'],
    'number' => $preview_data['number'],
    'right_chest_name_credentials' => $preview_data['right_chest_name_credentials'],
    'right_chest_department' => $preview_data['right_chest_department'],
    'logo_urls' => $preview_data['logo_urls'],
    'svg_class' => 'sunstreaker-product-thumb__svg',
    'aria_label' => sprintf(__('Customized preview of %s', 'sunstreaker'), $label_source),
  ]);
  if ($svg === '') return '';

  return '<span class="sunstreaker-product-thumb">'.$svg.'</span>';
}

function sunstreaker_render_cart_thumbnail(array $cart_item): string {
  $ss = !empty($cart_item['sunstreaker']) && is_array($cart_item['sunstreaker']) ? $cart_item['sunstreaker'] : [];
  if (!sunstreaker_has_renderable_preview_data($ss)) return '';

  $product = sunstreaker_get_cart_thumbnail_product($cart_item);
  $product_id = $product ? (int) $product->get_id() : (int) ($cart_item['product_id'] ?? 0);
  $settings_product_id = function_exists('sunstreaker_get_settings_product_id')
    ? sunstreaker_get_settings_product_id($product_id)
    : $product_id;
  $image_url = sunstreaker_get_cart_thumbnail_image_url($cart_item, $product, $settings_product_id);
  if ($image_url === '') return '';

  $reference = sunstreaker_get_cart_thumbnail_reference($cart_item, $product, $settings_product_id);
  $reference_width = max(1.0, (float) ($reference['width'] ?? 1200));
  $reference_height = max(1.0, (float) ($reference['height'] ?? 1200));
  $boundaries = function_exists('sunstreaker_get_preview_boundaries')
    ? sunstreaker_get_preview_boundaries($settings_product_id)
    : [
      'name' => ['x' => 0.22, 'y' => 0.26, 'w' => 0.56, 'h' => 0.12],
      'number' => ['x' => 0.30, 'y' => 0.41, 'w' => 0.40, 'h' => 0.24],
      'logo' => ['x' => 0.18, 'y' => 0.20, 'w' => 0.20, 'h' => 0.20],
      'right_chest' => ['x' => 0.18, 'y' => 0.20, 'w' => 0.34, 'h' => 0.12],
    ];
  $ink_color = !empty($ss['ink_color']) ? (string) $ss['ink_color'] : (function_exists('sunstreaker_get_ink_color') ? sunstreaker_get_ink_color($settings_product_id) : 'White');
  if (function_exists('sunstreaker_normalize_ink_color')) {
    $ink_color = sunstreaker_normalize_ink_color($ink_color);
  }
  $font_stack = !empty($ss['font_stack'])
    ? (string) $ss['font_stack']
    : (function_exists('sunstreaker_get_font_stack')
      ? sunstreaker_get_font_stack($settings_product_id)
      : '"Varsity Block","Freshman","College","Oswald","Arial Black",sans-serif');
  if (function_exists('sunstreaker_sanitize_font_stack')) {
    $font_stack = sunstreaker_sanitize_font_stack($font_stack);
  }
  $right_chest_font_stack = !empty($ss['right_chest_font_stack'])
    ? (string) $ss['right_chest_font_stack']
    : (function_exists('sunstreaker_get_right_chest_font_stack')
      ? sunstreaker_get_right_chest_font_stack($settings_product_id)
      : $font_stack);
  if (function_exists('sunstreaker_sanitize_font_stack')) {
    $right_chest_font_stack = sunstreaker_sanitize_font_stack($right_chest_font_stack);
  }

  $svg = sunstreaker_render_composite_svg([
    'image_url' => $image_url,
    'reference' => [
      'width' => $reference_width,
      'height' => $reference_height,
    ],
    'boundaries' => $boundaries,
    'ink_color' => $ink_color,
    'font_stack' => $font_stack,
    'right_chest_font_stack' => $right_chest_font_stack,
    'right_chest_font_choice' => $ss['right_chest_font_choice'] ?? '',
    'name' => $ss['name'] ?? '',
    'number' => $ss['number'] ?? '',
    'right_chest_name_credentials' => $ss['right_chest_name_credentials'] ?? '',
    'right_chest_department' => $ss['right_chest_department'] ?? '',
    'logo_urls' => $ss['logo_urls'] ?? [],
    'logo_url' => $ss['logo_url'] ?? '',
    'svg_class' => 'sunstreaker-cart-thumb__svg',
    'aria_label' => esc_attr__('Customized product preview', 'sunstreaker'),
  ]);
  if ($svg === '') return '';

  return '<span class="sunstreaker-cart-thumb">'.$svg.'</span>';
}

add_filter('woocommerce_add_to_cart_validation', function($passed, $product_id, $qty){
  if (!sunstreaker_is_enabled_for_product($product_id)) return $passed;

  $name = sunstreaker_get_posted_name();
  $num  = sunstreaker_get_posted_number();
  $right_chest_name = function_exists('sunstreaker_get_posted_right_chest_name_credentials') ? sunstreaker_get_posted_right_chest_name_credentials() : '';
  $right_chest_department = function_exists('sunstreaker_get_posted_right_chest_department') ? sunstreaker_get_posted_right_chest_department() : '';
  $use_name_number = function_exists('sunstreaker_uses_name_number') ? sunstreaker_uses_name_number($product_id) : true;
  $use_right_chest = function_exists('sunstreaker_uses_right_chest_text') ? sunstreaker_uses_right_chest_text($product_id) : false;
  $has_text_personalization = $use_name_number && !($name === '' && $num === '');
  $has_right_chest_text = $use_right_chest && !($right_chest_name === '' && $right_chest_department === '');
  $scrub_fields = function_exists('sunstreaker_get_scrub_attributes_for_product') ? sunstreaker_get_scrub_attributes_for_product($product_id) : [];
  $scrubs_choice = function_exists('sunstreaker_get_posted_scrubs_choice') ? sunstreaker_get_posted_scrubs_choice($product_id) : 'no';
  $scrub_values = function_exists('sunstreaker_get_posted_scrub_values') ? sunstreaker_get_posted_scrub_values($product_id) : [];
  $logo_location_choices = (function_exists('sunstreaker_uses_logos') && sunstreaker_uses_logos($product_id) && function_exists('sunstreaker_get_posted_logo_location_choices'))
    ? sunstreaker_get_posted_logo_location_choices($product_id)
    : [];
  $logo_ids = array_values(array_map('absint', array_column($logo_location_choices, 'logo_id')));

  // Personalization is optional; only validate when either field is provided.
  if (!$has_text_personalization && !$has_right_chest_text && empty($logo_ids) && $scrubs_choice !== 'yes') return $passed;
  foreach ($logo_location_choices as $logo_location_choice) {
    $logo_id = isset($logo_location_choice['logo_id']) ? (int) $logo_location_choice['logo_id'] : 0;
    if (!function_exists('sunstreaker_is_allowed_logo') || !sunstreaker_is_allowed_logo($product_id, $logo_id)) {
      wc_add_notice(__('Choose a valid logo from the list.', 'sunstreaker'), 'error');
      return false;
    }
  }
  if ($has_text_personalization && ($name === '' || $num === '')) {
    wc_add_notice(__('Enter both Name and Number, or leave both blank.', 'sunstreaker'), 'error');
    return false;
  }
  if ($has_text_personalization && mb_strlen($name) > 20) {
    wc_add_notice(__('Name must be 20 characters or less.', 'sunstreaker'), 'error');
    return false;
  }
  if ($has_text_personalization && !preg_match('/^[0-9]{2}$/', $num)) {
    wc_add_notice(__('Number must be two digits (00–99).', 'sunstreaker'), 'error');
    return false;
  }
  if ($scrubs_choice === 'yes' && !empty($scrub_fields)) {
    foreach ($scrub_fields as $field_key => $field) {
      if (!empty($scrub_values[$field_key])) continue;
      $label = !empty($field['label']) ? (string) $field['label'] : (function_exists('sunstreaker_scrub_attribute_field_label') ? sunstreaker_scrub_attribute_field_label((string) $field_key) : ucwords(str_replace('_', ' ', (string) $field_key)));
      wc_add_notice(sprintf(__('Choose a %s option.', 'sunstreaker'), $label), 'error');
      return false;
    }
  }
  return $passed;
}, 10, 3);

add_filter('woocommerce_add_cart_item_data', function($cart_item_data, $product_id, $variation_id){
  if (!sunstreaker_is_enabled_for_product($product_id)) return $cart_item_data;

  $name = sunstreaker_get_posted_name();
  $num  = sunstreaker_get_posted_number();
  $right_chest_name = function_exists('sunstreaker_get_posted_right_chest_name_credentials') ? sunstreaker_get_posted_right_chest_name_credentials() : '';
  $right_chest_department = function_exists('sunstreaker_get_posted_right_chest_department') ? sunstreaker_get_posted_right_chest_department() : '';
  $font_choice = function_exists('sunstreaker_get_posted_font_choice_key')
    ? sunstreaker_get_posted_font_choice_key($product_id)
    : (function_exists('sunstreaker_get_font_choice_key') ? sunstreaker_get_font_choice_key($product_id) : 'varsity_block');
  $right_chest_font_choice = function_exists('sunstreaker_get_posted_right_chest_font_choice_key')
    ? sunstreaker_get_posted_right_chest_font_choice_key($product_id)
    : (function_exists('sunstreaker_get_right_chest_font_choice_key') ? sunstreaker_get_right_chest_font_choice_key($product_id) : 'montserrat');
  $use_name_number = function_exists('sunstreaker_uses_name_number') ? sunstreaker_uses_name_number($product_id) : true;
  $use_right_chest = function_exists('sunstreaker_uses_right_chest_text') ? sunstreaker_uses_right_chest_text($product_id) : false;
  $scrub_fields = function_exists('sunstreaker_get_scrub_attributes_for_product') ? sunstreaker_get_scrub_attributes_for_product($product_id) : [];
  $scrubs_choice = function_exists('sunstreaker_get_posted_scrubs_choice') ? sunstreaker_get_posted_scrubs_choice($product_id) : 'no';
  $scrub_values = function_exists('sunstreaker_get_posted_scrub_values') ? sunstreaker_get_posted_scrub_values($product_id) : [];
  $logo_location_choices = (function_exists('sunstreaker_uses_logos') && sunstreaker_uses_logos($product_id) && function_exists('sunstreaker_get_posted_logo_location_choices'))
    ? sunstreaker_get_posted_logo_location_choices($product_id)
    : [];
  $logo_ids = array_values(array_map('absint', array_column($logo_location_choices, 'logo_id')));
  $has_text_personalization = $use_name_number && !($name === '' && $num === '');
  $has_right_chest_text = $use_right_chest && !($right_chest_name === '' && $right_chest_department === '');
  $has_scrubs = ($scrubs_choice === 'yes' && !empty($scrub_values));
  $logo_choices = [];
  foreach ($logo_location_choices as $logo_location_choice) {
    if (empty($logo_location_choice['logo']) || !is_array($logo_location_choice['logo'])) continue;
    $choice = $logo_location_choice['logo'];
    $choice['location_key'] = (string) ($logo_location_choice['location_key'] ?? '');
    $choice['location_label'] = (string) ($logo_location_choice['location_label'] ?? '');
    $choice['production'] = (string) ($logo_location_choice['production'] ?? '');
    $choice['price'] = isset($logo_location_choice['price']) ? (float) $logo_location_choice['price'] : 0.0;
    $logo_choices[] = $choice;
  }

  if (!$use_name_number) {
    $name = '';
    $num = '';
  }
  if (!$use_right_chest) {
    $right_chest_name = '';
    $right_chest_department = '';
  }
  if (!$has_scrubs || empty($scrub_fields)) {
    $scrub_values = [];
  }

  if (!$has_text_personalization && !$has_right_chest_text && empty($logo_choices) && !$has_scrubs) return $cart_item_data;

  $name_number_addon = $has_text_personalization ? sunstreaker_get_addon_price($product_id) : 0.0;
  $logo_addon = 0.0;
  foreach ($logo_choices as $logo_choice) {
    $logo_addon += isset($logo_choice['price']) ? max(0.0, (float) $logo_choice['price']) : 0.0;
  }
  $right_chest_addon = $has_right_chest_text && function_exists('sunstreaker_get_right_chest_price')
    ? sunstreaker_get_right_chest_price($product_id)
    : ($has_right_chest_text ? 10.0 : 0.0);
  $addon_total = $name_number_addon + $logo_addon + $right_chest_addon;

  $cart_item_data['sunstreaker'] = [
    'name'   => $name,
    'number' => $num,
    'right_chest_name_credentials' => $right_chest_name,
    'right_chest_department' => $right_chest_department,
    'font_choice' => $font_choice,
    'font_stack' => function_exists('sunstreaker_get_font_stack_from_choice_key')
      ? sunstreaker_get_font_stack_from_choice_key($font_choice, function_exists('sunstreaker_get_font_choice_key') ? sunstreaker_get_font_choice_key($product_id) : 'varsity_block')
      : (function_exists('sunstreaker_get_font_stack') ? sunstreaker_get_font_stack($product_id) : '"Varsity Block","Freshman","College","Oswald","Arial Black",sans-serif'),
    'right_chest_font_choice' => $right_chest_font_choice,
    'right_chest_font_stack' => function_exists('sunstreaker_get_font_stack_from_choice_key')
      ? sunstreaker_get_font_stack_from_choice_key($right_chest_font_choice, function_exists('sunstreaker_get_right_chest_font_choice_key') ? sunstreaker_get_right_chest_font_choice_key($product_id) : 'montserrat')
      : (function_exists('sunstreaker_get_right_chest_font_stack') ? sunstreaker_get_right_chest_font_stack($product_id) : (function_exists('sunstreaker_get_font_stack') ? sunstreaker_get_font_stack($product_id) : '"Montserrat","Helvetica Neue",Helvetica,Arial,sans-serif')),
    'ink_color' => function_exists('sunstreaker_get_ink_color') ? sunstreaker_get_ink_color($product_id) : 'White',
    'name_number_addon' => $name_number_addon,
    'logo_addon' => $logo_addon,
    'right_chest_addon' => $right_chest_addon,
    'addon_total' => $addon_total,
    'addon'  => $addon_total,
  ];
  if (!empty($scrub_values)) {
    $cart_item_data['sunstreaker']['scrubs'] = 'yes';
    $cart_item_data['sunstreaker']['scrub_attributes'] = $scrub_values;
    foreach ($scrub_values as $field_key => $field_value) {
      $cart_item_data['sunstreaker']['scrub_'.sanitize_key((string) $field_key)] = (string) $field_value;
    }
  }
  $preview_image_url = function_exists('sunstreaker_get_posted_preview_image_url') ? sunstreaker_get_posted_preview_image_url() : '';
  $preview_image_width = function_exists('sunstreaker_get_posted_preview_image_width') ? sunstreaker_get_posted_preview_image_width() : 0;
  $preview_image_height = function_exists('sunstreaker_get_posted_preview_image_height') ? sunstreaker_get_posted_preview_image_height() : 0;
  if ($preview_image_url !== '') {
    $cart_item_data['sunstreaker']['preview_image_url'] = $preview_image_url;
  }
  if ($preview_image_width > 0) {
    $cart_item_data['sunstreaker']['preview_image_width'] = $preview_image_width;
  }
  if ($preview_image_height > 0) {
    $cart_item_data['sunstreaker']['preview_image_height'] = $preview_image_height;
  }
  if (!empty($logo_choices)) {
    $cart_item_data['sunstreaker']['logo_locations'] = [];
    $cart_item_data['sunstreaker']['logo_ids'] = array_values(array_map('absint', array_column($logo_choices, 'id')));
    $cart_item_data['sunstreaker']['logo_labels'] = [];
    $cart_item_data['sunstreaker']['logo_urls'] = array_values(array_map('strval', array_column($logo_choices, 'preview_url')));
    foreach ($logo_choices as $logo_choice) {
      $location_label = isset($logo_choice['location_label']) ? trim((string) $logo_choice['location_label']) : '';
      $logo_title = isset($logo_choice['title']) ? (string) $logo_choice['title'] : '';
      $display_label = $location_label !== '' ? $location_label.': '.$logo_title : $logo_title;
      $cart_item_data['sunstreaker']['logo_labels'][] = $display_label;
      $cart_item_data['sunstreaker']['logo_locations'][] = [
        'location_key' => (string) ($logo_choice['location_key'] ?? ''),
        'location_label' => $location_label,
        'logo_id' => (int) ($logo_choice['id'] ?? 0),
        'logo_label' => $logo_title,
        'logo_url' => (string) ($logo_choice['preview_url'] ?? ''),
        'production' => (string) ($logo_choice['production'] ?? ''),
        'price' => isset($logo_choice['price']) ? (float) $logo_choice['price'] : 0.0,
      ];
    }
    $cart_item_data['sunstreaker']['logo_id'] = (int) ($cart_item_data['sunstreaker']['logo_ids'][0] ?? 0);
    $cart_item_data['sunstreaker']['logo_label'] = implode(', ', $cart_item_data['sunstreaker']['logo_labels']);
    $cart_item_data['sunstreaker']['logo_url'] = (string) ($cart_item_data['sunstreaker']['logo_urls'][0] ?? '');
  }
  $cart_item_data['sunstreaker_base_price'] = sunstreaker_get_cart_base_price((int)$product_id, (int)$variation_id);
  $cart_item_data = sunstreaker_apply_line_configuration($cart_item_data, (int) $product_id);

  $cart_item_data['sunstreaker_key'] = md5($name.'|'.$num.'|'.$font_choice.'|'.$right_chest_name.'|'.$right_chest_department.'|'.$right_chest_font_choice.'|'.wp_json_encode($logo_location_choices).'|'.wp_json_encode($scrub_values).'|'.microtime(true));

  return $cart_item_data;
}, 10, 3);

add_filter('woocommerce_get_item_data', function($item_data, $cart_item){
  if (empty($cart_item['sunstreaker']) || !is_array($cart_item['sunstreaker'])) return $item_data;

  $ss = $cart_item['sunstreaker'];
  $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
  $has_name_number = !empty($ss['name']) || !empty($ss['number']);
  $has_right_chest = !empty($ss['right_chest_name_credentials']) || !empty($ss['right_chest_department']);
  $product_scrub_fields = function_exists('sunstreaker_get_scrub_attributes_for_product')
    ? sunstreaker_get_scrub_attributes_for_product($product_id)
    : [];
  if (!empty($ss['name'])) {
    $item_data[] = ['key' => 'Name', 'value' => wc_clean($ss['name'] ?? '')];
  }
  if (!empty($ss['number'])) {
    $item_data[] = ['key' => 'Number', 'value' => wc_clean($ss['number'] ?? '')];
  }
  if ($has_name_number) {
    $back_font_label = sunstreaker_get_name_number_font_label($ss, $product_id);
    if ($back_font_label !== '') {
      $item_data[] = ['key' => 'Back Font', 'value' => wc_clean($back_font_label)];
    }
  }
  if (!empty($ss['logo_locations']) && is_array($ss['logo_locations'])) {
    foreach ($ss['logo_locations'] as $logo_location) {
      if (!is_array($logo_location)) continue;
      $location_label = !empty($logo_location['location_label']) ? (string) $logo_location['location_label'] : __('Logo', 'sunstreaker');
      $logo_label = !empty($logo_location['logo_label']) ? (string) $logo_location['logo_label'] : '';
      $logo_url = !empty($logo_location['logo_url']) ? (string) $logo_location['logo_url'] : '';
      if ($logo_label === '') continue;
      $logo_media = sunstreaker_prepare_media_entries([$logo_url], [$logo_label], $location_label);
      $logo_display = sunstreaker_render_cart_item_media_html($logo_media);
      $entry = ['key' => 'Logo - '.wc_clean($location_label), 'value' => wc_clean($logo_label)];
      if ($logo_display !== '') {
        $entry['display'] = $logo_display;
      }
      $item_data[] = $entry;
    }
  } elseif (!empty($ss['logo_label'])) {
    $logo_key = !empty($ss['logo_ids']) && count((array) $ss['logo_ids']) > 1 ? 'Logos' : 'Logo';
    $logo_value = wc_clean($ss['logo_label'] ?? '');
    $logo_urls = !empty($ss['logo_urls']) && is_array($ss['logo_urls'])
      ? $ss['logo_urls']
      : (!empty($ss['logo_url']) ? [(string) $ss['logo_url']] : []);
    $logo_labels = !empty($ss['logo_labels']) && is_array($ss['logo_labels'])
      ? $ss['logo_labels']
      : ($logo_value !== '' ? [$logo_value] : []);
    $logo_media = sunstreaker_prepare_media_entries($logo_urls, $logo_labels, $logo_key);
    $logo_display = sunstreaker_render_cart_item_media_html($logo_media);

    $entry = ['key' => $logo_key, 'value' => $logo_value];
    if ($logo_display !== '') {
      $entry['display'] = $logo_display;
    }
    $item_data[] = $entry;
  }
  if (!empty($ss['right_chest_name_credentials'])) {
    $item_data[] = ['key' => 'Right Chest Name/Credentials', 'value' => wc_clean($ss['right_chest_name_credentials'] ?? '')];
  }
  if (!empty($ss['right_chest_department'])) {
    $item_data[] = ['key' => 'Right Chest Department', 'value' => wc_clean($ss['right_chest_department'] ?? '')];
  }
  if ($has_right_chest) {
    $right_chest_font_label = sunstreaker_get_right_chest_font_label($ss, $product_id);
    if ($right_chest_font_label !== '') {
      $item_data[] = ['key' => 'Right Chest Font', 'value' => wc_clean($right_chest_font_label)];
    }
  }
  if (!empty($ss['scrub_attributes']) && is_array($ss['scrub_attributes']) && function_exists('sunstreaker_scrub_attribute_definitions')) {
    foreach (sunstreaker_scrub_attribute_definitions() as $field_key => $definition) {
      $value = isset($ss['scrub_attributes'][$field_key]) ? trim((string) $ss['scrub_attributes'][$field_key]) : '';
      $field = isset($product_scrub_fields[$field_key]) && is_array($product_scrub_fields[$field_key]) ? $product_scrub_fields[$field_key] : [];
      $variation_key = !empty($field['attribute_name']) ? 'attribute_'.(string) $field['attribute_name'] : '';
      $is_variation_field = !empty($field['is_variation']) && $variation_key !== '' && !empty($cart_item['variation'][$variation_key]);
      if ($value === '') continue;
      if ($is_variation_field) continue;
      $label = isset($definition['label']) ? (string) $definition['label'] : ucwords(str_replace('_', ' ', (string) $field_key));
      $item_data[] = ['key' => $label, 'value' => wc_clean($value)];
    }
  }

  return $item_data;
}, 10, 2);

add_filter('woocommerce_cart_item_thumbnail', function($thumbnail, $cart_item, $cart_item_key){
  if (empty($cart_item['sunstreaker']) || !is_array($cart_item['sunstreaker'])) return $thumbnail;

  $mockup = sunstreaker_render_cart_thumbnail($cart_item);
  return $mockup !== '' ? $mockup : $thumbnail;
}, 20, 3);

add_filter('woocommerce_product_get_image', function($image_html, $product, $size, $attr, $placeholder, $original_image){
  if (!$product || !is_a($product, 'WC_Product')) return $image_html;
  if (is_admin() && !wp_doing_ajax()) return $image_html;

  $preview = sunstreaker_render_product_thumbnail($product, $size);
  return $preview !== '' ? $preview : $image_html;
}, 20, 6);

add_action('woocommerce_before_calculate_totals', function($cart){
  if (is_admin() && !defined('DOING_AJAX')) return;
  if (!$cart || !is_a($cart, 'WC_Cart')) return;

  foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
    if (empty($cart_item['sunstreaker']) || empty($cart_item['data']) || !is_a($cart_item['data'], 'WC_Product')) continue;

    $ss = $cart_item['sunstreaker'];
    $addon = sunstreaker_cart_addon_total($ss);
    if ($addon <= 0) continue;

    $product = $cart_item['data'];
    $base = isset($cart_item['sunstreaker_base_price'])
      ? (float) $cart_item['sunstreaker_base_price']
      : (float) $product->get_price('edit');
    if (!isset($cart_item['sunstreaker_base_price'])) {
      $cart->cart_contents[$cart_item_key]['sunstreaker_base_price'] = $base;
    }
    $product->set_price($base + $addon);
    if (isset($cart->cart_contents[$cart_item_key]['sunstreaker_price_applied'])) {
      unset($cart->cart_contents[$cart_item_key]['sunstreaker_price_applied']);
    }
  }
}, 20, 1);

add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values, $order){
  if (empty($values['sunstreaker']) || !is_array($values['sunstreaker'])) return;

  $ss = $values['sunstreaker'];
  $product_id = isset($values['product_id']) ? (int) $values['product_id'] : 0;
  $product_scrub_fields = function_exists('sunstreaker_get_scrub_attributes_for_product')
    ? sunstreaker_get_scrub_attributes_for_product($product_id)
    : [];
  $has_name_number = !empty($ss['name']) || !empty($ss['number']);
  $has_right_chest = !empty($ss['right_chest_name_credentials']) || !empty($ss['right_chest_department']);
  if (!empty($ss['name'])) {
    $item->add_meta_data('Name', wc_clean($ss['name'] ?? ''), true);
  }
  if (!empty($ss['number'])) {
    $item->add_meta_data('Number', wc_clean($ss['number'] ?? ''), true);
  }
  if ($has_name_number) {
    $back_font_label = sunstreaker_get_name_number_font_label($ss, $product_id);
    if ($back_font_label !== '') {
      $item->add_meta_data('Back Font', wc_clean($back_font_label), true);
    }
  }
  if (!empty($ss['logo_locations']) && is_array($ss['logo_locations'])) {
    foreach ($ss['logo_locations'] as $logo_location) {
      if (!is_array($logo_location)) continue;
      $location_label = !empty($logo_location['location_label']) ? (string) $logo_location['location_label'] : 'Logo';
      $logo_label = !empty($logo_location['logo_label']) ? (string) $logo_location['logo_label'] : '';
      if ($logo_label === '') continue;
      $item->add_meta_data('Logo - '.$location_label, wc_clean($logo_label), true);
    }
  } elseif (!empty($ss['logo_label'])) {
    $item->add_meta_data(!empty($ss['logo_ids']) && count((array) $ss['logo_ids']) > 1 ? 'Logos' : 'Logo', wc_clean($ss['logo_label'] ?? ''), true);
  }
  if (!empty($ss['right_chest_name_credentials'])) {
    $item->add_meta_data('Right Chest Name/Credentials', wc_clean($ss['right_chest_name_credentials'] ?? ''), true);
  }
  if (!empty($ss['right_chest_department'])) {
    $item->add_meta_data('Right Chest Department', wc_clean($ss['right_chest_department'] ?? ''), true);
  }
  if ($has_right_chest) {
    $right_chest_font_label = sunstreaker_get_right_chest_font_label($ss, $product_id);
    if ($right_chest_font_label !== '') {
      $item->add_meta_data('Right Chest Font', wc_clean($right_chest_font_label), true);
    }
  }
  if (!empty($ss['scrubs'])) {
    $item->add_meta_data('_sunstreaker_scrubs', wc_clean((string) $ss['scrubs']), true);
  }
  if (!empty($ss['scrub_attributes']) && is_array($ss['scrub_attributes']) && function_exists('sunstreaker_scrub_attribute_definitions')) {
    foreach (sunstreaker_scrub_attribute_definitions() as $field_key => $definition) {
      $value = isset($ss['scrub_attributes'][$field_key]) ? trim((string) $ss['scrub_attributes'][$field_key]) : '';
      $field = isset($product_scrub_fields[$field_key]) && is_array($product_scrub_fields[$field_key]) ? $product_scrub_fields[$field_key] : [];
      $variation_key = !empty($field['attribute_name']) ? 'attribute_'.(string) $field['attribute_name'] : '';
      $is_variation_field = !empty($field['is_variation']) && $variation_key !== '' && !empty($values['variation'][$variation_key]);
      if ($value === '') continue;

      $label = isset($definition['label']) ? (string) $definition['label'] : ucwords(str_replace('_', ' ', (string) $field_key));
      $meta_key = isset($definition['meta_key']) ? (string) $definition['meta_key'] : '_sunstreaker_scrub_'.sanitize_key((string) $field_key);
      $item->add_meta_data($meta_key, wc_clean($value), true);
      if (!$is_variation_field) {
        $item->add_meta_data($label, wc_clean($value), true);
      }
    }
  }
  if (!empty($ss['font_choice'])) {
    $item->add_meta_data('_sunstreaker_font_choice', wc_clean($ss['font_choice']), true);
  }
  if (!empty($ss['right_chest_font_choice'])) {
    $item->add_meta_data('_sunstreaker_right_chest_font_choice', wc_clean($ss['right_chest_font_choice']), true);
  }
  if (!empty($ss['logo_locations']) && is_array($ss['logo_locations'])) {
    $item->add_meta_data('_sunstreaker_logo_locations', wp_json_encode($ss['logo_locations']), true);
  }
  if (!empty($ss['logo_ids']) && is_array($ss['logo_ids'])) {
    $item->add_meta_data('_sunstreaker_logo_ids', implode(',', array_map('absint', $ss['logo_ids'])), true);
  } elseif (!empty($ss['logo_id'])) {
    $item->add_meta_data('_sunstreaker_logo_id', (int) $ss['logo_id'], true);
  }
  if (!empty($ss['logo_labels']) && is_array($ss['logo_labels'])) {
    $item->add_meta_data('_sunstreaker_logo_labels', wp_json_encode(array_values(array_map('wc_clean', $ss['logo_labels']))), true);
  } elseif (!empty($ss['logo_label'])) {
    $item->add_meta_data('_sunstreaker_logo_labels', wp_json_encode([wc_clean((string) $ss['logo_label'])]), true);
  }
  if (!empty($ss['logo_urls']) && is_array($ss['logo_urls'])) {
    $item->add_meta_data('_sunstreaker_logo_urls', wp_json_encode(array_values(array_map('esc_url_raw', $ss['logo_urls']))), true);
  } elseif (!empty($ss['logo_url'])) {
    $item->add_meta_data('_sunstreaker_logo_url', esc_url_raw((string) $ss['logo_url']), true);
  }
  if (!empty($ss['ink_color'])) {
    $item->add_meta_data('_sunstreaker_ink_color', wc_clean($ss['ink_color']), true);
  }
  if (!empty($ss['preview_image_url'])) {
    $item->add_meta_data('_sunstreaker_preview_image_url', esc_url_raw((string) $ss['preview_image_url']), true);
  }
  if (!empty($ss['preview_image_width'])) {
    $item->add_meta_data('_sunstreaker_preview_image_width', absint($ss['preview_image_width']), true);
  }
  if (!empty($ss['preview_image_height'])) {
    $item->add_meta_data('_sunstreaker_preview_image_height', absint($ss['preview_image_height']), true);
  }
  if (!empty($ss['mockup_svg_url'])) {
    $item->add_meta_data('_sunstreaker_mockup_svg_url', esc_url_raw((string) $ss['mockup_svg_url']), true);
  }
  if (!empty($ss['mockup_png_url'])) {
    $item->add_meta_data('_sunstreaker_mockup_png_url', esc_url_raw((string) $ss['mockup_png_url']), true);
  }
  if (!empty($ss['mockup_url'])) {
    $mockup_url = esc_url_raw((string) $ss['mockup_url']);
    $item->add_meta_data('mockup_url', $mockup_url, true);
    $item->add_meta_data('Mockup', $mockup_url, true);
  }
  if (!empty($ss['production'])) {
    $item->add_meta_data('Production', wc_clean((string) $ss['production']), true);
  }
  if (!empty($ss['print_location'])) {
    $item->add_meta_data('Print Location', wc_clean((string) $ss['print_location']), true);
  }
  if (!empty($ss['special_instructions'])) {
    $item->add_meta_data('Special Instructions for production', sanitize_textarea_field((string) $ss['special_instructions']), true);
  }
  if (!empty($ss['original_art']) && is_array($ss['original_art'])) {
    foreach ($ss['original_art'] as $key => $url) {
      $key = is_scalar($key) ? trim((string) $key) : '';
      $url = is_scalar($url) ? trim((string) $url) : '';
      if ($key === '' || $url === '') continue;
      $item->add_meta_data($key, esc_url_raw($url), true);
    }
  }
  $size = sunstreaker_get_checkout_size_from_values($values);
  if ($size !== '') {
    $item->add_meta_data('_sunstreaker_size', wc_clean($size), true);
  }

  if (isset($ss['name_number_addon']) && (float) $ss['name_number_addon'] > 0) {
    $item->add_meta_data('Name/Number Add-on', wc_clean(number_format((float)$ss['name_number_addon'], 2, '.', '')), true);
  }
  if (isset($ss['logo_addon']) && (float) $ss['logo_addon'] > 0) {
    $item->add_meta_data('Logo Add-on', wc_clean(number_format((float)$ss['logo_addon'], 2, '.', '')), true);
  }
  if (isset($ss['right_chest_addon']) && (float) $ss['right_chest_addon'] > 0) {
    $item->add_meta_data('Right Chest Text Add-on', wc_clean(number_format((float)$ss['right_chest_addon'], 2, '.', '')), true);
  }
}, 10, 4);

add_filter('woocommerce_order_item_get_formatted_meta_data', function($formatted_meta, $item) {
  $email_context = sunstreaker_get_email_context();
  if (is_admin() && empty($email_context['active'])) return $formatted_meta;

  $hidden_lookup = array_fill_keys([
    'Production',
    'Print Location',
    'Special Instructions for production',
    'mockup_url',
    'Mockup',
    'Original Art Front',
    'Original Art Back',
    'Original Art - Front',
    'Original Art - Back',
    'Original Art - Name Number Back',
    'Sunstreaker Add-on',
  ], true);

  foreach ($formatted_meta as $meta_id => $meta) {
    $display_key = '';
    if (is_object($meta)) {
      if (isset($meta->display_key)) {
        $display_key = (string) $meta->display_key;
      } elseif (isset($meta->key)) {
        $display_key = (string) $meta->key;
      }
    }

    if ($display_key !== '' && isset($hidden_lookup[$display_key])) {
      unset($formatted_meta[$meta_id]);
    }
  }

  return $formatted_meta;
}, 20, 2);

add_action('woocommerce_email_before_order_table', function($order, $sent_to_admin, $plain_text, $email) {
  $GLOBALS['sunstreaker_email_context'] = [
    'active' => true,
    'sent_to_admin' => (bool) $sent_to_admin,
    'plain_text' => (bool) $plain_text,
    'email_id' => is_object($email) && isset($email->id) ? (string) $email->id : '',
  ];
}, 5, 4);

add_action('woocommerce_email_after_order_table', function() {
  unset($GLOBALS['sunstreaker_email_context']);
}, 999, 0);

add_action('woocommerce_order_item_meta_end', function($item_id, $item, $order, $plain_text) {
  if (!($item instanceof WC_Order_Item_Product)) return;

  $email_context = sunstreaker_get_email_context();
  if (empty($email_context['active']) || empty($email_context['sent_to_admin'])) return;

  $details = sunstreaker_render_email_work_order_details($item, (bool) $plain_text);
  if ($details === '') return;

  echo $details; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}, 20, 4);
