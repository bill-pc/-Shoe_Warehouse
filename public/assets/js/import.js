// =========================================================
// LOGIC NHẬP KHO AI (THEO PHIẾU CHỈ ĐỊNH & BẢNG TẠM)
// =========================================================

let currentImportTicketId = null;
let currentImportTicketCode = "";
let importTicketItems = [];
let importScannedData = [];
let importSelectedFiles = [];
let activeScannedIndex = -1;

document.getElementById('addProductModal').addEventListener('show.bs.modal', loadMyImportTickets);

// 1. TẢI DANH SÁCH PHIẾU NHẬP ĐANG CHỜ
async function loadMyImportTickets() {
    const list = document.getElementById('import_ticket_list');
    list.innerHTML = '<div class="text-center text-white-50 py-3"><i class="fas fa-spinner fa-spin me-2"></i>Đang tải dữ liệu...</div>';

    document.getElementById('import_step_1').classList.remove('d-none');
    document.getElementById('import_step_2').classList.add('d-none');
    document.getElementById('import_ai_upload_zone').classList.add('d-none');
    document.getElementById('import_form_zone').classList.add('d-none');
    document.getElementById('import_right_default').classList.remove('d-none');

    try {
        const res = await fetch('index.php?page=tickets&action=get_my_imports');
        const tickets = await res.json();

        if (!tickets || tickets.length === 0) {
            list.innerHTML = '<div class="alert alert-glass-blink text-center text-warning border-0">Không có phiếu nhập nào được giao.</div>';
            return;
        }

        list.innerHTML = tickets.map(t => `
            <div class="list-group-item bg-dark text-white border-secondary mb-2 rounded-1 p-3 cursor-pointer" 
                 onclick="openImportTicket(${t.ticket_id}, '${t.ticket_code}')">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-bold text-info"><i class="fas fa-truck-loading me-2"></i>${t.ticket_code}</span>
                    <span class="badge ${t.status === 'PAUSED' ? 'bg-warning text-dark' : 'bg-primary'}">${t.status}</span>
                </div>
            </div>
        `).join('');
    } catch (e) { list.innerHTML = '<div class="text-danger small">Lỗi kết nối.</div>'; }
}

// 2. MỞ PHIẾU CHI TIẾT
async function openImportTicket(ticketId, ticketCode) {
    currentImportTicketId = ticketId;
    currentImportTicketCode = ticketCode;
    importSelectedFiles = [];
    importScannedData = [];
    activeScannedIndex = -1;

    const fileInput = document.getElementById('ai_import_files');
    if (fileInput) fileInput.value = '';

    const preview = document.getElementById('import_pre_scan_preview');
    if (preview) preview.innerHTML = '';
    const postScan = document.getElementById('import_post_scan_area');
    if (postScan) postScan.innerHTML = '';
    const scanBtn = document.getElementById('btn-scan-import');
    if (scanBtn) {
        scanBtn.classList.add('d-none');
        scanBtn.disabled = false;
        scanBtn.innerHTML = '<i class="fas fa-magic me-1"></i>QUÉT AI';
    }

    document.getElementById('import_current_ticket_code').innerText = `MÃ PHIẾU: ${ticketCode}`;
    document.getElementById('import_step_1').classList.add('d-none');
    document.getElementById('import_step_2').classList.remove('d-none');

    document.getElementById('import_right_default').classList.add('d-none');
    document.getElementById('import_ai_upload_zone').classList.remove('d-none');

    fetch(`index.php?page=tickets&action=update_status&ticket_id=${ticketId}&status=PROCESSING`, { method: 'POST' });
    loadImportTicketItems();
}

async function loadImportTicketItems() {
    const container = document.getElementById('import_ticket_items');
    container.innerHTML = '<div class="text-center text-white-50"><i class="fas fa-spinner fa-spin"></i> Đang tải giày...</div>';

    try {
        // Gọi chung API get_ticket_details của Export
        const res = await fetch(`index.php?page=tickets&action=get_ticket_details&ticket_id=${currentImportTicketId}`);
        importTicketItems = await res.json();
        renderImportItemsUI();
    } catch (e) { container.innerHTML = '<div class="text-danger small">Lỗi tải dữ liệu phiếu.</div>'; }
}

