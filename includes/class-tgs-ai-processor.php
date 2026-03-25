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
     * Test connection to AI provider (text-only, no file)
     */
    public static function test_connection()
    {
        $settings = TGS_AI_Settings::get_all();

        if (empty($settings['api_key'])) {
            return ['success' => false, 'error' => 'Chưa cấu hình API key. Vào Cài đặt AI để thiết lập.'];
        }

        $provider = $settings['provider'] ?? 'openrouter';
        $api_key = $settings['api_key'];
        $model = $settings['model'] ?: 'nvidia/nemotron-nano-12b-v2-vl:free';

        $test_prompt = 'Trả lời đúng 1 từ: "OK"';

        switch ($provider) {
            case 'openrouter':
                // Thử model đã chọn + fallback models
                $fallback_models = [
                    'openrouter/free',
                    'nvidia/nemotron-nano-12b-v2-vl:free',
                    'mistralai/mistral-small-3.1-24b-instruct:free',
                    'google/gemma-3-27b-it:free',
                    'google/gemma-3-12b-it:free',
                    'google/gemma-3-4b-it:free',
                ];
                $models_to_try = array_unique(array_merge([$model], $fallback_models));
                $last_error = '';

                foreach ($models_to_try as $try_model) {
                    $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
                        'timeout' => 30,
                        'headers' => [
                            'Authorization' => 'Bearer ' . $api_key,
                            'Content-Type'  => 'application/json',
                            'HTTP-Referer'  => home_url(),
                            'X-Title'       => 'TGS AI Recognition',
                        ],
                        'body' => wp_json_encode([
                            'model'      => $try_model,
                            'messages'   => [['role' => 'user', 'content' => $test_prompt]],
                            'max_tokens' => 10,
                        ]),
                    ]);

                    if (is_wp_error($response)) {
                        $last_error = $response->get_error_message();
                        continue;
                    }

                    $sc = wp_remote_retrieve_response_code($response);
                    $bd = json_decode(wp_remote_retrieve_body($response), true);

                    if ($sc === 200) {
                        $msg = "Kết nối OpenRouter thành công! Model: {$try_model}";
                        if ($try_model !== $model) {
                            $msg .= " (model '{$model}' không khả dụng, đã fallback)";
                        }
                        return ['success' => true, 'message' => $msg];
                    }

                    $err = $bd['error']['message'] ?? "HTTP {$sc}";
                    if (
                        strpos($err, 'No endpoints') !== false ||
                        strpos($err, 'not available') !== false ||
                        strpos($err, 'Provider returned error') !== false ||
                        strpos($err, 'rate limit') !== false ||
                        $sc === 429 || $sc === 502 || $sc === 503
                    ) {
                        $last_error = "Model {$try_model}: {$err}";
                        continue;
                    }
                    return ['success' => false, 'error' => "OpenRouter API lỗi: {$err}"];
                }

                return ['success' => false, 'error' => 'Tất cả model free đều không khả dụng. ' . $last_error];
                break;

            case 'groq':
                $response = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', [
                    'timeout' => 15,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $api_key,
                        'Content-Type'  => 'application/json',
                    ],
                    'body' => wp_json_encode([
                        'model'      => $model,
                        'messages'   => [['role' => 'user', 'content' => $test_prompt]],
                        'max_tokens' => 10,
                    ]),
                ]);
                $error_prefix = 'Groq';
                break;

            case 'gemini':
                $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . $api_key;
                $response = wp_remote_post($url, [
                    'timeout' => 15,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => wp_json_encode([
                        'contents' => [['parts' => [['text' => $test_prompt]]]],
                        'generationConfig' => ['maxOutputTokens' => 10],
                    ]),
                ]);
                $error_prefix = 'Gemini';
                break;

            case 'openai':
                $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                    'timeout' => 15,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $api_key,
                        'Content-Type'  => 'application/json',
                    ],
                    'body' => wp_json_encode([
                        'model'      => $model,
                        'messages'   => [['role' => 'user', 'content' => $test_prompt]],
                        'max_tokens' => 10,
                    ]),
                ]);
                $error_prefix = 'OpenAI';
                break;

            case 'custom':
                return ['success' => true, 'message' => 'Custom endpoint - không thể test tự động.'];

            default:
                return ['success' => false, 'error' => 'Provider không hợp lệ.'];
        }

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => "Lỗi kết nối {$error_prefix}: " . $response->get_error_message()];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code !== 200) {
            $error_msg = $data['error']['message'] ?? "HTTP {$status_code}";
            return ['success' => false, 'error' => "{$error_prefix} API lỗi: " . $error_msg, 'raw_response' => $body];
        }

        return ['success' => true, 'message' => "Kết nối {$error_prefix} thành công! Model: {$model}"];
    }

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
        // Tăng execution time cho xử lý AI (fallback nhiều model)
        @set_time_limit(300);

        $settings = TGS_AI_Settings::get_all();

        if (empty($settings['api_key'])) {
            return ['success' => false, 'error' => 'Chưa cấu hình API key. Vào Cài đặt AI để thiết lập.'];
        }

        $provider = $settings['provider'] ?? 'openrouter';

        switch ($provider) {
            case 'openrouter':
                return self::process_openrouter($file_path, $file_type, $original_name, $settings);
            case 'groq':
                return self::process_groq($file_path, $file_type, $original_name, $settings);
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
     * Process via OpenRouter API (miễn phí, có vision, OpenAI-compatible)
     * Endpoint: openrouter.ai/api/v1/chat/completions
     * Tự động fallback sang model khác nếu model chính bị "No endpoints found"
     */
    private static function process_openrouter($file_path, $file_type, $original_name, $settings)
    {
        $api_key = $settings['api_key'];
        $selected_model = $settings['model'] ?: 'google/gemini-2.0-flash-exp:free';
        $prompt = $settings['prompt_template'] ?: TGS_AI_Settings::get_default_prompt();

        $is_image = strpos($file_type, 'image/') === 0;
        $is_excel = in_array($file_type, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
        ]);

        // Không dùng system message vì một số model (Gemma) không hỗ trợ
        // Gộp prompt vào user message để tương thích mọi model
        $messages = [];

        if ($is_image) {
            $img = self::compress_image_for_ai($file_path, $file_type);
            $img_size_kb = round(strlen($img['data']) * 3 / 4 / 1024);
            $messages[] = [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $prompt . "\n\nPhân tích ảnh này và trích xuất danh sách sản phẩm. File: " . $original_name,
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url'    => 'data:' . $img['mime_type'] . ';base64,' . $img['data'],
                            'detail' => 'high',
                        ],
                    ],
                ],
            ];
        } elseif ($is_excel) {
            $csv_content = self::excel_to_csv($file_path, $file_type);
            if ($csv_content === false) {
                return ['success' => false, 'error' => 'Không thể đọc file Excel. Hãy thử dùng chức năng "Nhập từ Excel" thay thế.'];
            }
            $messages[] = [
                'role' => 'user',
                'content' => $prompt . "\n\nTrích xuất sản phẩm từ dữ liệu bảng sau (file: {$original_name}):\n\n{$csv_content}",
            ];
        } else {
            $text_content = self::extract_text($file_path, $file_type);
            if (empty($text_content)) {
                return ['success' => false, 'error' => 'Không thể đọc nội dung file. Hỗ trợ: ảnh (PNG/JPG), Excel, CSV.'];
            }
            $messages[] = [
                'role' => 'user',
                'content' => $prompt . "\n\nTrích xuất sản phẩm từ nội dung sau (file: {$original_name}):\n\n{$text_content}",
            ];
        }

        // Build list of models to try: selected first, then fallbacks
        // Sắp xếp: model nhỏ/nhanh trước, model lớn/chậm sau
        $fallback_models = [
            'openrouter/free',
            'google/gemma-3-4b-it:free',
            'google/gemma-3-12b-it:free',
            'mistralai/mistral-small-3.1-24b-instruct:free',
            'google/gemma-3-27b-it:free',
            'nvidia/nemotron-nano-12b-v2-vl:free',
        ];

        // Put selected model first, remove duplicates
        $models_to_try = array_unique(array_merge([$selected_model], $fallback_models));

        $last_error = '';
        $all_errors = [];
        foreach ($models_to_try as $model) {
            $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
                'timeout' => 45,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                    'HTTP-Referer'  => home_url(),
                    'X-Title'       => 'TGS AI Recognition',
                ],
                'body' => wp_json_encode([
                    'model'       => $model,
                    'messages'    => $messages,
                    'max_tokens'  => 4096,
                    'temperature' => 0.1,
                ]),
            ]);

            if (is_wp_error($response)) {
                $all_errors[] = "{$model}: WP Error - " . $response->get_error_message();
                continue;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($status_code !== 200) {
                $error_msg = $data['error']['message'] ?? "HTTP {$status_code}";
                $metadata = '';
                if (!empty($data['error']['metadata'])) {
                    $metadata = ' | metadata: ' . wp_json_encode($data['error']['metadata']);
                }
                $all_errors[] = "{$model} (HTTP {$status_code}): {$error_msg}{$metadata}";
                // Retryable errors
                $is_retryable = (
                    strpos($error_msg, 'No endpoints') !== false ||
                    strpos($error_msg, 'not available') !== false ||
                    strpos($error_msg, 'Provider returned error') !== false ||
                    strpos($error_msg, 'rate limit') !== false ||
                    strpos($error_msg, 'not enabled') !== false ||
                    $status_code === 400 ||
                    $status_code === 429 ||
                    $status_code === 502 ||
                    $status_code === 503
                );
                if ($is_retryable) {
                    $last_error = "Model {$model}: {$error_msg}";
                    if ($status_code === 429) {
                        sleep(2); // Rate limited → chờ 2s trước khi thử model tiếp
                    }
                    continue;
                }
                return ['success' => false, 'error' => 'OpenRouter API lỗi: ' . $error_msg, 'raw_response' => $body];
            }

            $ai_text = $data['choices'][0]['message']['content'] ?? '';
            $result = self::parse_ai_response($ai_text);
            // Nếu dùng model khác model đã chọn → ghi chú
            if ($model !== $selected_model && $result['success']) {
                $result['note'] = "Model '{$selected_model}' không khả dụng, đã tự động dùng '{$model}'.";
            }
            return $result;
        }

        $debug_info = implode("\n", $all_errors);
        $size_info = isset($img_size_kb) ? "\nKích thước ảnh: {$img_size_kb} KB (GD: " . (extension_loaded('gd') ? 'Có' : 'Không') . ")" : '';
        return [
            'success' => false,
            'error'   => "Tất cả model free đều không khả dụng.{$size_info}\n\nChi tiết lỗi từng model:\n" . $debug_info,
        ];
    }

    /**
     * Process via Groq API (miễn phí, OpenAI-compatible)
     * Endpoint: api.groq.com/openai/v1/chat/completions
     */
    private static function process_groq($file_path, $file_type, $original_name, $settings)
    {
        $api_key = $settings['api_key'];
        $model = $settings['model'] ?: 'llama-3.3-70b-versatile';
        $prompt = $settings['prompt_template'] ?: TGS_AI_Settings::get_default_prompt();

        $is_image = strpos($file_type, 'image/') === 0;
        $is_excel = in_array($file_type, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
        ]);

        // Check if model supports vision (model name contains "vision")
        $is_vision_model = (strpos($model, 'vision') !== false);

        $messages = [
            ['role' => 'system', 'content' => $prompt],
        ];

        if ($is_image) {
            if ($is_vision_model) {
                // Vision model: gửi compressed image
                $img = self::compress_image_for_ai($file_path, $file_type);
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
                                'url' => 'data:' . $img['mime_type'] . ';base64,' . $img['data'],
                            ],
                        ],
                    ],
                ];
            } else {
                // Non-vision model: không gửi được ảnh
                return [
                    'success' => false,
                    'error' => 'Model "' . $model . '" không hỗ trợ đọc ảnh. Vào Cài đặt AI → bấm "Tải danh sách model" → chọn model có chữ "vision". Hoặc dùng file Excel/CSV thay thế.',
                ];
            }
        } elseif ($is_excel) {
            $csv_content = self::excel_to_csv($file_path, $file_type);
            if ($csv_content === false) {
                return ['success' => false, 'error' => 'Không thể đọc file Excel. Hãy thử dùng chức năng "Nhập từ Excel" thay thế.'];
            }
            $messages[] = [
                'role' => 'user',
                'content' => "Trích xuất sản phẩm từ dữ liệu bảng sau (file: {$original_name}):\n\n{$csv_content}",
            ];
        } else {
            $text_content = self::extract_text($file_path, $file_type);
            if (empty($text_content)) {
                return ['success' => false, 'error' => 'Không thể đọc nội dung file. Hỗ trợ: ảnh (PNG/JPG), Excel, CSV.'];
            }
            $messages[] = [
                'role' => 'user',
                'content' => "Trích xuất sản phẩm từ nội dung sau (file: {$original_name}):\n\n{$text_content}",
            ];
        }

        $response = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', [
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
            return ['success' => false, 'error' => 'Lỗi kết nối Groq: ' . $response->get_error_message()];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code !== 200) {
            $error_msg = $data['error']['message'] ?? "HTTP {$status_code}";
            return ['success' => false, 'error' => 'Groq API lỗi: ' . $error_msg, 'raw_response' => $body];
        }

        $ai_text = $data['choices'][0]['message']['content'] ?? '';
        return self::parse_ai_response($ai_text);
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
            $img = self::compress_image_for_ai($file_path, $file_type);
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
                            'url' => 'data:' . $img['mime_type'] . ';base64,' . $img['data'],
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
            $img = self::compress_image_for_ai($file_path, $file_type);
            $parts[] = ['text' => 'Phân tích ảnh này và trích xuất danh sách sản phẩm. File: ' . $original_name];
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $img['mime_type'],
                    'data'      => $img['data'],
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
            $name = trim($item['name'] ?? '');
            // Chấp nhận sản phẩm có SKU hoặc có tên
            if (empty($sku) && empty($name)) continue;

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
     * Compress and resize image for AI processing
     * Returns ['data' => base64_string, 'mime_type' => 'image/jpeg'] or false on failure
     */
    private static function compress_image_for_ai($file_path, $file_type, $max_dimension = 1280, $quality = 85)
    {
        // Try GD library first
        if (!function_exists('imagecreatefromstring')) {
            // GD not available, return original
            return [
                'data' => base64_encode(file_get_contents($file_path)),
                'mime_type' => $file_type,
            ];
        }

        $image_data = file_get_contents($file_path);
        $src = @imagecreatefromstring($image_data);
        if (!$src) {
            return [
                'data' => base64_encode($image_data),
                'mime_type' => $file_type,
            ];
        }

        $orig_w = imagesx($src);
        $orig_h = imagesy($src);

        // Calculate new dimensions
        if ($orig_w > $max_dimension || $orig_h > $max_dimension) {
            if ($orig_w >= $orig_h) {
                $new_w = $max_dimension;
                $new_h = (int) round($orig_h * ($max_dimension / $orig_w));
            } else {
                $new_h = $max_dimension;
                $new_w = (int) round($orig_w * ($max_dimension / $orig_h));
            }

            $dst = imagecreatetruecolor($new_w, $new_h);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
            imagedestroy($src);
            $src = $dst;
        }

        // Output as JPEG to buffer
        ob_start();
        imagejpeg($src, null, $quality);
        $compressed = ob_get_clean();
        imagedestroy($src);

        return [
            'data' => base64_encode($compressed),
            'mime_type' => 'image/jpeg',
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
