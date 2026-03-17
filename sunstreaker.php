<?php
/*
 * Plugin Name: Sunstreaker
 * Version: 0.1.10
 * Plugin URI: https://github.com/emkowale/sunstreaker
 * Description: Adds required Name + Number personalization fields to selected WooCommerce products (e.g., for jersey/shirt backs) with an optional per-product price add-on.
 * Author: Eric Kowalewski
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/emkowale/sunstreaker
 * GitHub Plugin URI: emkowale/sunstreaker
 */

if ( ! defined( 'ABSPATH' ) ) exit;



define('SUNSTREAKER_VERSION', '0.1.10');
define('SUNSTREAKER_PATH', plugin_dir_path(__FILE__));
define('SUNSTREAKER_URL',  plugin_dir_url(__FILE__));
define('SUNSTREAKER_SLUG', plugin_basename(__FILE__));
define('SUNSTREAKER_GH_REPO', 'emkowale/sunstreaker');

function sunstreaker_github_repo_slug(): string {
  $repo = trim((string) SUNSTREAKER_GH_REPO);
  $repo = trim((string) apply_filters('sunstreaker_github_repo_slug', $repo));
  return $repo;
}

function sunstreaker_github_repo_url(): string {
  return 'https://github.com/'.sunstreaker_github_repo_slug();
}

function sunstreaker_github_api_repo_url(string $suffix): string {
  $suffix = ltrim($suffix, '/');
  return 'https://api.github.com/repos/'.sunstreaker_github_repo_slug().'/'.$suffix;
}

function sunstreaker_github_token(): string {
  if (defined('SUNSTREAKER_GITHUB_TOKEN')) {
    $c = trim((string) constant('SUNSTREAKER_GITHUB_TOKEN'));
    if ($c !== '') return $c;
  }
  $env = getenv('SUNSTREAKER_GITHUB_TOKEN');
  $token = (is_string($env) && trim($env) !== '') ? trim($env) : '';
  $token = trim((string) apply_filters('sunstreaker_github_token', $token));
  return $token;
}

function sunstreaker_github_http_headers(): array {
  $headers = [
    'User-Agent' => 'WordPress; Sunstreaker Updater',
    'Accept' => 'application/vnd.github+json',
  ];
  $token = sunstreaker_github_token();
  if ($token !== '') {
    $headers['Authorization'] = 'Bearer '.$token;
  }
  return $headers;
}

function sunstreaker_github_get_json(string $url): array {
  $res = wp_remote_get($url, [
    'headers' => sunstreaker_github_http_headers(),
    'timeout' => 15,
  ]);
  if (is_wp_error($res)) return [];
  $code = (int) wp_remote_retrieve_response_code($res);
  if ($code < 200 || $code >= 300) return [];
  $data = json_decode((string) wp_remote_retrieve_body($res), true);
  return is_array($data) ? $data : [];
}

function sunstreaker_release_asset_url(string $version): string {
  return sunstreaker_github_repo_url().'/releases/download/v'.$version.'/sunstreaker-v'.$version.'.zip';
}

function sunstreaker_latest_release_payload(): array {
  $data = sunstreaker_github_get_json(sunstreaker_github_api_repo_url('releases/latest'));
  if (!$data || empty($data['tag_name'])) return [];

  $version = ltrim((string) $data['tag_name'], 'vV');
  if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) return [];

  $package = '';
  if (!empty($data['assets']) && is_array($data['assets'])) {
    foreach ($data['assets'] as $asset) {
      if (!is_array($asset)) continue;
      $url = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';
      if ($url !== '' && preg_match('/sunstreaker-v[0-9]+\.[0-9]+\.[0-9]+\.zip$/', $url)) {
        $package = $url;
        break;
      }
    }
  }
  if ($package === '') {
    $package = sunstreaker_release_asset_url($version);
  }

  return [
    'version' => $version,
    'package' => $package,
  ];
}