// Render danh sách bên trái (Cột phiếu)
// sửa hàm này kiểm tra dk biến allDone nếu muốn hiện nút lưu chốt khi nhập xong biến thể
function renderImportItemsUI() {
    const container = document.getElementById('import_ticket_items');
    let allDone = true;

    container.innerHTML = importTicketItems.map(item => {
        const qty = parseInt(item.quantity);
        const processed = parseInt(item.processed_qty) || 0;

        let badgeClass = 'bg-danger';
        let statusTag = '';

        if (processed > 0) {
            if (processed === qty) {
                badgeClass = 'bg-success';
                statusTag = '<span class="text-success small fw-bold"><i class="fas fa-check-circle"></i> ĐỦ HÀNG</span>';
            } else if (processed < qty) {
                badgeClass = 'bg-warning text-dark';
                statusTag = '<span class="text-warning small fw-bold"><i class="fas fa-exclamation-triangle"></i> THIẾU HÀNG</span>';
            } else {
                badgeClass = 'bg-info text-dark';
                statusTag = '<span class="text-info small fw-bold"><i class="fas fa-exclamation-circle"></i> DƯ HÀNG</span>';
            }
        }

        const isDone = processed >= qty;
        if (!isDone) allDone = false;

        let rowClass = processed > 0 ? 'bg-success bg-opacity-10 border-secondary cursor-pointer' : 'bg-dark border-secondary';
        let clickEvent = processed > 0 ? `onclick="editImportItem(${item.variant_id})"` : '';

        // --- BỔ SUNG: IN RA THÔNG BÁO VỊ TRÍ KỆ ĐÃ LƯU BÊN DANH SÁCH ---
        let locationsHtml = '';
        if (item.putaway_locations) {
            try {
                let locs = JSON.parse(item.putaway_locations);
                if (locs && locs.length > 0) {
                    let locArr = locs.map(l => `${l.shelf_name || ''}${l.tier}-${l.slot} (${l.qty} đôi)`);
                    locationsHtml = `<div class="small text-warning mt-2 pt-2 border-top border-secondary"><i class="fas fa-map-marker-alt me-1"></i>Đã cất: ${locArr.join(', ')}</div>`;
                }
            } catch (e) { }
        }

        return `
            <div id="import_row_${item.variant_id}" class="p-3 rounded border ${rowClass} transition-all" ${clickEvent}>
                <div class="d-flex align-items-center">
                    <img src="assets/img_product/${item.product_image || 'default_shoe.png'}" class="rounded me-3 border border-secondary bg-white" style="width: 50px; height: 50px; object-fit: contain;">
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold small ${processed > 0 ? 'text-success' : 'text-white'}">${item.product_name}</span>
                            <span class="badge ${badgeClass}">${processed} / ${qty}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-end">
                            <div class="small text-white-50">
                                Size: <strong class="text-white">${item.size}</strong> | Màu: <strong class="text-white">${item.color}</strong>
                            </div>
                            ${statusTag}
                        </div>
                        ${locationsHtml}
                    </div>
                </div>
            </div>
        `;
    }).join('');

    // Chỉ hiện nút Hoàn Tất khi tất cả các dòng biến thể đều đã được Lưu tạm ít nhất 1 lần (processed_qty > 0)
    const allSavedTemp = importTicketItems.length > 0 && importTicketItems.every(item => (parseInt(item.processed_qty) || 0) > 0);

    if (allSavedTemp) {
        document.getElementById('btn_complete_import').classList.remove('d-none');
    } else {
        document.getElementById('btn_complete_import').classList.add('d-none');
    }

}

// 3. XỬ LÝ ẢNH & QUÉT AI
function previewImportImages(input) {
    importSelectedFiles = Array.from(input.files).slice(0, 3); // Lấy tối đa 3 ảnh
    const container = document.getElementById('import_pre_scan_preview');
    container.innerHTML = '';

    if (importSelectedFiles.length > 0) {
        document.getElementById('btn-scan-import').classList.remove('d-none');
        importSelectedFiles.forEach(file => {
            container.innerHTML += `<img src="${URL.createObjectURL(file)}" class="rounded" style="width: 60px; height: 60px; object-fit:cover;">`;
        });
    }
}

async function executeImportScan() {
    const btn = document.getElementById('btn-scan-import');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;

    const fd = new FormData();
    importSelectedFiles.forEach(f => fd.append('images[]', f));

    // --- BỔ SUNG: Gửi kèm ID phiếu nhập hiện tại để server lọc gợi ý AI ---
    if (currentImportTicketId) {
        fd.append('ticket_id', currentImportTicketId);
    }
    // ----------------------------------------------------------------------

    try {
        const response = await fetch('index.php?page=products&action=scan-ai', { method: 'POST', body: fd });
        const res = await response.json();

        if (res.status === 'success') {
            importScannedData = res.data;
            document.getElementById('import_pre_scan_preview').innerHTML = ''; // Clear ảnh nháp
            btn.classList.add('d-none');
            renderImportPostScan();
            if (importScannedData.length > 0) processAIResult(0); // Tự động load ảnh đầu tiên
        } else { alert("Lỗi quét AI: " + res.message); }
    } catch (e) { alert("Lỗi kết nối AI."); }
    finally {
        btn.innerHTML = '<i class="fas fa-magic me-1"></i>QUÉT AI';
        btn.disabled = false;
    }
}


function renderImportPostScan() {
    const container = document.getElementById('import_post_scan_area');
    container.innerHTML = importScannedData.map((item, idx) => {
        let isDoneClass = item.is_done ? 'opacity-25' : '';
        let activeClass = (idx === activeScannedIndex) ? 'border-warning shadow' : 'border-secondary';

        // Nếu đã nhập đủ (is_done = true), thêm viền xanh lá cây
        if (item.is_done) {
            activeClass = 'border-success shadow-lg';
        }

        // BẠN HÃY COPY TOÀN BỘ KHỐI RETURN NÀY THAY CHO KHỐI RETURN HIỆN TẠI:
        return `<div class="d-flex flex-column align-items-center me-2 mb-2">
                    <img src="${URL.createObjectURL(importSelectedFiles[idx])}" 
                         class="rounded border-3 cursor-pointer ${activeClass} ${isDoneClass}" 
                         style="width: 80px; height: 80px; object-fit:contain;" 
                         onclick="processAIResult(${idx})">
                    <span id="scan_sku_${idx}" class=" fw-bold mt-1" style="font-size: 20px;"></span>
                </div>`;
    }).join('');
}



// =========================================================================
// 4. SO KHỚP AI, ĐÁNH GIÁ % VÀ HIGHLIGHT PHIẾU
// =========================================================================

function getNormalizedScore(match) {
    let s = parseFloat(match.similarity_score || match.score || 0);
    if (isNaN(s)) return 0;
    return s <= 1 ? s * 100 : s;
}

