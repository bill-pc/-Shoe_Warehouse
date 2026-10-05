<!-- Dòng này cực kỳ quan trọng để JS lấy được ID nhân viên -->
<div id="user-context" data-user-id="<?= $_SESSION['user_id'] ?? 0 ?>" class="d-none"></div>
<link rel="stylesheet" href="assets/css/topbar.css">

<?php
$cModel = new CategoryModel();
// Lấy tất cả các hãng đang kinh doanh (status = true)
$allCategories = $cModel->getAll();
?>

<script>
    // Chuyển mảng PHP sang JSON để JavaScript sử dụng
    const categoriesList = <?= json_encode($allCategories) ?>;
    const QR_BASE_URL = "<?= (require __DIR__ . '/../../../config/QRconfig.php')['BASE_URL'] ?>";
    const PUSHER_CONFIG = <?= json_encode(require __DIR__ . '/../../../config/pusherconfig.php') ?>;
    const STOCK_CONFIG = <?= json_encode(require __DIR__ . '/../../../config/stockconfig.php') ?>;
</script>


<div class="topbar mb-4 d-flex justify-content-between align-items-center p-3 rounded shadow-sm ">

    <div class="search-box flex-grow-1 me-4 position-relative">
        <input type="text" id="mainSearch" class="form-control " placeholder="Tìm tên giày, size, mã SKU, màu sắc..." style="max-width: 400px;" autocomplete="off">

        <div id="searchResults" class="list-group position-absolute w-100 shadow mt-1 d-none"
            style="z-index: 1050; max-width: 400px; max-height: 400px; overflow-y: auto; border-radius: 4px;">
        </div>
    </div>

    <div class="user-info d-flex align-items-center">
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'STAFF'): ?>
            <button class="btn me-3 fw-bold position-relative" data-bs-toggle="modal" data-bs-target="#addProductModal" style="border-radius: 7px; border: 1px solid #000000;">
                Nhập Kho (AI)
                <!-- Badge Nhập -->
                <span id="badge-import" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none">0</span>
            </button>

            <button class="btn me-3 fw-bold position-relative" data-bs-toggle="modal" data-bs-target="#exportAIModal" style="border-radius: 7px; border: 1px solid #000000;">
                Xuất Kho
                <!-- Badge Xuất -->
                <span id="badge-export" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none">0</span>
            </button>
        <?php endif; ?>

        <div class="dropdown">
            <div class="d-flex align-items-center  px-3 py-2  rounded cursor-pointer dropdown-toggle"
                id="userMenu" data-bs-toggle="dropdown" aria-expanded="false" style="cursor: pointer;">
                <span class="fw-bold "><?= $_SESSION['full_name'] ?? 'Người dùng hệ thống' ?></span>
            </div>

            <ul class="dropdown-menu dropdown-menu-end shadow-sm boder mt-1" aria-labelledby="userMenu" style="border-radius: 4px;">
                <li>
                    <a class="dropdown-item py-2 " href="#" data-bs-toggle="modal" data-bs-target="#profileModal">
                        Thông tin tài khoản
                    </a>
                </li>
                <li>
                    <a class="dropdown-item py-2 " href="#" data-bs-toggle="modal" data-bs-target="#updateProfileModal">
                        Cập nhật bảo mật
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

