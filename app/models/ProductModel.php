<?php
// app/models/ProductModel.php

class ProductModel
{
    private $conn;

    /**
     * Khởi tạo kết nối CSDL toàn cục
     */
    public function __construct()
    {
        $this->conn = getConnection();
    }

    public function getConnection()
    {
        return $this->conn;
    }
    /**
     * Lấy danh sách sản phẩm theo ID Hãng (Loại trừ sản phẩm đã xóa)
     */
    public function getByCategory($category_id)
    {
        $sql = "SELECT product_id, category_id, product_name, product_image, status, is_deleted 
                FROM products 
                WHERE category_id = $1 AND is_deleted = false 
                ORDER BY product_id DESC";
        $result = pg_query_params($this->conn, $sql, [$category_id]);
        return $result ? pg_fetch_all($result) : [];
    }

    /**
     * Lấy tên hãng để hiển thị tiêu đề UI
     */
    public function getCategoryName($category_id)
    {
        $sql = "SELECT category_name FROM categories WHERE category_id = $1";
        $result = pg_query_params($this->conn, $sql, [$category_id]);
        $row = pg_fetch_assoc($result);
        return $row ? $row['category_name'] : 'Sản phẩm';
    }


    /**
     * Kiểm tra sản phẩm có chứa biến thể hoạt động nào không
     */
    public function hasVariants($product_id)
    {
        $sql = "SELECT COUNT(*) as cnt FROM product_variants WHERE product_id = $1 AND is_deleted = false";
        $result = pg_query_params($this->conn, $sql, [(int)$product_id]);
        if ($result) {
            $row = pg_fetch_assoc($result);
            return (int)$row['cnt'] > 0;
        }
        return false;
    }


    /**
     * Xóa mềm sản phẩm và cascade xóa mềm toàn bộ biến thể bên trong, đồng thời giải phóng vị trí kệ kho
     */
    public function delete($product_id)
    {
        pg_query($this->conn, "BEGIN");
        try {
            // 1. Lấy danh sách các variant_id thuộc sản phẩm này để dọn kệ kho
            $sqlGetVariants = "SELECT variant_id FROM product_variants WHERE product_id = $1 AND is_deleted = false";
            $resVariants = pg_query_params($this->conn, $sqlGetVariants, [(int)$product_id]);

            if ($resVariants) {
                while ($row = pg_fetch_assoc($resVariants)) {
                    $variant_id = (int)$row['variant_id'];
                    // Tự động dọn dẹp vị trí trên các kệ kho của biến thể này
                    $this->removePutawayFromShelves($variant_id, null);
                }
            }

            // 2. Xóa mềm toàn bộ biến thể con thuộc sản phẩm mẹ này (đặt status = false)
            $sqlDeleteVariants = "UPDATE product_variants SET is_deleted = true, status = false WHERE product_id = $1";
            pg_query_params($this->conn, $sqlDeleteVariants, [(int)$product_id]);

            // 3. Xóa mềm chính sản phẩm mẹ (đặt status = false)
            $sqlDeleteProduct = "UPDATE products SET is_deleted = true, status = false WHERE product_id = $1";
            pg_query_params($this->conn, $sqlDeleteProduct, [(int)$product_id]);

            pg_query($this->conn, "COMMIT");
            return true;
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            return false;
        }
    }


    /**
     * Cập nhật trạng thái kinh doanh của 1 sản phẩm và cascade đến các biến thể con
     */
    public function updateStatus($product_id, $new_status)
    {
        pg_query($this->conn, "BEGIN");
        try {
            // 1. Cập nhật trạng thái toàn bộ biến thể con thuộc sản phẩm này
            $sqlVariants = "UPDATE product_variants SET status = $1 WHERE product_id = $2";
            pg_query_params($this->conn, $sqlVariants, [$new_status, $product_id]);

            // 2. Cập nhật trạng thái sản phẩm mẹ
            $sqlProduct = "UPDATE products SET status = $1 WHERE product_id = $2";
            pg_query_params($this->conn, $sqlProduct, [$new_status, $product_id]);

            pg_query($this->conn, "COMMIT");
            return true;
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            return false;
        }
    }

    /**
     * Cập nhật trạng thái hàng loạt theo Hãng (Cascade đến toàn bộ sản phẩm và biến thể)
     */
    public function updateStatusByCategory($category_id, $new_status)
    {
        pg_query($this->conn, "BEGIN");
        try {
            // 1. Cập nhật trạng thái toàn bộ biến thể thuộc các sản phẩm của hãng này
            $sqlVariants = "UPDATE product_variants 
                            SET status = $1 
                            WHERE product_id IN (SELECT product_id FROM products WHERE category_id = $2)";
            pg_query_params($this->conn, $sqlVariants, [$new_status, $category_id]);

            // 2. Cập nhật trạng thái toàn bộ sản phẩm thuộc hãng này
            $sqlProducts = "UPDATE products SET status = $1 WHERE category_id = $2";
            pg_query_params($this->conn, $sqlProducts, [$new_status, $category_id]);

            pg_query($this->conn, "COMMIT");
            return true;
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            return false;
        }
    }


