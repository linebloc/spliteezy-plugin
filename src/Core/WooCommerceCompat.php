<?php

namespace Spliteezy\Core;

use Spliteezy\Api\Manifest;

defined('ABSPATH') || exit;

/**
 * WooCommerce price integration for product tests. A visitor assigned to a
 * variant must see — and be charged — the variant's price everywhere:
 * product page, cart, checkout, and the resulting order. Price getters are
 * filtered on every front-end request from the visitor's sticky assignment,
 * so totals stay consistent after the visitor leaves the tested page.
 * Visitors without an assignment cookie always get control pricing.
 */
class WooCommerceCompat
{
    /** @var array<int, array{price: string, regular: string, sale: string}> */
    private array $overrides = [];

    public function register(): void
    {
        if (! class_exists('WooCommerce')) {
            return;
        }

        add_action('wp_loaded', [$this, 'setup_price_overrides']);
    }

    public function setup_price_overrides(): void
    {
        if (is_admin() && ! wp_doing_ajax()) {
            return;
        }

        if (! Options::is_configured()) {
            return;
        }

        $manifest = Manifest::get();

        foreach (is_array($manifest) ? ($manifest['tests'] ?? []) : [] as $test) {
            if (($test['status'] ?? '') !== 'active' || empty($test['id'])) {
                continue;
            }

            $target = (int) ($test['target_post_id'] ?? 0);

            if (! $target || get_post_type($target) !== 'product') {
                continue;
            }

            $variants = is_array($test['variants'] ?? null) ? $test['variants'] : [];
            $index = $this->assigned_index((string) $test['id'], $variants);

            if (! $index) {
                continue;
            }

            $variant_pid = (int) ($variants[$index]['post_id'] ?? 0);

            if (! $variant_pid) {
                continue;
            }

            $prices = [
                'price' => (string) get_post_meta($variant_pid, '_price', true),
                'regular' => (string) get_post_meta($variant_pid, '_regular_price', true),
                'sale' => (string) get_post_meta($variant_pid, '_sale_price', true),
            ];

            if ($prices['price'] === '' && $prices['regular'] === '') {
                continue;
            }

            $this->overrides[$target] = $prices;
        }

        if (empty($this->overrides)) {
            return;
        }

        add_filter('woocommerce_product_get_price', [$this, 'filter_price'], 20, 2);
        add_filter('woocommerce_product_get_regular_price', [$this, 'filter_regular_price'], 20, 2);
        add_filter('woocommerce_product_get_sale_price', [$this, 'filter_sale_price'], 20, 2);
    }

    /**
     * @param  mixed  $price
     * @param  \WC_Product  $product
     * @return mixed
     */
    public function filter_price($price, $product)
    {
        $override = $this->overrides[$product->get_id()] ?? null;

        return $override && $override['price'] !== '' ? $override['price'] : $price;
    }

    /**
     * @param  mixed  $price
     * @param  \WC_Product  $product
     * @return mixed
     */
    public function filter_regular_price($price, $product)
    {
        $override = $this->overrides[$product->get_id()] ?? null;

        return $override && $override['regular'] !== '' ? $override['regular'] : $price;
    }

    /**
     * The sale price is overridden even when empty — an empty value means
     * the variant deliberately runs without a sale.
     *
     * @param  mixed  $price
     * @param  \WC_Product  $product
     * @return mixed
     */
    public function filter_sale_price($price, $product)
    {
        $override = $this->overrides[$product->get_id()] ?? null;

        return $override !== null ? $override['sale'] : $price;
    }

    /**
     * The visitor's assigned variant index for a test, or null without a
     * usable assignment. The vary-mode cookie is authoritative; otherwise
     * the index derives from the visitor cookie, and a visitor who has
     * never been assigned keeps control pricing.
     */
    private function assigned_index(string $test_id, array $variants): ?int
    {
        if (count($variants) < 2) {
            return null;
        }

        $vary = VaryRenderer::bucket_from_cookie($test_id, count($variants));

        if ($vary !== null) {
            return $vary;
        }

        if (! isset($_COOKIE['spliteezy_vid'])) {
            return null;
        }

        $vid = sanitize_text_field(wp_unslash($_COOKIE['spliteezy_vid']));

        if (! preg_match('/^eezy_[a-f0-9]{32}$/', $vid)) {
            return null;
        }

        return Visitor::assign_variant($test_id, array_column($variants, 'weight'));
    }
}
