<?php

/**
 * Michael Cloud Image Auto Importer - Uninstall Script
 *
 * @package CloudAutoImporter
 */

// If uninstall not called from WordPress, exit.
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Prevent direct file access.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Clean up plugin data on uninstall
 */
function mcai_cleanup_on_uninstall()
{
    // Check user capabilities for security.
    if (! current_user_can('activate_plugins')) {
        return;
    }

    // Check if we should remove data.
    $remove_data = get_option('mcai_remove_data_on_uninstall', true);
    if (! $remove_data) {
        return;
    }

    global $wpdb;

    // List of all plugin options to delete.
    $options = array(
        'mcai_plugin_installed',
        'mcai_db_version',
        'mcai_import_stats',
        'mcai_google_client_id',
        'mcai_google_client_secret',
        'mcai_google_access_token',
        'mcai_google_refresh_token',
        'mcai_google_token_expiry',
        'mcai_google_token_received',
        'mcai_last_oauth_error',
        'mcai_user_consent_given',
        'mcai_remove_data_on_uninstall',
        'mcai_dismiss_welcome_notice',
        'mcai_user_consent_given',
        'mcai_last_folder_sync',
        'mcai_transient_list',
    );

    // Delete options (delete_option() handles cache clearing internally).
    foreach ($options as $option) {
        delete_option($option);
    }

    // For multisite, delete network options.
    if (is_multisite()) {
        foreach ($options as $option) {
            delete_site_option($option);
        }
    }

    // Clean up options with mcai_ prefix using direct query (justified for bulk deletion).
    $option_prefixes = array(
        'mcai_',
        'mcai_import_',
        'mcai_user_usage_',
    );

    foreach ($option_prefixes as $prefix) {
        // Get options to delete.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $options_to_delete = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            )
        );

        // Use WordPress functions for deletion and cache clearing.
        foreach ($options_to_delete as $option_name) {
            delete_option($option_name);
        }
    }

    // Clean up postmeta using WordPress functions where possible.
    $meta_keys = array(
        '_mcai_%',
        'mcai_%',
    );

    foreach ($meta_keys as $key_pattern) {
        // Get post IDs with our meta keys.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
                $key_pattern
            )
        );

        // Delete meta for each post.
        foreach ($post_ids as $post_id) {
            // Get specific keys to delete for this post.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $keys_to_delete = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
                    $post_id,
                    $key_pattern
                )
            );

            foreach ($keys_to_delete as $meta_key) {
                delete_post_meta($post_id, $meta_key);
            }
        }
    }

    // Clean up user meta if any exists.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $user_meta_keys = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like('mcai_') . '%'
        )
    );

    if (! empty($user_meta_keys)) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
                $wpdb->esc_like('mcai_') . '%'
            )
        );

        // Clear user meta cache for affected users.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $user_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
                $wpdb->esc_like('mcai_') . '%'
            )
        );

        foreach ($user_ids as $user_id) {
            clean_user_cache($user_id);
        }
    }

    // Clean up any transients.
    $transient_groups = array('mcai_');

    foreach ($transient_groups as $group) {
        // Use WordPress functions for transient cleanup.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $transients = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} 
                WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_' . $group) . '%',
                $wpdb->esc_like('_transient_timeout_' . $group) . '%'
            )
        );

        foreach ($transients as $transient) {
            if (strpos($transient, '_transient_') === 0) {
                $name = substr($transient, strlen('_transient_'));
                delete_transient($name);
            }
        }
    }

    // Drop custom tables (schema changes require direct queries, but we'll document why).
    $tables = array(
        $wpdb->prefix . 'mcai_import_logs',
    );

    foreach ($tables as $table) {
        // Check if table exists before dropping.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;

        if ($table_exists) {
            // Direct query justified for schema change.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %s", $table));
        }
    }

    // Clean up scheduled cron events.
    $cron_hooks = array(
        'mcai_daily_import',
        'mcai_hourly_import',
        'mcai_weekly_import',
        'mcai_daily_cleanup',
        'mcai_weekly_maintenance',
    );

    foreach ($cron_hooks as $hook) {
        $timestamp = wp_next_scheduled($hook);
        while ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
            $timestamp = wp_next_scheduled($hook);
        }
    }

    // For multisite, clean up all sites.
    if (is_multisite()) {
        $blog_ids = get_sites(array(
            'fields' => 'ids',
            'number' => 0,
        ));

        $original_blog_id = get_current_blog_id();

        foreach ($blog_ids as $blog_id) {
            switch_to_blog($blog_id);

            // Clean up blog-specific options.
            foreach ($options as $option) {
                delete_option($option);
            }

            // Clean up blog-specific transients.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $blog_transients = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    $wpdb->esc_like('_transient_mcai_') . '%',
                    $wpdb->esc_like('_transient_timeout_mcai_') . '%'
                )
            );

            foreach ($blog_transients as $transient) {
                if (strpos($transient, '_transient_') === 0) {
                    $name = substr($transient, strlen('_transient_'));
                    delete_transient($name);
                }
            }

            restore_current_blog();
        }

        switch_to_blog($original_blog_id);
    }

    // Clean up temp directory
    $upload_dir = wp_upload_dir();
    $temp_dir   = trailingslashit($upload_dir['basedir']) . 'mcai_temp';

    if (file_exists($temp_dir)) {
        // Use safe directory removal
        if (function_exists('mcai_safe_rmdir')) {
            mcai_safe_rmdir($temp_dir);
        } else {
            // Fallback method
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
            global $wp_filesystem;
            if ($wp_filesystem && $wp_filesystem->exists($temp_dir)) {
                $wp_filesystem->rmdir($temp_dir, true);
            }
        }
    }

    // Clear object cache (wp_cache_flush() is WordPress standard).
    wp_cache_flush();

    // Delete rewrite rules and flush them on next load.
    delete_option('rewrite_rules');

    // Log uninstallation for debugging (only in debug mode).
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[MCAI] Plugin uninstalled. Data cleaned up successfully.');
    }
}

// Run the cleanup function.
if (function_exists('mcai_cleanup_on_uninstall')) {
    mcai_cleanup_on_uninstall();
}