    /**
     * Lấy danh sách biến thể (Size, Màu, Tồn kho) thuộc về 1 sản phẩm
     */
    public function getVariantsByProductId($product_id)
    {
        $sql = "SELECT v.variant_id, v.sku, v.size, v.color, v.stock, v.status as variant_status,
                   p.product_name, p.product_image
            FROM product_variants v
            JOIN products p ON v.product_id = p.product_id
            WHERE v.product_id = $1 AND v.is_deleted = false 
            ORDER BY v.size ASC";
        $result = pg_query_params($this->conn, $sql, [$product_id]);
        return $result ? pg_fetch_all($result) : [];
    }

    /**
     * Tìm kiếm sản phẩm linh hoạt (Tên, SKU, Size, Màu)
     */
    public function searchProducts($keyword)
    {
        $keyword = '%' . $keyword . '%';
        $sql = "SELECT DISTINCT p.product_id, p.product_name, p.product_image, p.category_id 
            FROM products p
            LEFT JOIN product_variants v ON p.product_id = v.product_id
            WHERE (p.product_name ILIKE $1 
               OR v.sku ILIKE $1 
               OR v.size ILIKE $1 
               OR v.color ILIKE $1)
               AND p.is_deleted = false
            LIMIT 8";
        $result = pg_query_params($this->conn, $sql, [$keyword]);
        return $result ? pg_fetch_all($result) : [];
    }

    /**
     * Tạo bản ghi Sản phẩm Gốc (Cha)
     */
    public function create($name, $category_id, $image, $user_id)
    {
        $sql = "INSERT INTO products (product_name, category_id, product_image, created_by, status, is_deleted, created_at) 
            VALUES ($1, $2, $3, $4, 't', false, NOW()) 
            RETURNING product_id";
        $result = pg_query_params($this->conn, $sql, [$name, (int)$category_id, $image, (int)$user_id]);
        if ($result) {
            $row = pg_fetch_assoc($result);
            return $row['product_id'];
        }
        return false;
    }

    /**
     * Tạo bản ghi Biến thể (Con)
     */
    public function createVariant($product_id, $size, $color, $stock, $sku)
    {
        $sql = "INSERT INTO product_variants (product_id, size, color, stock, sku) 
            VALUES ($1, $2, $3, $4, $5)";
        return pg_query_params($this->conn, $sql, [(int)$product_id, (int)$size, $color, (int)$stock, $sku]);
    }

    /**
     * Đối soát văn bản: Tìm sản phẩm dựa theo Tên và Hãng
     */
    public function findExistingProduct($brandName, $modelName)
    {
        $brandName = trim($brandName);
        $modelName = trim($modelName);
        $sql = "SELECT p.product_id, p.product_name, c.category_id, c.category_name
            FROM products p
            JOIN categories c ON p.category_id = c.category_id
            WHERE (REPLACE(p.product_name, '\"', '') ILIKE $1) 
            AND c.category_name ILIKE $2 
            AND p.is_deleted = false 
            LIMIT 1";
        $cleanModelName = str_replace('"', '', $modelName);
        $res = pg_query_params($this->conn, $sql, ["%$cleanModelName%", "%$brandName%"]);
        return $res ? pg_fetch_assoc($res) : null;
    }

    /**
     * Kiểm tra tính duy nhất của Màu sắc trong 1 sản phẩm
     */
    public function checkColorExists($product_id, $color)
    {
        $sql = "SELECT color FROM product_variants 
            WHERE product_id = $1 AND color ILIKE $2 AND is_deleted = false LIMIT 1";
        $res = pg_query_params($this->conn, $sql, [$product_id, $color]);
        return (bool)pg_fetch_assoc($res);
    }

    /**
     * Đối soát Biến thể: Tìm chính xác ID, Size, Màu
     */
    public function findVariant($product_id, $size, $color)
    {
        $sql = "SELECT variant_id, stock FROM product_variants 
            WHERE product_id = $1 AND size = $2 AND color ILIKE $3 AND is_deleted = false LIMIT 1";
        $res = pg_query_params($this->conn, $sql, [(int)$product_id, (int)$size, $color]);
        return $res ? pg_fetch_assoc($res) : null;
    }

    /**
     * Cộng dồn số lượng tồn kho
     */
    public function addStock($variant_id, $quantity)
    {
        $sql = "UPDATE product_variants SET stock = stock + $1 WHERE variant_id = $2";
        return pg_query_params($this->conn, $sql, [(int)$quantity, (int)$variant_id]);
    }

