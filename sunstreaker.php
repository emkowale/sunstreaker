<?php
/*
 * Plugin Name: Sunstreaker
 * Version: 0.1.19
 * Plugin URI: https://github.com/emkowale/sunstreaker
 * Description: Adds required Name + Number personalization fields to selected WooCommerce products (e.g., for jersey/shirt backs) with an optional per-product price add-on.
 * Author: Eric Kowalewski
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/emkowale/sunstreaker
 * GitHub Plugin URI: emkowale/sunstreaker
 */

if ( ! defined( 'ABSPATH' ) ) exit;



define('SUNSTREAKER_VERSION', '0.1.19');
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

add_action('load-plugins.php', function(){
  if (!current_user_can('update_plugins')) return;
  sunstreaker_store_update_transient(false);
});

add_action('load-update-core.php', function(){
  if (!current_user_can('update_plugins')) return;
  sunstreaker_store_update_transient(true);
});

add_action('admin_init', function(){
  if (!is_admin() || !current_user_can('update_plugins')) return;
  if (!isset($_GET['sunstreaker_check_updates'])) return;

  check_admin_referer('sunstreaker_check_updates');

  delete_site_transient(SUNSTREAKER_UPDATE_CACHE_KEY);
  delete_site_transient('update_plugins');
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
require_once SUNSTREAKER_PATH.'includes/original-art.php';
