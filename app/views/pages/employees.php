<?php
// Dữ liệu $users đã được index.php lấy từ Controller
$error = $error ?? '';
?>

<link rel="stylesheet" href="assets/css/employees.css">

<?php include __DIR__ . '/../layouts/topbar.php'; ?>

<div class="employees-container">
    <?php if (isset($_SESSION['success'])): ?>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                setTimeout(function() {
                    alert(<?= json_encode($_SESSION['success']) ?>);
                }, 100);
            });
        </script>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                setTimeout(function() {
                    alert(<?= json_encode($_SESSION['error']) ?>);
                }, 100);
            });
        </script>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- HEADER & TOOLBAR (Gộp chung thành 1 hàng) -->
    <div class="employees-header d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800">QUẢN LÝ NHÂN VIÊN</h1>

        <!-- KHU VỰC TÌM KIẾM VÀ LỌC (Nằm giữa) -->
        <div class="glass-filter-form d-flex gap-2 rounded" style="flex-grow: 0.5; max-width: 600px;">
            <input type="text" id="searchInput" class="glass-input form-control"
                placeholder="Nhập ID, Tên..." style="flex: 1; height: 40px;">

            <select id="roleFilter" class="glass-input form-select" style="width: 145px; height: 40px;">
                <option value="">Tất cả vai trò</option>
                <option value="Admin">Admin</option>
                <option value="Manager">Manager</option>
                <option value="Staff">Staff</option>
            </select>
        </div>

        <?php if ($_SESSION['role'] === 'ADMIN'): ?>
            <button class="btn btn-add-brand px-4 fw-bold text-dark border border-dark d-flex align-items-center justify-content-center"
                data-bs-toggle="modal" data-bs-target="#addModal" style="height: 40px;">
                <i class="fas fa-plus me-2"></i> Thêm nhân viên
            </button>
        <?php endif; ?>
    </div>

    <!-- BẢNG NHÂN VIÊN -->
    <table class="employees-table" id="employeesTable">
        <thead>
            <tr>
                <th>ID</th>
                <th>Họ tên</th>
                <th>Vai trò</th>
                <th>Trạng thái</th>
                <th>Thao tác</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($users)): ?>
                <?php foreach ($users as $user): ?>
                    <!-- Thêm class employee-row để JS dễ tìm -->
                    <tr class="employee-row">
                        <td class="col-id"><?= $user['user_id'] ?></td>
                        <td class="col-name"><?= htmlspecialchars($user['full_name']) ?></td>
                        <td class="col-role"><span class="badge" style="color: #374151;"><?= ucfirst($user['role']) ?></span></td>
                        <td>
                            <?php $isActive = ($user['status'] === 't' || $user['status'] === true || $user['status'] == 1); ?>
                            <span class="badge <?= $isActive ? 'active' : 'inactive' ?>">
                                <?= $isActive ? 'Đang hoạt động' : 'Tạm ngưng' ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($user['user_id'] == $_SESSION['user_id']): ?>
                                <button type="button" class="btn-action btn-edit text-dark fw-bold border border-dark bg-light px-3 py-1" data-bs-toggle="modal" data-bs-target="#updateProfileModal" style="border-radius: 4px; font-size: 12px;">Cập nhật thông tin (Bạn)</button>
                            <?php else: ?>
                                <?php if ($_SESSION['role'] === 'ADMIN'): ?>
                                    <button class="btn-action btn-edit btn-edit-user"
                                        data-bs-toggle="modal" data-bs-target="#editModal"
                                        data-user="<?= htmlspecialchars(json_encode($user), ENT_QUOTES, 'UTF-8') ?>">Sửa</button>
                                <?php endif; ?>

                                <?php if ($_SESSION['role'] === 'ADMIN' && $user['user_id'] != $_SESSION['user_id']): ?>
                                    <form method="POST" action="index.php?page=employees" style="display:inline;" onsubmit="return confirm('Bạn có chắc chắn muốn xóa nhân viên này?')">
                                        <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                        <button type="submit" name="delete" class="btn-action btn-delete">Xóa</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr id="noDataRow">
                    <td colspan="6" style="text-align:center;">Chưa có nhân viên nào.</td>
                </tr>
            <?php endif; ?>
            <!-- Dòng báo lỗi khi tìm không thấy (Mặc định ẩn) -->
            <tr id="noResultRow" style="display: none;">
                <td colspan="5" class="text-center py-4 fw-bold text-muted">Không tìm thấy nhân viên nào phù hợp!</td>
            </tr>
        </tbody>
    </table>
</div>

<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 600px;">
        <div class="modal-content glass-modal">
            <div class="modal-header">
                <h5>Thêm nhân viên mới</h5>
                <span class="modal-close" data-bs-dismiss="modal">×</span>
            </div>
            <div class="modal-body">
                <form method="POST" action="index.php?page=employees">
                    <label>Họ tên</label>
                    <input type="text" name="full_name" placeholder="Nhập họ tên" required>

                    <label>Mật khẩu</label>
                    <input type="password" name="password" value="12345" placeholder="Mật khẩu" required>
                    <div class="form-text mt-1 text-muted" style="font-size: 12px;">
                        <i class="fas fa-info-circle me-1"></i> Mật khẩu ban đầu mặc định là <strong>12345</strong>.
                    </div>


                    <label>Vai trò</label>
                    <select name="role">
                        <option value="staff">Staff</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                    </select>

                    <div class="form-check mb-3 d-flex align-items-center" style="gap: 10px; margin-top: 15px;">
                        <input type="checkbox" name="status" id="status_add" class="form-check-input" checked
                            style="width: 20px !important; height: 20px !important; cursor: pointer; margin: 0;">
                        <label class="form-check-label" for="status_add" style="cursor: pointer; margin: 0; white-space: nowrap; line-height: 1;">
                            Kích hoạt tài khoản
                        </label>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" name="add_user" class="btn-save">Lưu</button>
                        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Hủy</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 600px;">
        <div class="modal-content glass-modal">
            <div class="modal-header">
                <h5>Cập nhật trạng thái nhân viên</h5>
                <span class="modal-close" data-bs-dismiss="modal">×</span>
            </div>
            <div class="modal-body">
                <form method="POST" action="index.php?page=employees"
                    onsubmit="return confirm('Bồ có chắc chắn muốn thay đổi trạng thái hoạt động của nhân viên này không?')">

                    <input type="hidden" name="user_id" id="edit_user_id">

                    <label>Họ tên nhân viên:</label>
                    <input type="text" id="edit_full_name" readonly>

                    <label>Ngày tạo tài khoản:</label>
                    <input type="text" id="edit_created_at" readonly>

                    <div class="form-check mb-3 d-flex align-items-center" style="gap: 10px; margin-top: 15px;">
                        <input type="checkbox" name="status" id="edit_status" class="form-check-input" style="width: 20px !important; height: 20px !important; cursor: pointer; margin: 0;">
                        <label class="form-check-label" for="edit_status" style="font-weight: bold; cursor: pointer; margin: 0; white-space: nowrap; line-height: 1;">
                            Kích hoạt tài khoản
                        </label>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" name="update" class="btn-save">Cập nhật</button>
                        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Hủy</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/employees.js"></script>