<?php
require_once __DIR__ . '/../../config/database.php';

class TicketModel
{
    private $conn;

    public function __construct()
    {
        $this->conn = getConnection();
    }

    /**
     * Chức năng: Lấy danh sách nhân viên để phân công.
     * Tác dụng: Dùng để fill dữ liệu vào thẻ <select> khi tạo hoặc chuyển phiếu.
     */
    public function getStaffs()
    {
        $sql = "SELECT user_id, full_name 
                FROM users 
                WHERE role = 'STAFF' AND is_deleted = false AND status = true";

        $result = pg_query($this->conn, $sql);
        return $result ? (pg_fetch_all($result) ?: []) : [];
    }

    /**
     * Chức năng: Lấy danh sách các Hãng giày đang kinh doanh.
     * Tác dụng: Dùng để fill dữ liệu vào combobox chọn Hãng khi tạo phiếu.
     */
    public function getBrands()
    {
        $sql = "SELECT category_id, category_name 
                FROM categories 
                WHERE is_deleted = false AND status = true";

        $result = pg_query($this->conn, $sql);
        return $result ? (pg_fetch_all($result) ?: []) : [];
    }

    /**
     * Chức năng: Lấy danh sách Mẫu giày dựa theo ID Hãng.
     * Tác dụng: Cập nhật động dữ liệu cho combobox Mẫu giày sau khi đã chọn Hãng.
     */
    public function getProductsByBrand($brand_id)
    {
        $sql = "SELECT product_id, product_name 
                FROM products 
                WHERE category_id = $1 AND is_deleted = false";

        $result = pg_query_params($this->conn, $sql, [$brand_id]);
        return $result ? (pg_fetch_all($result) ?: []) : [];
    }

    /**
     * Chức năng: Sinh tự động mã phiếu Nhập/Xuất kho.
     * Tác dụng: Tạo chuỗi định dạng PN-YYMMDD-XXXX (Nhập) hoặc PX-YYMMDD-XXXX (Xuất) chống trùng lặp.
     */
    public function generateTicketCode($type)
    {
        $typeStr = ($type === 'IMPORT') ? 'PN-' : 'PX-';
        $dateStr = date('ymd');
        $prefix = $typeStr . $dateStr . '-';

        $sql = "SELECT COALESCE(MAX(REPLACE(ticket_code, $1, '')::integer), 0) + 1 AS next_id 
                FROM tickets 
                WHERE ticket_code LIKE $2";

        $result = pg_query_params($this->conn, $sql, [$prefix, $prefix . '%']);
        $row = pg_fetch_assoc($result);

        return $prefix . str_pad($row['next_id'], 4, '0', STR_PAD_LEFT);
    }




    /**
     * Chức năng: Sinh tự động mã Lô hàng cho phiếu Xuất kho.
     * Cấu trúc: LH-YYMMDD-XXX (LH - 6 số ngày tạo - 3 số tự tăng)
     */
    public function generateExportBatchCode()
    {
        $dateStr = date('ymd');
        $prefix = 'LH-' . $dateStr . '-';

        $sql = "SELECT COALESCE(MAX(REPLACE(batch_code, $1, '')::integer), 0) + 1 AS next_id 
                FROM tickets 
                WHERE batch_code LIKE $2";

        $result = pg_query_params($this->conn, $sql, [$prefix, $prefix . '%']);
        $row = pg_fetch_assoc($result);

        return $prefix . str_pad($row['next_id'], 3, '0', STR_PAD_LEFT);
    }


    /**
     * Chức năng: Tìm kiếm các sản phẩm sắp hết hàng.
     * Tác dụng: Hiển thị gợi ý nhắc nhở nhập kho nếu số lượng tồn kho dưới 5 đôi.
     */
    public function getLowStockSuggestions()
    {
        // NẠP ĐỘNG STOCK CONFIG
        $stockConfig = require __DIR__ . '/../../config/stockconfig.php';
        $threshold = (int)$stockConfig['low_stock_threshold'];

        $sql = "SELECT pv.variant_id, c.category_name as brand, p.product_name, p.product_image, pv.color, pv.size, pv.stock
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.product_id
            JOIN categories c ON p.category_id = c.category_id
            WHERE pv.stock < $threshold AND pv.is_deleted = false AND p.is_deleted = false
            ORDER BY pv.stock ASC";

        $result = pg_query($this->conn, $sql);
        return $result ? (pg_fetch_all($result) ?: []) : [];
    }


