<?php

/**
 * Cloud Auto Importer - Core Import Engine
 * FIXED VERSION - Handles file processing with memory optimization
 * Version 2.0.2 - Added empty folder handling, single file import, and error recovery
 *
 * @package MichaelCloudImageAutoImporter
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Core Import Engine class
 */
class MCAI_Importer_Core
{

    /**
     * Batch size
     *
     * @var int
     */
    private $batch_size = 20;

    /**
     * Google Drive instance
     *
     * @var MCAI_Google_Drive
     */
    private $google_drive;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Load Google Drive class if available.
        if (class_exists('MCAI_Google_Drive')) {
            $this->google_drive = new MCAI_Google_Drive();
        }

        // Add AJAX handlers for background processing.
        add_action('wp_ajax_mcai_process_batch', array($this, 'ajax_process_batch'));
        add_action('wp_ajax_mcai_get_import_status', array($this, 'ajax_get_import_status'));
        add_action('wp_ajax_mcai_start_import', array($this, 'ajax_start_import'));
        add_action('wp_ajax_mcai_cancel_import', array($this, 'ajax_cancel_import'));
    }

    /**
     * Validate table name
     * @param string $table_name Table name to validate
     * @return bool True if valid
     */
    private function is_valid_table_name($table_name)
    {
        global $wpdb;
        return preg_match('/^' . preg_quote($wpdb->prefix, '/') . '[a-zA-Z_][a-zA-Z0-9_]*$/', $table_name);
    }

    /**
     * Sanitize SQL query parameters
     * @param mixed $param Parameter to sanitize
     * @return string|int Sanitized parameter
     */
    private function sanitize_sql_param($param)
    {
        if (is_int($param)) {
            return intval($param);
        } elseif (is_float($param)) {
            return floatval($param);
        } else {
            return sanitize_text_field($param);
        }
    }

    /**
     * Check rate limiting for user
     *
     * @param int $user_id User ID.
     * @return bool|WP_Error True if allowed, WP_Error if rate limited.
     */
    private function check_rate_limit($user_id)
    {
        $transient_key = 'mcai_rate_limit_' . $user_id;
        $attempts = get_transient($transient_key) ?: 0;
        
        if ($attempts > apply_filters('mcai_max_attempts', 10)) {
            /* translators: %s: number of attempts allowed */
            return new WP_Error('rate_limit', sprintf(__('Too many import attempts. Please try again later. (Max: %s)', 'michael-cloud-image-auto-importer'), apply_filters('mcai_max_attempts', 10)));
        }
        
        set_transient($transient_key, $attempts + 1, HOUR_IN_SECONDS);
        return true;
    }

    /**
     * Validate import ownership
     *
     * @param string $import_id Import ID.
     * @param int    $user_id   User ID.
     * @return bool True if user owns the import.
     */
    private function validate_import_ownership($import_id, $user_id)
    {
        $import_data = get_option("mcai_import_{$import_id}");
        return $import_data && isset($import_data['user_id']) && absint($import_data['user_id']) === absint($user_id);
    }

    /**
     * Get batch size
     *
     * @return int Batch size.
     */
    private function get_batch_size()
    {
        return apply_filters('mcai_batch_size', 
            defined('MCAI_BATCH_SIZE') ? MCAI_BATCH_SIZE : 20);
    }

    /**
     * Get maximum files to process
     *
     * @return int Maximum files.
     */
    private function get_max_files()
    {
        return apply_filters('mcai_max_files',
            defined('MCAI_MAX_FILES') ? MCAI_MAX_FILES : 1000);
    }

    /**
     * Handle import error logging
     *
     * @param string $import_id     Import ID.
     * @param string $error_message Error message.
     * @param string $file_name     File name (optional).
     * @return void
     */
    private function handle_import_error($import_id, $error_message, $file_name = '')
    {
        $this->log_activity(
            sprintf('Import Error: %s', $error_message),
            'error',
            array(
                'import_id' => $import_id,
                'file'      => $file_name,
            )
        );
        
        // Store error in import data
        $import_data = get_option("mcai_import_{$import_id}");
        if ($import_data) {
            if (!isset($import_data['errors'])) {
                $import_data['errors'] = array();
            }
            $import_data['errors'][] = array(
                'file'    => $file_name,
                'message' => $error_message,
                'time'    => current_time('mysql')
            );
            update_option("mcai_import_{$import_id}", $import_data);
        }
    }

    /**
     * Log activity
     *
     * @param string $message Log message.
     * @param string $level   Log level (info, warning, error, success, debug).
     * @param array  $context Additional context.
     * @return void
     */
    private function log_activity($message, $level = 'info', $context = array())
    {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            return;
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_entry = sprintf(
                '[%s] [%s] %s %s',
                current_time('mysql'),
                strtoupper($level),
                $message,
                !empty($context) ? json_encode($context) : ''
            );
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log($log_entry);
        }
    }

    /**
     * Check server resources before processing
     *
     * @param int $batch_size Batch size to process.
     * @return bool|WP_Error True if enough resources, WP_Error if not.
     */
    private function check_server_resources($batch_size)
    {
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $memory_usage = memory_get_usage(true);
        $available_memory = $memory_limit - $memory_usage;
        
        // Estimate ~10MB per image for processing
        $estimated_needed = $batch_size * 10 * 1024 * 1024;
        /* translators: %1$s: available memory in MB, %2$s: estimated needed memory in MB */
        if ($available_memory < $estimated_needed) {
            
            /* translators: 1: available memory in megabytes, 2: estimated memory needed in megabytes */
            return new WP_Error(
                /* translators: 1: available memory in megabytes, 2: estimated memory needed in megabytes */
                'insufficient_memory',
                /* translators: %1$s: available memory in MB, %2$s: estimated needed memory in MB */
                sprintf(
                    /* translators: %1$s: available memory in MB, %2$s: estimated needed memory in MB */
                    __('Insufficient server memory. Available: %1$s MB, Needed: %2$s MB. Reduce batch size or increase PHP memory limit.', 'michael-cloud-image-auto-importer'),
                    round($available_memory / 1024 / 1024, 1),
                    round($estimated_needed / 1024 / 1024, 1)
                )
            );
        }
        
        return true;
    }

    /**
     * Calculate file hash efficiently without loading entire file
     *
     * @param string $file_path Path to file
     * @return string MD5 hash
     */
    private function calculate_file_hash($file_path)
    {
        // For smaller files, use built-in function
        if (file_exists($file_path) && filesize($file_path) < 10 * 1024 * 1024) { // Under 10MB
            return md5_file($file_path);
        }
        
        // For larger files, use WP_Filesystem
        global $wp_filesystem;
        
        // Initialize WP_Filesystem if needed
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        if ($wp_filesystem->exists($file_path)) {
            $contents = $wp_filesystem->get_contents($file_path);
            if (false !== $contents) {
                return md5($contents);
            }
        }
        
        // Fallback
        return md5_file($file_path);
    }

    /**
     * AJAX: Cancel an import
     *
     * @return void
     */
    public function ajax_cancel_import()
    {
        // Security check.
        check_ajax_referer('mcai_ajax_nonce', 'nonce');

        if (! current_user_can('upload_files')) {
            wp_send_json_error(
                array(
                    'message' => __('Permission denied', 'michael-cloud-image-auto-importer'),
                )
            );
        }

        // Sanitize input.
        $import_id = isset($_POST['import_id'])
            ? sanitize_text_field(wp_unslash($_POST['import_id']))
            : '';

        if (empty($import_id)) {
            wp_send_json_error(
                array(
                    'message' => __('Import ID required', 'michael-cloud-image-auto-importer'),
                )
            );
        }

        // Verify ownership.
        if (!$this->validate_import_ownership($import_id, get_current_user_id())) {
            wp_send_json_error(
                array(
                    'message' => __('Access denied to this import', 'michael-cloud-image-auto-importer'),
                )
            );
        }

        // Clean up the import.
        $result = $this->cleanup_failed_import($import_id, __('Cancelled by user', 'michael-cloud-image-auto-importer'));

        if ($result) {
            wp_send_json_success(
                array(
                    'message' => __('Import cancelled successfully', 'michael-cloud-image-auto-importer'),
                )
            );
        } else {
            wp_send_json_error(
                array(
                    'message' => __('Failed to cancel import', 'michael-cloud-image-auto-importer'),
                )
            );
        }
    }

    /**
     * Clean up a failed or cancelled import
     *
     * @param string $import_id Import ID.
     * @param string $reason    Reason for cleanup.
     * @return bool True if cleaned up successfully.
     */
    private function cleanup_failed_import($import_id, $reason = '')
    {
        global $wpdb;
        
        // Delete import data from options.
        $option_key = "mcai_import_{$import_id}";
        $import_data = get_option($option_key);
        
        if ($import_data) {
            // Clean up any temp files if they exist.
            if (isset($import_data['temp_files']) && is_array($import_data['temp_files'])) {
                foreach ($import_data['temp_files'] as $temp_file) {
                    if (file_exists($temp_file)) {
                        wp_delete_file($temp_file);
                    }
                }
            }
            
            delete_option($option_key);
            wp_cache_delete($option_key, 'cloud-auto-importer');
        }
        
        // Check for completed version.
        $completed_key = "mcai_import_{$import_id}_completed";
        if (get_option($completed_key)) {
            delete_option($completed_key);
            wp_cache_delete($completed_key, 'cloud-auto-importer');
        }
        
        // Update log to failed status.
        $table_name = $wpdb->prefix . 'mcai_import_logs';
        
        if ($this->is_valid_table_name($table_name)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->update(
                $table_name,
                array(
                    'status'       => 'failed',
                    'completed_at' => current_time('mysql'),
                ),
                array('import_id' => $import_id),
                array('%s', '%s'),
                array('%s')
            );
            
            // Add cancellation note if reason provided.
            if (!empty($reason)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->insert(
                    $table_name . '_notes',
                    array(
                        'import_id' => $import_id,
                        'note'      => $reason,
                        'created_at' => current_time('mysql'),
                    ),
                    array('%s', '%s', '%s')
                );
            }
        }
        
        // Clear any rate limiting for this user on successful cleanup.
        $rate_key = 'mcai_rate_limit_' . get_current_user_id();
        delete_transient($rate_key);
        
        $this->log_activity(
            sprintf('Import %s cleaned up. Reason: %s', $import_id, $reason),
            'info'
        );
        
        return true;
    }

    /**
     * AJAX: Start a new import
     *
     * @return void
     */
    public function ajax_start_import()
    {
        // Security check
        if (! check_ajax_referer('mcai_ajax_nonce', 'nonce') || ! current_user_can('upload_files')) {
            wp_send_json_error(
                array(
                    'message' => __('Security check failed', 'michael-cloud-image-auto-importer'),
                )
            );
        }

        // Check rate limiting
        $rate_limit = $this->check_rate_limit(get_current_user_id());
        if (is_wp_error($rate_limit)) {
            wp_send_json_error(
                array(
                    'message' => $rate_limit->get_error_message(),
                )
            );
        }

        // Validate and sanitize all inputs
        $cloud_folder_url = isset($_POST['cloud_folder_url'])
            ? esc_url_raw(wp_unslash($_POST['cloud_folder_url']))
            : '';

        $compress_images = isset($_POST['compress_images']) && '1' === $_POST['compress_images'];
        $skip_duplicates = isset($_POST['skip_duplicates']) && '1' === $_POST['skip_duplicates'];
        $generate_alt_text = isset($_POST['generate_alt_text']) && '1' === $_POST['generate_alt_text'];
        $compression_quality = isset($_POST['compression_quality'])
            ? min(max(intval($_POST['compression_quality']), 1), 100)
            : 80;

        if (empty($cloud_folder_url)) {
            wp_send_json_error(
                array(
                    'message' => __('Please enter a valid Google Drive folder URL.', 'michael-cloud-image-auto-importer'),
                )
            );
        }

        // Generate unique import ID with user token for better security.
        $user_token = wp_get_session_token();
        $import_id = 'mcai_' . get_current_user_id() . '_' . time() . '_' . substr(md5($user_token), 0, 8);

        // Prepare settings.
        $settings = array(
            'compress'            => $compress_images,
            'skip_duplicates'     => $skip_duplicates,
            'generate_alt_text'   => $generate_alt_text,
            'compression_quality' => $compression_quality,
            'max_width'           => 1920,
            'max_height'          => 1080,
        );

        // Check if it's a single file or folder.
        $url_type = $this->detect_url_type($cloud_folder_url);
        
        if ($url_type === 'file') {
            // Handle single file import.
            $result = $this->import_single_file($cloud_folder_url, $import_id, $settings);
        } else {
            // Handle folder import.
            $result = $this->start_import($cloud_folder_url, $import_id, $settings);
        }

        // Ensure response has import_id.
        if ($result['success'] && ! isset($result['import_id'])) {
            $result['import_id'] = $import_id;
        }

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Detect if URL is for a single file or folder
     *
     * @param string $url Google Drive URL.
     * @return string 'file', 'folder', or 'unknown'
     */
    private function detect_url_type($url)
    {
        // Check for file patterns.
        if (preg_match('/\/file\/d\/([a-zA-Z0-9_-]+)/', $url) ||
            preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url) ||
            preg_match('/\/open\?id=([a-zA-Z0-9_-]+)/', $url)) {
            return 'file';
        }
        
        // Check for folder patterns.
        if (preg_match('/\/folders\/([a-zA-Z0-9_-]+)/', $url) ||
            preg_match('/id=([a-zA-Z0-9_-]+)/', $url)) {
            return 'folder';
        }
        
        return 'unknown';
    }

    /**
     * Import a single file from Google Drive
     *
     * @param string $file_url  File URL.
     * @param string $import_id Import ID.
     * @param array  $settings  Import settings.
     * @return array Result array.
     */
    private function import_single_file($file_url, $import_id, $settings)
    {
        // Validate Google Drive connection.
        if (! $this->google_drive || ! method_exists($this->google_drive, 'is_connected')) {
            return array(
                'success' => false,
                'message' => __('Google Drive not properly connected', 'michael-cloud-image-auto-importer'),
            );
        }

        if (! $this->google_drive->is_connected()) {
            return array(
                'success' => false,
                'message' => __('Google Drive not connected', 'michael-cloud-image-auto-importer'),
            );
        }

        // Extract file ID from URL.
        $file_id = $this->extract_file_id($file_url);
        if (! $file_id) {
            return array(
                'success' => false,
                'message' => __('Invalid Google Drive file URL', 'michael-cloud-image-auto-importer'),
            );
        }

        // Get file metadata.
        if (! method_exists($this->google_drive, 'get_file_info')) {
            return array(
                'success' => false,
                'message' => __('Google Drive method not available', 'michael-cloud-image-auto-importer'),
            );
        }

        $file_info = $this->google_drive->get_file_info($file_id);
        
        if (! $file_info || ! isset($file_info['success']) || ! $file_info['success']) {
            $error_msg = isset($file_info['message']) ? $file_info['message'] : __('Failed to get file information', 'michael-cloud-image-auto-importer');
            return array(
                'success' => false,
                'message' => $error_msg,
            );
        }

        $file_data = $file_info['file'];
        
        // Check if it's an image.
        if (! $this->is_image_file($file_data['name'], $file_data['mimeType'])) {
            return array(
                'success' => false,
                'message' => __('Please use a folder URL instead of a direct file link. Create a folder in Google Drive, add your images to it, then share the folder link.', 'michael-cloud-image-auto-importer'),
            );
        }

        // Create files array with single file.
        $files = array($file_data);
        $total_files = 1;

        // Check server resources.
        $resource_check = $this->check_server_resources($this->get_batch_size());
        if (is_wp_error($resource_check)) {
            return array(
                'success' => false,
                'message' => $resource_check->get_error_message(),
            );
        }

        // Store import data for processing.
        $import_data = array(
            'import_id'        => $import_id,
            'file_id'          => $file_id,
            'files'            => $files,
            'total_files'      => $total_files,
            'processed_files'  => 0,
            'successful_files' => 0,
            'failed_files'     => 0,
            'skipped_files'    => 0,
            'settings'         => wp_parse_args(
                $settings,
                array(
                    'compress'            => true,
                    'compression_quality' => 80,
                    'skip_duplicates'     => true,
                    'generate_alt_text'   => true,
                    'max_width'           => 1920,
                    'max_height'          => 1080,
                )
            ),
            'current_index'    => 0,
            'attachment_ids'   => array(),
            'started_at'       => current_time('mysql'),
            'last_update'      => current_time('mysql'),
            'status'           => 'processing',
            'user_id'          => get_current_user_id(),
            'errors'           => array(),
            'is_single_file'   => true,
        );

        // Save import data.
        $option_key = "mcai_import_{$import_id}";
        update_option($option_key, $import_data, false);
        wp_cache_set($option_key, $import_data, 'cloud-auto-importer', 3600);

        // Create initial log entry.
        $this->log_import_start($import_id, $total_files);

        return array(
            'success'     => true,
            'import_id'   => $import_id,
            'total_files' => $total_files,
            'message'     => __('Found 1 file. Starting import...', 'michael-cloud-image-auto-importer'),
        );
    }

    /**
     * Extract file ID from Google Drive URL
     *
     * @param string $url Google Drive URL.
     * @return string|false File ID or false.
     */
    private function extract_file_id($url)
    {
        $patterns = array(
            '/\/file\/d\/([a-zA-Z0-9_-]+)/',
            '/\/d\/([a-zA-Z0-9_-]+)/',
            '/\/open\?id=([a-zA-Z0-9_-]+)/',
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return sanitize_text_field($matches[1]);
            }
        }
        
        return false;
    }

    /**
     * Get file count from URL without downloading
     *
     * @param string $folder_url Folder URL.
     * @return array Result array.
     */
    private function get_file_count_from_url($folder_url)
    {
        if (! $this->google_drive || ! method_exists($this->google_drive, 'is_connected')) {
            return array(
                'success' => false,
                'message' => __('Google Drive not available', 'michael-cloud-image-auto-importer'),
            );
        }

        if (! $this->google_drive->is_connected()) {
            return array(
                'success' => false,
                'message' => __('Google Drive not connected', 'michael-cloud-image-auto-importer'),
            );
        }

        $folder_id = $this->extract_folder_id($folder_url);
        if (! $folder_id) {
            return array(
                'success' => false,
                'message' => __('Invalid folder URL', 'michael-cloud-image-auto-importer'),
            );
        }

        if (! method_exists($this->google_drive, 'get_file_count')) {
            return array(
                'success' => false,
                'message' => __('Method not available', 'michael-cloud-image-auto-importer'),
            );
        }

        return $this->google_drive->get_file_count($folder_id);
    }

    /**
     * Start a new import job
     *
     * @param string $folder_url Folder URL.
     * @param string $import_id  Import ID.
     * @param array  $settings   Settings.
     * @return array Result array.
     */
    public function start_import($folder_url, $import_id, $settings = array())
    {
        // Validate Google Drive connection.
        if (! $this->google_drive || ! method_exists($this->google_drive, 'is_connected')) {
            return array(
                'success' => false,
                'message' => __('Google Drive not properly connected', 'michael-cloud-image-auto-importer'),
            );
        }

        if (! $this->google_drive->is_connected()) {
            return array(
                'success' => false,
                'message' => __('Google Drive not connected', 'michael-cloud-image-auto-importer'),
            );
        }

        // Extract folder ID from URL.
        $folder_id = $this->extract_folder_id($folder_url);
        if (! $folder_id) {
            return array(
                'success' => false,
                'message' => __('Invalid Google Drive URL', 'michael-cloud-image-auto-importer'),
            );
        }

        // Get files from Google Drive with pagination.
        if (! method_exists($this->google_drive, 'list_files')) {
            return array(
                'success' => false,
                'message' => __('Google Drive method not available', 'michael-cloud-image-auto-importer'),
            );
        }
        
        $max_files = $this->get_max_files();
        $files_result = $this->google_drive->list_files($folder_id, $max_files);
        
        /* translators: %s: error message from Google Drive API */
        if (! $files_result['success']) {
            return array(
                'success' => false,
                /* translators: %s: error message from Google Drive API */
                'message' => sprintf(
                    /* translators: %s: error message from Google Drive API */
                    __('Failed to list files: %s', 'michael-cloud-image-auto-importer'),
                    $files_result['message']
                ),
            );
        }

        $files       = $files_result['files'];
        $total_files = count($files);

        // Handle empty folder case.
        if (0 === $total_files) {
            // Clean up any partial data.
            $this->cleanup_failed_import($import_id, __('No files found in folder', 'michael-cloud-image-auto-importer'));
            
            return array(
                'success'   => false,
                'no_files'  => true,
                'message'   => __('No image files found in this folder. Please check the folder and try again.', 'michael-cloud-image-auto-importer'),
            );
        }

        // Check server resources before starting
        $resource_check = $this->check_server_resources($this->get_batch_size());
        if (is_wp_error($resource_check)) {
            $this->cleanup_failed_import($import_id, $resource_check->get_error_message());
            return array(
                'success' => false,
                'message' => $resource_check->get_error_message(),
            );
        }

        // Store import data for processing.
        $import_data = array(
            'import_id'        => $import_id,
            'folder_id'        => $folder_id,
            'files'            => $files,
            'total_files'      => $total_files,
            'processed_files'  => 0,
            'successful_files' => 0,
            'failed_files'     => 0,
            'skipped_files'    => 0,
            'settings'         => wp_parse_args(
                $settings,
                array(
                    'compress'            => true,
                    'compression_quality' => 80,
                    'skip_duplicates'     => true,
                    'generate_alt_text'   => true,
                    'max_width'           => 1920,
                    'max_height'          => 1080,
                )
            ),
            'current_index'    => 0,
            'attachment_ids'   => array(),
            'started_at'       => current_time('mysql'),
            'last_update'      => current_time('mysql'),
            'status'           => 'processing',
            'user_id'          => get_current_user_id(),
            'errors'           => array(),
            'is_single_file'   => false,
        );

        // Save import data to options table with caching consideration.
        $option_key = "mcai_import_{$import_id}";
        update_option($option_key, $import_data, false);
        wp_cache_set($option_key, $import_data, 'cloud-auto-importer', 3600);

        // Create initial log entry using WordPress functions.
        $this->log_import_start($import_id, $total_files);

        /* translators: %d: number of files found in Google Drive folder */
        $message = sprintf(
            /* translators: %d: number of files found in Google Drive folder */
            _n(
                'Found %d file. Starting import...',
                'Found %d files. Starting import...',
                $total_files,
                'michael-cloud-image-auto-importer'
            ),
            $total_files
        );

        return array(
            'success'     => true,
            'import_id'   => $import_id,
            'total_files' => $total_files,
            'message'     => $message,
        );
    }

    /**
     * Log import start
     *
     * @param string $import_id   Import ID.
     * @param int    $total_files Total files.
     * @return void
     */
    private function log_import_start($import_id, $total_files)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcai_import_logs';

        // Validate table name
        if (!$this->is_valid_table_name($table_name)) {
            $this->handle_import_error($import_id, "Invalid table name: {$table_name}");
            return;
        }

        // Use WordPress insert function with proper formatting.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table_name,
            array(
                'import_type' => 'google_drive',
                'import_id'   => sanitize_text_field($import_id),
                'user_id'     => absint(get_current_user_id()),
                'status'      => 'processing',
                'total_files' => absint($total_files),
                'created_at'  => current_time('mysql'),
            ),
            array('%s', '%s', '%d', '%s', '%d', '%s')
        );

        if (false === $result) {
            $this->handle_import_error($import_id, "Failed to log import start: " . $wpdb->last_error);
        }

        // Clear cache for this import.
        wp_cache_delete("import_log_{$import_id}", 'cloud-auto-importer');
    }

    /**
     * Process a batch of files (called via AJAX)
     *
     * @return void
     */
    public function ajax_process_batch()
    {
        // Security check.
        check_ajax_referer('mcai_ajax_nonce', 'nonce');

        if (! current_user_can('upload_files')) {
            wp_send_json_error(
                array(
                    'message' => __('Permission denied', 'michael-cloud-image-auto-importer'),
                )
            );
        }

        // Sanitize and validate inputs
        $import_id = isset($_POST['import_id'])
            ? sanitize_text_field(wp_unslash($_POST['import_id']))
            : '';

        $batch_size = isset($_POST['batch_size'])
            ? min(max(intval($_POST['batch_size']), 1), 100)
            : $this->get_batch_size();

        // Validate import ID format and ownership
        if (empty($import_id) || !preg_match('/^mcai_\d+_\d+_[a-f0-9]{8}$/', $import_id)) {
            wp_send_json_error(
                array(
                    'message' => __('Invalid Import ID', 'michael-cloud-image-auto-importer'),
                )
            );
        }

        // Verify user owns this import
        if (!$this->validate_import_ownership($import_id, get_current_user_id())) {
            wp_send_json_error(
                array(
                    'message' => __('Access denied to this import', 'michael-cloud-image-auto-importer'),
                )
            );
        }

        // Check server resources
        $resource_check = $this->check_server_resources($batch_size);
        if (is_wp_error($resource_check)) {
            wp_send_json_error(
                array(
                    'message' => $resource_check->get_error_message(),
                )
            );
        }

        $result = $this->process_batch($import_id, $batch_size);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Process a batch of files
     *
     * @param string $import_id  Import ID.
     * @param int    $batch_size Batch size.
     * @return array Result array.
     */
    private function process_batch($import_id, $batch_size = 25)
    {
        // Get import data with caching.
        $option_key = "mcai_import_{$import_id}";
        $import_data = wp_cache_get($option_key, 'cloud-auto-importer');

        if (false === $import_data) {
            $import_data = get_option($option_key);
            if ($import_data) {
                wp_cache_set($option_key, $import_data, 'cloud-auto-importer', 3600);
            }
        }

        if (! $import_data) {
            return array(
                'success' => false,
                'message' => __('Import job not found', 'michael-cloud-image-auto-importer'),
            );
        }

        $files             = $import_data['files'];
        $total_files       = $import_data['total_files'];
        $current_index     = $import_data['current_index'];
        $processed_files   = $import_data['processed_files'];
        $successful_files  = $import_data['successful_files'];
        $failed_files      = $import_data['failed_files'];
        $skipped_files     = $import_data['skipped_files'];
        $settings          = $import_data['settings'];
        $attachment_ids    = $import_data['attachment_ids'];
        $user_id           = $import_data['user_id'];

        // Adjust batch size based on remaining files.
        $remaining_files   = $total_files - $current_index;
        $files_to_process  = min($batch_size, $remaining_files);

        // Handle case where no files to process (should not happen normally)
        if ($files_to_process <= 0) {
            // Clean up this failed import.
            $this->cleanup_failed_import($import_id, __('No files remaining to process', 'michael-cloud-image-auto-importer'));
            
            return array(
                'success' => false,
                'message' => __('No files to process. Import cancelled.', 'michael-cloud-image-auto-importer'),
            );
        }

        $batch_results = array(
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors'   => array(),
        );

        // Process each file in the batch.
        for ($i = 0; $i < $files_to_process; $i++) {
            $file_index = $current_index + $i;

            if (! isset($files[$file_index])) {
                continue;
            }

            $file   = $files[$file_index];
            $result = $this->process_single_file($file, $settings, $import_id);

            $batch_results['processed']++;

            if ($result['success']) {
                $batch_results['successful']++;
                $attachment_ids[] = $result['attachment_id'];
            } elseif ('skipped' === $result['status']) {
                $batch_results['skipped']++;
                /* translators: %1$s: file name, %2$s: skip reason */
                $batch_results['errors'][] = sprintf(__('%1$s: %2$s (Skipped)', 'michael-cloud-image-auto-importer'), $file['name'], $result['message']);
            } else {
                $batch_results['failed']++;
                /* translators: %1$s: file name, %2$s: error message */
                $batch_results['errors'][] = sprintf(__('%1$s: %2$s', 'michael-cloud-image-auto-importer'), $file['name'], $result['message']);
                $this->handle_import_error($import_id, $result['message'], $file['name']);
            }
        }

        // Update import data.
        $new_current_index     = $current_index + $files_to_process;
        $new_processed_files   = $processed_files + $batch_results['processed'];
        $new_successful_files  = $successful_files + $batch_results['successful'];
        $new_failed_files      = $failed_files + $batch_results['failed'];
        $new_skipped_files     = $skipped_files + $batch_results['skipped'];

        $import_data['current_index']    = $new_current_index;
        $import_data['processed_files']  = $new_processed_files;
        $import_data['successful_files'] = $new_successful_files;
        $import_data['failed_files']     = $new_failed_files;
        $import_data['skipped_files']    = $new_skipped_files;
        $import_data['attachment_ids']   = $attachment_ids;
        $import_data['last_update']      = current_time('mysql');
        $import_data['status']           = $new_current_index >= $total_files ? 'completed' : 'processing';

        update_option($option_key, $import_data, false);
        wp_cache_set($option_key, $import_data, 'cloud-auto-importer', 3600);

        // Update database log with caching.
        $this->update_import_log(
            $import_id,
            $total_files,
            $new_processed_files,
            $new_successful_files,
            $new_failed_files,
            $new_skipped_files,
            $new_current_index >= $total_files ? 'completed' : 'processing'
        );

        // Prepare response.
        $response = array(
            'success' => true,
            'import_id' => $import_id,
            'batch_results' => $batch_results,
            'progress' => array(
                'current'     => $new_current_index,
                'total'       => $total_files,
                'percentage'  => round(($new_current_index / $total_files) * 100),
                'processed'   => $new_processed_files,
                'successful'  => $new_successful_files,
                'failed'      => $new_failed_files,
                'skipped'     => $new_skipped_files,
            ),
            'completed' => $new_current_index >= $total_files,
        );
        /* translators: %1$d: number of successfully imported files, %2$d: number of failed imports, %3$d: number of skipped files */
        if ($response['completed']) {
            /* translators: %1$d: number of successfully imported files, %2$d: number of failed imports, %3$d: number of skipped files */
            $response['message'] = sprintf(
                /* translators: %1$d: number of successfully imported files, %2$d: number of failed imports, %3$d: number of skipped files */
                __('Import completed! %1$d successful, %2$d failed, %3$d skipped.', 'michael-cloud-image-auto-importer'),
                $new_successful_files,
                $new_failed_files,
                $new_skipped_files
            );
            $response['attachment_ids'] = $attachment_ids;

            // Clean up import data after completion with caching.
            $completed_key = "mcai_import_{$import_id}_completed";
            update_option($completed_key, $import_data, false);
            wp_cache_set($completed_key, $import_data, 'cloud-auto-importer', DAY_IN_SECONDS);

            delete_option($option_key);
            wp_cache_delete($option_key, 'cloud-auto-importer');

            // Store user who imported these files.
            foreach ($attachment_ids as $attachment_id) {
                update_post_meta($attachment_id, '_mcai_imported_by', $user_id);
                wp_cache_delete($attachment_id, 'post_meta');
            }
        }

        return $response;
    }

    /**
     * Update import log
     *
     * @param string $import_id        Import ID.
     * @param int    $total_files      Total files.
     * @param int    $processed_files  Processed files.
     * @param int    $successful_files Successful files.
     * @param int    $failed_files     Failed files.
     * @param int    $skipped_files    Skipped files.
     * @param string $status           Status.
     * @return void
     */
    private function update_import_log($import_id, $total_files, $processed_files, $successful_files, $failed_files, $skipped_files, $status)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcai_import_logs';

        // Validate table name
        if (!$this->is_valid_table_name($table_name)) {
            $this->handle_import_error($import_id, "Invalid table name in update_import_log: {$table_name}");
            return;
        }

        // Find the log entry for this import with caching.
        $cache_key = "import_log_{$import_id}";
        $log_id = wp_cache_get($cache_key, 'cloud-auto-importer');

        if (false === $log_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $log_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}mcai_import_logs WHERE import_id = %s ORDER BY id DESC LIMIT 1",
                    $import_id
                )
            );
            
            if ($log_id) {
                wp_cache_set($cache_key, $log_id, 'cloud-auto-importer', HOUR_IN_SECONDS);
            }
        }
        
        if ($log_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->update(
                $table_name,
                array(
                    'processed_files'  => absint($processed_files),
                    'successful_files' => absint($successful_files),
                    'failed_files'     => absint($failed_files),
                    'skipped_files'    => absint($skipped_files),
                    'status'           => sanitize_text_field($status),
                    'completed_at'     => 'completed' === $status ? current_time('mysql') : null,
                ),
                array('id' => absint($log_id)),
                array('%d', '%d', '%d', '%d', '%s', '%s'),
                array('%d')
            );

            // Clear cache.
            wp_cache_delete($cache_key, 'cloud-auto-importer');
        }
    }

    /**
     * Process a single file
     *
     * @param array  $file_data File data.
     * @param array  $settings  Settings.
     * @param string $import_id Import ID.
     * @return array Result array.
     */
    private function process_single_file($file_data, $settings, $import_id)
    {
        $file_name  = $file_data['name'];
        $file_id    = $file_data['id'];
        $mime_type  = $file_data['mimeType'];

        $this->log_activity("Starting processing for: {$file_name}", 'debug', array('import_id' => $import_id));

        // Skip non-image files with improved validation.
        if (! $this->is_image_file($file_name, $mime_type)) {
            $this->log_activity("Skipping non-image file: {$file_name}", 'warning', array('import_id' => $import_id));
            return array(
                'success' => false,
                'status'  => 'failed',
                'message' => __('Not an image file', 'michael-cloud-image-auto-importer'),
            );
        }

        // Download file from Google Drive (now using streaming)
        $download_result = $this->google_drive->download_file($file_id, $file_name);
        /* translators: %s: error message from download */
        if (! $download_result['success']) {
            /* translators: %s: error message from download */
            $this->log_activity("Download failed for {$file_name}: " . $download_result['message'], 'error', array('import_id' => $import_id));
            return array(
                /* translators: %s: error message from download */
                'success' => false,
                'status'  => 'failed',
                /* translators: %s: error message from download */
                'message' => sprintf(
                    /* translators: %s: error message from download */
                    __('Download failed: %s', 'michael-cloud-image-auto-importer'),
                    $download_result['message']
                ),
            );
        }

        $temp_file = $download_result['temp_file'];
        $this->log_activity("Downloaded {$file_name} to temp file", 'debug', array('import_id' => $import_id));

        // Generate file hash for duplicate detection using memory-efficient method
        $file_hash = $this->calculate_file_hash($temp_file);
        $this->log_activity("File hash generated for {$file_name}: {$file_hash}", 'debug', array('import_id' => $import_id));

        // Check for duplicates if enabled.
        if ($settings['skip_duplicates']) {
            $duplicate_check = $this->check_for_duplicate($file_name, $file_hash, $temp_file);

            if ($duplicate_check['is_duplicate']) {
                $this->log_activity(
                    "Duplicate detected for {$file_name} - Skipping. Existing attachment ID: " . $duplicate_check['existing_id'],
                    'info',
                    array('import_id' => $import_id)
                );

                // Clean up temp file
                $this->safe_delete_temp_file($temp_file);

                return array(
                    'success' => false,
                    'status'  => 'skipped',
                    'message' => __('Duplicate file skipped', 'michael-cloud-image-auto-importer'),
                );
            }
        }

        // Apply compression if enabled.
        $compression_applied = false;
        $compression_saved   = 0;

        if ($settings['compress'] && $this->is_compressible_image($temp_file)) {
            $this->log_activity("Starting compression for {$file_name}", 'debug', array('import_id' => $import_id));
            $compression_result = $this->compress_image($temp_file, $settings);

            if ($compression_result['success']) {
                // $temp_file is now the compressed version (original was deleted in compress_image)
                $compression_applied   = true;
                $compression_saved     = $compression_result['savings_percent'];

                $this->log_activity(
                    "Compression successful for {$file_name}: Saved {$compression_saved}%",
                    'success',
                    array('import_id' => $import_id)
                );
            } else {
                $this->log_activity(
                    "Compression failed for {$file_name}: " . $compression_result['message'],
                    'warning',
                    array('import_id' => $import_id)
                );
            }
        }

        // Generate alt text from filename.
        $alt_text = '';
        if ($settings['generate_alt_text']) {
            $alt_text = $this->generate_alt_text($file_name);
            $this->log_activity("Generated alt text for {$file_name}: {$alt_text}", 'debug', array('import_id' => $import_id));
        }

        // Save to WordPress Media Library WITH ALT TEXT.
        $attachment_id = $this->save_to_media_library($temp_file, $file_name, $import_id, $file_hash, $alt_text);

        // Clean up temp file safely (in case it wasn't cleaned up elsewhere).
        $this->safe_delete_temp_file($temp_file);
        
        /* translators: %s: error message from WordPress */
        if (is_wp_error($attachment_id)) {
            $this->log_activity(
                "Media Library error for {$file_name}: " . $attachment_id->get_error_message(),
                'error',
                array('import_id' => $import_id)
            );
            return array(
                'success' => false,
                'status'  => 'failed',
                /* translators: %s: error message from WordPress */
                'message' => sprintf(
                    /* translators: %s: error message from WordPress */
                    __('Media Library error: %s', 'michael-cloud-image-auto-importer'),
                    $attachment_id->get_error_message()
                ),
            );
        }

        if (! $attachment_id) {
            $this->log_activity("Failed to save {$file_name} to Media Library", 'error', array('import_id' => $import_id));
            return array(
                'success' => false,
                'status'  => 'failed',
                'message' => __('Failed to save to Media Library', 'michael-cloud-image-auto-importer'),
            );
        }

        // Add compression info to attachment meta with caching.
        if ($compression_applied) {
            $message = sprintf(
                /* translators: %s: compression status (e.g., "compressed") */                 
                __('Successfully imported (%s)', 'michael-cloud-image-auto-importer'),
                __('compressed', 'michael-cloud-image-auto-importer')
            );
        } else {
            $message = __('Successfully imported', 'michael-cloud-image-auto-importer');
        }
        
        // Update compression info in post meta
        if ($compression_applied) {
            update_post_meta($attachment_id, '_mcai_compression_saved', $compression_saved);
            update_post_meta($attachment_id, '_mcai_compression_applied', true);
            wp_cache_delete($attachment_id, 'post_meta');
        }
        
        $this->log_activity(
            "Successfully imported {$file_name} as attachment ID: {$attachment_id} with alt: {$alt_text}",
            'success',
            array('import_id' => $import_id)
        );

        return array(
            'success'             => true,
            'status'              => 'success',
            'attachment_id'       => $attachment_id,
            'file_name'           => $file_name,
            'file_hash'           => $file_hash,
            'alt_text'            => $alt_text,
            'compression_applied' => $compression_applied,
            'compression_saved'   => $compression_saved,
            'message'             => $message,
        );
    }

    /**
     * Safely delete temporary file
     *
     * @param string $temp_file Temporary file path.
     * @return bool True if deleted or doesn't exist.
     */
    private function safe_delete_temp_file($temp_file)
    {
        if (file_exists($temp_file)) {
            return wp_delete_file($temp_file);
        }
        return true;
    }

    /**
     * Check for duplicate files
     *
     * @param string $file_name File name.
     * @param string $file_hash File hash.
     * @param string $temp_file Temp file path.
     * @return array Duplicate check result.
     */
    private function check_for_duplicate($file_name, $file_hash, $temp_file)
    {
        global $wpdb;

        // Check by file hash (most reliable) with caching.
        $cache_key = 'mcai_file_hash_' . $file_hash;
        $existing_by_hash = wp_cache_get($cache_key, 'cloud-auto-importer');

        if (false === $existing_by_hash) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $existing_by_hash = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_mcai_file_hash' AND meta_value = %s LIMIT 1",
                    $file_hash
                )
            );
            wp_cache_set($cache_key, $existing_by_hash, 'cloud-auto-importer', HOUR_IN_SECONDS);
        }

        if ($existing_by_hash) {
            return array(
                'is_duplicate' => true,
                'existing_id'  => absint($existing_by_hash),
                'method'       => 'hash',
            );
        }

        return array(
            'is_duplicate' => false,
            'existing_id'  => null,
            'method'       => 'none',
        );
    }

    /**
     * Generate SEO-friendly alt text from filename
     *
     * @param string $filename Filename.
     * @return string Alt text.
     */
    private function generate_alt_text($filename)
    {
        // Remove file extension.
        $name_without_ext = preg_replace('/\\.[^.\\s]{3,4}$/', '', $filename);

        // Common patterns to clean.
        $patterns = array(
            '/\d{4}-\d{2}-\d{2}_/',    // Remove date prefixes: 2023-12-25_.
            '/\d{8}_/',                 // Remove date prefixes: 20231225_.
            '/\d+_/',                   // Remove number prefixes: 001_.
            '/^DSC_/',                  // Remove camera prefixes: DSC_.
            '/^IMG_/',                   // Remove IMG_ prefixes.
            '/^PIC_/',                  // Remove PIC_ prefixes.
            '/^Screenshot_/',           // Remove Screenshot_ prefixes.
            '/^photo_/',                // Remove photo_ prefixes.
            '/^image_/',                // Remove image_ prefixes.
        );

        $clean_name = preg_replace($patterns, '', $name_without_ext);

        // Replace separators with spaces.
        $separators  = array('-', '_', '.');
        $clean_name  = str_replace($separators, ' ', $clean_name);

        // Remove special characters.
        $clean_name  = preg_replace('/[^a-zA-Z0-9\s]/', '', $clean_name);

        // Remove extra spaces.
        $clean_name  = preg_replace('/\s+/', ' ', $clean_name);
        $clean_name  = trim($clean_name);

        // If empty, use the original name.
        if (empty($clean_name) || strlen($clean_name) < 2) {
            $clean_name  = $name_without_ext;
            $clean_name  = str_replace(array('-', '_'), ' ', $clean_name);
            $clean_name  = preg_replace('/[^a-zA-Z0-9\s]/', '', $clean_name);
            $clean_name  = trim($clean_name);
        }

        // Convert to lowercase then capitalize.
        $clean_name  = strtolower($clean_name);
        $clean_name  = ucwords($clean_name);

        // Add context if too generic.
        if (strlen($clean_name) < 5) {
            $clean_name = sprintf(
                /* translators: %s: clean name from download */
                __('Image of %s', 'michael-cloud-image-auto-importer'),
                $clean_name
            );
        }

        // Limit length.
        if (strlen($clean_name) > 125) {
            $clean_name = substr($clean_name, 0, 122) . '...';
        }

        // If still empty, create descriptive alt text.
        if (empty($clean_name)) {
            $extension   = pathinfo($filename, PATHINFO_EXTENSION);
            $clean_name = sprintf(
                /* translators: 1: file extension in uppercase, 2: original filename */
                __('Uploaded %1$s image: %2$s', 'michael-cloud-image-auto-importer'),
                strtoupper($extension),
                $filename
            );
        }

        return $clean_name;
    }

    /**
     * Compress an image file with memory optimization
     *
     * @param string $file_path File path.
     * @param array  $settings  Settings.
     * @return array Result array.
     */
    private function compress_image($file_path, $settings)
    {
        // Get original file size.
        $original_size = filesize($file_path);

        if (!function_exists('wp_get_image_editor')) {
            return array(
                'success' => false,
                'message' => __('Image editor not available', 'michael-cloud-image-auto-importer'),
            );
        }

        $editor = wp_get_image_editor($file_path);

        if (is_wp_error($editor)) {
            return array(
                'success' => false,
                'message' => $editor->get_error_message(),
            );
        }

        // Resize if needed.
        $size       = $editor->get_size();
        $max_width  = $settings['max_width'] ?? 1920;
        $max_height = $settings['max_height'] ?? 1080;

        if ($size['width'] > $max_width || $size['height'] > $max_height) {
            $editor->resize($max_width, $max_height, false);
        }

        // Generate temporary path for compressed version.
        $pathinfo        = pathinfo($file_path);
        $compressed_path = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '-compressed.' . $pathinfo['extension'];

        // Save with compression.
        $quality = $settings['compression_quality'] ?? 80;
        $result  = $editor->save($compressed_path, null, $quality);

        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message(),
            );
        }

        // Check if compression actually reduced size.
        $compressed_size = filesize($result['path']);

        if ($compressed_size >= $original_size) {
            // Compression didn't help, delete compressed version.
            wp_delete_file($result['path']);
            return array(
                'success' => false,
                'message' => __('Compression did not reduce file size', 'michael-cloud-image-auto-importer'),
            );
        }

        // Delete original file
        wp_delete_file($file_path);
        
        // Rename compressed to original path using WP_Filesystem
        global $wp_filesystem;
        
        // Initialize WP_Filesystem if needed
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        // Move/rename the file
        $wp_filesystem->move($compressed_path, $file_path, true);

        $savings_percent = round((($original_size - $compressed_size) / $original_size) * 100, 2);

        return array(
            'success'         => true,
            'file_path'       => $file_path,
            'original_size'   => $original_size,
            'compressed_size' => $compressed_size,
            'savings_percent' => $savings_percent,
        );
    }

    /**
     * Save file to WordPress Media Library
     *
     * @param string $file_path  File path.
     * @param string $file_name  File name.
     * @param string $import_id  Import ID.
     * @param string $file_hash  File hash.
     * @param string $alt_text   Alt text.
     * @return int|WP_Error Attachment ID or error.
     */
    private function save_to_media_library($file_path, $file_name, $import_id, $file_hash, $alt_text = '')
    {
        // Validate file exists
        if (!file_exists($file_path)) {
            return new WP_Error('file_missing', __('Temporary file not found', 'michael-cloud-image-auto-importer'));
        }

        // Validate file path is within temp directory
        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . 'mcai_temp';
        
        if (0 !== strpos(realpath($file_path), realpath($temp_dir))) {
            return new WP_Error('invalid_path', __('Invalid file path', 'michael-cloud-image-auto-importer'));
        }

        // Prepare file array for media_handle_sideload.
        $file_array = array(
            'name'     => sanitize_file_name($file_name),
            'tmp_name' => $file_path,
        );

        // Include required WordPress files.
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        // Note: image.php is not directly required as media_handle_sideload handles it

        // Upload to Media Library.
        $attachment_id = media_handle_sideload($file_array, 0);

        // Add import metadata.
        if (! is_wp_error($attachment_id)) {
            // Store file hash for duplicate detection.
            update_post_meta($attachment_id, '_mcai_import_id', $import_id);
            update_post_meta($attachment_id, '_mcai_imported_at', current_time('mysql'));
            update_post_meta($attachment_id, '_mcai_file_hash', $file_hash);
            update_post_meta($attachment_id, '_mcai_imported_by', get_current_user_id());

            // Set alt text.
            if (! empty($alt_text)) {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
            }

            // Also set the post title.
            wp_update_post(
                array(
                    'ID'         => absint($attachment_id),
                    'post_title' => ! empty($alt_text) ? $alt_text : sanitize_file_name($file_name),
                )
            );

            // Clear cache for this attachment.
            wp_cache_delete($attachment_id, 'post_meta');

            $this->log_activity(
                "Stored metadata for attachment {$attachment_id}: Hash={$file_hash} | Alt={$alt_text}",
                'debug',
                array('import_id' => $import_id)
            );
        }

        return $attachment_id;
    }

    /**
     * Check if file is an image with improved validation
     *
     * @param string $file_name File name.
     * @param string $mime_type MIME type.
     * @return bool Is image.
     */
    private function is_image_file($file_name, $mime_type)
    {
        $image_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'svg', 'ico');
        $image_mimes = array(
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 
            'image/bmp', 'image/tiff', 'image/x-tiff', 'image/svg+xml', 'image/x-icon'
        );
        
        $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        return in_array($extension, $image_extensions, true) || in_array($mime_type, $image_mimes, true);
    }

    /**
     * Check if image can be compressed
     *
     * @param string $file_path File path.
     * @return bool Is compressible.
     */
    private function is_compressible_image($file_path)
    {
        $compressible_types = array('image/jpeg', 'image/png', 'image/webp');

        if (! function_exists('mime_content_type')) {
            // Fallback to extension check.
            $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            return in_array($extension, array('jpg', 'jpeg', 'png', 'webp'), true);
        }

        $mime_type = mime_content_type($file_path);
        return in_array($mime_type, $compressible_types, true);
    }

    /**
     * Extract folder ID from Google Drive URL
     *
     * @param string $url Google Drive URL.
     * @return string|false Folder ID or false.
     */
    private function extract_folder_id($url)
    {
        if (preg_match('/\/folders\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return sanitize_text_field($matches[1]);
        }

        if (preg_match('/id=([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return sanitize_text_field($matches[1]);
        }

        // Handle short URL format.
        if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return sanitize_text_field($matches[1]);
        }

        return false;
    }

    /**
     * AJAX: Get import status
     *
     * @return void
     */
    public function ajax_get_import_status()
    {
        check_ajax_referer('mcai_ajax_nonce', 'nonce');

        if (! current_user_can('upload_files')) {
            wp_send_json_error(
                array(
                    'message' => __('Permission denied', 'michael-cloud-image-auto-importer'),
                )
            );
        }

        // Sanitize input.
        $import_id = isset($_POST['import_id']) ? sanitize_text_field(wp_unslash($_POST['import_id'])) : '';

        if (empty($import_id)) {
            wp_send_json_error(
                array(
                    'message' => __('Import ID required', 'michael-cloud-image-auto-importer'),
                )
            );
        }

        // Verify user owns this import
        if (!$this->validate_import_ownership($import_id, get_current_user_id())) {
            wp_send_json_error(
                array(
                    'message' => __('Access denied to this import', 'michael-cloud-image-auto-importer'),
                )
            );
        }

        // Check cache first.
        $cache_key = "mcai_import_{$import_id}";
        $import_data = wp_cache_get($cache_key, 'cloud-auto-importer');

        if (false === $import_data) {
            $import_data = get_option($cache_key);
        }

        if (! $import_data) {
            // Check if completed.
            $completed_key = "mcai_import_{$import_id}_completed";
            $import_data = wp_cache_get($completed_key, 'cloud-auto-importer');

            if (false === $import_data) {
                $import_data = get_option($completed_key);
            }

            if (! $import_data) {
                wp_send_json_error(
                    array(
                        'message' => __('Import not found', 'michael-cloud-image-auto-importer'),
                    )
                );
            }

            wp_send_json_success(
                array(
                    'completed' => true,
                    'progress'  => array(
                        'current'     => $import_data['total_files'],
                        'total'       => $import_data['total_files'],
                        'percentage'  => 100,
                        'processed'   => $import_data['processed_files'],
                        'successful'  => $import_data['successful_files'],
                        'failed'      => $import_data['failed_files'],
                        'skipped'     => $import_data['skipped_files'],
                    ),
                    'attachment_ids' => $import_data['attachment_ids'],
                )
            );
        }

        $current_index = $import_data['current_index'];
        $total_files   = $import_data['total_files'];

        wp_send_json_success(
            array(
                'completed' => $current_index >= $total_files,
                'progress'  => array(
                    'current'     => $current_index,
                    'total'       => $total_files,
                    'percentage'  => round(($current_index / $total_files) * 100),
                    'processed'   => $import_data['processed_files'],
                    'successful'  => $import_data['successful_files'],
                    'failed'      => $import_data['failed_files'],
                    'skipped'     => $import_data['skipped_files'],
                ),
            )
        );
    }
}