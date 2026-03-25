/**
 * TicketAIRecognition - Nhận diện sản phẩm bằng AI từ ảnh/file
 *
 * Upload file → AI phân tích → validate SKU → fill vào phiếu
 * Dùng cho cả khối "Danh sách sản phẩm" (main) và "Hàng tặng kèm" (gift)
 *
 * @requires jQuery
 * @requires Bootstrap 5
 */

class TicketAIRecognition {
    constructor(options = {}) {
        this.ajaxUrl = options.ajaxUrl || window.TGS_AI_CONFIG?.ajaxUrl || '';
        this.nonce = options.nonce || window.TGS_AI_CONFIG?.nonce || '';
        this.ticketInstance = options.ticketInstance || null;
        this.excelImportInstance = options.excelImportInstance || null;

        // State
        this.targetBlock = 'main'; // 'main' or 'gift'
        this.selectedFile = null;
        this.aiProducts = [];       // Products returned by AI
        this.validatedProducts = []; // Products after SKU validation
        this.isProcessing = false;

        // Config from server
        this.maxFileSize = (window.TGS_AI_CONFIG?.maxFileSize || 10) * 1024 * 1024;
        this.enabled = window.TGS_AI_CONFIG?.enabled || false;

        this.modal = null;
        this.elements = {};

        this.init();
    }

    init() {
        const modalEl = document.getElementById('ticketAIRecognitionModal');
        if (!modalEl) {
            console.warn('TicketAIRecognition: Modal not found. AI plugin may not be active.');
            return;
        }

        this.modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        // Cache elements
        this.elements = {
            targetLabel: document.getElementById('aiTargetLabel'),
            // Step 1 - Input
            step1: document.getElementById('aiStep1_input'),
            cameraBtn: document.getElementById('aiCameraBtn'),
            cameraInput: document.getElementById('aiCameraInput'),
            imageInput: document.getElementById('aiImageInput'),
            fileInput: document.getElementById('aiFileInput'),
            dropZone: document.getElementById('aiDropZone'),
            maxSize: document.getElementById('aiMaxSize'),
            filePreview: document.getElementById('aiFilePreview'),
            previewThumb: document.getElementById('aiPreviewThumb'),
            fileName: document.getElementById('aiFileName'),
            fileInfo: document.getElementById('aiFileInfo'),
            removeFile: document.getElementById('aiRemoveFile'),
            // Step 2 - Processing
            step2: document.getElementById('aiStep2_processing'),
            processingStatus: document.getElementById('aiProcessingStatus'),
            processingDetail: document.getElementById('aiProcessingDetail'),
            progressBar: document.getElementById('aiProgressBar'),
            // Step 3 - Results
            step3: document.getElementById('aiStep3_results'),
            resultSummary: document.getElementById('aiResultSummary'),
            resultsBody: document.getElementById('aiResultsBody'),
            selectAll: document.getElementById('aiSelectAll'),
            rawResponse: document.getElementById('aiRawResponse'),
            rawText: document.getElementById('aiRawText'),
            // Error
            errorDisplay: document.getElementById('aiErrorDisplay'),
            errorMessage: document.getElementById('aiErrorMessage'),
            errorRaw: document.getElementById('aiErrorRaw'),
            errorRawText: document.getElementById('aiErrorRawText'),
            // Buttons
            processBtn: document.getElementById('aiProcessBtn'),
            confirmBtn: document.getElementById('aiConfirmBtn'),
            retryBtn: document.getElementById('aiRetryBtn'),
        };

        // Update max size display
        if (this.elements.maxSize) {
            this.elements.maxSize.textContent = window.TGS_AI_CONFIG?.maxFileSize || 10;
        }

        // Hide camera button on desktop
        if (this.elements.cameraBtn && !this.isMobileDevice()) {
            this.elements.cameraBtn.style.display = 'none';
        }

        this.bindEvents();
    }

