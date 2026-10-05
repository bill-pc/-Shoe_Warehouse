document.addEventListener('DOMContentLoaded', function () {

    // ==========================================
    // 1. CHỨC NĂNG XUẤT KHO (STAFF)
    // ==========================================
    window.confirmExport = function (form) {
        const qty = parseInt(form.quantity.value);
        const stock = parseInt(form.current_stock.value);

        if (qty > stock) {
            alert("Số lượng xuất (" + qty + ") không được lớn hơn tồn kho (" + stock + ")!");
            return false;
        }
        if (qty <= 0) {
            alert("Số lượng xuất phải lớn hơn 0 nhé!");
            return false;
        }

        return confirm("Xác nhận xuất " + qty + " đôi này khỏi kho?");
    };

    // ==========================================
    // 2. CHỨC NĂNG ĐIỀU CHUYỂN NỘI BỘ KHO (MANAGER)
    // HỖ TRỢ CHIA NHỎ NHIỀU ĐÍCH
    // ==========================================
    window.currentOpenVid = null;
    window.moveState = {
        vid: null,
        sku: null,
        locs: [],
        source: null,
        destinations: [], // Mảng lưu các ô đích: { loc: 'A1-01', qty: 2 }
        sourceQty: 0,
        unallocatedQty: 0 // Số lượng chưa phân bổ vào đích
    };

    // SỔ ROW XUỐNG VÀ LOAD BẢN ĐỒ
    window.toggleMoveMap = function (vid, sku, locsBase64) {
        const mapRow = document.getElementById(`map-row-${vid}`);
        const bsCollapse = new bootstrap.Collapse(document.getElementById(`collapseMap-${vid}`), { toggle: false });

        if (window.currentOpenVid === vid) {
            closeMoveMap(vid);
            return;
        }

        if (window.currentOpenVid !== null) {
            closeMoveMap(window.currentOpenVid);
        }

        window.currentOpenVid = vid;
        window.moveState = {
            vid: vid,
            sku: sku,
            locs: JSON.parse(atob(locsBase64)),
            source: null,
            destinations: [],
            sourceQty: 0,
            unallocatedQty: 0
        };

        mapRow.classList.remove('d-none');
        bsCollapse.show();

        const statusDiv = document.getElementById(`moveMapStatus-${vid}`);
        const containerDiv = document.getElementById(`moveMapContainer-${vid}`);
        document.getElementById(`btnConfirmMove-${vid}`).disabled = true;

        statusDiv.innerHTML = "<div class='alert alert-info m-0 border-info py-2'>Bước 1: Vui lòng click chọn <b>Ô NGUỒN</b> đang chứa giày (Các ô đang nhấp nháy đỏ).</div>";
        containerDiv.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border text-primary" role="status"></div> Đang tải bản đồ...</div>';

        fetch('index.php?page=products&action=get_mini_heatmap')
            .then(r => r.text())
            .then(html => {
                containerDiv.innerHTML = html;
                setupMapInteractions(vid);
            });
    };

    // ĐÓNG SƠ ĐỒ KHO
    window.closeMoveMap = function (vid) {
        const collapseEl = document.getElementById(`collapseMap-${vid}`);
        if (!collapseEl) return;

        const bsCollapse = bootstrap.Collapse.getInstance(collapseEl);
        if (bsCollapse) bsCollapse.hide();

        setTimeout(() => {
            const mapRow = document.getElementById(`map-row-${vid}`);
            if (mapRow) mapRow.classList.add('d-none');
        }, 300);

        if (window.currentOpenVid === vid) window.currentOpenVid = null;
    };

    // TƯƠNG TÁC SƠ ĐỒ ĐƯỢC GIỚI HẠN TRONG DÒNG ĐÓ
    window.setupMapInteractions = function (vid) {
        const container = document.getElementById(`moveMapContainer-${vid}`);
        const cells = container.querySelectorAll('.shelf-cell');
        const validSources = window.moveState.locs.map(l => l.loc);
        const btnConfirm = document.getElementById(`btnConfirmMove-${vid}`);

        cells.forEach(cell => {
            const code = cell.getAttribute('data-code');
            const occ = parseInt(cell.getAttribute('data-occupancy'));
            const maxCap = parseInt(cell.getAttribute('data-max')) || 4;

            if (validSources.includes(code)) {
                cell.classList.add('blinking-source');
            }

            cell.addEventListener('click', function () {
                // CHƯA CHỌN NGUỒN
                if (!window.moveState.source) {
                    if (!validSources.includes(code)) {
                        alert("Vui lòng chọn ô ĐANG CHỨA GIÀY (các ô đang nhấp nháy)!");
                        return;
                    }

                    const maxSource = window.moveState.locs.find(l => l.loc === code).qty;
                    let qty = prompt(`CHỌN Ô NGUỒN: ${code}\nBạn muốn lấy ra bao nhiêu đôi để chuyển đi? (Tối đa ${maxSource}):`, maxSource);
                    qty = parseInt(qty);

                    if (isNaN(qty) || qty <= 0 || qty > maxSource) {
                        alert("Số lượng lấy ra không hợp lệ!");
                        return;
                    }

                    window.moveState.source = code;
                    window.moveState.sourceQty = qty;
                    window.moveState.unallocatedQty = qty;
                    window.moveState.destinations = [];

                    cells.forEach(c => c.classList.remove('blinking-source'));
                    this.classList.add('selected-source');

                    updateMoveStatus(vid);
                }
                // ĐÃ CHỌN NGUỒN (CHUYỂN SANG GIAI ĐOẠN CLICK CHỌN ĐÍCH)
                else {
                    // 1. Nếu click lại ô nguồn -> Hỏi Hủy bỏ làm lại
                    if (code === window.moveState.source) {
                        if (!confirm("Bạn muốn HỦY thao tác hiện tại và làm lại từ đầu?")) return;
                        window.moveState.source = null;
                        window.moveState.destinations = [];
                        this.classList.remove('selected-source');
                        cells.forEach(c => c.classList.remove('selected-dest'));
                        setupMapInteractions(vid); // Reset nháy sáng nguồn
                        document.getElementById(`moveMapStatus-${vid}`).innerHTML = "<div class='alert alert-info m-0 border-info py-2'>Bước 1: Vui lòng click chọn <b>Ô NGUỒN</b>.</div>";
                        btnConfirm.disabled = true;
                        return;
                    }

                    // 2. Nếu click vào một ô đích ĐÃ CHỌN TRƯỚC ĐÓ -> Hủy bỏ việc xếp vào ô đó
                    const existingDestIndex = window.moveState.destinations.findIndex(d => d.loc === code);
                    if (existingDestIndex > -1) {
                        const removedQty = window.moveState.destinations[existingDestIndex].qty;
                        window.moveState.destinations.splice(existingDestIndex, 1);
                        window.moveState.unallocatedQty += removedQty;
                        this.classList.remove('selected-dest');
                        updateMoveStatus(vid);
                        return;
                    }

                    // 3. Nếu đã phân bổ hết sạch số lượng -> Báo cho người dùng biết
                    if (window.moveState.unallocatedQty === 0) {
                        alert("Tuyệt vời! Đã phân bổ đủ số lượng. Nhấn XÁC NHẬN để lưu.\n(Nếu muốn đổi ý, click vào ô đích vừa chọn để Hủy).");
                        return;
                    }

                    // 4. Kiểm tra sức chứa ô đích
                    const freeSpace = maxCap - occ;
                    if (freeSpace <= 0) {
                        alert(`Ô ${code} đã ĐẦY! Vui lòng chọn ô khác còn trống.`);
                        return;
                    }

                    // Đề xuất điền nốt phần còn dư (không vượt quá khoảng trống)
                    const suggestQty = Math.min(window.moveState.unallocatedQty, freeSpace);
                    let qty = prompt(`Ô ĐÍCH: ${code}\nSức chứa còn trống: ${freeSpace} đôi.\nĐang dư: ${window.moveState.unallocatedQty} đôi cần phân bổ.\n\nNhập số lượng xếp vào ô này:`, suggestQty);
                    qty = parseInt(qty);

                    if (isNaN(qty) || qty <= 0 || qty > freeSpace) {
                        alert("Số lượng nhập vào không hợp lệ hoặc lớn hơn sức chứa ô đích!");
                        return;
                    }
                    if (qty > window.moveState.unallocatedQty) {
                        alert("Số lượng chuyển vào không được lớn hơn số lượng còn dư chưa phân bổ!");
                        return;
                    }

                    // Thêm đích vào danh sách, trừ bớt số chưa phân bổ
                    window.moveState.destinations.push({ loc: code, qty: qty });
                    window.moveState.unallocatedQty -= qty;
                    this.classList.add('selected-dest');

                    updateMoveStatus(vid);
                }
            });
        });
    };

    function updateMoveStatus(vid) {
        const statusDiv = document.getElementById(`moveMapStatus-${vid}`);
        const btnConfirm = document.getElementById(`btnConfirmMove-${vid}`);

        let destText = window.moveState.destinations.map(d => `<span class='badge bg-light text-dark fs-6'>${d.loc} (<b>${d.qty}</b>)</span>`).join(' + ');
        if (!destText) destText = "(Chưa chọn)";

        let msg = `<div class='alert alert-warning m-0 border-warning py-2'>
            <b>Nguồn:</b> ${window.moveState.source} (Lấy ra ${window.moveState.sourceQty} đôi).<br>
            <b>Đã phân bổ vào:</b> ${destText}.<br>`;

        if (window.moveState.unallocatedQty > 0) {
            msg += `<span class='text-danger'>Còn <b>${window.moveState.unallocatedQty}</b> đôi chưa phân bổ. Hãy click chọn thêm ô đích!</span>`;
            btnConfirm.disabled = true;
        } else {
            msg += `<span class='text-success fw-bold'><i class='fas fa-check-circle'></i> Tuyệt vời! Đã phân bổ xong toàn bộ. Nhấn Xác Nhận!</span>`;
            btnConfirm.disabled = false;
        }
        msg += `</div>`;
        statusDiv.innerHTML = msg;
    }

    // GỬI LỆNH ĐIỀU CHUYỂN LÊN SERVER
    window.executeVisualMove = async function (vid) {
        if (window.moveState.unallocatedQty > 0) {
            alert("Vẫn còn số lượng chưa phân bổ vào đích! Vui lòng hoàn thành.");
            return;
        }
        if (!confirm(`Xác nhận thực hiện điều chuyển từ ${window.moveState.source} sang các đích đã chọn?`)) return;

        try {
            const response = await fetch('index.php?page=products&action=move_location', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    variant_id: window.moveState.vid,
                    from_loc: window.moveState.source,
                    destinations: window.moveState.destinations // Mảng đích mới
                })
            });
            const res = await response.json();
            if (res.status === 'success') {
                alert(res.message);
                location.reload();
            } else {
                alert("Lỗi: " + res.message);
            }
        } catch (e) {
            alert("Lỗi kết nối máy chủ.");
        }
    };


    // ==========================================
    // 3. TỰ ĐỘNG BẬT MODAL & HIGHLIGHT THEO URL PARAMS
    // ==========================================
    const urlParams = new URLSearchParams(window.location.search);
    const openModalId = urlParams.get('open_modal');
    const highlightVid = urlParams.get('highlight_vid');

    if (openModalId) {
        // 1. Mở Modal chứa Product ID
        const targetModalEl = document.getElementById(`detailModal${openModalId}`);
        if (targetModalEl) {
            const myModal = new bootstrap.Modal(targetModalEl);
            myModal.show();

            // 2. Chờ Modal mở xong (animation) rồi mới cuộn chuột và nhấp nháy
            targetModalEl.addEventListener('shown.bs.modal', function () {
                if (highlightVid) {
                    const targetRow = document.getElementById(`variant-row-${highlightVid}`);
                    if (targetRow) {
                        // Trượt xuống dòng đó cho người dùng thấy
                        targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });

                        // Bật Class nhấp nháy viền vàng
                        targetRow.classList.add('highlight-variant-row');
                    }
                }
            });
        }

        // Dọn dẹp URL cho sạch sẽ (Xóa tham số đi để F5 không bị bật Modal lại)
        window.history.replaceState({}, document.title, window.location.pathname + "?page=products&category_id=" + urlParams.get('category_id'));
    }

});


