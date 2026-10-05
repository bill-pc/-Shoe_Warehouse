<link rel="stylesheet" href="assets/css/dashboard.css">
<!-- Tải thư viện Chart.js cho Dashboard -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php include __DIR__ . '/../layouts/topbar.php'; ?>

<h1>Tổng quan Kho</h1>
<p class="text-muted mb-4">Dữ liệu cập nhật thời gian thực</p>

<div class="d-flex flex-wrap gap-3 mb-4 cards">
    <div class="flex-fill clickable-card" data-type="stock" style="min-width: 150px; cursor: pointer;">
        <div class="card p-3 border-0 shadow-sm border-start border-primary border-4">
            <p class="mb-1 text-secondary">Tổng Tồn Kho</p>
            <h5 class="mb-0"><?= number_format($stats['total_stock'] ?? 0) ?> đôi</h5>
        </div>
    </div>

    <div class="flex-fill clickable-card" data-type="import" style="min-width: 150px; cursor: pointer;">
        <div class="card p-3 border-0 shadow-sm border-start border-info border-4">
            <p class="mb-1 text-secondary">Đã nhập (Tháng này)</p>
            <h5 class="mb-0 text-info"><?= number_format($stats['period_imports'] ?? 0) ?> đôi</h5>
        </div>
    </div>

    <div class="flex-fill clickable-card" data-type="export" style="min-width: 150px; cursor: pointer;">
        <div class="card p-3 border-0 shadow-sm border-start border-success border-4">
            <p class="mb-1 text-secondary">Đã xuất (Tháng này)</p>
            <h5 class="mb-0 text-success"><?= number_format($stats['period_exports'] ?? 0) ?> đôi</h5>
        </div>
    </div>

    <div class="flex-fill clickable-card" data-type="shortage" style="min-width: 150px; cursor: pointer;">
        <div class="card p-3 border-0 shadow-sm border-start border-danger border-4">
            <p class="mb-1 text-secondary">Dự báo Thiếu hụt</p>
            <h5 class="mb-0 text-danger"><?= $stats['shortage_count'] ?? 0 ?> mã hàng</h5>
        </div>
    </div>
</div>

<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold" id="modalTitle">Chi tiết dữ liệu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="modalContent"></div>
            </div>
        </div>
    </div>
</div>

