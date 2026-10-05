// --- BIẾN TOÀN CỤC ---
let globalScannedData = [];
let currentSelectedIndex = 0;
let selectedFilesArray = [];
let importMode = 'ai'; // Mặc định là AI


// BẮT SỰ KIỆN CHUYỂN TAB
document.querySelectorAll('#importTabs .nav-link').forEach(tab => {
    tab.addEventListener('click', function (e) {
        e.preventDefault();
        // Đổi active
        document.querySelectorAll('#importTabs .nav-link').forEach(t => t.classList.remove('active'));
        this.classList.add('active');

        importMode = this.getAttribute('data-mode');

        // Reset toàn bộ UI
        selectedFilesArray = [];
        globalScannedData = [];
        currentSelectedIndex = 0;
        renderPreScanThumbnails();
        document.getElementById('post_scan_area').classList.add('d-none');
        document.getElementById('active_form_container').innerHTML = `
                <div class="text-center text-white-50 py-5 mt-4">
                    <h4 class="mb-3 opacity-50 fw-bold">CHƯA CÓ DỮ LIỆU</h4>
                    <p class="mb-0">Hệ thống đang chờ lệnh. Vui lòng tải tệp để tiếp tục.</p>
                </div>`;

        // Đổi text nút bấm & Gợi ý
        const btn = document.getElementById('btn-scan-batch');
        const note = document.querySelector('.upload-zone p');
        const btnSelect = document.querySelector('.upload-zone button');

        if (importMode === 'manual') {
            btn.innerHTML = '<i class="fas fa-edit me-2"></i>TIẾN HÀNH NHẬP LIỆU';
            btnSelect.innerHTML = 'CHỌN TỆP HÌNH ẢNH (TỐI ĐA 10)';
            note.innerHTML = '*Lưu ý: Chế độ thủ công cho phép nhập tối đa 10 ảnh cùng lúc.';
        } else {
            btn.innerHTML = '<i class="fas fa-magic me-2"></i>BẮT ĐẦU XỬ LÝ AI';
            btnSelect.innerHTML = 'CHỌN TỆP HÌNH ẢNH (TỐI ĐA 3)';
            note.innerHTML = '*Lưu ý: Để chọn nhiều ảnh, vui lòng giữ phím Ctrl và click chọn các ảnh cùng lúc.';
        }
    });
});


function previewSelectedImages(input) {
    const newFiles = Array.from(input.files);
    const maxLimit = importMode === 'ai' ? 3 : 10; // AI max 3, Thủ công max 10

    for (let file of newFiles) {
        if (selectedFilesArray.length < maxLimit) {
            selectedFilesArray.push(file);
        } else {
            alert(`Chế độ này chỉ hỗ trợ tối đa ${maxLimit} ảnh cùng lúc!`);
            break;
        }
    }
    input.value = '';
    renderPreScanThumbnails();
}

function renderPreScanThumbnails() {
    const container = document.getElementById('pre_scan_preview');
    container.innerHTML = '';
    selectedFilesArray.forEach((file, index) => {
        const fileUrl = URL.createObjectURL(file);
        container.innerHTML += `
                <div class="position-relative  rounded-1 " style="width: 120px; height: 120px;">
                    <img src="${fileUrl}" style="width: 100%; height: 100%; object-fit: contain; background-color: #f8f9fa;">
                    <button type="button" class="btn btn-danger p-0 position-absolute " 
                            style="top: 4px; right: 4px; width: 22px; height: 22px; font-size: 12px; font-weight: bold; border-radius: 2px;"
                            onclick="removeSelectedFile(${index})" title="Loại bỏ ảnh này">X</button>
                </div>`;
    });
}

function removeSelectedFile(index) {
    selectedFilesArray.splice(index, 1);
    renderPreScanThumbnails();
}