<!-- modal nhập kho -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content shadow rounded-1">
            <div class="modal-header border-bottom flex-column align-items-start">
                <div class="d-flex w-100 justify-content-between align-items-center">
                    <h5 class="modal-title fw-bold text-white text-uppercase">NHẬP KHO BẰNG TRÍ TUỆ NHÂN TẠO (AI).</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
            </div>

            <div class="modal-body p-0">
                <div class="row g-0 d-flex" style="min-height: 550px;">

                    <div class="col-md-5 p-4 border-end-glass" style="background: rgba(0,0,0,0.2); max-height: 650px; overflow-y: auto;">

                        <div id="import_step_1">
                            <h6 class="fw-bold text-white pb-2 mb-3 border-bottom-glass">
                                <i class="fas fa-clipboard-list me-2"></i>PHIẾU NHẬP ĐANG CHỜ
                            </h6>
                            <div id="import_ticket_list" class="list-group gap-2"></div>
                        </div>

                        <div id="import_step_2" class="d-none">
                            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom-glass pb-2">
                                <h6 class="fw-bold text-info mb-0" id="import_current_ticket_code">MÃ PHIẾU: ...</h6>
                                <button class="btn btn-sm btn-outline-light py-0" onclick="backToImportTickets()">
                                    <i class="fas fa-arrow-left me-1"></i>Đổi phiếu
                                </button>
                            </div>
                            <p class="text-white-50 small mb-2">Hệ thống sẽ highlight dòng tương ứng khi AI nhận diện thành công:</p>
                            <div id="import_ticket_items" class="d-flex flex-column gap-2 mb-3"></div>

                            <button id="btn_complete_import" class="btn btn-success fw-bold w-100 py-2 d-none" onclick="completeImportTicket()">
                                XÁC NHẬN HOÀN TẤT PHIẾU NÀY
                            </button>
                        </div>
                    </div>

                    <div class="col-md-7 p-4 d-flex flex-column position-relative">
                        <div id="import_ai_upload_zone" class="mb-3 d-none">
                            <h6 class="fw-bold text-white pb-2 mb-2 border-bottom-glass">1. TẢI ẢNH ĐẠI DIỆN THÙNG HÀNG</h6>
                            <div class="d-flex gap-2">
                                <input type="file" id="ai_import_files" class="d-none" multiple accept="image/*" onchange="previewImportImages(this)">
                                <button type="button" class="btn btn-outline-info fw-bold btn-sm" onclick="document.getElementById('ai_import_files').click()">
                                    <i class="fas fa-upload me-1"></i>CHỌN ẢNH (CTRL ĐỂ CHỌN NHIỀU)
                                </button>
                                <button type="button" id="btn-scan-import" class="btn btn-info text-dark fw-bold btn-sm d-none" onclick="executeImportScan()">
                                    <i class="fas fa-magic me-1"></i>QUÉT AI
                                </button>
                            </div>
                            <div id="import_pre_scan_preview" class="d-flex flex-wrap gap-2 mt-2"></div>
                            <div id="import_post_scan_area" class="d-flex flex-wrap gap-2 mt-2"></div>
                        </div>

                        <div id="import_form_zone" class="d-none flex-grow-1 flex-column p-3 rounded bg-dark border border-secondary">
                            <h6 class="fw-bold text-warning pb-2 mb-3 border-bottom border-secondary text-uppercase">2. KẾT QUẢ SO KHỚP & NHẬP LIỆU</h6>
                            <form id="form_temp_import" onsubmit="saveTempImport(event)">
                                <div class="row g-2 mb-2">
                                    <div class="col-6">
                                        <label class="small text-white-50 fw-bold">HÃNG (AI NHẬN DIỆN)</label>
                                        <input type="text" id="import_brand" class="form-control form-control-sm bg-black text-white" readonly>
                                    </div>
                                    <div class="col-6">
                                        <label class="small text-white-50 fw-bold">MẪU GIÀY (AI NHẬN DIỆN)</label>
                                        <input type="text" id="import_name" class="form-control form-control-sm bg-black text-white fw-bold" readonly>
                                    </div>
                                </div>

                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label class="small text-info fw-bold">CHỌN MÀU (THEO PHIẾU)</label>
                                        <select id="import_color" class="form-select form-select-sm fw-bold border-info" onchange="updateImportSizeDropdown()" required></select>
                                    </div>
                                    <div class="col-6">
                                        <label class="small text-info fw-bold">CHỌN SIZE (THEO PHIẾU)</label>
                                        <select id="import_size" class="form-select form-select-sm fw-bold border-info" onchange="autoFillImportQty()" required disabled>
                                            <option value="">-- Chọn màu trước --</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="p-2 bg-black rounded mb-3 border border-secondary">
                                    <div class="row align-items-center">
                                        <div class="col-4">
                                            <label class="small text-white-50 fw-bold mb-1">SỐ LƯỢNG YÊU CẦU</label>
                                            <input type="number" id="import_expected_qty" class="form-control form-control-sm bg-dark text-warning fw-bold text-center" readonly>
                                        </div>
                                        <div class="col-4">
                                            <label class="small text-white fw-bold mb-1">THỰC TẾ ĐẾM ĐƯỢC</label>
                                            <input type="number" id="import_actual_qty" name="actual_qty" class="form-control form-control-sm text-center fw-bold" required min="0" oninput="checkImportDiscrepancy()">
                                        </div>
                                        <div class="col-4">
                                            <label class="small text-white-50 fw-bold mb-1">TÌNH TRẠNG LÔ HÀNG</label>
                                            <div id="import_status_badge" class="badge bg-secondary w-100 p-2">CHƯA NHẬP DỮ LIỆU</div>
                                            <input type="hidden" id="import_discrepancy_type" name="discrepancy_type" value="MATCH">
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="small text-white-50 fw-bold">GHI CHÚ (NẾU CÓ SAI LỆCH)</label>
                                    <textarea name="note" id="import_note" class="form-control form-control-sm bg-dark text-white" rows="2" placeholder="Ví dụ: Rách hộp, thiếu hàng do NSX..."></textarea>
                                </div>

                                <input type="hidden" id="import_variant_id" name="variant_id">
                                <input type="hidden" id="import_detail_id" name="detail_id">
                                <input type="hidden" id="import_temp_image" name="scanned_image">

                                <button type="submit" id="btn_save_temp" class="btn btn-info text-dark fw-bold w-100 py-2">LƯU NHÁP BIẾN THỂ NÀY</button>
                            </form>
                        </div>

                        <div id="import_right_default" class="text-center text-white-50 m-auto">
                            <i class="fas fa-boxes fa-3x mb-3 opacity-25"></i>
                            <p>Chọn phiếu nhập để bắt đầu quy trình AI</p>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- modal xuất kho -->
