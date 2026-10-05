
<?php
// URL tạo QR ngrok
$qrConfig = require __DIR__ . '/../../config/QRconfig.php';
define('BASE_URL', $qrConfig['BASE_URL']);

class ImportModel
{
    private $conn;

    public function __construct()
    {
        $this->conn = getConnection();
    }

    /**
     * Chức năng: Tìm các phiếu Nhập (IMPORT) chưa hoàn tất của một Staff.
     * Tác dụng: Cấp dữ liệu để tải danh sách công việc bên cột trái màn hình thao tác Nhập Kho AI.
     */
    public function getMyImports($staff_id)
    {
        $sql = "SELECT ticket_id, ticket_code, status, created_at 
                FROM tickets 
                WHERE staff_id = $1 AND ticket_type = 'IMPORT' AND status IN ('PENDING', 'PROCESSING', 'PAUSED') AND is_deleted = false 
                ORDER BY created_at DESC";

        $res = pg_query_params($this->conn, $sql, [$staff_id]);
        return $res ? (pg_fetch_all($res) ?: []) : [];
    }

    /**
     * Chức năng: Cập nhật thông tin thực nhập cho một Size/Màu cụ thể vào Bảng Tạm.
     * Tác dụng: Ghi đè số liệu, tự động tính toán chênh lệch (is_diff) và cấp phát mã QR định danh.
     */
    public function saveTempImport($ticket_id, $detail_id, $variant_id, $actual_qty, $image, $note, $staff_id, $putaway_locations)
    {
        pg_query($this->conn, "BEGIN");
        try {
            // 1. Lấy số lượng yêu cầu để tính độ lệch
            $sqlGetQty = "SELECT quantity FROM ticket_details WHERE detail_id = $1";
            $resQty = pg_query_params($this->conn, $sqlGetQty, [$detail_id]);
            $expected_qty = pg_fetch_result($resQty, 0, 'quantity');

            // 2. Kích hoạt cờ is_diff nếu có sai lệch & Tạo URL cho QR Code
            $is_diff = ($actual_qty != $expected_qty) ? 'true' : 'false';


            // Tạo link đích bằng cách ghép hằng số BASE_URL
            $target_url = BASE_URL . "check_QR.php?tid=$ticket_id&vid=$variant_id";

            //  Gọi QuickChart API để "biến" cái link trên thành hình ảnh QR
            // Chỉ lưu đúng cái ĐÍCH ĐẾN (Target URL) vào Database
            $qr_code = BASE_URL . "check_QR.php?tid=$ticket_id&vid=$variant_id";

            pg_query_params($this->conn, "DELETE FROM ticket_import_temp WHERE ticket_id = $1 AND variant_id = $2", [$ticket_id, $variant_id]);

            // 3. Lưu vào bảng tạm
            $sqlTemp = "INSERT INTO ticket_import_temp 
                        (ticket_id, variant_id, expected_qty, actual_qty, scanned_image, note, staff_id, putaway_locations, is_diff, qr_code) 
                        VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)";
            pg_query_params($this->conn, $sqlTemp, [
                $ticket_id,
                $variant_id,
                $expected_qty,
                $actual_qty,
                $image,
                $note,
                $staff_id,
                $putaway_locations,
                $is_diff,
                $qr_code
            ]);

            // 4. Đồng bộ ngay vào bảng chi tiết
            $sqlUpdateDetail = "UPDATE ticket_details SET processed_qty = $1, note = $3, is_diff = $4, qr_code = $5 WHERE detail_id = $2";
            pg_query_params($this->conn, $sqlUpdateDetail, [$actual_qty, $detail_id, $note, $is_diff, $qr_code]);

            pg_query($this->conn, "COMMIT");

            // Trả về mảng chứa URL QR để file import.js gọi API hiện Popup
            return ['status' => 'success', 'qr_code' => $qr_code];
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }



    /**
     * Chức năng Helper: Nhét mã variant_id vào mảng JSON của kệ tương ứng.
     */
    private function addItemsToShelves($locations)
    {
        $grouped = [];
        foreach ($locations as $loc) {
            $grouped[$loc['shelf_id']][] = $loc;
        }

        foreach ($grouped as $shelf_id => $locs) {
            $res = pg_query_params($this->conn, "SELECT layout FROM shelves WHERE shelf_id = $1 FOR UPDATE", [$shelf_id]);
            if ($row = pg_fetch_assoc($res)) {
                $layout = json_decode($row['layout'], true);
                $changed = false;

                foreach ($locs as $loc) {
                    $tier = $loc['tier'];
                    $slot = $loc['slot'];
                    $qty = (int)$loc['qty'];
                    $vid = (string)$loc['variant_id'];

                    if (!isset($layout[$tier][$slot])) $layout[$tier][$slot] = [];

                    for ($i = 0; $i < $qty; $i++) {
                        $layout[$tier][$slot][] = $vid;
                        $changed = true;
                    }
                }
                if ($changed) {
                    pg_query_params($this->conn, "UPDATE shelves SET layout = $1 WHERE shelf_id = $2", [json_encode($layout), $shelf_id]);
                }
            }
        }
    }

