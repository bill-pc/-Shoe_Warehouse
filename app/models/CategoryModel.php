<?php
// app/models/CategoryModel.php

class CategoryModel
{
    private $conn;

    public function __construct()
    {
        $this->conn = getConnection();
    }

    // lấy danh sách hãng giày
    public function getAll()
    {
        $sql = "SELECT category_id, category_name, logo, created_at, status 
                FROM categories 
                WHERE is_deleted = false 
                ORDER BY category_id ASC";
        $result = pg_query($this->conn, $sql);
        return $result ? pg_fetch_all($result) : [];
    }

    // lấy thông tin chi tiết 1 hãng
    public function getById($id)
    {
        $sql = "SELECT * FROM categories WHERE category_id = $1";
        $result = pg_query_params($this->conn, $sql, [$id]);
        return $result ? pg_fetch_assoc($result) : null;
    }


    // Kiểm tra tên hãng giày đã tồn tại hay chưa (chỉ xét các hãng chưa bị xóa mềm)
    public function isCategoryExists($category_name)
    {
        $sql = "SELECT category_id FROM categories WHERE category_name ILIKE $1 AND is_deleted = false";
        $result = pg_query_params($this->conn, $sql, [trim($category_name)]);
        if ($result && pg_num_rows($result) > 0) {
            return true;
        }
        return false;
    }



    // Kiểm tra hãng giày có chứa sản phẩm hoạt động nào không
    public function hasProducts($category_id)
    {
        $sql = "SELECT COUNT(*) as cnt FROM products WHERE category_id = $1 AND is_deleted = false";
        $result = pg_query_params($this->conn, $sql, [(int)$category_id]);
        if ($result) {
            $row = pg_fetch_assoc($result);
            return (int)$row['cnt'] > 0;
        }
        return false;
    }



    // thêm hãng mới
    public function create($category_name, $logo, $created_by) // Thêm tham số $created_by
    {
        $sql = "INSERT INTO categories (category_name, logo, is_deleted, created_at, created_by)
            VALUES ($1, $2, false, NOW(), $3)";
        return pg_query_params($this->conn, $sql, [trim($category_name), $logo, $created_by]);
    }

    // cập nhật
    public function updateStatus($id, $new_status)
    {
        // $new_status sẽ là 't' (true) hoặc 'f' (false)
        $sql = "UPDATE categories SET status = $1 WHERE category_id = $2";
        return pg_query_params($this->conn, $sql, [$new_status, $id]);
    }


    // Cập nhật logo của hãng
    public function updateLogo($id, $logo)
    {
        $sql = "UPDATE categories SET logo = $1 WHERE category_id = $2";
        return pg_query_params($this->conn, $sql, [$logo, $id]);
    }


    // Xóa hãng giày (Soft Delete) và cascade xóa mềm toàn bộ sản phẩm & biến thể bên trong, giải phóng kệ kho
    public function delete($category_id)
    {
        pg_query($this->conn, "BEGIN");
        try {
            // 1. Tìm toàn bộ sản phẩm chưa bị xóa thuộc hãng này
            $sqlGetProducts = "SELECT product_id FROM products WHERE category_id = $1 AND is_deleted = false";
            $resProducts = pg_query_params($this->conn, $sqlGetProducts, [(int)$category_id]);

            if ($resProducts) {
                // Tái sử dụng logic xóa cascade của ProductModel đã viết ở Bước 1
                $productModel = new ProductModel();
                while ($row = pg_fetch_assoc($resProducts)) {
                    $product_id = (int)$row['product_id'];
                    // Gọi hàm xóa để tự động cascade xóa biến thể con và dọn sạch kệ kho
                    $productModel->delete($product_id);
                }
            }

            // 2. Xóa mềm Hãng giày (đặt status = false)
            $sqlDeleteCat = "UPDATE categories SET is_deleted = true, status = false WHERE category_id = $1";
            pg_query_params($this->conn, $sqlDeleteCat, [(int)$category_id]);

            pg_query($this->conn, "COMMIT");
            return true;
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            return false;
        }
    }


    /**
     * HÀM MỚI: Tự động dò tìm ID Hãng bằng Tên chữ. 
     * Nếu không tìm thấy, tự động khởi tạo Hãng mới.
     */
    public function getCategoryIdByName($brandName, $user_id)
    {
        $brandName = trim($brandName);

        // 1. Dùng ILIKE để tìm không phân biệt chữ hoa/thường (ví dụ: gõ "nike" vẫn khớp với "Nike")
        $sql = "SELECT category_id FROM categories WHERE category_name ILIKE $1 AND is_deleted = false LIMIT 1";
        $result = pg_query_params($this->conn, $sql, [$brandName]);
        $row = pg_fetch_assoc($result);

        if ($row) {
            // Đã có Hãng -> Trả về ID
            return $row['category_id'];
        } else {
            // 2. Chưa có Hãng -> Tự động Insert mới để hệ thống không bị lỗi gãy
            // Dùng một logo rỗng hoặc mặc định
            $insertSql = "INSERT INTO categories (category_name, logo, is_deleted, created_at, created_by, status)
                          VALUES ($1, 'default_brand.png', false, NOW(), $2, 't') RETURNING category_id";
            $insertResult = pg_query_params($this->conn, $insertSql, [$brandName, $user_id]);

            if ($insertResult) {
                $newRow = pg_fetch_assoc($insertResult);
                return $newRow['category_id']; // Trả về ID vừa mới sinh ra
            }

            return false;
        }
    }
}