    /**
     * Lấy mảng màu sắc hiện có của sản phẩm để hiện Combobox AI
     */
    public function getColorsByProduct($product_id)
    {
        $sql = "SELECT DISTINCT color FROM product_variants 
            WHERE product_id = $1 AND is_deleted = false 
            ORDER BY color ASC";
        $result = pg_query_params($this->conn, $sql, [(int)$product_id]);
        return $result ? pg_fetch_all($result) : [];
    }

    /**
     * Lưu trữ "ADN hình ảnh" (Mảng Vector 512 chiều) vào CSDL bằng pgvector
     */
    public function updateImageEmbedding($product_id, $vectorArray)
    {
        // pgvector yêu cầu chuỗi có dạng: "[0.1, 0.2, 0.3...]"
        $vectorString = '[' . implode(',', $vectorArray) . ']';

        $sql = "UPDATE products SET image_embedding = $1 WHERE product_id = $2";

        // Thực thi query với tham số an toàn
        $result = pg_query_params($this->conn, $sql, [$vectorString, (int)$product_id]);

        return $result ? true : false;
    }

    /**
     * Tìm kiếm hình ảnh bằng AI: Hỗ trợ kịch bản đối soát thông minh
     * Lấy danh sách các sản phẩm có độ tương đồng >= ngưỡng cấu hình PercentMatching
     */
    public function findTopMatchesByAI($vectorArray, $limit = 3)
    {
        // Đọc cấu hình ngưỡng nhận diện AI
        $configPath = __DIR__ . '/../../config/PercentMatching.php';
        $minSimilarity = 0.80; // Giá trị mặc định nếu file không tồn tại
        if (file_exists($configPath)) {
            $matchingConfig = require $configPath;
            $minSimilarity = isset($matchingConfig['min_similarity']) ? (float)$matchingConfig['min_similarity'] : 0.80;
        }

        // Định dạng lại mảng Vector cho PostgreSQL
        $vectorString = '[' . implode(',', $vectorArray) . ']';

        // SQL: Lấy thông tin sản phẩm + hãng + tính toán Similarity Score
        // (1 - Distance) = Similarity. Distance càng nhỏ, Similarity càng cao.
        $sql = "SELECT p.product_id, p.product_name, p.product_image, 
                       c.category_id, c.category_name as brand,
                       (1 - (p.image_embedding <=> $1)) AS similarity_score
                FROM products p
                JOIN categories c ON p.category_id = c.category_id
                WHERE p.is_deleted = false 
                  AND p.image_embedding IS NOT NULL
                  AND (1 - (p.image_embedding <=> $1)) >= $2 -- Ngưỡng cấu hình động
                ORDER BY similarity_score DESC 
                LIMIT $3";

        $result = pg_query_params($this->conn, $sql, [$vectorString, $minSimilarity, (int)$limit]);

        return $result ? pg_fetch_all($result) : [];
    }



    /**
     * Trừ số lượng tồn kho (Xuất kho)
     */
    public function removeStock($variant_id, $quantity)
    {
        // Chỉ trừ nếu số lượng xuất <= số lượng tồn
        $sql = "UPDATE product_variants SET stock = stock - $1 
            WHERE variant_id = $2 AND stock >= $1";
        return pg_query_params($this->conn, $sql, [(int)$quantity, (int)$variant_id]);
    }


    /**
     * Ghi lại lịch sử giao dịch (Nhập/Xuất)
     */
    // Chú ý tham số thứ 5 là $createdAt
    public function logTransaction($type, $variant_id, $quantity, $user_id, $createdAt = null)
    {
        // Nếu có truyền $createdAt từ Google sang thì dùng $1, không thì dùng NOW()
        $sql = "INSERT INTO transactions (transaction_type, variant_id, quantity, user_id, created_at)
            VALUES ($1, $2, $3, $4, " . ($createdAt ? "$5" : "NOW()") . ")";

        $params = [$type, $variant_id, $quantity, $user_id];
        if ($createdAt) {
            $params[] = $createdAt;
        }

        return pg_query_params($this->conn, $sql, $params);
    }


    /**
     * Cập nhật trạng thái Bật/Tắt của biến thể (Status)
     */
    public function updateVariantStatus($variantId, $status)
    {
        // PostgreSQL hiểu chuỗi 'true' hoặc 'false' cho kiểu boolean
        $sql = "UPDATE product_variants 
                SET status = $1 
                WHERE variant_id = $2";

        $result = pg_query_params($this->conn, $sql, [$status, (int)$variantId]);
        return $result ? true : false;
    }

