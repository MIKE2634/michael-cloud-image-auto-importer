<?php

/**
 * Plugin Name: Michael Cloud Image Auto Importer
 * Plugin URI: https://wordpress.org
 * Description: Import images from Google Drive to WordPress Media Library. Connect your Google Drive, select a folder, and import images with alt text generation.
 * Version: 1.0.6
 * Author: Michael Otieno
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: michael-cloud-image-auto-importer
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.2
 * Tested up to: 6.9
 *
 * @package MichaelCloudImageAutoImporter
 */

// Prevent direct access.
if (! defined('ABSPATH')) {
    exit;
}

// ============================================
// DEFINE CONSTANTS
// ============================================
define('MCAI_VERSION', '1.0.5');
define('MCAI_PLUGIN_FILE', __FILE__);
define('MCAI_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('MCAI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MCAI_DEBUG', (defined('WP_DEBUG') && WP_DEBUG));

// Define hook prefix for consistency
if (! defined('MCAI_HOOK_PREFIX')) {
    define('MCAI_HOOK_PREFIX', 'mcai_');
}

// ============================================
// ERROR HANDLING & LOGGING
// ============================================
if (! function_exists('mcai_log')) {
    /**
     * Log messages with WordPress standards
     *
     * @param string $message Message to log.
     * @param string $level   Log level (error, warning, info, debug).
     * @return void
     */
    function mcai_log($message, $level = 'info')
    {
        // Only log in debug mode.
        if (! MCAI_DEBUG) {
            return;
        }

        $timestamp = current_time('Y-m-d H:i:s');

        // Convert non-string messages.
        if (! is_string($message)) {
            if (is_array($message) || is_object($message)) {
                $message = wp_json_encode($message, JSON_PRETTY_PRINT);
            } else {
                $message = strval($message);
            }
        }

        $log_entry = sprintf(
            '[%s] [MCAI] [%s] %s',
            $timestamp,
            sanitize_key($level),
            sanitize_text_field($message)
        );

        // Use our prefixed hook for Query Monitor integration
        if (function_exists('do_action')) {
            do_action(MCAI_HOOK_PREFIX . 'debug_log', $log_entry, $level);
            
            // Also fire Query Monitor's hook for compatibility
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            do_action('qm/debug', $log_entry);
        }
        
        // Alternative logging to file if enabled
        if (defined('MCAI_LOG_TO_FILE') && MCAI_LOG_TO_FILE) {
            $log_file = trailingslashit(wp_upload_dir()['basedir']) . 'mcai_debug.log';
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log($log_entry . PHP_EOL, 3, $log_file);
        }
    }
}

// ============================================
// FILE SYSTEM HELPER FUNCTIONS
// ============================================
if (! function_exists('mcai_safe_rmdir')) {
    /**
     * Safely remove directory using WP_Filesystem
     *
     * @param string $directory Directory path.
     * @param bool   $recursive Remove recursively.
     * @return bool Success status.
     */
    function mcai_safe_rmdir($directory, $recursive = true)
    {
        if (! function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();
        global $wp_filesystem;

        if ($wp_filesystem && $wp_filesystem->exists($directory)) {
            return $wp_filesystem->rmdir($directory, $recursive);
        }

        return false;
    }
}

if (! function_exists('mcai_is_dir_empty')) {
    /**
     * Check if directory is empty
     */
    function mcai_is_dir_empty($dir)
    {
        if (! is_readable($dir)) {
            return false;
        }

        // Initialize WP_Filesystem to check directory
        if (! function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();
        global $wp_filesystem;  // FIXED: Changed from $wpfilesystem to $wp_filesystem

        if ($wp_filesystem) {
            $files = $wp_filesystem->dirlist($dir, false, false);
            return empty($files);
        }

        // Fallback to PHP method
        $scan = @scandir($dir);
        return $scan !== false && count($scan) == 2; // Only . and .. exist
    }
}

// ============================================
// CLEANUP HELPER FUNCTIONS
// ============================================
if (! function_exists('mcai_cleanup_import_logs')) {
    /**
     * Clean old import logs from database
     */
    function mcai_cleanup_import_logs()
    {
        global $wpdb;

        $threshold_date = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));

        // Direct database query with proper escaping
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'mcai_import_logs',
            array('created_at' => $threshold_date),
            array('%s')
        );

        if ($deleted !== false && MCAI_DEBUG) {
            mcai_log(sprintf('Cleaned up %d old import logs', $deleted), 'info');
        }

        // Clear related cache
        wp_cache_delete(MCAI_HOOK_PREFIX . 'import_stats', 'cloud-auto-importer');
        do_action(MCAI_HOOK_PREFIX . 'cleanup_completed', 'import_logs', $deleted);
    }
}

