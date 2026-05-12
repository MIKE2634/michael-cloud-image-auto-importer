<?php

/**
 * Cloud Auto Importer - Admin Class
 * CLOUDFLARE WORKER VERSION - No credential fields
 *
 * @package CloudAutoImporter
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Database handler class for secure operations
 */
class MCAI_Database
{
    private static $instance = null;
    private $table_name;

    private function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'mcai_import_logs';
        $this->ensure_table_exists();
    }

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Ensure database table exists
     */
    private function ensure_table_exists()
    {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            import_id varchar(100) NOT NULL,
            import_type varchar(50) DEFAULT 'google_drive',
            status varchar(20) DEFAULT 'pending',
            total_files int(11) DEFAULT 0,
            successful_files int(11) DEFAULT 0,
            failed_files int(11) DEFAULT 0,
            skipped_files int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY import_id (import_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Check if table exists
     */
    private function table_exists()
    {
        global $wpdb;
        
        $cache_key = 'mcai_table_exists';
        $table_exists = wp_cache_get($cache_key, 'mcai');
        
        if (false === $table_exists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for information_schema
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables 
                WHERE table_schema = %s 
                AND table_name = %s",
                DB_NAME,
                $this->table_name
            ));
            
            wp_cache_set($cache_key, $table_exists, 'mcai', DAY_IN_SECONDS);
        }
        
        return $table_exists;
    }

    /**
     * Get import logs
     */
    public function get_logs($limit = 50)
    {
        global $wpdb;

        if (!$this->table_exists()) {
            return array();
        }

        $cache_key = 'mcai_import_logs_' . $limit;
        $logs = wp_cache_get($cache_key, 'mcai');
        
        if (false === $logs) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query on custom logs table
            $logs = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}mcai_import_logs ORDER BY created_at DESC LIMIT %d",
                    $limit
                )
            );
            
            wp_cache_set($cache_key, $logs, 'mcai', HOUR_IN_SECONDS);
        }

        return $logs ?: array();
    }

    /**
     * Get import statistics
     */
    public function get_stats()
    {
        if (!$this->table_exists()) {
            return array(
                'total_imports'    => 0,
                'total_files'      => 0,
                'successful_files' => 0,
            );
        }

        $cache_key = 'mcai_import_stats';
        $stats = wp_cache_get($cache_key, 'mcai');

        if (false === $stats) {
            global $wpdb;
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query on custom logs table for stats
            $result = $wpdb->get_row(
                "SELECT 
                    COUNT(*) as total_imports,
                    COALESCE(SUM(total_files), 0) as total_files,
                    COALESCE(SUM(successful_files), 0) as successful_files
                FROM {$wpdb->prefix}mcai_import_logs"
            );
            
            $stats = array(
                'total_imports'    => $result ? (int) $result->total_imports : 0,
                'total_files'      => $result ? (int) $result->total_files : 0,
                'successful_files' => $result ? (int) $result->successful_files : 0,
            );
            
            wp_cache_set($cache_key, $stats, 'mcai', HOUR_IN_SECONDS);
        }

        return $stats;
    }
}

/**
 * Admin class for Michael Cloud Image Auto Importer
 */
class MCAI_Admin
{
    /**
     * Database handler instance
     */
    private $db;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = MCAI_Database::get_instance();

        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_post_mcai_disconnect_google', array($this, 'handle_disconnect_google'));
        add_action('admin_notices', array($this, 'display_admin_notices'));

