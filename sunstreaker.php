<?php
/*
 * Plugin Name: Sunstreaker
 * Version: 0.1.25
 * Plugin URI: https://github.com/emkowale/sunstreaker
 * Description: Adds required Name + Number personalization fields to selected WooCommerce products (e.g., for jersey/shirt backs) with an optional per-product price add-on.
 * Author: Eric Kowalewski
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/emkowale/sunstreaker
 * GitHub Plugin URI: emkowale/sunstreaker
 */

if ( ! defined( 'ABSPATH' ) ) exit;



define('SUNSTREAKER_VERSION', '0.1.25');
define('SUNSTREAKER_PATH', plugin_dir_path(__FILE__));
define('SUNSTREAKER_URL',  plugin_dir_url(__FILE__));
define('SUNSTREAKER_SLUG', plugin_basename(__FILE__));
define('SUNSTREAKER_UPDATE_MANIFEST_URL', 'https://github.com/emkowale/sunstreaker/releases/latest/download/update.json');
define('SUNSTREAKER_UPDATE_CACHE_KEY', 'sunstreaker_github_release_payload');
define('SUNSTREAKER_UPDATE_CACHE_TTL', 5 * MINUTE_IN_SECONDS);

function sunstreaker_fetch_remote_payload(bool $force = false): array {
  $url = SUNSTREAKER_UPDATE_MANIFEST_URL;
  if ($force) {
    $url = add_query_arg('t', (string) time(), $url);
  }

  $api = wp_remote_get($url, [
    'headers' => ['User-Agent' => 'WordPress; Sunstreaker Update Manifest'],
    'timeout' => 10,
  ]);
  if (is_wp_error($api)) return [];

  $data = json_decode(wp_remote_retrieve_body($api), true);
  if (!is_array($data) || empty($data['version']) || empty($data['package'])) return [];

  return [
    'name' => isset($data['name']) ? (string) $data['name'] : 'Sunstreaker',
    'slug' => isset($data['slug']) ? (string) $data['slug'] : 'sunstreaker',
    'version' => (string) $data['version'],
    'package' => (string) $data['package'],
    'url' => isset($data['url']) ? (string) $data['url'] : 'https://github.com/emkowale/sunstreaker',
    'requires' => isset($data['requires']) ? (string) $data['requires'] : '6.0',
    'tested' => isset($data['tested']) ? (string) $data['tested'] : '6.8.3',
    'requires_php' => isset($data['requires_php']) ? (string) $data['requires_php'] : '7.4',
    'sections' => (isset($data['sections']) && is_array($data['sections'])) ? $data['sections'] : [],
    'checked_at' => time(),
  ];
}

function sunstreaker_remote_payload(bool $force = false): array {
  if (!$force) {
    $cached = get_site_transient(SUNSTREAKER_UPDATE_CACHE_KEY);
    if (is_array($cached) && !empty($cached['version']) && !empty($cached['package'])) {
      return $cached;
    }
  }

  $payload = sunstreaker_fetch_remote_payload($force);
  if ($payload) {
    set_site_transient(SUNSTREAKER_UPDATE_CACHE_KEY, $payload, SUNSTREAKER_UPDATE_CACHE_TTL);
    return $payload;
  }

  $cached = get_site_transient(SUNSTREAKER_UPDATE_CACHE_KEY);
  return is_array($cached) ? $cached : [];
}

function sunstreaker_update_item(string $version, string $package): stdClass {
  $obj = new stdClass();
  $obj->slug = 'sunstreaker';
  $obj->plugin = SUNSTREAKER_SLUG;
  $obj->new_version = $version;
  $obj->url = 'https://github.com/emkowale/sunstreaker';
  $obj->package = $package;
  return $obj;
}

function sunstreaker_update_cache_is_stale(): bool {
  $cached = get_site_transient(SUNSTREAKER_UPDATE_CACHE_KEY);
  if (!is_array($cached) || empty($cached['checked_at'])) return true;
  return ((int) $cached['checked_at'] + SUNSTREAKER_UPDATE_CACHE_TTL) <= time();
}