    /**
     * Chức năng: Lấy danh sách Biến thể (Màu, Size) của một Mẫu giày.
     * Tác dụng: Dùng để người quản lý chọn đưa vào phiếu. Nếu là phiếu XUẤT, sẽ trừ đi số hàng đã bị đặt trước (reserved_stock).
     */
    public function getVariantsByProduct($product_id, $type = '')
    {
        if ($type === 'EXPORT') {
            $sql = "SELECT pv.variant_id, pv.color, pv.size, 
                           (pv.stock - COALESCE(pv.reserved_stock, 0)) AS stock, 
                           p.product_image 
                    FROM product_variants pv
                    JOIN products p ON pv.product_id = p.product_id
                    WHERE pv.product_id = $1 AND pv.is_deleted = false 
                      AND (pv.stock - COALESCE(pv.reserved_stock, 0)) > 0";
        } else {
            $sql = "SELECT pv.variant_id, pv.color, pv.size, pv.stock, p.product_image 
                    FROM product_variants pv
                    JOIN products p ON pv.product_id = p.product_id
                    WHERE pv.product_id = $1 AND pv.is_deleted = false";
        }

        $result = pg_query_params($this->conn, $sql, [$product_id]);
        return $result ? (pg_fetch_all($result) ?: []) : [];
    }

    /**
     * Chức năng: Tạo mới Phiếu Nhập/Xuất kho vào Database.
     * Tác dụng: Lưu phiếu mẹ, lưu các dòng chi tiết, và khóa số lượng tồn kho khả dụng (reserved_stock) nếu là xuất hàng.
     */
    public function createTicket($ticket_code, $batch_code, $type, $manager_id, $staff_id, $details)
    {
        $staff_id_param = !empty($staff_id) ? $staff_id : null;
        pg_query($this->conn, "BEGIN");

        try {
            $sqlMaster = "INSERT INTO tickets (ticket_code, batch_code, ticket_type, manager_id, staff_id) 
                          VALUES ($1, $2, $3, $4, $5) RETURNING ticket_id";
            $resMaster = pg_query_params($this->conn, $sqlMaster, [trim($ticket_code), trim($batch_code), $type, $manager_id, $staff_id_param]);
            $ticket_id = pg_fetch_result($resMaster, 0, 'ticket_id');

            foreach ($details as $item) {
                if ($type === 'EXPORT') {
                    $checkSql = "SELECT (stock - COALESCE(reserved_stock, 0)) AS available_stock 
                                 FROM product_variants WHERE variant_id = $1 FOR UPDATE";
                    $resCheck = pg_query_params($this->conn, $checkSql, [$item['variant_id']]);
                    $avail = pg_fetch_result($resCheck, 0, 'available_stock');

                    if ($item['quantity'] > $avail) {
                        throw new Exception("out_of_stock");
                    }
                }

                $sqlDetail = "INSERT INTO ticket_details (ticket_id, variant_id, quantity) VALUES ($1, $2, $3)";
                pg_query_params($this->conn, $sqlDetail, [$ticket_id, $item['variant_id'], $item['quantity']]);

                if ($type === 'EXPORT') {
                    $sqlReserve = "UPDATE product_variants SET reserved_stock = reserved_stock + $1 WHERE variant_id = $2";
                    pg_query_params($this->conn, $sqlReserve, [$item['quantity'], $item['variant_id']]);
                }
            }

            pg_query($this->conn, "COMMIT");
            return true;
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            if ($e->getMessage() === 'out_of_stock') {
                return 'out_of_stock';
            }
            return false;
        }
    }