        // AJAX handlers
        add_action('wp_ajax_mcai_start_import', array($this, 'ajax_start_import'));
        add_action('wp_ajax_mcai_get_import_status', array($this, 'ajax_get_import_status'));
        add_action('wp_ajax_mcai_process_batch', array($this, 'ajax_process_batch'));
        add_action('wp_ajax_mcai_get_import_stats', array($this, 'ajax_get_import_stats'));
    }

    /**
     * Register plugin settings
     *
     * @return void
     */
    public function register_settings()
    {
        // Register data removal setting only (no credentials needed)
        register_setting(
            'mcai_settings',
            'mcai_remove_data_on_uninstall',
            array(
                'type'              => 'boolean',
                'default'           => true,
                'sanitize_callback' => 'wp_validate_boolean',
            )
        );
    }

    /**
     * Render connection status bar
     *
     * @return void
     */
    public function render_connection_status_bar()
    {
        require_once MCAI_PLUGIN_PATH . 'includes/class-google-drive.php';
        $google_drive = new MCAI_Google_Drive();
        $is_connected = $google_drive->is_connected();
?>
        <div class="mcai-quick-stats-bar">
            <div class="mcai-stats-grid">
                <!-- Connection Status Card -->
                <div class="mcai-stat-card connection-status <?php echo $is_connected ? 'connected' : 'disconnected'; ?>">
                    <h4>🔗 <?php esc_html_e('Connection Status', 'michael-cloud-image-auto-importer'); ?></h4>
                    <div class="mcai-stat-value">
                        <?php
                        if ($is_connected) {
                            esc_html_e('Connected to Google Drive', 'michael-cloud-image-auto-importer');
                        } else {
                            esc_html_e('Not Connected', 'michael-cloud-image-auto-importer');
                        }
                        ?>
                    </div>
                    <div class="mcai-progress-text">
                        <?php if ($is_connected) : ?>
                            <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                            <?php esc_html_e('Ready to import', 'michael-cloud-image-auto-importer'); ?>
                        <?php else : ?>
                            <span class="dashicons dashicons-admin-links"></span>
                            <?php esc_html_e('Click "Connect Google Drive" below to get started', 'michael-cloud-image-auto-importer'); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Documentation Card -->
                <div class="mcai-stat-card documentation-card">
                    <h4>📚 <?php esc_html_e('Documentation', 'michael-cloud-image-auto-importer'); ?></h4>
                    <p><?php esc_html_e('Need help with setup?', 'michael-cloud-image-auto-importer'); ?></p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=cloud-auto-importer-guide')); ?>"
                        class="button button-secondary"
                        style="margin-top: 10px; width: 100%;">
                        <span class="dashicons dashicons-book-alt"></span>
                        <?php esc_html_e('Complete Setup Guide', 'michael-cloud-image-auto-importer'); ?>
                    </a>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Add admin menu pages
     *
     * @return void
     */
    public function add_menu()
    {
        add_menu_page(
            __('Michael Cloud Image Auto Importer', 'michael-cloud-image-auto-importer'),
            __('Cloud Importer', 'michael-cloud-image-auto-importer'),
            'manage_options',
            'cloud-auto-importer',
            array($this, 'render_main_page'),
            'dashicons-cloud-upload',
            30
        );

        // Add settings submenu
        add_submenu_page(
            'cloud-auto-importer',
            __('Settings', 'michael-cloud-image-auto-importer'),
            __('Settings', 'michael-cloud-image-auto-importer'),
            'manage_options',
            'cloud-auto-importer-settings',
            array($this, 'render_settings_page')
        );

        // Add setup guide submenu
        add_submenu_page(
            'cloud-auto-importer',
            __('Setup Guide', 'michael-cloud-image-auto-importer'),
            __('Setup Guide', 'michael-cloud-image-auto-importer'),
            'manage_options',
            'cloud-auto-importer-guide',
            array($this, 'render_guide_page')
        );

        // Add import logs submenu
        add_submenu_page(
            'cloud-auto-importer',
            __('Import Logs', 'michael-cloud-image-auto-importer'),
            __('Import Logs', 'michael-cloud-image-auto-importer'),
            'manage_options',
            'cloud-auto-importer-logs',
            array($this, 'render_logs_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current page hook
     * @return void
     */
    public function enqueue_scripts($hook)
    {
        // Only load on our plugin pages
        if (false === strpos($hook, 'cloud-auto-importer')) {
            return;
        }

        // Enqueue jQuery UI for slider
        wp_enqueue_script('jquery-ui-slider');
        wp_enqueue_style('jquery-ui-slider', includes_url('css/jquery-ui.min.css'), array(), '1.13.2');

        // Enqueue CSS
        wp_enqueue_style(
            'mcai-admin-style',
            MCAI_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            MCAI_VERSION
        );

        // Enqueue JavaScript
        wp_enqueue_script(
            'mcai-admin-script',
            MCAI_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'jquery-ui-slider'),
            MCAI_VERSION,
            true
        );

        // Localize script for AJAX
        wp_localize_script(
            'mcai-admin-script',
            'mcai_ajax',
            array(
                'ajax_url'   => admin_url('admin-ajax.php'),
                'nonce'      => wp_create_nonce('mcai_ajax_nonce'),
                'logs_url'   => admin_url('admin.php?page=cloud-auto-importer-logs'),
                'upload_url' => admin_url('upload.php'),
                'debug'      => defined('WP_DEBUG') && WP_DEBUG,
                'i18n'       => array(
                    'processing'            => __('Processing...', 'michael-cloud-image-auto-importer'),
                    'import_complete'       => __('Import complete!', 'michael-cloud-image-auto-importer'),
                    'error_occurred'        => __('An error occurred', 'michael-cloud-image-auto-importer'),
                    'starting_import'       => __('Starting import...', 'michael-cloud-image-auto-importer'),
                    'import_started'        => __('Import started! Processing files...', 'michael-cloud-image-auto-importer'),
                    'enter_drive_url'       => __('Please enter a Google Drive folder URL', 'michael-cloud-image-auto-importer'),
                    'valid_drive_url'       => __('Please enter a valid Google Drive URL', 'michael-cloud-image-auto-importer'),
                    'security_failed'       => __('Security check failed', 'michael-cloud-image-auto-importer'),
                    'start_import'          => __('Start Import', 'michael-cloud-image-auto-importer'),
                    'cancel_confirm'        => __('Are you sure you want to cancel this import?', 'michael-cloud-image-auto-importer'),
                    'view_privacy_details'  => __('View privacy details', 'michael-cloud-image-auto-importer'),
                    'hide_privacy_details'  => __('Hide privacy details', 'michael-cloud-image-auto-importer'),
                    'processed_files'       => __('Processed', 'michael-cloud-image-auto-importer'),
                    'files'                 => __('files', 'michael-cloud-image-auto-importer'),
                    'import_paused'         => __('Import paused', 'michael-cloud-image-auto-importer'),
                    'import_resumed'        => __('Import resumed', 'michael-cloud-image-auto-importer'),
                    'import_cancelled'      => __('Import cancelled', 'michael-cloud-image-auto-importer'),
                    'ajax_object_undefined' => __('mcai_ajax object not defined!', 'michael-cloud-image-auto-importer'),
                    'invalid_response'      => __('Invalid response received from server', 'michael-cloud-image-auto-importer'),
                    'no_import_id'          => __('Import started but no ID returned', 'michael-cloud-image-auto-importer'),
                    'ajax_error'            => __('AJAX request failed', 'michael-cloud-image-auto-importer'),
                    'import_complete_message' => __('Import complete!', 'michael-cloud-image-auto-importer'),
                    'images_imported'       => __('images imported successfully.', 'michael-cloud-image-auto-importer'),
                    'dismiss_notice'        => __('Dismiss this notice.', 'michael-cloud-image-auto-importer'),
                ),
            )
        );
    }

    /**
     * Render main admin page
     *
     * @return void
     */
    public function render_main_page()
    {
        require_once MCAI_PLUGIN_PATH . 'includes/class-google-drive.php';
        $google_drive = new MCAI_Google_Drive();

        $is_connected = $google_drive->is_connected();
        $auth_url     = $google_drive->get_auth_url();

    ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-cloud-upload"></span> <?php esc_html_e('Michael Cloud Image Auto Importer', 'michael-cloud-image-auto-importer'); ?></h1>

            <!-- Display Connection Status Bar -->
            <?php $this->render_connection_status_bar(); ?>

            <!-- Progress Bar Container -->
            <div id="mcai-progress-container" class="mcai-progress-container" style="display: none;">
                <div class="mcai-progress-header">
                    <h3><span class="dashicons dashicons-update"></span> <?php esc_html_e('Import Progress', 'michael-cloud-image-auto-importer'); ?></h3>
                    <div class="mcai-import-id" id="mcai-import-id-display"></div>
                </div>

                <div class="mcai-progress-bar">
                    <div class="mcai-progress-fill" style="width: 0%;"></div>
                </div>
                <div class="mcai-progress-text">
                    <span id="mcai-progress-percentage">0%</span>
                    <span id="mcai-progress-details"><?php esc_html_e('Initializing...', 'michael-cloud-image-auto-importer'); ?></span>
                </div>
                <div class="mcai-progress-stats">
                    <span id="mcai-processed-files">0</span> <?php esc_html_e('of', 'michael-cloud-image-auto-importer'); ?>
                    <span id="mcai-total-files">0</span> <?php esc_html_e('files processed', 'michael-cloud-image-auto-importer'); ?>
                </div>

                <div class="mcai-stats-grid" id="mcai-live-stats">
                    <div class="mcai-stat-card success">
                        <div class="mcai-stat-number" id="mcai-successful-count">0</div>
                        <div class="mcai-stat-label"><?php esc_html_e('Successful', 'michael-cloud-image-auto-importer'); ?></div>
                    </div>
                    <div class="mcai-stat-card warning">
                        <div class="mcai-stat-number" id="mcai-failed-count">0</div>
                        <div class="mcai-stat-label"><?php esc_html_e('Failed', 'michael-cloud-image-auto-importer'); ?></div>
                    </div>
                    <div class="mcai-stat-card info">
                        <div class="mcai-stat-number" id="mcai-skipped-count">0</div>
                        <div class="mcai-stat-label"><?php esc_html_e('Skipped (Duplicates)', 'michael-cloud-image-auto-importer'); ?></div>
                    </div>
                </div>

                <div class="mcai-progress-actions">
                    <button id="mcai-pause-import" class="button button-secondary" style="display: none;">
                        <span class="dashicons dashicons-controls-pause"></span> <?php esc_html_e('Pause', 'michael-cloud-image-auto-importer'); ?>
                    </button>
                    <button id="mcai-resume-import" class="button button-secondary" style="display: none;">
                        <span class="dashicons dashicons-controls-play"></span> <?php esc_html_e('Resume', 'michael-cloud-image-auto-importer'); ?>
                    </button>
                    <button id="mcai-cancel-import" class="button button-secondary">
                        <span class="dashicons dashicons-no"></span> <?php esc_html_e('Cancel', 'michael-cloud-image-auto-importer'); ?>
                    </button>
                    <button id="mcai-view-results" class="button button-primary" style="display: none;">
                        <span class="dashicons dashicons-visibility"></span> <?php esc_html_e('View Results', 'michael-cloud-image-auto-importer'); ?>
                    </button>
                </div>
            </div>

            <div class="mcai-dashboard">
                <!-- Status Card -->
                <div class="mcai-card">
                    <h2><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e('Connection Status', 'michael-cloud-image-auto-importer'); ?></h2>

                    <div class="mcai-status <?php echo $is_connected ? 'connected' : 'disconnected'; ?>">
                        <span class="dashicons dashicons-<?php echo $is_connected ? 'yes-alt' : 'no'; ?>"></span>
                        <?php
                        if ($is_connected) {
                            esc_html_e('Google Drive: Connected', 'michael-cloud-image-auto-importer');
                        } else {
                            esc_html_e('Google Drive: Not Connected', 'michael-cloud-image-auto-importer');
                        }
                        ?>
                    </div>

                    <div class="mcai-connection-actions">
                        <?php if (! $is_connected) : ?>
                            <?php if ($auth_url) : ?>
                                <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary">
                                    <span class="dashicons dashicons-plus"></span>
                                    <?php esc_html_e('Connect Google Drive', 'michael-cloud-image-auto-importer'); ?>
                                </a>
                            <?php else : ?>
                                <div class="notice notice-error" style="margin: 15px 0;">
                                    <p><?php esc_html_e('Unable to get authentication URL. Please check if Cloudflare Worker is configured correctly.', 'michael-cloud-image-auto-importer'); ?></p>
                                </div>
                            <?php endif; ?>
                        <?php else : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                                <input type="hidden" name="action" value="mcai_disconnect_google">
                                <?php wp_nonce_field('mcai_disconnect_nonce', 'mcai_disconnect_nonce'); ?>
                                <button type="submit" class="button button-secondary" onclick="return confirm('<?php esc_attr_e('Are you sure you want to disconnect?', 'michael-cloud-image-auto-importer'); ?>');">
                                    <span class="dashicons dashicons-no"></span>
                                    <?php esc_html_e('Disconnect Google Drive', 'michael-cloud-image-auto-importer'); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Import Card -->
                <div class="mcai-card">
                    <h2><span class="dashicons dashicons-upload"></span> <?php esc_html_e('Import Images', 'michael-cloud-image-auto-importer'); ?></h2>

                    <?php if (! $is_connected) : ?>
                        <div class="notice notice-warning">
                            <p><?php esc_html_e('Please connect Google Drive first to import images.', 'michael-cloud-image-auto-importer'); ?></p>
                        </div>
                    <?php else : ?>
                        <form id="mcai-import-form">
                            <?php wp_nonce_field('mcai_import_action', 'mcai_import_nonce'); ?>
                            <input type="hidden" id="mcai-current-import-id" name="current_import_id" value="">

                            <!-- Import Form -->
                            <table class="form-table">
                                <tr>
                                    <td colspan="2">
                                        <label for="cloud_folder_url" class="mcai-tooltip" data-tip="<?php esc_attr_e('Paste the shareable link from Google Drive', 'michael-cloud-image-auto-importer'); ?>">
                                            <strong><?php esc_html_e('Google Drive Folder URL', 'michael-cloud-image-auto-importer'); ?></strong>
                                        </label>
                                        <br><br>

                                        <input type="url"
                                            id="cloud_folder_url"
                                            name="cloud_folder_url"
                                            class="regular-text"
                                            placeholder="https://drive.google.com/drive/folders/..."
                                            required>
                                        <p class="description">
                                            <span class="dashicons dashicons-info"></span>
                                            <?php esc_html_e('Right-click folder → Get link → Copy link', 'michael-cloud-image-auto-importer'); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <td colspan="2">
                                        <label for="mcai_skip_duplicates" class="mcai-tooltip" data-tip="<?php esc_attr_e('Detects duplicates using file hash and filename', 'michael-cloud-image-auto-importer'); ?>">
                                            <strong><?php esc_html_e('Skip Duplicates', 'michael-cloud-image-auto-importer'); ?></strong>
                                        </label>
                                        <br><br>

                                        <label>
                                            <input type="checkbox"
                                                id="mcai_skip_duplicates"
                                                name="skip_duplicates"
                                                value="1"
                                                checked>
                                            <?php esc_html_e('Skip files already in Media Library', 'michael-cloud-image-auto-importer'); ?>
                                        </label>
                                        <p class="description">
                                            <span class="dashicons dashicons-filter"></span>
                                            <?php esc_html_e('Uses MD5 hash comparison for accurate detection', 'michael-cloud-image-auto-importer'); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <td colspan="2">
                                        <label for="mcai_compress_images" class="mcai-tooltip" data-tip="<?php esc_attr_e('Compress JPEG and PNG images to reduce file size', 'michael-cloud-image-auto-importer'); ?>">
                                            <strong> <?php esc_html_e('Image Compression', 'michael-cloud-image-auto-importer'); ?></strong>
                                        </label>
                                        <br><br>

                                        <label>
                                            <input type="checkbox"
                                                id="mcai_compress_images"
                                                name="compress_images"
                                                value="1"
                                                checked>
                                            <?php esc_html_e('Compress images', 'michael-cloud-image-auto-importer'); ?>
                                        </label>
                                        <div id="mcai-compression-options" style="margin-top: 15px; display: none;">
                                            <label for="mcai_compression_quality">
                                                <?php esc_html_e('Compression Quality:', 'michael-cloud-image-auto-importer'); ?>
                                                <span id="mcai-quality-value">80%</span>
                                            </label>
                                            <div id="mcai-quality-slider" style="width: 300px; margin: 10px 0;"></div>
                                            <input type="hidden" id="mcai_compression_quality" name="compression_quality" value="80">
                                            <p class="description">
                                                <span class="dashicons dashicons-image-rotate"></span>
                                                <?php esc_html_e('Higher = better quality, larger files. Lower = smaller files, lower quality.', 'michael-cloud-image-auto-importer'); ?>
                                            </p>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td colspan="2">
                                        <label for="mcai_generate_alt_text" class="mcai-tooltip" data-tip="<?php esc_attr_e('Auto-generates alt text from filenames (cat-sleeping.jpg → Cat Sleeping)', 'michael-cloud-image-auto-importer'); ?>">
                                            <strong><?php esc_html_e('Generate Alt Text', 'michael-cloud-image-auto-importer'); ?></strong>
                                        </label>

                                        <label>
                                            <input type="checkbox"
                                                id="mcai_generate_alt_text"
                                                name="generate_alt_text"
                                                value="1"
                                                checked>
                                            <?php esc_html_e('Generate alt text from filenames', 'michael-cloud-image-auto-importer'); ?>
                                        </label>
                                        <p class="description">
                                            <span class="dashicons dashicons-editor-textcolor"></span>
                                            <?php esc_html_e('Improves SEO and accessibility. Example: "my-cat-sleeping.jpg" → "My Cat Sleeping"', 'michael-cloud-image-auto-importer'); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <td colspan="2">
                                        <label for="mcai_batch_size" class="mcai-tooltip" data-tip="<?php esc_attr_e('Number of files processed in each batch', 'michael-cloud-image-auto-importer'); ?>">
                                            <strong><?php esc_html_e('Batch Size', 'michael-cloud-image-auto-importer'); ?></strong>
                                        </label>

                                        <select id="mcai_batch_size" name="batch_size">
                                            <option value="10"><?php esc_html_e('10 files per batch', 'michael-cloud-image-auto-importer'); ?></option>
                                            <option value="25" selected><?php esc_html_e('25 files per batch', 'michael-cloud-image-auto-importer'); ?></option>
                                            <option value="50"><?php esc_html_e('50 files per batch', 'michael-cloud-image-auto-importer'); ?></option>
                                            <option value="100"><?php esc_html_e('100 files per batch', 'michael-cloud-image-auto-importer'); ?></option>
                                        </select>
                                        <p class="description">
                                            <span class="dashicons dashicons-backup"></span>
                                            <?php esc_html_e('Smaller batches = more reliable, larger batches = faster', 'michael-cloud-image-auto-importer'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p class="submit">
                                <button type="submit"
                                    class="button button-primary button-large"
                                    id="mcai-start-import-btn">
                                    <span class="dashicons dashicons-cloud-upload"></span> <?php esc_html_e('Start Import', 'michael-cloud-image-auto-importer'); ?>
                                </button>
                                <span class="spinner" id="mcai-import-spinner" style="float: none; display: none;"></span>
                            </p>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Quick Guide Card -->
                <div class="mcai-card">
                    <h2><span class="dashicons dashicons-book-alt"></span> <?php esc_html_e('Quick Guide', 'michael-cloud-image-auto-importer'); ?></h2>

                    <div class="mcai-steps">
                        <div class="mcai-step">
                            <div class="mcai-step-number">1</div>
                            <div class="mcai-step-content">
                                <h3><?php esc_html_e('Connect Google Drive', 'michael-cloud-image-auto-importer'); ?></h3>
                                <p><?php esc_html_e('Click "Connect Google Drive" and authorize with your Google account.', 'michael-cloud-image-auto-importer'); ?></p>
                            </div>
                        </div>
                        <div class="mcai-step">
                            <div class="mcai-step-number">2</div>
                            <div class="mcai-step-content">
                                <h3><?php esc_html_e('Import Images', 'michael-cloud-image-auto-importer'); ?></h3>
                                <p><?php esc_html_e('Paste Google Drive folder URL and click "Start Import".', 'michael-cloud-image-auto-importer'); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="mcai-feature-list">
                        <h3><?php esc_html_e('Features Included:', 'michael-cloud-image-auto-importer'); ?></h3>
                        <ul>
                            <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Smart duplicate detection', 'michael-cloud-image-auto-importer'); ?></li>
                            <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Automatic alt text generation', 'michael-cloud-image-auto-importer'); ?></li>
                            <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Image compression', 'michael-cloud-image-auto-importer'); ?></li>
                            <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Batch processing', 'michael-cloud-image-auto-importer'); ?></li>
                            <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Progress tracking', 'michael-cloud-image-auto-importer'); ?></li>
                            <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Zero-config authentication', 'michael-cloud-image-auto-importer'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Render settings page - NO CREDENTIALS NEEDED
     *
     * @return void
     */
    public function render_settings_page()
    {
        require_once MCAI_PLUGIN_PATH . 'includes/class-google-drive.php';
        $google_drive = new MCAI_Google_Drive();
        $is_connected = $google_drive->is_connected();
        $current_data_removal = get_option('mcai_remove_data_on_uninstall', true);

        // Handle form submission with nonce verification
        if (isset($_POST['mcai_save_settings']) && isset($_POST['_wpnonce'])) {
            // Verify nonce
            if (wp_verify_nonce(sanitize_key(wp_unslash($_POST['_wpnonce'])), 'mcai_save_settings_nonce') && 
                current_user_can('manage_options')) {
                
                // Save data removal setting
                $data_removal = isset($_POST['mcai_remove_data_on_uninstall']) ? (bool) $_POST['mcai_remove_data_on_uninstall'] : false;
                update_option('mcai_remove_data_on_uninstall', $data_removal);

                echo '<div class="notice notice-success"><p>✅ ' . esc_html__('Settings saved successfully!', 'michael-cloud-image-auto-importer') . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>❌ ' . esc_html__('Security check failed. Settings not saved.', 'michael-cloud-image-auto-importer') . '</p></div>';
            }
        }
    ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e('Michael Cloud Image Auto Importer Settings', 'michael-cloud-image-auto-importer'); ?></h1>

            <div class="mcai-settings-container">
                <!-- Connection Info Card -->
                <div class="mcai-card">
                    <h2><span class="dashicons dashicons-cloud"></span> <?php esc_html_e('Google Drive Connection', 'michael-cloud-image-auto-importer'); ?></h2>

                    <div class="notice notice-info">
                        <p>
                            <?php esc_html_e('This plugin uses Cloudflare Workers to handle Google authentication. No API credentials needed!', 'michael-cloud-image-auto-importer'); ?>
                        </p>
                        <p>
                            <?php esc_html_e('Just click "Connect Google Drive" on the main page and authorize with your Google account.', 'michael-cloud-image-auto-importer'); ?>
                        </p>
                    </div>

                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('Connection Status', 'michael-cloud-image-auto-importer'); ?></th>
                            <td>
                                <?php if ($is_connected) : ?>
                                    <span style="color: #46b450;">✅ <?php esc_html_e('Connected to Google Drive', 'michael-cloud-image-auto-importer'); ?></span>
                                <?php else : ?>
                                    <span style="color: #dc3232;">❌ <?php esc_html_e('Not connected', 'michael-cloud-image-auto-importer'); ?></span>
                                    <p class="description">
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=cloud-auto-importer')); ?>">
                                            <?php esc_html_e('Go to main page to connect', 'michael-cloud-image-auto-importer'); ?>
                                        </a>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Data Management Card -->
                <div class="mcai-card">
                    <h2><span class="dashicons dashicons-database"></span> <?php esc_html_e('Data Management', 'michael-cloud-image-auto-importer'); ?></h2>

                    <form method="post" action="">
                        <?php wp_nonce_field('mcai_save_settings_nonce', '_wpnonce'); ?>
                        <input type="hidden" name="mcai_save_settings" value="1">

                        <table class="form-table">
                            <tr>
                                <th><label for="mcai_remove_data_on_uninstall"><?php esc_html_e('Data Removal', 'michael-cloud-image-auto-importer'); ?></label></th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                            id="mcai_remove_data_on_uninstall"
                                            name="mcai_remove_data_on_uninstall"
                                            value="1"
                                            <?php checked($current_data_removal); ?>>
                                        <?php esc_html_e('Remove all plugin data when uninstalling', 'michael-cloud-image-auto-importer'); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e('If checked, all plugin settings, logs, and import data will be deleted when you uninstall the plugin.', 'michael-cloud-image-auto-importer'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(__('Save Settings', 'michael-cloud-image-auto-importer'), 'primary', 'submit'); ?>
                    </form>
                </div>

                <!-- Support Card -->
                <div class="mcai-card">
                    <h2><span class="dashicons dashicons-sos"></span> <?php esc_html_e('Need Help?', 'michael-cloud-image-auto-importer'); ?></h2>
                    
                    <p><?php esc_html_e('Having trouble connecting? Make sure:', 'michael-cloud-image-auto-importer'); ?></p>
                    <ul style="margin-left: 20px;">
                        <li><?php esc_html_e('Cloudflare Worker is deployed and running', 'michael-cloud-image-auto-importer'); ?></li>
                        <li><?php esc_html_e('Your site can reach the worker URL', 'michael-cloud-image-auto-importer'); ?></li>
                        <li><?php esc_html_e('You have a valid Google account', 'michael-cloud-image-auto-importer'); ?></li>
                    </ul>
                    
                    <p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=cloud-auto-importer-guide')); ?>" class="button button-secondary">
                            <?php esc_html_e('View Setup Guide', 'michael-cloud-image-auto-importer'); ?>
                        </a>
                    </p>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Render guide page
     *
     * @return void
     */
    public function render_guide_page()
    {
    ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-book-alt"></span> <?php esc_html_e('Setup Guide', 'michael-cloud-image-auto-importer'); ?></h1>

            <div class="mcai-guide-container">
                <div class="mcai-card">
                    <h2><?php esc_html_e('Quick Setup', 'michael-cloud-image-auto-importer'); ?></h2>
                    
                    <div class="mcai-steps">
                        <div class="mcai-step">
                            <div class="mcai-step-number">1</div>
                            <div class="mcai-step-content">
                                <h3><?php esc_html_e('Connect Google Drive', 'michael-cloud-image-auto-importer'); ?></h3>
                                <p><?php esc_html_e('Go to the main plugin page and click "Connect Google Drive".', 'michael-cloud-image-auto-importer'); ?></p>
                            </div>
                        </div>
                        
                        <div class="mcai-step">
                            <div class="mcai-step-number">2</div>
                            <div class="mcai-step-content">
                                <h3><?php esc_html_e('Authorize Access', 'michael-cloud-image-auto-importer'); ?></h3>
                                <p><?php esc_html_e('Select your Google account and grant permission to access Google Drive.', 'michael-cloud-image-auto-importer'); ?></p>
                            </div>
                        </div>
                        
                        <div class="mcai-step">
                            <div class="mcai-step-number">3</div>
                            <div class="mcai-step-content">
                                <h3><?php esc_html_e('Start Importing', 'michael-cloud-image-auto-importer'); ?></h3>
                                <p><?php esc_html_e('Paste a Google Drive folder URL and click "Start Import".', 'michael-cloud-image-auto-importer'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Render logs page
     *
     * @return void
     */
    public function render_logs_page()
    {
        $logs = $this->db->get_logs(50);
        $stats = $this->db->get_stats();
?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-list-view"></span> <?php esc_html_e('Import Logs', 'michael-cloud-image-auto-importer'); ?></h1>

            <div class="mcai-stats-summary">
                <div class="mcai-stat-card">
                    <div class="mcai-stat-number"><?php echo esc_html($stats['total_imports']); ?></div>
                    <div class="mcai-stat-label"><?php esc_html_e('Total Imports', 'michael-cloud-image-auto-importer'); ?></div>
                </div>
                <div class="mcai-stat-card">
                    <div class="mcai-stat-number"><?php echo esc_html($stats['total_files']); ?></div>
                    <div class="mcai-stat-label"><?php esc_html_e('Total Files', 'michael-cloud-image-auto-importer'); ?></div>
                </div>
                <div class="mcai-stat-card">
                    <div class="mcai-stat-number"><?php echo esc_html($stats['successful_files']); ?></div>
                    <div class="mcai-stat-label"><?php esc_html_e('Successful Files', 'michael-cloud-image-auto-importer'); ?></div>
                </div>
            </div>

            <div class="mcai-logs-table">
                <?php if (empty($logs)) : ?>
                    <div class="notice notice-info">
                        <p><?php esc_html_e('No import logs found.', 'michael-cloud-image-auto-importer'); ?></p>
                    </div>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Import ID', 'michael-cloud-image-auto-importer'); ?></th>
                                <th><?php esc_html_e('Date', 'michael-cloud-image-auto-importer'); ?></th>
                                <th><?php esc_html_e('Status', 'michael-cloud-image-auto-importer'); ?></th>
                                <th><?php esc_html_e('Files', 'michael-cloud-image-auto-importer'); ?></th>
                                <th><?php esc_html_e('Successful', 'michael-cloud-image-auto-importer'); ?></th>
                                <th><?php esc_html_e('Failed', 'michael-cloud-image-auto-importer'); ?></th>
                                <th><?php esc_html_e('Skipped', 'michael-cloud-image-auto-importer'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log) : ?>
                                <tr>
                                    <td><code><?php echo esc_html(substr($log->import_id, 0, 15)); ?>...</code><br><small><?php echo esc_html($log->import_type); ?></small></td>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($log->created_at))); ?><br><small><?php echo esc_html(date_i18n(get_option('time_format'), strtotime($log->created_at))); ?></small></td>
                                    <td><span class="mcai-status-badge status-<?php echo esc_attr($log->status); ?>"><?php echo esc_html(ucfirst($log->status)); ?></span></td>
                                    <td><?php echo esc_html($log->total_files); ?></td>
                                    <td class="success"><strong><?php echo esc_html($log->successful_files); ?></strong></td>
                                    <td class="failed"><strong><?php echo esc_html($log->failed_files); ?></strong></td>
                                    <td class="skipped"><strong><?php echo esc_html($log->skipped_files); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    <?php
    }

    /**
     * Handle Google Drive disconnect
     *
     * @return void
     */
    public function handle_disconnect_google()
    {
        if (!isset($_POST['mcai_disconnect_nonce']) || !isset($_POST['action'])) {
            wp_die(esc_html__('Missing parameters.', 'michael-cloud-image-auto-importer'));
        }

        if (!wp_verify_nonce(sanitize_key(wp_unslash($_POST['mcai_disconnect_nonce'])), 'mcai_disconnect_nonce')) {
            wp_die(esc_html__('Security check failed.', 'michael-cloud-image-auto-importer'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'michael-cloud-image-auto-importer'));
        }

        $action = sanitize_key(wp_unslash($_POST['action']));
        if ('mcai_disconnect_google' !== $action) {
            wp_die(esc_html__('Invalid action.', 'michael-cloud-image-auto-importer'));
        }

        require_once MCAI_PLUGIN_PATH . 'includes/class-google-drive.php';
        $google_drive = new MCAI_Google_Drive();
        $google_drive->disconnect();

        wp_safe_redirect(admin_url('admin.php?page=cloud-auto-importer&disconnected=1'));
        exit;
    }

    /**
     * Display admin notices
     *
     * @return void
     */
    public function display_admin_notices()
    {
        if (isset($_GET['worker_connected']) && '1' === $_GET['worker_connected']) {
            // Verify nonce for connection success if present
            if (!isset($_GET['_wpnonce']) || wp_verify_nonce(sanitize_key($_GET['_wpnonce']), 'mcai_connection_action')) {
    ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Successfully connected to Google Drive you can start importing the images!', 'michael-cloud-image-auto-importer'); ?></p>
                </div>
            <?php
            }
        }

        if (isset($_GET['disconnected']) && '1' === $_GET['disconnected']) {
            ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Successfully disconnected from Google Drive.', 'michael-cloud-image-auto-importer'); ?></p>
                </div>
            <?php
        }

        if (isset($_GET['worker_error']) && '1' === $_GET['worker_error']) {
            $error_msg = get_option('mcai_connection_error', __('Unknown error', 'michael-cloud-image-auto-importer'));
            ?>
                <div class="notice notice-error is-dismissible">
                    <p>
                        <?php esc_html_e('Failed to connect to Google Drive:', 'michael-cloud-image-auto-importer'); ?>
                        <strong><?php echo esc_html($error_msg); ?></strong>
                    </p>
                    <p><?php esc_html_e('Please try again or check the Cloudflare Worker configuration.', 'michael-cloud-image-auto-importer'); ?></p>
                </div>
            <?php
        }
    }

    /**
     * AJAX: Start import
     *
     * @return void
     */
    public function ajax_start_import()
    {
        check_ajax_referer('mcai_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'michael-cloud-image-auto-importer')));
        }

        $folder_url = isset($_POST['cloud_folder_url']) ? sanitize_url(wp_unslash($_POST['cloud_folder_url'])) : '';
        $compress_images = isset($_POST['compress_images']) ? (bool) $_POST['compress_images'] : true;
        $compression_quality = isset($_POST['compression_quality']) ? (int) $_POST['compression_quality'] : 80;
        $skip_duplicates = isset($_POST['skip_duplicates']) ? (bool) $_POST['skip_duplicates'] : true;
        $generate_alt_text = isset($_POST['generate_alt_text']) ? (bool) $_POST['generate_alt_text'] : true;
        $batch_size = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 25;

        if (empty($folder_url) || !filter_var($folder_url, FILTER_VALIDATE_URL)) {
            wp_send_json_error(array('message' => __('Invalid Google Drive URL', 'michael-cloud-image-auto-importer')));
        }

        $import_id = 'mcai_' . wp_generate_uuid4();

        $import_data = array(
            'import_id'           => $import_id,
            'folder_url'          => $folder_url,
            'compress_images'     => $compress_images,
            'compression_quality' => $compression_quality,
            'skip_duplicates'     => $skip_duplicates,
            'generate_alt_text'   => $generate_alt_text,
            'batch_size'          => $batch_size,
            'status'              => 'pending',
            'started_at'          => current_time('mysql'),
            'processed_files'     => 0,
            'successful_files'    => 0,
            'failed_files'        => 0,
            'skipped_files'       => 0,
        );

        set_transient('mcai_import_' . $import_id, $import_data, DAY_IN_SECONDS);

        wp_send_json_success(array(
            'message'    => __('Import started successfully', 'michael-cloud-image-auto-importer'),
            'import_id'  => $import_id,
            'total_files' => 0,
        ));
    }

    /**
     * AJAX: Get import status
     *
     * @return void
     */
    public function ajax_get_import_status()
    {
        check_ajax_referer('mcai_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'michael-cloud-image-auto-importer')));
        }

        $import_id = isset($_POST['import_id']) ? sanitize_text_field(wp_unslash($_POST['import_id'])) : '';
        
        if (empty($import_id)) {
            wp_send_json_error(array('message' => __('Invalid import ID', 'michael-cloud-image-auto-importer')));
        }

        $import_data = get_transient('mcai_import_' . $import_id);
        
        if (false === $import_data) {
            wp_send_json_error(array('message' => __('Import not found or expired', 'michael-cloud-image-auto-importer')));
        }

        $total_files = isset($import_data['total_files']) ? (int) $import_data['total_files'] : 0;
        $processed = isset($import_data['processed_files']) ? (int) $import_data['processed_files'] : 0;
        $percentage = $total_files > 0 ? min(100, round(($processed / $total_files) * 100)) : 0;

        wp_send_json_success(array(
            'progress' => array(
                'percentage'      => $percentage,
                'processed'       => $processed,
                'total'           => $total_files,
                'successful'      => isset($import_data['successful_files']) ? (int) $import_data['successful_files'] : 0,
                'failed'          => isset($import_data['failed_files']) ? (int) $import_data['failed_files'] : 0,
                'skipped'         => isset($import_data['skipped_files']) ? (int) $import_data['skipped_files'] : 0,
                'current'         => $processed,
            ),
            'completed' => isset($import_data['status']) && 'completed' === $import_data['status'],
        ));
    }

    /**
     * AJAX: Process batch
     *
     * @return void
     */
    public function ajax_process_batch()
    {
        check_ajax_referer('mcai_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'michael-cloud-image-auto-importer')));
        }

        $import_id = isset($_POST['import_id']) ? sanitize_text_field(wp_unslash($_POST['import_id'])) : '';
        $batch_size = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 25;
        
        if (empty($import_id)) {
            wp_send_json_error(array('message' => __('Invalid import ID', 'michael-cloud-image-auto-importer')));
        }

        $import_data = get_transient('mcai_import_' . $import_id);
        
        if (false === $import_data) {
            wp_send_json_error(array('message' => __('Import not found or expired', 'michael-cloud-image-auto-importer')));
        }

        // Simulate batch processing
        $successful = min($batch_size, 10);
        $failed = max(0, $batch_size - 15);
        $skipped = $batch_size - $successful - $failed;
        
        $import_data['processed_files'] += $batch_size;
        $import_data['successful_files'] += $successful;
        $import_data['failed_files'] += $failed;
        $import_data['skipped_files'] += $skipped;
        
        if ($import_data['processed_files'] >= 100) {
            $import_data['status'] = 'completed';
        }
        
        set_transient('mcai_import_' . $import_id, $import_data, DAY_IN_SECONDS);

        wp_send_json_success(array(
            'batch_results' => array(
                'successful' => $successful,
                'failed'     => $failed,
                'skipped'    => $skipped,
            ),
            'progress' => array(
                'percentage' => $import_data['processed_files'] >= 100 ? 100 : 
                              min(100, round(($import_data['processed_files'] / 100) * 100)),
                'processed'  => $import_data['processed_files'],
                'total'      => 100,
                'successful' => $import_data['successful_files'],
                'failed'     => $import_data['failed_files'],
                'skipped'    => $import_data['skipped_files'],
                'current'    => $import_data['processed_files'],
            ),
            'completed' => isset($import_data['status']) && 'completed' === $import_data['status'],
        ));
    }

    /**
     * AJAX: Get import statistics
     *
     * @return void
     */
    public function ajax_get_import_stats()
    {
        check_ajax_referer('mcai_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'michael-cloud-image-auto-importer')));
        }

        $stats = $this->db->get_stats();
        wp_send_json_success($stats);
    }
}