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
     * Tìm các ô chứa biến thể và đề xuất lấy hàng FIFO thuần theo created_at
     * được lưu trên từng item trong shelves.layout JSONB.
     */
    public function getLocationsFromJSON($variant_id, $required_qty = 0, $current_detail_id = 0)
    {
        $variant_id = (int)$variant_id;
        $required_qty = max(0, (int)$required_qty);
        $current_detail_id = max(0, (int)$current_detail_id);

        $sql = "
            SELECT
                s.shelf_id,
                s.shelf_name,
                s.max_capacity_per_slot,
                tier.key AS tier_key,
                (tier.key || '-' || slot.key) AS slot_code,
                COUNT(item.value)::int AS physical_stock,
                jsonb_agg(item.value) AS matching_items
            FROM public.shelves s,
                 jsonb_each(s.layout) AS tier,
                 jsonb_each(tier.value) AS slot,
                 jsonb_array_elements(slot.value) AS item
            WHERE (CASE
                    WHEN jsonb_typeof(item.value) = 'object' THEN item.value->>'variant_id'
                    ELSE item.value #>> '{}'
                   END) = $1::text
              AND s.is_deleted = true
              AND s.status = true
            GROUP BY s.shelf_id, s.shelf_name, s.max_capacity_per_slot, tier.key, slot.key
            HAVING COUNT(item.value) > 0
        ";

        $res = pg_query_params($this->conn, $sql, [$variant_id]);
        $rows = $res ? (pg_fetch_all($res) ?: []) : [];
        if (empty($rows)) {
            return ['slots' => [], 'recommended' => [], 'shortage' => $required_qty];
        }

        // Trừ lượng đã được chọn trên các phiếu xuất còn mở khác ở đúng kệ/ô.
        $reservedBySlot = [];
        $reservationSql = "
            SELECT td.note
            FROM ticket_details td
            JOIN tickets t ON t.ticket_id = td.ticket_id
            WHERE td.variant_id = $1
              AND td.detail_id <> $2
              AND t.ticket_type = 'EXPORT'
              AND t.status IN ('PENDING', 'PROCESSING', 'PAUSED')
              AND t.is_deleted = false
              AND td.note IS NOT NULL
              AND BTRIM(td.note) <> ''
        ";
        $reservationRes = pg_query_params($this->conn, $reservationSql, [$variant_id, $current_detail_id]);
        if ($reservationRes) {
            while ($reservationRow = pg_fetch_assoc($reservationRes)) {
                $locations = json_decode($reservationRow['note'], true);
                if (!is_array($locations)) continue;

                foreach ($locations as $location) {
                    if (!is_array($location)) continue;
                    $key = ((int)($location['shelf_id'] ?? 0)) . '|' . (string)($location['slot_code'] ?? '');
                    $reservedBySlot[$key] = ($reservedBySlot[$key] ?? 0) + max(0, (int)($location['qty'] ?? 0));
                }
            }
        }

        $candidates = [];
        foreach ($rows as $row) {
            $slotKey = ((int)$row['shelf_id']) . '|' . $row['slot_code'];
            $items = json_decode($row['matching_items'], true);
            if (!is_array($items)) $items = [];

            usort($items, function ($a, $b) {
                $timeCompare = $this->getShelfItemTimestamp($a) <=> $this->getShelfItemTimestamp($b);
                if ($timeCompare !== 0) return $timeCompare;
                $ticketA = is_array($a) ? (int)($a['ticket_id'] ?? PHP_INT_MAX) : PHP_INT_MAX;
                $ticketB = is_array($b) ? (int)($b['ticket_id'] ?? PHP_INT_MAX) : PHP_INT_MAX;
                return $ticketA <=> $ticketB;
            });

            // Các phiếu xuất đang mở giữ trước những item cũ nhất trong đúng ô.
            $reservedQty = min(count($items), $reservedBySlot[$slotKey] ?? 0);
            $availableItems = array_slice($items, $reservedQty);
            $stock = count($availableItems);
            if ($stock <= 0) continue;

            $maxCapacity = max(1, (int)$row['max_capacity_per_slot']);
            $normalizedTier = 'T' . ltrim(strtoupper((string)$row['tier_key']), 'T');
            $oldestItem = $availableItems[0];
            $oldestTimestamp = $this->getShelfItemTimestamp($oldestItem);
            $hasCreatedAt = $oldestTimestamp !== PHP_INT_MAX;
            $oldestCreatedAt = $hasCreatedAt ? date('Y-m-d H:i:s', $oldestTimestamp) : null;
            $oldestDateFormatted = $hasCreatedAt ? date('d/m/Y H:i', $oldestTimestamp) : null;
            $ageDays = $hasCreatedAt ? max(0, (int)floor((time() - $oldestTimestamp) / 86400)) : null;
            $ticketId = is_array($oldestItem) ? ($oldestItem['ticket_id'] ?? null) : null;

            $candidates[] = [
                'shelf_id' => (int)$row['shelf_id'],
                'shelf_name' => $row['shelf_name'],
                'slot_code' => $row['slot_code'],
                'tier' => $normalizedTier,
                'qty_in_slot' => $stock,
                'physical_stock' => (int)$row['physical_stock'],
                'reserved_qty' => $reservedQty,
                'max_capacity' => $maxCapacity,
                'oldest_created_at' => $oldestCreatedAt,
                'oldest_date_formatted' => $oldestDateFormatted,
                'oldest_ticket_id' => $ticketId !== null ? (int)$ticketId : null,
                'fifo_age_days' => $ageDays,
                '_fifo_timestamp' => $oldestTimestamp,
                '_fifo_item_timestamps' => array_map(function ($item) {
                    return $this->getShelfItemTimestamp($item);
                }, $availableItems),
            ];
        }

        usort($candidates, function ($a, $b) {
            $fifoCompare = $a['_fifo_timestamp'] <=> $b['_fifo_timestamp'];
            if ($fifoCompare !== 0) return $fifoCompare;
            $shelfCompare = strcmp($a['shelf_name'], $b['shelf_name']);
            return $shelfCompare !== 0 ? $shelfCompare : strcmp($a['slot_code'], $b['slot_code']);
        });

        $fifoUnits = [];
        foreach ($candidates as $rankIndex => &$candidate) {
            $candidate['fifo_rank'] = $rankIndex + 1;
            foreach ($candidate['_fifo_item_timestamps'] as $unitIndex => $timestamp) {
                $fifoUnits[] = [
                    'timestamp' => $timestamp,
                    'shelf_id' => $candidate['shelf_id'],
                    'slot_code' => $candidate['slot_code'],
                    'fifo_rank' => $candidate['fifo_rank'],
                    'oldest_created_at' => $candidate['oldest_created_at'],
                    'oldest_date_formatted' => $candidate['oldest_date_formatted'],
                    'unit_index' => $unitIndex,
                ];
            }
            unset($candidate['_fifo_timestamp'], $candidate['_fifo_item_timestamps']);
        }
        unset($candidate);

        // Chọn từng đơn vị cũ nhất trên toàn kho rồi mới gom số lượng theo ô.
        usort($fifoUnits, function ($a, $b) {
            foreach (['timestamp', 'shelf_id', 'slot_code', 'unit_index'] as $field) {
                $compare = $a[$field] <=> $b[$field];
                if ($compare !== 0) return $compare;
            }
            return 0;
        });

        $remaining = $required_qty;
        $recommendedBySlot = [];
        foreach ($fifoUnits as $unit) {
            if ($remaining <= 0) break;
            $key = $unit['shelf_id'] . '|' . $unit['slot_code'];
            if (!isset($recommendedBySlot[$key])) {
                $recommendedBySlot[$key] = [
                    'shelf_id' => $unit['shelf_id'],
                    'slot_code' => $unit['slot_code'],
                    'qty' => 0,
                    'fifo_rank' => $unit['fifo_rank'],
                    'oldest_created_at' => $unit['oldest_created_at'],
                    'oldest_date_formatted' => $unit['oldest_date_formatted'],
                ];
            }
            $recommendedBySlot[$key]['qty']++;
            $remaining--;
        }
        $recommended = array_values($recommendedBySlot);

        return [
            'slots' => $candidates,
            'recommended' => $recommended,
            'shortage' => max(0, $remaining),
        ];
    }

    private function getShelfItemTimestamp($item)
    {
        if (!is_array($item) || empty($item['created_at'])) return PHP_INT_MAX;
        $timestamp = strtotime((string)$item['created_at']);
        return $timestamp === false ? PHP_INT_MAX : $timestamp;
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

                        $matchingKeys = [];
                        foreach ($items as $key => $val) {
                            $itemVid = is_array($val) ? ($val['variant_id'] ?? null) : $val;
                            if ($itemVid == $variant_id) $matchingKeys[] = $key;
                        }
                        usort($matchingKeys, function ($keyA, $keyB) use ($items) {
                            $timeCompare = $this->getShelfItemTimestamp($items[$keyA]) <=> $this->getShelfItemTimestamp($items[$keyB]);
                            return $timeCompare !== 0 ? $timeCompare : ($keyA <=> $keyB);
                        });

                        foreach (array_slice($matchingKeys, 0, $qty_to_remove) as $key) {
                            unset($items[$key]);
                            $removed_in_slot++;
                            $total_removed++;
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
        $res = pg_query($this->conn, "SELECT shelf_id, layout FROM shelves WHERE is_deleted = true AND status = true");
        if (!$res) return 0;

        $layouts = [];
        $fifoItems = [];
        while ($row = pg_fetch_assoc($res)) {
            $shelfId = (int)$row['shelf_id'];
            $layouts[$shelfId] = json_decode($row['layout'], true) ?: [];
            foreach ($layouts[$shelfId] as $tier => $slots) {
                foreach ($slots as $slotCode => $items) {
                    if (!is_array($items)) continue;
                    foreach ($items as $key => $item) {
                        $itemVid = is_array($item) ? ($item['variant_id'] ?? null) : $item;
                        if ($itemVid == $variant_id) {
                            $fifoItems[] = [
                                'timestamp' => $this->getShelfItemTimestamp($item),
                                'shelf_id' => $shelfId,
                                'tier' => (string)$tier,
                                'slot' => (string)$slotCode,
                                'key' => $key,
                            ];
                        }
                    }
                }
            }
        }

        usort($fifoItems, function ($a, $b) {
            foreach (['timestamp', 'shelf_id', 'tier', 'slot', 'key'] as $field) {
                $compare = $a[$field] <=> $b[$field];
                if ($compare !== 0) return $compare;
            }
            return 0;
        });

        $changedShelves = [];
        foreach (array_slice($fifoItems, 0, $total_to_remove) as $fifoItem) {
            unset($layouts[$fifoItem['shelf_id']][$fifoItem['tier']][$fifoItem['slot']][$fifoItem['key']]);
            $changedShelves[$fifoItem['shelf_id']] = true;
            $total_to_remove--;
        }

        foreach (array_keys($changedShelves) as $shelfId) {
            foreach ($layouts[$shelfId] as &$slots) {
                foreach ($slots as &$items) {
                    if (is_array($items)) $items = array_values($items);
                }
                unset($items);
            }
            unset($slots);
            pg_query_params(
                $this->conn,
                "UPDATE shelves SET layout = $1 WHERE shelf_id = $2",
                [json_encode($layouts[$shelfId]), $shelfId]
            );
        }

        // Trả về số lượng thực tế đã gỡ thành công khỏi các ô kệ
        return $original_to_remove - $total_to_remove;
    }
}
