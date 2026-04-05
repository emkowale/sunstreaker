<?php
if (!defined('ABSPATH')) exit;

/**
 * Per-product settings:
 * - _sunstreaker_enabled: yes|no
 * - _sunstreaker_use_name_number: yes|no
 * - _sunstreaker_addon_price: decimal string (e.g., 5.00)
 * - _sunstreaker_ink_color: plain text (e.g., White)
 * - _sunstreaker_font_choice: selected font option key
 * - _sunstreaker_use_logos: yes|no
 * - _sunstreaker_logo_price: decimal string (e.g., 14.00)
 * - _sunstreaker_logo_ids: comma-separated attachment IDs
 * - _sunstreaker_use_right_chest_text: yes|no
 * - _sunstreaker_right_chest_price: decimal string (e.g., 10.00)
 * - _sunstreaker_use_front_back: yes|no
 * - _sunstreaker_front_back_price: decimal string (e.g., 0.00)
 * - _sunstreaker_name_number_production: production method label
 * - _sunstreaker_logos_production: production method label
 * - _sunstreaker_right_chest_production: production method label
 * - _sunstreaker_front_back_production: production method label
 * - _sunstreaker_right_chest_font_choice: selected font option key
 * - _sunstreaker_name_boundary: JSON boundary ratios for the name preview box
 * - _sunstreaker_number_boundary: JSON boundary ratios for the number preview box
 * - _sunstreaker_logo_boundary: JSON boundary ratios for the logo preview box
 * - _sunstreaker_right_chest_boundary: JSON boundary ratios for the right-chest preview box
 * - _sunstreaker_front_boundary: JSON boundary ratios for the front-art preview box
 * - _sunstreaker_back_boundary: JSON boundary ratios for the back-art preview box
 */

function sunstreaker_get_settings_product_id($product_id): int {
  $product_id = (int) $product_id;
  if ($product_id <= 0 || !function_exists('wc_get_product')) return max(0, $product_id);

  $product = wc_get_product($product_id);
  if ($product && is_a($product, 'WC_Product_Variation')) {
    $parent_id = (int) $product->get_parent_id();
    if ($parent_id > 0) return $parent_id;
  }

  return $product_id;
}

function sunstreaker_is_enabled_for_product($product_id): bool {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $val = get_post_meta($product_id, '_sunstreaker_enabled', true);
  return ($val === 'yes');
}

function sunstreaker_uses_name_number($product_id): bool {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $val = get_post_meta($product_id, '_sunstreaker_use_name_number', true);
  return ($val === 'yes');
}

function sunstreaker_get_addon_price($product_id): float {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $raw = get_post_meta($product_id, '_sunstreaker_addon_price', true);
  if ($raw === '' || $raw === null) return 5.00;
  $raw = is_string($raw) ? $raw : (string)$raw;
  $raw = preg_replace('/[^0-9.]/', '', $raw);
  if ($raw === '') return 5.00;
  return max(0.0, (float)$raw);
}

function sunstreaker_get_ink_color($product_id): string {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $raw = get_post_meta($product_id, '_sunstreaker_ink_color', true);
  $raw = is_string($raw) ? trim($raw) : '';
  $raw = sanitize_text_field($raw);
  if ($raw === '') return 'White';
  return $raw;
}

function sunstreaker_uses_logos($product_id): bool {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $val = get_post_meta($product_id, '_sunstreaker_use_logos', true);
  return ($val === 'yes');
}

function sunstreaker_get_logo_price($product_id): float {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $raw = get_post_meta($product_id, '_sunstreaker_logo_price', true);
  if ($raw === '' || $raw === null) return 14.00;
  $raw = is_string($raw) ? $raw : (string) $raw;
  $raw = preg_replace('/[^0-9.]/', '', $raw);
  if ($raw === '') return 14.00;
  return max(0.0, (float) $raw);
}

function sunstreaker_uses_right_chest_text($product_id): bool {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $val = get_post_meta($product_id, '_sunstreaker_use_right_chest_text', true);
  return ($val === 'yes');
}

function sunstreaker_get_right_chest_price($product_id): float {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $raw = get_post_meta($product_id, '_sunstreaker_right_chest_price', true);
  if ($raw === '' || $raw === null) return 10.00;
  $raw = is_string($raw) ? $raw : (string) $raw;
  $raw = preg_replace('/[^0-9.]/', '', $raw);
  if ($raw === '') return 10.00;
  return max(0.0, (float) $raw);
}

function sunstreaker_uses_front_back($product_id): bool {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $val = get_post_meta($product_id, '_sunstreaker_use_front_back', true);
  return ($val === 'yes');
}

function sunstreaker_get_front_back_price($product_id): float {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $raw = get_post_meta($product_id, '_sunstreaker_front_back_price', true);
  if ($raw === '' || $raw === null) return 0.00;
  $raw = is_string($raw) ? $raw : (string) $raw;
  $raw = preg_replace('/[^0-9.]/', '', $raw);
  if ($raw === '') return 0.00;
  return max(0.0, (float) $raw);
}

function sunstreaker_get_front_back_display_addon($product): float {
  $product_id = 0;

  if (is_numeric($product)) {
    $product_id = (int) $product;
  } elseif (is_object($product) && method_exists($product, 'get_id')) {
    $product_id = (int) $product->get_id();
  }

  if ($product_id <= 0) return 0.0;
  if (!sunstreaker_is_enabled_for_product($product_id) || !sunstreaker_uses_front_back($product_id)) return 0.0;

  return sunstreaker_get_front_back_price($product_id);
}

function sunstreaker_format_front_back_adjusted_price_html(WC_Product $product, float $addon): string {
  $addon = max(0.0, $addon);
  if ($addon <= 0) return $product->get_price_html();

  if ($product->is_type('variable')) {
    $min_price = (float) $product->get_variation_price('min', true);
    $max_price = (float) $product->get_variation_price('max', true);

    if ($min_price !== $max_price) {
      return wc_price($min_price + $addon).' - '.wc_price($max_price + $addon);
    }

    $min_regular = (float) $product->get_variation_regular_price('min', true);
    if ($min_regular > $min_price) {
      return wc_format_sale_price($min_regular + $addon, $min_price + $addon);
    }

    return wc_price($min_price + $addon);
  }

  $price = (float) wc_get_price_to_display($product);
  $regular_price_raw = $product->get_regular_price();
  $regular_price = $regular_price_raw !== '' ? (float) wc_get_price_to_display($product, ['price' => (float) $regular_price_raw]) : $price;

  if ($regular_price > $price) {
    return wc_format_sale_price($regular_price + $addon, $price + $addon);
  }

  return wc_price($price + $addon);
}

function sunstreaker_scrub_attribute_definitions(): array {
  return [
    'fabric' => [
      'label' => 'Fabric',
      'aliases' => ['fabric'],
      'input_name' => 'sunstreaker_scrub_fabric',
      'meta_key' => '_sunstreaker_scrub_fabric',
    ],
    'wear_style' => [
      'label' => 'Wear Style',
      'aliases' => ['wear-style', 'wearstyle'],
      'input_name' => 'sunstreaker_scrub_wear_style',
      'meta_key' => '_sunstreaker_scrub_wear_style',
    ],
    'pants_style' => [
      'label' => 'Pants Style',
      'aliases' => ['pants-style', 'pantsstyle'],
      'input_name' => 'sunstreaker_scrub_pants_style',
      'meta_key' => '_sunstreaker_scrub_pants_style',
    ],
    'fit' => [
      'label' => 'Fit',
      'aliases' => ['fit'],
      'input_name' => 'sunstreaker_scrub_fit',
      'meta_key' => '_sunstreaker_scrub_fit',
    ],
  ];
}

