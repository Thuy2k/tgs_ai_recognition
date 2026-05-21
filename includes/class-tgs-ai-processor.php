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

        $api_keys = TGS_AI_Settings::get_api_keys($settings);
        if (empty($api_keys)) {
            return ['success' => false, 'error' => 'Chưa cấu hình API key. Vào Cài đặt AI để thiết lập.'];
        }

        $provider = $settings['provider'] ?? 'openrouter';
        $model = $settings['model'] ?: 'nvidia/nemotron-nano-12b-v2-vl:free';

        $test_prompt = 'Trả lời đúng 1 từ: "OK"';
        $last_error = '';

        foreach ($api_keys as $key_index => $api_key) {
            $key_label = 'key #' . ($key_index + 1);

            switch ($provider) {
                case 'openrouter':
                    // Thử model đã chọn + fallback models
                    $fallback_models = [
                        'openrouter/free',
                        'google/gemma-3-12b-it:free',
                        'mistralai/mistral-small-3.1-24b-instruct:free',
                    ];
                    $models_to_try = array_unique(array_merge([$model], $fallback_models));

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
                            $last_error = $key_label . ' - ' . $response->get_error_message();
                            continue;
                        }

                        $sc = wp_remote_retrieve_response_code($response);
                        $bd = json_decode(wp_remote_retrieve_body($response), true);

                        if ($sc === 200) {
                            $msg = "Kết nối OpenRouter thành công! Model: {$try_model} ({$key_label})";
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
                            $last_error = "{$key_label} - Model {$try_model}: {$err}";
                            continue;
                        }
                        $last_error = "{$key_label} - OpenRouter API lỗi: {$err}";
                        break;
                    }

                    continue 2;

                case 'huggingface':
                    $response = wp_remote_post('https://api-inference.huggingface.co/v1/chat/completions', [
                        'timeout' => 30,
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
                    $error_prefix = 'HuggingFace';
                    break;

                case 'together':
                    $response = wp_remote_post('https://api.together.xyz/v1/chat/completions', [
                        'timeout' => 30,
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
                    $error_prefix = 'Together AI';
                    break;

                case 'nvidia':
                    $response = wp_remote_post('https://integrate.api.nvidia.com/v1/chat/completions', [
                        'timeout' => 30,
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
                    $error_prefix = 'NVIDIA NIM';
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

                case 'chatgpt':
                    $response = wp_remote_post('https://api.openai.com/v1/responses', [
                        'timeout' => 15,
                        'headers' => [
                            'Authorization' => 'Bearer ' . $api_key,
                            'Content-Type'  => 'application/json',
                        ],
                        'body' => wp_json_encode([
                            'model'             => $model,
                            'input'             => $test_prompt,
                            'max_output_tokens' => 20,
                        ]),
                    ]);
                    $error_prefix = 'ChatGPT';
                    break;

                case 'custom':
                    return ['success' => true, 'message' => 'Custom endpoint - không thể test tự động.'];

                default:
                    return ['success' => false, 'error' => 'Provider không hợp lệ.'];
            }

            if (is_wp_error($response)) {
                $last_error = "{$key_label} - Lỗi kết nối {$error_prefix}: " . $response->get_error_message();
                continue;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($status_code !== 200) {
                $error_msg = $data['error']['message'] ?? "HTTP {$status_code}";
                $last_error = "{$key_label} - {$error_prefix} API lỗi: {$error_msg}";
                continue;
            }

            return ['success' => true, 'message' => "Kết nối {$error_prefix} thành công! Model: {$model} ({$key_label})"];
        }

        return ['success' => false, 'error' => 'Tất cả API key đều lỗi khi test kết nối. ' . $last_error];
    }

    /**
     * Process uploaded file qua AI provider
     *
     * @param string $file_path Đường dẫn file tạm
     * @param string $file_type MIME type
     * @param string $original_name Tên file gốc
     * @return array ['success' => bool, 'products' => [...], 'raw_response' => '...', 'error' => '...']
     */
    public static function process($file_path, $file_type, $original_name = '', $prompt_override = null)
    {
        // Tăng execution time cho xử lý AI (fallback nhiều model)
        @set_time_limit(300);

        $settings = TGS_AI_Settings::get_all();

        $api_keys = TGS_AI_Settings::get_api_keys($settings);
        if (empty($api_keys)) {
            return ['success' => false, 'error' => 'Chưa cấu hình API key. Vào Cài đặt AI để thiết lập.'];
        }

        // Nếu có prompt override (ví dụ POS mode), truyền vào settings
        if ($prompt_override !== null) {
            $settings['_prompt_override'] = $prompt_override;
        }

        $provider = $settings['provider'] ?? 'openrouter';

        $last_api_error = null;
        foreach ($api_keys as $idx => $api_key) {
            $settings_with_key = $settings;
            $settings_with_key['api_key'] = $api_key;

            switch ($provider) {
                case 'openrouter':
                    $result = self::process_openrouter($file_path, $file_type, $original_name, $settings_with_key);
                    break;
                case 'huggingface':
                    $result = self::process_huggingface($file_path, $file_type, $original_name, $settings_with_key);
                    break;
                case 'together':
                    $result = self::process_together($file_path, $file_type, $original_name, $settings_with_key);
                    break;
                case 'nvidia':
                    $result = self::process_nvidia($file_path, $file_type, $original_name, $settings_with_key);
                    break;
                case 'groq':
                    $result = self::process_groq($file_path, $file_type, $original_name, $settings_with_key);
                    break;
                case 'gemini':
                    $result = self::process_gemini($file_path, $file_type, $original_name, $settings_with_key);
                    break;
                case 'openai':
                    $result = self::process_openai($file_path, $file_type, $original_name, $settings_with_key);
                    break;
                case 'chatgpt':
                    $result = self::process_chatgpt($file_path, $file_type, $original_name, $settings_with_key);
                    break;
                case 'custom':
                    $result = self::process_custom($file_path, $file_type, $original_name, $settings_with_key);
                    break;
                default:
                    return ['success' => false, 'error' => 'Provider không hợp lệ: ' . $provider];
            }

            if (!empty($result['success'])) {
                if ($idx > 0) {
                    $existing_note = isset($result['note']) ? trim((string) $result['note']) : '';
                    $fallback_note = "Đã tự động chuyển sang API key #" . ($idx + 1) . " do key trước bị lỗi.";
                    $result['note'] = $existing_note !== '' ? ($existing_note . ' ' . $fallback_note) : $fallback_note;
                }
                return $result;
            }

            if (!self::is_api_error_result($result)) {
                return $result;
            }

            $last_api_error = $result;
        }

        if (is_array($last_api_error)) {
            $last_api_error['error'] = ($last_api_error['error'] ?? 'API lỗi') . ' (đã thử ' . count($api_keys) . ' API key).';
            return $last_api_error;
        }

        return ['success' => false, 'error' => 'Không thể xử lý với tất cả API key đã cấu hình.'];
    }

    private static function is_api_error_result($result)
    {
        if (!is_array($result) || empty($result['error'])) {
            return false;
        }

        $error = mb_strtolower((string) $result['error']);
        $signals = [
            'api lỗi',
            'lỗi kết nối',
            'timeout',
            'rate',
            'http',
            'unauthorized',
            'forbidden',
            'invalid api key',
            'quota',
            '429',
            '401',
            '403',
        ];

        foreach ($signals as $signal) {
            if (strpos($error, $signal) !== false) {
                return true;
            }
        }

        return false;
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
        $prompt = $settings['_prompt_override'] ?? ($settings['prompt_template'] ?: TGS_AI_Settings::get_default_prompt());

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
                            'detail' => 'auto',
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
        $fallback_models = [
            'openrouter/free',
            'google/gemma-3-12b-it:free',
            'google/gemma-3-4b-it:free',
            'mistralai/mistral-small-3.1-24b-instruct:free',
            'google/gemma-3-27b-it:free',
        ];

        // Put selected model first, remove duplicates
        $models_to_try = array_unique(array_merge([$selected_model], $fallback_models));

        $last_error = '';
        $all_errors = [];
        foreach ($models_to_try as $model) {
            $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
                'timeout' => 120,
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
                        sleep(5); // Rate limited → chờ 5s trước khi thử model tiếp
                    }
                    continue;
                }
                return ['success' => false, 'error' => 'OpenRouter API lỗi: ' . $error_msg, 'raw_response' => $body];
            }

            // Log response for debugging
            $ai_text = $data['choices'][0]['message']['content'] ?? '';
            if (empty($ai_text)) {
                // Content rỗng — log toàn bộ response để debug
                error_log('[TGS AI] Model ' . $model . ' returned empty content. Full response: ' . mb_substr($body, 0, 2000));
                // Có thể model trả finish_reason khác, hoặc structure khác
                $finish_reason = $data['choices'][0]['finish_reason'] ?? 'unknown';
                $all_errors[] = "{$model}: Content rỗng (finish_reason: {$finish_reason})";
                continue; // Thử model tiếp theo
            }

            error_log('[TGS AI] Model ' . $model . ' responded successfully. Content length: ' . strlen($ai_text));
            $result = self::parse_ai_response($ai_text);

            if ($result['success'] && $result['total'] > 0) {
                // Nếu dùng model khác model đã chọn → ghi chú
                if ($model !== $selected_model) {
                    $result['note'] = "Model '{$selected_model}' không khả dụng, đã tự động dùng '{$model}'.";
                }
                return $result;
            }

            // Parse thất bại hoặc 0 sản phẩm → thử model tiếp
            $parse_err = $result['error'] ?? "0 sản phẩm";
            error_log('[TGS AI] Model ' . $model . ' parse failed: ' . $parse_err . '. Raw: ' . mb_substr($ai_text, 0, 500));
            $all_errors[] = "{$model}: {$parse_err}";
            continue;
        }

        $debug_info = implode("\n", $all_errors);
        $size_info = isset($img_size_kb) ? "\nKích thước ảnh: {$img_size_kb} KB (GD: " . (extension_loaded('gd') ? 'Có' : 'Không') . ")" : '';
        error_log('[TGS AI] All models failed. Errors: ' . $debug_info);
        return [
            'success'      => false,
            'error'        => "Tất cả model free đều không khả dụng.{$size_info}\n\nChi tiết lỗi từng model:\n" . $debug_info,
            'raw_response' => $debug_info,
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
        $prompt = $settings['_prompt_override'] ?? ($settings['prompt_template'] ?: TGS_AI_Settings::get_default_prompt());

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
        $prompt = $settings['_prompt_override'] ?? ($settings['prompt_template'] ?: TGS_AI_Settings::get_default_prompt());

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
     * Process via ChatGPT provider (OpenAI Responses API)
     * Endpoint: api.openai.com/v1/responses
     */
    private static function process_chatgpt($file_path, $file_type, $original_name, $settings)
    {
        $api_key = $settings['api_key'];
        $model = $settings['model'] ?: 'gpt-4.1-mini';
        $prompt = $settings['_prompt_override'] ?? ($settings['prompt_template'] ?: TGS_AI_Settings::get_default_prompt());

        $is_image = strpos($file_type, 'image/') === 0;
        $is_excel = in_array($file_type, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
        ]);

        if ($is_image) {
            $img = self::compress_image_for_ai($file_path, $file_type);
            $input = [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $prompt . "\n\nPhân tích ảnh này và trích xuất danh sách sản phẩm. File: " . $original_name,
                    ],
                    [
                        'type' => 'input_image',
                        'image_url' => 'data:' . $img['mime_type'] . ';base64,' . $img['data'],
                        'detail' => 'high',
                    ],
                ],
            ]];
        } elseif ($is_excel) {
            $csv_content = self::excel_to_csv($file_path, $file_type);
            if ($csv_content === false) {
                return ['success' => false, 'error' => 'Không thể đọc file Excel. Hãy thử dùng chức năng "Nhập từ Excel" thay thế.'];
            }
            $input = $prompt . "\n\nTrích xuất sản phẩm từ dữ liệu bảng sau (file: {$original_name}):\n\n{$csv_content}";
        } else {
            $text_content = self::extract_text($file_path, $file_type);
            if (empty($text_content)) {
                return ['success' => false, 'error' => 'Không thể đọc nội dung file. Hỗ trợ: ảnh (PNG/JPG), Excel, CSV.'];
            }
            $input = $prompt . "\n\nTrích xuất sản phẩm từ nội dung sau (file: {$original_name}):\n\n{$text_content}";
        }

        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'             => $model,
                'input'             => $input,
                'max_output_tokens' => 4096,
                'temperature'       => 0.1,
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => 'Lỗi kết nối ChatGPT: ' . $response->get_error_message()];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code !== 200) {
            $error_msg = $data['error']['message'] ?? "HTTP {$status_code}";
            return ['success' => false, 'error' => 'ChatGPT API lỗi: ' . $error_msg, 'raw_response' => $body];
        }

        $ai_text = self::extract_responses_output_text($data);
        return self::parse_ai_response($ai_text);
    }

    /**
     * Process via HuggingFace Inference API (miễn phí, OpenAI-compatible)
     * Endpoint: api-inference.huggingface.co/v1/chat/completions
     * Free tier: unlimited models, rate limited
     */
    private static function process_huggingface($file_path, $file_type, $original_name, $settings)
    {
        $api_key = $settings['api_key'];
        $selected_model = $settings['model'] ?: 'Qwen/Qwen2.5-VL-7B-Instruct';
        $prompt = $settings['_prompt_override'] ?? ($settings['prompt_template'] ?: TGS_AI_Settings::get_default_prompt());

        $is_image = strpos($file_type, 'image/') === 0;
        $is_excel = in_array($file_type, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
        ]);

        $messages = [];

        if ($is_image) {
            $img = self::compress_image_for_ai($file_path, $file_type);
            $img_size_kb = round(strlen(base64_decode($img['data'])) / 1024);
            error_log('[TGS AI] HuggingFace image size: ' . $img_size_kb . ' KB');

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
                            'url' => 'data:' . $img['mime_type'] . ';base64,' . $img['data'],
                        ],
                    ],
                ],
            ];
        } elseif ($is_excel) {
            $csv_content = self::excel_to_csv($file_path, $file_type);
            if ($csv_content === false) {
                return ['success' => false, 'error' => 'Không thể đọc file Excel.'];
            }
            $messages[] = [
                'role' => 'user',
                'content' => $prompt . "\n\nTrích xuất sản phẩm từ bảng (file: {$original_name}):\n\n{$csv_content}",
            ];
        } else {
            $text_content = ($file_type === 'application/pdf')
                ? 'Nội dung PDF (base64): ' . base64_encode(file_get_contents($file_path))
                : self::extract_text($file_path, $file_type);
            if (empty($text_content)) {
                return ['success' => false, 'error' => 'Không thể đọc nội dung file.'];
            }
            $messages[] = [
                'role' => 'user',
                'content' => $prompt . "\n\nTrích xuất sản phẩm (file: {$original_name}):\n\n{$text_content}",
            ];
        }

        // Fallback models
        $fallback_models = [
            'Qwen/Qwen2.5-VL-7B-Instruct',
            'meta-llama/Llama-3.2-11B-Vision-Instruct',
        ];
        $models_to_try = array_unique(array_merge([$selected_model], $fallback_models));

        $all_errors = [];
        foreach ($models_to_try as $model) {
            error_log('[TGS AI] Trying HuggingFace model: ' . $model);

            $response = wp_remote_post('https://api-inference.huggingface.co/v1/chat/completions', [
                'timeout' => 120,
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
                $all_errors[] = "{$model}: WP Error - " . $response->get_error_message();
                continue;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($status_code !== 200) {
                $error_msg = $data['error'] ?? $data['error']['message'] ?? "HTTP {$status_code}";
                if (is_array($error_msg)) $error_msg = wp_json_encode($error_msg);
                $all_errors[] = "{$model} (HTTP {$status_code}): {$error_msg}";
                error_log('[TGS AI] HuggingFace error: ' . $error_msg);

                $is_retryable = ($status_code === 429 || $status_code === 503 || $status_code === 500);
                if ($is_retryable) {
                    if ($status_code === 429) sleep(3);
                    continue;
                }
                return ['success' => false, 'error' => 'HuggingFace API lỗi: ' . $error_msg, 'raw_response' => $body];
            }

            $ai_text = $data['choices'][0]['message']['content'] ?? '';
            if (empty($ai_text)) {
                error_log('[TGS AI] HuggingFace model ' . $model . ' returned empty. Response: ' . mb_substr($body, 0, 1000));
                $all_errors[] = "{$model}: Content rỗng";
                continue;
            }

            error_log('[TGS AI] HuggingFace model ' . $model . ' success. Length: ' . strlen($ai_text));
            $result = self::parse_ai_response($ai_text);

            if ($result['success'] && $result['total'] > 0) {
                if ($model !== $selected_model) {
                    $result['note'] = "Model '{$selected_model}' không khả dụng, đã dùng '{$model}'.";
                }
                return $result;
            }

            $parse_err = $result['error'] ?? '0 sản phẩm';
            $all_errors[] = "{$model}: {$parse_err}";
            continue;
        }

        $debug_info = implode("\n", $all_errors);
        $size_info = isset($img_size_kb) ? "\nKích thước ảnh: {$img_size_kb} KB" : '';
        error_log('[TGS AI] HuggingFace all models failed: ' . $debug_info);
        return [
            'success'      => false,
            'error'        => "Tất cả model HuggingFace đều thất bại.{$size_info}\n\nChi tiết:\n" . $debug_info,
            'raw_response' => $debug_info,
        ];
    }

    /**
     * Process via Together AI API (có model Llama-Vision-Free miễn phí)
     * Endpoint: api.together.xyz/v1/chat/completions (OpenAI-compatible)
     */
    private static function process_together($file_path, $file_type, $original_name, $settings)
    {
        $api_key = $settings['api_key'];
        $selected_model = $settings['model'] ?: 'meta-llama/Llama-Vision-Free';
        $prompt = $settings['_prompt_override'] ?? ($settings['prompt_template'] ?: TGS_AI_Settings::get_default_prompt());

        $is_image = strpos($file_type, 'image/') === 0;
        $is_excel = in_array($file_type, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
        ]);

        $messages = [];

        if ($is_image) {
            $img = self::compress_image_for_ai($file_path, $file_type);
            $img_size_kb = round(strlen(base64_decode($img['data'])) / 1024);
            error_log('[TGS AI] Together image size: ' . $img_size_kb . ' KB');

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
                            'url' => 'data:' . $img['mime_type'] . ';base64,' . $img['data'],
                        ],
                    ],
                ],
            ];
        } elseif ($is_excel) {
            $csv_content = self::excel_to_csv($file_path, $file_type);
            if ($csv_content === false) {
                return ['success' => false, 'error' => 'Không thể đọc file Excel.'];
            }
            $messages[] = [
                'role' => 'user',
                'content' => $prompt . "\n\nTrích xuất sản phẩm từ bảng (file: {$original_name}):\n\n{$csv_content}",
            ];
        } else {
            $text_content = ($file_type === 'application/pdf')
                ? 'Nội dung PDF (base64): ' . base64_encode(file_get_contents($file_path))
                : self::extract_text($file_path, $file_type);
            if (empty($text_content)) {
                return ['success' => false, 'error' => 'Không thể đọc nội dung file.'];
            }
            $messages[] = [
                'role' => 'user',
                'content' => $prompt . "\n\nTrích xuất sản phẩm (file: {$original_name}):\n\n{$text_content}",
            ];
        }

        // Fallback models: Free model first, then turbo
        $fallback_models = [
            'meta-llama/Llama-Vision-Free',
            'meta-llama/Llama-3.2-11B-Vision-Instruct-Turbo',
        ];
        $models_to_try = array_unique(array_merge([$selected_model], $fallback_models));

        $all_errors = [];
        foreach ($models_to_try as $model) {
            error_log('[TGS AI] Trying Together model: ' . $model);

            $response = wp_remote_post('https://api.together.xyz/v1/chat/completions', [
                'timeout' => 120,
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
                $all_errors[] = "{$model}: WP Error - " . $response->get_error_message();
                continue;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($status_code !== 200) {
                $error_msg = $data['error']['message'] ?? $data['error'] ?? "HTTP {$status_code}";
                if (is_array($error_msg)) $error_msg = wp_json_encode($error_msg);
                $all_errors[] = "{$model} (HTTP {$status_code}): {$error_msg}";
                error_log('[TGS AI] Together error: ' . $error_msg);
                if ($status_code === 429 || $status_code === 503) {
                    sleep(3);
                    continue;
                }
                continue;
            }

            $ai_text = $data['choices'][0]['message']['content'] ?? '';
            if (empty($ai_text)) {
                error_log('[TGS AI] Together model ' . $model . ' returned empty. Response: ' . mb_substr($body, 0, 1000));
                $all_errors[] = "{$model}: Content rỗng";
                continue;
            }

            error_log('[TGS AI] Together model ' . $model . ' success. Length: ' . strlen($ai_text));
            $result = self::parse_ai_response($ai_text);

            if ($result['success'] && $result['total'] > 0) {
                if ($model !== $selected_model) {
                    $result['note'] = "Model '{$selected_model}' không khả dụng, đã dùng '{$model}'.";
                }
                return $result;
            }

            $parse_err = $result['error'] ?? '0 sản phẩm';
            $all_errors[] = "{$model}: {$parse_err}";
            continue;
        }

        $debug_info = implode("\n", $all_errors);
        $size_info = isset($img_size_kb) ? "\nKích thước ảnh: {$img_size_kb} KB" : '';
        error_log('[TGS AI] Together all models failed: ' . $debug_info);
        return [
            'success'      => false,
            'error'        => "Tất cả model Together đều thất bại.{$size_info}\n\nChi tiết:\n" . $debug_info,
            'raw_response' => $debug_info,
        ];
    }

    /**
     * Process via NVIDIA NIM API (1000 free credits, OpenAI-compatible)
     * Endpoint: integrate.api.nvidia.com/v1/chat/completions
     */
    private static function process_nvidia($file_path, $file_type, $original_name, $settings)
    {
        $api_key = $settings['api_key'];
        $selected_model = $settings['model'] ?: 'nvidia/neva-22b';
        $prompt = $settings['_prompt_override'] ?? ($settings['prompt_template'] ?: TGS_AI_Settings::get_default_prompt());

        $is_image = strpos($file_type, 'image/') === 0;
        $is_excel = in_array($file_type, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
        ]);

        $messages = [];

        if ($is_image) {
            $img = self::compress_image_for_ai($file_path, $file_type);
            $img_size_kb = round(strlen(base64_decode($img['data'])) / 1024);
            error_log('[TGS AI] NVIDIA image size: ' . $img_size_kb . ' KB');

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
                            'url' => 'data:' . $img['mime_type'] . ';base64,' . $img['data'],
                        ],
                    ],
                ],
            ];
        } elseif ($is_excel) {
            $csv_content = self::excel_to_csv($file_path, $file_type);
            if ($csv_content === false) {
                return ['success' => false, 'error' => 'Không thể đọc file Excel.'];
            }
            $messages[] = [
                'role' => 'user',
                'content' => $prompt . "\n\nTrích xuất sản phẩm từ bảng (file: {$original_name}):\n\n{$csv_content}",
            ];
        } else {
            $text_content = ($file_type === 'application/pdf')
                ? 'Nội dung PDF (base64): ' . base64_encode(file_get_contents($file_path))
                : self::extract_text($file_path, $file_type);
            if (empty($text_content)) {
                return ['success' => false, 'error' => 'Không thể đọc nội dung file.'];
            }
            $messages[] = [
                'role' => 'user',
                'content' => $prompt . "\n\nTrích xuất sản phẩm (file: {$original_name}):\n\n{$text_content}",
            ];
        }

        error_log('[TGS AI] Trying NVIDIA model: ' . $selected_model);

        $response = wp_remote_post('https://integrate.api.nvidia.com/v1/chat/completions', [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'       => $selected_model,
                'messages'    => $messages,
                'max_tokens'  => 4096,
                'temperature' => 0.1,
            ]),
        ]);

        if (is_wp_error($response)) {
            error_log('[TGS AI] NVIDIA connection error: ' . $response->get_error_message());
            return ['success' => false, 'error' => 'Lỗi kết nối NVIDIA: ' . $response->get_error_message()];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code !== 200) {
            $error_msg = $data['detail'] ?? $data['error']['message'] ?? "HTTP {$status_code}";
            if (is_array($error_msg)) $error_msg = wp_json_encode($error_msg);
            error_log('[TGS AI] NVIDIA error: ' . $error_msg);
            return ['success' => false, 'error' => 'NVIDIA API lỗi: ' . $error_msg, 'raw_response' => $body];
        }

        $ai_text = $data['choices'][0]['message']['content'] ?? '';
        if (empty($ai_text)) {
            error_log('[TGS AI] NVIDIA returned empty. Response: ' . mb_substr($body, 0, 1000));
            return ['success' => false, 'error' => 'NVIDIA trả về kết quả rỗng.', 'raw_response' => $body];
        }

        error_log('[TGS AI] NVIDIA success. Length: ' . strlen($ai_text));
        error_log('[TGS AI] NVIDIA raw content: ' . mb_substr($ai_text, 0, 2000));
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
        $prompt = $settings['_prompt_override'] ?? ($settings['prompt_template'] ?: TGS_AI_Settings::get_default_prompt());

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
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($request_body),
        ]);

        if (is_wp_error($response)) {
            error_log('[TGS AI] Gemini connection error: ' . $response->get_error_message());
            return ['success' => false, 'error' => 'Lỗi kết nối Gemini: ' . $response->get_error_message()];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code !== 200) {
            $error_msg = $data['error']['message'] ?? "HTTP {$status_code}";
            error_log('[TGS AI] Gemini API error: ' . $error_msg);
            return ['success' => false, 'error' => 'Gemini API lỗi: ' . $error_msg, 'raw_response' => $body];
        }

        // Extract text from Gemini response
        $ai_text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        error_log('[TGS AI] Gemini response length: ' . strlen($ai_text) . '. Preview: ' . mb_substr($ai_text, 0, 200));
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
     * Extract text from OpenAI Responses API payload
     */
    private static function extract_responses_output_text($data)
    {
        if (!is_array($data)) {
            return '';
        }

        if (!empty($data['output_text']) && is_string($data['output_text'])) {
            return $data['output_text'];
        }

        $chunks = [];
        if (!empty($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $output_item) {
                if (empty($output_item['content']) || !is_array($output_item['content'])) {
                    continue;
                }
                foreach ($output_item['content'] as $content_item) {
                    if (($content_item['type'] ?? '') === 'output_text' && isset($content_item['text'])) {
                        $chunks[] = $content_item['text'];
                    }
                }
            }
        }

        return trim(implode("\n", $chunks));
    }

    /**
     * Parse AI response text → structured products array
     */
    public static function parse_ai_response($ai_text)
    {
        if (empty($ai_text)) {
            return ['success' => false, 'error' => 'AI không trả về kết quả.', 'raw_response' => ''];
        }

        // Strategy 0: Try entire text as JSON object (POS HTSoft format: {"items":[...],"customer":{},"htsoft_total":0})
        $decoded_obj = json_decode(trim($ai_text), true);
        if (is_array($decoded_obj) && isset($decoded_obj['items']) && is_array($decoded_obj['items'])) {
            return self::normalize_pos_htsoft_response($decoded_obj, $ai_text);
        }

        // Strategy 1: Extract JSON from ```json ... ``` code block
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $ai_text, $matches)) {
            $json_inner = trim($matches[1]);
            $decoded_inner = json_decode($json_inner, true);
            if (is_array($decoded_inner) && isset($decoded_inner['items'])) {
                return self::normalize_pos_htsoft_response($decoded_inner, $ai_text);
            }
            if (is_array($decoded_inner)) {
                return self::normalize_products($decoded_inner, $ai_text);
            }
        }

        // Strategy 1b: Extract JSON object from text (POS format wrapped in text)
        if (preg_match('/\{\s*"items"\s*:.*\}/s', $ai_text, $matches)) {
            $decoded_obj2 = json_decode($matches[0], true);
            if (is_array($decoded_obj2) && isset($decoded_obj2['items'])) {
                return self::normalize_pos_htsoft_response($decoded_obj2, $ai_text);
            }
        }

        // Strategy 2: Find the largest [...] JSON array in text
        if (preg_match_all('/\[\s*\{.*?\}\s*\]/s', $ai_text, $matches)) {
            usort($matches[0], function($a, $b) { return strlen($b) - strlen($a); });
            foreach ($matches[0] as $match) {
                $products = json_decode($match, true);
                if (is_array($products) && !empty($products)) {
                    return self::normalize_products($products, $ai_text);
                }
            }
        }

        // Strategy 3: Collect individual JSON objects
        if (preg_match_all('/\{\s*"sku"\s*:.*?\}/s', $ai_text, $matches)) {
            $products = [];
            foreach ($matches[0] as $json_str) {
                $item = json_decode($json_str, true);
                if (is_array($item) && (!empty($item['sku']) || !empty($item['name']))) {
                    $products[] = $item;
                }
            }
            if (!empty($products)) {
                return self::normalize_products($products, $ai_text);
            }
        }

        // Strategy 4: Try entire text as JSON array
        $products = json_decode(trim($ai_text), true);
        if (is_array($products) && !isset($products['items'])) {
            return self::normalize_products($products, $ai_text);
        }

        return [
            'success' => false,
            'error' => 'Không thể parse kết quả AI. Vui lòng thử lại.',
            'raw_response' => $ai_text,
        ];
    }

    /**
     * Normalize POS HTSoft response object { items, customer, htsoft_total }
     */
    private static function normalize_pos_htsoft_response($data, $raw_text)
    {
        $items = [];
        foreach ($data['items'] as $item) {
            if (!is_array($item)) continue;
            $sku  = trim($item['sku']  ?? '');
            $name = trim($item['name'] ?? '');
            if (empty($sku) && empty($name)) continue;
            $items[] = [
                'sku'              => $sku,
                'name'             => $name,
                'quantity'         => floatval($item['quantity']         ?? 1),
                'unit'             => trim($item['unit']                 ?? ''),
                'unit_price'       => floatval($item['unit_price']       ?? 0),
                'discount_percent' => floatval($item['discount_percent'] ?? 0),
                'total_amount'     => floatval($item['total_amount']     ?? 0),
            ];
        }
        return [
            'success'      => true,
            'products'     => $items,
            'total'        => count($items),
            'raw_data'     => [
                'items'        => $items,
                'customer'     => [
                    'phone' => trim($data['customer']['phone'] ?? ''),
                    'name'  => trim($data['customer']['name']  ?? ''),
                ],
                'htsoft_total' => floatval($data['htsoft_total'] ?? 0),
            ],
            'raw_response' => $raw_text,
        ];
    }
    /**
     * Normalize parsed products array
     */
    private static function normalize_products($products, $raw_text)
    {
        $normalized = [];
        foreach ($products as $item) {
            if (!is_array($item)) continue;
            $sku = trim($item['sku'] ?? '');
            $name = trim($item['name'] ?? '');
            if (empty($sku) && empty($name)) continue;

            $normalized[] = [
                'sku'      => $sku,
                'name'     => $name,
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
            'raw_response' => $raw_text,
        ];
    }

    /**
     * Compress and resize image for AI processing
     * Returns ['data' => base64_string, 'mime_type' => 'image/jpeg'] or false on failure
     */
    private static function compress_image_for_ai($file_path, $file_type, $max_dimension = 800, $quality = 70)
    {
        // Ensure enough memory for GD operations on large phone photos
        @ini_set('memory_limit', '256M');

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

