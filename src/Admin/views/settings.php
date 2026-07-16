<?php defined('ABSPATH') || exit; ?>

<div class="eezy-wrap">
	<div class="eezy-header">
		<div class="eezy-logo">
			<svg class="eezy-logo__icon" width="26" height="26" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
				<defs>
					<linearGradient id="ezst-bg" x1="4" y1="4" x2="44" y2="44" gradientUnits="userSpaceOnUse">
						<stop offset="0" stop-color="#4335d8"/>
						<stop offset="0.55" stop-color="#5b4cf5"/>
						<stop offset="1" stop-color="#b04fe0"/>
					</linearGradient>
					<linearGradient id="ezst-win" x1="0" y1="0" x2="1" y2="1">
						<stop offset="0" stop-color="#22b8d6"/>
						<stop offset="1" stop-color="#3ec98e"/>
					</linearGradient>
				</defs>
				<rect x="4" y="4" width="40" height="40" rx="11" fill="url(#ezst-bg)"/>
				<path d="M24 34.5 V29 C24 23.5 15.5 25 15.5 19.5 M24 29 C24 23.5 32.5 25 32.5 19.5" stroke="#ffffff" stroke-opacity="0.55" stroke-width="2.2" stroke-linecap="round" fill="none"/>
				<circle cx="24" cy="37" r="3" fill="#ffffff"/>
				<rect x="10.5" y="10" width="10" height="9" rx="2.6" fill="#ffffff" fill-opacity="0.5"/>
				<rect x="27.5" y="10" width="10" height="9" rx="2.6" fill="#ffffff"/>
				<circle cx="37.2" cy="10.4" r="3.4" fill="url(#ezst-win)"/>
				<path d="M35.7 10.4 l1 1.1 2 -2.1" stroke="#ffffff" stroke-width="1.1" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
			<span class="eezy-logo__name">Split<span style="color:var(--eezy-primary);">eezy</span></span>
		</div>
		<span class="eezy-conn-badge <?php echo $is_configured ? 'eezy-conn-badge--connected' : 'eezy-conn-badge--disconnected'; ?>">
			<span class="eezy-conn-badge__dot"></span>
			<?php echo $is_configured ? esc_html__('Connected', 'spliteezy') : esc_html__('Not connected', 'spliteezy'); ?>
		</span>
		<span class="eezy-header__page-label">
			<?php esc_html_e('Settings', 'spliteezy'); ?>
		</span>
	</div>

	<?php if (isset($_GET['updated']) && $_GET['updated'] === '1') { // phpcs:ignore WordPress.Security -- read-only notice flag set by this plugin's own redirect; strict comparison against a literal, never stored or output.?>
		<div class="eezy-notice eezy-notice--success">
			<?php esc_html_e('Settings saved.', 'spliteezy'); ?>
		</div>
	<?php } ?>

	<?php if (isset($_GET['connected']) && $_GET['connected'] === '1') { // phpcs:ignore WordPress.Security -- read-only notice flag set by this plugin's own redirect; strict comparison against a literal, never stored or output.?>
		<div class="eezy-notice eezy-notice--success">
			<?php esc_html_e('Connected to Spliteezy. Your website is now linked to your account.', 'spliteezy'); ?>
		</div>
	<?php } ?>

	<?php if (isset($_GET['disconnected']) && $_GET['disconnected'] === '1') { // phpcs:ignore WordPress.Security -- read-only notice flag set by this plugin's own redirect; strict comparison against a literal, never stored or output.?>
		<div class="eezy-notice eezy-notice--success">
			<?php esc_html_e('Website disconnected. Your tests and data are kept in your Spliteezy account — reconnect anytime.', 'spliteezy'); ?>
		</div>
	<?php } ?>

	<?php
    if (isset($_GET['connect_error'])) { // phpcs:ignore WordPress.Security -- read-only notice flag set by this plugin's own redirect; resolved below against a fixed allowlist of messages.
        $spliteezy_connect_errors = [
            'denied' => __('Connection cancelled — no changes were made.', 'spliteezy'),
            'plan_limit' => __('Your Spliteezy plan has no room for another website. Upgrade your plan, then try connecting again.', 'spliteezy'),
            'state_mismatch' => __('The connection attempt could not be verified. Please start the connection again from this page.', 'spliteezy'),
            'expired_code' => __('The connection code expired. Please try connecting again.', 'spliteezy'),
            'domain_mismatch' => __('The connection was issued for a different domain. Please try connecting again from this website.', 'spliteezy'),
            'network' => __('Could not reach Spliteezy to complete the connection. Check your connectivity and try again.', 'spliteezy'),
        ];
        $spliteezy_connect_error = sanitize_key(wp_unslash($_GET['connect_error'])); // phpcs:ignore WordPress.Security -- unslashed and sanitized inline with sanitize_key; used only as an array key into the fixed allowlist above.
        ?>
		<div class="eezy-notice eezy-notice--warning">
			<?php echo esc_html($spliteezy_connect_errors[$spliteezy_connect_error] ?? __('The connection could not be completed. Please try again.', 'spliteezy')); ?>
		</div>
	<?php } ?>

	<?php if (! $is_configured) { ?>
		<div class="eezy-notice eezy-notice--info">
			<strong><?php esc_html_e('Connect Spliteezy', 'spliteezy'); ?></strong> —
			<?php esc_html_e('Link this website to your Spliteezy account with one click to start A/B testing.', 'spliteezy'); ?>
		</div>

		<!-- Connect (separate form — admin-post redirects to the Spliteezy authorize screen) -->
		<div class="eezy-card">
			<div class="eezy-card__header">
				<h2 class="eezy-card__title"><?php esc_html_e('Connect', 'spliteezy'); ?></h2>
				<p class="eezy-card__description">
					<?php esc_html_e('You will be sent to spliteezy.com to log in (or create a free account) and authorize this website. The API key is set up automatically.', 'spliteezy'); ?>
				</p>
			</div>
			<div class="eezy-card__body">
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="spliteezy_connect_start" />
					<?php wp_nonce_field('spliteezy_connect_start'); ?>
					<button type="submit" class="eezy-btn eezy-btn--primary">
						<?php esc_html_e('Connect to Spliteezy', 'spliteezy'); ?>
					</button>
				</form>
			</div>
		</div>
	<?php } ?>

	<?php if ($effective_mode === 'client') { ?>
		<div class="eezy-notice eezy-notice--warning">
			<?php if ($cache_plugin) { ?>
				<strong><?php echo esc_html($cache_plugin); ?> <?php esc_html_e('detected', 'spliteezy'); ?></strong> —
			<?php } ?>
			<?php esc_html_e('Cache-safe delivery is active: tested pages stay cacheable, but only changes inside the post content can vary between variants. Titles, custom fields, and anything rendered by your theme template (heroes, banners) will look the same in every variant. Switch to server-side delivery for full coverage.', 'spliteezy'); ?>
		</div>
	<?php } elseif ($effective_mode === 'vary') { ?>
		<div class="eezy-notice eezy-notice--info">
			<?php if ($cache_plugin) { ?>
				<strong><?php echo esc_html($cache_plugin); ?> <?php esc_html_e('detected', 'spliteezy'); ?></strong> —
			<?php } ?>
			<?php esc_html_e('Cache-per-variant delivery is active: tested pages stay cached, with one cached copy per variant. Titles, custom fields, and template-rendered content all vary. Visitors assigned to a variant reach their copy through a cached variant address that never shows in the address bar.', 'spliteezy'); ?>
		</div>
		<?php if ($layered_host_cache) { ?>
			<div class="eezy-notice eezy-notice--info">
				<strong><?php echo esc_html($layered_host_cache); ?> <?php esc_html_e('host cache detected', 'spliteezy'); ?></strong> —
				<?php esc_html_e('the host cache keys on URLs only, so returning variant visitors are routed through a quick redirect instead of a direct cache hit. This is automatic and needs no configuration. Optional, to remove the hop on WP Engine with NitroPack: exclude tested URLs from the host cache, or add this line to wp-config.php:', 'spliteezy'); ?>
				<code>define('NITROPACK_CACHE_CONTROL_OVERRIDE', 'no-cache, must-revalidate, max-age=0');</code>
			</div>
		<?php } ?>
	<?php } elseif ($cache_plugin && $effective_mode === 'server') { ?>
		<div class="eezy-notice eezy-notice--info">
			<strong><?php echo esc_html($cache_plugin); ?> <?php esc_html_e('detected', 'spliteezy'); ?></strong> —
			<?php esc_html_e('Pages with a running test are automatically excluded from page caching, so every visitor gets their own variant. All other pages stay cached as usual.', 'spliteezy'); ?>
			<?php if (strpos($cache_plugin, 'NitroPack') !== false) { ?>
				<?php esc_html_e('NitroPack serves pages from its own CDN and may ignore this — also add your tested page URLs to NitroPack\'s "Excluded URLs" setting.', 'spliteezy'); ?>
			<?php } ?>
		</div>
	<?php } ?>

	<?php if ($nitropack_sync !== null) { ?>
		<div class="eezy-notice eezy-notice--info">
			<strong><?php esc_html_e('NitroPack sync status', 'spliteezy'); ?></strong> —
			<?php esc_html_e('excluded URLs active on this site:', 'spliteezy'); ?>
			<?php echo $nitropack_sync['excluded_urls'] ? esc_html(implode(', ', $nitropack_sync['excluded_urls'])) : esc_html__('none', 'spliteezy'); ?>
			· <?php esc_html_e('variation cookies:', 'spliteezy'); ?>
			<?php echo $nitropack_sync['vary_cookies'] ? esc_html(implode(', ', $nitropack_sync['vary_cookies'])) : esc_html__('none', 'spliteezy'); ?>.
			<?php esc_html_e('Changes made through the NitroPack dashboard or by Spliteezy reach this list within a few minutes. In "Cache per variant" mode, tested URLs must NOT appear as excluded and each active test needs its eezy_v_ cookie listed here.', 'spliteezy'); ?>
		</div>
	<?php } ?>

	<?php if ($is_configured) { ?>
		<!-- Connection (outside the settings form — connect/disconnect are their own admin-post actions) -->
		<div class="eezy-card">
			<div class="eezy-card__header">
				<h2 class="eezy-card__title"><?php esc_html_e('Connection', 'spliteezy'); ?></h2>
			</div>
			<div class="eezy-card__body">
				<div class="eezy-connection-status" id="eezy-connection-status">
					<span class="eezy-connection-status__dot eezy-connection-status__dot--idle"></span>
					<span class="eezy-connection-status__text" id="eezy-connection-text">
						<?php esc_html_e('Not tested yet', 'spliteezy'); ?>
					</span>
					<button type="button" class="eezy-btn eezy-btn--ghost" id="eezy-test-connection">
						<?php esc_html_e('Check connection', 'spliteezy'); ?>
					</button>
				</div>

				<div class="eezy-connection-actions">
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<input type="hidden" name="action" value="spliteezy_connect_start" />
						<?php wp_nonce_field('spliteezy_connect_start'); ?>
						<button type="submit" class="eezy-btn eezy-btn--ghost">
							<?php esc_html_e('Reconnect', 'spliteezy'); ?>
						</button>
					</form>
					<form
						method="post"
						action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
						onsubmit="return confirm('<?php echo esc_attr__('Disconnect this website from Spliteezy? Running tests will stop being served until you reconnect.', 'spliteezy'); ?>');"
					>
						<input type="hidden" name="action" value="spliteezy_disconnect" />
						<?php wp_nonce_field('spliteezy_disconnect'); ?>
						<button type="submit" class="eezy-btn eezy-btn--ghost eezy-btn--danger">
							<?php esc_html_e('Disconnect', 'spliteezy'); ?>
						</button>
					</form>
				</div>
				<p class="eezy-field__help">
					<?php esc_html_e('Reconnect issues a fresh API key. Disconnect unlinks this website — tests and data are kept in your Spliteezy account.', 'spliteezy'); ?>
				</p>
			</div><!-- .eezy-card__body -->
		</div>
	<?php } ?>

	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action" value="spliteezy_save_settings" />
		<?php wp_nonce_field('spliteezy_settings_save'); ?>

		<?php if ($dev_mode) { ?>
		<!-- API Endpoint (dev mode only) -->
		<div class="eezy-card">
			<div class="eezy-card__header">
				<h2 class="eezy-card__title"><?php esc_html_e('API Endpoint', 'spliteezy'); ?></h2>
			</div>
			<div class="eezy-card__body">
			<div class="eezy-field">
				<label class="eezy-field__label" for="eezy-api-endpoint">
					<?php esc_html_e('API Endpoint', 'spliteezy'); ?>
				</label>
				<input
					id="eezy-api-endpoint"
					class="eezy-field__input eezy-field__input--full"
					type="url"
					name="api_endpoint"
					value="<?php echo esc_attr($api_endpoint); ?>"
				/>
				<p class="eezy-field__help">
					<?php esc_html_e('Only change this if you are self-hosting Spliteezy.', 'spliteezy'); ?>
				</p>
			</div>
			</div><!-- .eezy-card__body -->
		</div>
		<?php } ?>

		<!-- Delivery Mode -->
		<div class="eezy-card">
			<div class="eezy-card__header">
				<h2 class="eezy-card__title"><?php esc_html_e('Delivery Mode', 'spliteezy'); ?></h2>
				<p class="eezy-card__description">
					<?php esc_html_e('How variants are delivered to visitors.', 'spliteezy'); ?>
				</p>
			</div>
			<div class="eezy-card__body">
				<?php $spliteezy_mode_choice = in_array($delivery_mode, ['client', 'vary'], true) ? $delivery_mode : 'server'; // Legacy 'auto' resolves to server.?>
				<div class="eezy-checkbox-group">
					<label class="eezy-checkbox eezy-radio-card">
						<input type="radio" name="delivery_mode" value="server" <?php checked($spliteezy_mode_choice, 'server'); ?> />
						<span class="eezy-checkbox__label"><?php esc_html_e('Server-side (recommended)', 'spliteezy'); ?></span>
						<span class="eezy-checkbox__slug">
							<?php esc_html_e('Variants swap in PHP before any output — no flicker, and titles, custom fields, and template-rendered content all vary. Pages with a running test are automatically excluded from page caching.', 'spliteezy'); ?>
						</span>
					</label>
					<label class="eezy-checkbox eezy-radio-card">
						<input type="radio" name="delivery_mode" value="vary" <?php checked($spliteezy_mode_choice, 'vary'); ?> />
						<span class="eezy-checkbox__label"><?php esc_html_e('Cache per variant', 'spliteezy'); ?></span>
						<span class="eezy-checkbox__slug">
							<?php esc_html_e('Full variant coverage like server-side, and tested pages stay cached: each variant gets its own cached copy, keyed by URL so it works with any page cache or host. Variant visitors are routed to their copy with a brief one-time redirect (served directly from cache on NitroPack and WP Rocket).', 'spliteezy'); ?>
						</span>
					</label>
					<label class="eezy-checkbox eezy-radio-card">
						<input type="radio" name="delivery_mode" value="client" <?php checked($spliteezy_mode_choice, 'client'); ?> />
						<span class="eezy-checkbox__label"><?php esc_html_e('Cache-safe (client-side)', 'spliteezy'); ?></span>
						<span class="eezy-checkbox__slug">
							<?php esc_html_e('Tested pages stay cacheable: they embed every variant and the browser assigns instantly. Only content inside the post content can vary between variants.', 'spliteezy'); ?>
						</span>
					</label>
				</div>
				<p class="eezy-field__help">
					<?php esc_html_e('The same visitor always gets the same variant in every mode.', 'spliteezy'); ?>
				</p>
			</div><!-- .eezy-card__body -->
		</div>

		<!-- Post Types + Permissions (side by side) -->
		<div class="eezy-cards-row">

			<div class="eezy-card">
				<div class="eezy-card__header">
					<h2 class="eezy-card__title"><?php esc_html_e('Post Types', 'spliteezy'); ?></h2>
					<p class="eezy-card__description">
						<?php esc_html_e('Select which post types can be used in A/B tests.', 'spliteezy'); ?>
					</p>
				</div>
				<div class="eezy-card__body">
				<div class="eezy-checkbox-group eezy-checkbox-group--grid">
					<?php foreach ($post_types as $spliteezy_type_key => $spliteezy_type_label) { ?>
						<label class="eezy-checkbox">
							<input
								type="checkbox"
								name="enabled_post_types[]"
								value="<?php echo esc_attr($spliteezy_type_key); ?>"
								<?php checked(in_array($spliteezy_type_key, $enabled_types, true)); ?>
							/>
							<span class="eezy-checkbox__label"><?php echo esc_html($spliteezy_type_label); ?></span>
							<span class="eezy-checkbox__slug">(<?php echo esc_html($spliteezy_type_key); ?>)</span>
						</label>
					<?php } ?>
				</div>
				</div><!-- .eezy-card__body -->
			</div>

			<div class="eezy-card">
				<div class="eezy-card__header">
					<h2 class="eezy-card__title"><?php esc_html_e('Permissions', 'spliteezy'); ?></h2>
					<p class="eezy-card__description">
						<?php esc_html_e('Control which roles can access Spliteezy features.', 'spliteezy'); ?>
					</p>
				</div>
				<div class="eezy-card__body">
				<div class="eezy-perm-table">
					<div class="eezy-perm-row eezy-perm-row--header">
						<span class="eezy-perm-row__role"></span>
						<span class="eezy-perm-row__caps">
							<span><?php esc_html_e('View', 'spliteezy'); ?></span>
							<span><?php esc_html_e('Create', 'spliteezy'); ?></span>
							<span><?php esc_html_e('Edit', 'spliteezy'); ?></span>
						</span>
					</div>
					<?php foreach ($roles as $spliteezy_role_slug => $spliteezy_role_label) { ?>
						<?php $spliteezy_is_admin = $spliteezy_role_slug === 'administrator'; ?>
						<div class="eezy-perm-row <?php echo $spliteezy_is_admin ? 'eezy-perm-row--admin' : ''; ?>">
							<span class="eezy-perm-row__role"><?php echo esc_html($spliteezy_role_label); ?></span>
							<span class="eezy-perm-row__caps">
								<label class="eezy-perm-cap">
									<input
										type="checkbox"
										name="permissions[view_roles][]"
										value="<?php echo esc_attr($spliteezy_role_slug); ?>"
										<?php checked($spliteezy_is_admin || in_array($spliteezy_role_slug, $permissions['view_roles'], true)); ?>
										<?php disabled($spliteezy_is_admin); ?>
									/>
								</label>
								<label class="eezy-perm-cap">
									<input
										type="checkbox"
										name="permissions[create_roles][]"
										value="<?php echo esc_attr($spliteezy_role_slug); ?>"
										<?php checked($spliteezy_is_admin || in_array($spliteezy_role_slug, $permissions['create_roles'], true)); ?>
										<?php disabled($spliteezy_is_admin); ?>
									/>
								</label>
								<label class="eezy-perm-cap">
									<input
										type="checkbox"
										name="permissions[edit_roles][]"
										value="<?php echo esc_attr($spliteezy_role_slug); ?>"
										<?php checked($spliteezy_is_admin || in_array($spliteezy_role_slug, $permissions['edit_roles'], true)); ?>
										<?php disabled($spliteezy_is_admin); ?>
									/>
								</label>
							</span>
						</div>
					<?php } ?>
				</div><!-- .eezy-perm-table -->
				</div><!-- .eezy-card__body -->
			</div><!-- .eezy-card -->

		</div><!-- .eezy-cards-row -->


		<div class="eezy-actions">
			<button type="submit" class="eezy-btn eezy-btn--primary">
				<?php esc_html_e('Save Settings', 'spliteezy'); ?>
			</button>
		</div>
	</form>
</div>