add_filter('woocommerce_get_price_html', function($price_html, $product) {
  if (!$product || !is_a($product, 'WC_Product')) return $price_html;

  $addon = sunstreaker_get_front_back_display_addon($product);
  if ($addon <= 0) return $price_html;

  return sunstreaker_format_front_back_adjusted_price_html($product, $addon);
}, 20, 2);

add_filter('woocommerce_available_variation', function($data, $product, $variation) {
  if (!$variation || !is_a($variation, 'WC_Product_Variation')) return $data;

  $addon = sunstreaker_get_front_back_display_addon($variation);
  if ($addon <= 0) return $data;

  $display_price = isset($data['display_price']) ? (float) $data['display_price'] : (float) wc_get_price_to_display($variation);
  $display_regular_price = isset($data['display_regular_price']) ? (float) $data['display_regular_price'] : (float) wc_get_price_to_display($variation, ['price' => (float) $variation->get_regular_price()]);

  $data['display_price'] = $display_price + $addon;
  $data['display_regular_price'] = $display_regular_price + $addon;

  if ($data['display_regular_price'] > $data['display_price']) {
    $data['price_html'] = '<span class="price">'.wc_format_sale_price($data['display_regular_price'], $data['display_price']).'</span>';
  } else {
    $data['price_html'] = '<span class="price">'.wc_price($data['display_price']).'</span>';
  }

  return $data;
}, 20, 3);

function sunstreaker_scrub_attribute_field_label(string $field_key): string {
  $definitions = sunstreaker_scrub_attribute_definitions();
  if (!empty($definitions[$field_key]['label'])) {
    return (string) $definitions[$field_key]['label'];
  }

  return ucwords(str_replace('_', ' ', sanitize_key($field_key)));
}

function sunstreaker_scrub_attribute_input_name(string $field_key): string {
  $definitions = sunstreaker_scrub_attribute_definitions();
  if (!empty($definitions[$field_key]['input_name'])) {
    return (string) $definitions[$field_key]['input_name'];
  }

  return 'sunstreaker_scrub_'.sanitize_key($field_key);
}

function sunstreaker_scrub_attribute_meta_key(string $field_key): string {
  $definitions = sunstreaker_scrub_attribute_definitions();
  if (!empty($definitions[$field_key]['meta_key'])) {
    return (string) $definitions[$field_key]['meta_key'];
  }

  return '_sunstreaker_scrub_'.sanitize_key($field_key);
}

function sunstreaker_scrub_attribute_candidates($attribute, $product): array {
  $candidates = [];
  $names = [];
  $expanded_names = [];

  if (is_object($attribute) && method_exists($attribute, 'get_name')) {
    $names[] = (string) $attribute->get_name();
  } elseif (is_array($attribute) && isset($attribute['name'])) {
    $names[] = (string) $attribute['name'];
  }

  foreach ($names as $name) {
    $name = trim($name);
    if ($name === '') continue;
    $expanded_names[] = $name;
    $expanded_names[] = strpos($name, 'pa_') === 0 ? substr($name, 3) : $name;
    if (function_exists('wc_attribute_label')) {
      $label = (string) wc_attribute_label($name, $product);
      if ($label !== '') $expanded_names[] = $label;
    }
  }

  foreach ($expanded_names as $name) {
    $name = trim((string) $name);
    if ($name === '') continue;

    $slug = sanitize_title($name);
    if ($slug !== '') {
      $candidates[$slug] = $slug;
      $collapsed = str_replace('-', '', $slug);
      if ($collapsed !== '') $candidates[$collapsed] = $collapsed;
    }
  }

  return array_values($candidates);
}

function sunstreaker_scrub_attribute_option_labels(int $product_id, $attribute): array {
  $options = [];

  if (is_object($attribute) && method_exists($attribute, 'is_taxonomy') && $attribute->is_taxonomy()) {
    $taxonomy = method_exists($attribute, 'get_name') ? (string) $attribute->get_name() : '';
    $raw_options = method_exists($attribute, 'get_options') ? $attribute->get_options() : [];

    if ($taxonomy !== '' && is_array($raw_options)) {
      foreach ($raw_options as $raw_option) {
        $term = false;
        if (is_numeric($raw_option)) {
          $term = get_term((int) $raw_option, $taxonomy);
        } elseif (is_string($raw_option) && $raw_option !== '') {
          $term = get_term_by('slug', $raw_option, $taxonomy);
        }

        if ($term && !is_wp_error($term) && !empty($term->name)) {
          $options[] = (string) $term->name;
        }
      }
    }

    if (empty($options) && $taxonomy !== '' && function_exists('wc_get_product_terms')) {
      $term_names = wc_get_product_terms($product_id, $taxonomy, ['fields' => 'names']);
      if (is_array($term_names)) {
        foreach ($term_names as $term_name) {
          $options[] = (string) $term_name;
        }
      }
    }
  } else {
    $raw_options = is_object($attribute) && method_exists($attribute, 'get_options')
      ? $attribute->get_options()
      : [];

    if (is_string($raw_options)) {
      if (function_exists('wc_get_text_attributes')) {
        $raw_options = wc_get_text_attributes($raw_options);
      } else {
        $raw_options = preg_split('/\s*\|\s*/', $raw_options);
      }
    }

    if (is_array($raw_options)) {
      foreach ($raw_options as $raw_option) {
        $options[] = (string) $raw_option;
      }
    }
  }

  $cleaned = [];
  $seen = [];
  foreach ($options as $option) {
    $label = sanitize_text_field(trim((string) $option));
    if ($label === '') continue;

    $key = strtolower(preg_replace('/\s+/', ' ', $label));
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $cleaned[] = $label;
  }

  return $cleaned;
}

function sunstreaker_get_scrub_attributes_for_product($product_id): array {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  if ($product_id <= 0 || !function_exists('wc_get_product')) return [];

  $product = wc_get_product($product_id);
  if (!$product || !is_a($product, 'WC_Product')) return [];

  $attributes = $product->get_attributes();
  if (!is_array($attributes) || empty($attributes)) return [];

  $definitions = sunstreaker_scrub_attribute_definitions();
  $matched = [];

  foreach ($attributes as $attribute) {
    $candidates = sunstreaker_scrub_attribute_candidates($attribute, $product);
    if (empty($candidates)) continue;

    foreach ($definitions as $field_key => $definition) {
      if (isset($matched[$field_key])) continue;

      $aliases = isset($definition['aliases']) && is_array($definition['aliases']) ? $definition['aliases'] : [];
      if (empty(array_intersect($aliases, $candidates))) continue;

      $options = sunstreaker_scrub_attribute_option_labels($product_id, $attribute);
      if (empty($options)) continue;

      $matched[$field_key] = [
        'key' => $field_key,
        'label' => (string) $definition['label'],
        'input_name' => (string) $definition['input_name'],
        'meta_key' => (string) $definition['meta_key'],
        'attribute_name' => is_object($attribute) && method_exists($attribute, 'get_name')
          ? (string) $attribute->get_name()
          : '',
        'is_variation' => is_object($attribute) && method_exists($attribute, 'get_variation')
          ? (bool) $attribute->get_variation()
          : false,
        'options' => $options,
      ];
      break;
    }
  }

  $ordered = [];
  foreach (array_keys($definitions) as $field_key) {
    if (!isset($matched[$field_key])) continue;
    $ordered[$field_key] = $matched[$field_key];
  }

  return $ordered;
}

function sunstreaker_resolve_scrub_option_value($raw_value, array $options): string {
  $raw_value = sanitize_text_field(trim((string) $raw_value));
  if ($raw_value === '') return '';

  $target_slug = sanitize_title($raw_value);
  foreach ($options as $option) {
    $label = sanitize_text_field(trim((string) $option));
    if ($label === '') continue;

    if (strcasecmp($label, $raw_value) === 0) return $label;
    if ($target_slug !== '' && sanitize_title($label) === $target_slug) return $label;
  }

  return '';
}

