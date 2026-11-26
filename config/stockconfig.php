<?php
// config/stockconfig.php

if (!function_exists('getSmartStockConfig')) {
    /**
     * Cấu hình tham số cho thuật toán cảnh báo tồn kho & đề xuất nhập hàng thông minh
     */
    function getSmartStockConfig(): array
    {
        return [
            'export_window_days'  => 30,  // Cửa sổ lịch sử xuất kho (30 ngày gần nhất)
            'min_stock_ratio'     => 0.20, // Ngưỡng cảnh báo sắp hết hàng = 20% tổng xuất 30 ngày
            'target_stock_ratio'  => 0.70, // Ngưỡng tồn kho mục tiêu = 70% tổng xuất 30 ngày
        ];
    }
}

if (!function_exists('getExport30DaysSqlSubquery')) {
    /**
     * Truy vấn con chuẩn hóa lấy tổng số lượng xuất kho đã hoàn tất trong 30 ngày gần nhất theo từng biến thể.
     * Chỉ tính giao dịch EXPORT đã thực sự hoàn tất (không tính phiếu nháp/chưa xác nhận/đã hủy).
     */
    function getExport30DaysSqlSubquery(): string
    {
        $days = (int)getSmartStockConfig()['export_window_days'];
        return "SELECT t.variant_id, COALESCE(SUM(t.quantity), 0) AS total_export_30d
                FROM transactions t
                LEFT JOIN tickets tk ON t.reference_id = tk.ticket_code
                WHERE t.transaction_type = 'EXPORT'
                  AND t.created_at >= NOW() - INTERVAL '{$days} days'
                  AND (tk.ticket_id IS NULL OR tk.status IN ('COMPLETED', 'COMPLETE_DIFF'))
                GROUP BY t.variant_id";
    }
}

if (!function_exists('calculateSmartStockMetrics')) {
    /**
     * Thuật toán tính toán cảnh báo tồn kho & đề xuất nhập hàng cho từng biến thể giày.
     *
     * @param int|float $currentStock   Số lượng tồn kho hiện tại
     * @param int|float $totalExport30d Tổng số lượng xuất kho hoàn tất trong 30 ngày gần nhất
     * @return array
     */
    function calculateSmartStockMetrics($currentStock, $totalExport30d): array
    {
        $cfg = getSmartStockConfig();
        $stock = max(0, (int)$currentStock);
        $export30d = max(0, (int)$totalExport30d);

        // Trường hợp không có lịch sử xuất kho trong 30 ngày gần nhất
        if ($export30d <= 0) {
            return [
                'current_stock'    => $stock,
                'total_export_30d' => 0,
                'min_stock'        => null,
                'target_stock'     => null,
                'suggested_import' => null,
                'status_code'      => 'INSUFFICIENT_DATA',
                'status_label'     => 'Chưa đủ dữ liệu',
                'badge_class'      => 'bg-secondary',
                'has_enough_data'  => false,
                'is_warning'       => false,
            ];
        }

        $minStock = (int)ceil($export30d * $cfg['min_stock_ratio']);
        $targetStock = (int)ceil($export30d * $cfg['target_stock_ratio']);
        $suggestedImport = max(0, $targetStock - $stock);

        if ($stock === 0) {
            $statusCode = 'OUT_OF_STOCK';
            $statusLabel = 'Hết hàng';
            $badgeClass = 'bg-danger';
            $isWarning = true;
        } elseif ($stock <= $minStock) {
            $statusCode = 'LOW_STOCK';
            $statusLabel = 'Sắp hết hàng';
            $badgeClass = 'bg-warning text-dark';
            $isWarning = true;
        } elseif ($stock > $targetStock) {
            $statusCode = 'HIGH_STOCK';
            $statusLabel = 'Tồn kho cao';
            $badgeClass = 'bg-info text-dark';
            $isWarning = false;
        } else {
            $statusCode = 'NORMAL';
            $statusLabel = 'Bình thường';
            $badgeClass = 'bg-success';
            $isWarning = false;
        }

        return [
            'current_stock'    => $stock,
            'total_export_30d' => $export30d,
            'min_stock'        => $minStock,
            'target_stock'     => $targetStock,
            'suggested_import' => $suggestedImport,
            'status_code'      => $statusCode,
            'status_label'     => $statusLabel,
            'badge_class'      => $badgeClass,
            'has_enough_data'  => true,
            'is_warning'       => $isWarning,
        ];
    }
}

return getSmartStockConfig();
