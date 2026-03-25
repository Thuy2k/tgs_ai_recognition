<?php

/**
 * TGS AI Processor - Xử lý file qua AI provider
 *
 * Nhận file (ảnh/Excel/PDF) → gửi đến AI provider → trả kết quả structured JSON
 *
 * @package tgs_ai_recognition
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_AI_Processor
{
    /**
     * Process uploaded file qua AI provider
     *
     * @param string $file_path Đường dẫn file tạm
     * @param string $file_type MIME type
     * @param string $original_name Tên file gốc
     * @return array ['success' => bool, 'products' => [...], 'raw_response' => '...', 'error' => '...']
     */
    public static function process($file_path, $file_type, $original_name = '')
    {
        $settings = TGS_AI_Settings::get_all();

        if (empty($settings['api_key'])) {
            return ['success' => false, 'error' => 'Chưa cấu hình API key. Vào Cài đặt AI để thiết lập.'];
        }

        $provider = $settings['provider'] ?? 'gemini';

        switch ($provider) {
            case 'gemini':
                return self::process_gemini($file_path, $file_type, $original_name, $settings);
            case 'openai':
                return self::process_openai($file_path, $file_type, $original_name, $settings);
            case 'custom':
                return self::process_custom($file_path, $file_type, $original_name, $settings);
            default:
                return ['success' => false, 'error' => 'Provider không hợp lệ: ' . $provider];
        }
    }

    /**
     * Process via OpenAI Vision API
     */
    private static function process_openai($file_path, $file_type, $original_name, $settings)
    {
        $api_key = $settings['api_key'];
        $model = $settings['model'] ?: 'gpt-4o';
        $prompt = $settings['prompt_template'] ?: TGS_AI_Settings::get_default_prompt();

        // Determine content type
        $is_image = strpos($file_type, 'image/') === 0;
        $is_excel = in_array($file_type, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
        ]);

        $messages = [
            ['role' => 'system', 'content' => $prompt],
        ];

        if ($is_image) {
            // Encode image to base64
            $image_data = base64_encode(file_get_contents($file_path));
            $messages[] = [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Phân tích ảnh này và trích xuất danh sách sản phẩm. File: ' . $original_name,
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => 'data:' . $file_type . ';base64,' . $image_data,
                            'detail' => 'high',
                        ],
                    ],
                ],
            ];
        } elseif ($is_excel) {
            // Excel: đọc nội dung bằng text rồi gửi cho AI
            $csv_content = self::excel_to_csv($file_path, $file_type);
            if ($csv_content === false) {
                return ['success' => false, 'error' => 'Không thể đọc file Excel. Hãy thử dùng chức năng "Nhập từ Excel" thay thế.'];
            }
            $messages[] = [
                'role' => 'user',
                'content' => "Trích xuất sản phẩm từ dữ liệu bảng sau (file: {$original_name}):\n\n{$csv_content}",
            ];
        } else {
            // PDF hoặc file khác: đọc text nếu có thể
            $text_content = self::extract_text($file_path, $file_type);
            if (empty($text_content)) {
                // Nếu không extract text được, thử gửi như image (PDF page 1)
                return ['success' => false, 'error' => 'Không thể đọc nội dung file. Hỗ trợ: ảnh (PNG/JPG), Excel, CSV.'];
            }
            $messages[] = [
                'role' => 'user',
                'content' => "Trích xuất sản phẩm từ nội dung sau (file: {$original_name}):\n\n{$text_content}",
            ];
        }

        // Call OpenAI API
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'messages'    => $messages,
                'max_tokens'  => 4096,
                'temperature' => 0.1,
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => 'Lỗi kết nối: ' . $response->get_error_message()];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code !== 200) {
            $error_msg = $data['error']['message'] ?? "HTTP {$status_code}";
            return ['success' => false, 'error' => 'OpenAI API lỗi: ' . $error_msg, 'raw_response' => $body];
        }

        // Parse AI response
        $ai_text = $data['choices'][0]['message']['content'] ?? '';
        return self::parse_ai_response($ai_text);
    }

    /**
     * Process via Google Gemini API (miễn phí)
     * Endpoint: generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
     */
    private static function process_gemini($file_path, $file_type, $original_name, $settings)
    {
        $api_key = $settings['api_key'];
        $model = $settings['model'] ?: 'gemini-2.0-flash';
        $prompt = $settings['prompt_template'] ?: TGS_AI_Settings::get_default_prompt();

        $is_image = strpos($file_type, 'image/') === 0;
        $is_excel = in_array($file_type, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
        ]);

        // Build parts array for Gemini
        $parts = [];

        // System instruction as first text part
        $parts[] = ['text' => $prompt];

        if ($is_image) {
            $image_data = base64_encode(file_get_contents($file_path));
            $parts[] = ['text' => 'Phân tích ảnh này và trích xuất danh sách sản phẩm. File: ' . $original_name];
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $file_type,
                    'data'      => $image_data,
                ],
            ];
        } elseif ($is_excel) {
            $csv_content = self::excel_to_csv($file_path, $file_type);
            if ($csv_content === false) {
                return ['success' => false, 'error' => 'Không thể đọc file Excel. Hãy thử dùng chức năng "Nhập từ Excel" thay thế.'];
            }
            $parts[] = ['text' => "Trích xuất sản phẩm từ dữ liệu bảng sau (file: {$original_name}):\n\n{$csv_content}"];
        } else {
            // PDF: gửi dưới dạng inline_data nếu là PDF, hoặc extract text
            if ($file_type === 'application/pdf') {
                $pdf_data = base64_encode(file_get_contents($file_path));
                $parts[] = ['text' => 'Trích xuất danh sách sản phẩm từ file PDF này. File: ' . $original_name];
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => 'application/pdf',
                        'data'      => $pdf_data,
                    ],
                ];
            } else {
                $text_content = self::extract_text($file_path, $file_type);
                if (empty($text_content)) {
                    return ['success' => false, 'error' => 'Không thể đọc nội dung file. Hỗ trợ: ảnh (PNG/JPG), Excel, CSV, PDF.'];
                }
                $parts[] = ['text' => "Trích xuất sản phẩm từ nội dung sau (file: {$original_name}):\n\n{$text_content}"];
            }
        }

        // Build request body
        $request_body = [
            'contents' => [
                [
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'temperature'     => 0.1,
                'maxOutputTokens' => 4096,
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . $api_key;

        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($request_body),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => 'Lỗi kết nối Gemini: ' . $response->get_error_message()];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code !== 200) {
            $error_msg = $data['error']['message'] ?? "HTTP {$status_code}";
            return ['success' => false, 'error' => 'Gemini API lỗi: ' . $error_msg, 'raw_response' => $body];
        }

        // Extract text from Gemini response
        $ai_text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return self::parse_ai_response($ai_text);
    }

    /**
     * Process via Custom API endpoint
     */
    private static function process_custom($file_path, $file_type, $original_name, $settings)
    {
        $endpoint = $settings['custom_endpoint'] ?? '';
        if (empty($endpoint)) {
            return ['success' => false, 'error' => 'Chưa cấu hình Custom API endpoint.'];
        }

        // Send file to custom endpoint
        $boundary = wp_generate_password(24, false);
        $file_content = file_get_contents($file_path);
        $file_basename = basename($original_name ?: $file_path);

        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$file_basename}\"\r\n";
        $body .= "Content-Type: {$file_type}\r\n\r\n";
        $body .= $file_content . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $response = wp_remote_post($endpoint, [
            'timeout' => 60,
            'headers' => [
                'Content-Type' => "multipart/form-data; boundary={$boundary}",
                'Authorization' => 'Bearer ' . ($settings['api_key'] ?? ''),
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => 'Lỗi kết nối custom API: ' . $response->get_error_message()];
        }

        $body_text = wp_remote_retrieve_body($response);
        return self::parse_ai_response($body_text);
    }

    /**
     * Parse AI response text → structured products array
     */
    public static function parse_ai_response($ai_text)
    {
        if (empty($ai_text)) {
            return ['success' => false, 'error' => 'AI không trả về kết quả.', 'raw_response' => ''];
        }

        // Extract JSON from response (AI có thể wrap trong ```json ... ```)
        $json_text = $ai_text;

        // Bóc cặp ```json ... ```
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $ai_text, $matches)) {
            $json_text = $matches[1];
        }

        $json_text = trim($json_text);

        // Try parse
        $products = json_decode($json_text, true);

        if (!is_array($products)) {
            return [
                'success' => false,
                'error' => 'Không thể parse kết quả AI. Vui lòng thử lại.',
                'raw_response' => $ai_text,
            ];
        }

        // Normalize products
        $normalized = [];
        foreach ($products as $item) {
            $sku = trim($item['sku'] ?? '');
            if (empty($sku)) continue;

            $normalized[] = [
                'sku'      => $sku,
                'name'     => trim($item['name'] ?? ''),
                'unit'     => trim($item['unit'] ?? ''),
                'quantity' => floatval($item['quantity'] ?? 0),
                'exp_date' => trim($item['exp_date'] ?? ''),
                'lot_code' => trim($item['lot_code'] ?? ''),
                'note'     => trim($item['note'] ?? ''),
            ];
        }

        return [
            'success'      => true,
            'products'     => $normalized,
            'total'        => count($normalized),
            'raw_response' => $ai_text,
        ];
    }

    /**
     * Convert Excel/CSV to CSV text for AI processing
     */
    private static function excel_to_csv($file_path, $file_type)
    {
        if ($file_type === 'text/csv') {
            $content = file_get_contents($file_path);
            // Limit to first 200 rows
            $lines = explode("\n", $content);
            return implode("\n", array_slice($lines, 0, 200));
        }

        // For .xlsx/.xls — use PhpSpreadsheet if available, otherwise return false
        // In practice, client-side JS (SheetJS) handles Excel → this endpoint handles images primarily
        // Excel files should use the standard "Nhập từ Excel" function instead
        return false;
    }

    /**
     * Extract text from file
     */
    private static function extract_text($file_path, $file_type)
    {
        if ($file_type === 'text/csv' || $file_type === 'text/plain') {
            return file_get_contents($file_path);
        }
        return '';
    }
}