function sunstreaker_production_method_choices(): array {
  return [
    'DTG' => 'DTG',
    'DTF' => 'DTF',
    'Embroidery' => 'Embroidery',
    'UV' => 'UV',
    'Fulfill' => 'Fulfill',
  ];
}

function sunstreaker_default_feature_production_method(string $feature): string {
  $defaults = [
    'name_number' => 'DTF',
    'logos' => 'Embroidery',
    'right_chest' => 'Embroidery',
    'front_back' => 'DTF',
  ];

  return isset($defaults[$feature]) ? $defaults[$feature] : 'DTF';
}

function sunstreaker_feature_production_meta_key(string $feature): string {
  $map = [
    'name_number' => '_sunstreaker_name_number_production',
    'logos' => '_sunstreaker_logos_production',
    'right_chest' => '_sunstreaker_right_chest_production',
    'front_back' => '_sunstreaker_front_back_production',
  ];

  return isset($map[$feature]) ? $map[$feature] : '';
}

function sunstreaker_sanitize_production_method($value, string $fallback = ''): string {
  $value = is_scalar($value) ? trim((string) $value) : '';
  $choices = sunstreaker_production_method_choices();
  if ($value !== '' && isset($choices[$value])) return $value;

  $fallback = trim($fallback);
  if ($fallback !== '' && isset($choices[$fallback])) return $fallback;

  return sunstreaker_default_feature_production_method('name_number');
}

function sunstreaker_get_feature_production_method($product_id, string $feature): string {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $meta_key = sunstreaker_feature_production_meta_key($feature);
  $fallback = sunstreaker_default_feature_production_method($feature);
  if ($meta_key === '') return $fallback;

  $raw = get_post_meta($product_id, $meta_key, true);
  return sunstreaker_sanitize_production_method($raw, $fallback);
}

function sunstreaker_sanitize_logo_ids($raw): array {
  if (is_string($raw)) {
    $trimmed = trim($raw);
    if ($trimmed !== '' && $trimmed[0] === '[') {
      $decoded = json_decode($trimmed, true);
      if (is_array($decoded)) {
        $raw = $decoded;
      } else {
        $raw = preg_split('/\s*,\s*/', $trimmed);
      }
    } else {
      $raw = preg_split('/\s*,\s*/', $trimmed);
    }
  }

  if (!is_array($raw)) return [];

  $ids = [];
  foreach ($raw as $value) {
    $id = absint($value);
    if ($id <= 0) continue;
    $mime = (string) get_post_mime_type($id);
    if ($mime === '' || stripos($mime, 'image/') !== 0) continue;
    $ids[] = $id;
  }

  return array_values(array_unique($ids));
}

function sunstreaker_get_logo_ids($product_id): array {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $raw = get_post_meta($product_id, '_sunstreaker_logo_ids', true);
  return sunstreaker_sanitize_logo_ids($raw);
}

function sunstreaker_get_logo_choices($product_id): array {
  $choices = [];

  foreach (sunstreaker_get_logo_ids($product_id) as $attachment_id) {
    $title = trim((string) get_the_title($attachment_id));
    $file_path = (string) get_attached_file($attachment_id);
    $filename = $file_path !== '' ? wp_basename($file_path) : '';
    $preview_url = wp_get_attachment_image_url($attachment_id, 'full');
    if (!$preview_url) $preview_url = wp_get_attachment_url($attachment_id);
    if (!$preview_url) continue;

    $thumb_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
    if (!$thumb_url) $thumb_url = $preview_url;

    $choices[] = [
      'id' => $attachment_id,
      'title' => $title !== '' ? $title : 'Logo '.$attachment_id,
      'filename' => $filename,
      'preview_url' => (string) $preview_url,
      'thumb_url' => (string) $thumb_url,
      'alt' => trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true)),
    ];
  }

  return $choices;
}

function sunstreaker_get_logo_choice($product_id, int $logo_id): array {
  foreach (sunstreaker_get_logo_choices($product_id) as $logo) {
    if ((int) ($logo['id'] ?? 0) === $logo_id) return $logo;
  }

  return [];
}

function sunstreaker_is_allowed_logo($product_id, int $logo_id): bool {
  if ($logo_id <= 0) return false;
  return !empty(sunstreaker_get_logo_choice($product_id, $logo_id));
}

function sunstreaker_font_choices(): array {
  return [
    'varsity_block' => [
      'label' => 'Varsity',
      'family' => 'Graduate',
      'stack' => "\"Graduate\",\"Varsity Block\",\"Freshman\",\"College\",serif",
      'sample' => 'YOUR NAME 26',
    ],
    'athletic_impact' => [
      'label' => 'Anton',
      'family' => 'Anton',
      'stack' => "\"Anton\",Impact,Haettenschweiler,\"Arial Narrow Bold\",sans-serif",
      'sample' => 'YOUR NAME 26',
    ],
    'pro_condensed' => [
      'label' => 'Teko',
      'family' => 'Teko',
      'stack' => "\"Teko\",\"Oswald\",\"Bebas Neue\",\"Arial Narrow\",sans-serif",
      'sample' => 'YOUR NAME 26',
    ],
    'clean_sans' => [
      'label' => 'Source Sans 3',
      'family' => 'Source Sans 3',
      'stack' => "\"Source Sans 3\",\"Helvetica Neue\",Helvetica,Arial,sans-serif",
      'sample' => 'Dr. Jane Smith, MD',
    ],
    'classic_serif' => [
      'label' => 'Cormorant Garamond',
      'family' => 'Cormorant Garamond',
      'stack' => "\"Cormorant Garamond\",Georgia,\"Times New Roman\",serif",
      'sample' => 'Dr. Jane Smith, MD',
    ],
    'embroidery_script' => [
      'label' => 'Great Vibes',
      'family' => 'Great Vibes',
      'stack' => "\"Great Vibes\",\"Brush Script MT\",\"Segoe Script\",cursive",
      'sample' => 'Dr. Jane Smith, MD',
    ],
  ];
}

function sunstreaker_right_chest_font_choices(): array {
  return [
    'montserrat' => [
      'label' => 'Montserrat',
      'family' => 'Montserrat',
      'stack' => "\"Montserrat\",\"Helvetica Neue\",Helvetica,Arial,sans-serif",
      'sample' => 'Dr. Jane Smith, MD',
    ],
    'arial' => [
      'label' => 'Arial',
      'family' => 'Arial',
      'stack' => "Arial,\"Helvetica Neue\",Helvetica,sans-serif",
      'sample' => 'Dr. Jane Smith, MD',
    ],
    'averia_serif_libre' => [
      'label' => 'Averia Serif Libre',
      'family' => 'Averia Serif Libre',
      'stack' => "\"Averia Serif Libre\",Georgia,\"Times New Roman\",serif",
      'sample' => 'Dr. Jane Smith, MD',
    ],
    'baloo' => [
      'label' => 'Baloo',
      'family' => 'Baloo 2',
      'stack' => "\"Baloo 2\",\"Baloo\",cursive",
      'sample' => 'Dr. Jane Smith, MD',
    ],
    'original_surfer' => [
      'label' => 'Original Surfer',
      'family' => 'Original Surfer',
      'stack' => "\"Original Surfer\",\"Trebuchet MS\",sans-serif",
      'sample' => 'Dr. Jane Smith, MD',
    ],
    'caveat_brush' => [
      'label' => 'Caveat Brush',
      'family' => 'Caveat Brush',
      'stack' => "\"Caveat Brush\",\"Brush Script MT\",\"Segoe Print\",cursive",
      'sample' => 'Dr. Jane Smith, MD',
    ],
    'ravi_prakash' => [
      'label' => 'Ravi Prakash',
      'family' => 'Ravi Prakash',
      'stack' => "\"Ravi Prakash\",\"Comic Sans MS\",cursive",
      'sample' => 'Dr. Jane Smith, MD',
    ],
    'birds_of_paradise' => [
      'label' => 'Birds Of Paradise',
      'family' => 'Birds of Paradise',
      'stack' => "\"Birds of Paradise\",\"Alex Brush\",\"Allura\",\"Brush Script MT\",cursive",
      'sample' => 'Dr. Jane Smith, MD',
    ],
  ];
}

