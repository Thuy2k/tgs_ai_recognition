<?php

/**
 * TGS AI AJAX Handler - Xử lý AJAX requests
 *
 * @package tgs_ai_recognition
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_AI_Ajax_Handler
{
    /**
     * Process uploaded file via AI
     */
    public static function process_file()
    {
        check_ajax_referer('tgs_ai_nonce', 'nonce');

        if (!current_user_can('manage_options') && !current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Không có quyền truy cập.']);
        }

        // Check if AI is enabled
        if (!TGS_AI_Settings::get('enabled')) {
            wp_send_json_error(['message' => 'Tính năng AI chưa được bật. Vào Cài đặt AI để kích hoạt.']);
        }

        // Validate file upload
        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => 'Chưa chọn file.']);
        }

        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'Lỗi upload file: ' . self::get_upload_error_message($file['error'])]);
        }

        // Validate file size
        $max_size = TGS_AI_Settings::get('max_file_size', 10) * 1024 * 1024; // MB → bytes
        if ($file['size'] > $max_size) {
            wp_send_json_error(['message' => 'File quá lớn. Tối đa ' . TGS_AI_Settings::get('max_file_size', 10) . 'MB.']);
        }

        // Validate file type
        $allowed_types = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
            'application/pdf',
        ];

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected_type = $finfo->file($file['tmp_name']);

        if (!in_array($detected_type, $allowed_types)) {
            wp_send_json_error(['message' => 'Loại file không được hỗ trợ (' . esc_html($detected_type) . '). Hỗ trợ: ảnh, Excel, CSV, PDF.']);
        }

        // Process via AI
        $result = TGS_AI_Processor::process(
            $file['tmp_name'],
            $detected_type,
            sanitize_file_name($file['name'])
        );

        if ($result['success']) {
            $response_data = [
                'products' => $result['products'],
                'total'    => $result['total'],
                'message'  => "AI nhận diện được {$result['total']} sản phẩm.",
            ];
            // Include raw response for debugging when products empty or debug mode on
            if (($result['total'] === 0 || TGS_AI_Settings::get('debug_mode')) && !empty($result['raw_response'])) {
                $response_data['raw_response'] = mb_substr($result['raw_response'], 0, 1000);
            }
            if (!empty($result['note'])) {
                $response_data['note'] = $result['note'];
            }
            wp_send_json_success($response_data);
        } else {
            $error_data = ['message' => $result['error']];
            if (!empty($result['raw_response']) && TGS_AI_Settings::get('debug_mode')) {
                $error_data['raw_response'] = $result['raw_response'];
            }
            wp_send_json_error($error_data);
        }
    }

    /**
     * Save AI settings
     */
    public static function save_settings()
    {
        check_ajax_referer('tgs_ai_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Không có quyền thay đổi cài đặt.']);
        }

        $data = [];
        $fields = ['enabled', 'provider', 'api_key', 'model', 'max_file_size',
                    'accepted_formats', 'prompt_template', 'auto_fill',
                    'camera_enabled', 'debug_mode', 'custom_endpoint'];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = wp_unslash($_POST[$field]);
            }
        }

        $saved = TGS_AI_Settings::save($data);

        // Mask API key trước khi trả về frontend
        $safe_settings = $saved;
        $safe_settings['api_key'] = TGS_AI_Settings::get_masked_api_key();

        wp_send_json_success([
            'message'  => 'Đã lưu cấu hình AI thành công.',
            'settings' => $safe_settings,
        ]);
    }

    /**
     * Test AI connection (text-only, no file upload)
     */
    public static function test_connection()
    {
        check_ajax_referer('tgs_ai_nonce', 'nonce');

        if (!current_user_can('manage_options') && !current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Không có quyền truy cập.']);
        }

        $result = TGS_AI_Processor::test_connection();

        if ($result['success']) {
            wp_send_json_success(['message' => $result['message'] ?? 'Kết nối thành công!']);
        } else {
            $error_data = ['message' => $result['error']];
            if (!empty($result['raw_response']) && TGS_AI_Settings::get('debug_mode')) {
                $error_data['raw_response'] = $result['raw_response'];
            }
            wp_send_json_error($error_data);
        }
    }

    /**
     * Fetch available models from provider API
     */
    public static function fetch_models()
    {
        check_ajax_referer('tgs_ai_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Không có quyền truy cập.']);
        }

        $settings = TGS_AI_Settings::get_all();
        $provider = sanitize_text_field($_POST['provider'] ?? $settings['provider']);
        $api_key = $settings['api_key'];

        if (empty($api_key)) {
            wp_send_json_error(['message' => 'Chưa cấu hình API key.']);
        }

        switch ($provider) {
            case 'openrouter':
                $response = wp_remote_get('https://openrouter.ai/api/v1/models', [
                    'timeout' => 15,
                    'headers' => ['Authorization' => 'Bearer ' . $api_key],
                ]);
                break;
            case 'groq':
                $response = wp_remote_get('https://api.groq.com/openai/v1/models', [
                    'timeout' => 15,
                    'headers' => ['Authorization' => 'Bearer ' . $api_key],
                ]);
                break;
            case 'openai':
                $response = wp_remote_get('https://api.openai.com/v1/models', [
                    'timeout' => 15,
                    'headers' => ['Authorization' => 'Bearer ' . $api_key],
                ]);
                break;
            default:
                wp_send_json_error(['message' => 'Provider này không hỗ trợ tải danh sách model.']);
                return;
        }

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Lỗi kết nối: ' . $response->get_error_message()]);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error_msg = $body['error']['message'] ?? "HTTP {$status_code}";
            wp_send_json_error(['message' => 'API lỗi: ' . $error_msg]);
        }

        $models = [];
        if (!empty($body['data']) && is_array($body['data'])) {
            foreach ($body['data'] as $m) {
                $id = $m['id'] ?? '';
                if (empty($id)) continue;
                // Filter: skip whisper, tts, embedding models
                if (preg_match('/(whisper|tts|embed|distil|guard)/i', $id)) continue;
                // For OpenRouter: only show free models
                if ($provider === 'openrouter') {
                    if (strpos($id, ':free') === false) continue;
                }
                $models[] = $id;
            }
            sort($models);
        }

        if (empty($models)) {
            wp_send_json_error(['message' => 'Không tìm thấy model nào.']);
        }

        wp_send_json_success(['models' => $models]);
    }

    /**
     * Upload error messages
     */
    private static function get_upload_error_message($code)
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'File vượt quá giới hạn upload_max_filesize của server.',
            UPLOAD_ERR_FORM_SIZE  => 'File vượt quá giới hạn MAX_FILE_SIZE.',
            UPLOAD_ERR_PARTIAL    => 'File chỉ được upload một phần.',
            UPLOAD_ERR_NO_FILE    => 'Không có file nào được upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Thiếu thư mục tạm trên server.',
            UPLOAD_ERR_CANT_WRITE => 'Không thể ghi file lên ổ đĩa.',
        ];
        return $messages[$code] ?? 'Lỗi không xác định.';
    }
}