// ============================================
// ACTIVATION & DEACTIVATION
// ============================================
register_activation_hook(__FILE__, 'mcai_activation_handler');
register_deactivation_hook(__FILE__, 'mcai_deactivation_handler');

if (! function_exists('mcai_activation_handler')) {
    /**
     * Plugin activation handler
     */
    function mcai_activation_handler()
    {
        global $wpdb;

        // Fire pre-activation hook
        do_action(MCAI_HOOK_PREFIX . 'before_activation');

        // Set default options.
        $default_options = array(
            'mcai_plugin_installed' => time(),
            'mcai_db_version'       => '1.0',
            'mcai_user_consent_given' => false,
            'mcai_remove_data_on_uninstall' => false,
            'mcai_dismiss_welcome_notice' => false,
        );

        foreach ($default_options as $key => $value) {
            if (false === get_option($key)) {
                add_option($key, $value);
                wp_cache_delete($key, 'options');
            }
        }

        // Create temp directory safely using WP_Filesystem.
        $upload_dir = wp_upload_dir();
        $temp_dir   = trailingslashit($upload_dir['basedir']) . 'mcai_temp';

        // Initialize WP_Filesystem.
        if (! function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        global $wp_filesystem;
        if (WP_Filesystem()) {
            if (! $wp_filesystem->exists($temp_dir)) {
                if ($wp_filesystem->mkdir($temp_dir, 0755)) {
                    // Security: Add index.php to prevent directory listing.
                    $index_file = trailingslashit($temp_dir) . 'index.php';
                    if (! $wp_filesystem->exists($index_file)) {
                        $wp_filesystem->put_contents($index_file, "<?php\n// Silence is golden.\n");
                    }
                }
            }
        }

        // Create database tables using dbDelta.
        $charset_collate = $wpdb->get_charset_collate();
        $table_name      = $wpdb->prefix . 'mcai_import_logs';

        $sql = "CREATE TABLE IF NOT EXISTS `" . esc_sql($table_name) . "` (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            import_type varchar(50) DEFAULT 'google_drive',
            import_id varchar(100) NOT NULL,
            user_id bigint(20) NOT NULL,
            status varchar(50) DEFAULT 'processing',
            total_files int(11) DEFAULT 0,
            processed_files int(11) DEFAULT 0,
            successful_files int(11) DEFAULT 0,
            failed_files int(11) DEFAULT 0,
            skipped_files int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime NULL,
            PRIMARY KEY (id),
            KEY import_id (import_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Update database version.
        update_option('mcai_db_version', '1.0');
        wp_cache_delete('mcai_db_version', 'options');

        // Schedule daily cleanup
        if (! wp_next_scheduled(MCAI_HOOK_PREFIX . 'daily_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', MCAI_HOOK_PREFIX . 'daily_cleanup');
        }

        // Schedule other maintenance tasks
        if (! wp_next_scheduled(MCAI_HOOK_PREFIX . 'weekly_maintenance')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', MCAI_HOOK_PREFIX . 'weekly_maintenance');
        }

        // Flush rewrite rules.
        flush_rewrite_rules();

        // Fire post-activation hook
        do_action(MCAI_HOOK_PREFIX . 'after_activation');

        if (MCAI_DEBUG) {
            mcai_log('Plugin activated successfully', 'info');
        }
    }
}

if (! function_exists('mcai_deactivation_handler')) {
    /**
     * Plugin deactivation handler
     */
    function mcai_deactivation_handler()
    {
        // Fire pre-deactivation hook
        do_action(MCAI_HOOK_PREFIX . 'before_deactivation');

        // Remove scheduled events.
        wp_clear_scheduled_hook(MCAI_HOOK_PREFIX . 'daily_cleanup');
        wp_clear_scheduled_hook(MCAI_HOOK_PREFIX . 'weekly_maintenance');

        // Clean up temporary options.
        delete_transient('mcai_recent_errors');

        // Flush rewrite rules.
        flush_rewrite_rules();

        // Fire post-deactivation hook
        do_action(MCAI_HOOK_PREFIX . 'after_deactivation');

        if (MCAI_DEBUG) {
            mcai_log('Plugin deactivated', 'info');
        }
    }
}

// ============================================
// UNINSTALL HOOK
// ============================================
if (function_exists('register_uninstall_hook')) {
    register_uninstall_hook(__FILE__, 'mcai_uninstall_handler');
}

if (! function_exists('mcai_uninstall_handler')) {
    /**
     * Plugin uninstall handler
     */
    function mcai_uninstall_handler()
    {
        // Fire pre-uninstall hook
        do_action(MCAI_HOOK_PREFIX . 'before_uninstall');

        // Check if we should remove data.
        if (! get_option('mcai_remove_data_on_uninstall', false)) {
            return;
        }

        global $wpdb;

        // Clear all mcai-related cache first
        wp_cache_flush_group('options');
        wp_cache_flush_group('cloud-auto-importer');

        // Remove all plugin options with caching consideration.
        $options = array(
            'mcai_plugin_installed',
            'mcai_db_version',
            'mcai_user_consent_given',
            'mcai_remove_data_on_uninstall',
            'mcai_dismiss_welcome_notice',
            'mcai_google_client_id',
            'mcai_google_client_secret',
            'mcai_google_access_token',
            'mcai_google_refresh_token',
            'mcai_last_folder_sync',
            'mcai_last_oauth_error',
            'mcai_transient_list',
        );

        foreach ($options as $option) {
            delete_option($option);
            wp_cache_delete($option, 'options');
        }

        // Clear any transients using WP API
        delete_expired_transients();

        // Drop database table safely
        $table_name = $wpdb->prefix . 'mcai_import_logs';
        
        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table_name))
        );
        
        if ($table_exists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mcai_import_logs");
        }

        // Clear object cache completely.
        wp_cache_flush();

        // Clean up temp directory
        $upload_dir = wp_upload_dir();
        $temp_dir   = trailingslashit($upload_dir['basedir']) . 'mcai_temp';

        if (file_exists($temp_dir)) {
            // Use safe directory removal
            mcai_safe_rmdir($temp_dir);
        }

        // Fire post-uninstall hook
        do_action(MCAI_HOOK_PREFIX . 'after_uninstall');

        if (MCAI_DEBUG) {
            mcai_log('Plugin data removed on uninstall', 'info');
        }
    }
}