function sunstreaker_apply_update_payload($transient, bool $force = false) {
  if (!is_object($transient)) $transient = new stdClass();
  if (!isset($transient->response) || !is_array($transient->response)) $transient->response = [];
  if (!isset($transient->no_update) || !is_array($transient->no_update)) $transient->no_update = [];

  $current = isset($transient->checked[SUNSTREAKER_SLUG]) ? (string) $transient->checked[SUNSTREAKER_SLUG] : SUNSTREAKER_VERSION;
  if ($current === '') $current = SUNSTREAKER_VERSION;

  $remote = sunstreaker_remote_payload($force);
  if (!$remote || empty($remote['version'])) return $transient;

  unset($transient->response[SUNSTREAKER_SLUG]);

  $tag = (string) $remote['version'];
  $package = isset($remote['package']) ? (string) $remote['package'] : '';
  if (version_compare($tag, $current, '<=')) {
    $transient->no_update[SUNSTREAKER_SLUG] = sunstreaker_update_item($current, '');
    return $transient;
  }

  unset($transient->no_update[SUNSTREAKER_SLUG]);
  $transient->response[SUNSTREAKER_SLUG] = sunstreaker_update_item($tag, $package);
  return $transient;
}

function sunstreaker_store_update_transient(bool $force = false): void {
  $transient = get_site_transient('update_plugins');
  $transient = sunstreaker_apply_update_payload($transient, $force);
  if (!is_object($transient)) return;

  if (!isset($transient->checked) || !is_array($transient->checked)) $transient->checked = [];
  $transient->checked[SUNSTREAKER_SLUG] = SUNSTREAKER_VERSION;
  $transient->last_checked = time();

  set_site_transient('update_plugins', $transient);
}

function sunstreaker_update_check_url(): string {
  return wp_nonce_url(
    add_query_arg('sunstreaker_check_updates', '1', self_admin_url('plugins.php')),
    'sunstreaker_check_updates'
  );
}

/**
 * GitHub Releases updater (modeled after Bumblebee).
 * Expects an asset named: sunstreaker-vX.Y.Z.zip
 */
add_filter('pre_set_site_transient_update_plugins', function($transient){
  return sunstreaker_apply_update_payload($transient, true);
});

// Safety net: if a stale update row persists, suppress it when versions match.
add_filter('site_transient_update_plugins', function($transient){
  return sunstreaker_apply_update_payload($transient, false);
});

add_filter('update_plugins_github.com', function($update, $plugin_data, $plugin_file){
  if ($plugin_file !== SUNSTREAKER_SLUG) return $update;

  $remote = sunstreaker_remote_payload(false);
  if (!$remote || empty($remote['version']) || empty($remote['package'])) return false;

  return [
    'id' => $plugin_data['UpdateURI'],
    'slug' => 'sunstreaker',
    'version' => (string) $remote['version'],
    'url' => isset($remote['url']) ? (string) $remote['url'] : 'https://github.com/emkowale/sunstreaker',
    'package' => (string) $remote['package'],
    'tested' => isset($remote['tested']) ? (string) $remote['tested'] : '6.8.3',
    'requires' => isset($remote['requires']) ? (string) $remote['requires'] : '6.0',
    'requires_php' => isset($remote['requires_php']) ? (string) $remote['requires_php'] : '7.4',
  ];
}, 10, 4);

add_action('load-plugins.php', function(){
  if (!current_user_can('update_plugins')) return;
  if (sunstreaker_update_cache_is_stale() && function_exists('wp_clean_plugins_cache')) {
    delete_site_transient(SUNSTREAKER_UPDATE_CACHE_KEY);
    wp_clean_plugins_cache(true);
  }
  sunstreaker_store_update_transient(false);
}, 1);

