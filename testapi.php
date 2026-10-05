<?php
$apiKey = "AIzaSyByrJA7EKF1QQRMjZxsfXWy5HvHvcXOGCo";
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $apiKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
curl_close($ch);

$data = json_decode($res, true);

echo "<h3>Danh sách Model bồ ĐƯỢC DÙNG:</h3>";
if (isset($data['models'])) {
    foreach ($data['models'] as $m) {
        // Lọc các model có thể nhìn ảnh (Vision)
        if (in_array("generateContent", $m['supportedGenerationMethods'])) {
            echo "<li><code>" . $m['name'] . "</code></li>";
        }
    }
} else {
    echo "Lỗi: " . ($data['error']['message'] ?? 'Không lấy được danh sách');
}
?>