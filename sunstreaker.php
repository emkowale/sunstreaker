<?php
/*
 * Plugin Name: Sunstreaker
 * Version: 0.1.17
 * Plugin URI: https://github.com/emkowale/sunstreaker
 * Description: Adds required Name + Number personalization fields to selected WooCommerce products (e.g., for jersey/shirt backs) with an optional per-product price add-on.
 * Author: Eric Kowalewski
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/emkowale/sunstreaker
 * GitHub Plugin URI: emkowale/sunstreaker
 */

if ( ! defined( 'ABSPATH' ) ) exit;



define('SUNSTREAKER_VERSION', '0.1.17');
define('SUNSTREAKER_PATH', plugin_dir_path(__FILE__));
define('SUNSTREAKER_URL',  plugin_dir_url(__FILE__));
define('SUNSTREAKER_SLUG', plugin_basename(__FILE__));
define('SUNSTREAKER_UPDATE_CACHE_KEY', 'sunstreaker_github_release_payload');
define('SUNSTREAKER_UPDATE_CACHE_TTL', 5 * MINUTE_IN_SECONDS);

function sunstreaker_fetch_remote_payload(): array {
  $api = wp_remote_get('https://api.github.com/repos/emkowale/sunstreaker/releases/latest', [
    'headers' => ['User-Agent' => 'WordPress; Sunstreaker Updater'],
    'timeout' => 10,
  ]);
  if (is_wp_error($api)) return [];

  $data = json_decode(wp_remote_retrieve_body($api), true);
  if (!is_array($data) || empty($data['tag_name'])) return [];

  $tag = ltrim((string) $data['tag_name'], 'vV');
  $package = '';
  if (!empty($data['assets'])) {
    foreach ($data['assets'] as $asset) {
      if (!empty($asset['browser_download_url']) && preg_match('/sunstreaker-v[0-9]+\.[0-9]+\.[0-9]+\.zip$/', $asset['browser_download_url'])) {
        $package = $asset['browser_download_url'];
        break;
      }
    }
  }
  if ($package === '') $package = isset($data['zipball_url']) ? (string) $data['zipball_url'] : '';
  if ($tag === '' || $package === '') return [];

  return [
    'version' => $tag,
    'package' => $package,
  ];
}

function sunstreaker_remote_payload(bool $force = false): array {
  if (!$force) {
    $cached = get_site_transient(SUNSTREAKER_UPDATE_CACHE_KEY);
    if (is_array($cached) && !empty($cached['version']) && !empty($cached['package'])) {
      return $cached;
    }
  }

  $payload = sunstreaker_fetch_remote_payload();
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

add_filter('plugins_api', function($res, $action, $args){
  if ($action !== 'plugin_information' || (isset($args->slug) && $args->slug !== 'sunstreaker')) return $res;
  $info = new stdClass();
  $info->name = 'Sunstreaker';
  $info->slug = 'sunstreaker';
  $info->version = SUNSTREAKER_VERSION;
  $info->author = '<a href="https://github.com/emkowale">Eric Kowalewski</a>';
  $info->homepage = 'https://github.com/emkowale/sunstreaker';
  $info->requires = '6.0';
  $info->tested = '6.8.3';
  $info->sections = [ 'description' => 'Adds Name + Number personalization fields to selected WooCommerce products.' ];
  return $info;
}, 10, 3);

require_once SUNSTREAKER_PATH.'includes/product-meta.php';
require_once SUNSTREAKER_PATH.'includes/frontend-fields.php';
require_once SUNSTREAKER_PATH.'includes/cart-order.php';
require_once SUNSTREAKER_PATH.'includes/original-art.php';