<div class="modal fade" id="exportAIModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content shadow rounded-1">
            <div class="modal-header border-bottom flex-column align-items-start">
                <div class="d-flex w-100 justify-content-between align-items-center mb-2">
                    <h5 class="modal-title fw-bold text-white text-uppercase">XUẤT KHO THEO PHIẾU CHỈ ĐỊNH</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0">
                <div class="row g-0 d-flex" style="min-height: 550px;">

                    <!-- CỘT TRÁI: ĐIỀU HƯỚNG (DANH SÁCH PHIẾU -> DANH SÁCH GIÀY) -->
                    <div class="col-md-5 p-4 border-end-glass" style="background: rgba(0,0,0,0.2); max-height: 650px; overflow-y: auto;">

                        <!-- Màn hình 1: Danh sách phiếu -->
                        <div id="export_step_1">
                            <h6 class="fw-bold text-white pb-2 mb-3 border-bottom-glass">
                                <i class="fas fa-clipboard-list me-2"></i>PHIẾU CHỜ XỬ LÝ
                            </h6>
                            <div id="export_ticket_list" class="list-group gap-2">
                                <!-- Trống -->
                            </div>
                        </div>

                        <!-- Màn hình 2: Danh sách giày trong phiếu -->
                        <div id="export_step_2" class="d-none">
                            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom-glass pb-2">
                                <h6 class="fw-bold text-info mb-0" id="export_current_ticket_code">MÃ PHIẾU: ...</h6>
                                <button class="btn btn-sm btn-outline-light py-0" onclick="backToExportTickets()">
                                    <i class="fas fa-arrow-left me-1"></i>Đổi phiếu
                                </button>
                            </div>
                            <p class="text-white-50 small mb-2">Vui lòng nhấn vào biến thể để xem vị trí và xuất kho:</p>
                            <div id="export_ticket_items" class="d-flex flex-column gap-2 mb-3">
                                <!-- Trống -->
                            </div>
                            <button id="btn_complete_export" class="btn btn-success fw-bold w-100 py-2 d-none" onclick="completeExportTicket()">
                                XÁC NHẬN HOÀN TẤT PHIẾU NÀY
                            </button>
                        </div>
                    </div>

                    <!-- CỘT PHẢI: FORM XUẤT KHO AUTO-FILL -->
                    <div class="col-md-7 p-4 d-flex flex-column border-start border-secondary border-opacity-25 position-relative">
                        <h6 class="fw-bold text-white pb-2 mb-3 text-uppercase border-bottom-glass">Biểu mẫu chi tiết</h6>

                        <div id="export_right_default" class="text-center text-white-50 m-auto">
                            <i class="fas fa-file-invoice fa-3x mb-3 opacity-25"></i>
                            <p>Chọn sản phẩm để điền dữ liệu tự động</p>
                        </div>

                        <div id="export_right_action" class="d-none flex-grow-1 flex-column">
                            <div class="row g-3">
                                <div class="col-md-4 text-center">
                                    <label class="small text-white-50 fw-bold d-block mb-2 text-start">ẢNH MINH HỌA</label>
                                    <img id="autofill_image" src="" class="img-fluid rounded border border-secondary p-1 bg-white" style="max-height: 120px;">
                                </div>
                                <div class="col-md-8">
                                    <div class="mb-2">
                                        <label class="small text-white-50 fw-bold">MÃ PHIẾU</label>
                                        <input type="text" id="autofill_ticket_code" class="form-control form-control-sm bg-dark text-info fw-bold" readonly>
                                    </div>
                                    <div class="mb-2">
                                        <label class="small text-white-50 fw-bold">HÃNG SẢN XUẤT</label>
                                        <input type="text" id="autofill_brand" class="form-control form-control-sm bg-dark text-white" readonly>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="small text-white-50 fw-bold">TÊN SẢN PHẨM</label>
                                    <input type="text" id="autofill_name" class="form-control form-control-sm bg-dark text-white fw-bold" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="small text-white-50 fw-bold">MÀU SẮC</label>
                                    <input type="text" id="autofill_color" class="form-control form-control-sm bg-dark text-white" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="small text-white-50 fw-bold">KÍCH THƯỚC (SIZE)</label>
                                    <input type="text" id="autofill_size" class="form-control form-control-sm bg-dark text-white text-center" readonly>
                                </div>
                            </div>

                            <div id="other_variants_area" class="mt-3 p-2 rounded bg-black bg-opacity-25 border border-secondary d-none">
                                <label class="small text-info fw-bold mb-1"><i class="fas fa-layer-group me-1"></i>SẢN PHẨM CÙNG MẪU TRONG PHIẾU:</label>
                                <div id="other_variants_list" class="small text-white-50"></div>
                            </div>

                            <div class="mt-3 p-3 rounded bg-dark border border-warning shadow-sm">
                                <h6 class="fw-bold text-warning mb-2 small"><i class="fas fa-map-marker-alt me-1"></i>VỊ TRÍ LẤY HÀNG:</h6>
                                <div id="pick_locations_container" class="d-flex flex-wrap gap-2"></div>
                            </div>

                            <div class="mt-auto pt-3 border-top border-secondary">
                                <label class="fw-bold small text-white-50 mb-1">XÁC NHẬN SỐ LƯỢNG LẤY:</label>
                                <div class="input-group">
                                    <input type="number" id="pick_qty_input" class="form-control form-control-lg bg-dark text-white text-center fw-bold border-info" value="1" min="1">
                                    <span class="input-group-text bg-info text-dark fw-bold" id="pick_qty_max">/ 0</span>
                                </div>
                                <input type="hidden" id="pick_detail_id">
                                <input type="hidden" id="pick_variant_id">

                                <button class="btn btn-glass-confirm w-100 fw-bold py-2 mt-3" onclick="confirmPickItem()">XÁC NHẬN LẤY HÀNG</button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header ">
                <h5 class="modal-title fw-bold" id="profileModalLabel">Thông Tin Tài Khoản</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <?php
                $uModel = new UserModel();
                $uData = $uModel->getUserById($_SESSION['user_id']);
                ?>
                <div class="table-responsive  rounded">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <tbody>
                            <tr>
                                <th style="width: 35%;" class="ps-3">Mã nhân viên (ID)</th>
                                <td class="fw-bold"><?= $uData['user_id'] ?></td>
                            </tr>

                            <tr>
                                <th class="ps-3">Họ và tên</th>
                                <td><?= $uData['full_name'] ?></td>
                            </tr>
                            <tr>
                                <th class="ps-3">Quyền hạn</th>
                                <td><span class="badge "><?= $uData['role'] === 'MANAGER' ? 'QUẢN LÝ KHU VỰC' : 'NHÂN VIÊN KHO' ?></span></td>
                            </tr>
                            <tr>
                                <th class="ps-3">Số điện thoại liên hệ</th>
                                <td><?= !empty($uData['phone_number']) ? $uData['phone_number'] : '<span class="text-muted">Chưa cập nhật</span>' ?></td>
                            </tr>
                            <tr>
                                <th class="ps-3">Địa chỉ thường trú</th>
                                <td class="small"><?= !empty($uData['address']) ? $uData['address'] : '<span class="text-muted">Chưa cập nhật</span>' ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer  ">
                <button type="button" class="btn btn-outline-secondary fw-bold rounded-1 px-4" data-bs-dismiss="modal">Đóng</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="updateProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content shadow">
            <form id="formUpdateProfile" action="index.php?page=<?= $_GET['page'] ?? 'dashboard' ?>" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Cập Nhật Thông Tin Bảo Mật</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">

                    <div id="profile-msg-container"></div>

                    <div class="row">
                        <div class="col-md-5">
                            <h6 class="mb-3 fw-bold text-dark pb-2">Thông tin lưu trữ hiện tại</h6>
                            <div class="t p-3 rounded">
                                <table class="table">
                                    <tbody>
                                        <tr>
                                            <td class="text-secondary fw-bold">SĐT:</td>
                                            <td class="text-end"><?= !empty($uData['phone_number']) ? htmlspecialchars($uData['phone_number']) : '<span class="text-muted fw-normal">Trống</span>' ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-secondary fw-bold">Địa chỉ:</td>
                                            <td class="text-end"><?= !empty($uData['address']) ? htmlspecialchars($uData['address']) : '<span class="text-muted fw-normal">Trống</span>' ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-7 ps-4">
                            <h6 class="fw-bold mb-3 text-dark pb-2">Biểu mẫu thay đổi dữ liệu</h6>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-secondary">Họ và tên mới</label>
                                <input type="text" name="full_name" class="form-control rounded-1" value="<?= htmlspecialchars($uData['full_name']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-secondary">Số điện thoại mới</label>
                                <input type="text" name="phone_number" class="form-control rounded-1">
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold text-secondary">Địa chỉ mới</label>
                                <textarea name="address" class="form-control rounded-1" rows="2"></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-secondary">Mật khẩu mới (Bỏ trống nếu không đổi)</label>
                                <input type="password" name="new_password" class="form-control rounded-1">
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold text-danger mb-2">XÁC THỰC QUYỀN TRUY CẬP</label>
                                <input type="password" name="old_password" class="form-control rounded-1" required placeholder="Nhập mật khẩu hiện tại để lưu">
                                <small class="text-muted mt-1 d-block">Đây là bước bắt buộc để đảm bảo an toàn dữ liệu kho.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary fw-bold rounded-1 px-4" data-bs-dismiss="modal">Hủy bỏ</button>
                    <button type="submit" name="btn_update_profile" class=" btn btn-outline-secondary fw-bold rounded-1 px-4">Lưu Dữ Liệu</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    /* CSS Tinh gọn, rõ nét, chuẩn form nghiệp vụ */
    #searchResults .list-group-item {
        border: none;
        border-bottom: 1px solid #dee2e6;
        transition: background 0.1s;
    }

    #searchResults .list-group-item:hover {
        background-color: #f8f9fa;
        padding-left: 18px;
    }

    .upload-zone {
        transition: border-color 0.2s;
    }

    .upload-zone:hover {
        border-color: #ffffff !important;
    }
