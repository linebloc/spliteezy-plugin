<?php

namespace Spliteezy\Core;

defined('ABSPATH') || exit;

/**
 * Cache-safe ("client") delivery mode.
 *
 * Renders the control wrapped in a marker element carrying the test config,
 * embeds the other variants as inert <template> elements, and a head script
 * swaps the assigned variant in before paint using the same deterministic
 * bucketing as Visitor::assign_variant(). The HTML is identical for every
 * visitor, so tested pages stay safe to cache.
 */
class ClientRenderer
{
    /**
     * True while rendering an embedded variant through `the_content`,
     * so the wrap filter does not recurse into itself.
     */
    private static bool $rendering_variant = false;

    /** @var array<string, mixed> Manifest test entry. */
    private array $test;

    private \WP_Post $original;

    /**
     * @param  array<string, mixed>  $test  Manifest test entry.
     */
    public function __construct(array $test, \WP_Post $original)
    {
        $this->test = $test;
        $this->original = $original;
    }

    public function register(): void
    {
        add_action('wp_head', [$this, 'print_head_assets'], 1);
        add_filter('the_content', [$this, 'wrap_content'], PHP_INT_MAX);
    }

    /**
     * Cloak CSS + resolver runtime, printed before any content renders.
     */
    public function print_head_assets(): void
    {
        $cloak_selectors = '[data-eezy-cloak]';

        if ($this->titles_differ()) {
            $cloak_selectors .= ',h1,.entry-title,.wp-block-post-title';
        }

        echo '<style id="eezy-cloak">'.esc_html($cloak_selectors).'{visibility:hidden!important}</style>';
        echo '<noscript><style>'.esc_html($cloak_selectors).'{visibility:visible!important}</style></noscript>';
        // The attributes opt out of JS delayers (NitroPack, WP Rocket,
        // LiteSpeed, Rocket Loader) — a delayed resolver leaves the page
        // cloaked and assignment never fires.
        echo '<script id="eezy-resolver" nitro-exclude nowprocket data-cfasync="false" data-no-defer="1" data-no-optimize="1">'.$this->resolver_runtime_js().'</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static inline script with a fixed payload; escaping would corrupt the JS.
    }

    /**
     * Wraps the control content and appends variant templates. Runs last on
     * `the_content` so all other filters have already been applied.
     */
    public function wrap_content(string $content): string
    {
        if (self::$rendering_variant) {
            return $content;
        }

        if (! in_the_loop() || ! is_main_query() || get_the_ID() !== $this->original->ID) {
            return $content;
        }

        $test_id = (string) $this->test['id'];
        $variants = $this->test['variants'] ?? [];

        $templates = '';
        $config_variants = [];

        foreach ($variants as $index => $variant) {
            $config_variants[] = [
                'index' => (int) $index,
                'id' => $variant['id'] ?? null,
                'weight' => (float) ($variant['weight'] ?? 0),
                'title' => $index === 0 ? null : $this->variant_title((int) ($variant['post_id'] ?? 0)),
            ];

            if ((int) $index === 0) {
                continue;
            }

            $html = $this->render_variant((int) ($variant['post_id'] ?? 0));

            if ($html === null) {
                continue;
            }

            $templates .= '<template data-eezy-variant="'.esc_attr((string) $index).'">'.$html.'</template>';
        }

        $config = [
            'test_id' => $test_id,
            'control_title' => get_the_title($this->original),
            'variants' => $config_variants,
            'cookie' => 'spliteezy_vid',
            'secure' => is_ssl(),
        ];

        return '<div data-eezy-test="'.esc_attr($test_id).'" data-eezy-config="'.esc_attr((string) wp_json_encode($config)).'" data-eezy-cloak>'.$content.'</div>'
            .$templates;
    }

    /**
     * Renders a variant's content through the full `the_content` pipeline.
     * The global $post is temporarily pointed at the variant so blocks that
     * read fields off the implicit current post (e.g. ACF's get_field())
     * resolve against the variant.
     */
    private function render_variant(int $post_id): ?string
    {
        $variant_post = $post_id > 0 ? get_post($post_id) : null;

        if (! ($variant_post instanceof \WP_Post) || $variant_post->post_status !== 'publish') {
            return null;
        }

        global $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $post is temporarily repointed so implicit-current-post lookups resolve against the variant; restored before returning.
        $original_global_post = $post;

        self::$rendering_variant = true;
        $post = $variant_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- blocks reading the implicit current post must resolve against the variant; restored below.
        setup_postdata($variant_post);

        $html = (string) apply_filters('the_content', $variant_post->post_content); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'the_content' is WordPress core's filter; the variant must render through the standard content pipeline.

        $post = $original_global_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the original global.
        setup_postdata($original_global_post);
        self::$rendering_variant = false;

        return $html;
    }