function processAIResult(index) {
    activeScannedIndex = index;
    renderImportPostScan();

    const aiItem = importScannedData[index];
    const matches = aiItem.matches || [];
    if (matches.length === 0) return alert("AI không nhận diện được bất kỳ mẫu giày nào!");

    // ÉP SẮP XẾP GIẢM DẦN BẢO ĐẢM TỈ LỆ CAO NHẤT NẰM TRÊN CÙNG
    matches.sort((a, b) => getNormalizedScore(b) - getNormalizedScore(a));

    // Ẩn Form điền và xóa vùng gợi ý cũ (nếu có)
    document.getElementById('import_form_zone').classList.remove('d-flex');
    document.getElementById('import_form_zone').classList.add('d-none');
    let suggestionContainer = document.getElementById('import_suggestion_container');
    if (suggestionContainer) suggestionContainer.innerHTML = '';

    // Tắt hết highlight màu xanh của các dòng
    document.querySelectorAll('[id^="import_row_"]').forEach(el => {
        el.classList.remove('bg-info', 'bg-opacity-25', 'border-info');
    });

    const topMatch = matches[0];
    const topScore = getNormalizedScore(topMatch);

    // Dưới 80% -> Từ chối
    if (topScore < 80) {
        alert(`Tỉ lệ khớp quá thấp (${topScore.toFixed(1)}%). Vui lòng chụp lại ảnh rõ nét hơn!`);
        return;
    }

    // === CHÉP ĐÈ ĐOẠN NÀY VÀO HÀM processAIResult ===
    console.log("🔥 ĐANG CHẠY CODE MỚI NHẤT");
    console.log("Số lượng mẫu tìm thấy:", matches.length);
    console.log("Điểm cao nhất (topScore):", topScore);

    // Nếu có từ 2 mẫu trở lên HOẶC điểm thấp hơn 90% -> Ép buộc hiện khung màu vàng
    if (matches.length > 1 || topScore < 90) {
        console.log("👉 ĐIỀU KIỆN ĐÚNG: Đang mở khung Phân Vân màu vàng!");
        renderSuggestionDropdown(matches, aiItem.temp_image);
    } else {
        console.log("👉 ĐIỀU KIỆN ĐÚNG: Đã thỏa mãn >= 90%, cho phép Auto-Match!");
        let isSuccess = autoProcessMatch(topMatch, aiItem.temp_image);
        if (isSuccess) alert(`Thành công! Khớp ảnh kho (Gốc: ${topScore.toFixed(1)}%)`);
    }

}

function normalizeProductText(value) {
    return String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
}

function isSameProduct(ticketItem, matchData) {
    if (ticketItem.product_id && matchData.product_id) {
        return String(ticketItem.product_id) === String(matchData.product_id);
    }

    const ticketName = normalizeProductText(ticketItem.product_name);
    const matchName = normalizeProductText(
        matchData.product_name || matchData.model || matchData.name
    );
    const ticketBrand = normalizeProductText(ticketItem.brand);
    const matchBrand = normalizeProductText(matchData.brand);

    return ticketName !== '' && ticketName === matchName &&
        (ticketBrand === '' || matchBrand === '' || ticketBrand === matchBrand);
}

// 4.1 Hàm điền dữ liệu (Có chặn hàng ngoài phiếu)
function autoProcessMatch(matchData, tempImage) {
    let matchedTicketItems = importTicketItems.filter(item =>
        isSameProduct(item, matchData) &&
        parseInt(item.processed_qty || 0) < parseInt(item.quantity || 0)
    );

    if (matchedTicketItems.length === 0) {
        alert("Sản phẩm AI nhận diện được KHÔNG CÓ trong phiếu nhập này, hoặc đã nhập đủ số lượng!");
        return false;
    }

    document.getElementById('import_form_zone').classList.remove('d-none');
    document.getElementById('import_form_zone').classList.add('d-flex');
    document.getElementById('import_temp_image').value = tempImage;
    document.getElementById('import_brand').value = matchData.brand || matchedTicketItems[0].brand || "";
    document.getElementById('import_name').value =
        matchData.product_name || matchData.model || matchData.name || matchedTicketItems[0].product_name || "";

    // Quét dọn sạch sẽ Ghi chú và Form của lần trước
    if (document.getElementById('import_note')) document.getElementById('import_note').value = '';
    document.getElementById('import_actual_qty').value = '';
    document.getElementById('import_expected_qty').value = '';
    const badge = document.getElementById('import_status_badge');
    badge.className = 'badge bg-secondary w-100 p-2';
    badge.innerText = 'CHƯA NHẬP DỮ LIỆU';
    document.getElementById('import_discrepancy_type').value = 'MATCH';

    matchedTicketItems.forEach(item => {
        let row = document.getElementById(`import_row_${item.variant_id}`);
        if (row) row.classList.add('bg-info', 'bg-opacity-25', 'border-info');
    });

    const colorDropdown = document.getElementById('import_color');
    document.getElementById('import_size').innerHTML = '<option value="">-- Chọn size --</option>';
    document.getElementById('import_size').disabled = true;
    colorDropdown.innerHTML = '<option value="">-- Chọn màu --</option>';

    let uniqueColors = [...new Set(matchedTicketItems.map(i => i.color).filter(Boolean))];
    uniqueColors.forEach(c => {
        colorDropdown.innerHTML += `<option value="${c}">${c}</option>`;
    });

    window.currentMatchedItems = matchedTicketItems;

    // TỰ ĐỘNG: Tự động chọn ngay Màu đầu tiên trong danh sách và tự động Fill Size/Kệ
    if (uniqueColors.length > 0) {
        colorDropdown.value = uniqueColors[0];
        updateImportSizeDropdown();
    }
    return true;
}

