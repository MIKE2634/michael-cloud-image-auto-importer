<?php

/**
 * Cloud Auto Importer - Google Drive Integration
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
            return new WP_Error('connection_failed', $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

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
        $this->register_transient($transient_key); // Track this transient

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
        // Verify nonce if provided.
        if (!empty($nonce) && !wp_verify_nonce($nonce, 'mcai_google_disconnect')) {
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

        // Fallback: Check by file extension.
        if (! empty($filename)) {
            $extension        = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $image_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'svg', 'ico');
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
        $this->register_transient($cache_key); // Track this cache key

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
        $this->register_transient($cache_key); // Track this cache key

        return $count_result;
    }

    /**
     * Download file from Google Drive
     *
     * @param string $file_id   File ID.
     * @param string $file_name File name.
     * @return array Download result.
     */
    public function download_file($file_id, $file_name)
    {
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

        $access_token = get_option('mcai_google_access_token');

        // Create temp directory if it doesn't exist.
        $upload_dir = wp_upload_dir();
        $temp_dir   = trailingslashit($upload_dir['basedir']) . 'mcai_temp';

        // Initialize WP_Filesystem.
        if (! function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        global $wp_filesystem;
        if (! WP_Filesystem()) {
            return array(
                'success' => false,
                'message' => __('Filesystem error', 'michael-cloud-image-auto-importer'),
            );
        }

        if (! $wp_filesystem->exists($temp_dir)) {
            if (! $wp_filesystem->mkdir($temp_dir, 0755)) {
                return array(
                    'success' => false,
                    'message' => __('Could not create temp directory', 'michael-cloud-image-auto-importer'),
                );
            }
        }

        // Clean file name.
        $clean_name = sanitize_file_name($file_name);
        $temp_file  = trailingslashit($temp_dir) . uniqid('mcai_') . '_' . $clean_name;

        // Download file.
        $url = "https://www.googleapis.com/drive/v3/files/{$file_id}?alt=media";

        $response = wp_remote_get(
            $url,
            array(
                'headers' => array('Authorization' => 'Bearer ' . $access_token),
                'timeout' => 60,
                'stream'  => true,
                'filename' => $temp_file,
            )
        );

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);

        if (200 !== $code) {
            if ($wp_filesystem->exists($temp_file)) {
                $wp_filesystem->delete($temp_file);
            }
            return array(
                'success' => false,
                /* translators: %d: error code */
                'message' => sprintf(__('Download failed with code: %d', 'michael-cloud-image-auto-importer'), $code),
            );
        }

        if (! $wp_filesystem->exists($temp_file) || 0 === $wp_filesystem->size($temp_file)) {
            if ($wp_filesystem->exists($temp_file)) {
                $wp_filesystem->delete($temp_file);
            }
            return array(
                'success' => false,
                'message' => __('Downloaded file is empty', 'michael-cloud-image-auto-importer'),
            );
        }

        $file_size = $wp_filesystem->size($temp_file);
        $this->log_message("Downloaded {$file_name} to {$temp_file} ({$file_size} bytes)", 'success');

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