function sunstreaker_all_font_choices(): array {
  return array_merge(sunstreaker_font_choices(), sunstreaker_right_chest_font_choices());
}

function sunstreaker_name_number_font_choices(): array {
  $choices = sunstreaker_font_choices();
  unset($choices['classic_serif'], $choices['embroidery_script']);
  return $choices;
}

function sunstreaker_font_choice_labels(): array {
  $labels = [];
  foreach (sunstreaker_all_font_choices() as $key => $choice) {
    $labels[$key] = isset($choice['label']) ? (string) $choice['label'] : (string) $key;
  }
  return $labels;
}

function sunstreaker_resolve_name_number_font_choice_key(string $key, string $fallback = ''): string {
  $choices = sunstreaker_name_number_font_choices();
  $key = sanitize_key($key);
  if ($key !== '' && isset($choices[$key])) return $key;

  $fallback = sanitize_key($fallback);
  if ($fallback !== '' && isset($choices[$fallback])) return $fallback;

  return sunstreaker_default_font_choice_key();
}

function sunstreaker_resolve_font_choice_key(string $key, string $fallback = ''): string {
  $choices = sunstreaker_all_font_choices();
  $key = sanitize_key($key);
  if ($key !== '' && isset($choices[$key])) return $key;

  $fallback = sanitize_key($fallback);
  if ($fallback !== '' && isset($choices[$fallback])) return $fallback;

  return sunstreaker_default_font_choice_key();
}

function sunstreaker_resolve_right_chest_font_choice_key(string $key, string $fallback = ''): string {
  $choices = sunstreaker_right_chest_font_choices();
  $key = sanitize_key($key);
  if ($key !== '' && isset($choices[$key])) return $key;

  $fallback = sanitize_key($fallback);
  if ($fallback !== '' && isset($choices[$fallback])) return $fallback;

  return sunstreaker_default_right_chest_font_choice_key();
}

function sunstreaker_get_font_choice_data(string $key, string $fallback = ''): array {
  $choices = sunstreaker_all_font_choices();
  $resolved = sunstreaker_resolve_font_choice_key($key, $fallback);
  if (isset($choices[$resolved])) return $choices[$resolved];
  return $choices[sunstreaker_default_font_choice_key()];
}

function sunstreaker_get_font_stack_from_choice_key(string $key, string $fallback = ''): string {
  $choice = sunstreaker_get_font_choice_data($key, $fallback);
  $stack = isset($choice['stack']) ? trim((string) $choice['stack']) : '';
  if ($stack !== '') return $stack;
  return sunstreaker_get_font_stack(0);
}

function sunstreaker_get_font_label_from_choice_key(string $key, string $fallback = ''): string {
  $choice = sunstreaker_get_font_choice_data($key, $fallback);
  return isset($choice['label']) ? (string) $choice['label'] : sunstreaker_resolve_font_choice_key($key, $fallback);
}

function sunstreaker_default_font_choice_key(): string {
  return 'varsity_block';
}

function sunstreaker_default_right_chest_font_choice_key(): string {
  return 'montserrat';
}

function sunstreaker_get_font_choice_key($product_id): string {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $default = sunstreaker_default_font_choice_key();
  $raw = get_post_meta($product_id, '_sunstreaker_font_choice', true);
  return sunstreaker_resolve_font_choice_key(is_string($raw) ? $raw : '', $default);
}

function sunstreaker_get_name_number_font_choice_key($product_id): string {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $default = sunstreaker_default_font_choice_key();
  $raw = get_post_meta($product_id, '_sunstreaker_font_choice', true);
  return sunstreaker_resolve_name_number_font_choice_key(is_string($raw) ? $raw : '', $default);
}

function sunstreaker_get_font_choice($product_id): array {
  $choices = sunstreaker_font_choices();
  $key = sunstreaker_get_font_choice_key($product_id);
  if (isset($choices[$key])) return $choices[$key];
  return $choices[sunstreaker_default_font_choice_key()];
}

function sunstreaker_get_name_number_font_choice($product_id): array {
  $choices = sunstreaker_name_number_font_choices();
  $key = sunstreaker_get_name_number_font_choice_key($product_id);
  if (isset($choices[$key])) return $choices[$key];
  return $choices[sunstreaker_default_font_choice_key()];
}

function sunstreaker_get_right_chest_font_choice_key($product_id): string {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $default = sunstreaker_default_right_chest_font_choice_key();
  $raw = get_post_meta($product_id, '_sunstreaker_right_chest_font_choice', true);
  return sunstreaker_resolve_right_chest_font_choice_key(is_string($raw) ? $raw : '', $default);
}

function sunstreaker_get_right_chest_font_choice($product_id): array {
  $choices = sunstreaker_right_chest_font_choices();
  $key = sunstreaker_get_right_chest_font_choice_key($product_id);
  if (isset($choices[$key])) return $choices[$key];
  return $choices[sunstreaker_default_right_chest_font_choice_key()];
}

function sunstreaker_get_font_family($product_id): string {
  $choice = sunstreaker_get_name_number_font_choice($product_id);
  $family = isset($choice['family']) ? trim((string) $choice['family']) : '';
  return $family !== '' ? $family : 'Varsity Block';
}

function sunstreaker_get_font_stack($product_id): string {
  $choice = sunstreaker_get_name_number_font_choice($product_id);
  $stack = isset($choice['stack']) ? trim((string) $choice['stack']) : '';
  if ($stack !== '') return $stack;
  return "\"Varsity Block\",\"Freshman\",\"College\",\"Oswald\",\"Arial Black\",sans-serif";
}

function sunstreaker_get_right_chest_font_stack($product_id): string {
  $choice = sunstreaker_get_right_chest_font_choice($product_id);
  $stack = isset($choice['stack']) ? trim((string) $choice['stack']) : '';
  if ($stack !== '') return $stack;
  return sunstreaker_get_font_stack($product_id);
}

function sunstreaker_right_chest_max_width_in(): float {
  return 4.0;
}

function sunstreaker_right_chest_min_letter_height_in(): float {
  return 0.25;
}

function sunstreaker_right_chest_min_height_ratio(): float {
  $max_width_in = max(0.01, (float) sunstreaker_right_chest_max_width_in());
  $min_letter_height_in = max(0.01, (float) sunstreaker_right_chest_min_letter_height_in());
  return $min_letter_height_in / $max_width_in;
}

function sunstreaker_right_chest_min_text_height_px(float $box_width_px): float {
  return max(1.0, $box_width_px * sunstreaker_right_chest_min_height_ratio());
}