    bindEvents() {
        const $ = jQuery;

        // File inputs
        if (this.elements.cameraInput) {
            this.elements.cameraInput.addEventListener('change', (e) => this.handleFileSelect(e.target.files[0]));
        }
        if (this.elements.imageInput) {
            this.elements.imageInput.addEventListener('change', (e) => this.handleFileSelect(e.target.files[0]));
        }
        if (this.elements.fileInput) {
            this.elements.fileInput.addEventListener('change', (e) => this.handleFileSelect(e.target.files[0]));
        }

        // Drag & Drop
        if (this.elements.dropZone) {
            const dz = this.elements.dropZone;
            dz.addEventListener('dragover', (e) => {
                e.preventDefault();
                dz.style.borderColor = '#ffc107';
                dz.style.backgroundColor = '#fff8e1';
            });
            dz.addEventListener('dragleave', () => {
                dz.style.borderColor = '';
                dz.style.backgroundColor = '';
            });
            dz.addEventListener('drop', (e) => {
                e.preventDefault();
                dz.style.borderColor = '';
                dz.style.backgroundColor = '';
                if (e.dataTransfer.files.length > 0) {
                    this.handleFileSelect(e.dataTransfer.files[0]);
                }
            });
        }

        // Remove file
        if (this.elements.removeFile) {
            this.elements.removeFile.addEventListener('click', () => this.removeFile());
        }

        // Process button
        if (this.elements.processBtn) {
            this.elements.processBtn.addEventListener('click', () => this.processFile());
        }

        // Confirm button
        if (this.elements.confirmBtn) {
            this.elements.confirmBtn.addEventListener('click', () => this.confirmAndFill());
        }

        // Retry button
        if (this.elements.retryBtn) {
            this.elements.retryBtn.addEventListener('click', () => this.retry());
        }

        // Select all checkbox
        if (this.elements.selectAll) {
            this.elements.selectAll.addEventListener('change', (e) => {
                const checkboxes = this.elements.resultsBody.querySelectorAll('.ai-row-check');
                checkboxes.forEach(cb => { cb.checked = e.target.checked; });
            });
        }

        // Reset on modal close
        const modalEl = document.getElementById('ticketAIRecognitionModal');
        if (modalEl) {
            modalEl.addEventListener('hidden.bs.modal', () => this.reset());
        }
    }

    // ===== Open methods =====

    openForMain() {
        this.targetBlock = 'main';
        if (this.elements.targetLabel) this.elements.targetLabel.textContent = '(Sản phẩm chính)';
        this.reset();
        this.modal.show();
    }

    openForGift() {
        this.targetBlock = 'gift';
        if (this.elements.targetLabel) this.elements.targetLabel.textContent = '(Hàng tặng kèm)';
        this.reset();
        this.modal.show();
    }

    // ===== File handling =====

    handleFileSelect(file) {
        if (!file) return;

        // Validate size
        if (file.size > this.maxFileSize) {
            alert('File quá lớn! Tối đa ' + (window.TGS_AI_CONFIG?.maxFileSize || 10) + 'MB.');
            return;
        }

        this.selectedFile = file;
        this.showFilePreview(file);
        this.showButton('process');
    }

    showFilePreview(file) {
        if (!this.elements.filePreview) return;

        this.elements.filePreview.style.display = '';
        this.elements.fileName.textContent = file.name;
        this.elements.fileInfo.textContent = this.formatFileSize(file.size) + ' • ' + file.type;

        // Show thumbnail for images
        if (file.type.startsWith('image/')) {
            // Revoke previous objectURL if exists
            if (this._previewUrl) URL.revokeObjectURL(this._previewUrl);
            this._previewUrl = URL.createObjectURL(file);
            this.elements.previewThumb.innerHTML =
                '<img src="' + this._previewUrl + '" alt="Preview" style="width:48px;height:48px;object-fit:cover;border-radius:4px;">';
        } else if (file.name.match(/\.xlsx?$/i)) {
            this.elements.previewThumb.innerHTML = '<i class="bx bx-spreadsheet" style="font-size:2rem;color:#217346;"></i>';
        } else if (file.name.match(/\.pdf$/i)) {
            this.elements.previewThumb.innerHTML = '<i class="bx bx-file" style="font-size:2rem;color:#dc3545;"></i>';
        } else if (file.name.match(/\.csv$/i)) {
            this.elements.previewThumb.innerHTML = '<i class="bx bx-table" style="font-size:2rem;color:#198754;"></i>';
        } else {
            this.elements.previewThumb.innerHTML = '<i class="bx bx-file" style="font-size:2rem;"></i>';
        }
    }

