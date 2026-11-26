// ==========================================
// LOGIC XUẤT KHO THEO PHIẾU (THỦ CÔNG)
// ==========================================

let currentExportTicketId = null;
let currentExportTicketCode = "";
let currentTicketItems = [];

// Khi mở Modal Xuất Kho -> Load danh sách phiếu
document.getElementById('exportAIModal').addEventListener('show.bs.modal', loadMyExportTickets);

/**
 * Chức năng: Tải danh sách các phiếu xuất kho đang chờ xử lý.
 * Tác dụng: Gọi API lấy dữ liệu phiếu được giao cho nhân viên và hiển thị lên cột bên trái của giao diện Modal.
 */
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

/**
 * Chức năng: Mở xem chi tiết các đôi giày cần lấy trong 1 mã phiếu xuất.
 * Tác dụng: Chuyển màn hình sang bước 2, đổi trạng thái phiếu thành PROCESSING để khóa không cho người khác thao tác trùng.
 */
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

/**
 * Chức năng: Render danh sách giày cần xuất kho ra giao diện bên trái.
 * Tác dụng: Tạo các thẻ hiển thị sản phẩm, thanh tiến trình nhặt hàng và kiểm tra điều kiện để mở khóa nút Hoàn Tất Phiếu.
 */
function renderExportItems() {
    const container = document.getElementById('export_ticket_items');
    let allDone = true;

    container.innerHTML = currentTicketItems.map(item => {
        const qty = parseInt(item.quantity) || 0;
        const processed = parseInt(item.processed_qty) || 0;
        const remaining = qty - processed;
        const isDone = remaining <= 0;

        if (!isDone) allDone = false;

        // Luôn luôn cho phép nhấp vào dòng biến thể để chỉnh sửa giống nhập kho
        return `
            <div class="p-3 rounded border cursor-pointer ${isDone ? 'bg-success bg-opacity-25 border-success' : 'bg-dark border-secondary'}"
                 onclick="autoFillExportForm(${item.detail_id})">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold small ${isDone ? 'text-success' : 'text-white'}">${item.product_name}</span>
                    <span class="badge ${isDone ? 'bg-success' : 'bg-danger'}">${processed} / ${qty}</span>
                </div>
                <div class="d-flex justify-content-between align-items-end">
                    <div class="small text-white-50">
                        Size: <strong class="text-white">${item.size}</strong> | Màu: <strong class="text-white">${item.color}</strong>
                    </div>
                    ${isDone ? '<span class="text-success small fw-bold"><i class="fas fa-check-circle me-1"></i>Đã xong</span>' : ''}
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



/**
 * Chức năng: Thoát khỏi chi tiết phiếu hiện tại quay về danh sách phiếu gốc.
 * Tác dụng: Trả trạng thái phiếu từ PROCESSING về lại PAUSED để treo tiến trình cho lần làm việc sau.
 */
function backToExportTickets() {
    fetch(`index.php?page=tickets&action=update_status&ticket_id=${currentExportTicketId}&status=PAUSED`, { method: 'POST' });
    loadMyExportTickets();
}

let currentRequiredQty = 0;

/**
 * Chức năng: Đổ dữ liệu tự động vào Form Xuất kho bên phải khi bấm nút CHỌN.
 * Tác dụng: Trích xuất vị trí Ô, Kệ từ chuỗi JSONB, hiển thị ảnh mẫu và các sản phẩm cùng loại giúp NV đi lấy hàng đúng chỗ.
 */
async function autoFillExportForm(detailId) {
    const item = currentTicketItems.find(i => i.detail_id == detailId);
    if (!item) return;

    // Khi mở bảng chọn, yêu cầu phân bổ đủ tổng số lượng của biến thể đó
    const totalQty = parseInt(item.quantity);
    currentRequiredQty = totalQty;

    document.getElementById('export_right_default').classList.add('d-none');
    document.getElementById('export_right_action').classList.remove('d-none');
    document.getElementById('export_right_action').classList.add('d-flex');

    document.getElementById('autofill_ticket_code').value = currentExportTicketCode;
    document.getElementById('autofill_brand').value = item.brand;
    document.getElementById('autofill_name').value = item.product_name;
    document.getElementById('autofill_color').value = item.color;
    document.getElementById('autofill_size').value = item.size;
    document.getElementById('autofill_image').src = `assets/img_product/${item.product_image || 'default_shoe.png'}`;

    document.getElementById('pick_detail_id').value = item.detail_id;
    document.getElementById('pick_variant_id').value = item.variant_id;

    const inputQty = document.getElementById('pick_qty_input');
    inputQty.value = 0;
    inputQty.readOnly = true;
    document.getElementById('pick_qty_max').innerText = `/ ${totalQty}`;

    const othersArea = document.getElementById('other_variants_area');
    const othersList = document.getElementById('other_variants_list');
    if (item.other_variants && item.other_variants.length > 0) {
        othersArea.classList.remove('d-none');
        othersList.innerHTML = item.other_variants.map(o => `• Màu ${o.color}, Size ${o.size} (Cần lấy: ${o.quantity})`).join('<br>');
    } else {
        othersArea.classList.add('d-none');
    }

    const locContainer = document.getElementById('pick_locations_container');
    locContainer.innerHTML = '<span class="text-white-50 small"><i class="fas fa-spinner fa-spin"></i> Đang dò vị trí kệ...</span>';

    try {
        const params = new URLSearchParams({
            variant_id: item.variant_id,
            required_qty: totalQty,
            detail_id: item.detail_id
        });
        const res = await fetch(`index.php?page=tickets&action=get_locations&${params.toString()}`);
        const response = await res.json();
        const locs = Array.isArray(response) ? response : (response.slots || []);
        const recommended = Array.isArray(response.recommended) ? response.recommended : [];

        if (!locs || locs.length === 0) {
            locContainer.innerHTML = '<span class="badge bg-danger p-2">Mẫu này hiện không có sẵn trên kệ!</span>';
            return;
        }

        // Đọc các vị trí đã tạm lưu trước đó (nếu có) từ trường note
        let savedLocs = [];
        if (item.note) {
            try {
                savedLocs = JSON.parse(item.note);
            } catch (e) {
                savedLocs = [];
            }
        }

        const hasSavedLocations = Array.isArray(savedLocs) && savedLocs.some(location => (parseInt(location.qty) || 0) > 0);
        const recommendedBySlot = new Map(
            recommended.map(location => [`${location.shelf_id}|${location.slot_code}`, parseInt(location.qty) || 0])
        );

        let html = `
            <div class="w-100 mb-2 position-relative">
                <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="#94a3b8" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                     style="position:absolute; left:12px; top:50%; transform:translateY(-50%); pointer-events:none; z-index:2;">
                    <circle cx="11" cy="11" r="7"></circle>
                    <path d="m20 20-3.5-3.5"></path>
                </svg>
                <input type="search" class="form-control form-control-sm bg-dark text-white border-secondary"
                       style="padding-left:36px;" autocomplete="off"
                       placeholder="Tìm mã ô, kệ, tầng (VD: A_1, Kệ B)..."
                       aria-label="Tìm vị trí lấy hàng" oninput="filterPickLocations(this.value)">
            </div>`;
        locs.forEach(l => {
            // Tìm số lượng đã tạm lưu trên kệ/ô này
            const savedItem = Array.isArray(savedLocs) ? savedLocs.find(s => s.shelf_id == l.shelf_id && s.slot_code == l.slot_code) : null;
            const slotKey = `${l.shelf_id}|${l.slot_code}`;
            const recommendedQty = recommendedBySlot.get(slotKey) || 0;
            const selectedQty = hasSavedLocations
                ? (savedItem ? parseInt(savedItem.qty) || 0 : 0)
                : recommendedQty;
            const isRecommended = recommendedQty > 0;
            const breakdown = l.score_breakdown || {};
            const rawTier = String(l.slot_code).split('-')[0];
            const searchText = `kệ ${l.shelf_name} ${l.shelf_name} ô ${l.slot_code} ${l.slot_code} tầng ${l.tier || rawTier} ${l.shelf_name}_${rawTier}`;

            html += `
            <div class="pick-location-row w-100 bg-black bg-opacity-25 p-2 rounded mb-2 border ${isRecommended ? 'border-success shadow-sm' : 'border-secondary'}"
                 data-pick-search="${escapeExportHtml(normalizePickSearch(searchText))}">
                <div class="d-flex justify-content-between align-items-center gap-2">
                    <div class="text-white fw-bold small">
                        <div>
                            Kệ ${escapeExportHtml(l.shelf_name)} - Ô ${escapeExportHtml(l.slot_code)}
                            <span class="text-warning">(Tồn: ${parseInt(l.qty_in_slot) || 0})</span>
                        </div>
                        <div class="d-flex align-items-center flex-wrap gap-1 mt-1">
                            ${isRecommended ? '<span class="badge bg-success">⭐ Đề xuất lấy hàng</span>' : ''}
                            <button type="button" class="badge rounded-pill bg-secondary border-0 text-light"
                                    style="cursor:pointer;" aria-expanded="false"
                                    onclick="togglePickScoreDetails(this)">${Number(l.score || 0).toFixed(2)} điểm ▾</button>
                        </div>
                    </div>
                    <input type="number" class="form-control form-control-sm text-center pick-input fw-bold bg-dark text-info border-info"
                           style="width: 80px;" data-shelf-id="${l.shelf_id}" data-slot="${escapeExportHtml(l.slot_code)}"
                           min="0" max="${parseInt(l.qty_in_slot) || 0}" value="${Math.min(selectedQty, parseInt(l.qty_in_slot) || 0)}" oninput="validatePickTotal()">
                </div>
                <div class="pick-score-details d-none mt-2 p-2 rounded border border-secondary bg-dark small text-white-50">
                    <div class="fw-bold text-info mb-1">Xếp hạng: Top #${parseInt(breakdown.rank) || 0} ưu tiên</div>
                    ${renderPickScoreLine('Ưu tiên nhập trước FIFO 35%', breakdown.fifo)}
                    ${renderPickScoreLine('Dọn trống ô lẻ 30%', breakdown.consolidation)}
                    ${renderPickScoreLine('Gom 1 lần lấy 25%', breakdown.single_stop)}
                    ${renderPickScoreLine('Tầm với tầng 10%', breakdown.ergonomics)}
                </div>
            </div>`;
        });
        locContainer.innerHTML = html;
        validatePickTotal();

    } catch (e) {
        locContainer.innerHTML = '<span class="text-danger small">Lỗi truy xuất sơ đồ kho.</span>';
    }
}

function normalizePickSearch(value) {
    return String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'D')
        .toLowerCase()
        .trim();
}

function escapeExportHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderPickScoreLine(label, detail) {
    const weighted = Number(detail && detail.weighted ? detail.weighted : 0).toFixed(2);
    const reason = escapeExportHtml(detail && detail.reason ? detail.reason : 'Chưa có dữ liệu');
    return `
        <div class="d-flex justify-content-between gap-2 border-top border-secondary py-1">
            <span><span class="text-white">${label}:</span> ${reason}</span>
            <strong class="text-info text-nowrap">+${weighted}</strong>
        </div>`;
}

function togglePickScoreDetails(button) {
    const details = button.closest('.pick-location-row')?.querySelector('.pick-score-details');
    if (!details) return;

    const isOpening = details.classList.contains('d-none');
    details.classList.toggle('d-none', !isOpening);
    button.setAttribute('aria-expanded', isOpening ? 'true' : 'false');
    button.textContent = button.textContent.replace(isOpening ? '▾' : '▴', isOpening ? '▴' : '▾');
}

function filterPickLocations(query) {
    const normalizedQuery = normalizePickSearch(query);
    document.querySelectorAll('#pick_locations_container .pick-location-row').forEach(row => {
        row.classList.toggle('d-none', normalizedQuery !== '' && !row.dataset.pickSearch.includes(normalizedQuery));
    });
}


/**
 * Chức năng: Kiểm tra liên tục tổng số lượng hàng đang lấy từ các kệ khác nhau.
 * Tác dụng: Ngăn chặn nhân viên nhặt sai số lượng yêu cầu (báo lỗi Dư/Thiếu và khóa nút Submit).
 */
function validatePickTotal() {
    let totalPicked = 0;
    const inputs = document.querySelectorAll('.pick-input');

    inputs.forEach(input => {
        let max = parseInt(input.max) || 0;
        let val = parseInt(input.value) || 0;

        if (val < 0) { val = 0; input.value = 0; }
        if (val > max) { val = max; input.value = max; }

        totalPicked += val;
    });

    const totalInput = document.getElementById('pick_qty_input');
    totalInput.value = totalPicked;

    const btnConfirm = document.querySelector('#export_right_action button');

    let warningMsg = document.getElementById('pick_warning_msg');
    if (!warningMsg) {
        warningMsg = document.createElement('div');
        warningMsg.id = 'pick_warning_msg';
        warningMsg.className = 'small fw-bold mt-2 text-center mb-2';
        btnConfirm.parentNode.insertBefore(warningMsg, btnConfirm);
    }

    if (totalPicked === currentRequiredQty) {
        totalInput.classList.replace('text-white', 'text-success');
        btnConfirm.disabled = false;
        warningMsg.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i> Đã chọn đủ số lượng yêu cầu!</span>';
    }
    else if (totalPicked < currentRequiredQty) {
        totalInput.classList.replace('text-success', 'text-white');
        btnConfirm.disabled = true;
        let thieu = currentRequiredQty - totalPicked;
        warningMsg.innerHTML = `<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i> Bạn đang lấy THIẾU ${thieu} đôi. Vui lòng chọn thêm ở kệ khác!</span>`;
    }
    else {
        totalInput.classList.replace('text-success', 'text-white');
        btnConfirm.disabled = true;
        let du = totalPicked - currentRequiredQty;
        warningMsg.innerHTML = `<span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i> Bạn đang chọn DƯ ${du} đôi so với phiếu. Vui lòng giảm bớt!</span>`;
    }
}

/**
 * Chức năng: Lưu tiến độ xuất kho của một biến thể cụ thể.
 * Tác dụng: Đẩy dữ liệu số lượng đã lấy về CSDL, cập nhật mảng biến thể, và dọn dẹp form.
 */
async function confirmPickItem() {
    const detailId = document.getElementById('pick_detail_id').value;
    const totalPicked = parseInt(document.getElementById('pick_qty_input').value);

    let pickedLocations = [];
    document.querySelectorAll('.pick-input').forEach(input => {
        let val = parseInt(input.value) || 0;
        if (val > 0) {
            pickedLocations.push({
                shelf_id: input.getAttribute('data-shelf-id'),
                slot_code: input.getAttribute('data-slot'),
                qty: val
            });
        }
    });

    const btn = document.querySelector('#export_right_action button');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>ĐANG LƯU...';

    try {
        const fd = new FormData();
        fd.append('detail_id', detailId);
        fd.append('picked_qty', totalPicked);
        fd.append('picked_locations', JSON.stringify(pickedLocations));

        const res = await fetch('index.php?page=tickets&action=update_export_progress', {
            method: 'POST',
            body: fd
        });
        const data = await res.json();

        if (data.status === 'success') {
            const item = currentTicketItems.find(i => i.detail_id == detailId);
            if (item) {
                // Lưu trực tiếp số lượng và mảng vị trí vào dữ liệu local
                item.processed_qty = totalPicked;
                item.note = JSON.stringify(pickedLocations);
            }

            renderExportItems();
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


/**
 * Chức năng: Chốt sổ phiếu xuất kho.
 * Tác dụng: Khởi chạy luồng gọi Server để cập nhật trừ số lượng vật lý trên kho và giải phóng hàng tồn.
 */
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