    /**
     * Chức năng: Xác nhận nhập kho chính thức.
     * Tác dụng: Cộng tồn kho, đẩy hàng lên kệ, xóa bảng tạm và gán tag COMPLETE_DIFF nếu có bất kỳ dòng nào sai lệch.
     */
    public function completeImportTicket($ticket_id, $user_id)
    {
        pg_query($this->conn, "BEGIN");
        try {
            // 🥇 BỔ SUNG: Truy vấn lấy mã ticket_code từ bảng tickets
            $resTicket = pg_query_params($this->conn, "SELECT ticket_code FROM tickets WHERE ticket_id = $1", [$ticket_id]);
            $ticket_code = $resTicket ? pg_fetch_result($resTicket, 0, 'ticket_code') : "TICKET-$ticket_id";
            $sqlTemp = "SELECT variant_id, actual_qty, putaway_locations FROM ticket_import_temp WHERE ticket_id = $1";
            $resTemp = pg_query_params($this->conn, $sqlTemp, [$ticket_id]);
            if ($resTemp) {
                while ($row = pg_fetch_assoc($resTemp)) {
                    $v_id = $row['variant_id'];
                    $qty = (int)$row['actual_qty'];
                    $locations = json_decode($row['putaway_locations'], true) ?: [];

                    // Tính tổng số lượng phân bổ thực tế trên các kệ cho biến thể này
                    $shelves_qty = 0;
                    if (is_array($locations)) {
                        foreach ($locations as $loc) {
                            if ($loc['variant_id'] == $v_id) {
                                $shelves_qty += (int)$loc['qty'];
                            }
                        }
                    }

                    // Đối soát: Số lượng thực tế nhập kho phải khớp hoàn toàn với số lượng xếp lên kệ
                    if ($qty !== $shelves_qty) {
                        throw new Exception("Số lượng thực tế nhập của biến thể ID $v_id ($qty đôi) không khớp với tổng số lượng xếp lên kệ ($shelves_qty đôi)!");
                    }

                    // Cập nhật tồn kho bảng product_variants bằng SỐ LƯỢNG THỰC TẾ
                    pg_query_params($this->conn, "UPDATE product_variants SET stock = stock + $1 WHERE variant_id = $2", [$qty, $v_id]);

                    // 🚀 Thay thế "TICKET-$ticket_id" thành $ticket_code
                    pg_query_params($this->conn, "INSERT INTO transactions (transaction_type, variant_id, quantity, user_id, reference_id) VALUES ('IMPORT', $1, $2, $3, $4)", [$v_id, $qty, $user_id, $ticket_code]);

                    // Đẩy giày vào các ô kệ đã chọn
                    if (count($locations) > 0) {
                        $this->addItemsToShelves($locations);
                    }
                }
            }

            // Quét cờ is_diff toàn bộ chi tiết phiếu
            $sqlCheck = "SELECT EXISTS(SELECT 1 FROM ticket_details WHERE ticket_id = $1 AND is_diff = true)";
            $resCheck = pg_query_params($this->conn, $sqlCheck, [$ticket_id]);
            $hasDiff = pg_fetch_result($resCheck, 0, 0) === 't';

            $final_status = $hasDiff ? 'COMPLETE_DIFF' : 'COMPLETED';
            $is_diff_val = $hasDiff ? 'true' : 'false';

            // Cập nhật phiếu mẹ
            pg_query_params($this->conn, "UPDATE tickets SET status = $2, is_diff = $3, completed_at = CURRENT_TIMESTAMP WHERE ticket_id = $1", [$ticket_id, $final_status, $is_diff_val]);

            pg_query_params($this->conn, "DELETE FROM ticket_import_temp WHERE ticket_id = $1", [$ticket_id]);

            pg_query($this->conn, "COMMIT");
            return $final_status;
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            $_SESSION['import_error'] = $e->getMessage(); // Lưu thông báo lỗi đối soát chi tiết
            return false;
        }
    }


