<?php
/**
 * ParkourONE Campaign Tracking - GitHub Auto-Updater
 * Automatische Updates direkt von GitHub (gleiches Pattern wie parkourone-theme)
 */

if (!defined('ABSPATH')) exit;

class POT_GitHub_Updater {

    /** Shared singleton instance — exactly one constructor run, one set of hooks. */
    private static $instance = null;

    private $github_repo = 'Ghosttracer8020/parkourone-campaign-tracking';
    private $plugin_slug = 'parkourone-campaign-tracking';
    private $check_interval = 3600; // 1 Stunde in Sekunden
    private $transient_key = 'pot_github_update_check';
    private $last_error = null;

    /**
     * House-style singleton accessor (mirrors AB_Email_Customizer::get_instance()). The
     * bootstrap line and the dashboard embed both call this, so admin_init/admin_notices
     * hooks are registered once — never doubled.
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        if (!is_admin()) {
            return;
        }

        if (!wp_doing_ajax()) {
            add_action('admin_init', [$this, 'maybe_auto_update']);
        }

        add_action('admin_init', [$this, 'handle_manual_check']);
        add_action('admin_init', [$this, 'handle_save_auto_update']);
        add_action('admin_notices', [$this, 'show_update_notice']);
    }

    /**
     * Holt den letzten Webhook-Eintrag für dieses Plugin
     */
    private function get_last_webhook_entry() {
        $log = get_option('parkourone_webhook_updates', []);
        $repo = $this->github_repo;

        for ($i = count($log) - 1; $i >= 0; $i--) {
            if (isset($log[$i]['repo']) && $log[$i]['repo'] === $repo) {
                return $log[$i];
            }
        }
        return null;
    }

    /**
     * Rendert die Update-Sektion (wird vom Admin-Menü eingebettet)
     */
    public function render_admin_section() {
        $local_version = $this->get_local_version();
        $last_update = get_option($this->plugin_slug . '_last_update');
        $last_webhook = $this->get_last_webhook_entry();

        $has_token = !empty(get_option('parkourone_github_token', ''));
        $remote_version = null;
        if ($has_token) {
            $remote_version = $this->get_remote_version();
        }

        $webhook_ok = ($last_webhook && $local_version !== 'unknown' && $local_version === $last_webhook['version']);

        ?>
        <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); max-width: 600px; margin-top: 20px;">

            <h2 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                ParkourONE Campaign Tracking
                <?php if ($webhook_ok): ?>
                    <span style="background: #46b450; color: #fff; font-size: 11px; padding: 2px 8px; border-radius: 4px; font-weight: normal;">Aktuell</span>
                <?php elseif ($last_webhook): ?>
                    <span style="background: #f0b849; color: #fff; font-size: 11px; padding: 2px 8px; border-radius: 4px; font-weight: normal;">Prüfen</span>
                <?php else: ?>
                    <span style="background: #999; color: #fff; font-size: 11px; padding: 2px 8px; border-radius: 4px; font-weight: normal;">Kein Webhook</span>
                <?php endif; ?>
            </h2>

            <?php
            if (isset($_GET['pot_updated']) && $_GET['pot_updated'] === '1') {
                $version = isset($_GET['pot_version']) ? sanitize_text_field($_GET['pot_version']) : '';
                echo '<div class="notice notice-success"><p><strong>Erfolg!</strong> Plugin auf Version <code>' . esc_html($version) . '</code> aktualisiert.</p></div>';
            } elseif (isset($_GET['pot_uptodate']) && $_GET['pot_uptodate'] === '1') {
                echo '<div class="notice notice-info"><p>Plugin ist bereits aktuell.</p></div>';
            } elseif (isset($_GET['pot_error'])) {
                echo '<div class="notice notice-error"><p><strong>Fehler:</strong> Update fehlgeschlagen. Siehe Error-Log.</p></div>';
            }
            ?>

