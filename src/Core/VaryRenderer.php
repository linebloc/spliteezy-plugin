<?php

namespace Spliteezy\Core;

defined('ABSPATH') || exit;

/**
 * Cache-per-variant ("vary") delivery: each variant is a fully cacheable
 * render keyed by the `?eezy_v={index}` URL parameter, with a per-test
 * cookie for stickiness. Cookieless requests get the control plus a head
 * bootstrap that assigns in the browser and redirects variant visitors
 * once. PHP never sets cookies here — every response must stay cacheable.
 */
class VaryRenderer
{
    private const COOKIE_PREFIX = 'eezy_v_';

    public const URL_PARAM = 'eezy_v';

    /** @var array<string, mixed> Manifest test entry. */
    private array $test;

    private \WP_Post $post;

    /**
     * @param  array<string, mixed>  $test  Manifest test entry.
     */
    public function __construct(array $test, \WP_Post $post)
    {
        $this->test = $test;
        $this->post = $post;
    }

    /**
     * The assignment cookie name for a test.
     */
    public static function cookie_name(string $test_id): string
    {
        return self::COOKIE_PREFIX.$test_id;
    }

    /**
     * The variant index requested via the URL parameter, or null when the
     * parameter is missing or invalid. The parametrized URL is the delivery
     * path that works under every cache layer — URLs are always part of the
     * cache key.
     */
    public static function bucket_from_param(int $variant_count): ?int
    {
        if (! isset($_GET[self::URL_PARAM])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public front-end read of the variant cache-key parameter; no state change.
            return null;
        }

        $value = sanitize_text_field(wp_unslash($_GET[self::URL_PARAM])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- unslashed and sanitized inline; strictly validated as an integer index below.

        if (! ctype_digit($value)) {
            return null;
        }

        $index = (int) $value;

        return $index < $variant_count ? $index : null;
    }

    /**
     * Register the address-bar cleanup for parametrized variant renders.
     */
    public static function register_url_cleanup(): void
    {
        add_action('wp_head', [self::class, 'print_url_cleanup'], 0);
    }

    /**
     * Strips the variant parameter from the address bar. The page content
     * is already the variant; the canonical URL stays the clean permalink.
     */
    public static function print_url_cleanup(): void
    {
        echo '<script id="eezy-vary-clean" nitro-exclude nowprocket data-cfasync="false" data-no-defer="1" data-no-optimize="1">(function(){try{var s=location.search.replace(/[?&]eezy_v=\d+/,"").replace(/^&/,"?");history.replaceState(null,"",location.pathname+s+location.hash)}catch(e){}})();</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static inline script with a fixed payload; escaping would corrupt the JS.
    }

    /**
     * The visitor's assigned variant index from the request cookie, or null
     * when the cookie is missing or invalid — both re-enter the bootstrap
     * flow, so a stale index (e.g. after a variant was removed) self-heals.
     */
    public static function bucket_from_cookie(string $test_id, int $variant_count): ?int
    {
        $name = self::cookie_name($test_id);

        if (! isset($_COOKIE[$name])) {
            return null;
        }

        $value = sanitize_text_field(wp_unslash($_COOKIE[$name]));

        if (! ctype_digit($value)) {
            return null;
        }

        $index = (int) $value;

        return $index < $variant_count ? $index : null;
    }

    /**
     * Register the self-assignment bootstrap printed on the cookieless
     * (control) render. Visitors who stay paint immediately; the page is
     * only cloaked around an actual redirect to a variant URL.
     */
    public function register_bootstrap(): void
    {
        add_action('wp_head', [$this, 'print_bootstrap'], 0);
    }

    public function print_bootstrap(): void
    {
        // The attributes opt out of JS delayers — a postponed bootstrap
        // would never assign.
        echo '<script id="eezy-vary-bootstrap" nitro-exclude nowprocket data-cfasync="false" data-no-defer="1" data-no-optimize="1">'.$this->bootstrap_js().'</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- inline script assembled from static JS and wp_json_encode()'d config; escaping would corrupt it.
    }

    /**
     * Path scope for the assignment cookie: the tested page's own path, so
     * other pages never receive the cookie and their cache keys stay whole.
     */
    private function cookie_path(): string
    {
        $permalink = get_permalink($this->post);
        $path = $permalink ? (string) wp_parse_url($permalink, PHP_URL_PATH) : '/';

        return $path !== '' ? $path : '/';
    }

    /**
     * The self-assignment bootstrap. Bucketing must stay byte-for-byte
     * compatible with Visitor::assign_variant(); bots always get the
     * control, matching server mode. Redirect loops are only possible when
     * a cache strips the parameter — detected in-script and answered by
     * holding the visitor on the control.
     */
    private function bootstrap_js(): string
    {
        $test_id = (string) $this->test['id'];
        $weights = array_map('floatval', array_column($this->test['variants'] ?? [], 'weight'));

        $config = wp_json_encode([
            'test_id' => $test_id,
            'cookie' => self::cookie_name($test_id),
            'path' => $this->cookie_path(),
            'weights' => $weights,
            'secure' => is_ssl(),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $runtime = <<<'JS'
(function (cfg) {
  'use strict';

  var cloakEl = null;

  function cloak() {
    cloakEl = document.createElement('style');
    cloakEl.textContent = 'html{visibility:hidden!important}';
    (document.head || document.documentElement).appendChild(cloakEl);
  }

  function uncloak() {
    if (cloakEl && cloakEl.parentNode) cloakEl.parentNode.removeChild(cloakEl);
    cloakEl = null;
  }

  function crc32(str) {
    var c, crc = 0xFFFFFFFF;
    for (var i = 0; i < str.length; i++) {
      c = (crc ^ str.charCodeAt(i)) & 0xFF;
      for (var k = 0; k < 8; k++) {
        c = c & 1 ? (c >>> 1) ^ 0xEDB88320 : c >>> 1;
      }
      crc = (crc >>> 8) ^ c;
    }
    return (crc ^ 0xFFFFFFFF) >>> 0;
  }

  function getCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  function setCookie(name, value, maxAge) {
    document.cookie = name + '=' + value + '; path=' + cfg.path +
      (maxAge ? '; max-age=' + maxAge : '') +
      '; samesite=lax' + (cfg.secure ? '; secure' : '');
  }

  function randomHex(bytes) {
    var out = '', arr = new Uint8Array(bytes);
    (window.crypto || window.msCrypto).getRandomValues(arr);
    for (var i = 0; i < arr.length; i++) {
      out += (arr[i] + 0x100).toString(16).slice(1);
    }
    return out;
  }

  function visitorId() {
    var id = getCookie('spliteezy_vid');
    if (id && /^eezy_[a-f0-9]{32}$/.test(id)) return id;
    id = 'eezy_' + randomHex(16);
    document.cookie = 'spliteezy_vid=' + id +
      '; path=/; max-age=31536000; samesite=lax' + (cfg.secure ? '; secure' : '');
    return id;
  }

  function pickIndex(visitor, testId, weights) {
    var bucket = crc32(visitor + '|' + testId) % 10000;
    var total = 0, cursor = 0, i;
    for (i = 0; i < weights.length; i++) total += weights[i];
    if (!total) return 0;
    for (i = 0; i < weights.length; i++) {
      cursor += Math.round((weights[i] / total) * 10000);
      if (bucket < cursor) return i;
    }
    return 0;
  }

  function variantUrl(idx) {
    var search = location.search ? location.search + '&' : '?';
    return location.pathname + search + 'eezy_v=' + idx + location.hash;
  }

  function redirect(idx) {
    cloak();
    location.replace(variantUrl(idx));
    setTimeout(uncloak, 1500);
  }

  try {
    if (/bot|crawler|spider|slurp|googlebot|bingbot|facebookexternalhit|headlesschrome|prerender/i.test(navigator.userAgent)) {
      return;
    }

    if (/[?&]eezy_v=/.test(location.search)) {
      setCookie(cfg.cookie, '0', 3600);
      return;
    }

    var existing = getCookie(cfg.cookie);

    if (existing === '0') {
      return;
    }

    if (existing !== null && /^\d+$/.test(existing) && parseInt(existing, 10) < cfg.weights.length) {
      redirect(existing);
      return;
    }

    var idx = pickIndex(visitorId(), cfg.test_id, cfg.weights);
    setCookie(cfg.cookie, String(idx), 31536000);

    if (idx === 0) {
      return;
    }

    if (getCookie(cfg.cookie) !== String(idx)) {
      return;
    }

    redirect(idx);
  } catch (e) {
    uncloak();
  }
})(__EEZY_CFG__);
JS;

        return str_replace('__EEZY_CFG__', (string) $config, $runtime);
    }
}
