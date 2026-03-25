<?php
/**
 * AI Recognition Modal - Modal nhận diện sản phẩm bằng AI
 *
 * Được inject vào trang tạo phiếu qua hook tgs_ticket_create_after_modals
 *
 * @package tgs_ai_recognition
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<!-- Modal AI nhận diện sản phẩm -->
<div class="modal fade" id="ticketAIRecognitionModal" tabindex="-1" aria-labelledby="ticketAIRecognitionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title" id="ticketAIRecognitionModalLabel">
                    <i class="bx bx-bot me-2"></i>AI nhận diện sản phẩm
                    <small class="text-muted ms-2" id="aiTargetLabel"></small>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <div class="modal-body">
                <!-- Step 1: Chọn file / chụp ảnh -->
                <div id="aiStep1_input">
                    <div class="text-center mb-3">
                        <p class="text-muted mb-3">Chọn cách nhập liệu:</p>
                        <div class="d-flex flex-wrap justify-content-center gap-2">
                            <!-- Camera (mobile) -->
                            <label class="btn btn-outline-primary" id="aiCameraBtn">
                                <i class="bx bx-camera me-1"></i>Chụp ảnh
                                <input type="file" accept="image/*" capture="environment" id="aiCameraInput"
                                       class="d-none">
                            </label>
                            <!-- Chọn ảnh từ thư viện -->
                            <label class="btn btn-outline-info">
                                <i class="bx bx-image me-1"></i>Chọn ảnh
                                <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" id="aiImageInput"
                                       class="d-none">
                            </label>
                            <!-- Upload file (Excel/PDF) -->
                            <label class="btn btn-outline-success">
                                <i class="bx bx-upload me-1"></i>Upload file
                                <input type="file" accept=".xlsx,.xls,.csv,.pdf" id="aiFileInput"
                                       class="d-none">
                            </label>
                        </div>
                    </div>

                    <!-- Drag & Drop Zone -->
                    <div id="aiDropZone" class="border border-2 border-dashed rounded text-center p-4 mb-3"
                         style="cursor:pointer; transition: all 0.2s;">
                        <i class="bx bx-cloud-upload" style="font-size: 3rem; color: #adb5bd;"></i>
                        <p class="text-muted mt-2 mb-0">Kéo thả file vào đây</p>
                        <small class="text-muted">Hỗ trợ: PNG, JPG, Excel, CSV, PDF (tối đa <span id="aiMaxSize">10</span>MB)</small>
                    </div>

                    <!-- File Preview -->
                    <div id="aiFilePreview" style="display:none;" class="mb-3">
                        <div class="card">
                            <div class="card-body py-2 d-flex align-items-center">
                                <div id="aiPreviewThumb" class="me-3">
                                    <i class="bx bx-file" style="font-size: 2rem;"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <strong id="aiFileName"></strong>
                                    <br><small class="text-muted" id="aiFileInfo"></small>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-danger" id="aiRemoveFile">
                                    <i class="bx bx-x"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2: AI Processing -->
                <div id="aiStep2_processing" style="display:none;">
                    <div class="text-center py-4">
                        <div class="spinner-border text-warning mb-3" role="status" style="width: 3rem; height: 3rem;">
                            <span class="visually-hidden">Đang xử lý...</span>
                        </div>
                        <h6 id="aiProcessingStatus">AI đang phân tích...</h6>
                        <p class="text-muted small" id="aiProcessingDetail">Vui lòng chờ trong giây lát</p>
                        <div class="progress mt-3" style="height: 4px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning"
                                 id="aiProgressBar" style="width: 0%"></div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Kết quả AI -->
                <div id="aiStep3_results" style="display:none;">
                    <div class="alert alert-info py-2 mb-3" id="aiResultSummary"></div>

                    <!-- Bảng kết quả -->
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-hover" id="aiResultsTable">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width:40px;">
                                        <input type="checkbox" class="form-check-input" id="aiSelectAll" checked>
                                    </th>
                                    <th>SKU</th>
                                    <th>Tên sản phẩm</th>
                                    <th>ĐVT</th>
                                    <th style="width:80px;">SL</th>
                                    <th>Mã lô</th>
                                    <th>HSD</th>
                                    <th>Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody id="aiResultsBody">
                            </tbody>
                        </table>
                    </div>

                    <!-- Raw response (debug mode) -->
                    <div id="aiRawResponse" style="display:none;" class="mt-3">
                        <details>
                            <summary class="text-muted small">Raw AI Response</summary>
                            <pre class="bg-light p-2 small" style="max-height: 200px; overflow: auto;"
                                 id="aiRawText"></pre>
                        </details>
                    </div>
                </div>

                <!-- Error display -->
                <div id="aiErrorDisplay" style="display:none;">
                    <div class="alert alert-danger" id="aiErrorMessage"></div>
                    <div id="aiErrorRaw" style="display:none;">
                        <details>
                            <summary class="text-muted small">Chi tiết lỗi</summary>
                            <pre class="bg-light p-2 small" style="max-height: 150px; overflow: auto;"
                                 id="aiErrorRawText"></pre>
                        </details>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                <button type="button" class="btn btn-warning" id="aiProcessBtn" style="display:none;">
                    <i class="bx bx-analyse me-1"></i>Gửi AI phân tích
                </button>
                <button type="button" class="btn btn-primary" id="aiConfirmBtn" style="display:none;">
                    <i class="bx bx-check me-1"></i>Xác nhận & Điền vào phiếu
                </button>
                <button type="button" class="btn btn-outline-warning" id="aiRetryBtn" style="display:none;">
                    <i class="bx bx-refresh me-1"></i>Thử lại
                </button>
            </div>
        </div>
    </div>
</div>