            <table class="form-table" style="margin: 0;">
                <tr>
                    <th>Installierte Version:</th>
                    <td>
                        <code style="font-size: 14px;"><?php echo esc_html($local_version); ?></code>
                        <?php if ($local_version === 'unknown'): ?>
                            <br><small style="color: #999;">Noch kein Webhook empfangen — wird beim nächsten Push gesetzt</small>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Letzter Webhook-Push:</th>
                    <td>
                        <?php if ($last_webhook): ?>
                            <code style="font-size: 14px;"><?php echo esc_html($last_webhook['version']); ?></code>
                            <span style="color: #666; margin-left: 8px;">
                                <?php echo esc_html($last_webhook['time']); ?>
                                (vor <?php echo human_time_diff(strtotime($last_webhook['time']), current_time('timestamp')); ?>)
                            </span>
                            <?php if ($webhook_ok): ?>
                                <br><small style="color: #46b450;">&#10003; Webhook-Update erfolgreich installiert</small>
                            <?php elseif ($local_version !== 'unknown'): ?>
                                <br><small style="color: #f0b849;">&#9888; Lokale Version weicht ab — ggf. manuell prüfen</small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color: #999;">Noch kein Webhook empfangen</span>
                            <br><small style="color: #666;">Webhook-URL: <code>/wp-json/parkourone/v1/github-webhook</code></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($last_update): ?>
                <tr>
                    <th>Letztes Update:</th>
                    <td>
                        <?php echo esc_html($last_update['time']); ?>
                        <span style="color: #666;">(<?php echo human_time_diff(strtotime($last_update['time']), current_time('timestamp')); ?> her)</span>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if ($has_token && $remote_version): ?>
                <tr>
                    <th>GitHub API:</th>
                    <td>
                        <code style="font-size: 14px;"><?php echo esc_html($remote_version); ?></code>
                        <?php if ($local_version === $remote_version): ?>
                            <span style="color: #46b450; margin-left: 10px;">&#10003;</span>
                        <?php else: ?>
                            <span style="color: #dc3232; margin-left: 10px;">&#8593; Update verfügbar</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>

            <hr style="margin: 20px 0;">

            <form method="post" style="display: inline;">
                <?php wp_nonce_field('pot_manual_update', 'pot_nonce'); ?>
                <button type="submit" name="pot_check_update" class="button button-primary" style="margin-right: 10px;">
                    Jetzt prüfen & aktualisieren
                </button>
            </form>

            <a href="https://github.com/<?php echo esc_attr($this->github_repo); ?>/commits/main" target="_blank" class="button">
                GitHub Commits ansehen
            </a>

            <form method="post" style="margin-top: 20px;">
                <?php wp_nonce_field('pot_save_auto_update', 'pot_auto_nonce'); ?>
                <label style="display: inline-flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="pot_auto_update" value="1" <?php checked(get_option('pot_auto_update', false)); ?> />
                    <strong><?php echo esc_html('Automatische Updates aktivieren'); ?></strong>
                </label>
                <button type="submit" name="pot_save_auto_update" class="button" style="margin-left: 10px;">
                    <?php echo esc_html('Speichern'); ?>
                </button>
                <p style="margin-top: 8px; color: #666; font-size: 13px;">
                    <?php echo esc_html('AUS (Standard): Updates werden nur per Knopfdruck installiert. AN: stündlicher Auto-Check und automatische Installation neuer Versionen.'); ?>
                </p>
            </form>

