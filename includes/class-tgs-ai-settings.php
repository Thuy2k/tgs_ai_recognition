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
        'enabled'               => false,
        'provider'              => 'openrouter',      // openrouter | groq | gemini | openai | custom
        'api_key'               => '',
        'api_keys'              => '',              // Nhiều API key, mỗi dòng 1 key
        'model'                 => 'nvidia/nemotron-nano-12b-v2-vl:free', // model AI dùng, tuỳ provider
        'max_file_size'         => 10,              // MB
        'accepted_formats'      => 'image/*,.xlsx,.xls,.csv,.pdf',
        'prompt_template'       => '',              // Custom prompt phiếu mua (nếu rỗng → dùng default)
        'pos_prompt_template'         => '',          // Custom prompt POS HTSoft (nếu rỗng → dùng default pos)
        'invoice_scan_prompt_template' => '',          // Custom prompt Scan phiếu bán hàng in (bill/receipt)
        'auto_fill'             => true,            // Tự fill sau khi AI xử lý xong hay chờ xác nhận
        'camera_enabled'        => true,            // Cho phép mở camera trên mobile
        'debug_mode'            => false,
        'custom_endpoint'       => '',              // URL endpoint cho custom provider
    ];

    /**
     * Fields dùng sanitize_textarea thay vì sanitize_text
     */
    private static $textarea_fields = ['api_keys', 'prompt_template', 'pos_prompt_template', 'invoice_scan_prompt_template'];

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
            if (empty($settings['api_keys'])) {
                $settings['api_keys'] = $settings['api_key'];
            }
        }

        // Hỗ trợ lưu nhiều API key (mỗi dòng 1 key hoặc ngăn cách bằng dấu phẩy)
        if (isset($data['api_keys']) && trim((string) $data['api_keys']) !== '') {
            $api_keys = self::parse_api_keys($data['api_keys']);
            if (!empty($api_keys)) {
                $settings['api_keys'] = implode("\n", $api_keys);
                $settings['api_key'] = $api_keys[0]; // backward compatibility
            }
        }

        update_option(self::OPTION_KEY, $settings);
        return $settings;
    }

    /**
     * Get masked API key for display
     */
    public static function get_masked_api_key()
    {
        $keys = self::get_api_keys();
        $key = !empty($keys) ? $keys[0] : self::get('api_key');
        if (empty($key)) return '';
        if (strlen($key) <= 8) return str_repeat('•', strlen($key));
        return substr($key, 0, 4) . str_repeat('•', strlen($key) - 8) . substr($key, -4);
    }

    public static function get_api_keys($settings = null)
    {
        $settings = is_array($settings) ? $settings : self::get_all();
        $keys = self::parse_api_keys($settings['api_keys'] ?? '');

        if (empty($keys) && !empty($settings['api_key'])) {
            $keys[] = sanitize_text_field($settings['api_key']);
        }

        return array_values(array_unique(array_filter($keys)));
    }

    public static function get_masked_api_keys()
    {
        $keys = self::get_api_keys();
        if (empty($keys)) {
            return '';
        }

        $masked = array_map(function ($key) {
            $key = (string) $key;
            if (strlen($key) <= 8) {
                return str_repeat('•', strlen($key));
            }
            return substr($key, 0, 4) . str_repeat('•', strlen($key) - 8) . substr($key, -4);
        }, $keys);

        return implode("\n", $masked);
    }

    private static function parse_api_keys($raw)
    {
        $raw = (string) $raw;
        if (trim($raw) === '') {
            return [];
        }

        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $parts = preg_split('/[\n,]+/', $raw);
        if (!is_array($parts)) {
            return [];
        }

        $keys = [];
        foreach ($parts as $part) {
            $key = sanitize_text_field(trim((string) $part));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Get default prompt template
     */
    public static function get_default_prompt()
    {
        return <<<'PROMPT'
Extract products from image into a JSON array. NO explanations, NO text, ONLY return JSON.

Each product schema: {"sku":"","name":"","unit":"","quantity":0,"exp_date":"","lot_code":"","note":""}

Correct output example:
[{"sku":"230723Y012","name":"Áo dài tay","unit":"Chiếc","quantity":1,"exp_date":"","lot_code":"","note":""},{"sku":"171417090","name":"Áo cổ 3p","unit":"Cái","quantity":3,"exp_date":"","lot_code":"","note":""}]

RULES:
1. Read EVERY line carefully, do NOT skip any.
2. Return a single JSON array containing ALL products.
3. NO explanations, NO markdown, NO bullet points.
4. Only output JSON array, starting with [ and ending with ]
PROMPT;
    }

    /**
     * Get default POS prompt template — dành riêng cho import hóa đơn HTSoft tại POS bán hàng
     */
    public static function get_default_pos_prompt()
    {
        return <<<'PROMPT'
Extract info from the HTSoft retail software invoice image into JSON. NO explanations, NO text, ONLY return JSON.

REQUIRED output format:
{"items":[{"sku":"","name":"","quantity":0,"unit":"","unit_price":0,"discount_percent":0,"total_amount":0}],"customer":{"phone":"","name":""},"htsoft_total":0}

COLUMN ORDER in "Chi tiết hóa đơn bán lẻ" table (left to right):
Mã hàng | Tên hàng | Kho | SL | ĐVT | Đơn giá | SL t | Thuế(%) | CK(%) | Thành tiền | Ghi chú | Số lô | Exp Dat | Line type

RULES:
1. Read EVERY product line carefully, do NOT skip any. Even lines with empty Unit Price or Total Amount must be included with unit_price=0 and total_amount=0.
2. "Mã hàng" column (1st) → sku. Read EVERY SINGLE DIGIT carefully to avoid mistakes: 0↔6, 0↔8, 1↔7, 3↔8, 4↔9, 5↔6, 8↔9. SKU usually has 9 digits.
3. "Tên hàng" column (2nd) → name.
4. "SL" column (4th) → quantity. Read the actual quantity of EACH line (e.g., 1, 2, 3...), do not default to 1.
5. "ĐVT" column (5th) → unit (Lon, Lốc, Hộp, Cái, Chai, Bộ, etc.). READ EXACTLY AS WRITTEN — this is the selling unit on the invoice, which may be a large unit (Lốc, Hộp, Thùng) or a small unit (Lon, Cái). DO NOT infer or guess — read the exact text in the ĐVT cell for EACH individual line. This field is critical for unit conversion.
6. "Đơn giá" column (6th) → unit_price (integer, in VND). Format: "95.000" = 95000, "1.500.000" = 1500000. If blank → unit_price = 0. NOTE: If the line is highlighted in blue (hovered/selected), still read the Unit Price of THAT specific line. Cross-check: unit_price × quantity × (1 - discount_percent/100) ≈ total_amount.
7. "SL t" column (7th, right after Đơn giá) → IGNORE completely, do not use for any field.
8. "Thuế(%)" column (8th) → IGNORE completely, do not use.
9. "CK(%)" column (9th, AFTER Thuế(%)) → discount_percent. IMPORTANT: Read the CK(%) cell separately for EACH product line, do not skip. E.g., "10,53" → 10.53; "10.53" → 10.53; blank or "0" → 0. This column may have large decimal values like 10.53. DO NOT confuse it with "SL t" column (7th, which has small integers like 1, 2, 3, 5).
10. "Thành tiền" column (10th) → total_amount (integer in VND, e.g., "85.000" = 85000). If blank → total_amount = 0.
11. "Thông tin khách hàng" section: Mob(F7) → customer.phone, Tên KH → customer.name.
12. htsoft_total: Take from the total "Thành tiền" field below the table, or sum all total_amount. NOTE: Vietnamese numbers use dot "." as thousands separator (e.g., "249.000" = 249000, "1.500.000" = 1500000), NOT as a decimal point.
13. ONLY output JSON, starting with { and ending with }, with nothing before or after.
PROMPT;
    }

    /**
     * Default prompt cho scan ảnh phiếu bán hàng in (bill/receipt)
     * Khác HTSoft: CK là số tiền (VNĐ) → tự quy sang %; mã hàng đọc từ dòng cuối ô Tên/Mã hàng
     */
    public static function get_default_invoice_scan_prompt()
    {
        return <<<'PROMPT'
You are an AI specialized in extracting data from printed receipt/bill images from Vietnamese retail stores. You will be provided with a sequence of multiple images (part 1, part 2, part 3, etc.) representing a single, long continuous receipt that has been split or photographed in segments.

TASK:
Focus ONLY on parsing the product table in the middle of the receipt across ALL provided images, merge them into a single deduplicated dataset, and return JSON.
NO explanations, NO text, ONLY return JSON.

REQUIRED output format:
{"items":[{"sku":"","name":"","quantity":0,"unit_price":0,"discount_percent":0,"total_amount":0}],"customer":{"phone":"","name":""},"htsoft_total":0}

MULTI-IMAGE MERGING & DEDUPLICATION RULES:
1. The images are sequential segments of ONE single continuous receipt. Overlapping product lines may appear at the bottom of an image and the top of the subsequent image.
2. You MUST use the extracted "sku" as the unique identifier for each product line to de-duplicate the data.
3. If a product line (same SKU) appears in multiple images:
   - Do NOT duplicate it in the "items" array.
   - Cross-check the "quantity" and "total_amount" across those images to ensure you only record that item ONCE with its correct, single-line values. Do NOT sum them up if they are just duplicates of the exact same transaction line on the bill.
4. Maintain the original chronological order of the products as they flow from the first image to the last image.

DATA EXTRACTION SCOPE:
Only read the product table consisting of 5 columns in this exact order:
Tên/Mã hàng (Product Name/Code) | SL (Qty) | Đơn giá (Unit Price) | CK (Discount) | Tổng tiền (Total)

The table may be divided into 2 parts:
- Top part: Product list → ONLY extract this part from all images.
- Bottom part: Totals, payment details → IGNORE completely when parsing "items", but use the final image's payment section for "htsoft_total".
Do not read any other regions outside the table (header, footer, notes, payment area, etc.).

DETAILED EXTRACTION RULES:
1. Extract EVERY product line across all segments, do not skip any unique line. Even lines with empty unit price or total amount must be included with unit_price=0 and total_amount=0.
2. "Tên/Mã hàng" column: For products with long names, the text might wrap across multiple lines. The SKU is the final continuous string of 9-12 characters at the very end of this cell, not separated by spaces (the last token). Just extract the last token after the final whitespace. If the last line contains no whitespace, take the entire line as the SKU. Name = The cell content after removing the SKU.
3. "SL" column → quantity: Integer, read accurately for each line, do not default to any value.
4. "Đơn giá" column → unit_price: Read directly from that specific line; DO NOT copy from other lines. Normalize numbers: "26.000" → 26000, "22,000" → 22000. If blank, blurry, or 0 → unit_price = 0.
5. "CK" column → discount_percent: "CK" represents the discount amount in VND, NOT a percentage. Formula: discount_percent = round(CK / unit_price * 100, 4). If CK = 0 or unit_price = 0 → discount_percent = 0.
6. "Tổng tiền" column → total_amount: Normalize the same way as unit_price. If blank → 0. Use this for cross-checking.

NUMBER READING RULES:
Both "." and "," are used as thousands separators.
E.g., "265,000" = 265000, "21.000" = 21000, "2,100" = 2100.

customer.name:
Extract from the line starting with "KH:" (usually found in the first image). Strip the "KH:" prefix and any leading/trailing whitespace.

customer.phone:
If not present → "".

htsoft_total:
Extract from the "Tổng tiền thanh toán" line at the very end of the final receipt segment (usually the last image). Do NOT use "Tổng tiền hàng".

FINAL REQUIREMENT:
ONLY return valid JSON. Do not add any text outside the JSON. Start with { and end with }.
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
                'models' => ['gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-2.0-flash-lite', 'gemini-1.5-flash', 'gemini-1.5-pro'],
                'supports' => ['image', 'excel', 'pdf'],
            ],
            'openai' => [
                'label' => 'OpenAI (GPT-4o Vision)',
                'description' => 'Sử dụng OpenAI API (trả phí). Model hỗ trợ vision để đọc ảnh/file.',
                'models' => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo'],
                'supports' => ['image', 'excel', 'pdf'],
                'fetchable' => true,
            ],
            'chatgpt' => [
                'label' => 'ChatGPT (OpenAI Responses API)',
                'description' => 'Dùng endpoint Responses API mới của OpenAI. Hỗ trợ text + vision, trả về output_text ổn định hơn.',
                'models' => ['gpt-4.1', 'gpt-4.1-mini', 'gpt-4o', 'gpt-4o-mini'],
                'supports' => ['image', 'excel', 'pdf'],
                'fetchable' => true,
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
