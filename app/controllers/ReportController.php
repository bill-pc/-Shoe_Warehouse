<?php
// app/controllers/ReportController.php

require_once __DIR__ . '/../models/ReportModel.php';

/**
 * Controller xử lý các yêu cầu liên quan đến báo cáo và thống kê
 * Chịu trách nhiệm điều phối dữ liệu từ Model sang View cho Dashboard và Report
 */
class ReportController
{
    private $reportModel;

    /**
     * Hàm khởi tạo: Kiểm tra quyền truy cập và khởi tạo Model
     */
    public function __construct()
    {
        if (!isset($_SESSION['user_id'])) {
            header("Location: index.php?page=login");
            exit;
        }
        $this->reportModel = new ReportModel();
    }

    /**
     * Chức năng: Xử lý dữ liệu cho trang Dashboard chính
     * Phân quyền: Manager xem được phân tích sâu, Staff xem số liệu tổng quát
     */
    public function index()
    {
        $role = strtoupper($_SESSION['role'] ?? '');

        // 1. Lấy dữ liệu KPIs tổng quát từ ReportModel (Đã bổ sung thương hiệu & hôm nay cho Dashboard)
        $data = [
            'stats'       => $this->reportModel->getGeneralStats(),
            'brand_dist'  => $this->reportModel->getBrandDistribution(), // Bổ sung thương hiệu
            'today_stats' => $this->reportModel->getTodayStats(),        // Bổ sung thống kê hôm nay
            'top_selling' => [] // Khởi tạo rỗng để tránh lỗi Undefined variable ở View
        ];


        // 2. Nếu là Manager, lấy thêm dữ liệu phân tích
        if ($role === 'MANAGER') {
            $data['top_selling']   = $this->reportModel->getTopSelling(4);
            $data['heatmap']       = $this->reportModel->getHeatmapData();
            $data['monthly_trend'] = $this->reportModel->getMonthlyTrend();
        }

        return $data;
    }

    /**
     * Chức năng: Xử lý dữ liệu cho trang "Báo Cáo Chi Tiết"
     * Bao gồm: Lọc theo Ngày/Tuần/Tháng và các bảng biểu phân tích
     */
    public function statistics()
    {
        // Ưu tiên lấy period từ URL
        $period = $_GET['period'] ?? 'day';
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? date('Y-m-d');

        // Nếu không bấm nút Lọc thủ công mà chỉ chọn Combobox
        if (!isset($_GET['start_date'])) {
            if ($period == 'week') {
                $start_date = date('Y-m-d', strtotime('-7 days'));
            } elseif ($period == 'month') {
                $start_date = date('Y-m-d', strtotime('-30 days'));
            } else {
                $start_date = date('Y-m-d');
            }
        }

        return [
            'all_stats'     => $this->reportModel->getGeneralStats($start_date, $end_date),
            'top_5_days'    => $this->reportModel->getTop5BusiestDays(),
            'product_flow'  => $this->reportModel->getTop5ProductFlow(),
            'variant_flow'  => $this->reportModel->getVariantFlowDetail(),
            'inventory'     => $this->reportModel->getDetailedInventory(),
            'brand_dist'    => $this->reportModel->getBrandDistribution(),
            'staff_perf'    => $this->reportModel->getStaffPerformance(),
            'summary_data'  => $this->reportModel->getActivitySummaryByRange($start_date, $end_date),
            'filter'        => ['start' => $start_date, 'end' => $end_date, 'period' => $period]
        ];
    }

    public function getReportDetailsAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        $date = $_GET['date'] ?? null;
        // Nếu bồ muốn xem toàn bộ ngày, productId sẽ null
        $productId = $_GET['product_id'] ?? null;
        $details = $this->reportModel->getDetailsByDate($date, $productId);
        echo json_encode($details ?: []);
        exit;
    }

    /**
     * Chức năng: Điều hướng bộ lọc ngày
     */
    public function filterByDate($start, $end)
    {
        header("Location: index.php?page=report&start_date=$start&end_date=$end");
        exit;
    }



    /**
     * AJAX Cấp 1: Lấy tổng hợp theo từng Brand dựa trên loại thẻ (stock, import, export, shortage)
     */
    public function getBrandDataAjax()
    {
        $this->cleanBuffer();
        $type = $_GET['type'] ?? 'stock';
        $data = $this->reportModel->getBrandDetail($type);
        echo json_encode($data ?: []);
        exit;
    }

    /**
     * AJAX Cấp 2: Lấy danh sách sản phẩm thuộc một Brand
     */
    public function getProductDataAjax()
    {
        $this->cleanBuffer();
        $brandId = $_GET['brand_id'] ?? null;
        $type = $_GET['type'] ?? 'stock';

        if (!$brandId) {
            echo json_encode([]);
            exit;
        }

        $data = $this->reportModel->getProductsByBrand($brandId, $type);
        echo json_encode($data ?: []);
        exit;
    }

    /**
     * AJAX Cấp 3: Lấy chi tiết biến thể (Size/Color) của một sản phẩm
     */
    public function getVariantDataAjax()
    {
        $this->cleanBuffer();
        $productId = $_GET['product_id'] ?? null;
        $type = $_GET['type'] ?? 'stock';

        if (!$productId) {
            echo json_encode([]);
            exit;
        }

        $data = $this->reportModel->getVariantsByProduct($productId, $type);
        echo json_encode($data ?: []);
        exit;
    }

    /**
     * Hàm bổ trợ: Làm sạch bộ đệm và set Header JSON để tránh lỗi hiển thị
     */
    private function cleanBuffer()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
    }
}