// ============================================
// ADMIN NOTICES
// ============================================
add_action('admin_notices', 'mcai_admin_notices');

if (! function_exists('mcai_admin_notices')) {
    /**
     * Display admin notices
     */
    function mcai_admin_notices()
    {
        // Only show to users with manage_options capability.
        if (! current_user_can('manage_options')) {
            return;
        }

        // Fire hook before displaying notices
        do_action(MCAI_HOOK_PREFIX . 'before_admin_notices');

        // Welcome notice (dismissible).
        if (! get_option('mcai_dismiss_welcome_notice', false)) {
            $welcome_url = admin_url('admin.php?page=cloud-auto-importer-settings');
            $google_url  = 'https://console.cloud.google.com/apis/credentials';
?>
            <div class="notice notice-info is-dismissible" data-notice="mcai_welcome">
                <p>
                    <strong><?php esc_html_e('Welcome to Michael Cloud Image Auto Importer!', 'michael-cloud-image-auto-importer'); ?></strong>
                    <?php esc_html_e('Configure your Google API credentials in Settings to start importing images from Google Drive.', 'michael-cloud-image-auto-importer'); ?>
                </p>
                <p>
                    <a href="<?php echo esc_url($welcome_url); ?>" class="button button-primary">
                        <?php esc_html_e('Configure Settings', 'michael-cloud-image-auto-importer'); ?>
                    </a>
                    <a href="<?php echo esc_url($google_url); ?>" target="_blank" rel="noopener noreferrer" class="button">
                        <?php esc_html_e('Get Google API Credentials', 'michael-cloud-image-auto-importer'); ?>
                    </a>
                </p>
            </div>
        <?php
        }

        // Missing credentials notice.
        $client_id     = get_option('mcai_google_client_id', '');
        $client_secret = get_option('mcai_google_client_secret', '');

        if (empty($client_id) || empty($client_secret)) {
            $settings_url = admin_url('admin.php?page=cloud-auto-importer-settings');
        ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php esc_html_e('Michael Cloud Image Auto Importer needs configuration', 'michael-cloud-image-auto-importer'); ?></strong>
                    <?php
                    echo wp_kses_post(
                        sprintf(
                            /* translators: %s: settings page URL */
                            __('Please enter your Google API credentials in the %s to connect to Google Drive.', 'michael-cloud-image-auto-importer'),
                            '<a href="' . esc_url($settings_url) . '">' . esc_html__('settings page', 'michael-cloud-image-auto-importer') . '</a>'
                        )
                    );
                    ?>
                </p>
            </div>
<?php
        }

        // Fire hook after displaying notices
        do_action(MCAI_HOOK_PREFIX . 'after_admin_notices');
    }
}

