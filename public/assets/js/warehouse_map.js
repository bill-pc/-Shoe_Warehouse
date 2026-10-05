// File: public/assets/js/warehouse_map.js
document.addEventListener('DOMContentLoaded', function () {

    // ==========================================
    // LOGIC TÌM KIẾM VÀ NHÁY SÁNG Ô CHỨA (BẢN AJAX CHUẨN)
    // ==========================================
    const searchInput = document.getElementById('mapSearchInput');
    const searchSuggestions = document.getElementById('mapSearchSuggestions');
    let searchTimeout = null;

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const keyword = this.value.trim();

            // 1. Xóa tất cả highlight cũ mỗi khi gõ từ khóa mới
            document.querySelectorAll('.shelf-cell').forEach(c => c.classList.remove('slot-highlighted'));
            searchSuggestions.innerHTML = '';

            if (keyword.length < 1) {
                searchSuggestions.classList.add('d-none');
                return;
            }

            // 2. Chống spam request (Debounce 300ms)
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                searchSuggestions.classList.remove('d-none');
                searchSuggestions.innerHTML = '<li class="list-group-item bg-dark text-info"><i class="fas fa-spinner fa-spin me-2"></i>Đang tìm...</li>';

                // 3. Gọi AJAX lên Controller
                fetch(`index.php?page=products&action=search_map&keyword=${encodeURIComponent(keyword)}`)
                    .then(res => res.json())
                    .then(results => {
                        searchSuggestions.innerHTML = '';
                        if (results.length > 0) {
                            results.forEach(item => {
                                const li = document.createElement('li');
                                li.className = 'list-group-item list-group-item-action bg-dark text-white border-secondary d-flex align-items-center cursor-pointer';
                                li.style.cursor = 'pointer';
                                li.innerHTML = `
                                    <img src="${item.image}" class="rounded me-3 border border-secondary" style="width: 40px; height: 40px; object-fit: cover;">
                                    <div class="flex-grow-1 lh-sm">
                                        <strong class="d-block text-truncate" style="max-width: 250px;">${item.name}</strong>
                                        <small class="text-info">${item.brand} | Size: ${item.size}</small>
                                    </div>
                                    <span class="badge bg-secondary">${item.cells.length} Ô</span>
                                `;

                                // Khi click vào một kết quả gợi ý
                                li.addEventListener('click', function () {
                                    searchInput.value = item.name;
                                    searchSuggestions.classList.add('d-none');

                                    // Nháy sáng tất cả các ô chứa đôi giày này
                                    item.cells.forEach((code, index) => {
                                        const targetCell = document.querySelector(`.shelf-cell[data-code="${code}"]`);
                                        if (targetCell) {
                                            targetCell.classList.add('slot-highlighted');
                                            // Cuộn màn hình đến ô đầu tiên tìm thấy
                                            if (index === 0) {
                                                targetCell.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                            }
                                        }
                                    });
                                });
                                searchSuggestions.appendChild(li);
                            });
                        } else {
                            searchSuggestions.innerHTML = '<li class="list-group-item bg-dark text-white-50 border-secondary text-center">Không tìm thấy giày này...</li>';
                        }
                    })
                    .catch(err => {
                        console.error('Lỗi tìm kiếm:', err);
                        searchSuggestions.classList.add('d-none');
                    });
            }, 300);
        });
    }
});

//Tạo bảng lưới kéo - vẽ, tạo hiệu ứng hover (isDragging), xử lý alert Confirm và gọi fetch API lên Controller.
// ====== CHỨC NĂNG THÊM/SỬA/XÓA KỆ ======
let isDraggingGrid = false;

function showAddShelfModal() {
    renderGrid12x12();
    let modal = new bootstrap.Modal(document.getElementById('addShelfModal'));
    modal.show();
}

