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
$stockConfig = require __DIR__ . '/../../../config/stockconfig.php';
$threshold = (int)$stockConfig['low_stock_threshold'];
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


    <!-- Phân Tích Tồn Kho -->
    <div class="card-custom">
        <?php
        $groupedInventory = [];
        $uniqueBrands = [];
        foreach ($inventory as $item) {
            $brand = $item['category_name'];
            $groupedInventory[$item['product_name']]['brand'] = $brand;
            $groupedInventory[$item['product_name']]['variants'][] = $item;
            if (!in_array($brand, $uniqueBrands)) $uniqueBrands[] = $brand;
        }
        sort($uniqueBrands);
        ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="section-title m-0" id="inventoryTitle" style="border: none;">Phân Tích Tồn Kho Hiện Tại</h6>

            <div class="d-flex align-items-center">
                <label for="brandFilter" class="form-label mb-0 me-2 text-muted small fw-bold">Lọc:</label>
                <select id="brandFilter" class="form-select form-select-sm shadow-sm" style="width: auto;">
                    <option value="ALL">Tất cả thương hiệu</option>
                    <?php foreach ($uniqueBrands as $b): ?>
                        <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="inventory-grid" id="inventoryGrid">
            <?php foreach ($groupedInventory as $pName => $pData): ?>
                <div class="inv-product-card" data-brand="<?= htmlspecialchars($pData['brand']) ?>">
                    <div class="inv-header">
                        <strong class="text-truncate"><?= htmlspecialchars($pName) ?></strong>
                        <span class="inv-brand-badge"><?= htmlspecialchars($pData['brand']) ?></span>
                    </div>
                    <div class="inv-body">
                        <?php foreach ($pData['variants'] as $var): ?>
                            <div class="stock-chip <?= $var['stock'] < $threshold ? 'danger' : '' ?>">
                                Sz <?= $var['size'] ?> | <?= htmlspecialchars($var['color']) ?>: <b class="variant-stock"><?= $var['stock'] ?></b>
                            </div>
                        <?php endforeach; ?>

                    </div>
                </div>
            <?php endforeach; ?>
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

<script src="assets/js/report.js"></script>