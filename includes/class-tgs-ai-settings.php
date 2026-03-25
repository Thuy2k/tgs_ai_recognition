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
        'provider'          => 'openrouter',      // openrouter | groq | gemini | openai | custom
        'api_key'           => '',
        'model'             => 'nvidia/nemotron-nano-12b-v2-vl:free', // model AI dùng, tuỳ provider
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
Trích xuất sản phẩm từ ảnh thành JSON array. KHÔNG giải thích, KHÔNG viết text, CHỈ trả về JSON.

Mỗi sản phẩm: {"sku":"","name":"","unit":"","quantity":0,"exp_date":"","lot_code":"","note":""}

VÍ DỤ output đúng:
[{"sku":"230723Y012","name":"Áo dài tay","unit":"Chiếc","quantity":1,"exp_date":"","lot_code":"","note":""},{"sku":"171417090","name":"Áo cổ 3p","unit":"Cái","quantity":3,"exp_date":"","lot_code":"","note":""}]

QUY TẮC:
1. Đọc KỸ từng dòng, KHÔNG bỏ sót
2. Trả về 1 JSON array duy nhất chứa TẤT CẢ sản phẩm
3. KHÔNG viết giải thích, KHÔNG dùng markdown, KHÔNG bullet point
4. Chỉ output JSON array, bắt đầu bằng [ và kết thúc bằng ]
PROMPT;
    }

    /**
     * Provider configs
     */
    public static function get_providers()
    {
        return [
            'openrouter' => [
                'label' => 'OpenRouter (Miễn phí 50 req/ngày)',
                'description' => 'OpenRouter: nhiều model vision MIỄN PHÍ đọc được ảnh. Giới hạn 50 req/ngày. Lấy key tại openrouter.ai/keys',
                'models' => ['openrouter/free', 'nvidia/nemotron-nano-12b-v2-vl:free', 'mistralai/mistral-small-3.1-24b-instruct:free', 'google/gemma-3-27b-it:free', 'google/gemma-3-12b-it:free', 'google/gemma-3-4b-it:free'],
                'supports' => ['image', 'excel', 'pdf'],
                'fetchable' => true,
            ],
            'huggingface' => [
                'label' => 'HuggingFace (Miễn phí)',
                'description' => 'HuggingFace Inference API: MIỄN PHÍ, không giới hạn region, nhiều model vision. Lấy token tại huggingface.co/settings/tokens',
                'models' => ['Qwen/Qwen2.5-VL-7B-Instruct', 'meta-llama/Llama-3.2-11B-Vision-Instruct'],
                'supports' => ['image', 'excel', 'pdf'],
            ],
            'together' => [
                'label' => 'Together AI (Cần credit)',
                'description' => 'Đăng ký bằng Google/GitHub. Lấy key tại api.together.xyz/settings/api-keys',
                'models' => ['meta-llama/Llama-Vision-Free', 'meta-llama/Llama-3.2-11B-Vision-Instruct-Turbo', 'meta-llama/Llama-3.2-90B-Vision-Instruct-Turbo'],
                'supports' => ['image', 'excel', 'pdf'],
            ],
            'nvidia' => [
                'label' => 'NVIDIA NIM (1000 credits free - Khuyên dùng ⭐)',
                'description' => 'NVIDIA cho 1000 credits FREE khi signup. Model vision rất mạnh. Đăng ký bằng Google tại build.nvidia.com → lấy API key.',
                'models' => ['nvidia/neva-22b', 'nvidia/vila', 'nvidia/cosmos-nemotron-34b', 'meta/llama-3.2-11b-vision-instruct'],
                'supports' => ['image', 'excel', 'pdf'],
            ],
            'groq' => [
                'label' => 'Groq (Miễn phí - Khuyên dùng)',
                'description' => 'Groq API miễn phí, tốc độ cực nhanh. Lấy key tại console.groq.com/keys. Bấm "Tải danh sách model" để xem model mới nhất.',
                'models' => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'gemma2-9b-it', 'mixtral-8x7b-32768'],
                'supports' => ['image', 'excel', 'pdf'],
                'fetchable' => true,
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
