<?php
// app/controllers/ReportController.php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../models/ReportModel.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

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

    /**
     * Chức năng: Xuất báo cáo Excel đa Sheet theo ngày đã chọn
     */
    public function exportExcel()
    {
        // 1. Lấy khoảng ngày lọc
        $startDate = $_GET['start_date'] ?? null;
        $endDate   = $_GET['end_date'] ?? null;

        if (!$startDate) {
            $startDate = date('Y-m-01');
        }
        if (!$endDate) {
            $endDate = date('Y-m-d');
        }

        // Định dạng tên file: "Báo cáo xx-xxxx.xlsx" (theo tháng-năm của ngày kết thúc lọc)
        $monthYear = date('m-Y', strtotime($endDate));
        $filename = "Báo cáo {$monthYear}.xlsx";

        // 2. Lấy dữ liệu từ Model
        $brandSummary   = $this->reportModel->getExcelBrandSummary();
        $inventory      = $this->reportModel->getExcelInventory();
        $importData     = $this->reportModel->getExcelTransactionReport('IMPORT', $startDate, $endDate);
        $exportData     = $this->reportModel->getExcelTransactionReport('EXPORT', $startDate, $endDate);

        // 3. Khởi tạo đối tượng Spreadsheet
        $spreadsheet = new Spreadsheet();

        // Định dạng style dùng chung
        $headerStyle = [
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size'  => 11,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '198754'], // Màu xanh lá/xanh thương hiệu
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => 'CCCCCC'],
                ],
            ],
        ];

        $borderStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => 'E0E0E0'],
                ],
            ],
        ];

        // ==========================================
        // SHEET 1: Danh sách các hãng có trong kho
        // ==========================================
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Danh sách hãng');

        // Tiêu đề bảng
        $sheet1->setCellValue('A1', 'Tên hãng');
        $sheet1->setCellValue('B1', 'Số lượng sản phẩm mỗi hãng');
        $sheet1->setCellValue('C1', 'Tổng số lượng tất cả sản phẩm có trong hãng');

        $rowIdx = 2;
        $totalBrandCount = count($brandSummary);

        foreach ($brandSummary as $b) {
            $sheet1->setCellValue('A' . $rowIdx, $b['brand']);
            $sheet1->setCellValue('B' . $rowIdx, (int)$b['total_products']);
            $sheet1->setCellValue('C' . $rowIdx, (int)$b['total_quantity']);
            $rowIdx++;
        }

        // Hàng tổng kết nếu có dữ liệu
        if ($rowIdx > 2) {
            $sheet1->setCellValue('A' . $rowIdx, 'Tổng cộng (' . $totalBrandCount . ' hãng)');
            $sheet1->setCellValue('B' . $rowIdx, "=SUM(B2:B" . ($rowIdx - 1) . ")");
            $sheet1->setCellValue('C' . $rowIdx, "=SUM(C2:C" . ($rowIdx - 1) . ")");

            $totalRowStyle = [
                'font' => ['bold' => true, 'color' => ['rgb' => '000000']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F5E9']],
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B0BEC5']],
                ],
            ];
            $sheet1->getStyle("A{$rowIdx}:C{$rowIdx}")->applyFromArray($totalRowStyle);
            $sheet1->getStyle("A2:C" . ($rowIdx - 1))->applyFromArray($borderStyle);
        }

        $sheet1->getStyle('A1:C1')->applyFromArray($headerStyle);
        $sheet1->getRowDimension(1)->setRowHeight(28);

        // Canh giữa số lượng
        if ($rowIdx > 2) {
            $sheet1->getStyle("B2:C{$rowIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        // Tự động căn chỉnh độ rộng cột
        foreach (range('A', 'C') as $col) {
            $sheet1->getColumnDimension($col)->setAutoSize(true);
        }

        // ==========================================
        // SHEET 2: Tồn kho gom nhóm theo hãng (Filter)
        // ==========================================
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Tồn kho theo hãng');

        $sheet2->setCellValue('A1', 'Mã SKU');
        $sheet2->setCellValue('B1', 'Tên hãng');
        $sheet2->setCellValue('C1', 'Tên sản phẩm');
        $sheet2->setCellValue('D1', 'Màu');
        $sheet2->setCellValue('E1', 'Size');
        $sheet2->setCellValue('F1', 'Tổng số lượng');
        $sheet2->setCellValue('G1', 'Trạng thái');

        // Bảng màu highlight cho từng trạng thái tồn kho
        $statusStyles = [
            'OUT_OF_STOCK' => [
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8D7DA']],
                'font' => ['bold' => true, 'color' => ['rgb' => '842029']],
            ],
            'LOW_STOCK' => [
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF3CD']],
                'font' => ['bold' => true, 'color' => ['rgb' => '664D03']],
            ],
            'NORMAL' => [
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D1E7DD']],
                'font' => ['bold' => true, 'color' => ['rgb' => '0F5132']],
            ],
            'HIGH_STOCK' => [
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'CFF4FC']],
                'font' => ['bold' => true, 'color' => ['rgb' => '055160']],
            ],
            'INSUFFICIENT_DATA' => [
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E3E5']],
                'font' => ['bold' => false, 'color' => ['rgb' => '41464B']],
            ],
        ];

        $rowIdx = 2;
        foreach ($inventory as $item) {
            $statusCode = $item['status_code'] ?? 'INSUFFICIENT_DATA';
            $statusLabel = $item['status_label'] ?? 'Chưa đủ dữ liệu';

            $sheet2->setCellValue('A' . $rowIdx, $item['sku']);
            $sheet2->setCellValue('B' . $rowIdx, $item['brand']);
            $sheet2->setCellValue('C' . $rowIdx, $item['product_name']);
            $sheet2->setCellValue('D' . $rowIdx, $item['color']);
            $sheet2->setCellValue('E' . $rowIdx, $item['size']);
            $sheet2->setCellValue('F' . $rowIdx, (int)$item['total_quantity']);
            $sheet2->setCellValue('G' . $rowIdx, $statusLabel);

            if (isset($statusStyles[$statusCode])) {
                $sheet2->getStyle('G' . $rowIdx)->applyFromArray($statusStyles[$statusCode]);
            }
            $rowIdx++;
        }

        $sheet2->getStyle('A1:G1')->applyFromArray($headerStyle);
        $sheet2->getRowDimension(1)->setRowHeight(28);

        if ($rowIdx > 2) {
            $sheet2->getStyle("A2:G" . ($rowIdx - 1))->applyFromArray($borderStyle);
            $sheet2->getStyle("A2:A" . ($rowIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet2->getStyle("D2:E" . ($rowIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet2->getStyle("F2:F" . ($rowIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet2->getStyle("G2:G" . ($rowIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            // Bật bộ lọc AutoFilter
            $sheet2->setAutoFilter("A1:G" . ($rowIdx - 1));
        } else {
            $sheet2->setAutoFilter("A1:G1");
        }

        foreach (range('A', 'G') as $col) {
            $sheet2->getColumnDimension($col)->setAutoSize(true);
        }

        // ==========================================
        // SHEET 3: Thông tin nhập kho (Filter)
        // ==========================================
        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('Thông tin nhập kho');

        $sheet3->setCellValue('A1', 'Mã phiếu');
        $sheet3->setCellValue('B1', 'Mã lô hàng');
        $sheet3->setCellValue('C1', 'Mã SKU');
        $sheet3->setCellValue('D1', 'Tên hãng');
        $sheet3->setCellValue('E1', 'Tên sản phẩm');
        $sheet3->setCellValue('F1', 'Màu');
        $sheet3->setCellValue('G1', 'Size');
        $sheet3->setCellValue('H1', 'Tổng số lượng');

        $rowIdx = 2;
        foreach ($importData as $item) {
            $sheet3->setCellValue('A' . $rowIdx, $item['ticket_code']);
            $sheet3->setCellValue('B' . $rowIdx, $item['batch_code']);
            $sheet3->setCellValue('C' . $rowIdx, $item['sku']);
            $sheet3->setCellValue('D' . $rowIdx, $item['brand']);
            $sheet3->setCellValue('E' . $rowIdx, $item['product_name']);
            $sheet3->setCellValue('F' . $rowIdx, $item['color']);
            $sheet3->setCellValue('G' . $rowIdx, $item['size']);
            $sheet3->setCellValue('H' . $rowIdx, (int)$item['total_quantity']);
            $rowIdx++;
        }

        $sheet3->getStyle('A1:H1')->applyFromArray($headerStyle);
        $sheet3->getRowDimension(1)->setRowHeight(28);

        if ($rowIdx > 2) {
            $sheet3->getStyle("A2:H" . ($rowIdx - 1))->applyFromArray($borderStyle);
            $sheet3->getStyle("A2:C" . ($rowIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet3->getStyle("F2:G" . ($rowIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet3->getStyle("H2:H" . ($rowIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            // Bật bộ lọc AutoFilter
            $sheet3->setAutoFilter("A1:H" . ($rowIdx - 1));
        } else {
            $sheet3->setAutoFilter("A1:H1");
        }

        foreach (range('A', 'H') as $col) {
            $sheet3->getColumnDimension($col)->setAutoSize(true);
        }

        // ==========================================
        // SHEET 4: Thông tin xuất kho (Filter)
        // ==========================================
        $sheet4 = $spreadsheet->createSheet();
        $sheet4->setTitle('Thông tin xuất kho');

        $sheet4->setCellValue('A1', 'Mã phiếu');
        $sheet4->setCellValue('B1', 'Mã lô hàng');
        $sheet4->setCellValue('C1', 'Mã SKU');
        $sheet4->setCellValue('D1', 'Tên hãng');
        $sheet4->setCellValue('E1', 'Tên sản phẩm');
        $sheet4->setCellValue('F1', 'Màu');
        $sheet4->setCellValue('G1', 'Size');
        $sheet4->setCellValue('H1', 'Tổng số lượng');

        $rowIdx = 2;
        foreach ($exportData as $item) {
            $sheet4->setCellValue('A' . $rowIdx, $item['ticket_code']);
            $sheet4->setCellValue('B' . $rowIdx, $item['batch_code']);
            $sheet4->setCellValue('C' . $rowIdx, $item['sku']);
            $sheet4->setCellValue('D' . $rowIdx, $item['brand']);
            $sheet4->setCellValue('E' . $rowIdx, $item['product_name']);
            $sheet4->setCellValue('F' . $rowIdx, $item['color']);
            $sheet4->setCellValue('G' . $rowIdx, $item['size']);
            $sheet4->setCellValue('H' . $rowIdx, (int)$item['total_quantity']);
            $rowIdx++;
        }

        $sheet4->getStyle('A1:H1')->applyFromArray($headerStyle);
        $sheet4->getRowDimension(1)->setRowHeight(28);

        if ($rowIdx > 2) {
            $sheet4->getStyle("A2:H" . ($rowIdx - 1))->applyFromArray($borderStyle);
            $sheet4->getStyle("A2:C" . ($rowIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet4->getStyle("F2:G" . ($rowIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet4->getStyle("H2:H" . ($rowIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            // Bật bộ lọc AutoFilter
            $sheet4->setAutoFilter("A1:H" . ($rowIdx - 1));
        } else {
            $sheet4->setAutoFilter("A1:H1");
        }

        foreach (range('A', 'H') as $col) {
            $sheet4->getColumnDimension($col)->setAutoSize(true);
        }

        // Đặt active sheet về sheet 1 khi mở file
        $spreadsheet->setActiveSheetIndex(0);

        // 4. Thiết lập header tải file về trình duyệt
        if (ob_get_length()) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}
