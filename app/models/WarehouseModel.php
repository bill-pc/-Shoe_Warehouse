<?php
// app/models/WarehouseModel.php

class WarehouseModel
{
    private $conn;

    public function __construct()
    {
        $this->conn = getConnection();
    }

    // 1. Lấy toàn bộ dữ liệu kệ thô
    public function getAllShelves()
    {
        $res = pg_query($this->conn, "SELECT * FROM shelves WHERE is_deleted = true ORDER BY shelf_name ASC");
        return $res ? pg_fetch_all($res) : [];
    }

    // 2. Lấy Map ánh xạ: [variant_id => tên_hãng]
    public function getVariantBrandMap()
    {
        $sql = "SELECT v.variant_id, c.category_name 
                FROM product_variants v 
                JOIN products p ON v.product_id = p.product_id 
                JOIN categories c ON p.category_id = c.category_id";
        $res = pg_query($this->conn, $sql);
        $map = [];
        if ($res) {
            while ($r = pg_fetch_assoc($res)) {
                $map[$r['variant_id']] = $r['category_name'];
            }
        }
        return $map;
    }

    // 3. Tìm Variant thô cho AJAX Search trên Sơ đồ
    public function searchVariantsRaw($keyword)
    {
        $sql = "SELECT v.variant_id, p.product_name, p.product_image, v.size, v.color, c.category_name as brand
                FROM product_variants v JOIN products p ON v.product_id = p.product_id
                JOIN categories c ON p.category_id = c.category_id
                WHERE (p.product_name ILIKE $1 OR c.category_name ILIKE $1) AND v.is_deleted = false";
        $res = pg_query_params($this->conn, $sql, ["%$keyword%"]);
        return $res ? pg_fetch_all($res) : [];
    }


    //Lấy Dictionary chi tiết của tất cả Variant (Dùng cho Popover Sơ đồ kho)
    public function getVariantDict()
    {
        // BỔ SUNG: Đã thêm p.product_id và p.category_id vào SELECT
        $sql = "SELECT v.variant_id, p.product_id, p.category_id, p.product_name, p.product_image, v.size, v.color 
                FROM product_variants v 
                JOIN products p ON v.product_id = p.product_id";

        $res = pg_query($this->conn, $sql);
        $dict = [];
        if ($res) {
            while ($row = pg_fetch_assoc($res)) {
                $dict[$row['variant_id']] = $row;
            }
        }
        return $dict;
    }


    // Chức năng: Thêm kệ mới vào DB, tự sinh cấu trúc layout mảng JSON trống theo số tầng và ô bạn đã kéo lưới
    public function addShelf($shelf_name, $max_capacity, $tiers, $slots)
    {
        $layout = [];
        for ($t = $tiers; $t >= 1; $t--) {
            $layout[(string)$t] = [];
            for ($s = 1; $s <= $slots; $s++) {
                $slotKey = str_pad($s, 2, '0', STR_PAD_LEFT);
                $layout[(string)$t][$slotKey] = [];
            }
        }

        // Bổ sung: Gắn cứng is_deleted = true và status = true khi tạo mới
        $sql = "INSERT INTO shelves (shelf_name, total_tiers, slots_per_tier, max_capacity_per_slot, layout, is_deleted, status) 
                VALUES ($1, $2, $3, $4, $5, true, true)";

        return pg_query_params($this->conn, $sql, [
            trim($shelf_name),
            (int)$tiers,
            (int)$slots,
            (int)$max_capacity,
            json_encode($layout)
        ]);
    }




    // Chức năng: Kiểm tra xem toàn bộ các ô trong kệ có đang trống hoàn toàn hay không
    public function isShelfEmpty($shelf_id)
    {
        $sql = "SELECT layout FROM shelves WHERE shelf_id = $1";
        $res = pg_query_params($this->conn, $sql, [$shelf_id]);
        if ($row = pg_fetch_assoc($res)) {
            $layout = json_decode($row['layout'], true);
            foreach ($layout as $tier => $slots) {
                foreach ($slots as $slot => $items) {
                    if (count($items) > 0) return false; // Có chứa giày, không trống
                }
            }
            return true; // Trống hoàn toàn
        }
        return false;
    }


    // Chức năng: Xóa mềm kệ (Đổi is_deleted thành false)
    public function softDeleteShelf($shelf_id)
    {
        $sql = "UPDATE shelves SET is_deleted = false WHERE shelf_id = $1";
        return pg_query_params($this->conn, $sql, [$shelf_id]);
    }


