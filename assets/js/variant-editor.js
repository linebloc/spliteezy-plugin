/* Spliteezy variant editor — Gutenberg plugin for A/B variant posts.
 * Config is injected via wp_localize_script as window.spliteezyVariantCfg. */
(function (cfg) {
    var el       = wp.element.createElement;
    var useState = wp.element.useState;
    var Modal    = wp.components.Modal;
    var Button   = wp.components.Button;
    var __       = wp.i18n.__;
    var PluginPostPublishPanel =
        (wp.editor   && wp.editor.PluginPostPublishPanel) ||
        (wp.editPost && wp.editPost.PluginPostPublishPanel);

    // ── Blocking modal shown on editor load ──────────────────────────────────
    function SpliteezyVariantModal() {
        var s = useState(true), open = s[0], setOpen = s[1];
        if (!open) return null;
        return el(
            Modal,
            { title: __("You're editing a variant", 'spliteezy'), isDismissible: false, style: { maxWidth: 480 } },
            el('p', { style: { color: '#374151', lineHeight: '1.6', marginBottom: '20px' } },
                __('This post is a variant in a Spliteezy A/B test. Edit the content here and save when done — your changes will be compared against the original.', 'spliteezy')
            ),
            el('div', { style: { display: 'flex', gap: '8px', justifyContent: 'flex-end' } },
                el(Button, { variant: 'secondary', href: cfg.backUrl }, __('← Back to test', 'spliteezy')),
                el(Button, { variant: 'primary', onClick: function () { setOpen(false); } }, __('Start editing', 'spliteezy'))
            )
        );
    }
    wp.plugins.registerPlugin('spliteezy-variant-modal', { render: SpliteezyVariantModal });

    // ── Post-publish panel: replace "View Post" / "Add Post" buttons ─────────
    if (PluginPostPublishPanel) {
        function SpliteezyPublishPanel() {
            return el(
                PluginPostPublishPanel,
                { title: __("What's next?", 'spliteezy'), initialOpen: true },
                el('div', { style: { display: 'flex', flexDirection: 'column', gap: '8px', padding: '4px 0' } },
                    el(Button, { variant: 'primary', href: cfg.backUrl, style: { justifyContent: 'center' } },
                        __('See test →', 'spliteezy')),
                    el(Button, { variant: 'secondary', href: cfg.dashboardUrl, style: { justifyContent: 'center' } },
                        __('Spliteezy Dashboard', 'spliteezy'))
                )
            );
        }
        wp.plugins.registerPlugin('spliteezy-publish-panel', { render: SpliteezyPublishPanel });

        // Hide the default "POST ADDRESS" field and "View Post" / "Add Post" buttons.
        var style = document.createElement('style');
        style.textContent =
            '.editor-post-publish-panel__postpublish-subheader,' +
            '.editor-post-publish-panel__postpublish-buttons,' +
            '.post-publish-panel__postpublish-post-address' +
            '{ display: none !important; }';
        document.head.appendChild(style);
    }
}(window.spliteezyVariantCfg || {}));
