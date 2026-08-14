<?php
use Spliteezy\Api\Manifest;
use Spliteezy\Core\Options;

defined('ABSPATH') || exit; ?>

<?php if (! $is_configured) { ?>
<div class="eezy-wrap">
	<div class="eezy-header">
		<h1 class="eezy-header__title"><?php esc_html_e('Spliteezy', 'spliteezy'); ?></h1>
	</div>
	<div class="eezy-empty-state">
		<div class="eezy-empty-state__icon">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2">
				<path d="M24 4L4 16v16l20 12 20-12V16L24 4z"/>
				<path d="M24 4v40M4 16l20 12 20-12"/>
			</svg>
		</div>
		<h2 class="eezy-empty-state__title"><?php esc_html_e('Connect your website to Spliteezy', 'spliteezy'); ?></h2>
		<p class="eezy-empty-state__body">
			<?php esc_html_e('Add your API key to start running backend A/B tests — no flicker, no front-end redirects.', 'spliteezy'); ?>
		</p>
		<a href="<?php echo esc_url(admin_url('admin.php?page=spliteezy-settings')); ?>" class="eezy-btn eezy-btn--primary">
			<?php esc_html_e('Go to Settings', 'spliteezy'); ?>
		</a>
	</div>
</div>
<?php return; ?>
<?php } ?>

<div class="eezy-wrap" id="spliteezy-app">
	<?php
    /*
     * React mounts here and replaces this placeholder. It is the same skeleton
     * the dashboard renders while its first fetch is in flight (see
     * dashboard/src/components/Skeleton.jsx) so the handover costs no layout
     * shift — keep the two markups in step. Which one to print is decided the
     * same way App.jsx decides which page to mount: the `test` query arg.
     */
    $spliteezy_is_detail = isset($_GET['test']); // phpcs:ignore WordPress.Security -- read-only presence check on a routing arg; the value is never read, stored, or output.