</style>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- GIỮ NGUYÊN LOGIC TÌM KIẾM ---
        const searchInput = document.getElementById('mainSearch');
        const resultsBox = document.getElementById('searchResults');

        searchInput.addEventListener('input', function() {
            let keyword = this.value.trim();
            if (keyword.length > 0) {
                fetch(`index.php?page=search_ajax&keyword=${encodeURIComponent(keyword)}`)
                    .then(response => response.json())
                    .then(data => {
                        resultsBox.innerHTML = '';
                        if (data.length > 0) {
                            data.forEach(item => {
                                resultsBox.innerHTML += `<a href="index.php?page=products&category_id=${item.category_id}#product_${item.product_id}" class="list-group-item list-group-item-action d-flex align-items-center"><img src="assets/img_product/${item.product_image || 'default_shoe.png'}" style="width: 50px; height: 40px; object-fit: contain;" class="rounded-1  me-3"><div><div class="fw-bold text-dark mb-0">${item.product_name}</div><span class="text-secondary small">Xem chi tiết tồn kho</span></div></a>`;
                            });
                            resultsBox.classList.remove('d-none');
                        } else {
                            resultsBox.innerHTML = '<div class="list-group-item text-secondary text-center py-3 fw-bold">Không tìm thấy thông tin sản phẩm khớp.</div>';
                            resultsBox.classList.remove('d-none');
                        }
                    });
            } else {
                resultsBox.classList.add('d-none');
            }
        });

        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !resultsBox.contains(e.target)) resultsBox.classList.add('d-none');
        });
    });
</script>
<script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
<script src="assets/js/import.js"></script>
<script src="assets/js/export.js"></script>
<script src="assets/js/profile.js"></script>
<script src="assets/js/topbar.js"></script>