add_action('load-update-core.php', function(){
  if (!current_user_can('update_plugins')) return;
  if (function_exists('wp_clean_plugins_cache')) {
    delete_site_transient(SUNSTREAKER_UPDATE_CACHE_KEY);
    wp_clean_plugins_cache(true);
  }
  sunstreaker_store_update_transient(true);
}, 1);

add_action('admin_init', function(){
  if (!is_admin() || !current_user_can('update_plugins')) return;
  if (!isset($_GET['sunstreaker_check_updates'])) return;

  check_admin_referer('sunstreaker_check_updates');

  delete_site_transient(SUNSTREAKER_UPDATE_CACHE_KEY);
  if (function_exists('wp_clean_plugins_cache')) {
    wp_clean_plugins_cache(true);
  } else {
    delete_site_transient('update_plugins');
  }
  if (function_exists('wp_update_plugins')) {
    wp_update_plugins();
  }
  sunstreaker_store_update_transient(true);

  $remote = sunstreaker_remote_payload(false);
  $args = ['sunstreaker_update_checked' => '1'];
  if (!empty($remote['version'])) $args['sunstreaker_remote_version'] = (string) $remote['version'];

  wp_safe_redirect(add_query_arg($args, self_admin_url('plugins.php')));
  exit;
});

add_action('admin_notices', function(){
  if (!is_admin() || !current_user_can('update_plugins')) return;
  if (!isset($_GET['sunstreaker_update_checked'])) return;

  $remote = isset($_GET['sunstreaker_remote_version']) ? sanitize_text_field(wp_unslash((string) $_GET['sunstreaker_remote_version'])) : '';
  $message = $remote !== ''
    ? sprintf('Sunstreaker checked GitHub and saw version %s.', esc_html($remote))
    : 'Sunstreaker checked GitHub, but no remote version was returned.';

  printf('<div class="notice notice-info is-dismissible"><p>%s</p></div>', $message);
});

add_filter('plugins_api', function($res, $action, $args){
  if ($action !== 'plugin_information' || (isset($args->slug) && $args->slug !== 'sunstreaker')) return $res;
  $remote = sunstreaker_remote_payload(false);
  $info = new stdClass();
  $info->name = !empty($remote['name']) ? (string) $remote['name'] : 'Sunstreaker';
  $info->slug = 'sunstreaker';
  $info->version = !empty($remote['version']) ? (string) $remote['version'] : SUNSTREAKER_VERSION;
  $info->author = '<a href="https://github.com/emkowale">Eric Kowalewski</a>';
  $info->homepage = !empty($remote['url']) ? (string) $remote['url'] : 'https://github.com/emkowale/sunstreaker';
  $info->requires = !empty($remote['requires']) ? (string) $remote['requires'] : '6.0';
  $info->tested = !empty($remote['tested']) ? (string) $remote['tested'] : '6.8.3';
  $info->requires_php = !empty($remote['requires_php']) ? (string) $remote['requires_php'] : '7.4';
  $info->download_link = !empty($remote['package']) ? (string) $remote['package'] : '';
  $info->sections = !empty($remote['sections']) && is_array($remote['sections'])
    ? $remote['sections']
    : [ 'description' => 'Adds required Name + Number personalization fields to selected WooCommerce products.' ];
  return $info;
}, 10, 3);

if (is_admin()) {
  $callback = function($links){
    array_splice(
      $links,
      1,
      0,
      '<a href="'.esc_url(sunstreaker_update_check_url()).'">Check updates now</a>'
    );
    return $links;
  };
  add_filter('plugin_action_links_' . plugin_basename(__FILE__), $callback);
  add_filter('network_admin_plugin_action_links_' . plugin_basename(__FILE__), $callback);
}

require_once SUNSTREAKER_PATH.'includes/product-meta.php';
require_once SUNSTREAKER_PATH.'includes/frontend-fields.php';
require_once SUNSTREAKER_PATH.'includes/cart-order.php';
require_once SUNSTREAKER_PATH.'includes/front-back.php';
require_once SUNSTREAKER_PATH.'includes/original-art.php';
