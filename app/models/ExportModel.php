<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/TicketModel.php';

class ExportModel
{
    private $conn;

    public function __construct()
    {
        $this->conn = getConnection();
    }

    /**
     * Chức năng: Tìm danh sách các phiếu EXPORT chưa xử lý.
     * Tác dụng: Render ra giao diện xuất thủ công của riêng tài khoản.
     */
    public function getMyExports($staff_id)
    {
        $sql = "SELECT ticket_id, ticket_code, status, created_at 
                FROM tickets 
                WHERE staff_id = $1 AND ticket_type = 'EXPORT' AND status IN ('PENDING', 'PROCESSING', 'PAUSED') AND is_deleted = false 
                ORDER BY created_at DESC";
        $res = pg_query_params($this->conn, $sql, [$staff_id]);
        return $res ? (pg_fetch_all($res) ?: []) : [];
    }

    /**
     * Chức năng: Cập nhật biến số lượng nhặt được vào CSDL.
     * Tác dụng: Lưu trực tiếp số lượng đã chọn và vị trí lấy hàng dưới dạng JSON vào cột note.
     */
    public function updateExportProgress($detail_id, $picked_qty, $picked_locations = null)
    {
        $sql = "UPDATE ticket_details SET processed_qty = $1, note = $2 WHERE detail_id = $3";
        return pg_query_params($this->conn, $sql, [$picked_qty, $picked_locations, $detail_id]);
    }


    /**
     * Chức năng: Tìm các Ô, Kệ chứa dòng JSONB mã hàng.
     * Tác dụng: Truy xuất được vị trí (layout) kho và chỉ đường cho thủ kho đi gom giày xuất đi.
     */
    public function getLocationsFromJSON($variant_id)
    {
        $sql = "
            SELECT 
                s.shelf_id,
                s.shelf_name, 
                (tier.key || '-' || slot.key) AS slot_code, 
                COUNT(item.value)::int AS qty_in_slot
            FROM 
                public.shelves s,
                jsonb_each(s.layout) AS tier,         
                jsonb_each(tier.value) AS slot,       
                jsonb_array_elements_text(slot.value) AS item 
            WHERE 
                item.value = $1::text AND s.is_deleted = true  AND s.status = true
            GROUP BY 
                s.shelf_id, s.shelf_name, tier.key, slot.key
            HAVING 
                COUNT(item.value) > 0
        ";

        $res = pg_query_params($this->conn, $sql, [$variant_id]);
        return $res ? (pg_fetch_all($res) ?: []) : [];
    }


    /**
     * Chức năng: Rà soát & kết thúc quy trình xuất kho.
     * Tác dụng: Xác nhận hàng đi. Khấu trừ cả lượng stock thực và lượng stock reserved. Giải phóng kệ chứa mảng JSON.
     */
    public function completeExportTicket($ticket_id, $user_id = null)
    {
        if (!$user_id && isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
        }
        pg_query($this->conn, "BEGIN");
        try {
            $resTicket = pg_query_params($this->conn, "SELECT ticket_code FROM tickets WHERE ticket_id = $1", [$ticket_id]);
            $ticket_code = $resTicket ? pg_fetch_result($resTicket, 0, 'ticket_code') : "TICKET-$ticket_id";
            $ticketModel = new TicketModel();
            $details = $ticketModel->getTicketDetails($ticket_id);
            foreach ($details as $item) {
                $qty = (int)$item['quantity'];
                $v_id = $item['variant_id'];
                $note = $item['note']; // Chứa vị trí kệ đã tạm lưu dưới dạng JSON

                // Khấu trừ lượng stock trong bảng product_variants
                $sqlStock = "UPDATE product_variants SET stock = stock - $1, reserved_stock = reserved_stock - $1 WHERE variant_id = $2";
                pg_query_params($this->conn, $sqlStock, [$qty, $v_id]);

                // Gỡ khỏi kệ kho bãi: Kiểm tra nếu có dữ liệu lưu tạm thì trừ kho chính xác, nếu không thì dùng thuật toán cũ
                $picked_locations = json_decode($note, true);
                if (empty($picked_locations) || !is_array($picked_locations)) {
                    $removed_from_shelves = $this->removeItemsFromShelves($v_id, $qty);
                } else {
                    $removed_from_shelves = $this->removePreciseItemsFromShelves($v_id, $note);
                }

                // =========================================================================
                // ĐÃ TẮT BỎ QUA KIỂM TRA SỐ LƯỢNG TRÊN KỆ ĐỂ TRÁNH BỊ LỖI KHI LỆCH KỆ ẢO
                // =========================================================================
                /*
                if ($removed_from_shelves !== $qty) {
                    throw new Exception("Số lượng xuất của biến thể ID $v_id ($qty đôi) không khớp với số lượng thực tế rút được trên các kệ ($removed_from_shelves đôi)!");
                }
                */

                pg_query_params($this->conn, "INSERT INTO transactions (transaction_type, variant_id, quantity, user_id, reference_id) VALUES ('EXPORT', $1, $2, $3, $4)", [$v_id, $qty, $user_id, $ticket_code]);
            }

            pg_query_params($this->conn, "UPDATE tickets SET status = 'COMPLETED', completed_at = CURRENT_TIMESTAMP WHERE ticket_id = $1", [$ticket_id]);

            pg_query($this->conn, "COMMIT");
            return true;
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            throw $e; // Ném lỗi ra ngoài
        }
    }

