<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * POT_Api_Settings — the managed-secret admin surface for the pull API.
 *
 * Renders a submenu under the theme-owned `parkourone` menu (cap manage_options) that
 * shows the endpoint URL (rest_url('pot/v1/metrics')) and the API Bearer secret MASKED
 * by default, plus a "Secret neu generieren" action. Rotation is CSRF-protected
 * (check_admin_referer) and capability-gated (current_user_can('manage_options')) because
 * the secret is the credential to the external endpoint.
 *
 * The plaintext secret is never echoed unmasked into the page on first load and is NEVER
 * logged. Regenerating breaks any configured Statusboard until the new secret is re-pasted.
 */
class POT_Api_Settings {

    const PAGE_SLUG  = 'pot-api-settings';
    const NONCE_NAME = 'pot_regenerate_secret';

    public static function init() {
        // Priority 1000 so POT_Admin (999) has already registered the fallback standalone
        // parent (POT_Admin::MENU_SLUG) by the time we resolve our parent — the page is NEVER
        // orphaned (falls back to the standalone Campaign-Tracking top menu).
        add_action('admin_menu', [__CLASS__, 'add_menu_page'], 1000);
        add_action('admin_post_pot_regenerate_secret', [__CLASS__, 'handle_regenerate']);
    }

    public static function add_menu_page() {
        // Register under the shared `parkourone` parent if it exists; otherwise hang under the
        // standalone Campaign-Tracking top menu POT_Admin created in its own fallback (999) —
        // so the page is NEVER orphaned. Only the parent slug differs between the branches.
        if (!empty($GLOBALS['admin_page_hooks']['parkourone'])) {
            add_submenu_page(
                'parkourone',
                'API / Statusboard',
                'API / Statusboard',
                'manage_options',
                self::PAGE_SLUG,
                [__CLASS__, 'render_page']
            );
        } else {
            add_submenu_page(
                POT_Admin::MENU_SLUG,
                'API / Statusboard',
                'API / Statusboard',
                'manage_options',
                self::PAGE_SLUG,
                [__CLASS__, 'render_page']
            );
        }
    }

    public static function render_page() {
        // Capability re-check before any output.
        if (!current_user_can('manage_options')) {
            return;
        }

        $endpoint = rest_url('pot/v1/metrics');
        $secret   = POT_Api::get_or_create_secret();
        // Masked preview: first 4 chars + dots. The full value lives only in the readonly
        // password field (hidden by default) and the copy button — never in visible text.
        $masked = substr($secret, 0, 4) . str_repeat('•', max(0, strlen($secret) - 4));

        $regenerate_url = wp_nonce_url(
            admin_url('admin-post.php?action=pot_regenerate_secret'),
            self::NONCE_NAME
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html('API / Statusboard'); ?></h1>

            <?php if (isset($_GET['pot_secret']) && $_GET['pot_secret'] === 'regenerated') : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php echo esc_html('Neuer Secret generiert. Der Statusboard-Empfänger muss mit dem neuen Secret aktualisiert werden.'); ?>
                </p></div>
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html('Endpoint-URL'); ?></th>
                    <td>
                        <input type="text" class="regular-text code" readonly
                               value="<?php echo esc_url($endpoint); ?>"
                               onclick="this.select();" />
                        <p class="description">
                            <?php echo esc_html('GET — gesichert per Bearer-Token (Authorization-Header).'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html('API-Secret'); ?></th>
                    <td>
                        <code><?php echo esc_html($masked); ?></code>
                        <input type="password" id="pot-api-secret" class="regular-text code"
                               style="display:none;" readonly
                               value="<?php echo esc_attr($secret); ?>" />
                        <button type="button" class="button"
                                onclick="var f=document.getElementById('pot-api-secret');f.style.display='inline-block';f.type='text';f.select();">
                            <?php echo esc_html('Anzeigen / Kopieren'); ?>
                        </button>
                        <p class="description">
                            <?php echo esc_html('Bearer-Token für den Statusboard-Empfänger. Wird neu generieren bricht eine bestehende Statusboard-Verbindung, bis das neue Secret dort eingetragen ist.'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="pot_regenerate_secret" />
                <?php wp_nonce_field(self::NONCE_NAME); ?>
                <p>
                    <button type="submit" class="button button-secondary">
                        <?php echo esc_html('Secret neu generieren'); ?>
                    </button>
                </p>
            </form>

            <?php
            // GitHub-updater UI (version display + manual check/update button). Use the shared
            // singleton — never `new` — so the constructor's admin_init/admin_notices hooks are
            // not registered twice. class_exists guard keeps this page graceful if the updater
            // require is ever absent. render_admin_section() echoes already-escaped output.
            if (class_exists('POT_GitHub_Updater')) {
                POT_GitHub_Updater::get_instance()->render_admin_section();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Rotate pot_api_secret. CSRF + capability gated; keeps autoload=false. Never logs the
     * secret value.
     */
    public static function handle_regenerate() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer(self::NONCE_NAME);

        update_option('pot_api_secret', wp_generate_password(32, false), false);

        wp_safe_redirect(
            add_query_arg(
                'pot_secret',
                'regenerated',
                wp_get_referer() ?: admin_url('admin.php?page=' . self::PAGE_SLUG)
            )
        );
        exit;
    }
}
