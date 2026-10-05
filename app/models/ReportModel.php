<?php
require_once __DIR__ . '/../../config/database.php';

class ReportModel
{
    private $conn;

    public function __construct()
    {
        $this->conn = getConnection();
    }

    // --- NHÓM 1: KPI TỔNG QUÁT ---
    public function getGeneralStats($start_date = null, $end_date = null)
    {
        $stats = [];
        $sql_stock = "SELECT SUM(stock) as total FROM product_variants WHERE is_deleted = false";
        $res_stock = pg_query($this->conn, $sql_stock);
        $stats['total_stock'] = pg_fetch_assoc($res_stock)['total'] ?? 0;

        $sql_tx = "SELECT 
                    SUM(CASE WHEN transaction_type = 'IMPORT' THEN quantity ELSE 0 END) as total_imp,
                    SUM(CASE WHEN transaction_type = 'EXPORT' THEN quantity ELSE 0 END) as total_exp
                  FROM transactions";

        if ($start_date && $end_date) {
            $sql_tx .= " WHERE created_at BETWEEN $1 AND $2";
            $res_tx = pg_query_params($this->conn, $sql_tx, [$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
        } else {
            $sql_tx .= " WHERE date_trunc('month', created_at) = date_trunc('month', CURRENT_DATE)";
            $res_tx = pg_query($this->conn, $sql_tx);
        }
        $row_tx = pg_fetch_assoc($res_tx);
        $stats['period_imports'] = $row_tx['total_imp'] ?? 0;
        $stats['period_exports'] = $row_tx['total_exp'] ?? 0;


        $stockConfig = require __DIR__ . '/../../config/stockconfig.php';
        $threshold = (int)$stockConfig['low_stock_threshold'];

        $sql_shortage = "SELECT COUNT(*) as total FROM product_variants WHERE stock < 5 AND is_deleted = false";
        $stats['shortage_count'] = pg_fetch_assoc(pg_query($this->conn, $sql_shortage))['total'] ?? 0;

        return $stats;
    }

    // --- NHÓM 2: GOM NHÓM & CHI TIẾT THEO NGÀY (CHO BẢNG VÀ MODAL) ---
    public function getActivitySummaryByRange($start, $end)
    {
        // CHỈ GOM THEO NGÀY
        $sql = "SELECT 
                DATE(created_at) as work_date,
                SUM(CASE WHEN transaction_type = 'IMPORT' THEN quantity ELSE 0 END) as total_import,
                SUM(CASE WHEN transaction_type = 'EXPORT' THEN quantity ELSE 0 END) as total_export,
                COUNT(transaction_id) as total_transactions
            FROM transactions 
            WHERE created_at BETWEEN $1 AND $2
            GROUP BY work_date
            ORDER BY work_date DESC";

        $res = pg_query_params($this->conn, $sql, [$start . ' 00:00:00', $end . ' 23:59:59']);
        return pg_fetch_all($res) ?: [];
    }

    // Hàm lấy chi tiết của TOÀN BỘ ngày đó
    public function getDetailsByDate($date, $productId = null)
    {
        $sql = "SELECT t.created_at, t.transaction_type, t.quantity,
                   p.product_name, c.category_name as brand, pv.size, pv.color, u.full_name as staff
            FROM transactions t
            JOIN product_variants pv ON t.variant_id = pv.variant_id
            JOIN products p ON pv.product_id = p.product_id
            JOIN categories c ON p.category_id = c.category_id
            JOIN users u ON t.user_id = u.user_id
            WHERE DATE(t.created_at) = $1";

        $params = [$date];
        if ($productId) {
            $sql .= " AND pv.product_id = $2";
            $params[] = $productId;
        }

        $sql .= " ORDER BY t.created_at ASC";
        $res = pg_query_params($this->conn, $sql, $params);
        return pg_fetch_all($res) ?: [];
    }

    // --- NHÓM 3: CÁC HÀM PHÂN TÍCH CHUYÊN SÂU (ĐÃ KHÔI PHỤC) ---
    public function getTopSelling($limit = 4)
    {
        $sql = "SELECT p.product_name, SUM(t.quantity) as total_sold
            FROM transactions t
            JOIN product_variants pv ON t.variant_id = pv.variant_id
            JOIN products p ON pv.product_id = p.product_id
            WHERE t.transaction_type = 'EXPORT'
            GROUP BY p.product_id, p.product_name
            ORDER BY total_sold DESC LIMIT $1";

        // LỖI Ở ĐÂY: Sửa pg_query thành pg_query_params để nó nhận tham số $1
        $res = pg_query_params($this->conn, $sql, [(int)$limit]);
        return pg_fetch_all($res) ?: [];
    }

    public function getHeatmapData()
    {
        $sql = "SELECT variant_id, COUNT(*) as activity_count FROM transactions 
                GROUP BY variant_id ORDER BY activity_count DESC";
        return pg_fetch_all(pg_query($this->conn, $sql)) ?: [];
    }

    public function getVariantFlowDetail()
    {
        $sql = "WITH TopProducts AS (
                    SELECT p.product_id, SUM(t.quantity) as total_vol
                    FROM transactions t
                    JOIN product_variants pv ON t.variant_id = pv.variant_id
                    JOIN products p ON pv.product_id = p.product_id
                    GROUP BY p.product_id ORDER BY total_vol DESC LIMIT 5
                )
                SELECT p.product_name, pv.size, pv.color, tp.total_vol,
                       SUM(CASE WHEN t.transaction_type = 'IMPORT' THEN t.quantity ELSE 0 END) as imp,
                       SUM(CASE WHEN t.transaction_type = 'EXPORT' THEN t.quantity ELSE 0 END) as exp
                FROM transactions t 
                JOIN product_variants pv ON t.variant_id = pv.variant_id
                JOIN products p ON pv.product_id = p.product_id
                JOIN TopProducts tp ON p.product_id = tp.product_id
                GROUP BY p.product_name, pv.size, pv.color, tp.total_vol
                ORDER BY tp.total_vol DESC, (SUM(t.quantity)) DESC";
        return pg_fetch_all(pg_query($this->conn, $sql)) ?: [];
    }

    // --- NHÓM 4: PHỤC VỤ BIỂU ĐỒ ---
    public function getTop5BusiestDays()
    {
        $sql = "SELECT 
                DATE(created_at) as work_date, 
                SUM(CASE WHEN transaction_type = 'IMPORT' THEN quantity ELSE 0 END) as total_import,
                SUM(CASE WHEN transaction_type = 'EXPORT' THEN quantity ELSE 0 END) as total_export,
                SUM(quantity) as total_vol
            FROM transactions 
            GROUP BY work_date 
            ORDER BY total_vol DESC 
            LIMIT 5";
        $res = pg_query($this->conn, $sql);
        return pg_fetch_all($res) ?: [];
    }
    public function getTop5ProductFlow()
    {
        $sql = "SELECT p.product_name, 
                SUM(CASE WHEN t.transaction_type = 'IMPORT' THEN t.quantity ELSE 0 END) as total_import,
                SUM(CASE WHEN t.transaction_type = 'EXPORT' THEN t.quantity ELSE 0 END) as total_export
                FROM transactions t 
                JOIN product_variants pv ON t.variant_id = pv.variant_id
                JOIN products p ON pv.product_id = p.product_id
                GROUP BY p.product_name ORDER BY (SUM(t.quantity)) DESC LIMIT 5";
        return pg_fetch_all(pg_query($this->conn, $sql)) ?: [];
    }

    public function getMonthlyTrend()
    {
        $sql = "SELECT to_char(created_at, 'Mon') as month_name, 
                SUM(CASE WHEN transaction_type = 'IMPORT' THEN quantity ELSE 0 END) as imp, 
                SUM(CASE WHEN transaction_type = 'EXPORT' THEN quantity ELSE 0 END) as exp 
                FROM transactions 
                WHERE created_at > CURRENT_DATE - INTERVAL '6 months' 
                GROUP BY month_name, date_trunc('month', created_at) 
                ORDER BY date_trunc('month', created_at) ASC";
        return pg_fetch_all(pg_query($this->conn, $sql)) ?: [];
    }

    public function getBrandDistribution()
    {
        $sql = "SELECT c.category_name as brand, SUM(pv.stock) as total_stock 
                FROM product_variants pv 
                JOIN products p ON pv.product_id = p.product_id 
                JOIN categories c ON p.category_id = c.category_id 
                WHERE pv.is_deleted = false GROUP BY c.category_name";
        return pg_fetch_all(pg_query($this->conn, $sql)) ?: [];
    }

    public function getDetailedInventory()
    {
        $sql = "SELECT p.product_name, pv.size, pv.color, pv.stock, c.category_name
                FROM product_variants pv JOIN products p ON pv.product_id = p.product_id
                JOIN categories c ON p.category_id = c.category_id
                WHERE pv.is_deleted = false ORDER BY p.product_name ASC, pv.size ASC";
        return pg_fetch_all(pg_query($this->conn, $sql)) ?: [];
    }

    public function getStaffPerformance()
    {
        $sql = "SELECT u.full_name, COUNT(t.transaction_id) as total_tx 
                FROM transactions t JOIN users u ON t.user_id = u.user_id 
                GROUP BY u.full_name ORDER BY total_tx DESC LIMIT 3";
        return pg_fetch_all(pg_query($this->conn, $sql)) ?: [];
    }



    // --- CẤP 1: Lấy tổng hợp theo Brand ---
    public function getBrandDetail($type)
    {
        $stockConfig = require __DIR__ . '/../../config/stockconfig.php';
        $threshold = (int)$stockConfig['low_stock_threshold'];
        if ($type === 'stock') {
            $sql = "SELECT c.category_id, c.category_name as brand, SUM(pv.stock) as total 
                FROM categories c 
                JOIN products p ON c.category_id = p.category_id 
                JOIN product_variants pv ON p.product_id = pv.product_id 
                WHERE pv.is_deleted = false AND pv.status = true 
                  AND p.is_deleted = false AND p.status = true
                  AND c.is_deleted = false AND c.status = true
                GROUP BY c.category_id, c.category_name";
        } elseif ($type === 'shortage') {
            $sql = "SELECT c.category_id, c.category_name as brand, COUNT(pv.variant_id) as total 
        FROM categories c 
        JOIN products p ON c.category_id = p.category_id 
        JOIN product_variants pv ON p.product_id = pv.product_id 
        WHERE pv.stock < $threshold AND pv.is_deleted = false AND pv.status = true
          AND p.is_deleted = false AND p.status = true
          AND c.is_deleted = false AND c.status = true
        GROUP BY c.category_id, c.category_name";
        } else {
            $tType = strtoupper($type); // IMPORT hoặc EXPORT
            $sql = "SELECT c.category_id, c.category_name as brand, SUM(t.quantity) as total 
                FROM categories c 
                JOIN products p ON c.category_id = p.category_id 
                JOIN product_variants pv ON p.product_id = pv.product_id 
                JOIN transactions t ON pv.variant_id = t.variant_id 
                WHERE t.transaction_type = '$tType' 
                  AND date_trunc('month', t.created_at) = date_trunc('month', CURRENT_DATE)
                  AND pv.is_deleted = false AND p.is_deleted = false AND c.is_deleted = false
                GROUP BY c.category_id, c.category_name";
        }
        $res = pg_query($this->conn, $sql);
        return pg_fetch_all($res) ?: [];
    }

    // --- CẤP 2: Lấy danh sách sản phẩm theo từng Brand ---
    public function getProductsByBrand($brandId, $type)
    {
        $stockConfig = require __DIR__ . '/../../config/stockconfig.php';
        $threshold = (int)$stockConfig['low_stock_threshold'];
        if ($type === 'stock') {
            $sql = "SELECT p.product_id, p.product_name, SUM(pv.stock) as total 
                FROM products p 
                JOIN product_variants pv ON p.product_id = pv.product_id 
                WHERE p.category_id = $1 AND pv.is_deleted = false AND pv.status = true
                  AND p.is_deleted = false AND p.status = true
                GROUP BY p.product_id, p.product_name";
        } elseif ($type === 'shortage') {
            $sql = "SELECT p.product_id, p.product_name, COUNT(pv.variant_id) as total 
        FROM products p 
        JOIN product_variants pv ON p.product_id = pv.product_id 
        WHERE p.category_id = $1 AND pv.stock < $threshold AND pv.is_deleted = false AND pv.status = true
        GROUP BY p.product_id, p.product_name";
        } else {
            $tType = strtoupper($type);
            $sql = "SELECT p.product_id, p.product_name, SUM(t.quantity) as total 
                FROM products p 
                JOIN product_variants pv ON p.product_id = pv.product_id 
                JOIN transactions t ON pv.variant_id = t.variant_id 
                WHERE p.category_id = $1 AND t.transaction_type = '$tType' 
                  AND date_trunc('month', t.created_at) = date_trunc('month', CURRENT_DATE)
                  AND pv.is_deleted = false AND p.is_deleted = false
                GROUP BY p.product_id, p.product_name";
        }
        $res = pg_query_params($this->conn, $sql, [$brandId]);
        return pg_fetch_all($res) ?: [];
    }

    // --- CẤP 3: Lấy chi tiết biến thể (Size/Color) của một sản phẩm ---
    public function getVariantsByProduct($productId, $type)
    {
        $stockConfig = require __DIR__ . '/../../config/stockconfig.php';
        $threshold = (int)$stockConfig['low_stock_threshold'];
        if ($type === 'stock' || $type === 'shortage') {
            $sql = "SELECT size, color, stock as total 
        FROM product_variants 
        WHERE product_id = $1 AND is_deleted = false AND status = true";
            if ($type === 'shortage') $sql .= " AND stock < $threshold";
        } else {
            $tType = strtoupper($type);
            $sql = "SELECT pv.size, pv.color, SUM(t.quantity) as total 
                FROM product_variants pv 
                JOIN transactions t ON pv.variant_id = t.variant_id 
                WHERE pv.product_id = $1 AND t.transaction_type = '$tType' 
                  AND date_trunc('month', t.created_at) = date_trunc('month', CURRENT_DATE)
                  AND pv.is_deleted = false
                GROUP BY pv.size, pv.color";
        }
        $res = pg_query_params($this->conn, $sql, [$productId]);
        return pg_fetch_all($res) ?: [];
    }

    /**
     * BỔ SUNG CHO DASHBOARD: Lấy thống kê số lượng nhập, xuất và tổng giao dịch ngày hôm nay
     */
    public function getTodayStats()
    {
        $sql = "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'IMPORT' THEN quantity ELSE 0 END), 0) as total_import,
                    COALESCE(SUM(CASE WHEN transaction_type = 'EXPORT' THEN quantity ELSE 0 END), 0) as total_export,
                    COUNT(transaction_id) as total_transactions
                FROM transactions 
                WHERE DATE(created_at) = CURRENT_DATE";
        $res = pg_query($this->conn, $sql);
        return pg_fetch_assoc($res) ?: ['total_import' => 0, 'total_export' => 0, 'total_transactions' => 0];
    }
}