function sunstreaker_right_chest_font_fit_profile(string $font_stack = '', string $choice_key = ''): array {
  $choice_key = sanitize_key($choice_key);
  $font_stack = trim($font_stack);
  $stack_lc = strtolower($font_stack);
  $is_montserrat = ($choice_key === 'montserrat') || ($stack_lc !== '' && strpos($stack_lc, 'montserrat') !== false);
  $is_arial = ($choice_key === 'arial') || ($stack_lc !== '' && strpos($stack_lc, 'arial') !== false);
  $is_averia = ($choice_key === 'averia_serif_libre') || ($stack_lc !== '' && strpos($stack_lc, 'averia serif libre') !== false);
  $is_baloo = ($choice_key === 'baloo') || ($stack_lc !== '' && strpos($stack_lc, 'baloo') !== false);
  $is_surfer = ($choice_key === 'original_surfer') || ($stack_lc !== '' && strpos($stack_lc, 'original surfer') !== false);
  $is_caveat = ($choice_key === 'caveat_brush') || ($stack_lc !== '' && strpos($stack_lc, 'caveat brush') !== false);
  $is_ravi = ($choice_key === 'ravi_prakash') || ($stack_lc !== '' && strpos($stack_lc, 'ravi prakash') !== false);
  $is_birds = ($choice_key === 'birds_of_paradise') || ($stack_lc !== '' && (strpos($stack_lc, 'birds of paradise') !== false || strpos($stack_lc, 'alex brush') !== false || strpos($stack_lc, 'allura') !== false));

  if ($is_birds) {
    return [
      'char_width_ratio' => 0.58,
      'letter_spacing_ratio' => 0.000,
      'visible_height_ratio' => 1.42,
      'visible_width_ratio' => 1.34,
      'visible_box_height_ratio' => 1.30,
      'name_font_weight' => 400,
      'department_font_weight' => 400,
    ];
  }

  if ($is_caveat) {
    return [
      'char_width_ratio' => 0.58,
      'letter_spacing_ratio' => 0.001,
      'visible_height_ratio' => 1.34,
      'visible_width_ratio' => 1.28,
      'visible_box_height_ratio' => 1.26,
      'name_font_weight' => 400,
      'department_font_weight' => 400,
    ];
  }

  if ($is_ravi) {
    return [
      'char_width_ratio' => 0.60,
      'letter_spacing_ratio' => 0.002,
      'visible_height_ratio' => 1.32,
      'visible_width_ratio' => 1.28,
      'visible_box_height_ratio' => 1.24,
      'name_font_weight' => 400,
      'department_font_weight' => 400,
    ];
  }

  if ($is_baloo) {
    return [
      'char_width_ratio' => 0.60,
      'letter_spacing_ratio' => 0.002,
      'visible_height_ratio' => 1.18,
      'visible_width_ratio' => 1.22,
      'visible_box_height_ratio' => 1.12,
      'name_font_weight' => 600,
      'department_font_weight' => 500,
    ];
  }

  if ($is_surfer) {
    return [
      'char_width_ratio' => 0.59,
      'letter_spacing_ratio' => 0.002,
      'visible_height_ratio' => 1.18,
      'visible_width_ratio' => 1.24,
      'visible_box_height_ratio' => 1.12,
      'name_font_weight' => 400,
      'department_font_weight' => 400,
    ];
  }

  if ($is_averia) {
    return [
      'char_width_ratio' => 0.58,
      'letter_spacing_ratio' => 0.002,
      'visible_height_ratio' => 1.24,
      'visible_width_ratio' => 1.22,
      'visible_box_height_ratio' => 1.16,
      'name_font_weight' => 700,
      'department_font_weight' => 400,
    ];
  }

  if ($is_arial) {
    return [
      'char_width_ratio' => 0.57,
      'letter_spacing_ratio' => 0.000,
      'visible_height_ratio' => 1.18,
      'visible_width_ratio' => 1.18,
      'visible_box_height_ratio' => 1.10,
      'name_font_weight' => 700,
      'department_font_weight' => 400,
    ];
  }

  if ($is_montserrat) {
    return [
      'char_width_ratio' => 0.56,
      'letter_spacing_ratio' => 0.001,
      'visible_height_ratio' => 1.18,
      'visible_width_ratio' => 1.18,
      'visible_box_height_ratio' => 1.10,
      'name_font_weight' => 600,
      'department_font_weight' => 500,
    ];
  }

  return [
    'char_width_ratio' => 0.56,
    'letter_spacing_ratio' => 0.001,
    'visible_height_ratio' => 1.18,
    'visible_width_ratio' => 1.18,
    'visible_box_height_ratio' => 1.10,
    'name_font_weight' => 600,
    'department_font_weight' => 500,
  ];
}

function sunstreaker_get_font_stack_from_family(string $family): string {
  $family = trim($family);
  if ($family === '') return sunstreaker_get_font_stack(0);

  foreach (sunstreaker_all_font_choices() as $choice) {
    $candidate = isset($choice['family']) ? trim((string) $choice['family']) : '';
    if ($candidate !== '' && strcasecmp($candidate, $family) === 0) {
      $stack = isset($choice['stack']) ? trim((string) $choice['stack']) : '';
      if ($stack !== '') return $stack;
    }
  }

  return "\"".$family."\",sans-serif";
}

function sunstreaker_font_stylesheet_url(): string {
  return 'https://fonts.googleapis.com/css2?family=Graduate&family=Anton&family=Teko:wght@400;500;600;700&family=Source+Sans+3:wght@400;600;700&family=Montserrat:wght@400;500;600;700&family=Averia+Serif+Libre:wght@400;700&family=Baloo+2:wght@400;600;700&family=Original+Surfer&family=Caveat+Brush&family=Ravi+Prakash&family=Alex+Brush&family=Allura&display=swap';
}

function sunstreaker_custom_font_face_css(): string {
  if (!defined('SUNSTREAKER_PATH') || !defined('SUNSTREAKER_URL')) return '';

  $base_path = rtrim((string) SUNSTREAKER_PATH, '/').'/assets/fonts/';
  $base_url = rtrim((string) SUNSTREAKER_URL, '/').'/assets/fonts/';
  $families = [
    'Birds of Paradise' => [
      ['path' => $base_path.'birds-of-paradise.woff2', 'url' => $base_url.'birds-of-paradise.woff2', 'format' => 'woff2'],
      ['path' => $base_path.'birds-of-paradise.woff', 'url' => $base_url.'birds-of-paradise.woff', 'format' => 'woff'],
      ['path' => $base_path.'birds-of-paradise.otf', 'url' => $base_url.'birds-of-paradise.otf', 'format' => 'opentype'],
      ['path' => $base_path.'birds-of-paradise.ttf', 'url' => $base_url.'birds-of-paradise.ttf', 'format' => 'truetype'],
    ],
  ];

  $css_blocks = [];
  foreach ($families as $family => $candidates) {
    foreach ($candidates as $candidate) {
      $path = (string) ($candidate['path'] ?? '');
      $url = (string) ($candidate['url'] ?? '');
      $format = (string) ($candidate['format'] ?? 'truetype');
      if ($path === '' || $url === '' || !is_readable($path)) continue;

      $quoted_family = str_replace("'", "\\'", $family);
      $css_blocks[] = "@font-face {\n"
        ."  font-family: '".$quoted_family."';\n"
        ."  font-style: normal;\n"
        ."  font-weight: 400;\n"
        ."  font-display: swap;\n"
        ."  src: url('".esc_url_raw($url)."') format('".$format."');\n"
        ."}\n"
        ."@font-face {\n"
        ."  font-family: '".$quoted_family."';\n"
        ."  font-style: normal;\n"
        ."  font-weight: 700;\n"
        ."  font-display: swap;\n"
        ."  src: url('".esc_url_raw($url)."') format('".$format."');\n"
        ."}";
      break;
    }
  }

  return implode("\n", $css_blocks);
}