    /**
     * Xóa mềm biến thể (Đưa vào thùng rác)
     */
    public function softDeleteVariant($variantId)
    {
        // Xóa mềm: is_deleted = true và tắt luôn status = false
        $sql = "UPDATE product_variants 
                SET is_deleted = true, status = false 
                WHERE variant_id = $1";

        $result = pg_query_params($this->conn, $sql, [(int)$variantId]);
        return $result ? true : false;
    }








    // =========================================================================
    // THUẬT TOÁN THÁC NƯỚC (WATERFALL PUTAWAY) & LƯU JSONB
    // =========================================================================

    // 3. Hàm lưu mảng vị trí vào JSONB (Dùng khi ấn Lưu)
    public function savePutawayToShelves($putawayDataArray, $variantId)
    {
        foreach ($putawayDataArray as $item) {
            $loc = $item['location']; // Ví dụ: A1-01
            $qty = intval($item['quantity']);

            preg_match('/^(.+)_(\d+)-(\d{2})$/', $loc, $matches);

            if (count($matches) == 4) {
                $shelfName = $matches[1];
                $tier = $matches[2];
                $slot = $matches[3];

                $sql = "SELECT layout FROM shelves WHERE shelf_name = $1";
                $res = pg_query_params($this->conn, $sql, [$shelfName]);
                if ($row = pg_fetch_assoc($res)) {
                    $layout = json_decode($row['layout'], true);
                    if (!isset($layout[$tier][$slot])) $layout[$tier][$slot] = [];

                    for ($k = 0; $k < $qty; $k++) {
                        array_push($layout[$tier][$slot], (int)$variantId);
                    }

                    $jsonb_str = json_encode($layout);
                    pg_query_params($this->conn, "UPDATE shelves SET layout = $1::jsonb WHERE shelf_name = $2", [$jsonb_str, $shelfName]);
                }
            }
        }
    }





    /**
     * Rút variant_id khỏi kệ. 
     * - Nếu $qtyToRemove là số nguyên: Rút đúng số lượng (Dùng cho Xuất kho)
     * - Nếu $qtyToRemove là null: Quét và rút sạch toàn bộ (Dùng cho Xóa biến thể)
     */
    public function removePutawayFromShelves($variantId, $qtyToRemove = null)
    {
        $sql = "SELECT shelf_name, layout FROM shelves";
        $shelves = pg_fetch_all(pg_query($this->conn, $sql)) ?: [];

        $remainingToRemove = $qtyToRemove;
        $removeAll = ($qtyToRemove === null); // Cờ báo hiệu: Xóa tất cả

        foreach ($shelves as $shelf) {
            // Nếu không phải chế độ xóa tất cả, và đã rút đủ số lượng -> Dừng quét kho
            if (!$removeAll && $remainingToRemove <= 0) break;

            $shelfName = $shelf['shelf_name'];
            $layout = json_decode($shelf['layout'], true);
            $layoutModified = false;

            if ($layout) {
                foreach ($layout as $tier => &$slots) {
                    foreach ($slots as $slotKey => &$shoesArray) {

                        $indexesToDelete = array_keys($shoesArray, (int)$variantId);

                        if (!empty($indexesToDelete)) {
                            foreach ($indexesToDelete as $idx) {
                                if ($removeAll || $remainingToRemove > 0) {
                                    unset($shoesArray[$idx]);
                                    if (!$removeAll) $remainingToRemove--;
                                    $layoutModified = true;
                                } else {
                                    break; // Đủ số lượng thì thoát vòng lặp nhỏ
                                }
                            }
                            // Gắn lại key của mảng để khi lưu JSON không bị biến thành Object
                            $shoesArray = array_values($shoesArray);
                        }
                    }
                }
            }

            // Lưu kệ này nếu có thay đổi
            if ($layoutModified) {
                $jsonb_str = json_encode($layout);
                pg_query_params($this->conn, "UPDATE shelves SET layout = $1::jsonb WHERE shelf_name = $2", [$jsonb_str, $shelfName]);
            }
        }

        // Trả về true nếu là chế độ xóa sạch, hoặc nếu xuất kho đã rút đủ số lượng
        return $removeAll ? true : ($remainingToRemove == 0);
    }







    /**
     * Lấy danh sách tên các sản phẩm (dạng chữ thường) có trong phiếu nhập
     */
    public function getProductNamesInTicket($ticket_id)
    {
        $sql = "SELECT DISTINCT p.product_name 
                FROM ticket_details td 
                JOIN product_variants pv ON td.variant_id = pv.variant_id
                JOIN products p ON pv.product_id = p.product_id
                WHERE td.ticket_id = $1";
        $res = pg_query_params($this->conn, $sql, [$ticket_id]);
        $names = [];
        if ($res) {
            while ($row = pg_fetch_assoc($res)) {
                $names[] = mb_strtolower(trim($row['product_name']), 'UTF-8');
            }
        }
        return $names;
    }
}
