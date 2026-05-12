<?php

/**
 * Cloud Auto Importer - Google Drive Integration
 * FIXED VERSION - Uses streaming downloads for memory efficiency
 *
 * @package CloudAutoImporter
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Google Drive integration class
 */
class MCAI_Google_Drive
{
    /**
     * Client ID
     *
     * @var string
     */
    private $client_id;

    /**
     * Client secret
     *
     * @var string
     */
    private $client_secret;

    /**
     * Redirect URI
     *
     * @var string
     */
    private $redirect_uri;

    /**
     * Debug mode
     *
     * @var bool
     */
    private $debug_mode;

    /**
     * WordPress Filesystem instance
     *
     * @var WP_Filesystem_Base
     */
    private $wp_filesystem;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Get credentials from WordPress options.
        $this->client_id     = get_option('mcai_google_client_id', '');
        $this->client_secret = get_option('mcai_google_client_secret', '');

        $this->redirect_uri = add_query_arg(
            array('page' => 'cloud-auto-importer'),
            admin_url('admin.php')
        );

        // Set debug mode.
        $this->debug_mode = defined('MCAI_DEBUG') && MCAI_DEBUG;

        $this->log_message('Google Drive class initialized', 'info');

        add_action('admin_init', array($this, 'handle_oauth_callback'));
        add_action('admin_init', array($this, 'check_google_connection_status'));

        // Initialize transient tracking
        add_action('init', array($this, 'init_transient_tracking'));
        
        // Schedule cleanup of temporary files
        add_action('wp', array($this, 'schedule_cleanup'));
        add_action('mcai_cleanup_temp_files', array($this, 'cleanup_temp_files'));
        
