<?php
/*
 * Plugin Name: Sunstreaker
 * Version: 0.1.3
 * Plugin URI: https://github.com/emkowale/sunstreaker
 * Description: Adds required Name + Number personalization fields to selected WooCommerce products (e.g., for jersey/shirt backs) with an optional per-product price add-on.
 * Author: Eric Kowalewski
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/emkowale/sunstreaker
 * GitHub Plugin URI: emkowale/sunstreaker
 */

if ( ! defined( 'ABSPATH' ) ) exit;



define('SUNSTREAKER_VERSION', '0.1.3');
define('SUNSTREAKER_PATH', plugin_dir_path(__FILE__));
define('SUNSTREAKER_URL',  plugin_dir_url(__FILE__));
define('SUNSTREAKER_SLUG', plugin_basename(__FILE__));

/**
 * GitHub Releases updater (modeled after Bumblebee).
 * Expects an asset named: sunstreaker-vX.Y.Z.zip
 */
add_filter('pre_set_site_transient_update_plugins', function($transient){
  if ( !is_object($transient) ) return $transient;
  if (!isset($transient->response) || !is_array($transient->response)) $transient->response = [];
  if (!isset($transient->no_update) || !is_array($transient->no_update)) $transient->no_update = [];

  unset($transient->response[SUNSTREAKER_SLUG]);

  $current = isset($transient->checked[SUNSTREAKER_SLUG]) ? (string) $transient->checked[SUNSTREAKER_SLUG] : SUNSTREAKER_VERSION;
  if ($current === '') $current = SUNSTREAKER_VERSION;
  if ( empty($transient->checked) ) return $transient;

  $api = wp_remote_get('https://api.github.com/repos/emkowale/sunstreaker/releases/latest', [
    'headers' => ['User-Agent' => 'WordPress; Sunstreaker Updater'],
    'timeout' => 10,
  ]);
  if (is_wp_error($api)) return $transient;

  $data = json_decode(wp_remote_retrieve_body($api), true);
  if (!is_array($data) || empty($data['tag_name'])) return $transient;

  $tag = ltrim((string)$data['tag_name'], 'vV');
  if (version_compare($tag, $current, '<=')) {
    $obj = new stdClass();
    $obj->slug = 'sunstreaker';
    $obj->plugin = SUNSTREAKER_SLUG;
    $obj->new_version = $current;
    $obj->url = 'https://github.com/emkowale/sunstreaker';
    $obj->package = '';
    $transient->no_update[SUNSTREAKER_SLUG] = $obj;
    return $transient;
  }

  $package = '';
  if (!empty($data['assets'])) {
    foreach ($data['assets'] as $asset) {
      if (!empty($asset['browser_download_url']) && preg_match('/sunstreaker-v[0-9]+\.[0-9]+\.[0-9]+\.zip$/', $asset['browser_download_url'])) {
        $package = $asset['browser_download_url']; break;
      }
    }
  }
  if ($package==='') $package = isset($data['zipball_url']) ? $data['zipball_url'] : '';

  $obj = new stdClass();
  $obj->slug = 'sunstreaker';
  $obj->plugin = SUNSTREAKER_SLUG;
  $obj->new_version = $tag;
  $obj->url = 'https://github.com/emkowale/sunstreaker';
  $obj->package = $package;

  unset($transient->no_update[SUNSTREAKER_SLUG]);
  $transient->response[SUNSTREAKER_SLUG] = $obj;
  return $transient;
});

// Safety net: if a stale update row persists, suppress it when versions match.
add_filter('site_transient_update_plugins', function($transient){
  if (!is_object($transient) || !isset($transient->response[SUNSTREAKER_SLUG])) return $transient;
  $item = $transient->response[SUNSTREAKER_SLUG];
  $incoming = (is_object($item) && isset($item->new_version)) ? (string) $item->new_version : '';
  if ($incoming !== '' && version_compare($incoming, SUNSTREAKER_VERSION, '<=')) {
    unset($transient->response[SUNSTREAKER_SLUG]);
  }
  return $transient;
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