    private function variant_title(int $post_id): ?string
    {
        $variant_post = $post_id > 0 ? get_post($post_id) : null;

        return $variant_post instanceof \WP_Post ? $variant_post->post_title : null;
    }

    private function titles_differ(): bool
    {
        $control_title = $this->original->post_title;

        foreach ($this->test['variants'] ?? [] as $index => $variant) {
            if ((int) $index === 0) {
                continue;
            }

            $title = $this->variant_title((int) ($variant['post_id'] ?? 0));

            if ($title !== null && $title !== $control_title) {
                return true;
            }
        }

        return false;
    }

    /**
     * The resolver runtime, printed once in <head>. Must stay behaviorally
     * identical to Visitor::assign_variant() so server and client mode hand
     * the same visitor the same variant.
     *
     * uncloak() appends a visibility override instead of only removing the
     * cloak style tag: CSS optimizers may merge or relocate that tag, which
     * would make removal a no-op and leave the page hidden.
     */
    private function resolver_runtime_js(): string
    {
        return <<<'JS'
(function () {
  'use strict';

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

  function randomHex(bytes) {
    var out = '', arr = new Uint8Array(bytes);
    (window.crypto || window.msCrypto).getRandomValues(arr);
    for (var i = 0; i < arr.length; i++) {
      out += (arr[i] + 0x100).toString(16).slice(1);
    }
    return out;
  }

  function visitorId(cookieName, secure) {
    var id = getCookie(cookieName);
    if (id && /^eezy_[a-f0-9]{32}$/.test(id)) return id;
    id = 'eezy_' + randomHex(16);
    document.cookie = cookieName + '=' + id +
      '; path=/; max-age=31536000; samesite=lax' + (secure ? '; secure' : '');
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

  function swapTitles(controlTitle, variantTitle) {
    if (!variantTitle || variantTitle === controlTitle) return;
    if (document.title.indexOf(controlTitle) !== -1) {
      document.title = document.title.replace(controlTitle, variantTitle);
    }
    var headings = document.querySelectorAll('h1, h2, .entry-title, .wp-block-post-title');
    for (var i = 0; i < headings.length; i++) {
      if (headings[i].textContent.trim() === controlTitle.trim()) {
        headings[i].textContent = variantTitle;
      }
    }
  }

  function uncloak() {
    var style = document.getElementById('eezy-cloak');
    if (style && style.parentNode) style.parentNode.removeChild(style);
    var override = document.createElement('style');
    override.textContent = '[data-eezy-cloak],h1,.entry-title,.wp-block-post-title{visibility:visible!important}';
    (document.head || document.documentElement).appendChild(override);
  }

  function resolveOne(container) {
    var cfg;
    try {
      cfg = JSON.parse(container.getAttribute('data-eezy-config'));
    } catch (e) {
      return;
    }

    var visitor = visitorId(cfg.cookie, cfg.secure);
    var weights = cfg.variants.map(function (v) { return v.weight; });
    var index = pickIndex(visitor, cfg.test_id, weights);

    if (index > 0) {
      var tpl = document.querySelector('template[data-eezy-variant="' + index + '"]');
      if (tpl) {
        container.innerHTML = tpl.innerHTML;
        swapTitles(cfg.control_title, cfg.variants[index] && cfg.variants[index].title);
      } else {
        index = 0; // Variant markup missing — fall back to control.
      }
    }

    var assignment = {
      test_id: cfg.test_id,
      variant_index: index,
      variant_id: cfg.variants[index] ? cfg.variants[index].id : null,
      visitor_id: visitor,
    };
    window.SpliteezyAssignment = assignment;
    document.dispatchEvent(new CustomEvent('spliteezy:assigned', { detail: assignment }));
  }

  function resolveAll() {
    try {
      var containers = document.querySelectorAll('[data-eezy-test]');
      for (var i = 0; i < containers.length; i++) {
        resolveOne(containers[i]);
      }
    } finally {
      uncloak();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', resolveAll);
  } else {
    resolveAll();
  }

  // Failsafe: never leave the page hidden.
  setTimeout(uncloak, 2500);
})();
JS;
    }
}
