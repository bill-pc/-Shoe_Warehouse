<link rel="stylesheet" href="assets/css/history.css">

<!-- Thêm topbar nếu bồ cần -->
<?php include __DIR__ . '/../layouts/topbar.php'; ?>

<div class="history-container mt-4">
    <!-- Tiêu đề lịch sử giao dịch -->
    <div class="mb-3">
        <h2 class="m-0 fw-bold text-dark">LỊCH SỬ GIAO DỊCH KHO</h2>
    </div>

    <!-- Form lọc chia đều hai bên col-6 -->
    <form method="GET" action="index.php" class="glass-filter-form p-3 rounded mb-4">
        <input type="hidden" name="page" value="history">
        <div class="row align-items-center g-3">
            <!-- Cột trái (col-6): Tìm kiếm -->
            <div class="col-5">
                <div class="d-flex align-items-center">
                    <label class="text-dark fw-bold me-2 mb-0 d-none d-lg-block" style="white-space: nowrap;">
                        <i class="fas fa-search me-1"></i>Tìm kiếm:
                    </label>
                    <input type="text" name="search" class="glass-input form-control form-control-sm w-100" placeholder="Mã phiếu hoặc mã nhân viên..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                </div>
            </div>

            <!-- Cột phải (col-6): Lọc ngày & Nút hành động dồn về bên phải -->
            <div class="col-7">
                <div class="d-flex align-items-center justify-content-end gap-2 flex-wrap">
                    <label class="text-dark fw-bold mb-0 d-none d-lg-block" style="white-space: nowrap;">
                        <i class="fas fa-calendar-alt me-1"></i>Thời gian:
                    </label>
                    <input type="date" name="from_date" class="glass-input form-control form-control-sm" style="width: auto;" value="<?= $_GET['from_date'] ?? '' ?>">
                    <span class="fw-bold text-dark small">đến</span>
                    <input type="date" name="to_date" class="glass-input form-control form-control-sm" style="width: auto;" value="<?= $_GET['to_date'] ?? '' ?>">

                    <button type="submit" class="btn btn-action-glass px-3 btn-sm ms-2">Lọc</button>
                    <a href="index.php?page=history" class="btn btn-cancel-glass border btn-sm">Đặt lại</a>
                </div>
            </div>
        </div>
    </form>


    <!-- KHU VỰC BẢNG (IMPORT / EXPORT) -->
    <div class="row g-4">
        <!-- Cột Nhập Kho -->
        <div class="col-md-6">
            <div class="glass-panel">
                <div class="glass-panel-header">
                    <h5 class="m-0"><i class="fas fa-file-import me-2"></i>Tổng Hợp Nhập Kho</h5>
                </div>
                <div class="table-responsive table-scroll">
                    <table class="history-table">
                        <thead class="sticky-top">
                            <tr>
                                <th style="width: 18%">Ngày</th>
                                <th style="width: 22%">Mã phiếu</th>
                                <th style="width: 35%">Sản phẩm / Nhân viên</th>
                                <th style="width: 15%" class="text-center">Tổng SL</th>
                                <th style="width: 10%" class="text-center">Chi tiết</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (empty($importHistory)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-dark fw-bold">Chưa có giao dịch nhập nào</td>
                                </tr>
                                <?php else:
                                $currentDateImport = '';
                                $isGrayImport = false;
                                foreach ($importHistory as $item):
                                    $itemDate = date('d/m/Y', strtotime($item['log_date']));

                                    if ($itemDate !== $currentDateImport) {
                                        $isGrayImport = !$isGrayImport;
                                        $currentDateImport = $itemDate;
                                    }

                                    $rowClass = $isGrayImport ? 'row-group-gray' : '';
                                ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td class="align-middle fw-bold" style="font-size: 0.85rem;">
                                            <?= $itemDate ?>
                                        </td>
                                        <td class="align-middle fw-bold " style="font-size: 0.85rem;">
                                            <?= htmlspecialchars($item['reference_id'] ?: '-') ?>
                                        </td>
                                        <td class="align-middle">
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($item['product_name']) ?></div>
                                            <small class="text-dark"><i class="fas fa-user-edit me-1"></i><?= htmlspecialchars($item['user_name']) ?></small>
                                        </td>
                                        <td class="align-middle text-center fw-bold">+<?= $item['total_qty'] ?></td>
                                        <td class="align-middle text-center">
                                            <button class="btn-view-glass btn-view-detail"
                                                data-date="<?= $item['log_date'] ?>"
                                                data-pid="<?= $item['product_id'] ?>"
                                                data-uid="<?= $item['user_id'] ?>"
                                                data-type="IMPORT"
                                                data-pname="<?= htmlspecialchars($item['product_name']) ?>"
                                                data-ref="<?= htmlspecialchars($item['reference_id'] ?? '') ?>">
                                                Xem
                                            </button>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>


        <!-- Cột Xuất Kho -->
        <div class="col-md-6">
            <div class="glass-panel">
                <div class="glass-panel-header">
                    <h5 class="m-0"><i class="fas fa-file-export me-2"></i>Tổng Hợp Xuất Kho</h5>
                </div>
                <div class="table-responsive table-scroll">
                    <table class="history-table">
                        <thead class="sticky-top">
                            <tr>
                                <th style="width: 18%">Ngày</th>
                                <th style="width: 22%">Mã phiếu</th>
                                <th style="width: 35%">Sản phẩm / Nhân viên</th>
                                <th style="width: 15%" class="text-center">Tổng SL</th>
                                <th style="width: 10%" class="text-center">Chi tiết</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (empty($exportHistory)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-dark fw-bold">Chưa có giao dịch xuất nào</td>
                                </tr>
                                <?php else:
                                $currentDateExport = '';
                                $isGrayExport = false;
                                foreach ($exportHistory as $item):
                                    $itemDate = date('d/m/Y', strtotime($item['log_date']));

                                    if ($itemDate !== $currentDateExport) {
                                        $isGrayExport = !$isGrayExport;
                                        $currentDateExport = $itemDate;
                                    }

                                    $rowClass = $isGrayExport ? 'row-group-gray' : '';
                                ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td class="align-middle fw-bold" style="font-size: 0.85rem;">
                                            <?= $itemDate ?>
                                        </td>
                                        <td class="align-middle fw-bold " style="font-size: 0.85rem;">
                                            <?= htmlspecialchars($item['reference_id'] ?: '-') ?>
                                        </td>
                                        <td class="align-middle">
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($item['product_name']) ?></div>
                                            <small class="text-dark"><i class="fas fa-user-tag me-1"></i><?= htmlspecialchars($item['user_name']) ?></small>
                                        </td>
                                        <td class="align-middle text-center fw-bold">-<?= $item['total_qty'] ?></td>
                                        <td class="align-middle text-center">
                                            <button class="btn-view-glass btn-view-detail"
                                                data-date="<?= $item['log_date'] ?>"
                                                data-pid="<?= $item['product_id'] ?>"
                                                data-uid="<?= $item['user_id'] ?>"
                                                data-type="EXPORT"
                                                data-pname="<?= htmlspecialchars($item['product_name']) ?>"
                                                data-ref="<?= htmlspecialchars($item['reference_id'] ?? '') ?>">
                                                Xem
                                            </button>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==============================================
     MODAL CHI TIẾT GIAO DỊCH (GLASSMORPHISM)
=============================================== -->
<div class="modal fade" id="modalDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Chi tiết biến thể</h5>
                <span class="modal-close" data-bs-dismiss="modal">×</span>
            </div>
            <div class="modal-body p-3">
                <div class="p-3">
                    <p class="mb-1" style="color: rgba(255, 255, 255, 0.7); font-size: 0.85rem;">Tên sản phẩm:</p>
                    <h6 id="detailProductName"></h6>
                </div>
                <!-- Bảng chi tiết bên trong Modal cũng cần trong suốt -->
                <table class="history-table modal-inner-table">
                    <thead style="background-color: rgb(38, 38, 38)">
                        <tr>
                            <th class="ps-3" style="width: 25%">Hình ảnh</th>
                            <th style="width: 25%">Kích cỡ (Size)</th>
                            <th style="width: 25%">Màu sắc</th>
                            <th class="text-center" style="width: 25%">Số lượng</th>
                        </tr>
                    </thead>
                    <tbody id="detailTableBody">
                        <tr>
                            <td colspan="4" class="text-center py-4 text-muted">Đang tải dữ liệu...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Nhúng file JS tách riêng -->
<script src="assets/js/history.js"></script>