// ============================================
// AJAX: DISMISS NOTICE
// ============================================
add_action('wp_ajax_mcai_dismiss_notice', 'mcai_ajax_dismiss_notice');

if (! function_exists('mcai_ajax_dismiss_notice')) {
    /**
     * Handle AJAX notice dismissal
     */
    function mcai_ajax_dismiss_notice()
    {
        // Check nonce.
        if (! check_ajax_referer(MCAI_HOOK_PREFIX . 'ajax_nonce', 'nonce', false)) {
            wp_send_json_error(
                array(
                    'message' => esc_html__('Security check failed.', 'michael-cloud-image-auto-importer'),
                    'code'    => 'nonce_failure',
                ),
                403
            );
            wp_die();
        }

        // Check capability.
        if (! current_user_can('manage_options')) {
            wp_send_json_error(
                array('message' => esc_html__('Unauthorized.', 'michael-cloud-image-auto-importer')),
                403
            );
            wp_die();
        }

        // Fire hook before dismissing notice
        do_action(MCAI_HOOK_PREFIX . 'before_notice_dismissal');

        $notice = isset($_POST['notice']) ? sanitize_key(wp_unslash($_POST['notice'])) : '';

        if ('mcai_welcome' === $notice) {
            update_option('mcai_dismiss_welcome_notice', true);
            wp_cache_delete('mcai_dismiss_welcome_notice', 'options');
            
            // Fire hook after successful dismissal
            do_action(MCAI_HOOK_PREFIX . 'notice_dismissed', $notice);
            
            wp_send_json_success(array('message' => esc_html__('Notice dismissed.', 'michael-cloud-image-auto-importer')));
            wp_die();
        }

        // Fire hook for failed dismissal
        do_action(MCAI_HOOK_PREFIX . 'notice_dismissal_failed', $notice);

        wp_send_json_error(
            array('message' => esc_html__('Invalid notice.', 'michael-cloud-image-auto-importer')),
            400
        );
        wp_die();
    }
}

// ============================================
// CLEANUP SCHEDULED TASKS
// ============================================
add_action(MCAI_HOOK_PREFIX . 'daily_cleanup', 'mcai_perform_daily_cleanup');
add_action(MCAI_HOOK_PREFIX . 'weekly_maintenance', 'mcai_perform_weekly_maintenance');

if (! function_exists('mcai_perform_daily_cleanup')) {
    /**
     * Perform daily cleanup tasks
     */
    function mcai_perform_daily_cleanup()
    {
        // Fire pre-cleanup hook
        do_action(MCAI_HOOK_PREFIX . 'before_daily_cleanup');

        // Clean old temp files (older than 7 days).
        $upload_dir = wp_upload_dir();
        $temp_dir   = trailingslashit($upload_dir['basedir']) . 'mcai_temp';

        if (file_exists($temp_dir) && is_dir($temp_dir)) {
            $files = glob(trailingslashit($temp_dir) . 'mcai_*');
            if ($files) {
                foreach ($files as $file) {
                    // Verify file is within temp directory before deletion
                    if (0 === strpos(realpath($file), realpath($temp_dir))) {
                        if (is_file($file) && filemtime($file) < time() - (7 * DAY_IN_SECONDS)) {
                            wp_delete_file($file);
                        }
                    }
                }
            }

            // Clean empty temp directories
            $subdirs = glob(trailingslashit($temp_dir) . '*', GLOB_ONLYDIR);
            foreach ($subdirs as $subdir) {
                // Verify directory is within temp directory
                if (0 === strpos(realpath($subdir), realpath($temp_dir))) {
                    if (is_dir($subdir) && mcai_is_dir_empty($subdir)) {
                        mcai_safe_rmdir($subdir);
                    }
                }
            }
        }

        // Clean old import logs (older than 30 days).
        mcai_cleanup_import_logs();

        // Clear expired transients using WordPress API
        delete_expired_transients();

        // Fire post-cleanup hook
        do_action(MCAI_HOOK_PREFIX . 'after_daily_cleanup');

        if (MCAI_DEBUG) {
            mcai_log('Daily cleanup completed', 'info');
        }
    }
}