async function executeBatchScan() {
    if (selectedFilesArray.length === 0) return alert("Vui lòng đính kèm tệp hình ảnh để xử lý.");

    const btn = document.getElementById('btn-scan-batch');
    const formContainer = document.getElementById('active_form_container');
    const preScanArea = document.getElementById('pre_scan_preview');

    // NẾU LÀ CHẾ ĐỘ THỦ CÔNG -> BỎ QUA GỌI AI
    if (importMode === 'manual') {
        btn.innerHTML = 'ĐANG TẠO BIỂU MẪU...';
        preScanArea.innerHTML = '';
        document.getElementById('post_scan_area').classList.add('d-none');

        globalScannedData = selectedFilesArray.map(f => ({
            temp_image: "",
            vector: null,
            matches: [],
            similarity: 0,
            status: "manual",
            fileObj: f
        }));

        renderThumbnails();
        loadFormForIndex(0);
        btn.innerHTML = '<i class="fas fa-edit me-2"></i>TIẾN HÀNH NHẬP LIỆU';
        return;
    }

    // --- NẾU LÀ AI ---
    btn.disabled = true;
    btn.innerHTML = 'ĐANG XỬ LÝ...';
    preScanArea.innerHTML = '';
    document.getElementById('post_scan_area').classList.add('d-none');
    formContainer.innerHTML = '<div class="text-center py-5"><p class="fw-bold">Hệ thống đang truy xuất dữ liệu kho...</p></div>';

    const fd = new FormData();
    selectedFilesArray.forEach(f => fd.append('images[]', f));

    try {
        const response = await fetch('index.php?page=products&action=scan-ai', {
            method: 'POST',
            body: fd
        });

        // KỸ THUẬT DEBUG: Lấy text thô từ Server trước khi ép sang JSON
        const rawText = await response.text();

        try {
            // Thử ép sang JSON
            const res = JSON.parse(rawText);
            if (res.status === 'success') {
                globalScannedData = res.data;
                renderThumbnails();
                if (globalScannedData.length > 0) loadFormForIndex(0);
            } else {
                alert("Lỗi xử lý: " + res.message);
                formContainer.innerHTML = '<div class="text-center py-5 fw-bold text-danger">Tiến trình thất bại.</div>';
            }
        } catch (parseError) {
            // CHÌA KHÓA Ở ĐÂY: Nếu PHP bị lỗi (dư dấu ngoặc, sai cú pháp), nó in ra HTML
            // JS sẽ in thẳng cục HTML đó ra màn hình để bồ biết đường mà sửa!
            console.error("LỖI PHP TRẢ VỀ:", rawText);
            formContainer.innerHTML = `
                    <div class="p-4 text-danger bg-dark rounded-1 text-start" style="overflow-y: auto; max-height: 400px;">
                        <h6 class="fw-bold text-warning"><i class="fas fa-bug me-2"></i>SERVER PHP BÁO LỖI:</h6>
                        <pre style="white-space: pre-wrap; font-size: 13px; color: #ff6b6b;">${rawText}</pre>
                    </div>`;
        }
    } catch (e) {
        alert("Lỗi mạng! Không thể gửi request đến server.");
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magic me-2"></i>BẮT ĐẦU XỬ LÝ AI';
    }
}

function renderThumbnails() {
    const postScanArea = document.getElementById('post_scan_area');
    const container = document.getElementById('scanned_thumbnails');

    // NẾU HẾT DỮ LIỆU THÌ HIỆN THÔNG BÁO HOÀN TẤT
    if (globalScannedData.length === 0) {
        postScanArea.classList.add('d-none');
        document.getElementById('active_form_container').innerHTML = `
                <div class="text-center py-5">
                    <h4 class=" fw-bold">HOÀN TẤT!</h4>
                    <p>Đã nhập kho xong toàn bộ sản phẩm.</p>
                    <button class="btn btn-dark btn-sm rounded-1" onclick="window.location.reload()">CẬP NHẬT DANH SÁCH KHO</button>
                </div>`;
        return;
    }

    postScanArea.classList.remove('d-none');
    container.innerHTML = '';
    globalScannedData.forEach((item, index) => {
        let borderClass = (index === currentSelectedIndex) ? '  opacity-100 shadow' : ' opacity-50';
        let localUrl = URL.createObjectURL(selectedFilesArray[index]);
        container.innerHTML += `<img src="${localUrl}" onclick="loadFormForIndex(${index})" class="rounded-1  ${borderClass}" style="width: 100px; height: 100px; object-fit:contain; background-color:#f8f9fa; cursor:pointer; transition: 0.2s;">`;
    });
}

