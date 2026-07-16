<?php

defined('ABSPATH') || exit;

/**
 * Variant posts listing page.
 *
 * @var array<string, WP_Post[]> $grouped Posts keyed by post_type.
 */
$spliteezy_status_badge = static function (string $status): string {
    $map = [
        'publish' => 'green',
        'draft' => 'gray',
        'pending' => 'yellow',
        'future' => 'blue',
        'private' => 'purple',
        'trash' => 'gray',
    ];
    $color = $map[$status] ?? 'gray';

    return '<span class="eezy-badge eezy-badge--'.esc_attr($color).'">'.esc_html($status).'</span>';
};
?>

<div class="eezy-wrap">
    <div class="eezy-header">
        <h1 class="eezy-header__title"><?php esc_html_e('Variant Posts', 'spliteezy'); ?></h1>
        <span class="eezy-muted" style="margin-left:12px;font-size:13px;">
            <?php esc_html_e('Posts created by Spliteezy as variant copies', 'spliteezy'); ?>
        </span>
    </div>

    <?php if (empty($grouped)) { ?>
        <div class="eezy-empty-state eezy-empty-state--inline">
            <p><?php esc_html_e('No variant posts found. Variants are created when you clone a test.', 'spliteezy'); ?></p>
        </div>
    <?php } else { ?>

        <?php foreach ($grouped as $spliteezy_ptype => $spliteezy_type_posts) { ?>
            <?php
            $spliteezy_type_obj = get_post_type_object($spliteezy_ptype);
            $spliteezy_type_label = $spliteezy_type_obj ? $spliteezy_type_obj->labels->name : $spliteezy_ptype;
            $spliteezy_is_legacy = $spliteezy_ptype === 'spliteezy_variant';
            ?>

            <div class="eezy-section-header">
                <h2 class="eezy-section-title">
                    <?php echo esc_html($spliteezy_type_label); ?>
                    <?php if ($spliteezy_is_legacy) { ?>
                        <span class="eezy-badge eezy-badge--gray" style="margin-left:8px;font-size:11px;">legacy CPT</span>
                    <?php } ?>
                </h2>
                <span class="eezy-muted" style="font-size:13px;"><?php echo esc_html(count($spliteezy_type_posts)); ?> variant<?php echo count($spliteezy_type_posts) !== 1 ? 's' : ''; ?></span>
            </div>

            <div class="eezy-table-wrapper" style="margin-bottom:24px;">
                <table class="eezy-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Variant Title', 'spliteezy'); ?></th>
                            <th><?php esc_html_e('Original Post', 'spliteezy'); ?></th>
                            <th><?php esc_html_e('Test', 'spliteezy'); ?></th>
                            <th><?php esc_html_e('Status', 'spliteezy'); ?></th>
                            <th><?php esc_html_e('Created', 'spliteezy'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($spliteezy_type_posts as $spliteezy_vpost) { ?>
                            <?php
                            $spliteezy_control_id = (int) get_post_meta($spliteezy_vpost->ID, '_spliteezy_control_post_id', true) ?: (int) $spliteezy_vpost->post_parent;
                            $spliteezy_original = $spliteezy_control_id ? get_post($spliteezy_control_id) : null;
                            $spliteezy_test_id = (string) get_post_meta($spliteezy_vpost->ID, '_spliteezy_test_id', true);
                            $spliteezy_edit_url = (string) get_edit_post_link($spliteezy_vpost->ID);
                            ?>
                            <tr class="eezy-table__row eezy-table__row--clickable" onclick="window.location='<?php echo esc_url($spliteezy_edit_url); ?>'">
                                <td>
                                    <span class="eezy-test-name">
                                        <?php echo esc_html($spliteezy_vpost->post_title ?: __('(no title)', 'spliteezy')); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($spliteezy_original) { ?>
                                        <a href="<?php echo esc_url((string) get_edit_post_link($spliteezy_original->ID)); ?>" onclick="event.stopPropagation()">
                                            <?php echo esc_html($spliteezy_original->post_title ?: __('(no title)', 'spliteezy')); ?>
                                        </a>
                                    <?php } else { ?>
                                        <span class="eezy-muted">—</span>
                                    <?php } ?>
                                </td>
                                <td>
                                    <?php if ($spliteezy_test_id) { ?>
                                        <a
                                            href="<?php echo esc_url(admin_url('admin.php?page=spliteezy&test='.urlencode($spliteezy_test_id))); ?>"
                                            onclick="event.stopPropagation()"
                                            class="eezy-muted"
                                            style="font-family:monospace;font-size:12px;"
                                        >
                                            <?php echo esc_html(substr($spliteezy_test_id, 0, 8)); ?>…
                                        </a>
                                    <?php } else { ?>
                                        <span class="eezy-muted">—</span>
                                    <?php } ?>
                                </td>
                                <td><?php echo $spliteezy_status_badge($spliteezy_vpost->post_status); // phpcs:ignore WordPress.Security.EscapeOutput -- badge HTML is fully escaped inside the closure (esc_attr/esc_html).?></td>
                                <td class="eezy-muted" style="font-size:12px;">
                                    <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($spliteezy_vpost->post_date))); ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

        <?php } ?>

    <?php } ?>
</div>
