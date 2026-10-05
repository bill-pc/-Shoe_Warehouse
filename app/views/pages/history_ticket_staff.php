<?php
// Khởi tạo giá trị mặc định để tránh lỗi Undefined variable khi mới vào trang
$filter_status = $filter_status ?? '';
$start_date = $start_date ?? '';
$end_date = $end_date ?? '';
$tickets = $tickets ?? [];
?>
<?php include __DIR__ . '/../layouts/topbar.php'; ?>
<div class="container-fluid p-0">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title fw-bold text-dark mb-0">Lịch sử công việc của tôi</h1>
            <p class="text-muted small mb-0 mt-1">Quản lý và tra cứu các phiếu kho bạn đã và đang xử lý.</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body p-3">
            <form method="GET" action="index.php" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="staff_ticket_history">

                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Trạng thái phiếu</label>
                    <select name="filter_status" class="form-select form-select-sm fw-bold">
                        <option value="">Tất cả trạng thái</option>
                        <option value="PENDING" <?= $filter_status === 'PENDING' ? 'selected' : '' ?>>Mới giao (Chờ xử lý)</option>
                        <option value="PROCESSING" <?= $filter_status === 'PROCESSING' ? 'selected' : '' ?>>Đang thực hiện</option>
                        <option value="PAUSED" <?= $filter_status === 'PAUSED' ? 'selected' : '' ?>>Tạm dừng</option>
                        <option value="COMPLETED" <?= $filter_status === 'COMPLETED' ? 'selected' : '' ?>>Đã hoàn tất</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Từ ngày</label>
                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?= htmlspecialchars($start_date) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Đến ngày</label>
                    <input type="date" name="end_date" class="form-control form-control-sm" value="<?= htmlspecialchars($end_date) ?>">
                </div>

                <div class="col-md-3">
                    <button type="submit" class="btn btn-dark btn-sm w-100 fw-bold rounded-1">
                        <i class="fas fa-search me-1"></i> Lọc dữ liệu
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-muted" style="font-size: 0.85rem; letter-spacing: 0.5px;">
                        <tr>
                            <th class="ps-4 py-3">MÃ PHIẾU</th>
                            <th>LOẠI PHIẾU</th>
                            <th>LÔ HÀNG</th>
                            <th>TRẠNG THÁI</th>
                            <th>NGÀY GIAO</th>
                            <th>NGÀY HOÀN TẤT</th>
                            <th class="text-center pe-4">THAO TÁC</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php if (!empty($tickets)): ?>
                            <?php foreach ($tickets as $t): ?>
                                <tr>
                                    <td class="ps-4 fw-bold text-dark"><?= htmlspecialchars($t['ticket_code']) ?></td>
                                    <td>
                                        <?php if ($t['ticket_type'] === 'IMPORT'): ?>
                                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary fw-bold">Nhập Kho</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger fw-bold">Xuất Kho</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted fw-bold"><?= htmlspecialchars($t['batch_code']) ?></td>
                                    <td>
                                        <?php
                                        $statusClass = [
                                            'PENDING' => 'bg-secondary',
                                            'PROCESSING' => 'bg-warning text-dark',
                                            'PAUSED' => 'bg-danger',
                                            'COMPLETED' => 'bg-success'
                                        ];
                                        $statusText = [
                                            'PENDING' => 'Chờ xử lý',
                                            'PROCESSING' => 'Đang làm',
                                            'PAUSED' => 'Tạm dừng',
                                            'COMPLETED' => 'Hoàn tất'
                                        ];
                                        $badgeClass = $statusClass[$t['status']] ?? 'bg-dark';
                                        $label = $statusText[$t['status']] ?? $t['status'];
                                        ?>
                                        <span class="badge <?= $badgeClass ?> rounded-pill px-3"><?= $label ?></span>
                                    </td>
                                    <td class="text-muted small"><?= date('d/m/Y H:i', strtotime($t['created_at'])) ?></td>
                                    <td class="text-muted small">
                                        <?= $t['completed_at'] ? date('d/m/Y H:i', strtotime($t['completed_at'])) : '--' ?>
                                    </td>
                                    <td class="text-center pe-4">
                                        <button class="btn btn-outline-dark btn-sm rounded-1 px-3 fw-bold" onclick="viewTicketDetails(<?= $t['ticket_id'] ?>, '<?= $t['ticket_code'] ?>')">
                                            Xem
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fas fa-box-open fa-3x mb-3 opacity-50"></i>
                                    <p class="mb-0">Bạn chưa có phiếu kho nào phù hợp với bộ lọc.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="staffTicketDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl" style="max-width: 90%;">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold text-primary">
                    <i class="fas fa-file-invoice me-2"></i> Chi tiết phiếu: <span id="modalTicketCode" class="text-dark"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="align-middle mb-0 text-center w-100 custom-table">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 10%">Hình ảnh</th>
                                <th style="width: 10%">Hãng</th>
                                <th style="width: 20%">Tên sản phẩm</th>
                                <th style="width: 12%">Phân loại</th>
                                <th style="width: 8%">Yêu cầu</th>
                                <th style="width: 8%">Thực tế</th>
                                <th style="width: 10%">Chênh lệch</th>
                                <th style="width: 14%">Ghi chú</th>
                                <th style="width: 8%" class="text-center pe-4">Mã QR</th>
                            </tr>
                        </thead>

                        <tbody id="ticketDetailBody">
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">Đang tải dữ liệu...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>
            <div class="modal-footer ">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
            </div>
        </div>
    </div>
    <!-- Modal In Mã QR (simpleQRModal) -->
    <div class="modal fade" id="simpleQRModal" tabindex="-1" data-bs-backdrop="false" style="background: none; z-index: 1060;">
        <div class="modal-dialog modal-sm modal-dialog-centered" style="box-shadow: 0 10px 50px rgba(0,0,0,0.2);">
            <div class="modal-content" style="border: 2px solid #000;">
                <div class="modal-header p-2 border-0">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-0" id="qrContentArea">
                </div>
                <div class="modal-footer p-2 border-0">
                    <button type="button" class="btn btn-dark w-100 fw-bold" onclick="startPrint()">IN MÃ QR</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        @media print {
            body * {
                visibility: hidden;
            }

            #qrContentArea,
            #qrContentArea * {
                visibility: visible;
            }

            #qrContentArea {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                text-align: center;
            }
        }
    </style>

</div>


<script src="assets/js/staff_history.js"></script>