function printVariantQR(sku, name, color, size, vid) {
    // 1. Kiểm tra an toàn
    if (!vid || vid === 'undefined') {
        alert("Lỗi: Không tìm thấy ID biến thể (variant_id). Vui lòng kiểm tra lại dữ liệu!");
        return;
    }

    // 2. Tạo link truy xuất chuẩn (Link Ngrok)
    const baseUrl = window.QR_BASE_URL || "https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/";

    const targetUrl = `${baseUrl}check_QR.php?vid=${vid}`;

    // 3. Dùng QuickChart tạo link ảnh
    const qrImageUrl = `https://quickchart.io/qr?text=${encodeURIComponent(targetUrl)}&size=250`;

    // 4. Render giao diện Modal
    const html = `
        <div class="text-center">
            <p class="mb-1"><strong>${name}</strong></p>
            <p class="small text-muted mb-2">${color} - Size ${size}</p>
            <img src="${qrImageUrl}" style="width: 250px; height: 250px;" class="border p-2 shadow-sm rounded">
            <p class="mt-3 mb-0 small text-secondary">MÃ TRUY XUẤT HỆ THỐNG</p>
            <p class="fw-bold text-dark">SKU: ${sku}</p>
        </div>
    `;

    // 5. Đẩy HTML vào Modal và hiển thị
    document.getElementById('qrContentArea').innerHTML = html;
    const modal = new bootstrap.Modal(document.getElementById('simpleQRModal'));
    modal.show();
}