    removeFile() {
        this.selectedFile = null;
        if (this.elements.filePreview) this.elements.filePreview.style.display = 'none';
        // Reset file inputs
        [this.elements.cameraInput, this.elements.imageInput, this.elements.fileInput].forEach(input => {
            if (input) input.value = '';
        });
        this.showButton('none');
    }

    // ===== AI Processing =====

    processFile() {
        if (!this.selectedFile || this.isProcessing) return;

        this.isProcessing = true;
        this.showStep('processing');
        this.showButton('none');

        // Fake progress animation
        let progress = 0;
        const progressInterval = setInterval(() => {
            progress += Math.random() * 15;
            if (progress > 90) progress = 90;
            this.updateProgress(progress);
        }, 500);

        // Build FormData
        const formData = new FormData();
        formData.append('action', 'tgs_ai_process_file');
        formData.append('nonce', this.nonce);
        formData.append('file', this.selectedFile);

        jQuery.ajax({
            url: this.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 180000, // 3 minutes
            success: (resp) => {
                clearInterval(progressInterval);
                this.updateProgress(100);
                this.isProcessing = false;

                if (resp.success && resp.data?.products) {
                    this.aiProducts = resp.data.products;
                    if (resp.data.products.length === 0) {
                        let errMsg = 'AI không nhận diện được sản phẩm nào.';
                        if (resp.data.raw_response) {
                            errMsg += '\n\nRaw AI response:\n' + resp.data.raw_response.substring(0, 500);
                        }
                        this.showError(errMsg);
                        return;
                    }
                    // Now validate SKUs against database
                    this.validateSkus(resp.data.products);
                } else {
                    this.showError(resp.data?.message || 'AI không trả về kết quả.', resp.data?.raw_response);
                }
            },
            error: (xhr) => {
                clearInterval(progressInterval);
                this.isProcessing = false;
                this.showError('Lỗi kết nối server. Vui lòng thử lại.');
            }
        });
    }

    /**
     * Validate SKUs against database (reuse Excel import AJAX)
     */
    validateSkus(products) {
        if (!products || products.length === 0) {
            this.showError('AI không nhận diện được sản phẩm nào.');
            return;
        }

        this.updateProcessingStatus('Đang kiểm tra mã SKU trong hệ thống...');

        const skus = products.map(p => p.sku).filter(s => s);
        if (skus.length === 0) {
            // Không có SKU nào → hiển thị trực tiếp kết quả để user tự nhập SKU
            this.buildResults(products, { products: {} });
            return;
        }

        jQuery.ajax({
            url: this.ajaxUrl,
            type: 'POST',
            data: {
                action: 'tgs_ticket_excel_check_skus',
                nonce: window.tgsTicketAdmin?.nonce || '',
                skus: JSON.stringify(skus)
            },
            success: (resp) => {
                if (resp.success) {
                    this.buildResults(products, resp.data);
                } else {
                    this.showError('Lỗi kiểm tra SKU: ' + (resp.data?.message || ''));
                }
            },
            error: () => {
                this.showError('Lỗi kết nối khi kiểm tra SKU.');
            }
        });
    }

