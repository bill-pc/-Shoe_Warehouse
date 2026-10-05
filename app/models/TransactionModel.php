<?php
// app/models/TransactionModel.php
require_once __DIR__ . '/../../config/database.php';
class TransactionModel
{
    private $conn;
    public function __construct()
    {
        $this->conn = getConnection();
    }

    // 1. Lấy danh sách đã GOM NHÓM theo Ngày + Sản phẩm + Người thực hiện + Mã phiếu (reference_id)
    // Đã nâng cấp: Hỗ trợ ô tìm kiếm tích hợp searchQuery (Tìm theo Mã phiếu HOẶC Tên nhân viên)
    public function getSummary($type, $fromDate = null, $toDate = null, $searchQuery = null, $manager_id = null)
    {
        $sql = "SELECT 
                DATE(t.created_at) as log_date,
                p.product_name,
                p.product_id,
                u.full_name as user_name,
                u.user_id,
                t.reference_id,
                SUM(t.quantity) as total_qty
            FROM transactions t
            JOIN users u ON t.user_id = u.user_id
            JOIN product_variants pv ON t.variant_id = pv.variant_id
            JOIN products p ON pv.product_id = p.product_id
            LEFT JOIN tickets tk ON t.reference_id = tk.ticket_code
            WHERE t.transaction_type = $1";

        $params = [strtoupper($type)];
        $pCount = 2;

        if ($fromDate) {
            $sql .= " AND t.created_at >= $" . $pCount++;
            $params[] = $fromDate . ' 00:00:00';
        }
        if ($toDate) {
            $sql .= " AND t.created_at <= $" . $pCount++;
            $params[] = $toDate . ' 23:59:59';
        }
        // TÌM KIẾM ĐA NĂNG: Khớp mã phiếu HOẶC mã nhân viên (user_id)
        if ($searchQuery && trim($searchQuery) !== '') {
            $keyword = '%' . trim($searchQuery) . '%';
            $sql .= " AND (t.reference_id ILIKE $" . $pCount . " OR u.user_id::text ILIKE $" . $pCount . ")";
            $pCount++;
            $params[] = $keyword;
        }
        // Chỉ hiện phiếu do manager đang đăng nhập tạo
        if ($manager_id) {
            $sql .= " AND tk.manager_id = $" . $pCount++;
            $params[] = $manager_id;
        }

        $sql .= " GROUP BY log_date, p.product_name, p.product_id, user_name, u.user_id, t.reference_id
              ORDER BY log_date DESC, p.product_name ASC";

        $res = pg_query_params($this->conn, $sql, $params);
        return pg_fetch_all($res) ?: [];
    }


    // 2. Lấy CHI TIẾT các biến thể (Thêm p.product_image và lọc theo reference_id)
    public function getGroupDetails($date, $productId, $userId, $type, $referenceId = null)
    {
        $sql = "SELECT pv.size, pv.color, t.quantity, t.created_at, p.product_image
                FROM transactions t
                JOIN product_variants pv ON t.variant_id = pv.variant_id
                JOIN products p ON pv.product_id = p.product_id
                WHERE DATE(t.created_at) = $1 
                  AND pv.product_id = $2 
                  AND t.user_id = $3 
                  AND t.transaction_type = $4";

        $params = [$date, $productId, $userId, strtoupper($type)];
        $pCount = 5;

        if ($referenceId) {
            $sql .= " AND t.reference_id = $" . $pCount++;
            $params[] = $referenceId;
        } else {
            $sql .= " AND (t.reference_id IS NULL OR t.reference_id = '')";
        }

        $sql .= " ORDER BY t.created_at ASC";

        $res = pg_query_params($this->conn, $sql, $params);
        return pg_fetch_all($res) ?: [];
    }
}
