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
     * Get default POS prompt template — dành riêng cho import hóa đơn HTSoft tại POS bán hàng
     */
    public static function get_default_pos_prompt()
    {
        return <<<'PROMPT'
Đây là ảnh hóa đơn bán lẻ phần mềm HTSoft. Trích xuất thông tin thành JSON. KHÔNG giải thích, KHÔNG viết text, CHỈ trả về JSON.

Format output BẮT BUỘC:
{"items":[{"sku":"","name":"","quantity":0,"unit":"","unit_price":0,"discount_percent":0,"total_amount":0}],"customer":{"phone":"","name":""},"htsoft_total":0}

THỨ TỰ CÁC CỘT trong bảng "Chi tiết hóa đơn bán lẻ" (từ trái sang phải):
Mã hàng | Tên hàng | Kho | SL | ĐVT | Đơn giá | SL t | Thuế(%) | CK(%) | Thành tiền | Ghi chú | Số lô | Exp Dat | Line type

QUY TẮC:
1. Đọc KỸ từng dòng sản phẩm, KHÔNG bỏ sót dòng nào. Kể cả dòng có Đơn giá hoặc Thành tiền để trống vẫn phải đưa vào kết quả với unit_price=0 và total_amount=0
2. Cột "Mã hàng" (cột 1) → sku. Đọc KỸ TỪNG CHỮ SỐ một, tránh nhầm: 0↔6, 0↔8, 1↔7, 3↔8, 4↔9, 5↔6, 8↔9. Mã hàng thường có 9 chữ số
3. Cột "Tên hàng" (cột 2) → name
4. Cột "SL" (cột 4) → quantity. Đọc số lượng thực tế của TỪNG dòng (thường là 1, 2, 3...), không mặc định là 1
5. Cột "ĐVT" (cột 5) → unit (Lon, Lốc, Hộp, Cái, Chai, Bộ, ...)
6. Cột "Đơn giá" (cột 6) → unit_price (số nguyên, đơn vị VND). Định dạng số: "95.000" = 95000, "1.500.000" = 1500000. Nếu ô trống → unit_price = 0. LƯU Ý: nếu dòng đang được tô màu nền xanh dương (hover/selected), vẫn đọc giá trị Đơn giá của CHÍNH dòng đó. Kiểm tra chéo: unit_price × quantity × (1 - discount_percent/100) ≈ total_amount
7. Cột "SL t" (cột 7, ngay sau Đơn giá) → BỎ QUA hoàn toàn, không dùng cho bất kỳ field nào
8. Cột "Thuế(%)" (cột 8) → BỎ QUA hoàn toàn, không dùng
9. Cột "CK(%)" (cột 9, nằm SAU cột Thuế(%)) → discount_percent. QUAN TRỌNG: phải đọc ô CK(%) riêng biệt cho TỪNG dòng sản phẩm, không bỏ sót. Ví dụ: "10,53" → 10.53; "10.53" → 10.53; ô trống hoặc "0" → 0. Cột này có thể có giá trị thập phân lớn như 10,53. KHÔNG nhầm với cột "SL t" (cột 7 có giá trị nhỏ nguyên như 1, 2, 3, 5)
10. Cột "Thành tiền" (cột 10) → total_amount (số nguyên VND, ví dụ "85.000" = 85000). Nếu ô trống → total_amount = 0
11. Phần "Thông tin khách hàng": Mob(F7) → customer.phone, Tên KH → customer.name
12. htsoft_total: lấy từ ô tổng "Thành tiền" dưới bảng (nếu có), hoặc tổng tất cả total_amount. LƯU Ý: định dạng số tiếng Việt dùng dấu chấm "." làm phân cách ngàn (ví dụ: "249.000" = 249000, "1.500.000" = 1500000), KHÔNG phải số thập phân
13. CHỈ output JSON, bắt đầu bằng { và kết thúc bằng }, không có gì trước hoặc sau
PROMPT;
    }

    /**
     * Default prompt cho scan ảnh phiếu bán hàng in (bill/receipt)
     * Khác HTSoft: CK là số tiền (VNĐ) → tự quy sang %; mã hàng đọc từ dòng cuối ô Tên/Mã hàng
     */
    public static function get_default_invoice_scan_prompt()
    {
        return <<<'PROMPT'
Bạn là AI đọc ảnh phiếu bán hàng (bill/receipt) in ra của cửa hàng bán lẻ Việt Nam.
NHIỆM VỤ: Đọc bảng sản phẩm và trả về JSON. KHÔNG giải thích, KHÔNG viết text, CHỈ trả về JSON.

Format output BẮT BUỘC:
{"items":[{"sku":"","name":"","quantity":0,"unit_price":0,"discount_percent":0,"total_amount":0}],"customer":{"phone":"","name":""},"htsoft_total":0}

CẤU TRÚC BẢNG (cột từ trái sang phải):
Tên/Mã hàng | SL | Đơn giá | CK | Tổng tiền

LUẬT ĐỌC MÃ HÀNG (cột "Tên/Mã hàng"):
- Mỗi ô có thể xuống dòng nhiều lần (tên sản phẩm dài)
- Chỉ xét DÒNG CUỐI CÙNG của ô để xác định mã hàng:
  + Dòng cuối CÓ khoảng trắng → sku = phần SAU khoảng trắng CUỐI CÙNG
    Ví dụ: "3L 171729004" → sku = "171729004"
  + Dòng cuối KHÔNG có khoảng trắng → cả dòng là sku
    Ví dụ: "201059002" → sku = "201059002"
- "name" = toàn bộ nội dung ô Tên/Mã hàng (có thể bỏ phần mã ở dòng cuối)

LUẬT ĐỌC SỐ — dấu chấm "." và phẩy "," là phân cách NGHÌN (KHÔNG phải thập phân):
"265,000" = 265000 | "21.000" = 21000 | "2,100" = 2100 | "18.900" = 18900

CỘT SL: số lượng bán (số nguyên).

CỘT ĐƠN GIÁ: đơn giá sau thuế (VNĐ).
  **TUYỆT ĐỐI KHÔNG được copy đơn giá từ dòng trên.**
  Mỗi dòng phải đọc độc lập trực tiếp từ ô Đơn giá của dòng đó.
  Nếu ô Đơn giá của dòng đó trống, mờ, hoặc hiển thị 0 → unit_price = 0.

CỘT CK: số tiền chiết khấu (VNĐ) — KHÔNG phải %. Công thức quy đổi:
  discount_percent = round(CK / Đơn_giá × 100, 4)
  Nếu CK = 0 hoặc Đơn_giá = 0 → discount_percent = 0.
  Ví dụ: Đơn giá = 21000, CK = 2100 → discount_percent = 10.0

CỘT TỔNG TIỀN: chỉ để kiểm tra chéo, không bắt buộc.

QUAN TRỌNG:
- Đọc TẤT CẢ dòng sản phẩm kể cả dòng có Đơn giá = 0, KHÔNG được bỏ sót dòng nào
- "htsoft_total" = lấy giá trị ở dòng "Tổng tiền thanh toán:" cuối phiếu
- "customer.name" = lấy từ dòng "KH:" nếu có (bỏ prefix "KH:" và khoảng trắng đầu)
- CHỈ output JSON, bắt đầu bằng { và kết thúc bằng }, không có gì trước hoặc sau
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