    /**
     * Chức năng: Thiết lập bảng tạm ban đầu trước khi quét hàng.
     * Tác dụng: (Dùng cho phương pháp cũ) Đổ dữ liệu = 0 chuẩn bị cho quét AI Real-time.
     */
    public function startImportProcess($ticket_id)
    {
        pg_query($this->conn, "BEGIN");
        try {
            pg_query_params($this->conn, "UPDATE tickets SET status = 'PROCESSING' WHERE ticket_id = $1", [$ticket_id]);

            $sql = "INSERT INTO ticket_import_temp (ticket_id, variant_id, expected_qty, actual_qty)
                SELECT ticket_id, variant_id, quantity, 0 
                FROM ticket_details WHERE ticket_id = $1
                ON CONFLICT (ticket_id, variant_id) DO NOTHING";
            pg_query_params($this->conn, $sql, [$ticket_id]);

            pg_query($this->conn, "COMMIT");
            return true;
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            return false;
        }
    }

    /**
     * Chức năng: (Cũ) Nhảy số lượng + 1 liên tục khi AI quét trúng.
     * Tác dụng: Quét tới đâu cộng số lượng tới đó (đã được thay bằng phương pháp Submit Form).
     */

    // 25-5-2026: HÀM NÀY CÓ THỂ KHÔNG CẦN NỮA, KIỂM TRA LẠI CÂN NHẮC KHI XÓA
    public function updateImportTemp($ticket_id, $variant_id, $image_url)
    {
        $sql = "UPDATE ticket_import_temp 
            SET actual_qty = actual_qty + 1, scanned_image = $3
            WHERE ticket_id = $1 AND variant_id = $2 
            RETURNING actual_qty";
        $res = pg_query_params($this->conn, $sql, [$ticket_id, $variant_id, $image_url]);
        return $res ? pg_fetch_assoc($res) : false;
    }

    /**
     * Chức năng: Trừ bớt 1 số lượng nếu xóa nhầm ảnh quét AI.
     * Tác dụng: Hoàn trả số thực nhận nếu NV check tay lại thấy bị dư.
     */
    // 25-5-2026: HÀM NÀY CÓ THỂ KHÔNG CẦN NỮA, KIỂM TRA LẠI CÂN NHẮC KHI XÓA
    public function decreaseImportTemp($ticket_id, $variant_id)
    {
        $sql = "UPDATE ticket_import_temp 
            SET actual_qty = GREATEST(actual_qty - 1, 0)
            WHERE ticket_id = $1 AND variant_id = $2";
        return pg_query_params($this->conn, $sql, [$ticket_id, $variant_id]);
    }