    /**
     * Chức năng: Truy xuất toàn bộ lịch sử Phiếu dành cho giao diện Quản lý.
     * Tác dụng: Trả về danh sách phiếu kèm bộ lọc trạng thái và khoảng thời gian tạo.
     */
    public function getAllTickets($type, $status = '', $start_date = '', $end_date = '', $manager_id = null)
    {
        $sql = "SELECT t.*, u.full_name as staff_name 
            FROM tickets t 
            LEFT JOIN users u ON t.staff_id = u.user_id 
            WHERE t.ticket_type = $1 AND t.is_deleted = false";

        $params = [$type];
        $pIdx = 2;

        if (!empty($status)) {
            $sql .= " AND t.status = $" . $pIdx++;
            $params[] = $status;
        }
        if (!empty($start_date)) {
            $sql .= " AND DATE(t.created_at) >= $" . $pIdx++;
            $params[] = $start_date;
        }
        if (!empty($end_date)) {
            $sql .= " AND DATE(t.created_at) <= $" . $pIdx++;
            $params[] = $end_date;
        }
        // Lọc theo manager tạo phiếu nếu có truyền vào
        if (!empty($manager_id)) {
            $sql .= " AND t.manager_id = $" . $pIdx++;
            $params[] = $manager_id;
        }

        $sql .= " ORDER BY t.created_at DESC";

        $result = pg_query_params($this->conn, $sql, $params);
        return $result ? (pg_fetch_all($result) ?: []) : [];
    }


    /**
     * Chức năng: Thay đổi nhân viên phụ trách phiếu (Bàn giao).
     * Tác dụng: Cho phép bàn giao phiếu ở trạng thái PENDING hoặc PAUSED.
     */
    public function updateStaffInTicket($ticket_id, $new_staff_id)
    {
        // 1. Lấy staff_id và status hiện tại để kiểm tra an toàn
        $sqlGet = "SELECT staff_id, status FROM tickets WHERE ticket_id = $1 AND is_deleted = false";
        $resGet = pg_query_params($this->conn, $sqlGet, [$ticket_id]);
        $row = $resGet ? pg_fetch_assoc($resGet) : null;
        if (!$row) return false;

        $old_staff_id = $row['staff_id'];
        $status = $row['status'];

        // Quy trình: Chỉ cho phép bàn giao khi phiếu chưa bắt đầu (PENDING) hoặc đang tạm dừng (PAUSED)
        if ($status !== 'PENDING' && $status !== 'PAUSED') {
            return false;
        }

        // 2. Tiến hành cập nhật staff_id mới
        $sql = "UPDATE tickets 
                SET staff_id = $1 
                WHERE ticket_id = $2 AND status IN ('PENDING', 'PAUSED') AND is_deleted = false";
        $result = pg_query_params($this->conn, $sql, [$new_staff_id, $ticket_id]);

        // Trả về staff_id cũ CHỈ KHI thực sự có dòng trong CSDL được cập nhật thành công
        return ($result && pg_affected_rows($result) > 0) ? $old_staff_id : false;
    }


    /**
     * Chức năng: Xóa một tờ phiếu bằng phương pháp Xóa Mềm.
     * Tác dụng: Đánh dấu is_deleted = true. Nếu là phiếu xuất kho, tự động xả số lượng hàng đang bị giữ chỗ (reserved_stock).
     */
    public function softDeleteTicket($ticket_id)
    {
        pg_query($this->conn, "BEGIN");
        try {
            $checkSql = "SELECT staff_id, ticket_type FROM tickets WHERE ticket_id = $1 AND status = 'PENDING' AND is_deleted = false";
            $resCheck = pg_query_params($this->conn, $checkSql, [$ticket_id]);

            if ($row = pg_fetch_assoc($resCheck)) {
                $staff_id = $row['staff_id'];

                if ($row['ticket_type'] === 'EXPORT') {
                    $details = $this->getTicketDetails($ticket_id);
                    foreach ($details as $item) {
                        pg_query_params($this->conn, "UPDATE product_variants SET reserved_stock = reserved_stock - $1 WHERE variant_id = $2", [$item['quantity'], $item['variant_id']]);
                    }
                }

                $sql = "UPDATE tickets SET is_deleted = true WHERE ticket_id = $1 AND status = 'PENDING'";
                $result = pg_query_params($this->conn, $sql, [$ticket_id]);
                $affected = pg_affected_rows($result);

                pg_query($this->conn, "COMMIT");

                return ($result && $affected > 0) ? $staff_id : false;
            }

            pg_query($this->conn, "ROLLBACK");
            return false;
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            return false;
        }
    }