if (! function_exists('mcai_perform_weekly_maintenance')) {
    /**
     * Perform weekly maintenance tasks
     */
    function mcai_perform_weekly_maintenance()
    {
        // Fire pre-maintenance hook
        do_action(MCAI_HOOK_PREFIX . 'before_weekly_maintenance');

        // Check if optimization was done recently (cache for a week)
        $cache_key = MCAI_HOOK_PREFIX . 'last_table_optimization';
        $last_optimized = get_transient($cache_key);
        
        // Only optimize once per week to avoid excessive database operations
        if (false === $last_optimized) {
            global $wpdb;
            
            // Define the specific table we want to optimize
            $expected_table_suffix = 'mcai_import_logs';
            $table_name = $wpdb->prefix . $expected_table_suffix;
            
            // Validate table name format - only allow alphanumeric and underscores
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $expected_table_suffix)) {
                // Check if table exists before attempting to optimize
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $table_exists = $wpdb->get_var(
                    $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table_name))
                );
                
                if ($table_exists && function_exists('wp_optimize_table')) {
                    // Use WordPress core function for safe optimization
                    wp_optimize_table($table_name);
                    
                    // Set cache for 1 week to prevent repeated optimizations
                    set_transient($cache_key, time(), WEEK_IN_SECONDS);
                    
                    if (MCAI_DEBUG) {
                        mcai_log(sprintf('Database table %s optimized via WordPress core', $table_name), 'info');
                    }
                    
                    // Fire hook after successful optimization
                    do_action(MCAI_HOOK_PREFIX . 'table_optimized', $table_name, true);
                } elseif (MCAI_DEBUG) {
                    mcai_log(sprintf('Database table %s does not exist - skipping optimization', $table_name), 'warning');
                }
            } else {
                // Invalid table name - log error but don't crash
                if (MCAI_DEBUG) {
                    mcai_log(sprintf('Invalid table name format: %s', $expected_table_suffix), 'error');
                }
            }
        } elseif (MCAI_DEBUG) {
            mcai_log('Table optimization skipped (already performed within the last week)', 'info');
        }

        // Clear all plugin cache
        mcai_clear_all_cache();

        // Fire post-maintenance hook
        do_action(MCAI_HOOK_PREFIX . 'after_weekly_maintenance');

        if (MCAI_DEBUG) {
            mcai_log('Weekly maintenance completed', 'info');
        }
    }
}

// ============================================
// LOAD PLUGIN CLASSES
// ============================================
add_action('plugins_loaded', 'mcai_init_plugin', 5);

if (! function_exists('mcai_init_plugin')) {
    /**
     * Initialize plugin classes
     */
    function mcai_init_plugin()
    {
        // Fire hook before plugin initialization
        do_action(MCAI_HOOK_PREFIX . 'before_init');

        // Check database version and upgrade if needed.
        $db_version = get_option('mcai_db_version', '0');
        if ('1.0' !== $db_version) {
            mcai_activation_handler();
        }

        // Load required files.
        $includes = array(
            'class-google-drive.php',
            'class-importer-core.php',
            'class-admin.php',
        );

        foreach ($includes as $file) {
            $path = MCAI_PLUGIN_PATH . 'includes/' . $file;
            if (file_exists($path)) {
                require_once $path;
            } elseif (MCAI_DEBUG) {
                mcai_log(sprintf('Missing required file: %s', $file), 'error');
            }
        }

        // Initialize classes.
        try {
            if (class_exists('MCAI_Google_Drive')) {
                new MCAI_Google_Drive();
            }

            if (class_exists('MCAI_Importer_Core')) {
                new MCAI_Importer_Core();
            }

            if (is_admin() && class_exists('MCAI_Admin')) {
                new MCAI_Admin();
            }

            if (MCAI_DEBUG) {
                mcai_log('Plugin classes initialized successfully', 'info');
            }
        } catch (Exception $e) {
            if (MCAI_DEBUG) {
                mcai_log(sprintf('Error initializing plugin classes: %s', $e->getMessage()), 'error');
            }
            
            // Fire error hook
            do_action(MCAI_HOOK_PREFIX . 'init_error', $e);
        }

        // Fire hook after plugin initialization
        do_action(MCAI_HOOK_PREFIX . 'after_init');
    }
}

