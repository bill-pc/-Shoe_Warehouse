<?php include __DIR__ . '/../layouts/topbar.php'; ?>
<?php
// Tự động khai báo dự phòng để IDE hết báo đỏ/báo lỗi Undefined Variable
$filter = $filter ?? ['start' => date('Y-m-d'), 'end' => date('Y-m-d'), 'period' => 'day'];
$all_stats = $all_stats ?? ['total_stock' => 0, 'period_imports' => 0, 'period_exports' => 0, 'shortage_count' => 0];
$staff_perf = $staff_perf ?? [];
$variant_flow = $variant_flow ?? [];
$top_5_days = $top_5_days ?? [];
$brand_dist = $brand_dist ?? [];
$product_flow = $product_flow ?? [];
$inventory = $inventory ?? [];
$summary_data = $summary_data ?? [];
?>

<link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="assets/css/report.css">

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start mb-4 no-print flex-wrap gap-3">
        <div>
            <h4 class="fw-bold m-0" style="color: #2c3e50;">Báo Cáo Phân Tích Kho Chi Tiết</h4>
            <p class="text-muted small mb-0">Dữ liệu từ <?= date('d/m/Y', strtotime($filter['start'])) ?> đến <?= date('d/m/Y', strtotime($filter['end'])) ?></p>
        </div>

        <div class="d-flex gap-2 align-items-center">
            <form method="GET" action="index.php" class="d-flex gap-2 bg-white p-2 shadow-sm rounded border">
                <input type="hidden" name="page" value="report">
                <input type="date" name="start_date" class="form-control form-control-sm" value="<?= $filter['start'] ?>">
                <input type="date" name="end_date" class="form-control form-control-sm" value="<?= $filter['end'] ?>">
                <button type="submit" class="btn btn-outline-dark btn-sm px-3">Lọc</button>
            </form>
            <a href="index.php?page=report-export-excel&start_date=<?= urlencode($filter['start']) ?>&end_date=<?= urlencode($filter['end']) ?>" 
               class="btn btn-sm px-3 shadow-sm d-flex align-items-center gap-1 text-decoration-none btn-export-excel"
               style="background-color: #ffffff !important; color: #198754 !important; border: 1px solid #198754 !important;"
               title="Xuất báo cáo Excel theo ngày đã chọn">
                <i class="fas fa-file-excel" style="color: #198754 !important;"></i>
                <span class="fw-semibold" style="color: #198754 !important;">Xuất Excel</span>
            </a>
            <!-- <button class="btn btn-sm btn-white border shadow-sm" onclick="window.print()">
                <i class="fas fa-print me-1"></i> In báo cáo
            </button> -->
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card-custom border-left-primary h-100 mb-0">
                <div class="stat-label">Tổng tồn thực tế</div>
                <div class="stat-value text-dark"><?= number_format($all_stats['total_stock']) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-custom border-left-success h-100 mb-0">
                <div class="stat-label">Nhập kho (Giai đoạn)</div>
                <div class="stat-value text-primary"><?= number_format($all_stats['period_imports']) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-custom border-left-danger h-100 mb-0">
                <div class="stat-label">Xuất kho (Giai đoạn)</div>
                <div class="stat-value text-danger"><?= number_format($all_stats['period_exports']) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-custom border-left-warning h-100 mb-0">
                <div class="stat-label">Cảnh báo nhập hàng</div>
                <div class="stat-value text-danger"><?= $all_stats['shortage_count'] ?></div>
            </div>
        </div>
    </div>

    <div class="card-custom mb-4">
        <h6 class="section-title">Lưu Lượng Giao Dịch Chi Tiết Theo Ngày</h6>
        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light position-sticky top-0 shadow-sm" style="z-index: 10;">
                    <tr>
                        <th>Ngày hoạt động</th>
                        <th class="text-center">Tổng Nhập</th>
                        <th class="text-center">Tổng Xuất</th>
                        <th class="text-center">Số giao dịch</th>
                        <th class="text-center">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($summary_data)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4">Không có dữ liệu</td>
                        </tr>
                        <?php else: foreach ($summary_data as $row): ?>
                            <tr>
                                <td class="fw-bold"><?= date('d/m/Y', strtotime($row['work_date'])) ?></td>
                                <td class="text-center text-primary fw-bold">+ <?= number_format($row['total_import']) ?></td>
                                <td class="text-center text-danger fw-bold">- <?= number_format($row['total_export']) ?></td>
                                <td class="text-center text-muted"><?= $row['total_transactions'] ?> lượt</td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-dark rounded-pill px-3 btn-open-report-detail"
                                        data-date="<?= $row['work_date'] ?>">
                                        <i class="fas fa-eye me-1"></i> Xem chi tiết ngày
                                    </button>
                                </td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8 mb-4">
            <div class="card-custom h-100">
                <h6 class="section-title">Top 5 Ngày Hoạt Động Cao Điểm</h6>
                <div class="chart-container"><canvas id="daysChart"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4 mb-4">
            <div class="card-custom h-100 position-relative">
                <h6 class="section-title">Cơ Cấu Thương Hiệu</h6>
                <div class="chart-container" style="padding-bottom: 40px;"><canvas id="brandChart"></canvas></div>
                <!-- NÚT XEM THÊM Ở GÓC DƯỚI BÊN PHẢI -->
                <div class="position-absolute" style="bottom: 15px; right: 20px; z-index: 5;">
                    <button class="btn btn-sm btn-outline-primary rounded-pill px-3 shadow-sm fw-bold" id="btnShowBrandDetailCharts">
                        Xem thêm <i class="fas fa-arrow-right ms-1"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- DÒNG BIỂU ĐỒ CHI TIẾT SẢN PHẨM THEO THƯƠNG HIỆU (ẨN MẶC ĐỊNH) -->
    <div class="row d-none" id="brandDetailChartsRow">
        <div class="col-lg-8 mb-4">
            <div class="card-custom h-100">
                <h6 class="section-title" id="brandDetailChartTitle">Sản Phẩm Theo Thương Hiệu</h6>
                <div class="chart-container"><canvas id="brandProductsChart"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4 mb-4">
            <div class="card-custom h-100">
                <h6 class="section-title">Lọc Chi Tiết Thương Hiệu</h6>
                <div class="p-3">
                    <label for="brandDetailSelect" class="form-label text-muted small fw-bold mb-2">Chọn Thương Hiệu:</label>
                    <select id="brandDetailSelect" class="form-select form-select-lg shadow-sm mb-3" style="border-radius: 8px; cursor: pointer;">
                        <!-- JS sẽ tự động đổ danh sách thương hiệu vào đây -->
                    </select>
                    <p class="text-white-50 small mt-2">Chọn một thương hiệu để xem biểu đồ chi tiết số lượng tồn kho của các dòng sản phẩm mẹ tương ứng (được tự động tổng hợp từ tất cả các kích cỡ và màu sắc biến thể).</p>
                </div>
            </div>
        </div>
    </div>


    <div class="row">
        <div class="col-lg-7 mb-4">
            <div class="card-custom h-100">
                <h6 class="section-title">Sản Phẩm Giao Dịch Nhiều Nhất (Flow)</h6>
                <div class="chart-container"><canvas id="productFlowChart"></canvas></div>
            </div>
        </div>
        <div class="col-lg-5 mb-4">
            <div class="card-custom h-100">
                <h6 class="section-title">Top 3 Nhân Viên Giao Dịch</h6>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th width="60">Hạng</th>
                                <th>Nhân viên</th>
                                <th class="text-center">Số giao dịch</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staff_perf as $idx => $s): ?>
                                <tr>
                                    <td><span class="badge bg-primary rounded-pill px-2 py-1"><?= $idx + 1 ?></span></td>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($s['full_name']) ?></td>
                                    <td class="text-center fw-bold text-primary"><?= number_format($s['total_tx']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card-custom mb-4">
        <h6 class="section-title">Chi Tiết Biến Thể Thuộc Top 5 Sản Phẩm Lưu Chuyển Mạnh Nhất</h6>
        <?php
        $groupedFlow = [];
        foreach ($variant_flow as $row) {
            $groupedFlow[$row['product_name']]['total_vol'] = $row['total_vol'] ?? ($row['imp'] + $row['exp']);
            $groupedFlow[$row['product_name']]['variants'][] = $row;
        }
        ?>
        <div class="top-product-grid">
            <?php foreach ($groupedFlow as $pName => $pData): ?>
                <div class="top-p-card shadow-sm">
                    <div class="top-p-header">
                        <span class="text-truncate" style="max-width: 70%;" title="<?= htmlspecialchars($pName) ?>">
                            <?= htmlspecialchars($pName) ?>
                        </span>
                        <span class="badge-vol">Tổng: <?= number_format($pData['total_vol']) ?></span>
                    </div>
                    <div class="top-p-body">
                        <?php foreach ($pData['variants'] as $v):
                            $total_v = $v['imp'] + $v['exp'];
                            $pct_imp = $total_v > 0 ? ($v['imp'] / $total_v) * 100 : 0;
                            $pct_exp = $total_v > 0 ? ($v['exp'] / $total_v) * 100 : 0;
                        ?>
                            <div class="v-item">
                                <div class="v-item-title">
                                    <span>Sz: <?= $v['size'] ?> | <?= htmlspecialchars($v['color']) ?></span>
                                    <span class="text-muted small">Flow: <?= number_format($total_v) ?></span>
                                </div>
                                <div class="progress-stacked">
                                    <div class="progress-imp" style="width: <?= $pct_imp ?>%;"></div>
                                    <div class="progress-exp" style="width: <?= $pct_exp ?>%;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>


    <!-- Phân Tích Tồn Kho & Cảnh Báo Thông Minh -->
    <div class="card-custom">
        <?php
        $uniqueBrands = [];
        $statusCounts = [
            'ALL'               => count($inventory),
            'OUT_OF_STOCK'      => 0,
            'LOW_STOCK'         => 0,
            'NORMAL'            => 0,
            'HIGH_STOCK'        => 0,
            'INSUFFICIENT_DATA' => 0,
        ];
        foreach ($inventory as $item) {
            $brand = $item['category_name'];
            if (!in_array($brand, $uniqueBrands)) $uniqueBrands[] = $brand;
            $st = $item['status_code'] ?? 'INSUFFICIENT_DATA';
            if (isset($statusCounts[$st])) $statusCounts[$st]++;
        }
        sort($uniqueBrands);
        ?>
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h6 class="section-title m-0" id="inventoryTitle" style="border: none;">Phân Tích Tồn Kho & Đề Xuất Nhập Hàng (30 Ngày)</h6>

            <div class="d-flex align-items-center gap-2 flex-wrap">
                <label for="statusFilter" class="form-label mb-0 text-muted small fw-bold">Trạng thái:</label>
                <select id="statusFilter" class="form-select form-select-sm shadow-sm" style="width: auto;">
                    <option value="ALL">Tất cả (<?= $statusCounts['ALL'] ?>)</option>
                    <option value="OUT_OF_STOCK">Hết hàng (<?= $statusCounts['OUT_OF_STOCK'] ?>)</option>
                    <option value="LOW_STOCK">Sắp hết hàng (<?= $statusCounts['LOW_STOCK'] ?>)</option>
                    <option value="NORMAL">Bình thường (<?= $statusCounts['NORMAL'] ?>)</option>
                    <option value="HIGH_STOCK">Tồn kho cao (<?= $statusCounts['HIGH_STOCK'] ?>)</option>
                    <option value="INSUFFICIENT_DATA">Chưa đủ dữ liệu (<?= $statusCounts['INSUFFICIENT_DATA'] ?>)</option>
                </select>

                <label for="brandFilter" class="form-label mb-0 ms-2 text-muted small fw-bold">Hãng:</label>
                <select id="brandFilter" class="form-select form-select-sm shadow-sm" style="width: auto;">
                    <option value="ALL">Tất cả thương hiệu</option>
                    <?php foreach ($uniqueBrands as $b): ?>
                        <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Thẻ tổng hợp số biến thể theo trạng thái -->
        <div class="row g-2 mb-3">
            <div class="col-6 col-md">
                <div class="p-2 rounded border bg-light text-center">
                    <div class="small text-muted">Hết hàng</div>
                    <div class="fw-bold text-danger fs-5"><?= $statusCounts['OUT_OF_STOCK'] ?></div>
                </div>
            </div>
            <div class="col-6 col-md">
                <div class="p-2 rounded border bg-light text-center">
                    <div class="small text-muted">Sắp hết hàng</div>
                    <div class="fw-bold text-warning fs-5"><?= $statusCounts['LOW_STOCK'] ?></div>
                </div>
            </div>
            <div class="col-6 col-md">
                <div class="p-2 rounded border bg-light text-center">
                    <div class="small text-muted">Bình thường</div>
                    <div class="fw-bold text-success fs-5"><?= $statusCounts['NORMAL'] ?></div>
                </div>
            </div>
            <div class="col-6 col-md">
                <div class="p-2 rounded border bg-light text-center">
                    <div class="small text-muted">Tồn kho cao</div>
                    <div class="fw-bold text-info fs-5"><?= $statusCounts['HIGH_STOCK'] ?></div>
                </div>
            </div>
            <div class="col-12 col-md">
                <div class="p-2 rounded border bg-light text-center">
                    <div class="small text-muted">Chưa đủ dữ liệu</div>
                    <div class="fw-bold text-secondary fs-5"><?= $statusCounts['INSUFFICIENT_DATA'] ?></div>
                </div>
            </div>
        </div>

        <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
            <table class="table table-hover table-bordered align-middle mb-0 small" id="smartInventoryTable">
                <thead class="table-dark position-sticky top-0 text-center" style="z-index: 5;">
                    <tr>
                        <th>Hình ảnh</th>
                        <th>Mã SP (SKU)</th>
                        <th>Tên sản phẩm</th>
                        <th>Màu sắc</th>
                        <th>Kích thước</th>
                        <th>Tồn hiện tại</th>
                        <th>Xuất 30 ngày</th>
                        <th>Ngưỡng thấp (20%)</th>
                        <th>Mục tiêu (70%)</th>
                        <th>Đề xuất nhập</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($inventory)): ?>
                        <tr>
                            <td colspan="11" class="text-center py-4 text-muted">Chưa có dữ liệu biến thể trong kho.</td>
                        </tr>
                    <?php else: foreach ($inventory as $var): ?>
                        <tr class="inv-variant-row"
                            data-brand="<?= htmlspecialchars($var['category_name']) ?>"
                            data-status="<?= htmlspecialchars($var['status_code'] ?? 'INSUFFICIENT_DATA') ?>"
                            data-stock="<?= (int)$var['stock'] ?>">
                            <td class="text-center">
                                <img src="assets/img_product/<?= htmlspecialchars(!empty($var['product_image']) ? $var['product_image'] : 'default_shoe.png') ?>"
                                     alt="<?= htmlspecialchars($var['product_name']) ?>"
                                     class="rounded border bg-white"
                                     style="width: 48px; height: 48px; object-fit: contain;">
                            </td>
                            <td class="text-center fw-bold text-secondary"><?= htmlspecialchars($var['sku'] ?? 'N/A') ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($var['product_name']) ?></div>
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($var['category_name']) ?></span>
                            </td>
                            <td class="text-center"><?= htmlspecialchars($var['color']) ?></td>
                            <td class="text-center fw-bold"><?= htmlspecialchars($var['size']) ?></td>
                            <td class="text-center fw-bold variant-stock"><?= (int)$var['stock'] ?></td>
                            <td class="text-center"><?= (int)($var['total_export_30d'] ?? 0) ?></td>
                            <td class="text-center"><?= $var['min_stock'] !== null ? (int)$var['min_stock'] : '-' ?></td>
                            <td class="text-center"><?= $var['target_stock'] !== null ? (int)$var['target_stock'] : '-' ?></td>
                            <td class="text-center fw-bold <?= ($var['suggested_import'] ?? 0) > 0 ? 'text-primary' : 'text-muted' ?>">
                                <?= $var['suggested_import'] !== null ? (int)$var['suggested_import'] : '-' ?>
                            </td>
                            <td class="text-center">
                                <span class="badge <?= htmlspecialchars($var['badge_class'] ?? 'bg-secondary') ?>">
                                    <?= htmlspecialchars($var['status_label'] ?? 'Chưa đủ dữ liệu') ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


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


<script>
    window.dayData = <?= json_encode($top_5_days) ?>;
    window.brandData = <?= json_encode($brand_dist) ?>;
    window.flowData = <?= json_encode($product_flow) ?>;
    window.inventoryData = <?= json_encode($inventory) ?>; // NẠP THÊM BIẾN NÀY ĐỂ JS XỬ LÝ GOM NHÓM
</script>

<script src="assets/js/report.js?v=<?= filemtime(__DIR__ . '/../../../public/assets/js/report.js') ?>"></script>