function sunstreaker_default_preview_boundaries(): array {
  return [
    'name' => [
      'x' => 0.22,
      'y' => 0.26,
      'w' => 0.56,
      'h' => 0.12,
    ],
    'number' => [
      'x' => 0.30,
      'y' => 0.41,
      'w' => 0.40,
      'h' => 0.24,
    ],
    'logo' => [
      'x' => 0.18,
      'y' => 0.20,
      'w' => 0.20,
      'h' => 0.20,
    ],
    'right_chest' => [
      'x' => 0.18,
      'y' => 0.20,
      'w' => 0.34,
      'h' => 0.12,
    ],
    'front' => [
      'x' => 0.20,
      'y' => 0.16,
      'w' => 0.24,
      'h' => 0.24,
    ],
    'back' => [
      'x' => 0.27,
      'y' => 0.22,
      'w' => 0.46,
      'h' => 0.52,
    ],
  ];
}

function sunstreaker_round_boundary_value(float $value): float {
  return round($value, 6);
}

function sunstreaker_sanitize_boundary_rect($rect, array $fallback): array {
  if (!is_array($rect)) return $fallback;

  $x = isset($rect['x']) ? (float) $rect['x'] : (float) $fallback['x'];
  $y = isset($rect['y']) ? (float) $rect['y'] : (float) $fallback['y'];
  $w = isset($rect['w']) ? (float) $rect['w'] : (float) $fallback['w'];
  $h = isset($rect['h']) ? (float) $rect['h'] : (float) $fallback['h'];

  if (!is_finite($x)) $x = (float) $fallback['x'];
  if (!is_finite($y)) $y = (float) $fallback['y'];
  if (!is_finite($w)) $w = (float) $fallback['w'];
  if (!is_finite($h)) $h = (float) $fallback['h'];

  $min_w = 0.05;
  $min_h = 0.05;

  $w = max($min_w, min(1.0, $w));
  $h = max($min_h, min(1.0, $h));
  $x = max(0.0, min(1.0 - $w, $x));
  $y = max(0.0, min(1.0 - $h, $y));

  return [
    'x' => sunstreaker_round_boundary_value($x),
    'y' => sunstreaker_round_boundary_value($y),
    'w' => sunstreaker_round_boundary_value($w),
    'h' => sunstreaker_round_boundary_value($h),
  ];
}

function sunstreaker_get_preview_boundaries($product_id): array {
  $product_id = sunstreaker_get_settings_product_id($product_id);
  $defaults = sunstreaker_default_preview_boundaries();
  if ($product_id <= 0) return $defaults;

  $boundaries = [];
  foreach ($defaults as $key => $fallback) {
    $stored = get_post_meta($product_id, '_sunstreaker_'.$key.'_boundary', true);
    $decoded = is_string($stored) && $stored !== '' ? json_decode($stored, true) : $stored;
    $boundaries[$key] = sunstreaker_sanitize_boundary_rect($decoded, $fallback);
  }

  return $boundaries;
}

function sunstreaker_get_product_preview_image_id($product, int $product_id): int {
  $image_id = 0;

  if ($product && is_a($product, 'WC_Product')) {
    $image_id = (int) $product->get_image_id();
    if ($image_id <= 0 && is_a($product, 'WC_Product_Variation')) {
      $image_id = (int) get_post_thumbnail_id((int) $product->get_parent_id());
    }
  }

  if ($image_id <= 0 && $product_id > 0) {
    $image_id = (int) get_post_thumbnail_id($product_id);
  }

  return max(0, $image_id);
}

function sunstreaker_get_product_preview_reference($product, int $product_id): array {
  $image_id = sunstreaker_get_product_preview_image_id($product, $product_id);
  $width = 1200;
  $height = 1200;

  if ($image_id > 0) {
    $meta = wp_get_attachment_metadata($image_id);
    if (is_array($meta)) {
      $meta_width = isset($meta['width']) ? (int) $meta['width'] : 0;
      $meta_height = isset($meta['height']) ? (int) $meta['height'] : 0;
      if ($meta_width > 0 && $meta_height > 0) {
        $width = $meta_width;
        $height = $meta_height;
      }
    }
  }

  return [
    'width' => $width,
    'height' => $height,
    'aspect_ratio' => $height / max(1, $width),
  ];
}

function sunstreaker_can_edit_product_boundaries(int $product_id): bool {
  if ($product_id <= 0) return false;
  if (current_user_can('edit_post', $product_id)) return true;
  return current_user_can('manage_woocommerce') || current_user_can('manage_options');
}