// --- HÀM LƯU AJAX (GIẢI QUYẾT VẤN ĐỀ CỦA BẠN) ---
async function saveProductByAJAX(event, form) {
    event.preventDefault();
    const btn = form.querySelector('button[type="submit"]');
    const formData = new FormData(form);
    const putawayData = JSON.parse(document.getElementById('putaway_data_input').value);
    const stockInput = parseInt(document.getElementById('input_stock_qty').value);
    let totalAssigned = putawayData.reduce((sum, item) => sum + item.quantity, 0);
    if (totalAssigned < stockInput) {
        alert(`Bạn chưa xếp vị trí! Cần xếp ${stockInput - totalAssigned} đôi nữa.`);
        if (document.getElementById('inline_putaway_area').classList.contains('d-none')) toggleInlinePutaway();
        return;
    }

    formData.append('add_product', '1');

    // KỸ THUẬT QUAN TRỌNG: Nếu nhập thủ công, nhét file ảnh thực tế vào FormData để gửi lên PHP
    if (importMode === 'manual' && globalScannedData[currentSelectedIndex].fileObj) {
        formData.append('manual_image', globalScannedData[currentSelectedIndex].fileObj);
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> ĐANG LƯU...';

    try {
        const response = await fetch('index.php?page=products&action=add', {
            method: 'POST',
            body: formData
        });
        const res = await response.json();

        if (res.status === 'success') {
            globalScannedData.splice(currentSelectedIndex, 1);
            selectedFilesArray.splice(currentSelectedIndex, 1);
            alert("Lưu thành công!");

            if (globalScannedData.length > 0) {
                currentSelectedIndex = 0;
                loadFormForIndex(0);
            } else {
                renderThumbnails(); // Hiện Hoàn tất
            }
        } else {
            alert("Lỗi: " + res.message);
            btn.disabled = false;
            btn.innerHTML = 'XÁC NHẬN LƯU DỮ LIỆU';
        }
    } catch (e) {
        alert("Lỗi hệ thống, vui lòng thử lại.");
        btn.disabled = false;
    }
}

// --- GIỮ NGUYÊN TOÀN BỘ LOGIC FORM CŨ CỦA BẠN ---
function loadFormForIndex(index) {
    currentSelectedIndex = index;
    renderThumbnails();

    const item = globalScannedData[index];
    const container = document.getElementById('active_form_container');
    let localUrl = URL.createObjectURL(selectedFilesArray[index]);

    const vectorStr = (item.vector && Array.isArray(item.vector)) ? JSON.stringify(item.vector) : "";
    const imageName = item.temp_image || ""; // Thủ công thì rỗng

    let alertMessage = "";
    let suggestionHtml = "";
    let defaultBrand = "";
    let defaultName = "";

    // --- KHỞI TẠO BIẾN CHO GIAO DIỆN MÀU SẮC ---
    let colorContainerHtml = `<input type="text" name="color" class="form-control fw-bold rounded-1" placeholder="Nhập màu sắc" required>`;

    // ============================================
    // PHÂN LOẠI: NẾU LÀ THỦ CÔNG HOẶC NẾU LÀ AI
    // ============================================
    if (importMode === 'manual') {
        alertMessage = `<div class="alert alert-glass-blink py-2 rounded-1 mb-3"><strong>NHẬP THỦ CÔNG:</strong> Vui lòng tự khai báo thông tin.</div>`;
    } else {
        const matches = item.matches || [];
        const topMatch = matches.length > 0 ? matches[0] : null;
        const topScore = topMatch ? Math.round(topMatch.similarity_score * 100) : 0;

        if (topScore >= 95) {
            alertMessage = `<div class="alert alert-glass-blink py-2 rounded-1 mb-3"><i class="fas fa-check-circle me-2"></i><strong>AI XÁC THỰC:</strong> Khớp tuyệt đối (${topScore}%).</div>`;
            defaultBrand = topMatch.brand;
            defaultName = topMatch.product_name;

            // NẾU KHỚP 100%, CHUYỂN Ô TEXT THÀNH COMBOBOX CÁC MÀU ĐÃ CÓ
            if (item.colors && item.colors.length > 0) {
                let opts = `<option value="">-- Chọn màu kho --</option>`;
                item.colors.forEach(c => {
                    opts += `<option value="${c.color}">${c.color}</option>`;
                });

                colorContainerHtml = `
                        <select name="color" class="form-select fw-bold rounded-1 shadow-sm" onchange="toggleNewColorInput(this)" required>
                            ${opts}
                        </select>
                      
                    `;
            }

        } else if (topScore >= 80) {
            alertMessage = `<div class="alert alert-info py-2 rounded-1 mb-3 bg-transparent border border-info text-info"><i class="fas fa-lightbulb me-2"></i><strong>AI GỢI Ý:</strong> Mẫu gần giống (${topScore}%). Xem bên dưới.</div>`;
            suggestionHtml = `<div class="suggestion-list mb-3 p-2 rounded" style="max-height: 150px; overflow-y: auto;">`;
            matches.forEach((m) => {
                // CHÚ Ý Ở ĐÂY: Truyền thêm m.product_id vào hàm click
                suggestionHtml += `<div class="d-flex align-items-center p-2" style="cursor:pointer;" onclick="fillFormFromSuggestion('${m.brand}', '${m.product_name}', ${m.product_id})">
                                           <img src="assets/img_product/${m.product_image}" style="width:40px; height:40px; object-fit:cover;" class="me-2 rounded">
                                           <div style="font-size: 11px;">
                                               <span class="fw-bold d-block">${m.product_name}</span>
                                               <span class="text-muted">${m.brand} - Khớp ${Math.round(m.similarity_score * 100)}%</span>
                                           </div>
                                       </div>`;
            });
            suggestionHtml += `</div>`;
        } else {
            alertMessage = `<div class="alert alert-glass-blink rounded-1 mb-3"><i class="fas fa-plus-circle me-2"></i><strong>MẪU MỚI:</strong> Không tìm thấy dữ liệu cũ.</div>`;
        }
    }

    // Tạo danh sách Hãng
    let categoryOptions = `<option value="">-- Chọn thương hiệu --</option>`;
    if (typeof categoriesList !== 'undefined') {
        categoriesList.forEach(cat => {
            let isSelected = (cat.category_name.toLowerCase() === defaultBrand.toLowerCase()) ? 'selected' : '';
            categoryOptions += `<option value="${cat.category_id}" ${isSelected}>${cat.category_name.toUpperCase()}</option>`;
        });
    }

    // RENDER FORM (Chú ý thẻ div chứa colorContainerHtml)
    container.innerHTML = `
        <form onsubmit="saveProductByAJAX(event, this)">
            ${alertMessage}
            ${suggestionHtml}
            <input type="hidden" name="vector" value='${vectorStr}'>
            <input type="hidden" name="temp_image_name" value="${imageName}">
            
            <div class="row mb-3 align-items-center">
                <div class="col-md-3"><label class="fw-bold small">ẢNH GỐC:</label></div>
                <div class="col-md-9">
                    <img src="${localUrl}" class="rounded-1" style="width: 80px; height: 80px; object-fit:contain; background-color: #f8f9fa;">
                </div>
            </div>

            <div class="mb-3">
                <label class="fw-bold small mb-1">HÃNG SẢN XUẤT:</label>
                <select id="input_brand" name="category_id" class="form-select rounded-1 shadow-sm fw-bold" required>
                    ${categoryOptions}
                </select>
            </div>

            <div class="mb-3">
                <label class="fw-bold small mb-1">TÊN DÒNG SẢN PHẨM:</label>
                <input type="text" id="input_product_name" name="product_name" class="form-control fw-bold rounded-1 shadow-sm" value="${defaultName}" placeholder="Nhập tên giày" required>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="fw-bold small mb-1">MÀU SẮC:</label>
                    <div id="color_input_wrapper">
                        ${colorContainerHtml}
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="fw-bold small mb-1">SIZE:</label>
                    <input type="number" name="size" class="form-control fw-bold text-center rounded-1" required>
                </div>
            </div>

            <div class="mb-4">
                <label class="fw-bold small mb-1">SỐ LƯỢNG NHẬP KHO & VỊ TRÍ:</label>
                <div class="input-group">
                    <input type="number" id="input_stock_qty" name="stock" class="form-control fw-bold text-center rounded-start-1" value="1" min="1" required>
                    <button type="button" class="btn btn-info fw-bold text-dark px-3 rounded-end-1" onclick="toggleInlinePutaway()">
                        <i class="fas fa-map-marked-alt me-1"></i> CHỌN KỆ
                    </button>
                </div>
            </div>

            <div id="inline_putaway_area" class="d-none mb-4 p-3 rounded-2" style="background: rgba(0,0,0,0.3); border: 1px dashed #0dcaf0;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold text-info small"><i class="fas fa-location-arrow me-1"></i> BẢN ĐỒ KHO</span>
                    <span class="badge bg-warning text-dark" id="putaway_status_badge">Cần xếp: 0 đôi</span>
                </div>
                
                <div id="inline_heatmap_content" class="mb-2 p-2 bg-dark rounded" style="max-height: 300px; overflow-y: auto; overflow-x: hidden;">
                    </div>

                <div class="bg-dark p-2 rounded border border-secondary small">
                    <span class="text-white-50">Vị trí đã chọn:</span>
                    <div id="putaway_selected_list" class="d-flex flex-wrap gap-1 mt-1">
                        <span class="text-muted">Chưa chọn</span>
                    </div>
                </div>
            </div>
            
            <input type="hidden" name="putaway_data" id="putaway_data_input" value="[]">

            <button type="submit" class="btn btn-glass-confirm w-100 fw-bold rounded-1 py-2 shadow-sm">XÁC NHẬN LƯU DỮ LIỆU</button>
        </form>`;
}

async function fillFormFromSuggestion(brandName, productName, productId) {
    const selectBrand = document.getElementById('input_brand');
    document.getElementById('input_product_name').value = productName;

    // Chọn Hãng
    for (let i = 0; i < selectBrand.options.length; i++) {
        if (selectBrand.options[i].text.toLowerCase() === brandName.toLowerCase()) {
            selectBrand.selectedIndex = i;
            break;
        }
    }

    // Hiệu ứng Highlight báo hiệu đã chọn
    const inputs = [selectBrand, document.getElementById('input_product_name')];
    inputs.forEach(el => {
        el.style.backgroundColor = 'rgba(255, 255, 255, 0.2)';
        setTimeout(() => el.style.backgroundColor = '', 500);
    });

    // --- GỌI API LẤY MÀU SẮC CỦA SẢN PHẨM VỪA CHỌN ---
    const colorWrapper = document.getElementById('color_input_wrapper');
    colorWrapper.innerHTML = '<span class="text-white-50 small"><i class="fas fa-spinner fa-spin"></i> Đang tải màu...</span>';

    try {
        // Chú ý: Đảm bảo URL này gọi đúng hàm getColorsAjax trong Controller của bồ
        const res = await fetch(`index.php?page=products&action=getColorsAjax&product_id=${productId}`);
        const colors = await res.json();

        let opts = `<option value="">-- Chọn màu kho --</option>`;
        if (colors && colors.length > 0) {
            colors.forEach(c => {
                opts += `<option value="${c.color}">${c.color}</option>`;
            });
        }


        // Render lại ô Select
        colorWrapper.innerHTML = `
                <select name="color" id="input_color_select" class="form-select fw-bold rounded-1 shadow-sm" onchange="toggleNewColorInput(this)" required>
                    ${opts}
                </select>
               
            `;

        // Highlight combo màu
        document.getElementById('input_color_select').style.backgroundColor = 'rgba(255, 255, 255, 0.2)';
        setTimeout(() => document.getElementById('input_color_select').style.backgroundColor = '', 500);

    } catch (e) {
        // Lỗi mạng thì trả về ô text bình thường cho người dùng tự gõ
        colorWrapper.innerHTML = '<input type="text" name="color" class="form-control fw-bold rounded-1" placeholder="Màu sắc" required>';
    }
}

// ==========================================
// LOGIC XUẤT KHO THEO PHIẾU (THỦ CÔNG)
// ==========================================
let currentExportTicketId = null;
let currentExportTicketCode = "";
let currentTicketItems = [];

// Khi mở Modal Xuất Kho -> Load danh sách phiếu
document.getElementById('exportAIModal').addEventListener('show.bs.modal', loadMyExportTickets);

async function loadMyExportTickets() {
    const list = document.getElementById('export_ticket_list');
    list.innerHTML = '<div class="text-center text-white-50 py-3"><i class="fas fa-spinner fa-spin me-2"></i>Đang tải dữ liệu...</div>';

    document.getElementById('export_step_1').classList.remove('d-none');
    document.getElementById('export_step_2').classList.add('d-none');
    document.getElementById('export_right_default').classList.remove('d-none');
    document.getElementById('export_right_action').classList.add('d-none');

    try {
        const res = await fetch('index.php?page=tickets&action=get_my_exports');
        const tickets = await res.json();

        if (!tickets || tickets.length === 0) {
            list.innerHTML = '<div class="alert alert-glass-blink text-center text-warning border-0">Không có phiếu xuất nào đang chờ.</div>';
            return;
        }

        list.innerHTML = tickets.map(t => `
            <div class="list-group-item bg-dark text-white border-secondary mb-2 rounded-1 p-3 cursor-pointer" 
                 onclick="openExportTicket(${t.ticket_id}, '${t.ticket_code}')">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-bold text-info"><i class="fas fa-ticket-alt me-2"></i>${t.ticket_code}</span>
                    <span class="badge ${t.status === 'PAUSED' ? 'bg-warning text-dark' : 'bg-primary'}">${t.status}</span>
                </div>
            </div>
        `).join('');
    } catch (e) {
        list.innerHTML = '<div class="text-danger small">Lỗi kết nối.</div>';
    }
}

async function openExportTicket(ticketId, ticketCode) {
    currentExportTicketId = ticketId;
    currentExportTicketCode = ticketCode;

    document.getElementById('export_current_ticket_code').innerText = `MÃ PHIẾU: ${ticketCode}`;
    document.getElementById('export_step_1').classList.add('d-none');
    document.getElementById('export_step_2').classList.remove('d-none');

    const itemsContainer = document.getElementById('export_ticket_items');
    itemsContainer.innerHTML = '<div class="text-center text-white-50 py-3"><i class="fas fa-spinner fa-spin"></i> Đang tải giày...</div>';

    // Cập nhật trạng thái phiếu sang PROCESSING 
    fetch(`index.php?page=tickets&action=update_status&ticket_id=${ticketId}&status=PROCESSING`, { method: 'POST' });

    try {
        const res = await fetch(`index.php?page=tickets&action=get_ticket_details&ticket_id=${ticketId}`);
        currentTicketItems = await res.json();
        renderExportItems();
    } catch (e) {
        itemsContainer.innerHTML = '<div class="text-danger small">Lỗi tải danh sách sản phẩm.</div>';
    }
}

function renderExportItems() {
    const container = document.getElementById('export_ticket_items');
    let allDone = true;

    container.innerHTML = currentTicketItems.map(item => {
        const qty = parseInt(item.quantity) || 0;
        const processed = parseInt(item.processed_qty) || 0;
        const remaining = qty - processed;
        const isDone = remaining <= 0;

        if (!isDone) allDone = false;

        // BỔ SUNG QUAN TRỌNG: Lấy mảng other_variants ép thành chuỗi JSON
        const othersJson = item.other_variants ? JSON.stringify(item.other_variants).replace(/'/g, "&apos;").replace(/"/g, "&quot;") : "[]";

        return `
            <div class="p-3 rounded border ${isDone ? 'bg-success bg-opacity-25 border-success' : 'bg-dark border-secondary'}">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold small ${isDone ? 'text-success' : 'text-white'}">${item.product_name}</span>
                    <span class="badge ${isDone ? 'bg-success' : 'bg-danger'}">${processed} / ${qty}</span>
                </div>
                <div class="d-flex justify-content-between align-items-end">
                    <div class="small text-white-50">
                        Size: <strong class="text-white">${item.size}</strong> | Màu: <strong class="text-white">${item.color}</strong>
                    </div>
                    ${!isDone ? `
                        <button class="btn btn-sm btn-info fw-bold py-0 px-3" 
                            onclick="autoFillExportForm(${item.variant_id}, ${item.detail_id}, '${item.brand}', '${item.product_name}', '${item.product_image}', '${item.size}', '${item.color}', ${remaining}, '${othersJson}')">
                            CHỌN
                        </button>
                    ` : '<span class="text-success small fw-bold"><i class="fas fa-check-circle me-1"></i>Đã xong</span>'}
                </div>
            </div>
        `;
    }).join('');

    if (allDone && currentTicketItems.length > 0) {
        document.getElementById('btn_complete_export').classList.remove('d-none');
        document.getElementById('export_right_default').classList.remove('d-none');
        document.getElementById('export_right_action').classList.add('d-none');
    } else {
        document.getElementById('btn_complete_export').classList.add('d-none');
    }
}

function backToExportTickets() {
    fetch(`index.php?page=tickets&action=update_status&ticket_id=${currentExportTicketId}&status=PAUSED`, { method: 'POST' });
    loadMyExportTickets();
}
// BỔ SUNG QUAN TRỌNG: Thêm tham số othersJsonStr vào cuối
async function autoFillExportForm(variantId, detailId, brand, name, image, size, color, remainingQty, othersJsonStr) {
    document.getElementById('export_right_default').classList.add('d-none');
    document.getElementById('export_right_action').classList.remove('d-none');
    document.getElementById('export_right_action').classList.add('d-flex');

    document.getElementById('autofill_ticket_code').value = currentExportTicketCode;
    document.getElementById('autofill_brand').value = brand;
    document.getElementById('autofill_name').value = name;
    document.getElementById('autofill_color').value = color;
    document.getElementById('autofill_size').value = size;
    document.getElementById('autofill_image').src = `assets/img_product/${image || 'default_shoe.png'}`;

    document.getElementById('pick_detail_id').value = detailId;
    document.getElementById('pick_variant_id').value = variantId;

    const inputQty = document.getElementById('pick_qty_input');
    inputQty.value = remainingQty;
    inputQty.max = remainingQty;
    document.getElementById('pick_qty_max').innerText = `/ ${remainingQty}`;

    // BỔ SUNG: Hiển thị các anh em cùng mẫu
    const othersArea = document.getElementById('other_variants_area');
    const othersList = document.getElementById('other_variants_list');
    try {
        const others = JSON.parse(othersJsonStr.replace(/&quot;/g, '"').replace(/&apos;/g, "'"));
        if (others && others.length > 0) {
            othersArea.classList.remove('d-none');
            othersList.innerHTML = others.map(o => `• Màu ${o.color}, Size ${o.size} (Cần lấy: ${o.quantity})`).join('<br>');
        } else {
            othersArea.classList.add('d-none');
        }
    } catch (e) {
        othersArea.classList.add('d-none');
    }

    const locContainer = document.getElementById('pick_locations_container');
    locContainer.innerHTML = '<span class="text-white-50 small"><i class="fas fa-spinner fa-spin"></i> Đang dò vị trí kệ...</span>';

    try {
        const res = await fetch(`index.php?page=products&action=get_locations&variant_id=${variantId}`);
        const locs = await res.json();

        if (!locs || locs.length === 0) {
            locContainer.innerHTML = '<span class="badge bg-danger p-2"><i class="fas fa-exclamation-triangle me-1"></i> Mẫu này hiện không có sẵn trên bất kỳ kệ nào!</span>';
            return;
        }

        locContainer.innerHTML = locs.map(l =>
            `<span class="badge bg-info text-dark p-2 border border-light" style="font-size: 13px;">
                Kệ ${l.shelf_name} <i class="fas fa-arrow-right mx-1"></i> Ô ${l.slot_code} 
                <span class="ms-1 opacity-75">(Tồn: ${l.qty_in_slot})</span>
            </span>`
        ).join('');
    } catch (e) {
        locContainer.innerHTML = '<span class="text-danger small">Lỗi truy xuất sơ đồ kho.</span>';
    }
}
async function confirmPickItem() {
    const detailId = document.getElementById('pick_detail_id').value;
    const qty = document.getElementById('pick_qty_input').value;
    const max = document.getElementById('pick_qty_input').max;

    if (!qty || parseInt(qty) <= 0 || parseInt(qty) > parseInt(max)) {
        return alert("Số lượng nhặt không hợp lệ!");
    }

    const btn = document.querySelector('#export_right_action button');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>ĐANG LƯU...';

    try {
        const fd = new FormData();
        fd.append('detail_id', detailId);
        fd.append('picked_qty', qty);

        const res = await fetch('index.php?page=tickets&action=update_export_progress', {
            method: 'POST',
            body: fd
        });
        const data = await res.json();

        if (data.status === 'success') {
            const item = currentTicketItems.find(i => i.detail_id == detailId);
            if (item) item.processed_qty = parseInt(item.processed_qty || 0) + parseInt(qty);

            renderExportItems();

            // Xong thì ẩn form, ép chọn đôi khác
            document.getElementById('export_right_default').classList.remove('d-none');
            document.getElementById('export_right_action').classList.add('d-none');
            document.getElementById('export_right_action').classList.remove('d-flex');
        } else {
            alert("Lỗi: " + data.message);
        }
    } catch (e) {
        alert("Lỗi mạng khi lưu tiến độ.");
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'XÁC NHẬN ĐÃ LẤY HÀNG NÀY';
    }
}

async function completeExportTicket() {
    if (!confirm("Hệ thống sẽ trừ kho chính thức và hoàn tất phiếu. Bạn chắc chắn chứ?")) return;

    try {
        const res = await fetch(`index.php?page=tickets&action=complete_export&ticket_id=${currentExportTicketId}`, { method: 'POST' });
        const data = await res.json();

        if (data.status === 'success') {
            alert("Xuất kho thành công!");
            window.location.reload();
        } else {
            alert("Lỗi hoàn tất: " + data.message);
        }
    } catch (e) {
        alert("Lỗi hệ thống.");
    }
}