    // Chức năng: Bật/Tắt trạng thái hoạt động của kệ (status lật ngược bằng NOT status)
    public function toggleStatusShelf($shelf_id)
    {
        $sql = "UPDATE shelves SET status = NOT status WHERE shelf_id = $1";
        return pg_query_params($this->conn, $sql, [$shelf_id]);
    }




    // Đổi tên kệ hàng
    public function renameShelf($shelf_id, $new_name)
    {
        $sql = "UPDATE shelves SET shelf_name = $1 WHERE shelf_id = $2";
        return pg_query_params($this->conn, $sql, [trim($new_name), $shelf_id]);
    }


    // Chức năng: Helper tìm ID kệ và trạng thái thông qua tên kệ
    public function getShelfIdByName($shelf_name)
    {
        // SỬA CHỮ FALSE THÀNH TRUE Ở CUỐI CÂU DƯỚI ĐÂY
        $sql = "SELECT shelf_id, status FROM shelves WHERE shelf_name = $1 AND is_deleted = true";

        $res = pg_query_params($this->conn, $sql, [$shelf_name]);
        return $res ? pg_fetch_assoc($res) : null;
    }


    // =========================================================================
    // CHỨC NĂNG: ĐIỀU CHUYỂN NỘI BỘ 
    // =========================================================================

    /**
     * Lấy danh sách vị trí kệ của tất cả biến thể thuộc 1 sản phẩm
     */
    /**
     * Quét toàn bộ kho và trả về Map vị trí của tất cả biến thể
     * Định dạng: [variant_id => [['loc' => 'A1-01', 'qty' => 2], ...]]
     */
    public function getAllShelvesLocationsMap()
    {
        $sql = "SELECT shelf_name, layout FROM shelves";
        $shelves = pg_fetch_all(pg_query($this->conn, $sql)) ?: [];

        $locationMap = [];

        foreach ($shelves as $shelf) {
            $shelfName = $shelf['shelf_name'];
            $layoutStr = $shelf['layout'];

            // Xử lý an toàn 100% chuỗi JSONB từ PostgreSQL (Chống lỗi double-encode)
            $layout = is_string($layoutStr) ? json_decode($layoutStr, true) : $layoutStr;
            if (is_string($layout)) $layout = json_decode($layout, true);

            if (!is_array($layout)) continue;

            foreach ($layout as $tier => $slots) {
                if (!is_array($slots)) continue;
                foreach ($slots as $slotKey => $shoesArray) {
                    if (!is_array($shoesArray) || empty($shoesArray)) continue;

                    // Đếm số lượng từng loại giày trong ô
                    $counts = array_count_values($shoesArray);
                    foreach ($counts as $vid => $qty) {
                        if (!isset($locationMap[$vid])) $locationMap[$vid] = [];
                        $locationMap[$vid][] = [
                            'loc' => "{$shelfName}_{$tier}-{$slotKey}",
                            'qty' => $qty,
                            'str' => "{$shelfName}_{$tier}-{$slotKey} (<b>{$qty}</b>)"
                        ];
                    }
                }
            }
        }
        return $locationMap;
    }

