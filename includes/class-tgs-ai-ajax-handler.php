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
        if (empty($_FILES['file']) && empty($_FILES['files'])) {
            wp_send_json_error(['message' => 'Chưa chọn file.']);
        }

        $uploaded_files = self::normalize_uploaded_files($_FILES['files'] ?? null);
        if (empty($uploaded_files) && !empty($_FILES['file'])) {
            $uploaded_files = self::normalize_uploaded_files($_FILES['file']);
        }
        if (empty($uploaded_files)) {
            wp_send_json_error(['message' => 'Chưa chọn file hợp lệ.']);
        }

        $max_size = TGS_AI_Settings::get('max_file_size', 10) * 1024 * 1024; // MB → bytes

        $allowed_types = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
            'application/pdf',
        ];

        $validated_files = [];
        foreach ($uploaded_files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                wp_send_json_error(['message' => 'Lỗi upload file: ' . self::get_upload_error_message((int) $file['error'])]);
            }

            if ((int) ($file['size'] ?? 0) > $max_size) {
                wp_send_json_error(['message' => 'File quá lớn. Tối đa ' . TGS_AI_Settings::get('max_file_size', 10) . 'MB.']);
            }

            $detected_type = self::detect_mime_type((string) $file['tmp_name'], (string) $file['name'], (string) ($file['type'] ?? ''));
            if (!in_array($detected_type, $allowed_types, true)) {
                wp_send_json_error(['message' => 'Loại file không được hỗ trợ (' . esc_html($detected_type) . '). Hỗ trợ: ảnh, Excel, CSV, PDF.']);
            }

            $validated_files[] = [
                'tmp_name' => (string) $file['tmp_name'],
                'mime_type' => (string) $detected_type,
                'name' => sanitize_file_name((string) $file['name']),
            ];
        }

        if (empty($validated_files)) {
            wp_send_json_error(['message' => 'Không có file hợp lệ để xử lý.']);
        }

        $primary_file = $validated_files[0];
        $additional_images = [];
        if (count($validated_files) > 1 && strpos($primary_file['mime_type'], 'image/') === 0) {
            foreach (array_slice($validated_files, 1) as $extra_file) {
                if (strpos($extra_file['mime_type'], 'image/') === 0) {
                    $additional_images[] = $extra_file;
                }
            }
        }

        // Process via AI
        $result = TGS_AI_Processor::process(
            $primary_file['tmp_name'],
            $primary_file['mime_type'],
            $primary_file['name'],
            null,
            ['additional_images' => $additional_images]
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
        $fields = ['enabled', 'provider', 'api_key', 'api_keys', 'model', 'max_file_size',
                    'accepted_formats', 'prompt_template', 'pos_prompt_template', 'invoice_scan_prompt_template', 'auto_fill',
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
        $api_keys = TGS_AI_Settings::get_api_keys($settings);

        if (empty($api_keys)) {
            wp_send_json_error(['message' => 'Chưa cấu hình API key.']);
        }

        $response = null;
        $last_error = '';
        foreach ($api_keys as $api_key) {
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
                case 'chatgpt':
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
                $last_error = 'Lỗi kết nối: ' . $response->get_error_message();
                continue;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code === 200) {
                break;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $last_error = $body['error']['message'] ?? "HTTP {$status_code}";
            $response = null;
        }

        if (!$response) {
            wp_send_json_error(['message' => 'Không thể tải model từ tất cả API key: ' . $last_error]);
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
     * Process uploaded file for POS HTSoft import — uses pos_prompt_template
     * Accessible to logged-in users (not just manage_options)
     */
    public static function process_pos_file()
    {
        // Bump memory limit for image processing — shared hosting often caps at 128M
        @ini_set('memory_limit', '256M');

        // POS uses tgs_pos_nonce (tgs_nonce), check it
        check_ajax_referer('tgs_pos_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Chưa đăng nhập.']);
        }

        if (!TGS_AI_Settings::get('enabled')) {
            wp_send_json_error(['message' => 'Tính năng AI chưa được bật. Vào Cài đặt AI để kích hoạt.']);
        }

        if (empty($_FILES['file']) && empty($_FILES['files'])) {
            wp_send_json_error(['message' => 'Chưa chọn file.']);
        }

        $uploaded_files = self::normalize_uploaded_files($_FILES['files'] ?? null);
        if (empty($uploaded_files) && !empty($_FILES['file'])) {
            $uploaded_files = self::normalize_uploaded_files($_FILES['file']);
        }
        if (empty($uploaded_files)) {
            wp_send_json_error(['message' => 'Chưa chọn file hợp lệ.']);
        }

        $max_size = TGS_AI_Settings::get('max_file_size', 10) * 1024 * 1024;

        $allowed_types = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel', 'text/csv', 'application/pdf',
        ];

        $validated_files = [];
        foreach ($uploaded_files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                wp_send_json_error(['message' => 'Lỗi upload file: ' . self::get_upload_error_message((int) $file['error'])]);
            }

            if ((int) ($file['size'] ?? 0) > $max_size) {
                wp_send_json_error(['message' => 'File quá lớn. Tối đa ' . TGS_AI_Settings::get('max_file_size', 10) . 'MB.']);
            }

            $detected_type = self::detect_mime_type((string) $file['tmp_name'], (string) $file['name'], (string) ($file['type'] ?? ''));
            if (!in_array($detected_type, $allowed_types, true)) {
                wp_send_json_error(['message' => 'Loại file không được hỗ trợ (' . esc_html($detected_type) . ').']);
            }

            $validated_files[] = [
                'tmp_name' => (string) $file['tmp_name'],
                'mime_type' => (string) $detected_type,
                'name' => sanitize_file_name((string) $file['name']),
            ];
        }

        if (empty($validated_files)) {
            wp_send_json_error(['message' => 'Không có file hợp lệ để xử lý.']);
        }

        // Lấy prompt theo scan_mode: 'htsoft' (mặc định) hoặc 'invoice' (phiếu bán hàng in)
        $scan_mode = sanitize_text_field($_POST['scan_mode'] ?? 'htsoft');
        if ($scan_mode === 'invoice') {
            $pos_prompt = TGS_AI_Settings::get('invoice_scan_prompt_template');
            if (empty(trim($pos_prompt))) {
                $pos_prompt = TGS_AI_Settings::get_default_invoice_scan_prompt();
            }
        } else {
            $pos_prompt = TGS_AI_Settings::get('pos_prompt_template');
            if (empty(trim($pos_prompt))) {
                $pos_prompt = TGS_AI_Settings::get_default_pos_prompt();
            }
        }

        $image_quality = sanitize_text_field($_POST['image_quality'] ?? 'normal');

        $image_files = array_values(array_filter($validated_files, static function ($file) {
            return strpos((string) ($file['mime_type'] ?? ''), 'image/') === 0;
        }));

        $result = null;
        $batch_errors = [];

        if (count($image_files) > 1) {
            $aggregate = [
                'success' => true,
                'products' => [],
                'total' => 0,
                'raw_data' => [
                    'items' => [],
                    'customer' => [
                        'phone' => '',
                        'name' => '',
                    ],
                    'htsoft_total' => 0,
                ],
            ];

            foreach ($image_files as $batch_index => $batch_file) {
                $batch_result = TGS_AI_Processor::process(
                    $batch_file['tmp_name'],
                    $batch_file['mime_type'],
                    $batch_file['name'],
                    $pos_prompt,  // override prompt
                    ['image_quality' => $image_quality]
                );

                if (empty($batch_result['success'])) {
                    $batch_errors[] = 'Ảnh #' . ($batch_index + 1) . ': ' . ($batch_result['error'] ?? 'Lỗi không xác định');
                    continue;
                }

                $aggregate = self::merge_pos_htsoft_result($aggregate, $batch_result);
            }

            if (empty($aggregate['raw_data']['items'])) {
                $error_message = 'AI không tìm thấy sản phẩm nào trong ảnh.';
                if (!empty($batch_errors)) {
                    $error_message .= ' Chi tiết: ' . implode(' | ', $batch_errors);
                }
                wp_send_json_error(['message' => $error_message]);
            }

            if (!empty($batch_errors)) {
                $aggregate['note'] = implode(' | ', $batch_errors);
            }

            $result = $aggregate;
        } else {
            $primary_file = $validated_files[0];
            $result = TGS_AI_Processor::process(
                $primary_file['tmp_name'],
                $primary_file['mime_type'],
                $primary_file['name'],
                $pos_prompt,  // override prompt
                ['image_quality' => $image_quality]
            );
        }

        if ($result['success']) {
            $response_data = [
                'products' => $result['products'],
                'total'    => $result['total'],
                'raw_data' => $result['raw_data'] ?? null, // raw JSON object cho POS parser
                'message'  => "AI nhận diện được {$result['total']} dòng sản phẩm.",
            ];
            if (($result['total'] === 0 || TGS_AI_Settings::get('debug_mode')) && !empty($result['raw_response'])) {
                $response_data['raw_response'] = mb_substr($result['raw_response'], 0, 2000);
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
     * Normalize $_FILES payload into a flat list.
     * Supports both single file and files[] format.
     */
    private static function normalize_uploaded_files($files_payload)
    {
        if (empty($files_payload) || !is_array($files_payload)) {
            return [];
        }

        $result = [];
        if (is_array($files_payload['name'] ?? null)) {
            $count = count($files_payload['name']);
            for ($i = 0; $i < $count; $i++) {
                $name = $files_payload['name'][$i] ?? '';
                $tmp_name = $files_payload['tmp_name'][$i] ?? '';
                if ($name === '' || $tmp_name === '') {
                    continue;
                }
                $result[] = [
                    'name' => (string) $name,
                    'type' => (string) ($files_payload['type'][$i] ?? ''),
                    'tmp_name' => (string) $tmp_name,
                    'error' => (int) ($files_payload['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int) ($files_payload['size'][$i] ?? 0),
                ];
            }
            return $result;
        }

        if (($files_payload['name'] ?? '') !== '' && ($files_payload['tmp_name'] ?? '') !== '') {
            $result[] = [
                'name' => (string) $files_payload['name'],
                'type' => (string) ($files_payload['type'] ?? ''),
                'tmp_name' => (string) $files_payload['tmp_name'],
                'error' => (int) ($files_payload['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($files_payload['size'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Detect MIME type for uploaded file.
     */
    private static function detect_mime_type($tmp_name, $original_name, $fallback_type = '')
    {
        if ($tmp_name !== '' && class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($tmp_name);
            if (!empty($detected)) {
                return $detected;
            }
        }

        if ($tmp_name !== '' && function_exists('mime_content_type')) {
            $detected = mime_content_type($tmp_name);
            if (!empty($detected)) {
                return $detected;
            }
        }

        $ext_data = wp_check_filetype(basename($original_name));
        if (!empty($ext_data['type'])) {
            return $ext_data['type'];
        }

        return $fallback_type;
    }

    /**
     * Merge one batch result into the aggregate HTSoft response.
     */
    private static function merge_pos_htsoft_result(array $aggregate, array $batch_result)
    {
        $aggregate_items = $aggregate['raw_data']['items'] ?? [];
        $batch_items = [];

        if (!empty($batch_result['raw_data']['items']) && is_array($batch_result['raw_data']['items'])) {
            $batch_items = $batch_result['raw_data']['items'];
        } elseif (!empty($batch_result['products']) && is_array($batch_result['products'])) {
            $batch_items = $batch_result['products'];
        }

        $merged_items = self::merge_pos_htsoft_items($aggregate_items, $batch_items);

        $aggregate['raw_data']['items'] = $merged_items;
        $aggregate['products'] = $merged_items;
        $aggregate['total'] = count($merged_items);

        if (!empty($batch_result['raw_data']['customer']) && is_array($batch_result['raw_data']['customer'])) {
            $current_customer = $aggregate['raw_data']['customer'] ?? ['phone' => '', 'name' => ''];
            $batch_customer = $batch_result['raw_data']['customer'];
            if (empty(trim((string) ($current_customer['phone'] ?? ''))) && !empty(trim((string) ($batch_customer['phone'] ?? '')))) {
                $aggregate['raw_data']['customer'] = [
                    'phone' => trim((string) ($batch_customer['phone'] ?? '')),
                    'name'  => trim((string) ($batch_customer['name'] ?? '')),
                ];
            } elseif (empty(trim((string) ($current_customer['name'] ?? ''))) && !empty(trim((string) ($batch_customer['name'] ?? '')))) {
                $aggregate['raw_data']['customer'] = [
                    'phone' => trim((string) ($current_customer['phone'] ?? '')),
                    'name'  => trim((string) ($batch_customer['name'] ?? '')),
                ];
            }
        }

        $batch_total = floatval($batch_result['raw_data']['htsoft_total'] ?? 0);
        if ($batch_total > floatval($aggregate['raw_data']['htsoft_total'] ?? 0)) {
            $aggregate['raw_data']['htsoft_total'] = $batch_total;
        }

        if (!empty($batch_result['note'])) {
            $existing_note = isset($aggregate['note']) ? trim((string) $aggregate['note']) : '';
            $batch_note = trim((string) $batch_result['note']);
            $aggregate['note'] = $existing_note !== '' ? ($existing_note . ' | ' . $batch_note) : $batch_note;
        }

        if (!empty($batch_result['raw_response'])) {
            $existing_raw = isset($aggregate['raw_response']) ? trim((string) $aggregate['raw_response']) : '';
            $batch_raw = trim((string) $batch_result['raw_response']);
            $aggregate['raw_response'] = $existing_raw !== '' ? ($existing_raw . "\n\n---\n\n" . $batch_raw) : $batch_raw;
        }

        return $aggregate;
    }

    /**
     * Merge HTSoft items by stable line key to avoid duplicates from overlapping pages.
     */
    private static function merge_pos_htsoft_items(array $existing_items, array $new_items)
    {
        $merged = [];
        $seen = [];

        foreach (array_merge($existing_items, $new_items) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = self::build_pos_htsoft_item_key($item);
            if ($key === '') {
                $key = 'idx-' . count($merged);
            }

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $item;
        }

        return $merged;
    }

    /**
     * Build a stable key for HTSoft items so overlap pages do not duplicate rows.
     */
    private static function build_pos_htsoft_item_key(array $item)
    {
        $sku = trim((string) ($item['sku'] ?? ''));
        $name = trim((string) ($item['name'] ?? ''));
        $unit = trim((string) ($item['unit'] ?? ''));
        $qty = number_format((float) ($item['quantity'] ?? 0), 3, '.', '');
        $unit_price = number_format((float) ($item['unit_price'] ?? 0), 2, '.', '');
        $discount = number_format((float) ($item['discount_percent'] ?? 0), 2, '.', '');
        $total = number_format((float) ($item['total_amount'] ?? 0), 2, '.', '');

        $seed = trim($sku . '|' . $name . '|' . $unit . '|' . $qty . '|' . $unit_price . '|' . $discount . '|' . $total);
        return $seed !== '' ? md5(mb_strtolower($seed)) : '';
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