    /**
     * Build results table combining AI output + DB validation
     */
    buildResults(aiProducts, dbData) {
        const dbProducts = dbData.products || {};
        const missingSkus = dbData.missing_skus || [];

        this.validatedProducts = [];
        let html = '';
        let foundCount = 0;
        let missingCount = 0;

        aiProducts.forEach((item, idx) => {
            const sku = item.sku;
            const dbProduct = dbProducts[sku];
            const exists = !!dbProduct;

            if (exists) {
                foundCount++;
                this.validatedProducts.push({
                    product: dbProduct,
                    quantity: item.quantity || 0,
                    lotCode: item.lot_code || '',
                    expDate: item.exp_date || '',
                    note: item.note || '',
                });
            } else {
                missingCount++;
            }

            const statusBadge = exists
                ? '<span class="badge bg-success">Có trong DB</span>'
                : '<span class="badge bg-danger">Không tìm thấy</span>';

            const checkedAttr = exists ? 'checked' : 'disabled';

            html += '<tr class="' + (exists ? '' : 'table-danger') + '">'
                + '<td><input type="checkbox" class="form-check-input ai-row-check" data-idx="' + idx + '" ' + checkedAttr + '></td>'
                + '<td><code>' + this.escapeHtml(sku) + '</code></td>'
                + '<td>' + this.escapeHtml(exists ? dbProduct.name : (item.name || '')) + '</td>'
                + '<td>' + this.escapeHtml(exists ? (dbProduct.unit || '') : (item.unit || '')) + '</td>'
                + '<td>' + (item.quantity || 0) + '</td>'
                + '<td>' + this.escapeHtml(item.lot_code || '') + '</td>'
                + '<td>' + this.escapeHtml(item.exp_date || '') + '</td>'
                + '<td>' + statusBadge + '</td>'
                + '</tr>';
        });

        this.elements.resultsBody.innerHTML = html;

        const summaryText = 'AI nhận diện: <strong>' + aiProducts.length + '</strong> sản phẩm. '
            + 'Có trong DB: <strong>' + foundCount + '</strong>. '
            + (missingCount > 0 ? 'Không tìm thấy: <strong class="text-danger">' + missingCount + '</strong>.' : '');
        this.elements.resultSummary.innerHTML = summaryText;

        this.showStep('results');
        this.showButton(foundCount > 0 ? 'confirm' : 'retry');

        // Show retry alongside confirm if there are missing items
        if (foundCount > 0 && missingCount > 0) {
            this.elements.retryBtn.style.display = '';
        }
    }

    /**
     * Confirm and fill products into the ticket form
     */
    confirmAndFill() {
        if (!this.ticketInstance) {
            alert('Lỗi: Không tìm thấy instance phiếu.');
            return;
        }

        // Collect checked row indices
        const checkedIdxs = new Set();
        this.elements.resultsBody.querySelectorAll('.ai-row-check:checked').forEach(cb => {
            checkedIdxs.add(parseInt(cb.dataset.idx));
        });

        // Map validated products back to their original AI index
        let validIdx = 0;
        const itemsToFill = [];
        this.aiProducts.forEach((aiItem, originalIdx) => {
            const sku = aiItem.sku;
            const vp = this.validatedProducts.find(v => v.product.sku === sku);
            if (vp && checkedIdxs.has(originalIdx)) {
                itemsToFill.push(vp);
            }
        });

        if (itemsToFill.length === 0) {
            alert('Chưa chọn sản phẩm nào để nhập.');
            return;
        }

        const isGift = this.targetBlock === 'gift';

        for (const item of itemsToFill) {
            const product = item.product;

            if (isGift) {
                if (typeof this.ticketInstance.addGiftProductRow === 'function') {
                    this.ticketInstance.addGiftProductRow(product);
                }
            } else {
                // Push to selectedProducts first
                if (Array.isArray(this.ticketInstance.selectedProducts)) {
                    this.ticketInstance.selectedProducts.push(product);
                }
                if (typeof this.ticketInstance.addProductRow === 'function') {
                    this.ticketInstance.addProductRow(product);
                }
            }

            // Fill additional data (quantity, lot, exp date, note)
            this.fillDataToRow(product, item, isGift);
        }

        // Update totals
        if (typeof this.ticketInstance.updateTotals === 'function') {
            this.ticketInstance.updateTotals();
        }

        this.modal.hide();
    }