<?php if (strtoupper($_SESSION['role'] ?? '') === 'MANAGER'): ?>
    <!-- HÀNG BIỂU ĐỒ & TOP BÁN CHẠY -->
    <div class="row g-3 mb-4">
        <!-- TOP BÁN CHẠY -->
        <div class="col-lg-6">
            <div class="card p-3 border-0 shadow-sm h-100 position-relative" style="padding-bottom: 55px !important;">
                <h3 class="fw-bold text-dark mb-3"></i>Top Bán Chạy</h3>
                <?php
                if (!empty($top_selling)):
                    $max_sold = $top_selling[0]['total_sold'];
                    foreach ($top_selling as $item):
                        $percent = ($item['total_sold'] / $max_sold) * 100;
                ?>
                        <div class="bar-item mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="small fw-bold"><?= htmlspecialchars($item['product_name']) ?></span>
                                <span class="small text-muted"><?= number_format($item['total_sold']) ?> đôi</span>
                            </div>
                            <div class="progress" style="height: 10px;">
                                <div class="progress-bar bg-primary" style="width: <?= $percent ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach;
                else: ?>
                    <p class="text-muted small">Chưa có dữ liệu xuất hàng.</p>
                <?php endif; ?>

                <!-- NÚT XEM THÊM Ở GÓC DƯỚI BÊN PHẢI -->
                <div class="position-absolute" style="bottom: 15px; right: 20px; z-index: 5;">
                    <a href="index.php?page=report" class="btn btn-sm btn-outline-dark rounded-pill px-3 shadow-sm fw-bold">
                        Xem thêm <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- THỐNG KÊ THƯƠNG HIỆU (THAY THẾ AI DỰ BÁO XU THẾ) -->
        <div class="col-lg-6">
            <div class="card p-3 border-0 shadow-sm h-100 position-relative" style="padding-bottom: 55px !important;">
                <h3 class="fw-bold text-dark mb-3">Thống Kê Thương Hiệu</h3>
                <div class="chart-container" style="height: 250px; position: relative;">
                    <canvas id="dashboardBrandChart"></canvas>
                </div>

                <!-- NÚT XEM THÊM Ở GÓC DƯỚI BÊN PHẢI (LINK SANG REPORT & TỰ ĐỘNG MỞ CHI TIẾT) -->
                <div class="position-absolute" style="bottom: 15px; right: 20px; z-index: 5;">
                    <a href="index.php?page=report&show_brand_detail=1" class="btn btn-sm btn-outline-dark rounded-pill px-3 shadow-sm fw-bold">
                        Xem thêm <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- PHẦN THỐNG KÊ GIAO DỊCH HÔM NAY -->
    <div class="card p-4 border-0 shadow-sm mb-4 position-relative" style="padding-bottom: 55px !important;">
        <h3 class="fw-bold text-dark mb-4">Giao Dịch Hôm Nay</h3>

        <div class="row text-center">
            <div class="col-md-4 mb-3 mb-md-0">
                <div class="p-3 bg-light rounded shadow-sm h-100 d-flex flex-column justify-content-center">
                    <p class="text-muted small fw-bold mb-1 text-uppercase">Tổng Nhập Hôm Nay</p>
                    <h3 class="fw-bold text-dark mb-0"><?= number_format($today_stats['total_import'] ?? 0) ?> <span class="fs-6 fw-normal text-muted">đôi</span></h3>
                </div>
            </div>
            <div class="col-md-4 mb-3 mb-md-0">
                <div class="p-3 bg-light rounded shadow-sm h-100 d-flex flex-column justify-content-center">
                    <p class="text-muted small fw-bold mb-1 text-uppercase">Tổng Xuất Hôm Nay</p>
                    <h3 class="fw-bold text-dark mb-0"><?= number_format($today_stats['total_export'] ?? 0) ?> <span class="fs-6 fw-normal text-muted">đôi</span></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-light rounded shadow-sm h-100 d-flex flex-column justify-content-center">
                    <p class="text-muted small fw-bold mb-1 text-uppercase">Lượt Giao Dịch</p>
                    <h3 class="fw-bold text-dark mb-0"><?= number_format($today_stats['total_transactions'] ?? 0) ?> <span class="fs-6 fw-normal text-muted">lượt</span></h3>
                </div>
            </div>
        </div>

        <div class="mt-4 text-center">
            <!-- NÚT MỞ MODAL XEM CHI TIẾT GIAO DỊCH HÔM NAY -->
            <button class="btn btn-outline-dark rounded-pill px-4 fw-bold btn-open-report-detail shadow-sm" data-date="<?= date('Y-m-d') ?>">
                <i class="fas fa-eye me-2"></i>Xem chi tiết giao dịch hôm nay
            </button>
        </div>

        <!-- NÚT XEM THÊM LỊCH SỬ Ở GÓC DƯỚI BÊN PHẢI -->
        <div class="position-absolute" style="bottom: 15px; right: 20px; z-index: 5;">
            <a href="index.php?page=report" class="btn btn-sm btn-outline-dark rounded-pill px-3 shadow-sm fw-bold">
                Xem thêm lịch sử <i class="fas fa-arrow-right ms-1"></i>
            </a>
        </div>
    </div>

<?php else: ?>
    <div class="alert alert-info border-0 shadow-sm mb-4">
        <i class="fas fa-info-circle me-2"></i> Chào <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'bạn') ?></strong>, hãy kiểm tra bản đồ kho bên dưới để bắt đầu nhặt hàng nhé!
    </div>
<?php endif; ?>

<!-- MODAL CHI TIẾT GIAO DỊCH (ĐỒNG BỘ UI TỪ REPORT) -->
<div class="modal fade" id="modalReportDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold text-primary">
                    <i class="fas fa-history me-2"></i> Chi tiết giao dịch ngày: <span id="reportDetailDate" class="text-dark"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive" style="max-height: 60vh; overflow-y: auto;">
                    <table class="align-middle mb-0 text-center w-100 custom-table">
                        <thead class="table-dark sticky-top">
                            <tr>
                                <th style="width: 10%">THỜI GIAN</th>
                                <th style="width: 10%">LOẠI</th>
                                <th style="width: 10%">HÃNG</th>
                                <th style="width: 30%">SẢN PHẨM</th>
                                <th style="width: 15%">BIẾN THỂ</th>
                                <th style="width: 10%">SỐ LƯỢNG</th>
                                <th style="width: 15%">NHÂN VIÊN</th>
                            </tr>
                        </thead>
                        <tbody id="reportDetailBody">
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">Đang tải dữ liệu...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light d-flex justify-content-between align-items-center">
                <span class="fw-bold text-primary" id="totalItemsCount" style="font-size: 0.95rem;"></span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
            </div>
        </div>
    </div>
</div>

<!-- ĐẨY DỮ LIỆU THƯƠNG HIỆU SANG JS -->
<script>
    window.brandData = <?= json_encode($brand_dist ?? []) ?>;
</script>

<script src="assets/js/dashboard.js"></script>