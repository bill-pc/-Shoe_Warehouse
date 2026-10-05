<?php
// app/models/UserModel.php

require_once __DIR__ . '/../../config/database.php';

class UserModel
{
    private $conn;

    public function __construct()
    {
        $this->conn = getConnection();
    }

    // Tìm user theo user_id để phục vụ Login (bảo mật bằng prepare)
    public function findById($user_id)
    {
        $sql = "SELECT user_id, password_hash, full_name, role, status 
                FROM users 
                WHERE user_id = $1 AND is_deleted = false";

        // Chuẩn bị query
        pg_prepare($this->conn, "find_user", $sql);

        // Thực thi
        $result = pg_execute($this->conn, "find_user", array(intval($user_id)));

        if ($result && pg_num_rows($result) > 0) {
            return pg_fetch_assoc($result);
        }

        return null;
    }

    // Lấy tất cả nhân viên chưa bị xóa (Có hỗ trợ Tìm kiếm theo ID hoặc Tên & Lọc)
    public function getAllUsers($keyword = '', $role = '')
    {
        $sql = "SELECT user_id, full_name, role, status, created_at 
                FROM users 
                WHERE is_deleted = false";
        $params = [];
        $paramIndex = 1;

        // Xử lý tìm kiếm theo ID hoặc Tên (không phân biệt hoa thường)
        if (!empty($keyword)) {
            $sql .= " AND (user_id::text ILIKE $" . $paramIndex . " OR full_name ILIKE $" . $paramIndex . ")";
            $params[] = "%" . $keyword . "%";
            $paramIndex++;
        }

        // Xử lý lọc theo Vai trò
        if (!empty($role)) {
            $sql .= " AND role = $" . $paramIndex;
            $params[] = strtoupper($role);
        }

        $sql .= " ORDER BY user_id ASC";

        if (empty($params)) {
            $result = pg_query($this->conn, $sql);
        } else {
            $result = pg_query_params($this->conn, $sql, $params);
        }

        return $result ? pg_fetch_all($result) : [];
    }




    // Kiểm tra ID nhân viên đã tồn tại hay chưa (bất kể đã xóa mềm hay chưa)
    public function checkUserIdExists($user_id)
    {
        $sql = "SELECT user_id FROM users WHERE user_id = $1";
        $result = pg_query_params($this->conn, $sql, [intval($user_id)]);
        if ($result && pg_num_rows($result) > 0) {
            return true;
        }
        return false;
    }

    // Thêm nhân viên mới (Tự sinh ID ngẫu nhiên 6 chữ số không trùng lặp)
    public function addUser($data)
    {
        // Sinh ID ngẫu nhiên 6 chữ số (từ 100000 đến 999999) và kiểm tra trùng lặp
        do {
            $random_id = rand(100000, 999999);
        } while ($this->checkUserIdExists($random_id));

        $sql = "INSERT INTO users (user_id, full_name, password_hash, role, status, is_deleted, created_at)
                VALUES ($1, $2, $3, $4, $5, false, NOW())";

        // Hash mật khẩu an toàn
        $password_hash = password_hash($data['password'], PASSWORD_BCRYPT);

        // Chuyển đổi boolean sang chuỗi 'true'/'false' cho PostgreSQL
        $status_db = $data['status'] ? 'true' : 'false';

        $result = pg_query_params($this->conn, $sql, [
            $random_id,
            trim($data['full_name']),
            $password_hash,
            strtoupper(trim($data['role'])),
            $status_db
        ]);

        // Nếu insert thành công, trả về ID vừa được sinh ngẫu nhiên
        return $result ? $random_id : false;
    }


    // Xóa mềm nhân viên
    public function deleteUser($user_id)
    {
        $sql = "UPDATE users SET is_deleted = true WHERE user_id = $1";
        return pg_query_params($this->conn, $sql, [$user_id]);
    }

    // cập nhật trạng thái (manager)
    public function updateUserStatus($user_id, $status)
    {
        // Chuyển đổi sang định dạng 'true'/'false' của PostgreSQL
        $status_db = ($status === true || $status === 'true' || $status === 'on') ? 'true' : 'false';

        $sql = "UPDATE users SET status = $1 WHERE user_id = $2 AND is_deleted = false";
        return pg_query_params($this->conn, $sql, [$status_db, $user_id]);
    }

    // Lấy 1 nhân viên theo ID
    public function getUserById($user_id)
    {
        $sql = "SELECT user_id, full_name, role, status, phone_number, address 
                FROM users 
                WHERE user_id = $1 AND is_deleted = false";

        $result = pg_query_params($this->conn, $sql, [intval($user_id)]);

        if ($result && pg_num_rows($result) > 0) {
            return pg_fetch_assoc($result);
        }
        return null;
    }




    // chỉnh sửa thông tin cá nhân
    public function updateProfile($user_id, $data)
    {
        // 1. Lấy thông tin hiện tại để giữ lại nếu không nhập mới
        $current = $this->getUserById($user_id);

        $fullName = !empty($data['full_name']) ? trim($data['full_name']) : $current['full_name'];
        $phone = !empty($data['phone_number']) ? trim($data['phone_number']) : $current['phone_number'];
        $address = !empty($data['address']) ? trim($data['address']) : $current['address'];

        // 2. Xử lý mật khẩu
        if (!empty($data['new_password'])) {
            $password_hash = password_hash($data['new_password'], PASSWORD_BCRYPT);
            $sql = "UPDATE users SET full_name = $1, phone_number = $2, address = $3, password_hash = $4 WHERE user_id = $5";
            $params = [$fullName, $phone, $address, $password_hash, $user_id];
        } else {
            $sql = "UPDATE users SET full_name = $1, phone_number = $2, address = $3 WHERE user_id = $4";
            $params = [$fullName, $phone, $address, $user_id];
        }

        // Cập nhật lại session full_name để hiển thị chính xác ngay trên UI
        $_SESSION['full_name'] = $fullName;

        return pg_query_params($this->conn, $sql, $params);
    }


    // Hàm kiểm tra mật khẩu cũ
    public function verifyOldPassword($user_id, $old_password)
    {
        $sql = "SELECT password_hash FROM users WHERE user_id = $1";
        $result = pg_query_params($this->conn, $sql, [$user_id]);
        $user = pg_fetch_assoc($result);

        if ($user && password_verify($old_password, $user['password_hash'])) {
            return true;
        }
        return false;
    }
}