// ============================================
// SECURITY HELPER FUNCTIONS
// ============================================
if (! function_exists('mcai_verify_ajax_request')) {
    /**
     * Verify AJAX request with nonce and capability
     *
     * @param string $action    AJAX action name.
     * @param string $nonce_key Nonce key in request.
     * @param string $capability Required user capability.
     * @return bool True if valid, false otherwise.
     */
    function mcai_verify_ajax_request($action = '', $nonce_key = 'nonce', $capability = 'manage_options')
    {
        // Verify nonce.
        if (! check_ajax_referer(MCAI_HOOK_PREFIX . 'ajax_nonce', $nonce_key, false)) {
            return false;
        }

        // Verify capability.
        if (! current_user_can($capability)) {
            return false;
        }

        // Fire verification hook
        do_action(MCAI_HOOK_PREFIX . 'ajax_request_verified', $action);

        return true;
    }
}

// ============================================
// FINAL INITIALIZATION
// ============================================
add_action('init', 'mcai_final_init');

if (! function_exists('mcai_final_init')) {
    /**
     * Final plugin initialization
     */
    function mcai_final_init()
    {
        // Fire hook before final initialization
        do_action(MCAI_HOOK_PREFIX . 'before_final_init');

        // Log plugin initialization in debug mode.
        if (MCAI_DEBUG) {
            mcai_log('Plugin fully initialized', 'info');
        }

        // Fire hook after final initialization
        do_action(MCAI_HOOK_PREFIX . 'after_final_init');
    }
}

// ============================================
// PLUGIN UPDATE HOOKS
// ============================================
add_action('upgrader_process_complete', 'mcai_plugin_updated', 10, 2);

if (! function_exists('mcai_plugin_updated')) {
    /**
     * Handle plugin updates
     */
    function mcai_plugin_updated($upgrader_object, $options)
    {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            if (isset($options['plugins']) && is_array($options['plugins'])) {
                foreach ($options['plugins'] as $plugin) {
                    if ($plugin === plugin_basename(__FILE__)) {
                        // Fire before update hook
                        do_action(MCAI_HOOK_PREFIX . 'before_update');
                        
                        // Run activation tasks on update
                        mcai_activation_handler();

                        // Fire after update hook
                        do_action(MCAI_HOOK_PREFIX . 'after_update');

                        if (MCAI_DEBUG) {
                            mcai_log('Plugin updated, activation tasks run', 'info');
                        }
                        break;
                    }
                }
            }
        }
    }
}

// ============================================
// CACHE MANAGEMENT FUNCTIONS
// ============================================
if (! function_exists('mcai_clear_all_cache')) {
    /**
     * Clear all plugin-related cache
     */
    function mcai_clear_all_cache()
    {
        // Fire pre-cache-clear hook
        do_action(MCAI_HOOK_PREFIX . 'before_cache_clear');

        // Clear transients
        $transients = array(
            MCAI_HOOK_PREFIX . 'google_drive_folders',
            MCAI_HOOK_PREFIX . 'recent_imports',
            MCAI_HOOK_PREFIX . 'import_stats',
        );

        foreach ($transients as $transient) {
            delete_transient($transient);
        }

        // Clear object cache group
        wp_cache_flush_group('cloud-auto-importer');

        // Clear options cache
        wp_cache_flush_group('options');

        // Fire post-cache-clear hook
        do_action(MCAI_HOOK_PREFIX . 'after_cache_clear');

        if (MCAI_DEBUG) {
            mcai_log('All plugin cache cleared', 'info');
        }
    }
}

// ============================================
// ADDITIONAL HOOKS FOR EXTENSIBILITY
// ============================================

/**
 * Hook for when an import starts
 * @since 1.0.3
 */
add_action(MCAI_HOOK_PREFIX . 'import_started', function($import_id, $user_id) {
    // Developers can hook into this
}, 10, 2);

/**
 * Hook for when an import completes
 * @since 1.0.3
 */
add_action(MCAI_HOOK_PREFIX . 'import_completed', function($import_id, $stats) {
    // Developers can hook into this
}, 10, 2);

/**
 * Hook for when an import fails
 * @since 1.0.3
 */
add_action(MCAI_HOOK_PREFIX . 'import_failed', function($import_id, $error_message) {
    // Developers can hook into this
}, 10, 2);

/**
 * Hook for when Google Drive connection is established
 * @since 1.0.3
 */
add_action(MCAI_HOOK_PREFIX . 'google_drive_connected', function($user_email) {
    // Developers can hook into this
}, 10, 1);

/**
 * Hook for when settings are saved
 * @since 1.0.3
 */
add_action(MCAI_HOOK_PREFIX . 'settings_saved', function($settings) {
    // Developers can hook into this
}, 10, 1);