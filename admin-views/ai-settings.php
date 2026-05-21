<?php
/**
 * AI Settings Page - Trang cấu hình AI nhận diện
 *
 * Hiển thị trong sidebar của tgs_shop_management
 *
 * @package tgs_ai_recognition
 */

if (!defined('ABSPATH')) {
    exit;
}

$settings = TGS_AI_Settings::get_all();
$providers = TGS_AI_Settings::get_providers();
$masked_key = TGS_AI_Settings::get_masked_api_key();
$masked_keys = TGS_AI_Settings::get_masked_api_keys();
$nonce = wp_create_nonce('tgs_ai_nonce');
?>

<div class="container-xxl flex-grow-1 container-p-y">
    <h4 class="fw-bold py-3 mb-4">
        <span class="text-muted fw-light">Cài đặt /</span> AI Nhận Diện
    </h4>

    <div class="row">
        <!-- Main Settings -->
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="bx bx-bot me-2"></i>Cấu hình AI nhận diện sản phẩm</h5>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="ai_enabled"
                               <?php echo $settings['enabled'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="ai_enabled">
                            <?php echo $settings['enabled'] ? 'Đang bật' : 'Đang tắt'; ?>
                        </label>
                    </div>
                </div>
                <div class="card-body">
                    <form id="aiSettingsForm">
                        <input type="hidden" name="nonce" value="<?php echo $nonce; ?>">

                        <!-- Provider -->
                        <div class="mb-3">
                            <label class="form-label" for="ai_provider">Nhà cung cấp AI</label>
                            <select class="form-select" id="ai_provider" name="provider">
                                <?php foreach ($providers as $key => $provider): ?>
                                    <option value="<?php echo esc_attr($key); ?>"
                                            <?php selected($settings['provider'], $key); ?>>
                                        <?php echo esc_html($provider['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="providerDescription">
                                <?php echo esc_html($providers[$settings['provider']]['description'] ?? ''); ?>
                            </div>
                        </div>

                        <!-- API Keys -->
                        <div class="mb-3">
                            <label class="form-label" for="ai_api_keys">API Keys</label>
                            <textarea class="form-control font-monospace" id="ai_api_keys" name="api_keys" rows="4"
                                      placeholder="<?php echo esc_attr($masked_keys ? $masked_keys : 'Mỗi dòng 1 API key...'); ?>"
                            ></textarea>
                            <div class="form-text">Nhập nhiều key (mỗi dòng 1 key). Hệ thống sẽ tự thử tuần tự từ trên xuống khi API lỗi. Để trống nếu không muốn thay đổi key hiện tại.</div>
                        </div>

                        <!-- Model -->
                        <div class="mb-3" id="modelGroup">
                            <label class="form-label" for="ai_model">
                                Model
                                <button type="button" class="btn btn-sm btn-link p-0 ms-2" id="btnFetchModels" style="display:none;">
                                    <i class="bx bx-refresh"></i> Tải danh sách model
                                </button>
                                <span id="fetchModelSpinner" class="spinner-border spinner-border-sm ms-1" style="display:none;"></span>
                            </label>
                            <select class="form-select" id="ai_model" name="model">
                                <!-- Populated by JS based on provider -->
                            </select>
                            <div class="form-text" id="modelHint"></div>
                        </div>

                        <!-- Custom Endpoint (only for custom provider) -->
                        <div class="mb-3" id="customEndpointGroup" style="display:none;">
                            <label class="form-label" for="ai_custom_endpoint">Custom API Endpoint</label>
                            <input type="url" class="form-control" id="ai_custom_endpoint" name="custom_endpoint"
                                   placeholder="https://your-api.com/v1/recognize"
                                   value="<?php echo esc_attr($settings['custom_endpoint'] ?? ''); ?>">
                        </div>

                        <hr>

                        <!-- Max File Size -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="ai_max_file_size">Dung lượng file tối đa (MB)</label>
                                <input type="number" class="form-control" id="ai_max_file_size" name="max_file_size"
                                       value="<?php echo intval($settings['max_file_size']); ?>" min="1" max="50">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tuỳ chọn</label>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="ai_camera_enabled" name="camera_enabled"
                                           <?php echo $settings['camera_enabled'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="ai_camera_enabled">
                                        Cho phép chụp camera (mobile)
                                    </label>
                                </div>
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" id="ai_auto_fill" name="auto_fill"
                                           <?php echo $settings['auto_fill'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="ai_auto_fill">
                                        Tự động điền sau khi AI xử lý xong
                                    </label>
                                </div>
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" id="ai_debug_mode" name="debug_mode"
                                           <?php echo $settings['debug_mode'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="ai_debug_mode">
                                        Debug mode (hiện raw response)
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Prompt Template — Phiếu mua / Nhập hàng -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="ai_prompt">
                                <i class="bx bx-file-find me-1 text-primary"></i>Prompt Template — Phiếu mua / Nhập hàng
                                <button type="button" class="btn btn-sm btn-link p-0 ms-2" id="resetPrompt">
                                    <i class="bx bx-reset"></i> Reset về mặc định
                                </button>
                            </label>
                            <textarea class="form-control" id="ai_prompt" name="prompt_template" rows="8"
                                      placeholder="Để trống để dùng prompt mặc định..."
                            ><?php echo esc_textarea($settings['prompt_template']); ?></textarea>
                            <div class="form-text">Prompt này dùng khi nhận diện sản phẩm từ <strong>phiếu mua hàng / nhập hàng</strong>.</div>
                        </div>

                        <hr>

                        <!-- Prompt Template — POS Bán hàng HTSoft -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="ai_pos_prompt">
                                <i class="bx bx-store me-1 text-warning"></i>Prompt Template — POS Bán hàng (Import HTSoft)
                                <button type="button" class="btn btn-sm btn-link p-0 ms-2" id="resetPosPrompt">
                                    <i class="bx bx-reset"></i> Reset về mặc định
                                </button>
                            </label>
                            <textarea class="form-control font-monospace" id="ai_pos_prompt" name="pos_prompt_template" rows="12"
                                      placeholder="Để trống để dùng prompt POS mặc định..."
                            ><?php echo esc_textarea($settings['pos_prompt_template']); ?></textarea>
                            <div class="form-text">
                                Prompt này dùng khi nhân viên bật chế độ <strong>"Import từ HTSoft"</strong> ở POS bán hàng.
                                Cần đọc được bảng chi tiết hóa đơn bán lẻ: Mã hàng, Tên hàng, SL, ĐVT, Đơn giá, CK(%), Thành tiền.
                                Output phải là JSON object: <code>{"items":[...],"customer":{"phone":"","name":""},"htsoft_total":0}</code>
                            </div>
                        </div>

                        <hr>

                        <!-- Prompt Template — Scan Phếu bán hàng in (bill/receipt) -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="ai_invoice_scan_prompt">
                                <i class="bx bx-receipt me-1 text-success"></i>Prompt Template — Scan Phếu bán hàng in (Bill/Receipt)
                                <button type="button" class="btn btn-sm btn-link p-0 ms-2" id="resetInvoiceScanPrompt">
                                    <i class="bx bx-reset"></i> Reset về mặc định
                                </button>
                            </label>
                            <textarea class="form-control font-monospace" id="ai_invoice_scan_prompt" name="invoice_scan_prompt_template" rows="12"
                                      placeholder="Để trống để dùng prompt phiếu bán hàng mặc định..."
                            ><?php echo esc_textarea($settings['invoice_scan_prompt_template']); ?></textarea>
                            <div class="form-text">
                                Dùng khi scan <strong>ảnh phiếu bán hàng in ra</strong> (bill/receipt) qua tab “Ảnh Phếu bán hàng” ở POS.
                                Khác HTSoft: cột CK là <strong>số tiền VNĐ</strong> (tự quy đổi sang %); mã hàng đọc từ dòng cuối ô “Tên/Mã hàng”.
                                Output JSON: <code>{"items":[...],"customer":{"phone":"","name":""},"htsoft_total":0}</code>
                            </div>
                        </div>

                        <!-- Save -->
                        <button type="submit" class="btn btn-primary" id="btnSaveAISettings">
                            <i class="bx bx-save me-1"></i>Lưu cấu hình
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar Info -->
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bx bx-info-circle me-1"></i>Hướng dẫn</h6>
                </div>
                <div class="card-body">
                    <p class="card-text small">
                        Plugin <strong>AI Nhận diện</strong> cho phép nhận diện sản phẩm từ:
                    </p>
                    <ul class="small">
                        <li><i class="bx bx-camera me-1"></i>Chụp ảnh (camera mobile)</li>
                        <li><i class="bx bx-image me-1"></i>Ảnh từ thư viện (PNG, JPG)</li>
                        <li><i class="bx bx-spreadsheet me-1"></i>File Excel (XLSX, XLS, CSV)</li>
                        <li><i class="bx bx-file me-1"></i>File PDF</li>
                    </ul>
                    <hr>
                    <p class="card-text small"><strong>Cách sử dụng:</strong></p>
                    <ol class="small">
                        <li>Lấy API key <strong>miễn phí</strong>:<br>
                            <strong class="text-success">OpenRouter (đọc ảnh ✅):</strong><br>
                            <a href="https://openrouter.ai/keys" target="_blank" class="text-primary">
                                openrouter.ai/keys
                            </a><br>
                            <strong>Groq (Excel/CSV):</strong><br>
                            <a href="https://console.groq.com/keys" target="_blank" class="text-primary">
                                console.groq.com/keys
                            </a>
                        </li>
                        <li>Chọn nhà cung cấp (<strong>OpenRouter</strong> nếu cần đọc ảnh)</li>
                        <li>Bật tính năng AI (toggle ở trên)</li>
                        <li>Paste API key vào ô bên trái</li>
                        <li>Nhấn <strong>Lưu cấu hình</strong></li>
                        <li>Vào trang <strong>Tạo phiếu mua hàng</strong></li>
                        <li>Nhấn nút <span class="badge bg-warning">AI nhận diện</span></li>
                    </ol>
                    <div class="alert alert-success py-2 mt-2 mb-0">
                        <small><i class="bx bx-image me-1"></i><strong>OpenRouter miễn phí (khuyên dùng):</strong> Đọc ảnh ✅, nhiều model vision miễn phí!</small>
                    </div>
                    <div class="alert alert-info py-2 mt-1 mb-0">
                        <small><i class="bx bx-rocket me-1"></i><strong>Groq miễn phí:</strong> 30 req/phút, nhanh nhưng chỉ đọc text/Excel.</small>
                    </div>
                    <div class="alert alert-secondary py-2 mt-1 mb-0">
                        <small><i class="bx bx-gift me-1"></i><strong>Gemini:</strong> Có thể bị giới hạn theo vùng VN.</small>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bx bx-key me-1"></i>API Key</h6>
                </div>
                <div class="card-body">
                    <?php if ($masked_keys): ?>
                        <?php $masked_key_lines = preg_split('/\r\n|\r|\n/', (string) $masked_keys); ?>
                        <?php $masked_key_lines = array_values(array_filter(array_map('trim', (array) $masked_key_lines))); ?>
                        <div class="alert alert-success mb-0 py-2">
                            <div class="small mb-1"><i class="bx bx-check-circle me-1"></i>Đã cấu hình <?php echo intval(count($masked_key_lines)); ?> API key:</div>
                            <?php if (!empty($masked_key_lines)): ?>
                                <ul class="mb-0 ps-3 small">
                                    <?php foreach ($masked_key_lines as $idx => $line): ?>
                                        <li><code><?php echo esc_html($line); ?></code></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning mb-0 py-2">
                            <small><i class="bx bx-error me-1"></i>Chưa cấu hình API key.</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Test Connection -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bx bx-check-shield me-1"></i>Kiểm tra kết nối</h6>
                </div>
                <div class="card-body">
                    <button type="button" class="btn btn-outline-info w-100" id="btnTestConnection">
                        <i class="bx bx-sync me-1"></i>Test kết nối AI
                    </button>
                    <div id="testResult" class="mt-2" style="display:none;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Settings JS -->
<script type="text/javascript">
jQuery(document).ready(function($) {
    var providers = <?php echo wp_json_encode($providers); ?>;
    var currentModel = '<?php echo esc_js($settings['model'] ?? 'gpt-4o'); ?>';

    // Toggle enabled label
    $('#ai_enabled').on('change', function() {
        $(this).next('label').text(this.checked ? 'Đang bật' : 'Đang tắt');
    });

    // Provider change → update model list + description
    function updateProviderUI(provider) {
        var info = providers[provider];
        if (!info) return;

        $('#providerDescription').text(info.description);

        // Update models
        var $model = $('#ai_model');
        $model.empty();
        if (info.models && info.models.length > 0) {
            info.models.forEach(function(m) {
                $model.append($('<option>', { value: m, text: m }));
            });
            $model.val(currentModel);
            $('#modelGroup').show();
        } else {
            $('#modelGroup').hide();
        }

        // Show/hide fetch models button
        $('#btnFetchModels').toggle(!!info.fetchable);
        $('#modelHint').text(info.fetchable ? 'Bấm "Tải danh sách model" để xem model mới nhất từ API.' : '');

        // Custom endpoint visibility
        $('#customEndpointGroup').toggle(provider === 'custom');
    }

    $('#ai_provider').on('change', function() {
        updateProviderUI($(this).val());
    });
    updateProviderUI($('#ai_provider').val());

    // Fetch models from API
    $('#btnFetchModels').on('click', function() {
        var $btn = $(this);
        var $spinner = $('#fetchModelSpinner');
        $btn.hide();
        $spinner.show();

        $.post(ajaxurl, {
            action: 'tgs_ai_fetch_models',
            nonce: '<?php echo $nonce; ?>',
            provider: $('#ai_provider').val()
        }, function(resp) {
            $spinner.hide();
            $btn.show();
            if (resp.success && resp.data.models) {
                var $model = $('#ai_model');
                var prev = $model.val();
                $model.empty();
                resp.data.models.forEach(function(m) {
                    var label = m;
                    if (m.indexOf('vision') !== -1) label += ' (👁 vision)';
                    $model.append($('<option>', { value: m, text: label }));
                });
                // Re‑select previous or first
                if (prev && $model.find('option[value="' + prev + '"]').length) {
                    $model.val(prev);
                }
                $('#modelHint').html('<span class="text-success">✅ Đã tải ' + resp.data.models.length + ' model. Chọn model có <strong>vision</strong> để đọc ảnh.</span>');
            } else {
                $('#modelHint').html('<span class="text-danger">❌ ' + (resp.data?.message || 'Lỗi') + '</span>');
            }
        }).fail(function() {
            $spinner.hide();
            $btn.show();
            $('#modelHint').html('<span class="text-danger">❌ Lỗi kết nối server</span>');
        });
    });

    // Reset prompt
    var defaultPrompt = <?php echo wp_json_encode(TGS_AI_Settings::get_default_prompt()); ?>;
    $('#resetPrompt').on('click', function() {
        $('#ai_prompt').val(defaultPrompt);
    });

    // Reset POS prompt
    var defaultPosPrompt = <?php echo wp_json_encode(TGS_AI_Settings::get_default_pos_prompt()); ?>;
    $('#resetPosPrompt').on('click', function() {
        $('#ai_pos_prompt').val(defaultPosPrompt);
    });

    // Reset Invoice Scan prompt
    var defaultInvoiceScanPrompt = <?php echo wp_json_encode(TGS_AI_Settings::get_default_invoice_scan_prompt()); ?>;
    $('#resetInvoiceScanPrompt').on('click', function() {
        $('#ai_invoice_scan_prompt').val(defaultInvoiceScanPrompt);
    });

    // Save Settings
    $('#aiSettingsForm').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#btnSaveAISettings');
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Đang lưu...');

        var formData = {
            action: 'tgs_ai_save_settings',
            nonce: '<?php echo $nonce; ?>',
            enabled: $('#ai_enabled').is(':checked'),
            provider: $('#ai_provider').val(),
            model: $('#ai_model').val(),
            max_file_size: $('#ai_max_file_size').val(),
            camera_enabled: $('#ai_camera_enabled').is(':checked'),
            auto_fill: $('#ai_auto_fill').is(':checked'),
            debug_mode: $('#ai_debug_mode').is(':checked'),
            prompt_template: $('#ai_prompt').val(),
            pos_prompt_template: $('#ai_pos_prompt').val(),
            invoice_scan_prompt_template: $('#ai_invoice_scan_prompt').val(),
            custom_endpoint: $('#ai_custom_endpoint').val()
        };

        // Only send API keys if user typed something
        var apiKeysVal = $('#ai_api_keys').val();
        if (apiKeysVal && apiKeysVal.trim()) {
            formData.api_keys = apiKeysVal;
        }

        $.post(ajaxurl, formData, function(resp) {
            $btn.prop('disabled', false).html('<i class="bx bx-save me-1"></i>Lưu cấu hình');
            if (resp.success) {
                alert('✅ ' + resp.data.message);
            } else {
                alert('❌ ' + (resp.data?.message || 'Lỗi không xác định'));
            }
        }).fail(function() {
            $btn.prop('disabled', false).html('<i class="bx bx-save me-1"></i>Lưu cấu hình');
            alert('❌ Lỗi kết nối server');
        });
    });

    // Test Connection
    $('#btnTestConnection').on('click', function() {
        var $btn = $(this);
        var $result = $('#testResult');
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Đang kiểm tra...');
        $result.hide();

        $.post(ajaxurl, {
            action: 'tgs_ai_test_connection',
            nonce: '<?php echo $nonce; ?>'
        }, function(resp) {
            $btn.prop('disabled', false).html('<i class="bx bx-sync me-1"></i>Test kết nối AI');
            if (resp.success) {
                $result.html('<div class="alert alert-success py-2 mb-0"><small>✅ ' + (resp.data?.message || 'Kết nối thành công!') + '</small></div>').show();
            } else {
                $result.html('<div class="alert alert-danger py-2 mb-0"><small>❌ ' + (resp.data?.message || 'Lỗi') + '</small></div>').show();
            }
        }).fail(function() {
            $btn.prop('disabled', false).html('<i class="bx bx-sync me-1"></i>Test kết nối AI');
            $result.html('<div class="alert alert-danger py-2 mb-0"><small>❌ Lỗi kết nối server</small></div>').show();
        });
    });
});
</script>