        // Initialize filesystem
        add_action('init', array($this, 'init_filesystem'));
    }

    /**
     * Initialize WordPress Filesystem
     *
     * @return void
     */
    public function init_filesystem()
    {
        global $wp_filesystem;
        
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        $this->wp_filesystem = $wp_filesystem;
    }

    /**
     * Initialize transient tracking
     *
     * @return void
     */
    public function init_transient_tracking()
    {
        // Ensure the transient list option exists
        if (false === get_option('mcai_transient_list', false)) {
            update_option('mcai_transient_list', array(), false);
        }
    }

    /**
     * Schedule cleanup of temporary files
     *
     * @return void
     */
    public function schedule_cleanup()
    {
        if (!wp_next_scheduled('mcai_cleanup_temp_files')) {
            wp_schedule_event(time(), 'hourly', 'mcai_cleanup_temp_files');
        }
    }

    /**
     * Clean up old temporary files
     *
     * @param int $max_age Maximum age in seconds (default: 1 hour)
     * @return void
     */
    public function cleanup_temp_files($max_age = 3600)
    {
        $this->init_filesystem();
        
        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . 'mcai_temp';
        
        if (!$this->wp_filesystem->is_dir($temp_dir)) {
            return;
        }
        
        $files = glob($temp_dir . '/mcai_*');
        $now = time();
        
        foreach ($files as $file) {
            if ($this->wp_filesystem->is_file($file) && ($now - filemtime($file)) > $max_age) {
                wp_delete_file($file);
            }
        }
        
        $this->log_message('Cleaned up old temporary files', 'info');
    }

    /**
     * Log message with debug control
     *
     * @param string $message Message to log.
     * @param string $type    Log type.
     * @return void
     */
    private function log_message($message, $type = 'info')
    {
        if ($this->debug_mode) {
            mcai_log($message, $type);
        }
    }

    /**
     * Get authentication URL
     *
     * @return string|false Authentication URL or false if credentials missing.
     */
    public function get_auth_url()
    {
        if (empty($this->client_id) || empty($this->client_secret)) {
            return false;
        }

        $params = array(
            'client_id'     => $this->client_id,
            'redirect_uri'  => $this->redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/drive.readonly',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => wp_create_nonce('mcai_google_oauth'),
        );

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Handle OAuth callback
     *
     * @return void
     */
    public function handle_oauth_callback()
    {
        // Check user capability.
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have permission to perform this action.', 'michael-cloud-image-auto-importer'),
                esc_html__('Permission Error', 'michael-cloud-image-auto-importer'),
                array('response' => 403)
            );
        }

        // Check if this is our OAuth callback.
        if (! isset($_GET['code']) || ! isset($_GET['state'])) {
            return;
        }

        if (! isset($_GET['page']) || 'cloud-auto-importer' !== $_GET['page']) {
            return;
        }

        // Sanitize and validate inputs before using them
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';

        // Verify nonce with sanitized value
        if (! wp_verify_nonce($state, 'mcai_google_oauth')) {
            wp_die(
                esc_html__('Security check failed', 'michael-cloud-image-auto-importer'),
                esc_html__('Security Error', 'michael-cloud-image-auto-importer'),
                array('response' => 403)
            );
        }

        // Process the authorization code
        $tokens = $this->exchange_code_for_tokens($code);

        if (is_wp_error($tokens)) {
            update_option('mcai_last_oauth_error', $tokens->get_error_message());
            wp_safe_redirect(admin_url('admin.php?page=cloud-auto-importer&oauth_error=1'));
            exit;
        }

        update_option('mcai_google_access_token', $tokens['access_token']);
        update_option('mcai_google_refresh_token', $tokens['refresh_token']);
        update_option('mcai_google_token_expiry', time() + $tokens['expires_in']);
        update_option('mcai_google_token_received', current_time('mysql'));

        delete_option('mcai_last_oauth_error');

        // Clear cache on new connection.
        $this->clear_cache();

        wp_safe_redirect(admin_url('admin.php?page=cloud-auto-importer&connected=1'));
        exit;
    }

    /**
     * Exchange authorization code for tokens
     *
     * @param string $code Authorization code.
     * @return array|WP_Error Tokens or error.
     */
    private function exchange_code_for_tokens($code)
    {
        // Check user consent first.
        if (! get_option('mcai_user_consent_given', false)) {
            return new WP_Error(
                'no_consent',
                __('User consent for external connections is required.', 'michael-cloud-image-auto-importer')
            );
        }

        if (empty($this->client_id) || empty($this->client_secret)) {
            return new WP_Error(
                'no_credentials',
                __('Google API credentials not configured', 'michael-cloud-image-auto-importer')
            );
        }

        $post_data = array(
            'code'          => sanitize_text_field($code),
            'client_id'     => sanitize_text_field($this->client_id),
            'client_secret' => sanitize_text_field($this->client_secret),
            'redirect_uri'  => esc_url_raw($this->redirect_uri),
            'grant_type'    => 'authorization_code',
        );

        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            array(
                'body'    => $post_data,
                'timeout' => 30,
            )
        );

        if (is_wp_error($response)) {
            $this->log_message('Token exchange failed: ' . $response->get_error_message(), 'error');
            return new WP_Error('connection_failed', $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Check for JSON parse errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_message('Invalid JSON response: ' . json_last_error_msg(), 'error');
            return new WP_Error('invalid_response', __('Invalid response from Google', 'michael-cloud-image-auto-importer'));
        }

        // Check HTTP status code
        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            $error_msg = isset($data['error_description']) ? $data['error_description'] : 
                        (isset($data['error']) ? $data['error'] : "HTTP {$status}");
            return new WP_Error('api_error', sanitize_text_field($error_msg));
        }

        if (! isset($data['access_token'])) {
            $error_msg = isset($data['error_description']) ? $data['error_description'] : 'No access token returned';
            return new WP_Error('no_token', sanitize_text_field($error_msg));
        }

        return array(
            'access_token'  => sanitize_text_field($data['access_token']),
            'refresh_token' => isset($data['refresh_token']) ? sanitize_text_field($data['refresh_token']) : '',
            'expires_in'    => isset($data['expires_in']) ? absint($data['expires_in']) : 3600,
        );
    }

    /**
     * Check if we're being rate limited
     *
     * @return bool True if rate limited
     */
    private function is_rate_limited()
    {
        $rate_key = 'mcai_api_requests_' . gmdate('YmdH');
        $requests = get_transient($rate_key) ?: 0;
        
        if ($requests > 100) { // Adjust limit as needed
            return true;
        }
        
        set_transient($rate_key, $requests + 1, HOUR_IN_SECONDS);
        $this->register_transient($rate_key);
        
        return false;
    }

    /**
     * Check and refresh access token if needed
     *
     * @return void
     */
    public function check_google_connection_status()
    {
        $expiry = get_option('mcai_google_token_expiry', 0);
        if ($expiry < time() + 300) { // 5 minutes buffer
            $this->refresh_access_token();
        }
    }

    /**
     * Refresh access token using refresh token
     *
     * @return bool Success or failure.
     */
    private function refresh_access_token()
    {
        // Check if we recently tried to refresh.
        $transient_key = 'mcai_token_refresh_attempt';
        if (get_transient($transient_key)) {
            return false; // Wait before trying again.
        }

        // Check user consent.
        if (! get_option('mcai_user_consent_given', false)) {
            return false;
        }

        $refresh_token = get_option('mcai_google_refresh_token', '');
        if (empty($refresh_token) || empty($this->client_id) || empty($this->client_secret)) {
            return false;
        }

        // Set transient to prevent frequent attempts.
        set_transient($transient_key, true, 60); // 60 seconds.
        $this->register_transient($transient_key);

        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            array(
                'body' => array(
                    'client_id'     => sanitize_text_field($this->client_id),
                    'client_secret' => sanitize_text_field($this->client_secret),
                    'refresh_token' => sanitize_text_field($refresh_token),
                    'grant_type'    => 'refresh_token',
                ),
                'timeout' => 30,
            )
        );

        if (is_wp_error($response)) {
            $this->log_message('Token refresh failed: ' . $response->get_error_message(), 'error');
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['access_token'])) {
            update_option('mcai_google_access_token', sanitize_text_field($data['access_token']));
            update_option(
                'mcai_google_token_expiry',
                time() + absint(isset($data['expires_in']) ? $data['expires_in'] : 3600)
            );
            $this->log_message('Access token refreshed successfully', 'success');
            return true;
        }

        $error_msg = isset($data['error_description']) ? $data['error_description'] : 'Unknown error';
        $this->log_message('Token refresh error: ' . $error_msg, 'error');
        return false;
    }

    /**
     * Check if connected to Google Drive
     *
     * @return bool Connected or not.
     */
    public function is_connected()
    {
        $token         = get_option('mcai_google_access_token', '');
        $expiry        = get_option('mcai_google_token_expiry', 0);
        $client_id     = get_option('mcai_google_client_id', '');
        $client_secret = get_option('mcai_google_client_secret', '');

        return ! empty($token) && $expiry > time() && ! empty($client_id) && ! empty($client_secret);
    }

    /**
     * Get connection status
     *
     * @return array Connection status.
     */
    public function get_connection_status()
    {
        return array(
            'connected' => $this->is_connected(),
            'client_id' => ! empty($this->client_id),
            'client_secret' => ! empty($this->client_secret),
            'token_expiry' => get_option('mcai_google_token_expiry', 0),
        );
    }

    /**
     * Disconnect from Google Drive
     *
     * @param string $nonce Nonce for security.
     * @return array Result.
     */
    public function disconnect($nonce = '')
    {
        // Check if nonce is provided in request if not passed directly
        if (empty($nonce) && isset($_POST['_wpnonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        }
        
        // Verify nonce
        if (!wp_verify_nonce($nonce, 'mcai_google_disconnect')) {
            return array(
                'success' => false,
                'message' => __('Security check failed', 'michael-cloud-image-auto-importer'),
            );
        }

        // Check user capability.
        if (!current_user_can('manage_options')) {
            return array(
                'success' => false,
                'message' => __('You do not have permission to perform this action.', 'michael-cloud-image-auto-importer'),
            );
        }

        delete_option('mcai_google_access_token');
        delete_option('mcai_google_refresh_token');
        delete_option('mcai_google_token_expiry');
        delete_option('mcai_google_token_received');

        // Clear cache on disconnect.
        $this->clear_cache();

        $this->log_message('Disconnected from Google Drive', 'info');

        return array(
            'success' => true,
            'message' => __('Disconnected successfully from Google Drive', 'michael-cloud-image-auto-importer'),
        );
    }

    /**
     * Check if MIME type is an image (more inclusive)
     *
     * @param string $mime     MIME type.
     * @param string $filename File name.
     * @return bool Is image or not.
     */
    private function is_image_mime($mime, $filename = '')
    {
        // Accept ANY MIME type that starts with "image/".
        if (strpos($mime, 'image/') === 0) {
            return true;
        }
        
        // Additional image MIME types that might not start with "image/"
        $additional_image_mimes = array(
            'application/postscript', // .eps
            'application/illustrator', // .ai
            'application/photoshop', // .psd
            'application/x-photoshop',
        );
        
        if (in_array($mime, $additional_image_mimes, true)) {
            return true;
        }

        // Fallback: Check by file extension.
        if (! empty($filename)) {
            $extension        = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $image_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'svg', 'ico', 'eps', 'ai', 'psd');
            return in_array($extension, $image_extensions, true);
        }

        return false;
    }

    /**
     * List files with better image detection and caching
     *
     * @param string $folder_id Folder ID.
     * @param int    $limit     Limit of files.
     * @return array List of files.
     */
    public function list_files($folder_id = 'root', $limit = 1000)
    {
        // Check rate limiting
        if ($this->is_rate_limited()) {
            return array(
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'michael-cloud-image-auto-importer'),
            );
        }

        // Validate parameters.
        $folder_id = sanitize_text_field($folder_id);
        $limit = absint($limit);
        $limit = max(1, min(1000, $limit)); // Limit between 1 and 1000.

        // Check user consent first.
        if (! get_option('mcai_user_consent_given', false)) {
            return array(
                'success' => false,
                'message' => __('Please enable external connections in settings.', 'michael-cloud-image-auto-importer'),
            );
        }

        if (! $this->is_connected()) {
            return array(
                'success' => false,
                'message' => __('Not connected to Google Drive', 'michael-cloud-image-auto-importer'),
            );
        }

        // Cache key based on folder and limit.
        $cache_key = 'mcai_gdrive_files_' . md5($folder_id . '_' . $limit);
        $cached = wp_cache_get($cache_key, 'cloud-auto-importer');

        if (false !== $cached) {
            $this->log_message("Returning cached files for folder: {$folder_id}", 'info');
            return $cached;
        }

        $access_token = get_option('mcai_google_access_token');
        $files        = array();
        $pageToken    = null;

        $this->log_message("Listing files in folder: {$folder_id}", 'info');

        do {
            $params = array(
                'q'                       => "'" . $folder_id . "' in parents and trashed = false",
                'pageSize'                => min(100, $limit),
                'fields'                  => 'nextPageToken, files(id,name,mimeType,size)',
                'pageToken'               => $pageToken,
                'orderBy'                 => 'name',
                'supportsAllDrives'       => 'true',
                'includeItemsFromAllDrives' => 'true',
            );

            $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query($params);

            $response = wp_remote_get(
                $url,
                array(
                    'headers' => array('Authorization' => 'Bearer ' . $access_token),
                    'timeout' => 30,
                )
            );

            if (is_wp_error($response)) {
                $this->log_message('Network error: ' . $response->get_error_message(), 'error');
                return array(
                    'success' => false,
                    'message' => __('Network error', 'michael-cloud-image-auto-importer'),
                );
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (200 !== $code) {
                $this->log_message("API error {$code}", 'error');
                $error_msg = isset($data['error']['message']) ? $data['error']['message'] : "API error {$code}";
                return array(
                    'success' => false,
                    /* translators: %s: error message */
                    'message' => sprintf(__('API error: %s', 'michael-cloud-image-auto-importer'), $error_msg),
                );
            }

            $batch = isset($data['files']) ? $data['files'] : array();

            foreach ($batch as $file) {
                $is_image = $this->is_image_mime($file['mimeType'], $file['name']);

                if ($is_image) {
                    $files[] = array(
                        'id'       => sanitize_text_field($file['id']),
                        'name'     => sanitize_text_field($file['name']),
                        'mimeType' => sanitize_text_field($file['mimeType']),
                        'size'     => isset($file['size']) ? absint($file['size']) : 0,
                    );
                }
            }

            $pageToken = isset($data['nextPageToken']) ? sanitize_text_field($data['nextPageToken']) : null;
        } while ($pageToken && count($files) < $limit);

        $this->log_message('Total images found: ' . count($files), 'success');

        $result = array(
            'success' => true,
            'files'   => $files,
            'count'   => count($files),
            'total'   => count($files),
        );

        // Cache the results for 5 minutes.
        wp_cache_set($cache_key, $result, 'cloud-auto-importer', 5 * MINUTE_IN_SECONDS);
        $this->register_transient($cache_key);

        return $result;
    }

    /**
     * Get file count with caching
     *
     * @param string $folder_id Folder ID.
     * @return array File count.
     */
    public function get_file_count($folder_id)
    {
        $folder_id = sanitize_text_field($folder_id);

        // Cache key for count.
        $cache_key = 'mcai_gdrive_count_' . md5($folder_id);
        $cached = wp_cache_get($cache_key, 'cloud-auto-importer');

        if (false !== $cached) {
            return $cached;
        }

        $result = $this->list_files($folder_id, 1000);

        $count_result = array(
            'success' => $result['success'],
            'count'   => $result['success'] ? $result['count'] : 0,
            'message' => isset($result['message']) ? $result['message'] : '',
        );

        // Cache count for 10 minutes.
        wp_cache_set($cache_key, $count_result, 'cloud-auto-importer', 10 * MINUTE_IN_SECONDS);
        $this->register_transient($cache_key);

        return $count_result;
    }

    /**
     * Verify downloaded file is valid
     *
     * @param string $file_path Path to downloaded file
     * @param string $expected_mime Expected MIME type
     * @return bool|WP_Error
     */
    private function verify_download($file_path, $expected_mime = '')
    {
        $this->init_filesystem();
        
        if (!$this->wp_filesystem->exists($file_path)) {
            return new WP_Error('file_missing', __('Downloaded file missing', 'michael-cloud-image-auto-importer'));
        }
        
        // Check if it's a valid image
        $image_info = @getimagesize($file_path);
        if ($image_info === false) {
            // Not an image, check if it's an error message from Google
            $content = $this->wp_filesystem->get_contents($file_path);
            if ($content && strpos($content, 'error') !== false) {
                return new WP_Error('api_error', __('Downloaded file contains API error', 'michael-cloud-image-auto-importer'));
            }
            return new WP_Error('invalid_image', __('Downloaded file is not a valid image', 'michael-cloud-image-auto-importer'));
        }
        
        // Verify MIME type if expected
        if ($expected_mime && $image_info['mime'] !== $expected_mime) {
            $this->log_message("MIME mismatch: expected {$expected_mime}, got {$image_info['mime']}", 'warning');
        }
        
        return true;
    }

    /**
     * DOWNLOAD FILE FROM GOOGLE DRIVE - FIXED VERSION
     * Uses WordPress HTTP API with streaming for memory efficiency
     *
     * @param string $file_id   File ID.
     * @param string $file_name File name.
     * @return array Download result.
     */
    public function download_file($file_id, $file_name)
    {
        // Check rate limiting
        if ($this->is_rate_limited()) {
            return array(
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'michael-cloud-image-auto-importer'),
            );
        }

        // Validate parameters.
        $file_id = sanitize_text_field($file_id);
        $file_name = sanitize_text_field($file_name);

        // Check user consent first.
        if (! get_option('mcai_user_consent_given', false)) {
            return array(
                'success' => false,
                'message' => __('Please enable external connections in settings.', 'michael-cloud-image-auto-importer'),
            );
        }

        if (! $this->is_connected()) {
            return array(
                'success' => false,
                'message' => __('Not connected to Google Drive', 'michael-cloud-image-auto-importer'),
            );
        }

        // Initialize filesystem
        $this->init_filesystem();

        $access_token = get_option('mcai_google_access_token');

        // Create temp directory if it doesn't exist.
        $upload_dir = wp_upload_dir();
        $temp_dir   = trailingslashit($upload_dir['basedir']) . 'mcai_temp';

        // Create directory if not exists using WordPress function
        if (!$this->wp_filesystem->is_dir($temp_dir)) {
            wp_mkdir_p($temp_dir);
            
            // Add security files to prevent direct access
            if (!$this->wp_filesystem->exists($temp_dir . '/.htaccess')) {
                $htaccess_content = "# Deny access to all files
<Files *>
    Order Deny,Allow
    Deny from all
</Files>

# But allow PHP files to be accessed through WordPress
<FilesMatch \"\\.php$\">
    Order Deny,Allow
    Deny from all
</FilesMatch>";
                $this->wp_filesystem->put_contents($temp_dir . '/.htaccess', $htaccess_content);
            }
            if (!$this->wp_filesystem->exists($temp_dir . '/index.php')) {
                $this->wp_filesystem->put_contents($temp_dir . '/index.php', '<?php // Silence is golden');
            }
        }

        // Check if directory is writable using WordPress filesystem API
        if (!$this->wp_filesystem->is_writable($temp_dir)) {
            $this->log_message("Temp directory not writable: {$temp_dir}", 'error');
            return array(
                'success' => false,
                'message' => __('Server temp directory is not writable', 'michael-cloud-image-auto-importer'),
            );
        }

        // Clean file name and create temp file path.
        $clean_name = sanitize_file_name($file_name);
        $temp_file  = trailingslashit($temp_dir) . uniqid('mcai_', true) . '_' . $clean_name;

        $this->log_message("Starting streaming download: {$file_name}", 'debug');

        // USE WORDPRESS HTTP API WITH STREAMING
        $url = "https://www.googleapis.com/drive/v3/files/{$file_id}?alt=media";
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token
            ),
            'timeout' => 300, // 5 minutes timeout for large files
            'stream' => true,  // Streams directly to disk
            'filename' => $temp_file,
            'user-agent' => 'WordPress/Cloud-Auto-Importer'
        ));

        // Check for WP_Error
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_message("Download failed for {$file_name}: {$error_message}", 'error');
            
            // Clean up partial file using WordPress function
            if ($this->wp_filesystem->exists($temp_file)) {
                wp_delete_file($temp_file);
            }
            
            return array(
                'success' => false,
                'message' => sprintf(
                    __('Download failed: %s', 'michael-cloud-image-auto-importer'),
                    $error_message
                ),
            );
        }

        // Check HTTP response code
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            $this->log_message("HTTP error {$http_code} for {$file_name}", 'error');
            
            // Clean up partial file using WordPress function
            if ($this->wp_filesystem->exists($temp_file)) {
                wp_delete_file($temp_file);
            }
            
            return array(
                'success' => false,
                'message' => sprintf(
                    __('Download failed with HTTP code: %d', 'michael-cloud-image-auto-importer'),
                    $http_code
                ),
            );
        }

        // Verify file was downloaded successfully
        if (!$this->wp_filesystem->exists($temp_file)) {
            $this->log_message("File does not exist after download: {$temp_file}", 'error');
            return array(
                'success' => false,
                'message' => __('Download failed: File not created', 'michael-cloud-image-auto-importer'),
            );
        }

        // Check file size using WordPress filesystem
        $file_size = $this->wp_filesystem->size($temp_file);
        if ($file_size === false || $file_size === 0) {
            $this->log_message("Downloaded file is empty: {$temp_file}", 'error');
            if ($this->wp_filesystem->exists($temp_file)) {
                wp_delete_file($temp_file);
            }
            return array(
                'success' => false,
                'message' => __('Downloaded file is empty', 'michael-cloud-image-auto-importer'),
            );
        }

        // Verify the downloaded file is valid
        $verification = $this->verify_download($temp_file);
        if (is_wp_error($verification)) {
            $this->log_message("File verification failed: " . $verification->get_error_message(), 'error');
            if ($this->wp_filesystem->exists($temp_file)) {
                wp_delete_file($temp_file);
            }
            return array(
                'success' => false,
                'message' => $verification->get_error_message(),
            );
        }

        $this->log_message("Download successful: {$file_name} ({$file_size} bytes)", 'success');

        return array(
            'success'   => true,
            'temp_file' => $temp_file,
            'file_size' => $file_size,
        );
    }

    /**
     * Extract folder ID from Google Drive URL
     *
     * @param string $url Google Drive URL.
     * @return string|false Folder ID or false.
     */
    public static function extract_folder_id($url)
    {
        $url = esc_url_raw($url);

        $patterns = array(
            '/\/folders\/([a-zA-Z0-9_-]+)/',
            '/id=([a-zA-Z0-9_-]+)/',
            '/\/d\/([a-zA-Z0-9_-]+)/',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return sanitize_text_field($matches[1]);
            }
        }
        return false;
    }

    /**
     * Clear cached data
     *
     * @return void
     */
    private function clear_cache()
    {
        // Clear specific transients
        $this->clear_specific_transients();

        // Clear object cache for file and count data
        $this->flush_object_cache();

        $this->log_message('Cache cleared', 'info');
    }

    /**
     * Clear specific transients used by the plugin
     *
     * @return void
     */
    private function clear_specific_transients()
    {
        // Clear token refresh transient
        delete_transient('mcai_token_refresh_attempt');

        // Get list of tracked transients and delete them
        $transient_list = get_option('mcai_transient_list', array());

        foreach ($transient_list as $transient_name) {
            delete_transient($transient_name);
        }

        // Clear the transient list itself
        update_option('mcai_transient_list', array(), false);
    }

    /**
     * Flush object cache for this plugin
     *
     * @return void
     */
    private function flush_object_cache()
    {
        global $wp_object_cache;

        if (function_exists('wp_cache_flush_group') && wp_cache_supports('flush_group')) {
            // WordPress 6.1+ supports cache groups
            wp_cache_flush_group('cloud-auto-importer');
        } else {
            // Fallback for older versions - manually clear cache keys
            $pattern = '/^mcai_/';

            if (isset($wp_object_cache->cache['cloud-auto-importer'])) {
                foreach (array_keys($wp_object_cache->cache['cloud-auto-importer']) as $key) {
                    if (preg_match($pattern, $key)) {
                        wp_cache_delete($key, 'cloud-auto-importer');
                    }
                }
            }
        }
    }

    /**
     * Register a transient for tracking
     *
     * @param string $transient_name Transient name.
     * @return void
     */
    private function register_transient($transient_name)
    {
        $transient_list = get_option('mcai_transient_list', array());
        if (!in_array($transient_name, $transient_list, true)) {
            $transient_list[] = $transient_name;
            update_option('mcai_transient_list', array_unique($transient_list), false);
        }
    }
}