function sunstreaker_latest_tag_payload(): array {
  $tags = sunstreaker_github_get_json(sunstreaker_github_api_repo_url('tags?per_page=100'));
  if (!$tags || !is_array($tags)) return [];

  $best = '';
  foreach ($tags as $tag) {
    if (!is_array($tag)) continue;
    $name = isset($tag['name']) ? ltrim((string) $tag['name'], 'vV') : '';
    if (!preg_match('/^\d+\.\d+\.\d+$/', $name)) continue;
    if ($best === '' || version_compare($name, $best, '>')) {
      $best = $name;
    }
  }
  if ($best === '') return [];

  return [
    'version' => $best,
    'package' => sunstreaker_release_asset_url($best),
  ];
}

function sunstreaker_remote_update_payload(): array {
  $remote = sunstreaker_latest_release_payload();
  if (!$remote) $remote = sunstreaker_latest_tag_payload();
  return is_array($remote) ? $remote : [];
}

// Add auth headers for private GitHub repos when downloading update metadata/package.
add_filter('http_request_args', function($args, $url){
  if (!is_string($url) || $url === '') return $args;
  $repo = sunstreaker_github_repo_slug();
  if ($repo === '') return $args;
  $repo_path = '/'.$repo.'/';
  $is_repo_api = (stripos($url, 'https://api.github.com/repos/'.$repo.'/') === 0);
  $is_repo_file = (stripos($url, 'https://github.com'.$repo_path) === 0);
  if (!$is_repo_api && !$is_repo_file) return $args;

  $token = sunstreaker_github_token();
  if ($token === '') return $args;

  if (!isset($args['headers']) || !is_array($args['headers'])) $args['headers'] = [];
  if (empty($args['headers']['Authorization'])) {
    $args['headers']['Authorization'] = 'Bearer '.$token;
  }
  if (empty($args['headers']['User-Agent'])) {
    $args['headers']['User-Agent'] = 'WordPress; Sunstreaker Updater';
  }
  if (empty($args['headers']['Accept'])) {
    $args['headers']['Accept'] = 'application/vnd.github+json';
  }
  return $args;
}, 10, 2);

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

  $remote = sunstreaker_remote_update_payload();
  if (!$remote) return $transient;

  $tag = (string) ($remote['version'] ?? '');
  if ($tag === '') return $transient;
  if (version_compare($tag, $current, '<=')) {
    $obj = new stdClass();
    $obj->slug = 'sunstreaker';
    $obj->plugin = SUNSTREAKER_SLUG;
    $obj->new_version = $current;
    $obj->url = sunstreaker_github_repo_url();
    $obj->package = '';
    $transient->no_update[SUNSTREAKER_SLUG] = $obj;
    return $transient;
  }

  $package = isset($remote['package']) ? (string) $remote['package'] : '';
  if ($package === '') $package = sunstreaker_release_asset_url($tag);

  $obj = new stdClass();
  $obj->slug = 'sunstreaker';
  $obj->plugin = SUNSTREAKER_SLUG;
  $obj->new_version = $tag;
  $obj->url = sunstreaker_github_repo_url();
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
  $remote = sunstreaker_remote_update_payload();
  $info = new stdClass();
  $info->name = 'Sunstreaker';
  $info->slug = 'sunstreaker';
  $info->version = isset($remote['version']) ? (string) $remote['version'] : SUNSTREAKER_VERSION;
  $info->author = '<a href="https://github.com/emkowale">Eric Kowalewski</a>';
  $info->homepage = sunstreaker_github_repo_url();
  $info->requires = '6.0';
  $info->tested = '6.8.3';
  $info->download_link = isset($remote['package']) ? (string) $remote['package'] : '';
  $info->sections = [ 'description' => 'Adds Name + Number personalization fields to selected WooCommerce products.' ];
  return $info;
}, 10, 3);

require_once SUNSTREAKER_PATH.'includes/product-meta.php';
require_once SUNSTREAKER_PATH.'includes/frontend-fields.php';
require_once SUNSTREAKER_PATH.'includes/cart-order.php';
require_once SUNSTREAKER_PATH.'includes/original-art.php';