// Toggle + addon price (General tab) — modeled after Frenzy's "Use with Frenzy"
add_action('woocommerce_product_options_general_product_data', function () {
  echo '<div class="options_group">';

  $product_id = function_exists('get_the_ID') ? (int) get_the_ID() : 0;
  $addon_default = number_format(sunstreaker_get_addon_price($product_id), 2, '.', '');
  $ink_color_default = sunstreaker_get_ink_color($product_id);
  $use_name_number_default = sunstreaker_uses_name_number($product_id) ? 'yes' : 'no';
  $name_number_production_default = sunstreaker_get_feature_production_method($product_id, 'name_number');
  $use_logos_default = sunstreaker_uses_logos($product_id) ? 'yes' : 'no';
  $logo_price_default = number_format(sunstreaker_get_logo_price($product_id), 2, '.', '');
  $logos_production_default = sunstreaker_get_feature_production_method($product_id, 'logos');
  $use_right_chest_default = sunstreaker_uses_right_chest_text($product_id) ? 'yes' : 'no';
  $right_chest_price_default = number_format(sunstreaker_get_right_chest_price($product_id), 2, '.', '');
  $right_chest_production_default = sunstreaker_get_feature_production_method($product_id, 'right_chest');
  $use_front_back_default = sunstreaker_uses_front_back($product_id) ? 'yes' : 'no';
  $front_back_price_default = number_format(sunstreaker_get_front_back_price($product_id), 2, '.', '');
  $front_back_production_default = sunstreaker_get_feature_production_method($product_id, 'front_back');
  $selected_logo_ids = sunstreaker_get_logo_ids($product_id);

  woocommerce_wp_checkbox([
    'id'          => '_sunstreaker_enabled',
    'label'       => __('Use with Sunstreaker', 'sunstreaker'),
    'desc_tip'    => true,
    'description' => __('Enable Sunstreaker customization options for this product.', 'sunstreaker'),
  ]);

  echo '<div class="sunstreaker-addon-wrap" style="margin-top:8px;">';
  woocommerce_wp_checkbox([
    'id'          => '_sunstreaker_use_name_number',
    'label'       => __('Use Name/Number Customization', 'sunstreaker'),
    'value'       => $use_name_number_default,
    'desc_tip'    => true,
    'description' => __('Show the Name and Number customization card on the product page.', 'sunstreaker'),
  ]);
  echo '<div class="sunstreaker-feature-settings sunstreaker-name-number-settings">';
  woocommerce_wp_text_input([
    'id'                => '_sunstreaker_addon_price',
    'label'             => __('Price', 'sunstreaker'),
    'value'             => $addon_default,
    'desc_tip'          => true,
    'description'       => __('Extra cost added when Name and Number are used. Default: 5.00', 'sunstreaker'),
    'type'              => 'text',
    'custom_attributes' => [
      'inputmode' => 'decimal',
      'pattern'   => '^[0-9]+(\.[0-9]{1,2})?$',
    ],
  ]);
  woocommerce_wp_text_input([
    'id'          => '_sunstreaker_ink_color',
    'label'       => __('Ink color', 'sunstreaker'),
    'value'       => $ink_color_default,
    'desc_tip'    => true,
    'description' => __('Shown in the product note: "Add your Name and Number in [Ink color] to the back of the garment..."', 'sunstreaker'),
    'type'        => 'text',
  ]);
  woocommerce_wp_select([
    'id'          => '_sunstreaker_name_number_production',
    'label'       => __('Production Type', 'sunstreaker'),
    'value'       => $name_number_production_default,
    'options'     => sunstreaker_production_method_choices(),
    'desc_tip'    => true,
    'description' => __('Production method used when Name/Number customization is added to the garment.', 'sunstreaker'),
  ]);
  echo '</div>';
  woocommerce_wp_checkbox([
    'id'          => '_sunstreaker_use_logos',
    'label'       => __('Use logos', 'sunstreaker'),
    'value'       => $use_logos_default,
    'desc_tip'    => true,
    'description' => __('Show an optional customer logo selector on the product page and enable a draggable logo preview boundary.', 'sunstreaker'),
  ]);
  echo '<div class="sunstreaker-feature-settings sunstreaker-logo-library-wrap">';
  woocommerce_wp_text_input([
    'id'                => '_sunstreaker_logo_price',
    'label'             => __('Price', 'sunstreaker'),
    'value'             => $logo_price_default,
    'desc_tip'          => true,
    'description'       => __('Extra cost added when a logo is selected. Default: 14.00', 'sunstreaker'),
    'type'              => 'text',
    'custom_attributes' => [
      'inputmode' => 'decimal',
      'pattern'   => '^[0-9]+(\.[0-9]{1,2})?$',
    ],
  ]);
  woocommerce_wp_select([
    'id'          => '_sunstreaker_logos_production',
    'label'       => __('Production Type', 'sunstreaker'),
    'value'       => $logos_production_default,
    'options'     => sunstreaker_production_method_choices(),
    'desc_tip'    => true,
    'description' => __('Production method used when a customer selects one or more logos.', 'sunstreaker'),
  ]);
  echo '  <p class="form-field sunstreaker-logo-library">';
  echo '    <label for="_sunstreaker_logo_ids">'.esc_html__('Logos', 'sunstreaker').'</label>';
  echo '    <span class="wrap">';
  echo '      <input type="hidden" id="_sunstreaker_logo_ids" name="_sunstreaker_logo_ids" value="'.esc_attr(implode(',', $selected_logo_ids)).'" />';
  echo '      <button type="button" class="button sunstreaker-logo-library__select">'.esc_html__('Select logos', 'sunstreaker').'</button>';
  echo '      <button type="button" class="button-link-delete sunstreaker-logo-library__clear"'.(empty($selected_logo_ids) ? ' hidden' : '').'>'.esc_html__('Clear', 'sunstreaker').'</button>';
  echo '      <span class="description">'.esc_html__('Choose one or more media library logos that customers can select from the frontend drop-down.', 'sunstreaker').'</span>';
  echo '      <ul class="sunstreaker-logo-library__list"></ul>';
  echo '    </span>';
  echo '  </p>';
  echo '</div>';
  woocommerce_wp_checkbox([
    'id'          => '_sunstreaker_use_right_chest_text',
    'label'       => __('Use right chest text', 'sunstreaker'),
    'value'       => $use_right_chest_default,
    'desc_tip'    => true,
    'description' => __('Enable right chest text settings for this product.', 'sunstreaker'),
  ]);
  echo '<div class="sunstreaker-feature-settings sunstreaker-right-chest-settings">';
  woocommerce_wp_text_input([
    'id'                => '_sunstreaker_right_chest_price',
    'label'             => __('Price', 'sunstreaker'),
    'value'             => $right_chest_price_default,
    'desc_tip'          => true,
    'description'       => __('Extra cost added when right chest text is used. Default: 10.00', 'sunstreaker'),
    'type'              => 'text',
    'custom_attributes' => [
      'inputmode' => 'decimal',
      'pattern'   => '^[0-9]+(\.[0-9]{1,2})?$',
    ],
  ]);
  woocommerce_wp_select([
    'id'          => '_sunstreaker_right_chest_production',
    'label'       => __('Production Type', 'sunstreaker'),
    'value'       => $right_chest_production_default,
    'options'     => sunstreaker_production_method_choices(),
    'desc_tip'    => true,
    'description' => __('Production method used when right chest text is added.', 'sunstreaker'),
  ]);
  echo '</div>';
  woocommerce_wp_checkbox([
    'id'          => '_sunstreaker_use_front_back',
    'label'       => __('Use Front/Back Customization', 'sunstreaker'),
    'value'       => $use_front_back_default,
    'desc_tip'    => true,
    'description' => __('Enable uploaded artwork on the front and/or back with draggable preview boxes.', 'sunstreaker'),
  ]);
  echo '<div class="sunstreaker-feature-settings sunstreaker-front-back-settings">';
  woocommerce_wp_text_input([
    'id'                => '_sunstreaker_front_back_price',
    'label'             => __('Price', 'sunstreaker'),
    'value'             => $front_back_price_default,
    'desc_tip'          => true,
    'description'       => __('Extra cost added when front or back artwork is used. Default: 0.00', 'sunstreaker'),
    'type'              => 'text',
    'custom_attributes' => [
      'inputmode' => 'decimal',
      'pattern'   => '^[0-9]+(\.[0-9]{1,2})?$',
    ],
  ]);
  woocommerce_wp_select([
    'id'          => '_sunstreaker_front_back_production',
    'label'       => __('Production Type', 'sunstreaker'),
    'value'       => $front_back_production_default,
    'options'     => sunstreaker_production_method_choices(),
    'desc_tip'    => true,
    'description' => __('Production method used when uploaded front or back artwork is added.', 'sunstreaker'),
  ]);
  echo '</div>';
  echo '</div>';

  echo '</div>';
});

