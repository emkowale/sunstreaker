<?php
if (!defined('ABSPATH')) exit;

function sunstreaker_get_current_product_page_id(): int {
  if (!function_exists('is_product') || !is_product()) return 0;
  return function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
}

add_filter('body_class', function(array $classes): array {
  $product_id = sunstreaker_get_current_product_page_id();
  if ($product_id > 0 && sunstreaker_is_enabled_for_product($product_id)) {
    $classes[] = 'sunstreaker-enabled-product';
  }
  return $classes;
});

add_filter('woocommerce_single_product_zoom_enabled', function($enabled) {
  $product_id = sunstreaker_get_current_product_page_id();
  if ($product_id > 0 && sunstreaker_is_enabled_for_product($product_id)) {
    return false;
  }
  return $enabled;
}, 20);

add_action('wp_enqueue_scripts', function(){
  $is_product  = function_exists('is_product') && is_product();
  $path = SUNSTREAKER_PATH.'assets/frontend.css';
  if (!file_exists($path)) return;
  $ver = (string) filemtime($path);
  wp_enqueue_style('sunstreaker-frontend', SUNSTREAKER_URL.'assets/frontend.css', [], $ver);

  if (function_exists('sunstreaker_font_stylesheet_url')) {
    wp_enqueue_style('sunstreaker-fonts', sunstreaker_font_stylesheet_url(), [], null);
    if (function_exists('sunstreaker_custom_font_face_css')) {
      $custom_font_face_css = trim((string) sunstreaker_custom_font_face_css());
      if ($custom_font_face_css !== '') {
        wp_add_inline_style('sunstreaker-fonts', $custom_font_face_css);
      }
    }
  }

  if (!$is_product) return;

  $product_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
  if ($product_id <= 0 || !sunstreaker_is_enabled_for_product($product_id)) return;

  $script_path = SUNSTREAKER_PATH.'assets/frontend.js';
  if (!file_exists($script_path)) return;
  $script_ver = (string) filemtime($script_path);

  wp_enqueue_script('sunstreaker-frontend', SUNSTREAKER_URL.'assets/frontend.js', [], $script_ver, true);

  $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
  $ink_color = function_exists('sunstreaker_get_ink_color') ? sunstreaker_get_ink_color($product_id) : 'White';
  $is_variable_product = $product && is_a($product, 'WC_Product') && $product->is_type('variable');
  $base_display_price = null;
  $base_display_regular_price = null;
  if (function_exists('sunstreaker_normalize_ink_color')) {
    $ink_color = sunstreaker_normalize_ink_color($ink_color);
  }
  $use_name_number = function_exists('sunstreaker_uses_name_number') ? sunstreaker_uses_name_number($product_id) : true;
  $logos = function_exists('sunstreaker_get_logo_choices') ? sunstreaker_get_logo_choices($product_id) : [];
  $logo_locations = function_exists('sunstreaker_get_enabled_logo_location_settings') ? sunstreaker_get_enabled_logo_location_settings($product_id) : [];
  $use_logos = function_exists('sunstreaker_uses_logos') ? sunstreaker_uses_logos($product_id) : false;
  $use_right_chest_text = function_exists('sunstreaker_uses_right_chest_text') ? sunstreaker_uses_right_chest_text($product_id) : false;
  $use_front_back = function_exists('sunstreaker_uses_front_back') ? sunstreaker_uses_front_back($product_id) : false;

  if ($use_front_back) {
    $front_back_script_path = SUNSTREAKER_PATH.'assets/front-back.js';
    if (file_exists($front_back_script_path)) {
      wp_enqueue_script('sunstreaker-front-back', SUNSTREAKER_URL.'assets/front-back.js', ['sunstreaker-frontend'], (string) filemtime($front_back_script_path), true);
    }
  }

  if ($product && is_a($product, 'WC_Product') && !$is_variable_product) {
    $base_display_price = (float) wc_get_price_to_display($product);
    $regular_price_raw = $product->get_regular_price();
    $base_display_regular_price = $regular_price_raw !== ''
      ? (float) wc_get_price_to_display($product, ['price' => (float) $regular_price_raw])
      : $base_display_price;
  }

  wp_localize_script('sunstreaker-frontend', 'sunstreakerPreview', [
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('sunstreaker_boundaries'),
    'productId' => $product_id,
    'boundaries' => function_exists('sunstreaker_get_preview_boundaries')
      ? sunstreaker_get_preview_boundaries($product_id)
      : [],
    'defaults' => function_exists('sunstreaker_default_preview_boundaries')
      ? sunstreaker_default_preview_boundaries()
      : [],
    'canEdit' => function_exists('sunstreaker_can_edit_product_boundaries')
      ? sunstreaker_can_edit_product_boundaries($product_id)
      : false,
    'canSaveMockup' => is_user_logged_in(),
    'inkColor' => $ink_color,
    'fontStack' => function_exists('sunstreaker_get_font_stack')
      ? sunstreaker_get_font_stack($product_id)
      : "\"Varsity Block\",\"Freshman\",\"College\",\"Oswald\",\"Arial Black\",sans-serif",
    'rightChestFontStack' => function_exists('sunstreaker_get_right_chest_font_stack')
      ? sunstreaker_get_right_chest_font_stack($product_id)
      : (function_exists('sunstreaker_get_font_stack')
        ? sunstreaker_get_font_stack($product_id)
        : "\"Montserrat\",\"Helvetica Neue\",Helvetica,Arial,sans-serif"),
    'useNameNumber' => $use_name_number,
    'useLogos' => $use_logos,
    'useRightChestText' => $use_right_chest_text,
    'useFrontBack' => $use_front_back,
    'uploadAction' => 'sunstreaker_upload_front_back_art',
    'logos' => $logos,
    'logoLocations' => array_reduce(array_keys($logo_locations), static function(array $carry, string $location_key) use ($product_id, $logo_locations): array {
      $carry[$location_key] = [
        'label' => function_exists('sunstreaker_get_logo_location_label')
          ? sunstreaker_get_logo_location_label($location_key)
          : ucwords(str_replace('_', ' ', $location_key)),
        'price' => isset($logo_locations[$location_key]['price']) ? (string) $logo_locations[$location_key]['price'] : '0.00',
        'production' => isset($logo_locations[$location_key]['production']) ? (string) $logo_locations[$location_key]['production'] : '',
        'logos' => function_exists('sunstreaker_get_logo_location_choices')
          ? sunstreaker_get_logo_location_choices($product_id, $location_key)
          : [],
      ];
      return $carry;
    }, []),
    'previewDefaults' => [
      'name' => $use_name_number ? 'YOUR NAME' : '',
      'number' => $use_name_number ? '26' : '',
      'rightChestNameCredentials' => '',
      'rightChestDepartment' => '',
    ],
    'previewReference' => function_exists('sunstreaker_get_product_preview_reference')
      ? sunstreaker_get_product_preview_reference($product, $product_id)
      : ['width' => 1200, 'height' => 1200, 'aspect_ratio' => 1],
    'constraints' => [
      'rightChestMaxWidthIn' => function_exists('sunstreaker_right_chest_max_width_in')
        ? (float) sunstreaker_right_chest_max_width_in()
        : 4.0,
      'rightChestMinLetterHeightIn' => function_exists('sunstreaker_right_chest_min_letter_height_in')
        ? (float) sunstreaker_right_chest_min_letter_height_in()
        : 0.25,
    ],
    'pricing' => [
      'enabled' => true,
      'isVariableProduct' => $is_variable_product,
      'baseDisplayPrice' => $base_display_price,
      'baseDisplayRegularPrice' => $base_display_regular_price,
      'nameNumberPrice' => (float) (function_exists('sunstreaker_get_addon_price') ? sunstreaker_get_addon_price($product_id) : 0.0),
      'rightChestPrice' => (float) (function_exists('sunstreaker_get_right_chest_price') ? sunstreaker_get_right_chest_price($product_id) : 0.0),
      'frontBackPrice' => (float) (function_exists('sunstreaker_get_front_back_price') ? sunstreaker_get_front_back_price($product_id) : 0.0),
      'priceFormat' => function_exists('get_woocommerce_price_format') ? (string) get_woocommerce_price_format() : '%1$s%2$s',
      'currencySymbol' => function_exists('get_woocommerce_currency_symbol') ? (string) get_woocommerce_currency_symbol() : '$',
      'decimalSeparator' => function_exists('wc_get_price_decimal_separator') ? (string) wc_get_price_decimal_separator() : '.',
      'thousandSeparator' => function_exists('wc_get_price_thousand_separator') ? (string) wc_get_price_thousand_separator() : ',',
      'decimals' => function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2,
      'trimZeros' => (bool) apply_filters('woocommerce_price_trim_zeros', false),
    ],
    'strings' => [
      'adjust' => __('Adjust Boundaries', 'sunstreaker'),
      'saveMockup' => __('Save Mockup', 'sunstreaker'),
      'save' => __('Save Boundaries', 'sunstreaker'),
      'cancel' => __('Cancel', 'sunstreaker'),
      'saving' => __('Saving…', 'sunstreaker'),
      'savingMockup' => __('Preparing mockup…', 'sunstreaker'),
      'savedMockup' => __('Mockup downloaded.', 'sunstreaker'),
      'saveMockupError' => __('Could not generate the mockup.', 'sunstreaker'),
      'saved' => __('Saved', 'sunstreaker'),
      'loading' => __('Loading saved boundaries…', 'sunstreaker'),
      'loadError' => __('Could not load boundaries.', 'sunstreaker'),
      'saveError' => __('Could not save boundaries.', 'sunstreaker'),
      'noImage' => __('No product image found.', 'sunstreaker'),
      'uploadingArt' => __('Uploading artwork…', 'sunstreaker'),
      'uploadArtError' => __('Could not upload artwork.', 'sunstreaker'),
      'artUploadPending' => __('Artwork is still uploading. Please wait.', 'sunstreaker'),
      'artRemoved' => __('Artwork removed.', 'sunstreaker'),
    ],
  ]);
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

function sunstreaker_get_posted_scrubs_choice(int $product_id = 0): string {
  $raw = isset($_POST['sunstreaker_scrubs']) ? (string) wp_unslash($_POST['sunstreaker_scrubs']) : '';
  $raw = strtolower(trim($raw));
  if ($raw === 'yes' || $raw === 'no') return $raw;

  $scrub_fields = function_exists('sunstreaker_get_scrub_attributes_for_product')
    ? sunstreaker_get_scrub_attributes_for_product($product_id)
    : [];

  foreach ($scrub_fields as $field) {
    $names = [];
    if (!empty($field['input_name'])) {
      $names[] = (string) $field['input_name'];
    }
    if (!empty($field['attribute_name'])) {
      $names[] = 'attribute_'.(string) $field['attribute_name'];
    }

    foreach ($names as $name) {
      $value = isset($_POST[$name]) ? trim((string) wp_unslash($_POST[$name])) : '';
      if ($value !== '') return 'yes';
    }
  }

  return 'no';
}

function sunstreaker_get_posted_scrub_values(int $product_id = 0): array {
  $selected = [];
  $scrub_fields = function_exists('sunstreaker_get_scrub_attributes_for_product')
    ? sunstreaker_get_scrub_attributes_for_product($product_id)
    : [];

  foreach ($scrub_fields as $field_key => $field) {
    $input_name = !empty($field['input_name'])
      ? (string) $field['input_name']
      : (function_exists('sunstreaker_scrub_attribute_input_name')
        ? sunstreaker_scrub_attribute_input_name((string) $field_key)
        : 'sunstreaker_scrub_'.sanitize_key((string) $field_key));

    $raw_value = isset($_POST[$input_name]) ? (string) wp_unslash($_POST[$input_name]) : '';
    if ($raw_value === '' && !empty($field['attribute_name'])) {
      $native_input_name = 'attribute_'.(string) $field['attribute_name'];
      $raw_value = isset($_POST[$native_input_name]) ? (string) wp_unslash($_POST[$native_input_name]) : '';
    }

    $value = function_exists('sunstreaker_resolve_scrub_option_value')
      ? sunstreaker_resolve_scrub_option_value($raw_value, isset($field['options']) && is_array($field['options']) ? $field['options'] : [])
      : sanitize_text_field(trim($raw_value));

    if ($value !== '') {
      $selected[$field_key] = $value;
    }
  }

  return $selected;
}

function sunstreaker_get_posted_font_choice_key(int $product_id = 0): string {
  $fallback = function_exists('sunstreaker_get_name_number_font_choice_key')
    ? sunstreaker_get_name_number_font_choice_key($product_id)
    : 'varsity_block';
  $raw = isset($_POST['sunstreaker_font_choice']) ? (string) wp_unslash($_POST['sunstreaker_font_choice']) : '';
  return function_exists('sunstreaker_resolve_name_number_font_choice_key')
    ? sunstreaker_resolve_name_number_font_choice_key($raw, $fallback)
    : sanitize_key($raw !== '' ? $raw : $fallback);
}

function sunstreaker_get_posted_right_chest_font_choice_key(int $product_id = 0): string {
  $fallback = function_exists('sunstreaker_get_right_chest_font_choice_key')
    ? sunstreaker_get_right_chest_font_choice_key($product_id)
    : 'montserrat';
  $raw = isset($_POST['sunstreaker_right_chest_font_choice']) ? (string) wp_unslash($_POST['sunstreaker_right_chest_font_choice']) : '';
  return function_exists('sunstreaker_resolve_right_chest_font_choice_key')
    ? sunstreaker_resolve_right_chest_font_choice_key($raw, $fallback)
    : sanitize_key($raw !== '' ? $raw : $fallback);
}

function sunstreaker_get_posted_logo_ids(): array {
  if (isset($_POST['sunstreaker_logo_id'])) {
    $logo_id = absint(wp_unslash($_POST['sunstreaker_logo_id']));
    return $logo_id > 0 ? [$logo_id] : [];
  }

  $raw = isset($_POST['sunstreaker_logo_ids']) ? wp_unslash($_POST['sunstreaker_logo_ids']) : [];
  if (function_exists('sunstreaker_sanitize_logo_ids')) {
    return sunstreaker_sanitize_logo_ids($raw);
  }

  if (!is_array($raw)) return [];
  return array_values(array_filter(array_map('absint', $raw)));
}

function sunstreaker_get_posted_logo_id(): int {
  $ids = sunstreaker_get_posted_logo_ids();
  return !empty($ids) ? (int) $ids[0] : 0;
}

function sunstreaker_get_posted_logo_location_choices(int $product_id = 0): array {
  $choices = [];
  $allowed_locations = function_exists('sunstreaker_get_enabled_logo_location_settings')
    ? sunstreaker_get_enabled_logo_location_settings($product_id)
    : [];
  $posted_logo_ids = isset($_POST['sunstreaker_logo_location_logo_id']) && is_array($_POST['sunstreaker_logo_location_logo_id'])
    ? wp_unslash($_POST['sunstreaker_logo_location_logo_id'])
    : [];

  foreach ($allowed_locations as $location_key => $location_settings) {
    $logo_id = isset($posted_logo_ids[$location_key]) ? absint($posted_logo_ids[$location_key]) : 0;
    if ($logo_id <= 0) continue;

    $choice = function_exists('sunstreaker_get_logo_choice')
      ? sunstreaker_get_logo_choice($product_id, $logo_id)
      : [];
    if (empty($choice)) continue;

    $choices[$location_key] = [
      'location_key' => $location_key,
      'location_label' => function_exists('sunstreaker_get_logo_location_label')
        ? sunstreaker_get_logo_location_label($location_key)
        : ucwords(str_replace('_', ' ', $location_key)),
      'logo_id' => $logo_id,
      'logo' => $choice,
      'price' => isset($location_settings['price']) ? max(0.0, (float) $location_settings['price']) : 0.0,
      'production' => isset($location_settings['production']) ? (string) $location_settings['production'] : '',
    ];
  }

  return $choices;
}

function sunstreaker_get_posted_right_chest_name_credentials(): string {
  $value = isset($_POST['sunstreaker_right_chest_name_credentials']) ? (string) wp_unslash($_POST['sunstreaker_right_chest_name_credentials']) : '';
  $value = trim(preg_replace('/\s+/', ' ', $value));
  return function_exists('mb_substr') ? mb_substr($value, 0, 60) : substr($value, 0, 60);
}

function sunstreaker_get_posted_right_chest_department(): string {
  $value = isset($_POST['sunstreaker_right_chest_department']) ? (string) wp_unslash($_POST['sunstreaker_right_chest_department']) : '';
  $value = trim(preg_replace('/\s+/', ' ', $value));
  return function_exists('mb_substr') ? mb_substr($value, 0, 60) : substr($value, 0, 60);
}

function sunstreaker_get_posted_preview_image_url(): string {
  $url = isset($_POST['sunstreaker_preview_image_url']) ? (string) wp_unslash($_POST['sunstreaker_preview_image_url']) : '';
  $url = trim($url);
  return $url !== '' ? esc_url_raw($url) : '';
}

function sunstreaker_get_posted_preview_image_width(): int {
  return isset($_POST['sunstreaker_preview_image_width']) ? max(0, absint(wp_unslash($_POST['sunstreaker_preview_image_width']))) : 0;
}

function sunstreaker_get_posted_preview_image_height(): int {
  return isset($_POST['sunstreaker_preview_image_height']) ? max(0, absint(wp_unslash($_POST['sunstreaker_preview_image_height']))) : 0;
}

function sunstreaker_sanitize_front_back_transform($raw): array {
  if (is_string($raw)) {
    $decoded = json_decode(wp_unslash($raw), true);
    if (is_array($decoded)) $raw = $decoded;
  }

  if (!is_array($raw)) return [];

  $x = isset($raw['x']) ? (float) $raw['x'] : 0.0;
  $y = isset($raw['y']) ? (float) $raw['y'] : 0.0;
  $w = isset($raw['w']) ? (float) $raw['w'] : 1.0;
  $h = isset($raw['h']) ? (float) $raw['h'] : 1.0;

  if (!is_finite($x)) $x = 0.0;
  if (!is_finite($y)) $y = 0.0;
  if (!is_finite($w)) $w = 1.0;
  if (!is_finite($h)) $h = 1.0;

  $w = max(0.05, min(1.0, $w));
  $h = max(0.05, min(1.0, $h));
  $x = max(0.0, min(1.0 - $w, $x));
  $y = max(0.0, min(1.0 - $h, $y));

  return [
    'x' => round($x, 6),
    'y' => round($y, 6),
    'w' => round($w, 6),
    'h' => round($h, 6),
  ];
}

function sunstreaker_is_allowed_front_back_art_url(string $url): bool {
  $url = trim($url);
  if ($url === '') return false;

  $normalized = esc_url_raw($url);
  if ($normalized === '') return false;

  $uploads = wp_upload_dir(null, false);
  $baseurl = isset($uploads['baseurl']) ? trailingslashit((string) $uploads['baseurl']) : '';
  if ($baseurl === '') return false;

  return strpos($normalized, $baseurl) === 0;
}

function sunstreaker_get_posted_front_back_art_url(string $field): string {
  if ($field !== 'front' && $field !== 'back') return '';
  $key = 'sunstreaker_'.$field.'_art_url';
  $url = isset($_POST[$key]) ? (string) wp_unslash($_POST[$key]) : '';
  $url = trim($url);
  if ($url === '') return '';

  $url = esc_url_raw($url);
  if ($url === '' || !sunstreaker_is_allowed_front_back_art_url($url)) return '';

  return $url;
}

function sunstreaker_get_posted_front_back_transform(string $field): array {
  if ($field !== 'front' && $field !== 'back') return [];
  $key = 'sunstreaker_'.$field.'_transform';
  $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
  return sunstreaker_sanitize_front_back_transform($raw);
}

function sunstreaker_get_posted_front_back_transform_json(string $field): string {
  $transform = sunstreaker_get_posted_front_back_transform($field);
  return !empty($transform) ? wp_json_encode($transform) : '';
}

add_action('woocommerce_before_add_to_cart_button', function(){
  global $product;
  if (!$product || !is_a($product, 'WC_Product')) return;
  $product_id = $product->get_id();
  if (!sunstreaker_is_enabled_for_product($product_id)) return;

  $posted_name = esc_attr(sunstreaker_get_posted_name());
  $posted_num  = esc_attr(sunstreaker_get_posted_number());
  $posted_font_choice_key = function_exists('sunstreaker_get_posted_font_choice_key') ? sunstreaker_get_posted_font_choice_key($product_id) : 'varsity_block';
  $posted_logo_locations = function_exists('sunstreaker_get_posted_logo_location_choices') ? sunstreaker_get_posted_logo_location_choices($product_id) : [];
  $posted_logo_id = !empty($posted_logo_locations) ? (int) (($posted_logo_locations[array_key_first($posted_logo_locations)]['logo_id'] ?? 0)) : (function_exists('sunstreaker_get_posted_logo_id') ? sunstreaker_get_posted_logo_id() : 0);
  $posted_right_chest_name = esc_attr(function_exists('sunstreaker_get_posted_right_chest_name_credentials') ? sunstreaker_get_posted_right_chest_name_credentials() : '');
  $posted_right_chest_department = esc_attr(function_exists('sunstreaker_get_posted_right_chest_department') ? sunstreaker_get_posted_right_chest_department() : '');
  $posted_right_chest_font_choice_key = function_exists('sunstreaker_get_posted_right_chest_font_choice_key') ? sunstreaker_get_posted_right_chest_font_choice_key($product_id) : 'montserrat';
  $addon_price = sunstreaker_get_addon_price($product_id);
  $right_chest_price = function_exists('sunstreaker_get_right_chest_price') ? sunstreaker_get_right_chest_price($product_id) : 10.00;
  $front_back_price = function_exists('sunstreaker_get_front_back_price') ? sunstreaker_get_front_back_price($product_id) : 0.00;
  $ink_color   = function_exists('sunstreaker_get_ink_color') ? sunstreaker_get_ink_color($product_id) : 'White';
  $use_name_number = function_exists('sunstreaker_uses_name_number') ? sunstreaker_uses_name_number($product_id) : true;
  $use_logos   = function_exists('sunstreaker_uses_logos') ? sunstreaker_uses_logos($product_id) : false;
  $use_right_chest_text = function_exists('sunstreaker_uses_right_chest_text') ? sunstreaker_uses_right_chest_text($product_id) : false;
  $use_front_back = function_exists('sunstreaker_uses_front_back') ? sunstreaker_uses_front_back($product_id) : false;
  $scrub_fields = function_exists('sunstreaker_get_scrub_attributes_for_product') ? sunstreaker_get_scrub_attributes_for_product($product_id) : [];
  $posted_scrubs_choice = function_exists('sunstreaker_get_posted_scrubs_choice') ? sunstreaker_get_posted_scrubs_choice($product_id) : 'no';
  $posted_scrub_values = function_exists('sunstreaker_get_posted_scrub_values') ? sunstreaker_get_posted_scrub_values($product_id) : [];
  $logo_location_settings = function_exists('sunstreaker_get_enabled_logo_location_settings') ? sunstreaker_get_enabled_logo_location_settings($product_id) : [];
  $font_choices = function_exists('sunstreaker_right_chest_font_choices') ? sunstreaker_right_chest_font_choices() : [];
  $name_number_font_choices = function_exists('sunstreaker_name_number_font_choices') ? sunstreaker_name_number_font_choices() : $font_choices;
  $posted_front_art_url = esc_url(function_exists('sunstreaker_get_posted_front_back_art_url') ? sunstreaker_get_posted_front_back_art_url('front') : '');
  $posted_back_art_url = esc_url(function_exists('sunstreaker_get_posted_front_back_art_url') ? sunstreaker_get_posted_front_back_art_url('back') : '');
  $posted_front_transform = esc_attr(function_exists('sunstreaker_get_posted_front_back_transform_json') ? sunstreaker_get_posted_front_back_transform_json('front') : '');
  $posted_back_transform = esc_attr(function_exists('sunstreaker_get_posted_front_back_transform_json') ? sunstreaker_get_posted_front_back_transform_json('back') : '');
  $addon_label = function_exists('wc_price')
    ? wc_price($addon_price)
    : '$'.number_format($addon_price, 2, '.', '');
  $right_chest_price_label = function_exists('wc_price')
    ? wc_price($right_chest_price)
    : '$'.number_format($right_chest_price, 2, '.', '');
  $note_html = sprintf(
    __('Add your Name and Number in %1$s to the back of the garment for %2$s.', 'sunstreaker'),
    esc_html($ink_color),
    $addon_label
  );
  $logo_note_html = __('Select your designs below', 'sunstreaker');
  $right_chest_note_html = sprintf(
    __('Add your Name/Credentials/Department for %s.', 'sunstreaker'),
    $right_chest_price_label
  );
  $front_back_note_html = __('Upload artwork for the front and/or back of the garment.', 'sunstreaker');

  if (!empty($scrub_fields)) {
    echo '<div class="sunstreaker-scrubs'.($posted_scrubs_choice === 'yes' ? ' is-active' : '').'" data-sunstreaker-scrubs>';
    echo '  <div class="sunstreaker-scrubs__header">';
    echo '    <span class="sunstreaker-scrubs__title">'.esc_html__('Scrubs', 'sunstreaker').'</span>';
    echo '    <div class="sunstreaker-scrubs__toggle" role="radiogroup" aria-label="'.esc_attr__('Scrubs', 'sunstreaker').'">';

    foreach ([
      'no' => __('No', 'sunstreaker'),
      'yes' => __('Yes', 'sunstreaker'),
    ] as $choice_key => $choice_label) {
      $input_id = 'sunstreaker_scrubs_'.$choice_key;
      echo '      <label class="sunstreaker-scrubs__toggle-option" for="'.esc_attr($input_id).'">';
      echo '        <input type="radio" class="sunstreaker-scrubs__toggle-input" id="'.esc_attr($input_id).'" name="sunstreaker_scrubs" value="'.esc_attr($choice_key).'" '.checked($posted_scrubs_choice, $choice_key, false).' />';
      echo '        <span class="sunstreaker-scrubs__toggle-label">'.esc_html($choice_label).'</span>';
      echo '      </label>';
    }

    echo '    </div>';
    echo '  </div>';
    echo '  <div class="sunstreaker-scrubs__card" data-sunstreaker-scrubs-card aria-hidden="'.($posted_scrubs_choice === 'yes' ? 'false' : 'true').'">';

    foreach ($scrub_fields as $field_key => $field) {
      $field_id = 'sunstreaker_scrub_'.sanitize_key((string) $field_key);
      $field_label = isset($field['label']) ? (string) $field['label'] : (function_exists('sunstreaker_scrub_attribute_field_label') ? sunstreaker_scrub_attribute_field_label((string) $field_key) : ucwords(str_replace('_', ' ', (string) $field_key)));
      $field_name = isset($field['input_name']) ? (string) $field['input_name'] : $field_id;
      $field_value = isset($posted_scrub_values[$field_key]) ? (string) $posted_scrub_values[$field_key] : '';
      $native_name = !empty($field['attribute_name']) ? 'attribute_'.(string) $field['attribute_name'] : '';

      echo '    <div class="sunstreaker-field sunstreaker-scrubs__field" data-scrub-field="'.esc_attr((string) $field_key).'"'.($native_name !== '' ? ' data-native-name="'.esc_attr($native_name).'"' : '').'>';
      echo '      <label for="'.esc_attr($field_id).'">'.esc_html($field_label).'</label>';
      echo '      <select class="sunstreaker-select sunstreaker-scrubs__select" id="'.esc_attr($field_id).'" name="'.esc_attr($field_name).'"'.($posted_scrubs_choice === 'yes' ? ' required' : ' disabled').' data-scrub-select="'.esc_attr((string) $field_key).'">';
      echo '        <option value="">'.esc_html(sprintf(__('Choose %s', 'sunstreaker'), $field_label)).'</option>';

      if (!empty($field['options']) && is_array($field['options'])) {
        foreach ($field['options'] as $option_label) {
          $option_label = sanitize_text_field(trim((string) $option_label));
          if ($option_label === '') continue;
          echo '        <option value="'.esc_attr($option_label).'" '.selected($field_value, $option_label, false).'>'.esc_html($option_label).'</option>';
        }
      }

      echo '      </select>';
      echo '    </div>';
    }

    echo '  </div>';
    echo '</div>';
  }

  if ($use_name_number) {
    echo '<div class="sunstreaker-fields">';
    echo '  <p class="sunstreaker-note">'.wp_kses_post($note_html).'</p>';
    echo '  <div class="sunstreaker-field">';
    echo '    <label for="sunstreaker_name">Name</label>';
    echo '    <input type="text" class="sunstreaker-text-input input-text text" id="sunstreaker_name" name="sunstreaker_name" value="'.$posted_name.'" maxlength="20" placeholder="YOUR NAME" />';
    echo '  </div>';
    echo '  <div class="sunstreaker-field">';
    echo '    <label for="sunstreaker_number">Number</label>';
    echo '    <input type="text" class="sunstreaker-text-input input-text text" id="sunstreaker_number" name="sunstreaker_number" value="'.$posted_num.'" inputmode="numeric" pattern="^[0-9]{1,2}$" maxlength="2" placeholder="26" />';
    echo '  </div>';
    if (!empty($name_number_font_choices)) {
      echo '  <div class="sunstreaker-field">';
      echo '    <label for="sunstreaker_font_choice">Font</label>';
      echo '    <select class="sunstreaker-select sunstreaker-font-select" id="sunstreaker_font_choice" name="sunstreaker_font_choice">';
      foreach ($name_number_font_choices as $choice_key => $choice) {
        $label = isset($choice['label']) ? (string) $choice['label'] : (string) $choice_key;
        $stack = isset($choice['stack']) ? (string) $choice['stack'] : '';
        echo '      <option value="'.esc_attr($choice_key).'" data-font-stack="'.esc_attr($stack).'" style="font-family:'.esc_attr($stack).';" '.selected($posted_font_choice_key, $choice_key, false).'>'.esc_html($label).'</option>';
      }
      echo '    </select>';
      echo '  </div>';
    }
    echo '</div>';
  }

  echo '<input type="hidden" id="sunstreaker_preview_image_url" name="sunstreaker_preview_image_url" value="" />';
  echo '<input type="hidden" id="sunstreaker_preview_image_width" name="sunstreaker_preview_image_width" value="" />';
  echo '<input type="hidden" id="sunstreaker_preview_image_height" name="sunstreaker_preview_image_height" value="" />';

  if ($use_logos) {
    echo '<div class="sunstreaker-fields sunstreaker-logo-fields">';
    echo '  <p class="sunstreaker-note">'.wp_kses_post($logo_note_html).'</p>';
    echo '  <input type="hidden" id="sunstreaker_logo_id" name="sunstreaker_logo_id" value="'.esc_attr((string) $posted_logo_id).'" />';
    echo '  <div class="sunstreaker-logo-location-list">';

    foreach ($logo_location_settings as $location_key => $location_settings) {
      $location_label = function_exists('sunstreaker_get_logo_location_label')
        ? sunstreaker_get_logo_location_label($location_key)
        : ucwords(str_replace('_', ' ', $location_key));
      $location_logo_choices = function_exists('sunstreaker_get_logo_location_choices')
        ? sunstreaker_get_logo_location_choices($product_id, $location_key)
        : [];
      $selected_location = $posted_logo_locations[$location_key] ?? null;
      $selected_logo_id = !empty($selected_location) ? (int) ($selected_location['logo_id'] ?? 0) : 0;
      echo '    <div class="sunstreaker-logo-location-option" data-logo-location="'.esc_attr($location_key).'">';
      echo '      <div class="sunstreaker-logo-location-option__header">';
      echo '        <span class="sunstreaker-logo-location-option__label">'.esc_html($location_label).'</span>';
      echo '      </div>';
      echo '      <div class="sunstreaker-logo-location-option__controls">';
      echo '        <select class="sunstreaker-select sunstreaker-logo-location-option__select" id="sunstreaker_logo_location_logo_id_'.esc_attr($location_key).'" name="sunstreaker_logo_location_logo_id['.esc_attr($location_key).']" data-logo-location-select="'.esc_attr($location_key).'" data-location-label="'.esc_attr($location_label).'">';
      echo '          <option value="">'.esc_html__('Choose a logo', 'sunstreaker').'</option>';
      foreach ($location_logo_choices as $logo) {
        $logo_id = isset($logo['id']) ? (int) $logo['id'] : 0;
        if ($logo_id <= 0) continue;
        $title = isset($logo['title']) ? (string) $logo['title'] : 'Logo '.$logo_id;
        echo '          <option value="'.esc_attr((string) $logo_id).'" '.selected($selected_logo_id, $logo_id, false).'>'.esc_html($title).'</option>';
      }
      echo '        </select>';
      echo '      </div>';
      echo '    </div>';
    }

    echo '  </div>';
    echo '</div>';
  }

  if ($use_right_chest_text) {
    echo '<div class="sunstreaker-fields sunstreaker-right-chest-fields">';
    echo '  <p class="sunstreaker-note">'.wp_kses_post($right_chest_note_html).'</p>';
    echo '  <div class="sunstreaker-field">';
    echo '    <label for="sunstreaker_right_chest_name_credentials">Name &amp; Credentials</label>';
    echo '    <input type="text" class="sunstreaker-text-input input-text text" id="sunstreaker_right_chest_name_credentials" name="sunstreaker_right_chest_name_credentials" value="'.$posted_right_chest_name.'" maxlength="60" placeholder="Name &amp; Credentials" />';
    echo '  </div>';
    echo '  <div class="sunstreaker-field">';
    echo '    <label for="sunstreaker_right_chest_department">Department</label>';
    echo '    <input type="text" class="sunstreaker-text-input input-text text" id="sunstreaker_right_chest_department" name="sunstreaker_right_chest_department" value="'.$posted_right_chest_department.'" maxlength="60" placeholder="Department" />';
    echo '  </div>';
    if (!empty($font_choices)) {
      echo '  <div class="sunstreaker-field">';
      echo '    <label for="sunstreaker_right_chest_font_choice">Font</label>';
      echo '    <select class="sunstreaker-select sunstreaker-font-select" id="sunstreaker_right_chest_font_choice" name="sunstreaker_right_chest_font_choice">';
      foreach ($font_choices as $choice_key => $choice) {
        $label = isset($choice['label']) ? (string) $choice['label'] : (string) $choice_key;
        $stack = isset($choice['stack']) ? (string) $choice['stack'] : '';
        echo '      <option value="'.esc_attr($choice_key).'" data-font-stack="'.esc_attr($stack).'" style="font-family:'.esc_attr($stack).';" '.selected($posted_right_chest_font_choice_key, $choice_key, false).'>'.esc_html($label).'</option>';
      }
      echo '    </select>';
      echo '  </div>';
    }
    echo '</div>';
  }

  if ($use_front_back) {
    echo '<div class="sunstreaker-fields sunstreaker-front-back-fields">';
    echo '  <p class="sunstreaker-note">'.wp_kses_post($front_back_note_html).'</p>';

    foreach ([
      'front' => ['label' => __('Front Graphic', 'sunstreaker'), 'url' => $posted_front_art_url, 'transform' => $posted_front_transform],
      'back' => ['label' => __('Back Graphic', 'sunstreaker'), 'url' => $posted_back_art_url, 'transform' => $posted_back_transform],
    ] as $field => $config) {
      $button_label = $config['url'] !== '' ? __('Replace artwork', 'sunstreaker') : __('Upload artwork', 'sunstreaker');
      $status_hidden = $config['url'] === '' ? ' hidden' : '';
      $thumb_markup = $config['url'] !== ''
        ? '<img class="sunstreaker-art-upload__status-thumb" src="'.esc_url($config['url']).'" alt="" loading="lazy" decoding="async" />'
        : '';
      echo '  <div class="sunstreaker-field sunstreaker-art-upload" data-art-field="'.esc_attr($field).'">';
      echo '    <div class="sunstreaker-art-upload__header">';
      echo '      <label>'.esc_html($config['label']).'</label>';
      echo '      <span class="sunstreaker-art-upload__status'.($config['url'] !== '' ? ' has-thumb' : '').'" id="sunstreaker_'.esc_attr($field).'_art_status"'.$status_hidden.'>'.$thumb_markup.'<span class="sunstreaker-art-upload__status-text" hidden></span></span>';
      echo '    </div>';
      echo '    <div class="sunstreaker-art-upload__actions">';
      echo '      <input type="file" id="sunstreaker_'.esc_attr($field).'_art_file" class="sunstreaker-art-upload__input" accept="image/png, image/jpeg, image/webp" hidden />';
      echo '      <button type="button" class="button sunstreaker-art-upload__button" id="sunstreaker_'.esc_attr($field).'_art_button">'.esc_html($button_label).'</button>';
      echo '    </div>';
      echo '    <input type="hidden" id="sunstreaker_'.esc_attr($field).'_art_url" name="sunstreaker_'.esc_attr($field).'_art_url" value="'.esc_attr($config['url']).'" />';
      echo '    <input type="hidden" id="sunstreaker_'.esc_attr($field).'_transform" name="sunstreaker_'.esc_attr($field).'_transform" value="'.esc_attr($config['transform']).'" />';
      echo '  </div>';
    }

    echo '</div>';
  }
});

function sunstreaker_handle_front_back_art_upload() {
  $nonce = isset($_POST['_ajax_nonce']) ? (string) wp_unslash($_POST['_ajax_nonce']) : '';
  if (!wp_verify_nonce($nonce, 'sunstreaker_boundaries')) {
    wp_send_json_error(['message' => 'Bad nonce'], 403);
  }

  $product_id = isset($_POST['product_id']) ? absint(wp_unslash($_POST['product_id'])) : 0;
  if ($product_id <= 0 || get_post_type($product_id) !== 'product') {
    wp_send_json_error(['message' => 'Invalid product'], 400);
  }
  if (!sunstreaker_is_enabled_for_product($product_id) || !function_exists('sunstreaker_uses_front_back') || !sunstreaker_uses_front_back($product_id)) {
    wp_send_json_error(['message' => 'Front/back artwork is not enabled for this product.'], 400);
  }

  $slot = isset($_POST['slot']) ? sanitize_key((string) wp_unslash($_POST['slot'])) : '';
  if ($slot !== 'front' && $slot !== 'back') {
    wp_send_json_error(['message' => 'Invalid artwork slot.'], 400);
  }

  if (empty($_FILES['image']['tmp_name'])) {
    wp_send_json_error(['message' => 'No artwork file received.'], 400);
  }

  require_once ABSPATH.'wp-admin/includes/file.php';
  $upload = wp_handle_upload($_FILES['image'], [
    'test_form' => false,
    'mimes' => [
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'webp' => 'image/webp',
    ],
  ]);

  if (!empty($upload['error'])) {
    wp_send_json_error(['message' => 'Upload failed: '.$upload['error']], 500);
  }

  $size = @getimagesize((string) $upload['file']);

  wp_send_json_success([
    'slot' => $slot,
    'url' => esc_url_raw((string) $upload['url']),
    'width' => isset($size[0]) ? (int) $size[0] : 0,
    'height' => isset($size[1]) ? (int) $size[1] : 0,
  ]);
}

add_action('wp_ajax_sunstreaker_upload_front_back_art', 'sunstreaker_handle_front_back_art_upload');
add_action('wp_ajax_nopriv_sunstreaker_upload_front_back_art', 'sunstreaker_handle_front_back_art_upload');