    /**
     * Chức năng: Lấy thông tin chi tiết từng đôi giày trong một mã phiếu cụ thể.
     * Tác dụng: Dùng để hiển thị lên Modal xem chi tiết phiếu hoặc đổ vào khung thao tác Quét hàng AI.
     * Lấy thêm product_id, mảng other_variants và vị trí kệ (putaway_locations)
     */
    public function getTicketDetails($ticket_id)
    {
        $sql = "SELECT 
                    td.detail_id,       
                    td.variant_id,      
                    td.quantity, 
                    COALESCE(td.processed_qty, 0) as processed_qty, 
                    td.note,
                    td.is_diff,      
                    td.qr_code,  
                    pv.color, 
                    pv.size, 
                    pv.sku,
                    p.product_id,       
                    p.product_name, 
                    p.product_image, 
                    c.category_name as brand,
                    tit.putaway_locations,
                    t.completed_at as import_date,
                    t.staff_id as staff_id,
                    u.full_name as staff_name
                FROM ticket_details td
                JOIN tickets t ON td.ticket_id = t.ticket_id
                LEFT JOIN users u ON t.staff_id = u.user_id
                JOIN product_variants pv ON td.variant_id = pv.variant_id
                JOIN products p ON pv.product_id = p.product_id
                JOIN categories c ON p.category_id = c.category_id
                LEFT JOIN ticket_import_temp tit ON td.ticket_id = tit.ticket_id AND td.variant_id = tit.variant_id
                WHERE td.ticket_id = $1";

        $result = pg_query_params($this->conn, $sql, [$ticket_id]);
        $items = $result ? (pg_fetch_all($result) ?: []) : [];

        foreach ($items as &$item) {
            $sqlOthers = "SELECT pv.size, pv.color, td.quantity 
                          FROM ticket_details td 
                          JOIN product_variants pv ON td.variant_id = pv.variant_id
                          WHERE td.ticket_id = $1 AND pv.product_id = $2 AND pv.variant_id != $3";
            $resOthers = pg_query_params($this->conn, $sqlOthers, [$ticket_id, $item['product_id'], $item['variant_id']]);
            $item['other_variants'] = $resOthers ? (pg_fetch_all($resOthers) ?: []) : [];
        }

        return $items;
    }


    /**
     * Chức năng: Đếm tổng số lượng phiếu đang chờ xử lý của một nhân viên.
     * Tác dụng: Đổ số lượng thông báo ra cục màu đỏ (Badge Notification) trên màn hình.
     */
    public function getPendingCounts($staff_id)
    {
        $sql = "SELECT ticket_type, COUNT(*) as total 
            FROM tickets 
            WHERE staff_id = $1 AND status IN ('PENDING', 'PAUSED', 'PROCESSING') AND is_deleted = false 
            GROUP BY ticket_type";

        $result = pg_query_params($this->conn, $sql, [$staff_id]);
        $counts = ['IMPORT' => 0, 'EXPORT' => 0];

        if ($result) {
            while ($row = pg_fetch_assoc($result)) {
                $counts[$row['ticket_type']] = (int)$row['total'];
            }
        }
        return $counts;
    }

    /**
     * Chức năng: Cập nhật đổi trạng thái của phiếu (Ví dụ: Từ PENDING sang PROCESSING).
     * Tác dụng: Lưu trạng thái mới vào CSDL, dùng chung cho cả nghiệp vụ Nhập và Xuất.
     */
    public function updateTicketStatus($ticket_id, $status)
    {
        $sql = "UPDATE tickets SET status = $1 WHERE ticket_id = $2";
        return pg_query_params($this->conn, $sql, [$status, $ticket_id]);
    }

    /**
     * Chức năng: Lấy lịch sử tất cả các phiếu của riêng một nhân viên.
     * Tác dụng: Hiển thị bảng tổng kết công việc cho nhân viên xem với bộ lọc tùy chỉnh.
     */
    public function getStaffTickets($staff_id, $status = '', $start_date = '', $end_date = '')
    {
        $sql = "SELECT * FROM tickets WHERE staff_id = $1 AND is_deleted = false";
        $params = [$staff_id];
        $pIdx = 2;

        if (!empty($status)) {
            $sql .= " AND status = $" . $pIdx++;
            $params[] = $status;
        }
        if (!empty($start_date)) {
            $sql .= " AND DATE(created_at) >= $" . $pIdx++;
            $params[] = $start_date;
        }
        if (!empty($end_date)) {
            $sql .= " AND DATE(created_at) <= $" . $pIdx++;
            $params[] = $end_date;
        }

        $sql .= " ORDER BY created_at DESC";

        $result = pg_query_params($this->conn, $sql, $params);
        return $result ? (pg_fetch_all($result) ?: []) : [];
    }
}