    /**
     * Xử lý Di chuyển hàng hóa (Nhiều đích đến, tự động chia nhỏ)
     * Kèm theo logic chặn kệ đã Tạm ngưng.
     */
    public function movePutawayLocationsMulti($variant_id, $from_loc, $destinations)
    {
        // 1. Phân tích tọa độ nguồn
        preg_match('/^(.+)_(\d+)-(\d{2})$/', $from_loc, $f_match);
        if (count($f_match) != 4) throw new Exception("Tọa độ nguồn không hợp lệ.");

        $f_shelf = $f_match[1];
        $f_tier = $f_match[2];
        $f_slot = $f_match[3];

        // 2. Tính tổng số lượng cần chuyển đi
        $totalQty = 0;
        foreach ($destinations as $dest) {
            $totalQty += intval($dest['qty']);
        }
        if ($totalQty <= 0) throw new Exception("Số lượng chuyển không hợp lệ.");

        // 3. Thu thập danh sách các kệ liên quan (để tối ưu câu query)
        $involvedShelfNames = [$f_shelf];
        foreach ($destinations as $dest) {
            preg_match('/^(.+)_(\d+)-(\d{2})$/', $dest['loc'], $t_match);
            if (count($t_match) != 4) throw new Exception("Tọa độ đích không hợp lệ: " . $dest['loc']);
            $t_shelf = $t_match[1];
            if (!in_array($t_shelf, $involvedShelfNames)) $involvedShelfNames[] = $t_shelf;
        }

        // 4. KIỂM TRA TRẠNG THÁI KỆ (Chặn kệ Tạm Ngưng/Đã Xóa)
        $placeholders = [];
        for ($i = 1; $i <= count($involvedShelfNames); $i++) $placeholders[] = "$" . $i;
        $inClause = implode(',', $placeholders);
        $sql = "SELECT shelf_name, layout, max_capacity_per_slot, status, is_deleted FROM shelves WHERE shelf_name IN ($inClause)";
        $res = pg_query_params($this->conn, $sql, $involvedShelfNames);
        $shelvesData = pg_fetch_all($res) ?: [];

        $shelves = [];
        $maxCaps = [];

        foreach ($shelvesData as $s) {
            // Quy tắc kinh doanh: Kệ phải có is_deleted = true VÀ status = true mới là đang hoạt động
            if ($s['status'] !== 't' || $s['is_deleted'] !== 't') {
                throw new Exception("Kệ {$s['shelf_name']} đang bị Tạm Ngưng hoặc đã Xóa. Không thể điều chuyển hàng!");
            }
            $shelves[$s['shelf_name']] = json_decode($s['layout'], true) ?: [];
            $maxCaps[$s['shelf_name']] = (int)$s['max_capacity_per_slot'];
        }

        // 5. Kiểm tra Nguồn có đủ giày không?
        if (!isset($shelves[$f_shelf][$f_tier][$f_slot])) $shelves[$f_shelf][$f_tier][$f_slot] = [];
        $sourceArr = &$shelves[$f_shelf][$f_tier][$f_slot];

        $sourceCounts = array_count_values($sourceArr);
        $availableQty = $sourceCounts[$variant_id] ?? 0;

        if ($availableQty < $totalQty) {
            throw new Exception("Ô nguồn $from_loc chỉ có $availableQty đôi, không đủ $totalQty đôi để chuyển.");
        }

        // 6. KIỂM TRA TẤT CẢ CÁC ĐÍCH CÓ BỊ QUÁ TẢI KHÔNG (Bỏ logic Swap cũ)
        foreach ($destinations as $dest) {
            preg_match('/^(.+)_(\d+)-(\d{2})$/', $dest['loc'], $t_match);
            $t_shelf = $t_match[1];
            $t_tier = $t_match[2];
            $t_slot = $t_match[3];
            $qtyToMove = intval($dest['qty']);

            if (!isset($shelves[$t_shelf][$t_tier][$t_slot])) $shelves[$t_shelf][$t_tier][$t_slot] = [];
            $destArr = $shelves[$t_shelf][$t_tier][$t_slot];

            $destOccupancy = count($destArr);
            $freeSpace = $maxCaps[$t_shelf] - $destOccupancy;

            // Nếu click chọn lại chính ô nguồn làm đích (ít xảy ra nhưng phòng hờ)
            if ($from_loc === $dest['loc']) $freeSpace += $qtyToMove;

            if ($freeSpace < $qtyToMove) {
                throw new Exception("Ô đích {$dest['loc']} chỉ còn chỗ cho $freeSpace đôi (cần $qtyToMove). Giao dịch bị hủy!");
            }
        }

        // 7. THỰC THI: Rút giày khỏi ô nguồn
        $removed = 0;
        foreach ($sourceArr as $idx => $vid) {
            if ($vid == $variant_id && $removed < $totalQty) {
                unset($sourceArr[$idx]);
                $removed++;
            }
        }
        $sourceArr = array_values($sourceArr); // Re-index mảng liên tục

        // 8. THỰC THI: Bơm giày vào các ô đích
        foreach ($destinations as $dest) {
            preg_match('/^(.+)_(\d+)-(\d{2})$/', $dest['loc'], $t_match);
            $t_shelf = $t_match[1];
            $t_tier = $t_match[2];
            $t_slot = $t_match[3];
            $qtyToMove = intval($dest['qty']);

            $destArrRef = &$shelves[$t_shelf][$t_tier][$t_slot];
            for ($i = 0; $i < $qtyToMove; $i++) {
                $destArrRef[] = (int)$variant_id;
            }
        }

        // 9. LƯU TOÀN BỘ VÀO DB
        foreach ($involvedShelfNames as $shelfName) {
            $jsonb = json_encode($shelves[$shelfName]);
            pg_query_params($this->conn, "UPDATE shelves SET layout = $1::jsonb WHERE shelf_name = $2", [$jsonb, $shelfName]);
        }

        return true;
    }
}