?>
	<div class="eezy-app">
		<div class="eezy-wrap eezy-skeleton" role="status" aria-busy="true">
			<span class="screen-reader-text"><?php esc_html_e('Loading…', 'spliteezy'); ?></span>

			<?php if ($spliteezy_is_detail) { ?>
				<div class="eezy-header">
					<span class="eezy-skel eezy-skel--btn"></span>
					<div class="eezy-header__title-group">
						<span class="eezy-skel eezy-skel--page-title"></span>
						<span class="eezy-skel eezy-skel--page-url"></span>
					</div>
					<div class="eezy-test-actions">
						<span class="eezy-skel eezy-skel--btn"></span>
						<span class="eezy-skel eezy-skel--btn"></span>
					</div>
				</div>

				<div class="eezy-info-cards">
					<?php for ($spliteezy_i = 0; $spliteezy_i < 4; $spliteezy_i++) { ?>
						<div class="eezy-info-card">
							<span class="eezy-skel eezy-skel--card-label"></span>
							<span class="eezy-skel eezy-skel--card-value"></span>
							<span class="eezy-skel eezy-skel--card-sub"></span>
						</div>
					<?php } ?>
				</div>

				<div class="eezy-card eezy-card--flush">
					<div class="eezy-chart-section">
						<span class="eezy-skel eezy-skel--card-title"></span>
						<div class="eezy-metric-tiles">
							<?php for ($spliteezy_i = 0; $spliteezy_i < 3; $spliteezy_i++) { ?>
								<div class="eezy-metric-tile">
									<span class="eezy-skel eezy-skel--tile-label"></span>
									<span class="eezy-skel eezy-skel--tile-value"></span>
								</div>
							<?php } ?>
						</div>
						<span class="eezy-skel eezy-skel--chart"></span>
					</div>

					<div class="eezy-skeleton__perf">
						<div class="eezy-perf-header">
							<?php for ($spliteezy_i = 0; $spliteezy_i < 6; $spliteezy_i++) { ?>
								<span class="eezy-skel"></span>
							<?php } ?>
						</div>
						<?php for ($spliteezy_row = 0; $spliteezy_row < 2; $spliteezy_row++) { ?>
							<div class="eezy-perf-row">
								<?php for ($spliteezy_i = 0; $spliteezy_i < 6; $spliteezy_i++) { ?>
									<span class="eezy-skel"></span>
								<?php } ?>
							</div>
						<?php } ?>
					</div>
				</div>
			<?php } else { ?>
				<div class="eezy-header">
					<span class="eezy-skel eezy-skel--logo"></span>
					<span class="eezy-skel eezy-skel--brand"></span>
					<span class="eezy-skel eezy-skel--badge"></span>
					<span class="eezy-skel eezy-skel--btn eezy-skel--push"></span>
				</div>

				<div class="eezy-stat-grid">
					<?php for ($spliteezy_i = 0; $spliteezy_i < 3; $spliteezy_i++) { ?>
						<div class="eezy-stat-card">
							<div class="eezy-stat-card__header">
								<span class="eezy-skel eezy-skel--stat-label"></span>
								<span class="eezy-skel eezy-skel--stat-icon"></span>
							</div>
							<span class="eezy-skel eezy-skel--stat-value"></span>
							<span class="eezy-skel eezy-skel--stat-sub"></span>
							<span class="eezy-skel eezy-skel--stat-track"></span>
						</div>
					<?php } ?>
				</div>

				<div class="eezy-section-header">
					<span class="eezy-skel eezy-skel--section-title"></span>
					<div class="eezy-skeleton__actions">
						<span class="eezy-skel eezy-skel--btn"></span>
						<span class="eezy-skel eezy-skel--btn"></span>
					</div>
				</div>

				<div class="eezy-tabs">
					<?php for ($spliteezy_i = 0; $spliteezy_i < 5; $spliteezy_i++) { ?>
						<span class="eezy-skel eezy-skel--tab"></span>
					<?php } ?>
				</div>

				<div class="eezy-table-wrapper">
					<table class="eezy-table eezy-table--skeleton">
						<thead>
							<tr>
								<?php for ($spliteezy_i = 0; $spliteezy_i < 7; $spliteezy_i++) { ?>
									<th><span class="eezy-skel"></span></th>
								<?php } ?>
							</tr>
						</thead>
						<tbody>
							<?php for ($spliteezy_row = 0; $spliteezy_row < 5; $spliteezy_row++) { ?>
								<tr>
									<td><span class="eezy-skel"></span><span class="eezy-skel eezy-skel--sub"></span></td>
									<td><span class="eezy-skel"></span></td>
									<td><span class="eezy-skel"></span><span class="eezy-skel eezy-skel--sub"></span></td>
									<td><span class="eezy-skel"></span></td>
									<td><span class="eezy-skel"></span></td>
									<td><span class="eezy-skel"></span></td>
									<td><span class="eezy-skel"></span></td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			<?php } ?>
		</div>
	</div>
</div>

<?php
wp_enqueue_script(
    'spliteezy-dashboard',
    SPLITEEZY_URL.'assets/js/dashboard.js',
    ['wp-i18n'],
    SPLITEEZY_VERSION,
    true
);

wp_set_script_translations('spliteezy-dashboard', 'spliteezy', SPLITEEZY_DIR.'languages');

$spliteezy_post_types_raw = get_post_types(['public' => true, 'show_ui' => true], 'objects');
unset($spliteezy_post_types_raw['attachment']);
$spliteezy_post_types = [];
foreach ($spliteezy_post_types_raw as $spliteezy_slug => $spliteezy_obj) {
    $spliteezy_post_types[$spliteezy_slug] = $spliteezy_obj->labels->singular_name;
}

$spliteezy_manifest = Manifest::get();
$spliteezy_plan = is_array($spliteezy_manifest) ? ($spliteezy_manifest['plan'] ?? []) : [];
$spliteezy_app_url = rtrim(preg_replace('#/api/v1/plugin$#', '', Options::api_endpoint()), '/');
$spliteezy_bool = static fn (string $key): bool => ! empty($spliteezy_plan[$key]);

wp_localize_script(
    'spliteezy-dashboard',
    'SpliteezyAdmin',
    [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('spliteezy_admin'),
        'settings_url' => admin_url('admin.php?page=spliteezy-settings'),
        'billing_url' => $spliteezy_app_url.'/billing',
        'admin_url' => admin_url(),
        'site_url' => home_url(),
        'version' => SPLITEEZY_VERSION,
        'post_types' => $spliteezy_post_types,
        'plan' => [
            'name' => $spliteezy_plan['name'] ?? 'free',
            'tests_used' => (int) ($spliteezy_plan['tests_used'] ?? 0),
            'tests_limit' => isset($spliteezy_plan['tests_limit']) ? (int) $spliteezy_plan['tests_limit'] : -1,
            'scheduling' => $spliteezy_bool('scheduling'),
            'goal_page_reached' => $spliteezy_bool('goal_page_reached'),
            'goal_click' => $spliteezy_bool('goal_click'),
            'goal_scroll_depth' => $spliteezy_bool('goal_scroll_depth'),
            'goal_time_on_page' => $spliteezy_bool('goal_time_on_page'),
            'goal_element_view' => $spliteezy_bool('goal_element_view'),
            'goal_video_play' => $spliteezy_bool('goal_video_play'),
            'goal_form_submission' => $spliteezy_bool('goal_form_submission'),
            'goal_external_event' => $spliteezy_bool('goal_external_event'),
            'goal_engagement' => $spliteezy_bool('goal_engagement'),
            'feature_min_plans' => $spliteezy_plan['feature_min_plans'] ?? [],
        ],
    ]
);
