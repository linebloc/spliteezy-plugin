<?php

namespace Spliteezy\Api;

use Spliteezy\Core\Options;

defined('ABSPATH') || exit;

/**
 * HTTP client for communicating with the Spliteezy Laravel API.
 * All requests are HMAC-signed with the site API key.
 */
class Client
{
    private string $api_key;

    private string $endpoint;

    public function __construct()
    {
        $this->api_key = Options::api_key();
        $this->endpoint = Options::api_endpoint();
    }

    /**
     * Fetch the active test manifest for this site.
     *
     * @return array<string, mixed>|null Returns null on failure.
     */
    public function get_manifest(): ?array
    {
        $response = $this->get('/manifest');

        if ($response === null) {
            return null;
        }

        return $response['data'] ?? null;
    }

    /**
     * Test the API connection and return a status array with details.
     * Used by the settings page connection check.
     *
     * @return array{ok: bool, code: int|null, message: string}
     */
    public function test_connection(): array
    {
        if (! $this->api_key) {
            return [
                'ok' => false,
                'code' => null,
                'message' => __('No API key configured.', 'spliteezy'),
            ];
        }

        $path = '/manifest';
        $url = $this->endpoint.$path;
        $timestamp = time();
        $full_path = wp_parse_url($url, PHP_URL_PATH);
        $signature = $this->sign('GET', $full_path, '', $timestamp);

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 8,
                'sslverify' => self::should_verify_ssl($this->endpoint),
                'headers' => $this->headers($signature, $timestamp),
            ]
        );

        $this->record_version_notice($response);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'code' => null,
                'message' => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code === 200) {
            return [
                'ok' => true,
                'code' => $code,
                'message' => __('Connected successfully.', 'spliteezy'),
            ];
        }

        // Surface the API's own error message when available.
        $api_message = is_array($data) ? ($data['message'] ?? null) : null;

        $known = [
            401 => __('Invalid API key.', 'spliteezy'),
            403 => __('Domain mismatch — this WordPress domain is not registered for this API key.', 'spliteezy'),
            429 => __('Rate limit reached.', 'spliteezy'),
            502 => __('Could not reach the Spliteezy API server.', 'spliteezy'),
        ];

        return [
            'ok' => false,
            'code' => $code,
            /* translators: %d: HTTP status code returned by the API. */
            'message' => $api_message ?? $known[$code] ?? sprintf(__('Unexpected response (HTTP %d).', 'spliteezy'), $code),
        ];
    }

    /**
     * Register a new A/B test and return the test UUID on success.
     *
     * @param  array<string, mixed>  $payload
     */
    public function create_test(array $payload): ?string
    {
        $response = $this->post('/tests', $payload);

        if (! isset($response['data']['id'])) {
            return null;
        }

        return (string) $response['data']['id'];
    }

    /**
     * Fetch a single test by UUID, bypassing the manifest transient.
     * Returns fresh visitor/conversion counts suitable for the dashboard detail view.
     *
     * @return array<string, mixed>|null
     */
    public function get_test(string $test_id): ?array
    {
        $response = $this->get('/tests/'.rawurlencode($test_id));

        if ($response === null) {
            return null;
        }

        return $response['data'] ?? null;
    }

    /**
     * Fetch all tests for this site (all statuses), plus plan data.
     *
     * @return array<string, mixed>|null
     */
    public function get_tests(): ?array
    {
        $response = $this->get('/tests');

        if ($response === null) {
            return null;
        }

        return $response['data'] ?? null;
    }

    /**
     * Update a test's name and/or split percentage.
     *
     * @param  array<string, mixed>  $payload  Accepts 'name' (string) and/or 'split' (int 10-90).
     */
    public function update_test(string $test_id, array $payload): bool
    {
        $response = $this->patch('/tests/'.rawurlencode($test_id), $payload);

        return $response !== null;
    }

    /**
     * Update a test's status (active / paused / ended).
     */
    public function update_test_status(string $test_id, string $status): bool
    {
        $response = $this->patch('/tests/'.rawurlencode($test_id).'/status', ['status' => $status]);

        return isset($response['data']);
    }

    /**
     * Hard-delete a test.
     */
    public function delete_test(string $test_id): bool
    {
        return $this->delete_request('/tests/'.rawurlencode($test_id));
    }

    /**
     * Send a batch of tracker events to the API.
     *
     * @param  array<array<string, mixed>>  $events
     */
    public function send_events(array $events): bool
    {
        $response = $this->post('/events', ['events' => $events]);

        return $response !== null;
    }

    /**
     * Revoke this site's API key server-side (called on Disconnect).
     */
    public function disconnect(): bool
    {
        $response = $this->post('/disconnect', []);

        return $response !== null;
    }

    // ------------------------------------------------------------------
    // HTTP primitives
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function get(string $path): ?array
    {
        if (! $this->api_key) {
            return null;
        }

        $url = $this->endpoint.$path;
        $timestamp = time();
        $full_path = wp_parse_url($url, PHP_URL_PATH);
        $signature = $this->sign('GET', $full_path, '', $timestamp);

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 5,
                'sslverify' => self::should_verify_ssl($this->endpoint),
                'headers' => $this->headers($signature, $timestamp),
            ]
        );

        return $this->parse_response($response);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function post(string $path, array $body): ?array
    {
        if (! $this->api_key) {
            return null;
        }

        $url = $this->endpoint.$path;
        $timestamp = time();
        $json_body = wp_json_encode($body);
        $full_path = wp_parse_url($url, PHP_URL_PATH);
        $signature = $this->sign('POST', $full_path, $json_body, $timestamp);

        $response = wp_remote_post(
            $url,
            [
                'timeout' => 5,
                'sslverify' => self::should_verify_ssl($this->endpoint),
                'headers' => array_merge(
                    $this->headers($signature, $timestamp),
                    ['Content-Type' => 'application/json']
                ),
                'body' => $json_body,
            ]
        );

        return $this->parse_response($response);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function patch(string $path, array $body): ?array
    {
        if (! $this->api_key) {
            return null;
        }

        $url = $this->endpoint.$path;
        $timestamp = time();
        $json_body = wp_json_encode($body);
        $full_path = wp_parse_url($url, PHP_URL_PATH);
        $signature = $this->sign('PATCH', $full_path, $json_body, $timestamp);

        $response = wp_remote_request(
            $url,
            [
                'method' => 'PATCH',
                'timeout' => 5,
                'sslverify' => self::should_verify_ssl($this->endpoint),
                'headers' => array_merge(
                    $this->headers($signature, $timestamp),
                    ['Content-Type' => 'application/json']
                ),
                'body' => $json_body,
            ]
        );

        return $this->parse_response($response);
    }

    private function delete_request(string $path): bool
    {
        if (! $this->api_key) {
            return false;
        }

        $url = $this->endpoint.$path;
        $timestamp = time();
        $full_path = wp_parse_url($url, PHP_URL_PATH);
        $signature = $this->sign('DELETE', $full_path, '', $timestamp);

        $response = wp_remote_request(
            $url,
            [
                'method' => 'DELETE',
                'timeout' => 5,
                'sslverify' => self::should_verify_ssl($this->endpoint),
                'headers' => $this->headers($signature, $timestamp),
            ]
        );

        $this->record_version_notice($response);

        if (is_wp_error($response)) {
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        return $code >= 200 && $code < 300;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $signature, int $timestamp): array
    {
        return [
            'X-Spliteezy-Key' => $this->api_key,
            'X-Spliteezy-Timestamp' => (string) $timestamp,
            'X-Spliteezy-Signature' => $signature,
            'X-Spliteezy-Domain' => wp_parse_url(home_url(), PHP_URL_HOST),
            'X-Spliteezy-Plugin-Version' => SPLITEEZY_VERSION,
            'X-Spliteezy-Timezone' => wp_timezone_string(),
            'Accept' => 'application/json',
        ];
    }

    /**
     * HMAC-SHA256 signature: METHOD|path|body|timestamp signed with the API key.
     */
    private function sign(string $method, string $path, string $body, int $timestamp): string
    {
        $payload = implode('|', [strtoupper($method), $path, $body, (string) $timestamp]);

        return hash_hmac('sha256', $payload, $this->api_key);
    }

    /**
     * Disable SSL verification for local development endpoints.
     * .test / localhost / 127.0.0.1 use self-signed certs that PHP rejects.
     */
    public static function should_verify_ssl(string $url): bool
    {
        $host = wp_parse_url($url, PHP_URL_HOST) ?? '';

        $local_patterns = ['.test', '.local', '.localhost', 'localhost', '127.0.0.1', '::1'];

        foreach ($local_patterns as $pattern) {
            if (str_contains($host, $pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|\WP_Error  $response
     * @return array<string, mixed>|null
     */
    private function parse_response($response): ?array
    {
        $this->last_error = null;

        $this->record_version_notice($response);

        if (is_wp_error($response)) {
            $this->last_error = [
                'message' => $response->get_error_message(),
                'code' => 'network_error',
                'status' => 0,
            ];

            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            // Keep the API's structured error (message/code/meta) so admin
            // screens can show the real reason instead of a generic failure.
            $this->last_error = [
                'message' => is_array($data) && ! empty($data['message']) ? (string) $data['message'] : '',
                'code' => is_array($data) && ! empty($data['code']) ? (string) $data['code'] : 'api_error',
                'status' => (int) $code,
            ];

            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Persists the API's version-compatibility signal so AdminMenu can show
     * an update notice without making its own request.
     *
     * @param  array<string, mixed>|\WP_Error  $response
     */
    private function record_version_notice($response): void
    {
        if (is_wp_error($response)) {
            return;
        }

        $latest = wp_remote_retrieve_header($response, 'x-spliteezy-latest-version');

        if (! $latest) {
            return;
        }

        Options::update([
            'update_notice' => [
                'recommended' => (bool) wp_remote_retrieve_header($response, 'x-spliteezy-update-recommended'),
                'latest_version' => (string) $latest,
            ],
        ]);
    }

    /**
     * Details of the last failed request, or null when the last request
     * succeeded. Shape: ['message' => string, 'code' => string, 'status' => int].
     *
     * @var array<string, mixed>|null
     */
    private $last_error = null;

    /**
     * @return array<string, mixed>|null
     */
    public function last_error(): ?array
    {
        return $this->last_error;
    }

    /**
     * The last API error message, or the given fallback.
     */
    public function last_error_message(string $fallback): string
    {
        $message = $this->last_error['message'] ?? '';

        return $message !== '' ? $message : $fallback;
    }
}