// 4.2 HÀM TẠO MENU DROPDOWN VÀ SHOW TOÀN BỘ ẢNH BÊN TRONG DROPDOWN
function renderSuggestionDropdown(matches, tempImage) {
    let validMatches = matches.filter(m => getNormalizedScore(m) >= 80);
    validMatches.sort((a, b) => getNormalizedScore(b) - getNormalizedScore(a));

    let container = document.getElementById('import_suggestion_container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'import_suggestion_container';
        container.className = 'w-100 mb-3';
        document.getElementById('import_form_zone').parentNode.insertBefore(container, document.getElementById('import_form_zone'));
    }

    let imagesHtml = '';
    let listItemsHtml = '';

    validMatches.forEach((m, idx) => {
        let score = getNormalizedScore(m).toFixed(1);
        let mBrand = (m.brand || "").toLowerCase();
        let mName = (m.product_name || m.model || m.name || "").toLowerCase();

        let inTicket = importTicketItems.some(ti => (ti.brand || "").toLowerCase() === mBrand && (ti.product_name || "").toLowerCase() === mName);
        let mark = inTicket ? "⭐ CÓ TRONG PHIẾU" : "Ngoài phiếu";

        let cleanObj = { brand: m.brand, product_name: m.product_name || m.model || m.name, product_image: m.product_image || 'default_shoe.png' };
        let valStr = JSON.stringify(cleanObj).replace(/'/g, "&#39;");
        let displayText = `[${score}%] - ${m.brand} ${cleanObj.product_name}`;

        // TẠO HTML CÁC DÒNG BÊN TRONG DROPDOWN (CÓ KÈM HÌNH ẢNH)
        listItemsHtml += `
            <li>
                <a class="dropdown-item text-white d-flex align-items-center py-2 border-bottom border-secondary bg-dark custom-hover-dropdown" 
                   href="javascript:void(0)" 
                   id="suggestion_item_${idx}"
                   data-val='${valStr}'
                   onclick="selectCustomSuggestion(this)">
                    <img src="assets/img_product/${cleanObj.product_image}" class="rounded bg-white me-3 border border-secondary" style="width: 45px; height: 45px; object-fit: contain;">
                    <div class="d-flex flex-column">
                        <span class="small fw-bold text-wrap" style="white-space: normal;">${displayText}</span>
                        <span class="text-warning small" style="font-size: 0.7rem;">${mark}</span>
                    </div>
                </a>
            </li>
        `;

        let borderClass = inTicket ? 'border-success' : 'border-secondary';
        imagesHtml += `
            <div class="text-center me-2 mb-2 p-1 rounded border ${borderClass} bg-dark" 
                 onclick="selectSuggestionImage(${idx})" style="width: 80px; cursor: pointer;">
                <img src="assets/img_product/${cleanObj.product_image}" class="rounded bg-white mb-1 w-100" style="height: 50px; object-fit: contain;">
                <div class="small fw-bold text-warning" style="font-size: 0.75rem;">${score}%</div>
            </div>
        `;
    });

    let actualScanImgUrl = URL.createObjectURL(importSelectedFiles[activeScannedIndex]);
    const customStyle = `<style>.custom-hover-dropdown:hover { background-color: #343a40 !important; }</style>`;

    container.innerHTML = customStyle + `
    <div class="p-3 bg-black border border-warning rounded" style="border-style: dashed !important;">
        <div class="text-warning small fw-bold mb-3"><i class="fas fa-exclamation-triangle me-1"></i> AI phân vân. Vui lòng chọn mẫu khớp nhất:</div>
        
        <div class="d-flex flex-wrap align-items-center mb-3 pb-2 border-bottom border-secondary">
            ${imagesHtml}
        </div>

        <div class="d-flex align-items-center mb-3">
   
            <div class="flex-grow-1">
                <div class="dropdown w-100">
                    <button class="btn btn-dark border-secondary w-100 dropdown-toggle d-flex justify-content-between align-items-center text-start px-3 py-2" type="button" id="customSuggestionBtn" data-bs-toggle="dropdown" aria-expanded="false">
                        <span id="customSuggestionBtnText" class="text-white-50">-- Bấm chọn mẫu --</span>
                    </button>
                    <ul class="dropdown-menu w-100 bg-dark border border-secondary p-0 shadow" style="max-height: 250px; overflow-y: auto;">
                        ${listItemsHtml}
                    </ul>
                </div>
                <input type="hidden" id="import_suggestion_value" value="">
            </div>
        </div>
        <button class="btn btn-warning btn-sm w-100 fw-bold" onclick="confirmSuggestedMatch('${tempImage}')">XÁC NHẬN MẪU ĐÃ CHỌN</button>
    </div>`;
}

// 4.3 CÁC HÀM XỬ LÝ LỰA CHỌN DROPDOWN (ĐÃ FIX LỖI TÌM ẢNH)
window.selectCustomSuggestion = function (element) {
    const hiddenInput = document.getElementById('import_suggestion_value');
    if (hiddenInput) {
        hiddenInput.value = element.getAttribute('data-val');
    }

    // Đổi ảnh preview an toàn
    const parsedData = JSON.parse(element.getAttribute('data-val'));
    const previewImg = document.getElementById('import_suggestion_preview_img');
    if (previewImg) {
        previewImg.src = `assets/img_product/${parsedData.product_image}`;
    }

    // Cập nhật text trên nút bấm
    const textSpan = element.querySelector('span.fw-bold');
    const btnText = document.getElementById('customSuggestionBtnText');
    if (textSpan && btnText) {
        btnText.innerText = textSpan.innerText;
        btnText.className = "text-white fw-bold text-truncate";
    }
};

window.selectSuggestionImage = function (index) {
    const targetItem = document.getElementById(`suggestion_item_${index}`);
    if (targetItem) targetItem.click();
};

window.confirmSuggestedMatch = function (tempImage) {
    const hiddenInput = document.getElementById('import_suggestion_value');
    if (!hiddenInput || !hiddenInput.value) return alert("Vui lòng chọn 1 mẫu từ danh sách hoặc click vào hình!");

    let isSuccess = autoProcessMatch(JSON.parse(hiddenInput.value), tempImage);
    if (isSuccess) {
        let container = document.getElementById('import_suggestion_container');
        if (container) container.innerHTML = '';
    }
}
// =========================================================================

// 5. DROPDOWN SIZE DỰA THEO MÀU
function updateImportSizeDropdown() {
    const color = document.getElementById('import_color').value;
    const sizeDropdown = document.getElementById('import_size');

    sizeDropdown.innerHTML = '<option value="">-- Chọn size --</option>';
    sizeDropdown.disabled = true;

    if (!color || !window.currentMatchedItems) {
        return;
    }

    // Tắt hết highlight cũ
    window.currentMatchedItems.forEach(item => {
        let row = document.getElementById(`import_row_${item.variant_id}`);
        if (row) row.classList.remove('bg-info', 'bg-opacity-25', 'border-info');
    });

    let availableSizes = window.currentMatchedItems.filter(i => i.color === color);
    if (availableSizes.length === 0) {
        return;
    }

    sizeDropdown.disabled = false;
    availableSizes.forEach(item => {
        // ĐÃ THÊM data-sku="${item.sku || ''}" VÀO THẺ OPTION DƯỚI ĐÂY:
        sizeDropdown.innerHTML += `<option value="${item.size}" data-detail-id="${item.detail_id}" data-variant-id="${item.variant_id}" data-qty="${item.quantity}" data-sku="${item.sku || ''}">Size ${item.size}</option>`;

        let row = document.getElementById(`import_row_${item.variant_id}`);
        if (row) row.classList.add('bg-info', 'bg-opacity-25', 'border-info');
    });


    // AUTO-FILL: Khi đã chọn Màu, tự động chọn Size đầu tiên và tự động Fill số lượng + Phân bổ kệ luôn
    if (availableSizes.length >= 1) {
        sizeDropdown.value = availableSizes[0].size;
        autoFillImportQty();
    }
}
// 6. AUTO FILL SỐ LƯỢNG & THU HẸP HIGHLIGHT
function autoFillImportQty() {
    const sizeSelect = document.getElementById('import_size');
    if (!sizeSelect || !sizeSelect.value) return;

    const selectedOption = sizeSelect.options[sizeSelect.selectedIndex];
    if (!selectedOption || !selectedOption.value) return;

    const expectedQty = selectedOption.getAttribute('data-qty');
    const targetVariantId = selectedOption.getAttribute('data-variant-id');

    document.getElementById('import_expected_qty').value = expectedQty;
    document.getElementById('import_actual_qty').value = expectedQty;
    document.getElementById('import_detail_id').value = selectedOption.getAttribute('data-detail-id');
    document.getElementById('import_variant_id').value = targetVariantId;
    // THÊM ĐOẠN NÀY: Hiển thị mã SKU tương ứng lên màn hình cạnh tấm ảnh
    const skuCode = selectedOption.getAttribute('data-sku');
    if (activeScannedIndex !== -1) {
        let skuSpan = document.getElementById(`scan_sku_${activeScannedIndex}`);
        if (skuSpan) {
            // SỬA DÒNG NÀY: Hiện rõ cảnh báo nếu SKU bị trống trong Database
            if (skuCode && skuCode.trim() !== '') {
                skuSpan.innerText = 'SKU: ' + skuCode;
                skuSpan.className = 'fw-bold mt-1'; // Màu vàng
            } else {
                skuSpan.innerText = 'SKU: Đang trống trong Database';
                skuSpan.className = 'text-danger small fw-bold mt-1'; // Màu đỏ cảnh báo
            }
        }
    }



    if (window.currentMatchedItems) {
        window.currentMatchedItems.forEach(item => {
            let row = document.getElementById(`import_row_${item.variant_id}`);
            if (row) row.classList.remove('bg-info', 'bg-opacity-25', 'border-info');
        });
    }

    let activeRow = document.getElementById(`import_row_${targetVariantId}`);
    if (activeRow) activeRow.classList.add('bg-info', 'bg-opacity-25', 'border-info');

    checkImportDiscrepancy();

    // Sau khi chọn màu và size, tự động render các ô kệ và fill số lượng theo Logic First-Fit.
    window.lastPutawayQuantity = parseInt(expectedQty, 10) || 0;
    loadPutawayLocations(targetVariantId);

    const putawayContainer = document.getElementById('putaway_locations_container');
    if (putawayContainer) {
        putawayContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

// =========================================================================
// HÀM MỚI: TẢI DANH SÁCH Ô KỆ ĐỂ CẤT HÀNG (PUTAWAY) - ĐÃ TỐI ƯU RANKING SCORE
// =========================================================================
async function loadPutawayLocations(variantId) {
    let container = document.getElementById('putaway_locations_container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'putaway_locations_container';
        container.className = 'mt-3 p-3 rounded bg-black bg-opacity-25 border border-info';

        const btnSave = document.getElementById('btn_save_temp');
        btnSave.parentNode.insertBefore(container, btnSave);
    }

    container.innerHTML = '<div class="text-center text-info small"><i class="fas fa-spinner fa-spin"></i> Đang tải sơ đồ kệ...</div>';

    try {
        const quantity = parseInt(document.getElementById('import_actual_qty').value, 10) || 0;
        const res = await fetch(`index.php?page=tickets&action=get_putaway_locations&variant_id=${encodeURIComponent(variantId)}&ticket_id=${encodeURIComponent(currentImportTicketId)}&quantity=${quantity}`);
        const data = await res.json();

        const currentSlots = Array.isArray(data.current) ? data.current : [];
        const availableSlots = Array.isArray(data.available) ? data.available : [];
        const savedAllocations = (data.current_alloc && typeof data.current_alloc === 'object') ? data.current_alloc : {};
        const serverRecommendations = (data.recommended && typeof data.recommended === 'object') ? data.recommended : {};

        // Chuẩn hóa cấu trúc phân bổ từ Backend gửi về
        const normalizeRecommendations = (map) => {
            const normalized = {};
            if (!map || typeof map !== 'object') return normalized;

            Object.entries(map).forEach(([key, value]) => {
                let qty = 0;
                if (typeof value === 'object' && value !== null) {
                    qty = parseInt(value.qty || value.allocated_amount || 0, 10) || 0;
                } else {
                    qty = parseInt(value, 10) || 0;
                }
                if (qty > 0) normalized[key] = qty;
            });
            return normalized;
        };

        const recommendedAllocations = normalizeRecommendations(serverRecommendations);

        // Khởi tạo số lượng cần phân bổ còn lại (Ví dụ: 3 đôi)
        let remainingToFill = Math.max(0, quantity);

        const renderSlot = (slot, isCurrent, isFirst = false) => {
            let mark = isCurrent ? `<span class="text-warning small">(Đang có: ${slot.var_count})</span>` : '';
            let key = `${slot.shelf_id}_${slot.tier}_${slot.slot}`;
            const slotCode = slot.location_code || slot.slot_code || `${slot.shelf_name}_${slot.tier}-${slot.slot}`;

            const availSpace = Math.max(0, parseInt(slot.available, 10) || 0);

            let prefillQty = 0;

            // Ưu tiên 1: Lấy đúng số lượng đã lưu nháp trước đó (nếu có)
            if (savedAllocations[key] !== undefined && savedAllocations[key] > 0) {
                prefillQty = Math.min(savedAllocations[key], availSpace);
                remainingToFill -= prefillQty;
            }
            // Ưu tiên 2: Autofill từ số lượng còn lại cho đến khi remainingToFill = 0
            else if (remainingToFill > 0 && availSpace > 0) {
                prefillQty = Math.min(remainingToFill, availSpace);
                remainingToFill -= prefillQty; // GIẢM TRỪ NGAY LẬP TỨC ĐỂ CÁC Ô SAU KHÔNG BỊ FILL THÊM
            }

            let bestBadge = (isFirst && prefillQty > 0)
                ? `<span class="badge bg-success ms-1" style="font-size: 0.65rem;">⭐ Gợi ý tốt nhất</span>`
                : '';

            return `
    <div class="d-flex justify-content-between align-items-center bg-black p-2 rounded mb-2 border border-secondary putaway-row transition-all">
        <span class="text-white fw-bold small">
            Ô ${slotCode}
            <span class="text-white-50 ms-1">(Trống: ${slot.available})</span>
            ${mark}
            ${bestBadge}
        </span>
        <input type="number" 
               class="form-control form-control-sm text-center putaway-input fw-bold bg-dark text-info border-info" 
               style="width: 70px;" 
               data-shelf-id="${slot.shelf_id}" 
               data-shelf-name="${slot.shelf_name}" 
               data-tier="${slot.tier}" 
               data-slot="${slot.slot}" 
               data-location-code="${slotCode}"
               max="${Math.max(0, availSpace + prefillQty)}" 
               min="0" 
               value="${prefillQty}" 
               oninput="validatePutawayTotal()">
    </div>`;
        };

        const currentHtml = currentSlots.length > 0
            ? currentSlots.map((s, idx) => renderSlot(s, true, idx === 0)).join('')
            : '<div class="small text-white-50">Không có kệ nào đang chứa sẵn mẫu này.</div>';

        const availableHtml = availableSlots.length > 0
            ? availableSlots.map((s, idx) => renderSlot(s, false, currentSlots.length === 0 && idx === 0)).join('')
            : '<div class="small text-danger">Kho đã đầy, không còn kệ trống!</div>';

        // Tính tổng số lượng đã được phân bổ vào các input
        const allocatedTotal = Object.values(recommendedAllocations).reduce((sum, v) => sum + v, 0);
        const shortage = Math.max(0, quantity - allocatedTotal);
        const shortageHtml = shortage > 0
            ? `<div class="alert alert-warning py-2 mt-3 mb-0 small fw-bold"><i class="fas fa-exclamation-triangle me-1"></i>Thiếu ${shortage} vị trí lưu trữ trong kho</div>`
            : '';

        // Render toàn bộ giao diện Modal một lần duy nhất
        container.innerHTML = `
            <h6 class="fw-bold text-info mb-3 small"><i class="fas fa-boxes me-1"></i>CHỌN VỊ TRÍ KỆ ĐỂ CẤT HÀNG</h6>
            <div class="row g-3">
                <div class="col-md-6 border-end border-secondary">
                    <label class="small text-warning fw-bold mb-2">Đang chứa sẵn mẫu này</label>
                    <div class="d-flex flex-column" style="max-height: 200px; overflow-y: auto; padding-right: 5px;">${currentHtml}</div>
                </div>
                <div class="col-md-6">
                    <label class="small text-success fw-bold mb-2">Gợi ý các ô trống khác</label>
                    <div class="d-flex flex-column" style="max-height: 200px; overflow-y: auto; padding-right: 5px;">${availableHtml}</div>
                </div>
            </div>
            ${shortageHtml}
            <div id="putaway_warning_msg" class="mt-3 text-center fw-bold small"></div>
        `;

        // Kích hoạt hàm kiểm tra tổng số lượng sau khi render hoàn tất
        validatePutawayTotal();

    } catch (e) {
        container.innerHTML = '<div class="text-danger small">Lỗi truy xuất sơ đồ kệ.</div>';
    }
}
// Kiểm tra điều kiện phân bổ số lượng lên các kệ
function validatePutawayTotal() {
    const targetQty = parseInt(document.getElementById('import_actual_qty').value) || 0;
    let allocatedQty = 0;
    const inputs = document.querySelectorAll('.putaway-input');

    inputs.forEach(input => {
        let max = parseInt(input.max) || 0;
        let val = parseInt(input.value) || 0;

        if (val < 0) { val = 0; input.value = 0; }
        if (val > max) { val = max; input.value = max; }

        const row = input.closest('.putaway-row');
        if (val > 0) {
            row.classList.replace('border-secondary', 'border-success');
            row.style.backgroundColor = 'rgba(25, 135, 84, 0.2)'; // Hightlight ô đã chọn
        } else {
            row.classList.replace('border-success', 'border-secondary');
            row.style.backgroundColor = '';
        }

        allocatedQty += val;
    });

    const btnSave = document.getElementById('btn_save_temp');
    const msg = document.getElementById('putaway_warning_msg');

    // ĐÃ FIX: CHỐT CHẶN CHỐNG CRASH JAVASCRIPT
    // Nếu bảng kệ chưa kịp load xong (msg = null) thì return luôn để không bị sập JS
    if (!msg) return;

    if (targetQty === 0) {
        msg.innerHTML = '<span class="text-secondary">Vui lòng đếm số lượng thực tế cần nhập.</span>';
        btnSave.disabled = true;
    } else if (allocatedQty === targetQty) {
        msg.innerHTML = `<span class="text-success"><i class="fas fa-check-circle me-1"></i>Đã chọn đủ vị trí cho ${targetQty} đôi. Có thể Lưu Nháp!</span>`;
        btnSave.disabled = false;
    } else if (allocatedQty < targetQty) {
        msg.innerHTML = `<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Vui lòng chọn thêm kệ để cất ${targetQty - allocatedQty} đôi nữa.</span>`;
        btnSave.disabled = true;
    } else {
        msg.innerHTML = `<span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>Phân bổ dư ${allocatedQty - targetQty} đôi. Giảm bớt số lượng!</span>`;
        btnSave.disabled = true;
    }
}

// KIỂM TRA DƯ/THIẾU & TỰ ĐỘNG ĐIỀN GHI CHÚ
function checkImportDiscrepancy() {
    const exp = parseInt(document.getElementById('import_expected_qty').value) || 0;
    const act = parseInt(document.getElementById('import_actual_qty').value) || 0;
    const badge = document.getElementById('import_status_badge');
    const noteField = document.getElementById('import_note');
    const shoeName = document.getElementById('import_name').value || '';

    if (act === exp) {
        badge.className = 'badge bg-success w-100 p-2';
        badge.innerText = 'KHỚP SỐ LƯỢNG';
        if (noteField) {
            // Nếu đủ hàng, xóa ghi chú tự động đã tạo trước đó để giao diện gọn gàng
            if (noteField.value.startsWith('thiếu ') || noteField.value.startsWith('dư ')) {
                noteField.value = '';
            }
        }
    } else {
        badge.className = 'badge bg-warning text-dark w-100 p-2';
        let diff = act - exp;
        badge.innerText = `CÓ LỆCH: ${diff > 0 ? '+' : ''}${diff} ĐÔI`;

        if (noteField) {
            if (act < exp) {
                // Tự động điền ghi chú nếu thiếu hàng
                noteField.value = `thiếu ${exp - act} ${shoeName}`;
            } else {
                // Tự động điền ghi chú nếu dư hàng
                noteField.value = `dư ${act - exp} ${shoeName}`;
            }
        }
    }
    validatePutawayTotal();

    // Số lượng thực tế có thể được staff chỉnh sau khi AI điền sẵn.
    // Tải lại recommendation để First-Fit luôn khớp với số lượng mới.
    const variantId = document.getElementById('import_variant_id').value;
    if (variantId && window.lastPutawayQuantity !== act) {
        window.lastPutawayQuantity = act;
        loadPutawayLocations(variantId);
    }
}



// 7. LƯU BẢNG TẠM (CÓ GỬI KÈM MẢNG VỊ TRÍ KỆ)
async function saveTempImport(e) {
    e.preventDefault();

    const exp = parseInt(document.getElementById('import_expected_qty').value) || 0;
    const act = parseInt(document.getElementById('import_actual_qty').value) || 0;

    let allocatedQty = 0;
    document.querySelectorAll('.putaway-input').forEach(input => {
        allocatedQty += parseInt(input.value) || 0;
    });

    if (act > 0 && allocatedQty !== act) {
        alert(`LỖI: Bạn đang nhập ${act} đôi giày nhưng mới xếp ${allocatedQty} đôi lên kệ.\nVui lòng phân bổ đủ vị trí trên Sơ đồ Kệ trước khi nhấn Lưu!`);
        return;
    }

    if (act !== exp) {
        let msg = act < exp ? `Phát hiện THIẾU ${exp - act} đôi.` : `Phát hiện DƯ ${act - exp} đôi.`;
        if (!confirm(`${msg}\nBạn có chắc chắn muốn ghi nhận số lượng thực tế là ${act} không?`)) return;
    }

    const form = e.target;
    const btn = document.getElementById('btn_save_temp');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ĐANG LƯU...';
    btn.disabled = true;

    let putawayLocations = [];
    document.querySelectorAll('.putaway-input').forEach(input => {
        let val = parseInt(input.value) || 0;
        if (val > 0) {
            putawayLocations.push({
                shelf_id: input.getAttribute('data-shelf-id'),
                shelf_name: input.getAttribute('data-shelf-name'),
                tier: input.getAttribute('data-tier'),
                slot: input.getAttribute('data-slot'),
                variant_id: document.getElementById('import_variant_id').value,
                qty: val
            });
        }
    });

    const fd = new FormData(form);
    fd.append('ticket_id', currentImportTicketId);
    fd.append('putaway_locations', JSON.stringify(putawayLocations));

    try {
        const res = await fetch('index.php?page=tickets&action=save_temp_import', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.status === 'success') {

            //ĐOẠN NÀY ĐỂ HIỆN QR
            if (data.qr_code) {
                const qrImage = `https://quickchart.io/qr?text=${encodeURIComponent(data.qr_code)}&size=200&ecLevel=H`;

                const qrModalHtml = `
                    <div id="temp_qr_overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9999; display:flex; justify-content:center; align-items:center;">
                        <div class="bg-white p-4 rounded text-center shadow-lg" style="width:300px;">
                            <h5 class="fw-bold text-success mb-3"><i class="fas fa-check-circle"></i> ĐÃ LƯU BIẾN THỂ</h5>
                            <img src="${qrImage}" class="img-fluid border p-2 rounded mb-3">
                            <p class="small text-muted mb-3">Quét mã để dán lên lô hàng</p>
                            <button class="btn btn-dark w-100 fw-bold" onclick="document.getElementById('temp_qr_overlay').remove()">TIẾP TỤC QUÉT</button>
                        </div>
                    </div>
                `;
                document.body.insertAdjacentHTML('beforeend', qrModalHtml);
            }
            loadImportTicketItems();

            if (window.currentMatchedItems && activeScannedIndex !== -1) {
                window.currentMatchedItems = window.currentMatchedItems.filter(i => i.detail_id != fd.get('detail_id'));
                if (window.currentMatchedItems.length === 0 && importScannedData[activeScannedIndex]) {
                    importScannedData[activeScannedIndex].is_done = true;
                }
            }

            document.getElementById('import_form_zone').classList.remove('d-flex');
            document.getElementById('import_form_zone').classList.add('d-none');

            let container = document.getElementById('putaway_locations_container');
            if (container) container.remove();

            if (activeScannedIndex !== -1) renderImportPostScan();

        } else { alert("Lỗi: " + data.message); }
    } catch (err) {
        alert("Lỗi kết nối ngầm!");
    } finally {
        btn.innerHTML = 'LƯU NHÁP BIẾN THỂ NÀY';
        btn.disabled = false;
    }
}

// 8. CHỐT SỔ CUỐI CÙNG (CÓ CONFIRM GHI NHẬN TRẠNG THÁI)
async function completeImportTicket() {
    if (!confirm("Hệ thống sẽ cộng tồn kho, lưu đồ thị mảng Kệ và ghi nhận trạng thái (KHỚP / THIẾU / DƯ). Bạn chắc chắn chốt phiếu?")) return;

    const btn = document.getElementById('btn_complete_import');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ĐANG XỬ LÝ...';
    btn.disabled = true;

    try {
        const fd = new FormData();
        fd.append('ticket_id', currentImportTicketId);

        const res = await fetch('index.php?page=tickets&action=complete_import', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.status === 'success') {
            alert(`NHẬP KHO THÀNH CÔNG!\nTrạng thái phiếu: ${data.final_status || 'Hoàn tất'}`);
            window.location.reload();
        } else {
            alert("Lỗi: " + data.message);
        }
    } catch (e) {
        alert("Lỗi hệ thống.");
    } finally {
        btn.innerHTML = 'XÁC NHẬN HOÀN TẤT PHIẾU NÀY';
        btn.disabled = false;
    }
}

// =========================================================================
// HÀM MỚI: BẤM VÀO DANH SÁCH BÊN TRÁI ĐỂ SỬA TAY 
// =========================================================================
function editImportItem(variantId) {
    const item = importTicketItems.find(i => i.variant_id == variantId);

    if (!item || parseInt(item.processed_qty || 0) === 0) {
        return;
    }

    window.currentMatchedItems = [item];
    activeScannedIndex = -1;

    document.getElementById('import_form_zone').classList.remove('d-none');
    document.getElementById('import_form_zone').classList.add('d-flex');

    document.getElementById('import_brand').value = item.brand || "";
    document.getElementById('import_name').value = item.product_name || "";
    document.getElementById('import_temp_image').value = item.product_image || "default_shoe.png";

    const colorDropdown = document.getElementById('import_color');
    const sizeDropdown = document.getElementById('import_size');
    // Gán sự kiện khi chọn Màu -> Tự cập nhật Size và Gợi ý Kệ
    colorDropdown.onchange = function () {
        updateImportSizeDropdown();
    };

    // Gán sự kiện khi chọn Size -> Tự điền số lượng và Gợi ý Kệ
    sizeDropdown.onchange = function () {
        autoFillImportQty();
    };

    colorDropdown.innerHTML = `<option value="${item.color}">${item.color}</option>`;
    sizeDropdown.innerHTML = `<option value="${item.size}" data-detail-id="${item.detail_id}" data-variant-id="${item.variant_id}" data-qty="${item.quantity}">Size ${item.size}</option>`;
    sizeDropdown.disabled = false;

    document.getElementById('import_expected_qty').value = item.quantity;
    document.getElementById('import_actual_qty').value = item.processed_qty || 0;
    document.getElementById('import_detail_id').value = item.detail_id;
    document.getElementById('import_variant_id').value = item.variant_id;

    if (document.getElementById('import_note')) {
        document.getElementById('import_note').value = item.note || '';
    }

    document.querySelectorAll('[id^="import_row_"]').forEach(el => {
        el.classList.remove('bg-info', 'bg-opacity-25', 'border-info');
    });
    const activeRow = document.getElementById(`import_row_${variantId}`);
    if (activeRow) activeRow.classList.add('bg-info', 'bg-opacity-25', 'border-info');

    checkImportDiscrepancy();

    // Mở bảng cho phép xếp kệ lại từ đầu nếu sửa số lượng
    loadPutawayLocations(variantId);
}

/**
 * Chức năng: Thoát khỏi chi tiết phiếu nhập hiện tại quay về danh sách phiếu nhập gốc.
 * Tác dụng: Trả trạng thái phiếu từ PROCESSING về lại PAUSED để treo tiến trình cho lần sau và tải lại danh sách.
 */
function backToImportTickets() {
    fetch(`index.php?page=tickets&action=update_status&ticket_id=${currentImportTicketId}&status=PAUSED`, { method: 'POST' });
    loadMyImportTickets();
}