    /**
     * Fill data into the newly added row
     */
    fillDataToRow(product, item, isGift) {
        const $ = jQuery;
        const tableId = isGift ? '#ticketGiftProductsTableBody' : '#ticketProductsTableBody';
        const $rows = $(`${tableId} tr[data-product-id="${product.id}"]`);
        const $row = $rows.last();

        if (!$row.length) return;

        // Set quantity
        if (item.quantity > 0) {
            const qtyClass = isGift ? '.ticket-gift-item-quantity' : '.ticket-item-quantity';
            const $qtyInput = $row.find(qtyClass);
            if ($qtyInput.length) {
                $qtyInput.val(item.quantity).trigger('input');
            }
        }

        // Set note
        if (item.note) {
            const noteClass = isGift ? '.ticket-gift-item-note' : '.ticket-item-note';
            $row.find(noteClass).val(item.note);
        }

        // Set lot code
        if (item.lotCode) {
            const lotClass = isGift ? '.ticket-gift-lot-code' : '.ticket-lot-code';
            $row.find(lotClass).val(item.lotCode);
        }

        // Set exp date
        if (item.expDate) {
            const hsdClass = isGift ? '.ticket-gift-exp-date' : '.ticket-exp-date';
            $row.find(hsdClass).val(item.expDate);
        }

        // Set discount = 100% for gift products
        if (isGift) {
            const $discountInput = $row.find('.ticket-gift-discount-value');
            if ($discountInput.length) {
                $discountInput.val(100).trigger('input');
            }
        }
    }

    // ===== UI Helpers =====

    showStep(step) {
        const steps = ['input', 'processing', 'results'];
        steps.forEach(s => {
            const el = document.getElementById('aiStep' + ({'input':'1_input','processing':'2_processing','results':'3_results'}[s]));
            if (el) el.style.display = (s === step) ? '' : 'none';
        });
        // Hide error when showing a step
        if (this.elements.errorDisplay) this.elements.errorDisplay.style.display = 'none';
    }

    showButton(type) {
        const btns = ['processBtn', 'confirmBtn', 'retryBtn'];
        btns.forEach(b => {
            if (this.elements[b]) this.elements[b].style.display = 'none';
        });
        if (type === 'process' && this.elements.processBtn) this.elements.processBtn.style.display = '';
        if (type === 'confirm' && this.elements.confirmBtn) this.elements.confirmBtn.style.display = '';
        if (type === 'retry' && this.elements.retryBtn) this.elements.retryBtn.style.display = '';
    }

    showError(message, rawResponse) {
        this.showStep('none'); // hide all steps
        // Actually show error display
        if (this.elements.errorDisplay) this.elements.errorDisplay.style.display = '';
        if (this.elements.errorMessage) this.elements.errorMessage.textContent = message;
        if (rawResponse && this.elements.errorRaw) {
            this.elements.errorRaw.style.display = '';
            this.elements.errorRawText.textContent = rawResponse;
        }
        this.showButton('retry');
    }

    updateProgress(pct) {
        if (this.elements.progressBar) {
            this.elements.progressBar.style.width = Math.min(pct, 100) + '%';
        }
    }

    updateProcessingStatus(text) {
        if (this.elements.processingStatus) this.elements.processingStatus.textContent = text;
    }

    retry() {
        this.aiProducts = [];
        this.validatedProducts = [];
        this.showStep('input');
        this.showButton(this.selectedFile ? 'process' : 'none');
        if (this.elements.errorDisplay) this.elements.errorDisplay.style.display = 'none';
    }

    reset() {
        this.selectedFile = null;
        this.aiProducts = [];
        this.validatedProducts = [];
        this.isProcessing = false;
        this.showStep('input');
        this.showButton('none');

        // Reset file inputs
        [this.elements.cameraInput, this.elements.imageInput, this.elements.fileInput].forEach(input => {
            if (input) input.value = '';
        });
        if (this.elements.filePreview) this.elements.filePreview.style.display = 'none';
        // Cleanup objectURL
        if (this._previewUrl) { URL.revokeObjectURL(this._previewUrl); this._previewUrl = null; }
        if (this.elements.resultsBody) this.elements.resultsBody.innerHTML = '';
        if (this.elements.errorDisplay) this.elements.errorDisplay.style.display = 'none';
        if (this.elements.rawResponse) this.elements.rawResponse.style.display = 'none';
        this.updateProgress(0);
    }

    // ===== Utilities =====

    isMobileDevice() {
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    }

    formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