    /**
     * Chức năng: Hàm Chốt phiếu gốc chưa chia Dư/Thiếu.
     * Tác dụng: Gọi dứt điểm phiên thao tác, cập nhật thời gian.
     */
    // 25-5-2026: HÀM NÀY CÓ THỂ KHÔNG CẦN NỮA, KIỂM TRA LẠI CÂN NHẮC KHI XÓA
    public function finalizeImport($ticket_id, $user_id)
    {
        pg_query($this->conn, "BEGIN");
        try {
            $res = pg_query_params($this->conn, "SELECT variant_id, actual_qty FROM ticket_import_temp WHERE ticket_id = $1", [$ticket_id]);
            while ($row = pg_fetch_assoc($res)) {
                pg_query_params($this->conn, "UPDATE product_variants SET stock = stock + $1 WHERE variant_id = $2", [$row['actual_qty'], $row['variant_id']]);
                pg_query_params($this->conn, "UPDATE ticket_details SET processed_qty = $1 WHERE ticket_id = $2 AND variant_id = $3", [$row['actual_qty'], $ticket_id, $row['variant_id']]);
            }
            pg_query_params($this->conn, "UPDATE tickets SET status = 'COMPLETE', completed_at = CURRENT_TIMESTAMP WHERE ticket_id = $1", [$ticket_id]);
            pg_query_params($this->conn, "DELETE FROM ticket_import_temp WHERE ticket_id = $1", [$ticket_id]);
            pg_query($this->conn, "COMMIT");
            return true;
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            return false;
        }
    }



/**
     * Chức năng: Tìm các Ô, Kệ đang chứa mẫu này HOẶC còn trống kèm chấm điểm Ranking Score.
     */
    public function getPutawayLocations($variant_id, $ticket_id = 0, $quantity = 0)
    {
        try {
            // 1. Lấy danh sách kệ từ DB
$shelves = pg_query($this->conn, "SELECT shelf_id, shelf_name, layout, max_capacity_per_slot FROM shelves ORDER BY shelf_name ASC, shelf_id ASC");
            if (!$shelves) {
                return ['current' => [], 'available' => [], 'recommended' => [], 'current_alloc' => []];
            }

            // 2. Lấy danh sách vị trí đã được giữ chỗ trong bảng tạm
            $sqlTemp = "SELECT putaway_locations FROM ticket_import_temp WHERE putaway_locations IS NOT NULL AND NOT (ticket_id = $1 AND variant_id = $2)";
            $resTemp = @pg_query_params($this->conn, $sqlTemp, [(int)$ticket_id, (int)$variant_id]);

            $reserved_slots = [];
            if ($resTemp) {
                while ($row = pg_fetch_assoc($resTemp)) {
                    $locs = json_decode($row['putaway_locations'], true);
                    if (is_array($locs)) {
                        foreach ($locs as $loc) {
                            $key = "{$loc['shelf_id']}_{$loc['tier']}_{$loc['slot']}";
                            $reserved_slots[$key] = ($reserved_slots[$key] ?? 0) + (int)$loc['qty'];
                        }
                    }
                }
            }

            // 3. Lấy vị trí đã lưu nháp của biến thể hiện tại
            $currentAllocData = [];
            $sqlAlloc = "SELECT putaway_locations FROM ticket_import_temp WHERE ticket_id = $1 AND variant_id = $2";
            $resAlloc = @pg_query_params($this->conn, $sqlAlloc, [(int)$ticket_id, (int)$variant_id]);
            if ($resAlloc) {
                while ($rowAlloc = pg_fetch_assoc($resAlloc)) {
                    $locs = json_decode($rowAlloc['putaway_locations'], true);
                    if (is_array($locs)) {
                        foreach ($locs as $l) {
                            $key = "{$l['shelf_id']}_{$l['tier']}_{$l['slot']}";
                            $currentAllocData[$key] = (int)$l['qty'];
                        }
                    }
                }
            }

            $current = [];
            $available = [];

            // 4. Lặp qua các kệ để tính ô trống
            while ($shelf = pg_fetch_assoc($shelves)) {
                $layout = json_decode($shelf['layout'], true) ?: [];
                $max = (int)$shelf['max_capacity_per_slot'];
                $sName = $shelf['shelf_name'];
                $sId = $shelf['shelf_id'];

                foreach ($layout as $tier => $slots) {
                    foreach ($slots as $slotKey => $items) {
                        $occ = count($items);
                        $rKey = "{$sId}_{$tier}_{$slotKey}";
                        if (isset($reserved_slots[$rKey])) {
                            $occ += $reserved_slots[$rKey];
                        }

                        $varCount = 0;
                        foreach ($items as $item) {
                            if ((string)$item === (string)$variant_id) $varCount++;
                        }

                        $availableQty = max(0, $max - $occ);
                        $locationCode = "{$sName}_{$tier}-{$slotKey}";
                        $slotInfo = [
                            'shelf_id' => $sId,
                            'shelf_name' => $sName,
                            'tier' => $tier,
                            'slot' => $slotKey,
                            'slot_code' => $locationCode,
                            'location_code' => $locationCode,
                            'max' => $max,
                            'available' => $availableQty,
                            'remaining_capacity' => $availableQty,
                            'var_count' => $varCount
                        ];

                        if ($varCount > 0 && $availableQty > 0) {
                            $current[] = $slotInfo;
                        } else if ($availableQty > 0 && $varCount == 0) {
                            $available[] = $slotInfo;
                        }
                    }
                }
            }

            // 5. Lấy Tên Brand/Category của biến thể đang nhập
            $brandName = '';
            try {
                $sqlBrand = "SELECT c.category_name 
                             FROM product_variants pv 
                             JOIN products p ON pv.product_id = p.product_id 
                             JOIN categories c ON p.category_id = c.category_id 
                             WHERE pv.variant_id = $1";
                $resBrand = @pg_query_params($this->conn, $sqlBrand, [(int)$variant_id]);
                if ($resBrand && pg_num_rows($resBrand) > 0) {
                    $brandName = pg_fetch_result($resBrand, 0, 'category_name');
                }
            } catch (Exception $e) {
                $brandName = '';
            }

            // 6. Lấy top brand bán chạy
            $topBrands = $this->getTopSellingBrands();

            // 7. Chấm điểm Ranking Score cho từng ô và Sắp xếp
            $candidates = array_merge($current, $available);
            foreach ($candidates as &$slot) {
                $slot['score'] = $this->calculateSlotScore($slot, $variant_id, $brandName, $topBrands);
            }
            unset($slot);

            usort($candidates, function($a, $b) {
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            });

            // 8. Gợi ý phân bổ số lượng tự động (Autofill)
            $remaining = max(0, (int)$quantity);
            $recommended = [];

            foreach ($candidates as $slot) {
                if ($remaining <= 0) break;
                $avail = (int)$slot['available'];
                if ($avail > 0) {
                    $qtyToFill = min($remaining, $avail);
                    $key = "{$slot['shelf_id']}_{$slot['tier']}_{$slot['slot']}";
                    $recommended[$key] = [
                        'qty' => $qtyToFill,
                        'score' => $slot['score'] ?? 0
                    ];
                    $remaining -= $qtyToFill;
                }
            }

            return [
                'current' => $current,
                'available' => $available,
                'current_alloc' => $currentAllocData,
                'recommended' => $recommended,
                'shortage' => $remaining,
                'location_code' => $current[0]['location_code'] ?? null
            ];

        } catch (Exception $e) {
            return [
                'current' => [],
                'available' => [],
                'current_alloc' => [],
                'recommended' => [],
                'shortage' => (int)$quantity
            ];
        }
    }
    /**
 * Chức năng: Tính điểm ưu tiên cho 1 ô (Slot)
 * Tiêu chuẩn: Brand bán chạy ở Kệ A > Gom cùng Size/SKU > Sức chứa còn trống nhiều > Tầng vừa tầm tay (T2, T3)
 */
private function calculateSlotScore($slot, $variant_id, $brand_name, $topBrands = [])
{
    $score = 0;

    // 1. Tiêu chí 1: Tốc độ xuất kho của Brand -> Ưu tiên xếp vào Kệ A, B (Trọng số 40)
    $brandRank = array_search(strtolower($brand_name), array_map('strtolower', $topBrands));
    if ($brandRank === 0 && strtoupper($slot['shelf_name']) === 'KỆ A') {
        $score += 40; // Brand bán chạy nhất nằm ở Kệ A
    } else if ($brandRank === 1 && strtoupper($slot['shelf_name']) === 'KỆ B') {
        $score += 30; // Brand top 2 ở Kệ B
    } else if (strtoupper($slot['shelf_name']) === 'KỆ A') {
        $score += 15; // Mặc định Kệ A có điểm vị trí cao hơn
    }

    // 2. Tiêu chí 2: Độ gom hàng / Gom cùng mẫu (Trọng số 30)
    if ($slot['var_count'] > 0) {
        $score += 30; // Đã chứa sẵn mẫu này trong ô
    } else {
        $score += 10; // Ô trống hoàn toàn
    }

    // 3. Tiêu chí 3: Sức chứa còn trống (Trọng số 20)
    $capacityRatio = $slot['max'] > 0 ? ($slot['available'] / $slot['max']) : 0;
    $score += ($capacityRatio * 20);

    // 4. Tiêu chí 4: Ergonomics - Vị trí Tầng dễ lấy hàng (Trọng số 10)
    $tierUpper = strtoupper($slot['tier']);
    if (in_array($tierUpper, ['T2', 'T3'])) {
        $score += 10; // Tầm tay chuẩn
    } else if ($tierUpper === 'T1') {
        $score += 7;  // Khom lưng
    } else if ($tierUpper === 'T4') {
        $score += 5;  // Với cao
    }

    return $score;
}

/**
     * Chức năng: Lấy danh sách Top Thương hiệu/Danh mục xuất kho nhiều nhất 30 ngày qua
     */
    private function getTopSellingBrands()
    {
        $sql = "SELECT c.category_name, SUM(t.quantity) as total_qty
                FROM transactions t
                JOIN product_variants pv ON t.variant_id = pv.variant_id
                JOIN products p ON pv.product_id = p.product_id
                JOIN categories c ON p.category_id = c.category_id
                WHERE t.transaction_type = 'EXPORT' 
                  AND t.created_at >= CURRENT_TIMESTAMP - INTERVAL '30 days'
                GROUP BY c.category_name
                ORDER BY total_qty DESC
                LIMIT 5";

        $res = @pg_query($this->conn, $sql);
        $topBrands = [];
        if ($res) {
            while ($row = pg_fetch_assoc($res)) {
                $topBrands[] = $row['category_name'];
            }
        }
        return $topBrands;
    }
}