            <p style="margin-top: 15px; color: #666; font-size: 13px;">
                Repo: <code><?php echo esc_html($this->github_repo); ?></code> — Updates via Webhook bei jedem Push.
            </p>
        </div>
        <?php
    }

    /**
     * Manuellen Update-Check verarbeiten
     */
    public function handle_manual_check() {
        if (!isset($_POST['pot_check_update'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['pot_nonce'] ?? '', 'pot_manual_update')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        delete_transient($this->transient_key);

        $remote_version = $this->get_remote_version();
        $local_version = $this->get_local_version();

        $redirect_args = ['page' => 'parkourone'];
        $was_updated = false;

        if ($remote_version && $remote_version !== $local_version) {
            $result = $this->do_update();
            if ($result) {
                $redirect_args['pot_updated'] = '1';
                $redirect_args['pot_version'] = $remote_version;
                $was_updated = true;
            } else {
                $redirect_args['pot_error'] = '1';
            }
        } else if (!$remote_version) {
            $redirect_args['pot_error'] = 'connection';
        } else {
            $redirect_args['pot_uptodate'] = '1';
        }

        set_transient($this->transient_key, time(), $this->check_interval);

        if ($was_updated) {
            set_transient('pot_update_success', $remote_version, 60);
            wp_redirect(admin_url('index.php'));
        } else {
            wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        }
        exit;
    }

    /**
     * Speichert den Auto-Update-Schalter (Checkbox auf der API/Statusboard-Seite)
     */
    public function handle_save_auto_update() {
        if (!isset($_POST['pot_save_auto_update'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['pot_auto_nonce'] ?? '', 'pot_save_auto_update')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        update_option('pot_auto_update', isset($_POST['pot_auto_update']) ? true : false, false);

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=pot-api-settings'));
        exit;
    }

    /**
     * Prüft und führt Auto-Update durch
     */
    public function maybe_auto_update() {
        // Auto-Update ist opt-in (Default AUS) — ohne aktivierte Option kein stündlicher Check.
        if (!get_option('pot_auto_update', false)) {
            return;
        }

        // Bei jedem Check alte Temp-Ordner aufräumen
        $this->cleanup_old_temp_dirs();

        $last_check = get_transient($this->transient_key);

        if ($last_check !== false) {
            return;
        }

        set_transient($this->transient_key, time(), $this->check_interval);

        $remote_version = $this->get_remote_version();

        if (!$remote_version) {
            return;
        }

        $local_version = $this->get_local_version();

        if ($remote_version !== $local_version) {
            $this->do_update();
        }
    }

    /**
     * Gibt die API-Headers zurück (inkl. Token falls gesetzt)
     */
    private function get_api_headers() {
        $headers = [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/ParkourONE-Campaign-Tracking-Updater'
        ];

        $token = get_option('parkourone_github_token', '');
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * Holt den neuesten Commit SHA von GitHub
     */
    private function get_remote_version() {
        $api_url = "https://api.github.com/repos/{$this->github_repo}/commits/main";
        $headers = $this->get_api_headers();

        $response = wp_remote_get($api_url, [
            'headers' => $headers,
            'timeout' => 20,
            'sslverify' => true
        ]);

        if (is_wp_error($response)) {
            $response = wp_remote_get($api_url, [
                'headers' => $headers,
                'timeout' => 20,
                'sslverify' => false
            ]);
        }

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            error_log('[POT Tracking] ' . $this->last_error);
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $this->last_error = 'HTTP ' . $response_code;
            error_log('[POT Tracking] ' . $this->last_error);
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['sha'])) {
            $this->last_error = null;
            return substr($body['sha'], 0, 7);
        }

        $this->last_error = 'Keine SHA in Antwort gefunden';
        return false;
    }

    private function get_local_version() {
        $version_file = $this->get_plugin_dir() . '.git-version';

        if (file_exists($version_file)) {
            return trim(file_get_contents($version_file));
        }

        return 'unknown';
    }

    private function save_local_version($version) {
        file_put_contents($this->get_plugin_dir() . '.git-version', $version);
    }

    private function get_plugin_dir() {
        return plugin_dir_path(dirname(__FILE__));
    }

    /**
     * Räumt alte Temp-Ordner auf die von fehlgeschlagenen Updates übrig geblieben sind
     */
    private function cleanup_old_temp_dirs() {
        $plugins_dir = dirname($this->get_plugin_dir());
        $pattern = $plugins_dir . '/' . $this->plugin_slug . '-temp-*';
        $temp_dirs = glob($pattern, GLOB_ONLYDIR);

        if (!is_array($temp_dirs)) return;

        foreach ($temp_dirs as $dir) {
            $dir_time = filemtime($dir);
            if ($dir_time && (time() - $dir_time) > 3600) {
                $this->remove_directory($dir);
                error_log('[POT Tracking] Alten Temp-Ordner gelöscht: ' . basename($dir));
            }
        }
    }

    /**
     * Führt das Update durch
     */
    private function do_update() {
        // Alte Temp-Ordner aufräumen
        $this->cleanup_old_temp_dirs();

        global $wp_filesystem;

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!WP_Filesystem(false, false, true)) {
            error_log('[POT Tracking] WP_Filesystem konnte nicht initialisiert werden');
            return false;
        }

        $token = get_option('parkourone_github_token', '');
        if ($token) {
            // Für private Repos: GitHub API Zipball-Endpoint mit Auth
            $zip_url = "https://api.github.com/repos/{$this->github_repo}/zipball/main";
            $response = wp_remote_get($zip_url, [
                'headers' => $this->get_api_headers(),
                'timeout' => 60,
                'sslverify' => true,
                'stream' => true,
                'filename' => wp_tempnam('pot_update')
            ]);

            if (is_wp_error($response)) {
                error_log('[POT Tracking] Download fehlgeschlagen - ' . $response->get_error_message());
                return false;
            }

            if (wp_remote_retrieve_response_code($response) !== 200) {
                error_log('[POT Tracking] Download HTTP ' . wp_remote_retrieve_response_code($response));
                return false;
            }

            $temp_file = $response['filename'];
        } else {
            $zip_url = "https://github.com/{$this->github_repo}/archive/refs/heads/main.zip";
            $temp_file = download_url($zip_url);

            if (is_wp_error($temp_file)) {
                error_log('[POT Tracking] Download fehlgeschlagen - ' . $temp_file->get_error_message());
                return false;
            }
        }

        $plugin_dir = $this->get_plugin_dir();
        $plugins_dir = dirname($plugin_dir);
        $temp_dir = $plugins_dir . '/' . $this->plugin_slug . '-temp-' . time();

        $unzip_result = unzip_file($temp_file, $temp_dir);

        if (is_wp_error($unzip_result)) {
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($temp_file) === true) {
                    @mkdir($temp_dir, 0755, true);
                    $zip->extractTo($temp_dir);
                    $zip->close();
                    $unzip_result = true;
                }
            }
        }

        @unlink($temp_file);

        if (is_wp_error($unzip_result) || $unzip_result !== true) {
            error_log('[POT Tracking] Entpacken fehlgeschlagen');
            $this->remove_directory($temp_dir);
            return false;
        }

        $extracted_dir = $temp_dir . '/' . $this->plugin_slug . '-main';

        if (!is_dir($extracted_dir)) {
            // Fallback: Ersten Unterordner suchen (API Zipball nutzt anderes Namensformat)
            $dirs = glob($temp_dir . '/*', GLOB_ONLYDIR);
            if (!empty($dirs)) {
                $extracted_dir = $dirs[0];
            } else {
                error_log('[POT Tracking] Extrahierter Ordner nicht gefunden: ' . $extracted_dir);
                $this->remove_directory($temp_dir);
                return false;
            }
        }

        $this->clean_plugin_directory($plugin_dir);
        $this->copy_directory($extracted_dir, $plugin_dir);
        $this->remove_directory($temp_dir);

        $new_version = $this->get_remote_version();
        if ($new_version) {
            $this->save_local_version($new_version);
        }

        update_option($this->plugin_slug . '_last_update', [
            'time' => current_time('mysql'),
            'version' => $new_version
        ]);

        error_log('[POT Tracking] Plugin erfolgreich auf ' . $new_version . ' aktualisiert');

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        return true;
    }

    private function clean_plugin_directory($dir) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if (strpos($file->getPathname(), '.git-version') !== false) {
                continue;
            }

            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
    }

    private function copy_directory($src, $dst) {
        $dir = opendir($src);
        @mkdir($dst, 0755, true);

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;

            $src_path = $src . '/' . $file;
            $dst_path = $dst . '/' . $file;

            if (is_dir($src_path)) {
                $this->copy_directory($src_path, $dst_path);
            } else {
                copy($src_path, $dst_path);
            }
        }

        closedir($dir);
    }

    private function remove_directory($dir) {
        if (!is_dir($dir)) return;

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($dir);
    }

    public function show_update_notice() {
        $update_success = get_transient('pot_update_success');
        if ($update_success) {
            delete_transient('pot_update_success');
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo '<strong>ParkourONE Campaign Tracking aktualisiert!</strong> ';
            echo 'Version: <code>' . esc_html($update_success) . '</code>';
            echo '</p></div>';
        }
    }

    public function get_last_error() {
        return $this->last_error ?? null;
    }
}

POT_GitHub_Updater::get_instance();