add_action('woocommerce_admin_process_product_object', function ($product) {
  if (!$product || !is_a($product, 'WC_Product')) return;

  $enabled = isset($_POST['_sunstreaker_enabled']) ? 'yes' : 'no';
  $product->update_meta_data('_sunstreaker_enabled', $enabled);

  $use_name_number = isset($_POST['_sunstreaker_use_name_number']) ? 'yes' : 'no';
  $product->update_meta_data('_sunstreaker_use_name_number', $use_name_number);

  $addon = isset($_POST['_sunstreaker_addon_price']) ? (string) wp_unslash($_POST['_sunstreaker_addon_price']) : '';
  $addon = preg_replace('/[^0-9.]/', '', $addon);
  if ($addon === '') $addon = '5.00';
  $addon_f = max(0.0, (float)$addon);
  $product->update_meta_data('_sunstreaker_addon_price', number_format($addon_f, 2, '.', ''));
  $name_number_production = isset($_POST['_sunstreaker_name_number_production']) ? wp_unslash($_POST['_sunstreaker_name_number_production']) : '';
  $product->update_meta_data('_sunstreaker_name_number_production', sunstreaker_sanitize_production_method($name_number_production, sunstreaker_get_feature_production_method($product->get_id(), 'name_number')));

  $ink_color = isset($_POST['_sunstreaker_ink_color']) ? (string) wp_unslash($_POST['_sunstreaker_ink_color']) : '';
  $ink_color = sanitize_text_field(trim($ink_color));
  if ($ink_color === '') $ink_color = 'White';
  $product->update_meta_data('_sunstreaker_ink_color', $ink_color);

  if (array_key_exists('_sunstreaker_font_choice', $_POST)) {
    $font_choice = isset($_POST['_sunstreaker_font_choice']) ? sanitize_key((string) wp_unslash($_POST['_sunstreaker_font_choice'])) : '';
    $product->update_meta_data('_sunstreaker_font_choice', sunstreaker_resolve_font_choice_key($font_choice, sunstreaker_get_font_choice_key($product->get_id())));
  }

  $use_logos = isset($_POST['_sunstreaker_use_logos']) ? 'yes' : 'no';
  $product->update_meta_data('_sunstreaker_use_logos', $use_logos);

  $logo_price = isset($_POST['_sunstreaker_logo_price']) ? (string) wp_unslash($_POST['_sunstreaker_logo_price']) : '';
  $logo_price = preg_replace('/[^0-9.]/', '', $logo_price);
  if ($logo_price === '') $logo_price = '14.00';
  $logo_price_f = max(0.0, (float) $logo_price);
  $product->update_meta_data('_sunstreaker_logo_price', number_format($logo_price_f, 2, '.', ''));
  $logos_production = isset($_POST['_sunstreaker_logos_production']) ? wp_unslash($_POST['_sunstreaker_logos_production']) : '';
  $product->update_meta_data('_sunstreaker_logos_production', sunstreaker_sanitize_production_method($logos_production, sunstreaker_get_feature_production_method($product->get_id(), 'logos')));

  $logo_ids = isset($_POST['_sunstreaker_logo_ids']) ? wp_unslash($_POST['_sunstreaker_logo_ids']) : '';
  $logo_ids = sunstreaker_sanitize_logo_ids($logo_ids);
  $product->update_meta_data('_sunstreaker_logo_ids', implode(',', $logo_ids));

  $use_right_chest_text = isset($_POST['_sunstreaker_use_right_chest_text']) ? 'yes' : 'no';
  $product->update_meta_data('_sunstreaker_use_right_chest_text', $use_right_chest_text);

  $right_chest_price = isset($_POST['_sunstreaker_right_chest_price']) ? (string) wp_unslash($_POST['_sunstreaker_right_chest_price']) : '';
  $right_chest_price = preg_replace('/[^0-9.]/', '', $right_chest_price);
  if ($right_chest_price === '') $right_chest_price = '10.00';
  $right_chest_price_f = max(0.0, (float) $right_chest_price);
  $product->update_meta_data('_sunstreaker_right_chest_price', number_format($right_chest_price_f, 2, '.', ''));
  $right_chest_production = isset($_POST['_sunstreaker_right_chest_production']) ? wp_unslash($_POST['_sunstreaker_right_chest_production']) : '';
  $product->update_meta_data('_sunstreaker_right_chest_production', sunstreaker_sanitize_production_method($right_chest_production, sunstreaker_get_feature_production_method($product->get_id(), 'right_chest')));

  $use_front_back = isset($_POST['_sunstreaker_use_front_back']) ? 'yes' : 'no';
  $product->update_meta_data('_sunstreaker_use_front_back', $use_front_back);

  $front_back_price = isset($_POST['_sunstreaker_front_back_price']) ? (string) wp_unslash($_POST['_sunstreaker_front_back_price']) : '';
  $front_back_price = preg_replace('/[^0-9.]/', '', $front_back_price);
  if ($front_back_price === '') $front_back_price = '0.00';
  $front_back_price_f = max(0.0, (float) $front_back_price);
  $product->update_meta_data('_sunstreaker_front_back_price', number_format($front_back_price_f, 2, '.', ''));
  $front_back_production = isset($_POST['_sunstreaker_front_back_production']) ? wp_unslash($_POST['_sunstreaker_front_back_production']) : '';
  $product->update_meta_data('_sunstreaker_front_back_production', sunstreaker_sanitize_production_method($front_back_production, sunstreaker_get_feature_production_method($product->get_id(), 'front_back')));

  if (array_key_exists('_sunstreaker_right_chest_font_choice', $_POST)) {
    $right_chest_font_choice = isset($_POST['_sunstreaker_right_chest_font_choice']) ? sanitize_key((string) wp_unslash($_POST['_sunstreaker_right_chest_font_choice'])) : '';
    $product->update_meta_data('_sunstreaker_right_chest_font_choice', sunstreaker_resolve_right_chest_font_choice_key($right_chest_font_choice, sunstreaker_get_right_chest_font_choice_key($product->get_id())));
  }
});

add_action('admin_enqueue_scripts', function($hook){
  if ($hook !== 'post.php' && $hook !== 'post-new.php') return;
  $screen = function_exists('get_current_screen') ? get_current_screen() : null;
  if (!$screen || $screen->post_type !== 'product') return;

  $product_id = isset($_GET['post']) ? absint($_GET['post']) : 0;

  wp_enqueue_style('sunstreaker-fonts-admin', sunstreaker_font_stylesheet_url(), [], null);
  $custom_font_face_css = trim((string) sunstreaker_custom_font_face_css());
  if ($custom_font_face_css !== '') {
    wp_add_inline_style('sunstreaker-fonts-admin', $custom_font_face_css);
  }
  wp_enqueue_media();

  $css_path = SUNSTREAKER_PATH.'assets/product.edit.css';
  if (file_exists($css_path)) {
    wp_enqueue_style('sunstreaker-product-edit', SUNSTREAKER_URL.'assets/product.edit.css', [], (string) filemtime($css_path));
  }

  $path = SUNSTREAKER_PATH.'assets/product.edit.js';
  $ver = SUNSTREAKER_VERSION;
  if (file_exists($path)) $ver = (string) filemtime($path);

  wp_enqueue_script('sunstreaker-product-edit', SUNSTREAKER_URL.'assets/product.edit.js', ['jquery'], $ver, true);
  wp_localize_script('sunstreaker-product-edit', 'sunstreakerProductEdit', [
    'fonts' => array_merge(sunstreaker_font_choices(), sunstreaker_right_chest_font_choices()),
    'selectedLogos' => $product_id > 0 ? sunstreaker_get_logo_choices($product_id) : [],
    'strings' => [
      'chooseLogos' => __('Choose logos', 'sunstreaker'),
      'useSelected' => __('Use selected logos', 'sunstreaker'),
      'emptyLogos' => __('No logos selected yet.', 'sunstreaker'),
      'removeLogo' => __('Remove logo', 'sunstreaker'),
    ],
  ]);
});

add_action('wp_ajax_sunstreaker_get_boundaries', function () {
  $nonce = isset($_POST['_ajax_nonce']) ? (string) wp_unslash($_POST['_ajax_nonce']) : '';
  if (!wp_verify_nonce($nonce, 'sunstreaker_boundaries')) {
    wp_send_json_error(['message' => 'Bad nonce'], 403);
  }

  $product_id = absint($_POST['product_id'] ?? 0);
  if ($product_id <= 0 || get_post_type($product_id) !== 'product') {
    wp_send_json_error(['message' => 'Invalid product'], 400);
  }
  if (!sunstreaker_can_edit_product_boundaries($product_id)) {
    wp_send_json_error(['message' => 'Unauthorized'], 403);
  }

  wp_send_json_success([
    'boundaries' => sunstreaker_get_preview_boundaries($product_id),
  ]);
});

add_action('wp_ajax_sunstreaker_save_boundaries', function () {
  $nonce = isset($_POST['_ajax_nonce']) ? (string) wp_unslash($_POST['_ajax_nonce']) : '';
  if (!wp_verify_nonce($nonce, 'sunstreaker_boundaries')) {
    wp_send_json_error(['message' => 'Bad nonce'], 403);
  }

  $product_id = absint($_POST['product_id'] ?? 0);
  if ($product_id <= 0 || get_post_type($product_id) !== 'product') {
    wp_send_json_error(['message' => 'Invalid product'], 400);
  }
  if (!sunstreaker_can_edit_product_boundaries($product_id)) {
    wp_send_json_error(['message' => 'Unauthorized'], 403);
  }

  $defaults = sunstreaker_default_preview_boundaries();
  $boundaries = [];
  foreach ($defaults as $key => $fallback) {
    $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;
    $boundaries[$key] = sunstreaker_sanitize_boundary_rect($decoded, $fallback);
    update_post_meta($product_id, '_sunstreaker_'.$key.'_boundary', wp_json_encode($boundaries[$key]));
  }

  wp_send_json_success([
    'boundaries' => $boundaries,
  ]);
});