// ==========================================
// 4. THAY ĐỔI ẢNH ĐẠI DIỆN SẢN PHẨM & SINH VECTOR
// ==========================================
window.submitAvatarChange = function (productId) {
    const form = document.getElementById(`form-update-avatar-${productId}`);
    if (form) {
        if (confirm("Bạn có chắc chắn muốn thay đổi ảnh đại diện cho sản phẩm này? Hệ thống sẽ gửi ảnh mới đến AI để sinh trích xuất đặc trưng đại diện và cập nhật dữ liệu mới.")) {
            form.submit(); // Thực hiện ấn lưu sau khi xác nhận
        } else {
            // Reset input file nếu người dùng hủy
            const fileInput = document.getElementById(`input-avatar-${productId}`);
            if (fileInput) {
                fileInput.value = "";
            }
        }
    }
};


function validateAddVariant(form) {
    const size = parseFloat(form.size.value);

    // Nếu size nhỏ hơn hoặc bằng 0 thì báo lỗi Alert ngay lập tức
    if (isNaN(size) || size <= 0) {
        alert("⚠️ Lỗi: Size giày bắt buộc phải lớn hơn 0!");
        form.size.focus();
        return false;
    }

    return confirm('Bạn có chắc chắn muốn khai báo thêm biến thể Màu/Size mới cho mẫu giày này không?');
}

