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
        this.selectedFiles = [];    // Array<File> — supports multiple images
        this.aiProducts = [];       // Products returned by AI
        this.validatedProducts = []; // Products after SKU validation
        this.isProcessing = false;
        this._previewUrls = [];     // objectURLs for image thumbnails

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
            this.elements.cameraInput.addEventListener('change', (e) => {
                if (e.target.files[0]) this.handleFilesAdd([e.target.files[0]]);
            });
        }
        if (this.elements.imageInput) {
            this.elements.imageInput.addEventListener('change', (e) => {
                if (e.target.files.length) this.handleFilesAdd(Array.from(e.target.files));
            });
        }
        if (this.elements.fileInput) {
            this.elements.fileInput.addEventListener('change', (e) => {
                if (e.target.files[0]) this.handleFilesAdd([e.target.files[0]]);
            });
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
                    this.handleFilesAdd(Array.from(e.dataTransfer.files));
                }
            });
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

    handleFilesAdd(files) {
        const fileArr = Array.from(files).filter(f => f);
        if (!fileArr.length) return;

        for (const file of fileArr) {
            if (file.size > this.maxFileSize) {
                alert('File "' + file.name + '" quá lớn! Tối đa ' + (window.TGS_AI_CONFIG?.maxFileSize || 10) + 'MB.');
                continue;
            }
            const isImage = file.type.startsWith('image/');
            if (!isImage) {
                // Excel/CSV/PDF: single file only — replaces everything
                this._clearPreviewUrls();
                this.selectedFiles = [file];
                this.renderFilePreviews();
                this.showButton('process');
                return;
            }
            // Images: accumulate, skip exact duplicates
            const isDup = this.selectedFiles.some(f => f.name === file.name && f.size === file.size);
            if (!isDup) this.selectedFiles.push(file);
        }

        this.renderFilePreviews();
        if (this.selectedFiles.length > 0) this.showButton('process');
    }

    renderFilePreviews() {
        if (!this.elements.filePreview) return;

        if (this.selectedFiles.length === 0) {
            this.elements.filePreview.style.display = 'none';
            this.elements.filePreview.innerHTML = '';
            return;
        }

        this.elements.filePreview.style.display = '';
        let html = '<div class="d-flex flex-wrap gap-2 align-items-start">';

        this.selectedFiles.forEach((file, idx) => {
            const isImage = file.type.startsWith('image/');
            let thumbHtml;
            if (isImage) {
                if (!this._previewUrls[idx]) {
                    this._previewUrls[idx] = URL.createObjectURL(file);
                }
                thumbHtml = '<img src="' + this._previewUrls[idx] + '" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:6px;">';
            } else if (file.name.match(/\.xlsx?$/i)) {
                thumbHtml = '<i class="bx bx-spreadsheet" style="font-size:2.5rem;color:#217346;"></i>';
            } else if (file.name.match(/\.pdf$/i)) {
                thumbHtml = '<i class="bx bx-file" style="font-size:2.5rem;color:#dc3545;"></i>';
            } else {
                thumbHtml = '<i class="bx bx-table" style="font-size:2.5rem;color:#198754;"></i>';
            }
            const shortName = file.name.length > 16 ? file.name.substring(0, 13) + '...' : file.name;
            html += '<div class="position-relative text-center" style="width:72px;">'
                + '<div class="border rounded d-flex align-items-center justify-content-center bg-light" style="width:72px;height:72px;">' + thumbHtml + '</div>'
                + '<div class="text-muted mt-1" style="font-size:10px;line-height:1.2;">' + this.escapeHtml(shortName) + '</div>'
                + '<button type="button" class="btn btn-danger position-absolute ai-remove-thumb"'
                + ' data-idx="' + idx + '" style="top:-6px;right:-6px;width:20px;height:20px;padding:0;font-size:11px;line-height:1;border-radius:50%;">×</button>'
                + '</div>';
        });

        html += '</div>';
        if (this.selectedFiles.length > 1) {
            html += '<div class="mt-2 text-muted small"><i class="bx bx-images me-1"></i>'
                + this.selectedFiles.length + ' ảnh — gửi cùng lúc tới AI để nhận diện chính xác hơn</div>';
        }

        this.elements.filePreview.innerHTML = html;
        this.elements.filePreview.querySelectorAll('.ai-remove-thumb').forEach(btn => {
            btn.addEventListener('click', () => this.removeFileAt(parseInt(btn.dataset.idx)));
        });
    }

    removeFileAt(idx) {
        if (this._previewUrls[idx]) URL.revokeObjectURL(this._previewUrls[idx]);
        this.selectedFiles.splice(idx, 1);
        this._previewUrls.splice(idx, 1);
        // Reset input values so same file can be re-selected
        [this.elements.cameraInput, this.elements.imageInput, this.elements.fileInput].forEach(input => {
            if (input) input.value = '';
        });
        this.renderFilePreviews();
        if (this.selectedFiles.length === 0) this.showButton('none');
    }

    _clearPreviewUrls() {
        this._previewUrls.forEach(url => url && URL.revokeObjectURL(url));
        this._previewUrls = [];
    }

    // ===== AI Processing =====

    processFile() {
        if (!this.selectedFiles.length || this.isProcessing) return;

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
        if (this.selectedFiles.length === 1) {
            formData.append('file', this.selectedFiles[0]);
        } else {
            this.selectedFiles.forEach(f => formData.append('files[]', f));
        }

        jQuery.ajax({
            url: this.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 660000, // 11 minutes (5 models × 120s + sleeps)
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

        this.updateProcessingStatus('Đang kiểm tra SKU/Tên sản phẩm trong hệ thống...');

        const items = products.map((p, idx) => ({
            index: idx,
            sku: p.sku || '',
            name: p.name || ''
        })).filter(item => item.sku || item.name);

        if (items.length === 0) {
            this.buildResults(products, { products: {}, missing_skus: [], matched_by_index: {} });
            return;
        }

        const skus = items.map(item => item.sku).filter(Boolean);

        jQuery.ajax({
            url: this.ajaxUrl,
            type: 'POST',
            data: {
                action: 'tgs_ticket_excel_check_products',
                nonce: window.tgsTicketAdmin?.nonce || '',
                items: JSON.stringify(items)
            },
            success: (resp) => {
                if (resp.success) {
                    this.buildResults(products, resp.data);
                } else {
                    // Lỗi check SKU → vẫn hiển thị kết quả AI, cảnh báo lỗi
                    console.warn('SKU check failed:', resp.data?.message);
                    this.buildResults(products, {
                        products: {},
                        missing_skus: skus,
                        matched_by_index: {},
                        _warning: 'Không thể kiểm tra SKU/Tên: ' + (resp.data?.message || 'Lỗi không xác định')
                    });
                }
            },
            error: () => {
                // Lỗi kết nối → vẫn hiển thị kết quả AI
                console.warn('SKU check connection error');
                this.buildResults(products, {
                    products: {},
                    missing_skus: skus,
                    matched_by_index: {},
                    _warning: 'Lỗi kết nối khi kiểm tra SKU/Tên. Sản phẩm vẫn hiển thị để bạn xử lý thủ công.'
                });
            }
        });
    }

    /**
     * Build results table combining AI output + DB validation
     */
    buildResults(aiProducts, dbData) {
        const dbProducts = dbData.products || {};
        const matchedByIndex = dbData.matched_by_index || {};
        const missingSkus = dbData.missing_skus || [];
        const warning = dbData._warning || '';

        this.validatedProducts = [];
        let html = '';
        let foundCount = 0;
        let missingCount = 0;

        aiProducts.forEach((item, idx) => {
            const sku = item.sku || '';
            const matchMeta = matchedByIndex[idx] || matchedByIndex[String(idx)] || null;
            const dbProduct = (matchMeta && matchMeta.product)
                ? matchMeta.product
                : (sku ? dbProducts[sku] : null);
            const exists = !!dbProduct;
            const matchedByName = !!(matchMeta && matchMeta.match_type === 'name');

            if (exists) {
                foundCount++;
                this.validatedProducts.push({
                    product: dbProduct,
                    aiItem: item,
                    quantity: item.quantity || 1,
                    lotCode: item.lot_code || '',
                    expDate: item.exp_date || '',
                    note: item.note || '',
                    matched: true,
                    originalIdx: idx,
                });
            } else {
                missingCount++;
                // Vẫn thêm vào validatedProducts nhưng đánh dấu unmatched
                this.validatedProducts.push({
                    product: { id: 0, sku: sku, name: item.name || '', unit: item.unit || '' },
                    aiItem: item,
                    quantity: item.quantity || 1,
                    lotCode: item.lot_code || '',
                    expDate: item.exp_date || '',
                    note: item.note || '',
                    matched: false,
                    originalIdx: idx,
                });
            }

            const statusBadge = exists
                ? (matchedByName
                    ? '<span class="badge bg-info">✓ Khớp theo tên</span>'
                    : '<span class="badge bg-success">✓ Có trong DB</span>')
                : (sku ? '<span class="badge bg-warning text-dark">⚠ Chưa khớp DB</span>' : '<span class="badge bg-secondary">Không có SKU</span>');

            // Sản phẩm khớp DB → checked mặc định, chưa khớp → unchecked nhưng vẫn cho tick
            const checkedAttr = exists ? 'checked' : '';

            html += '<tr class="' + (exists ? '' : 'table-warning') + '">'
                + '<td><input type="checkbox" class="form-check-input ai-row-check" data-idx="' + idx + '" ' + checkedAttr + '></td>'
                + '<td><code>' + this.escapeHtml(sku || '-') + '</code></td>'
                + '<td>' + this.escapeHtml(exists ? dbProduct.name : (item.name || '')) + '</td>'
                + '<td>' + this.escapeHtml(exists ? (dbProduct.unit || '') : (item.unit || '')) + '</td>'
                + '<td>' + (item.quantity || 1) + '</td>'
                + '<td>' + this.escapeHtml(item.lot_code || '') + '</td>'
                + '<td>' + this.escapeHtml(item.exp_date || '') + '</td>'
                + '<td>' + statusBadge + '</td>'
                + '</tr>';
        });

        this.elements.resultsBody.innerHTML = html;

        let summaryHtml = 'AI nhận diện: <strong>' + aiProducts.length + '</strong> sản phẩm. '
            + 'Khớp DB: <strong class="text-success">' + foundCount + '</strong>. ';
        if (missingCount > 0) {
            summaryHtml += 'Chưa khớp: <strong class="text-warning">' + missingCount + '</strong>. ';
        }
        if (warning) {
            summaryHtml += '<br><span class="text-danger"><i class="bx bx-error"></i> ' + this.escapeHtml(warning) + '</span>';
        }
        if (foundCount > 0 && missingCount > 0) {
            summaryHtml += '<br><small class="text-muted">Sản phẩm khớp DB đã được chọn sẵn. Tick thêm nếu muốn nhập thủ công sản phẩm chưa khớp.</small>';
        }

        this.elements.resultSummary.innerHTML = summaryHtml;

        this.showStep('results');
        // Luôn hiện nút xác nhận nếu có ít nhất 1 sản phẩm
        this.showButton(aiProducts.length > 0 ? 'confirm' : 'retry');
        this.elements.retryBtn.style.display = '';
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

        // Lấy sản phẩm đã tick
        const itemsToFill = this.validatedProducts.filter(vp => checkedIdxs.has(vp.originalIdx));

        if (itemsToFill.length === 0) {
            alert('Chưa chọn sản phẩm nào để nhập.');
            return;
        }

        const isGift = this.targetBlock === 'gift';
        let filledCount = 0;
        let skippedItems = [];

        for (const item of itemsToFill) {
            const product = item.product;

            // Sản phẩm chưa khớp DB (id=0) → bỏ qua, cảnh báo sau
            if (!item.matched || !product.id) {
                skippedItems.push(product.sku || product.name || '(không rõ)');
                continue;
            }

            if (isGift) {
                if (typeof this.ticketInstance.addGiftProductRow === 'function') {
                    this.ticketInstance.addGiftProductRow(product);
                }
            } else {
                if (Array.isArray(this.ticketInstance.selectedProducts)) {
                    this.ticketInstance.selectedProducts.push(product);
                }
                if (typeof this.ticketInstance.addProductRow === 'function') {
                    this.ticketInstance.addProductRow(product);
                }
            }

            this.fillDataToRow(product, item, isGift);
            filledCount++;
        }

        // Update totals
        if (typeof this.ticketInstance.updateTotals === 'function') {
            this.ticketInstance.updateTotals();
        }

        this.modal.hide();

        // Thông báo kết quả
        let msg = `Đã nhập ${filledCount} sản phẩm từ AI.`;
        if (skippedItems.length > 0) {
            msg += `\n\n⚠ ${skippedItems.length} sản phẩm chưa khớp DB (bỏ qua):\n- ${skippedItems.join('\n- ')}`;
        }
        if (skippedItems.length > 0) {
            alert(msg);
        }
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
        const qty = parseFloat(item.quantity) || 0;
        if (qty > 0) {
            const qtyClass = isGift ? '.ticket-gift-quantity-input' : '.ticket-quantity-input';
            const $qtyInput = $row.find(qtyClass);
            if ($qtyInput.length) {
                $qtyInput.val(qty).trigger('input').trigger('change');
            } else {
                // Fallback: tìm input number trong cột SL (cột thứ 5)
                const $fallback = $row.find('td').eq(4).find('input[type="number"]');
                if ($fallback.length) {
                    $fallback.val(qty).trigger('input').trigger('change');
                }
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
        this.showButton(this.selectedFiles.length > 0 ? 'process' : 'none');
        if (this.elements.errorDisplay) this.elements.errorDisplay.style.display = 'none';
    }

    reset() {
        this._clearPreviewUrls();
        this.selectedFiles = [];
        this.aiProducts = [];
        this.validatedProducts = [];
        this.isProcessing = false;
        this.showStep('input');
        this.showButton('none');

        // Reset file inputs
        [this.elements.cameraInput, this.elements.imageInput, this.elements.fileInput].forEach(input => {
            if (input) input.value = '';
        });
        if (this.elements.filePreview) {
            this.elements.filePreview.style.display = 'none';
            this.elements.filePreview.innerHTML = '';
        }
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