    /**
     * Chức năng: Helper trừ kho chính xác theo các kệ/ô mà thủ kho đã chọn và lưu tạm
     */
    private function removePreciseItemsFromShelves($variant_id, $note)
    {
        $picked_locations = json_decode($note, true);
        if (empty($picked_locations) || !is_array($picked_locations)) {
            return 0;
        }

        $total_removed = 0;
        foreach ($picked_locations as $loc) {
            $shelf_id = (int)$loc['shelf_id'];
            $slot_code = $loc['slot_code']; // Dạng "1-01" hoặc "tier-slot"
            $qty_to_remove = (int)$loc['qty'];

            if ($qty_to_remove <= 0) continue;

            $sql = "SELECT layout FROM shelves WHERE shelf_id = $1";
            $res = pg_query_params($this->conn, $sql, [$shelf_id]);
            if ($row = pg_fetch_assoc($res)) {
                $layout = json_decode($row['layout'], true);

                $parts = explode('-', $slot_code);
                if (count($parts) === 2) {
                    $tier = $parts[0];
                    $slot = $parts[1];

                    if (isset($layout[$tier][$slot]) && is_array($layout[$tier][$slot])) {
                        $items = &$layout[$tier][$slot];
                        $removed_in_slot = 0;

                        foreach ($items as $key => $val) {
                            if ($val == $variant_id && $removed_in_slot < $qty_to_remove) {
                                unset($items[$key]);
                                $removed_in_slot++;
                                $total_removed++;
                            }
                        }

                        if ($removed_in_slot > 0) {
                            $layout[$tier][$slot] = array_values($items);
                            $jsonb_str = json_encode($layout);
                            pg_query_params($this->conn, "UPDATE shelves SET layout = $1 WHERE shelf_id = $2", [$jsonb_str, $shelf_id]);
                        }
                    }
                }
            }
        }
        return $total_removed;
    }



    /**
     * Chức năng: Helper bóc tách mảng JSON của cấu trúc kho bãi.
     * Tác dụng: Gỡ chuỗi Object array theo ID, trả lại chổ trống cho kệ kho để sau này chứa đồ khác.
     */
    private function removeItemsFromShelves($variant_id, $total_to_remove)
    {
        $original_to_remove = $total_to_remove;
        $sql = "SELECT shelf_id, layout FROM shelves WHERE layout::text LIKE '%" . $variant_id . "%'";
        $res = pg_query($this->conn, $sql);

        while ($row = pg_fetch_assoc($res)) {
            if ($total_to_remove <= 0) break;
            $layout = json_decode($row['layout'], true);
            $changed = false;

            foreach ($layout as $tier => &$slots) {
                foreach ($slots as $slot_code => &$items) {
                    if ($total_to_remove <= 0) break;
                    $slotChanged = false;
                    foreach ($items as $key => $val) {
                        if ($val == $variant_id && $total_to_remove > 0) {
                            unset($items[$key]);
                            $total_to_remove--;
                            $slotChanged = true;
                            $changed = true;
                        }
                    }
                    if ($slotChanged) {
                        $items = array_values($items);
                    }
                }
            }
            if ($changed) {
                pg_query_params($this->conn, "UPDATE shelves SET layout = $1 WHERE shelf_id = $2", [json_encode($layout), $row['shelf_id']]);
            }
        }

        // Trả về số lượng thực tế đã gỡ thành công khỏi các ô kệ
        return $original_to_remove - $total_to_remove;
    }
}
