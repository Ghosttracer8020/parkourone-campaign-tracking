<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin shell — a placeholder page under the theme-owned `parkourone` top-level menu.
 *
 * Mirrors class-ab-customer-overview.php (submenu under parkourone, cap manage_options,
 * <div class="wrap"><h1>). The one addition over the analog is a top-level fallback so
 * the page is never orphaned when the parkourone parent menu is absent (theme inactive).
 *
 * AJAX/enqueue are intentionally dropped until Phase 4.
 */
class POT_Admin {

    const MENU_SLUG = 'parkourone-campaign-tracking';

    public static function init() {
        // Priority 999 so the theme/sibling-owned `parkourone` top-level menu is
        // registered before our check runs — otherwise the fallback could add a
        // duplicate standalone page even when the parent exists (WR-01).
        add_action('admin_menu', [__CLASS__, 'add_menu_page'], 999);
    }

    public static function add_menu_page() {
        // Register under the shared `parkourone` parent if it exists; otherwise add a
        // top-level page so the placeholder is never orphaned (CONTEXT.md fallback).
        if (!empty($GLOBALS['admin_page_hooks']['parkourone'])) {
            add_submenu_page(
                'parkourone',
                'Campaign Tracking',
                'Campaign Tracking',
                'manage_options',
                self::MENU_SLUG,
                [__CLASS__, 'render_page']
            );
        } else {
            add_menu_page(
                'Campaign Tracking',
                'Campaign Tracking',
                'manage_options',
                self::MENU_SLUG,
                [__CLASS__, 'render_page'],
                'dashicons-chart-line'
            );
        }
    }

    public static function render_page() {
        // Capability re-check before any output.
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html('Campaign Tracking'); ?></h1>
            <p><?php echo esc_html('Coming soon — data store ready.'); ?></p>
        </div>
        <?php
    }
}
