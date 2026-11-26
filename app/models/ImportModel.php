
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
     * Chức năng Helper: Nhét thông tin biến thể (variant_id, ticket_id, created_at) vào mảng JSON của kệ tương ứng.
     */
    private function addItemsToShelves($locations, $ticket_id = null, $created_at = null)
    {
        $savedAt = $created_at ?: date('Y-m-d H:i:s');
        $ticketIdVal = $ticket_id !== null ? (int)$ticket_id : null;

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
                    $vid = (int)$loc['variant_id'];

                    if (!isset($layout[$tier][$slot])) $layout[$tier][$slot] = [];

                    for ($i = 0; $i < $qty; $i++) {
                        $layout[$tier][$slot][] = [
                            'variant_id' => $vid,
                            'ticket_id'  => $ticketIdVal,
                            'created_at' => $savedAt,
                        ];
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

                    // Thực tế nhận 0 đôi (NCC giao thiếu toàn bộ / giao nhầm) -> không cộng kho, không ghi lịch sử nhập 0 đôi
                    if ($qty <= 0) {
                        continue;
                    }

                    // Cập nhật tồn kho bảng product_variants bằng SỐ LƯỢNG THỰC TẾ
                    pg_query_params($this->conn, "UPDATE product_variants SET stock = stock + $1 WHERE variant_id = $2", [$qty, $v_id]);

                    // 🚀 Thay thế "TICKET-$ticket_id" thành $ticket_code
                    pg_query_params($this->conn, "INSERT INTO transactions (transaction_type, variant_id, quantity, user_id, reference_id) VALUES ('IMPORT', $1, $2, $3, $4)", [$v_id, $qty, $user_id, $ticket_code]);

                    // Đẩy giày vào các ô kệ đã chọn (kèm ticket_id và ngày lưu created_at)
                    if (count($locations) > 0) {
                        $this->addItemsToShelves($locations, $ticket_id, date('Y-m-d H:i:s'));
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
            $shelves = pg_query(
                $this->conn,
                "SELECT shelf_id, shelf_name, layout, max_capacity_per_slot
                 FROM shelves
                 WHERE is_deleted = true AND status = true
                 ORDER BY shelf_name ASC, shelf_id ASC"
            );
            if (!$shelves) {
                return ['current' => [], 'available' => [], 'slots' => [], 'recommended' => [], 'current_alloc' => [], 'shortage' => max(0, (int)$quantity)];
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
            $shelfVelocityScores = $this->getShelfVelocityScores();
            $defaultVelocityScore = (float)($shelfVelocityScores['__default'] ?? 50.0);

            $siblingVariantIds = [];
            $resSiblings = @pg_query_params(
                $this->conn,
                "SELECT sibling.variant_id
                 FROM product_variants target
                 JOIN product_variants sibling ON sibling.product_id = target.product_id
                 WHERE target.variant_id = $1 AND sibling.variant_id <> target.variant_id",
                [(int)$variant_id]
            );
            if ($resSiblings) {
                while ($rowSibling = pg_fetch_assoc($resSiblings)) {
                    $siblingVariantIds[(string)$rowSibling['variant_id']] = true;
                }
            }

            // 4. Lặp qua các kệ để tính ô trống
            while ($shelf = pg_fetch_assoc($shelves)) {
                $layout = json_decode($shelf['layout'], true) ?: [];
                $max = (int)$shelf['max_capacity_per_slot'];
                $sName = $shelf['shelf_name'];
                $sId = $shelf['shelf_id'];

                $shelfHasSameProduct = false;
                foreach ($layout as $shelfSlots) {
                    foreach ($shelfSlots as $shelfItems) {
                        foreach ($shelfItems as $shelfItem) {
                            $itemVid = is_array($shelfItem) ? (string)($shelfItem['variant_id'] ?? '') : (string)$shelfItem;
                            if ($itemVid !== '' && isset($siblingVariantIds[$itemVid])) {
                                $shelfHasSameProduct = true;
                                break 3;
                            }
                        }
                    }
                }

                foreach ($layout as $tier => $slots) {
                    foreach ($slots as $slotKey => $items) {
                        $currentQty = count($items);
                        $rKey = "{$sId}_{$tier}_{$slotKey}";
                        $reservedQty = (int)($reserved_slots[$rKey] ?? 0);

                        $varCount = 0;
                        foreach ($items as $item) {
                            $itemVid = is_array($item) ? (string)($item['variant_id'] ?? '') : (string)$item;
                            if ($itemVid === (string)$variant_id) $varCount++;
                        }

                        $availableQty = max(0, $max - $currentQty - $reservedQty);
                        if ($availableQty <= 0) continue;
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
                            'available_space' => $availableQty,
                            'remaining_capacity' => $availableQty,
                            'current_qty' => $currentQty,
                            'reserved_qty' => $reservedQty,
                            'var_count' => $varCount,
                            'has_same_product_on_shelf' => $shelfHasSameProduct,
                            'velocity_score' => (float)($shelfVelocityScores[(string)$sId] ?? $defaultVelocityScore),
                            'velocity_is_default' => !array_key_exists((string)$sId, $shelfVelocityScores)
                                && $defaultVelocityScore === 50.0
                        ];

                        if ($varCount > 0) {
                            $current[] = $slotInfo;
                        } else {
                            $available[] = $slotInfo;
                        }
                    }
                }
            }

            // 7. Chấm điểm Ranking Score cho từng ô và Sắp xếp
            $candidates = array_merge($current, $available);
            foreach ($candidates as &$slot) {
                $breakdown = $this->calculateSlotScore($slot);
                $slot['score'] = $breakdown['total'];
                $slot['score_breakdown'] = $breakdown;
                $slot['is_current'] = (int)$slot['var_count'] > 0;
            }
            unset($slot);

            usort($candidates, function($a, $b) {
                $scoreCompare = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
                if ($scoreCompare !== 0) return $scoreCompare;

                $shelfCompare = strnatcasecmp((string)$a['shelf_name'], (string)$b['shelf_name']);
                if ($shelfCompare !== 0) return $shelfCompare;

                $tierCompare = strnatcasecmp((string)$a['tier'], (string)$b['tier']);
                if ($tierCompare !== 0) return $tierCompare;

                return strnatcasecmp((string)$a['slot'], (string)$b['slot']);
            });

            foreach ($candidates as $index => &$slot) {
                $slot['rank'] = $index + 1;
                $slot['score_breakdown']['rank'] = $index + 1;
            }
            unset($slot);

            $current = array_values(array_filter($candidates, fn($slot) => (int)$slot['var_count'] > 0));
            $available = array_values(array_filter($candidates, fn($slot) => (int)$slot['var_count'] === 0));

            // 8. Gợi ý phân bổ số lượng tự động (Autofill)
            $remaining = max(0, (int)$quantity);
            $recommended = [];

            foreach ($candidates as $slot) {
                if ($remaining <= 0) break;
                $avail = (int)$slot['available_space'];
                if ($avail > 0) {
                    $qtyToFill = min($remaining, $avail);
                    $recommended[] = [
                        'key' => "{$slot['shelf_id']}_{$slot['tier']}_{$slot['slot']}",
                        'shelf_id' => $slot['shelf_id'],
                        'shelf_name' => $slot['shelf_name'],
                        'tier' => $slot['tier'],
                        'slot' => $slot['slot'],
                        'qty' => $qtyToFill,
                        'allocated' => $qtyToFill,
                        'score' => $slot['score'] ?? 0
                    ];
                    $remaining -= $qtyToFill;
                }
            }

            return [
                'current' => $current,
                'available' => $available,
                'slots' => $candidates,
                'current_alloc' => $currentAllocData,
                'recommended' => $recommended,
                'shortage' => $remaining,
                'location_code' => $candidates[0]['location_code'] ?? null
            ];

        } catch (Exception $e) {
            return [
                'current' => [],
                'available' => [],
                'slots' => [],
                'current_alloc' => [],
                'recommended' => [],
                'shortage' => (int)$quantity
            ];
        }
    }
    /**
 * Tính điểm động cho một ô theo affinity, velocity, capacity và ergonomics.
 */
private function calculateSlotScore($slot)
{
    if ((int)$slot['var_count'] > 0) {
        $affinity = 100.0;
        $affinityReason = 'Ô đang chứa đúng biến thể này';
    } elseif (!empty($slot['has_same_product_on_shelf'])) {
        $affinity = 60.0;
        $affinityReason = 'Cùng kệ đang có biến thể khác của sản phẩm';
    } else {
        $affinity = 30.0;
        $affinityReason = 'Ô mới hoặc chưa có sản phẩm liên quan';
    }

    $velocity = max(0.0, min(100.0, (float)($slot['velocity_score'] ?? 50.0)));
    if (!empty($slot['velocity_is_default'])) {
        $velocityReason = 'Chưa có lịch sử xuất, dùng điểm mặc định 50/100';
    } elseif ($velocity >= 100) {
        $velocityReason = 'Kệ có lượng xuất cao nhất trong 30 ngày';
    } else {
        $velocityReason = "Điểm tốc độ xuất 30 ngày: " . round($velocity, 2) . '/100';
    }
    $capacity = (int)$slot['max'] > 0
        ? ((int)$slot['available_space'] / (int)$slot['max']) * 100.0
        : 0.0;
    $capacityReason = "Trống {$slot['available_space']}/{$slot['max']} đôi";

    $normalizedTier = 'T' . ltrim(strtoupper(trim((string)$slot['tier'])), 'T');
    if (in_array($normalizedTier, ['T2', 'T3'], true)) {
        $ergonomics = 100.0;
        $ergonomicsReason = 'Tầng vừa tầm tay, thao tác thuận tiện';
    } elseif ($normalizedTier === 'T1') {
        $ergonomics = 70.0;
        $ergonomicsReason = 'Tầng thấp, cần khom người';
    } elseif ($normalizedTier === 'T4') {
        $ergonomics = 50.0;
        $ergonomicsReason = 'Tầng cao, cần với tay';
    } else {
        $ergonomics = 40.0;
        $ergonomicsReason = 'Tầng ngoài vùng thao tác tiêu chuẩn';
    }

    $weighted = [
        'affinity' => round(0.35 * $affinity, 2),
        'velocity' => round(0.35 * $velocity, 2),
        'capacity' => round(0.20 * $capacity, 2),
        'ergonomics' => round(0.10 * $ergonomics, 2)
    ];

    $topComponent = array_search(max($weighted), $weighted, true);
    $reasonMap = [
        'affinity' => $affinity >= 100 ? 'Gom cùng biến thể trong một ô' : 'Gom cùng sản phẩm trên một kệ',
        'velocity' => $velocity >= 100 ? 'Kệ xuất nhanh nhất 30 ngày' : 'Kệ có tần suất xuất tốt',
        'capacity' => 'Ô còn nhiều sức chứa',
        'ergonomics' => 'Tầng dễ thao tác'
    ];

    return [
        'total' => round(array_sum($weighted), 2),
        'top_reason' => $reasonMap[$topComponent] ?? 'Cân bằng các tiêu chí lưu kho',
        'affinity' => ['label' => 'Độ gom hàng', 'raw' => round($affinity, 2), 'weight' => 35, 'weighted' => $weighted['affinity'], 'reason' => $affinityReason],
        'velocity' => ['label' => 'Tần suất xuất kệ', 'raw' => round($velocity, 2), 'weight' => 35, 'weighted' => $weighted['velocity'], 'reason' => $velocityReason],
        'capacity' => ['label' => 'Sức chứa trống', 'raw' => round($capacity, 2), 'weight' => 20, 'weighted' => $weighted['capacity'], 'reason' => $capacityReason],
        'ergonomics' => ['label' => 'Tầm với tầng', 'raw' => round($ergonomics, 2), 'weight' => 10, 'weighted' => $weighted['ergonomics'], 'reason' => $ergonomicsReason]
    ];
}

/**
     * Tính velocity theo lượng xuất thực tế tại từng kệ trong 30 ngày gần nhất.
     * Bảng transactions không có shelf_id nên vị trí được đọc từ ticket_details.note.
     */
    private function getShelfVelocityScores()
    {
        $sql = "SELECT td.detail_id, td.note
                FROM ticket_details td
                JOIN tickets tk ON tk.ticket_id = td.ticket_id
                WHERE tk.ticket_type = 'EXPORT'
                  AND EXISTS (
                      SELECT 1
                      FROM transactions tr
                      WHERE tr.reference_id = tk.ticket_code
                        AND tr.variant_id = td.variant_id
                        AND tr.transaction_type = 'EXPORT'
                        AND tr.created_at >= CURRENT_TIMESTAMP - INTERVAL '30 days'
                  )
                  AND td.note IS NOT NULL
                  AND BTRIM(td.note) <> ''";

        $res = @pg_query($this->conn, $sql);
        $exportedByShelf = [];
        if ($res) {
            while ($row = pg_fetch_assoc($res)) {
                $locations = json_decode($row['note'], true);
                if (!is_array($locations)) continue;

                foreach ($locations as $location) {
                    $shelfId = $location['shelf_id'] ?? null;
                    $qty = max(0, (int)($location['qty'] ?? 0));
                    if (!is_numeric($shelfId) || (int)$shelfId <= 0 || $qty <= 0) continue;

                    $key = (string)(int)$shelfId;
                    $exportedByShelf[$key] = ($exportedByShelf[$key] ?? 0) + $qty;
                }
            }
        }

        $maxExported = empty($exportedByShelf) ? 0 : max($exportedByShelf);
        if ($maxExported <= 0) return ['__default' => 50.0];

        $scores = ['__default' => 0.0];
        foreach ($exportedByShelf as $shelfId => $exportedQty) {
            $scores[$shelfId] = round(($exportedQty / $maxExported) * 100.0, 2);
        }
        return $scores;
    }
}
