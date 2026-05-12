<?php
/**
 * Cloud Auto Importer - Google Drive Integration
 */

if (! defined('ABSPATH')) {
    exit;
}

class MCAI_Google_Drive {

    private $worker_url;

    public function __construct() {
        $this->worker_url = 'https://michael-plugin.auto-importer.workers.dev';
        add_action('admin_init', array($this, 'handle_oauth_callback'));
    }

    /**
     * Generate Google auth URL with nonce protection
     */
    public function get_auth_url() {
        $wp_redirect = admin_url('admin.php?page=cloud-auto-importer');

        // Create nonce for OAuth flow
        $nonce = wp_create_nonce('mcai_oauth');

        // Append nonce to redirect URL
        $redirect_with_nonce = add_query_arg(
            array(
                'mcai_nonce' => $nonce
            ),
            $wp_redirect
        );

        $response = wp_remote_get(
            $this->worker_url . '/auth-url?redirect_uri=' . urlencode($redirect_with_nonce),
            array('timeout' => 30)
        );

        if (is_wp_error($response)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return isset($data['url']) ? $data['url'] : false;
    }

    /**
     * Handle OAuth callback with nonce verification
     */
    public function handle_oauth_callback() {

        if (! isset($_GET['page']) || 'cloud-auto-importer' !== $_GET['page']) {
            return;
        }

        // ✅ Verify nonce
        if (
            ! isset($_GET['mcai_nonce']) ||
            ! wp_verify_nonce(
                sanitize_text_field(wp_unslash($_GET['mcai_nonce'])),
                'mcai_oauth'
            )
        ) {
            return;
        }

        // Handle successful connection
        if (isset($_GET['connected']) && isset($_GET['user_token'])) {

            $session_id = sanitize_text_field(wp_unslash($_GET['user_token']));

            if (! empty($session_id)) {
                update_option('mcai_session_token', $session_id);
                update_option('mcai_connected', true);
            }

            wp_safe_redirect(admin_url('admin.php?page=cloud-auto-importer&worker_connected=1'));
            exit;
        }

        // Handle error case
        if (isset($_GET['error'])) {

            $error = sanitize_text_field(wp_unslash($_GET['error']));

            update_option('mcai_connection_error', $error);

            wp_safe_redirect(admin_url('admin.php?page=cloud-auto-importer&worker_error=1'));
            exit;
        }
    }

    /**
     * Check if connected
     */
    public function is_connected() {
        $connected = get_option('mcai_connected', false);
        $session_token = get_option('mcai_session_token', '');

        return $connected && ! empty($session_token);
    }

    /**
     * Disconnect account
     */
    public function disconnect() {
        delete_option('mcai_session_token');
        delete_option('mcai_connected');
        delete_option('mcai_connection_error');

        return array('success' => true);
    }

    /**
     * List files from Google Drive
     */
    public function list_files($folder_id = 'root', $limit = 1000) {

        $session_token = get_option('mcai_session_token', '');

        if (empty($session_token)) {
            return array(
                'success' => false,
                'message' => 'Not connected',
                'files' => array()
            );
        }

        $response = wp_remote_post($this->worker_url . '/list-files', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'user_token' => $session_token,
                'folder_id'  => $folder_id
            )),
            'timeout' => 60
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
                'files'   => array()
            );
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($data['error'])) {
            if ($data['error'] === 'Session not found') {
                delete_option('mcai_connected');
                delete_option('mcai_session_token');
            }

            return array(
                'success' => false,
                'message' => $data['error'],
                'files'   => array()
            );
        }

        $files = isset($data['files']) ? $data['files'] : array();

        return array(
            'success' => true,
            'files'   => $files,
            'count'   => count($files)
        );
    }

    /**
     * Get single file info
     */
    public function get_file_info($file_id) {

        $session_token = get_option('mcai_session_token', '');

        if (empty($session_token)) {
            return array(
                'success' => false,
                'message' => 'Not connected'
            );
        }

        $response = wp_remote_post($this->worker_url . '/file-info', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'user_token' => $session_token,
                'file_id'    => $file_id
            )),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }

        $http_code = wp_remote_retrieve_response_code($response);

        if ($http_code !== 200) {
            return array(
                'success' => false,
                'message' => "HTTP $http_code"
            );
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($data['error'])) {
            if ($data['error'] === 'Session not found') {
                delete_option('mcai_connected');
                delete_option('mcai_session_token');
            }

            return array(
                'success' => false,
                'message' => $data['error']
            );
        }

        return array(
            'success' => true,
            'file' => array(
                'id'       => $data['id'],
                'name'     => $data['name'],
                'mimeType' => $data['mimeType'],
                'size'     => isset($data['size']) ? $data['size'] : 0
            )
        );
    }

    /**
     * Download file
     */
    public function download_file($file_id, $file_name) {

        $session_token = get_option('mcai_session_token', '');

        if (empty($session_token)) {
            return array(
                'success' => false,
                'message' => 'Not connected'
            );
        }

        $response = wp_remote_post($this->worker_url . '/download-file', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'user_token' => $session_token,
                'file_id'    => $file_id
            )),
            'timeout' => 300
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }

        $http_code = wp_remote_retrieve_response_code($response);

        if ($http_code !== 200) {
            return array(
                'success' => false,
                'message' => "HTTP $http_code"
            );
        }

        $image_data = wp_remote_retrieve_body($response);

        if (empty($image_data)) {
            return array(
                'success' => false,
                'message' => 'Downloaded file is empty'
            );
        }

        $upload_dir = wp_upload_dir();
        $temp_dir   = trailingslashit($upload_dir['basedir']) . 'mcai_temp';

        if (! file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        $temp_file = $temp_dir . '/' . uniqid('mcai_') . '_' . sanitize_file_name($file_name);

        file_put_contents($temp_file, $image_data);

        $image_info = @getimagesize($temp_file);

        if ($image_info === false) {
            wp_delete_file($temp_file);

            return array(
                'success' => false,
                'message' => 'Not a valid image'
            );
        }

        return array(
            'success'   => true,
            'temp_file' => $temp_file,
            'file_size' => filesize($temp_file)
        );
    }

    /**
     * Extract Google Drive file ID
     */
    public static function extract_file_id($url) {

        $patterns = array(
            '/\/file\/d\/([a-zA-Z0-9_-]+)/',
            '/\/d\/([a-zA-Z0-9_-]+)/',
            '/\/open\?id=([a-zA-Z0-9_-]+)/',
            '/id=([a-zA-Z0-9_-]+)/',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return sanitize_text_field($matches[1]);
            }
        }

        return false;
    }

    /**
     * Detect URL type
     */
    public static function detect_url_type($url) {

        if (preg_match('/\/folders\//', $url)) {
            return 'folder';
        }

        if (
            preg_match('/\/file\/d\//', $url) ||
            (preg_match('/\/d\//', $url) && ! preg_match('/\/folders\//', $url))
        ) {
            return 'file';
        }

        return 'unknown';
    }

    /**
     * Extract folder ID
     */
    public static function extract_folder_id($url) {

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
}