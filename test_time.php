<?php
// Tắt mọi lỗi hiển thị không cần thiết để nhìn cho rõ
error_reporting(E_ALL);
ini_set('display_errors', 1);

$url = "https://www.google.com";

echo "<h2>--- ĐANG LẤY HEADER TỪ GOOGLE ---</h2>";

// Gửi yêu cầu lấy header
$headers = @get_headers($url, 1);

if ($headers) {
    echo "<pre>";
    // In toàn bộ header ra để bồ soi
    print_r($headers);
    echo "</pre>";

    echo "<hr>";

    // Thử trích xuất ngày tháng
    if (isset($headers['Date'])) {
        $dateStr = is_array($headers['Date']) ? $headers['Date'][0] : $headers['Date'];
        echo "<b>1. Giờ gốc từ Google (GMT):</b> " . $dateStr . "<br>";

        // Chuyển sang giờ Việt Nam
        $date = new DateTime($dateStr, new DateTimeZone('GMT'));
        $date->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
        
        echo "<b>2. Giờ đã chuyển sang Việt Nam (UTC+7):</b> " . $date->format('Y-m-d H:i:s') . "<br>";
        echo "<b>3. Giờ trên Laptop của bồ hiện tại:</b> " . date('Y-m-d H:i:s') . "<br>";
        
        echo "<h3>=> Nếu (2) và (3) lệch nhau, nghĩa là bồ đã 'hack' giờ máy tính thành công!</h3>";
    }
} else {
    echo "<b style='color:red;'>LỖI: Không thể kết nối tới Google!</b><br>";
    echo "Bồ kiểm tra xem máy có đang bật Internet không nhé.";
}
?>