<?php
// config/database.php

function getConnection() {
    $conn_string = "host=localhost port=5432 dbname=shoe_warehouse_ai user=admin_shoe_shop password=123456";

    $connection = pg_connect($conn_string);

    if (!$connection) {
        die("❌ Kết nối PostgreSQL thất bại!");
    }

    // --- PHẦN BỔ SUNG CHO AUDIT LOG ---
    // 1. Đảm bảo Session đã được khởi tạo để lấy user_id
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 2. Nếu đã đăng nhập, truyền user_id vào session của PostgreSQL
    if (isset($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
        
        // Sử dụng pg_query để thiết lập biến tạm 'audit.user_id' trong PostgreSQL
        // Biến này sẽ tồn tại trong suốt phiên kết nối này
        pg_query($connection, "SET audit.user_id = '$userId'");
    } else {
        // Nếu chưa đăng nhập (ví dụ: khách xem), có thể set về 0 hoặc NULL
        pg_query($connection, "SET audit.user_id = '0'");
    }
    // --- KẾT THÚC PHẦN BỔ SUNG ---

    return $connection;
}

function closeConnection($connection) {
    if ($connection) {
        pg_close($connection);
    }
}
?>