function renderGrid12x12() {
    const container = document.getElementById('shelf_grid_container');
    container.innerHTML = '';

    for (let r = 1; r <= 12; r++) { // 12 Tầng
        for (let c = 1; c <= 12; c++) { // 12 Ô mỗi tầng
            let box = document.createElement('div');
            box.style.width = '100%';
            box.style.paddingTop = '100%';
            box.style.background = '#444';
            box.style.cursor = 'crosshair';
            box.dataset.row = r;
            box.dataset.col = c;

            box.addEventListener('mousedown', () => { isDraggingGrid = true; highlightGrid(r, c); });
            box.addEventListener('mouseenter', () => { if (isDraggingGrid) highlightGrid(r, c); });
            box.addEventListener('mouseup', () => { isDraggingGrid = false; });

            container.appendChild(box);
        }
    }
    document.addEventListener('mouseup', () => { isDraggingGrid = false; });
    highlightGrid(4, 6); // Mặc định tô 4 tầng 6 ô
}

function highlightGrid(targetRow, targetCol) {
    const boxes = document.getElementById('shelf_grid_container').children;
    for (let box of boxes) {
        let r = parseInt(box.dataset.row);
        let c = parseInt(box.dataset.col);

        if (r <= targetRow && c <= targetCol) {
            box.style.background = '#0dcaf0'; // Màu xanh đánh dấu
        } else {
            box.style.background = '#444'; // Xám chưa chọn
        }
    }
    document.getElementById('grid_result').innerText = `${targetRow} Tầng x ${targetCol} Ô`;
    document.getElementById('selected_tiers').value = targetRow;
    document.getElementById('selected_slots').value = targetCol;
}

function submitAddShelf() {
    let name = document.getElementById('new_shelf_name').value.trim();
    let cap = document.getElementById('new_shelf_capacity').value;
    let tiers = document.getElementById('selected_tiers').value;
    let slots = document.getElementById('selected_slots').value;

    if (!name) return alert("Vui lòng nhập tên kệ!");
    if (!confirm(`Xác nhận tạo Kệ "${name}" gồm ${tiers} tầng, mỗi tầng ${slots} ô (Sức chứa ${cap} đôi/ô)?`)) return;

    let fd = new FormData();
    fd.append('shelf_name', name);
    fd.append('max_capacity', cap);
    fd.append('tiers', tiers);
    fd.append('slots', slots);

    fetch('index.php?page=warehouse_map&action=add_shelf', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            alert(data.message);
            if (data.status === 'success') window.location.reload();
        }).catch(err => alert("Lỗi kết nối mạng!"));
}

function deleteShelf(name) {
    if (!confirm(`⚠️ CẢNH BÁO: XÓA kệ "${name}"?\n(Điều kiện: Kệ phải hoàn toàn trống không có hàng hóa)`)) return;

    let fd = new FormData();
    fd.append('shelf_name', name);

    fetch('index.php?page=warehouse_map&action=delete_shelf', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            alert(data.message || (data.status === 'success' ? 'Xóa thành công' : ''));
            if (data.status === 'success') window.location.reload();
        }).catch(err => alert("Lỗi kết nối mạng!"));
}

function toggleShelfStatus(name) {
    if (!confirm(`BẬT/TẮT trạng thái kệ "${name}"?\n(Nếu muốn TẮT, kệ phải được dọn trống hoàn toàn trước)`)) return;

    let fd = new FormData();
    fd.append('shelf_name', name);

    fetch('index.php?page=warehouse_map&action=toggle_shelf', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                alert(`Cập nhật trạng thái thành công!`);
                window.location.reload();
            } else {
                alert(data.message);
            }
        }).catch(err => alert("Lỗi kết nối mạng!"));
}


//đổi tên kệ
function renameShelf(id, currentName) {
    let newName = prompt(`Nhập tên mới cho kệ "${currentName}":`, currentName);
    if (newName === null) return; // Người dùng ấn Hủy

    newName = newName.trim();
    if (!newName) return alert("Tên kệ không được để trống!");
    if (newName === currentName) return; // Không thay đổi gì

    let fd = new FormData();
    fd.append('shelf_id', id);
    fd.append('new_name', newName);

    fetch('index.php?page=warehouse_map&action=rename_shelf', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            alert(data.message);
            if (data.status === 'success') window.location.reload();
        }).catch(err => alert("Lỗi kết nối mạng!"));
}
