<?php

/**
 * TGS AI Settings - Quản lý cấu hình AI
 *
 * @package tgs_ai_recognition
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_AI_Settings
{
    const OPTION_KEY = 'tgs_ai_recognition_settings';

    /**
     * Default settings
     */
    private static $defaults = [
        'enabled'           => false,
        'provider'          => 'groq',           // groq | gemini | openai | custom
        'api_key'           => '',
        'model'             => 'llama-3.2-11b-vision-preview', // model AI dùng, tuỳ provider
        'max_file_size'     => 10,              // MB
        'accepted_formats'  => 'image/*,.xlsx,.xls,.csv,.pdf',
        'prompt_template'   => '',              // Custom prompt (nếu rỗng → dùng default)
        'auto_fill'         => true,            // Tự fill sau khi AI xử lý xong hay chờ xác nhận
        'camera_enabled'    => true,            // Cho phép mở camera trên mobile
        'debug_mode'        => false,
        'custom_endpoint'   => '',              // URL endpoint cho custom provider
    ];

    /**
     * Fields dùng sanitize_textarea thay vì sanitize_text
     */
    private static $textarea_fields = ['prompt_template'];

    /**
     * Get all settings (merged with defaults)
     */
    public static function get_all()
    {
        $saved = get_option(self::OPTION_KEY, []);
        return wp_parse_args($saved, self::$defaults);
    }

    /**
     * Get single setting
     */
    public static function get($key, $default = null)
    {
        $settings = self::get_all();
        return $settings[$key] ?? ($default ?? (self::$defaults[$key] ?? null));
    }

    /**
     * Save settings
     */
    public static function save($data)
    {
        $settings = self::get_all();

        // Whitelist fields
        $allowed = array_keys(self::$defaults);

        foreach ($allowed as $key) {
            if (isset($data[$key])) {
                if (is_bool(self::$defaults[$key])) {
                    $settings[$key] = filter_var($data[$key], FILTER_VALIDATE_BOOLEAN);
                } elseif (is_int(self::$defaults[$key])) {
                    $settings[$key] = intval($data[$key]);
                } else {
                    $settings[$key] = in_array($key, self::$textarea_fields)
                        ? sanitize_textarea_field($data[$key])
                        : sanitize_text_field($data[$key]);
                }
            }
        }

        // API key encrypt hint (chỉ lưu plaintext ở dev, production nên dùng wp_options encrypted)
        if (!empty($data['api_key'])) {
            $settings['api_key'] = sanitize_text_field($data['api_key']);
        }

        update_option(self::OPTION_KEY, $settings);
        return $settings;
    }

    /**
     * Get masked API key for display
     */
    public static function get_masked_api_key()
    {
        $key = self::get('api_key');
        if (empty($key)) return '';
        if (strlen($key) <= 8) return str_repeat('•', strlen($key));
        return substr($key, 0, 4) . str_repeat('•', strlen($key) - 8) . substr($key, -4);
    }

    /**
     * Get default prompt template
     */
    public static function get_default_prompt()
    {
        return <<<'PROMPT'
Bạn là AI trích xuất dữ liệu sản phẩm từ ảnh/file. Phân tích nội dung và trả về JSON array.

Mỗi sản phẩm cần có:
- sku: Mã hàng/SKU (bắt buộc)
- name: Tên sản phẩm (nếu có)
- unit: Đơn vị tính (nếu có)
- quantity: Số lượng (số, mặc định 0)
- exp_date: Hạn sử dụng (format YYYY-MM-DD, nếu có)
- lot_code: Mã lô (nếu có)
- note: Ghi chú (nếu có)

Trả về ĐÚNG format JSON:
[{"sku":"ABC123","name":"Sản phẩm A","unit":"Cái","quantity":10,"exp_date":"2026-12-31","lot_code":"LOT001","note":""}]

Nếu không nhận diện được bất kỳ sản phẩm nào, trả về: []
Chỉ trả về JSON, không giải thích thêm.
PROMPT;
    }

    /**
     * Provider configs
     */
    public static function get_providers()
    {
        return [
            'groq' => [
                'label' => 'Groq (Miễn phí - Khuyên dùng)',
                'description' => 'Groq API miễn phí, tốc độ cực nhanh. Lấy key tại console.groq.com/keys',
                'models' => ['llama-3.2-11b-vision-preview', 'llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'gemma2-9b-it', 'mixtral-8x7b-32768'],
                'vision_models' => ['llama-3.2-11b-vision-preview'],
                'supports' => ['image', 'excel', 'pdf'],
            ],
            'gemini' => [
                'label' => 'Google Gemini (Miễn phí)',
                'description' => 'Sử dụng Google Gemini API miễn phí. Hỗ trợ vision đọc ảnh. Lấy key tại aistudio.google.com/apikey',
                'models' => ['gemini-2.0-flash', 'gemini-2.0-flash-lite', 'gemini-1.5-flash', 'gemini-1.5-pro'],
                'supports' => ['image', 'excel', 'pdf'],
            ],
            'openai' => [
                'label' => 'OpenAI (GPT-4o Vision)',
                'description' => 'Sử dụng OpenAI API (trả phí). Model hỗ trợ vision để đọc ảnh/file.',
                'models' => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo'],
                'supports' => ['image', 'excel', 'pdf'],
            ],
            'custom' => [
                'label' => 'Custom API Endpoint',
                'description' => 'Gọi API tự host (self-hosted). Cấu hình endpoint riêng.',
                'models' => [],
                'supports' => ['image', 'excel', 'pdf'],
            ],
        ];
    }
}
