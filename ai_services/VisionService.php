<?php
// app/ai_services/VisionService.php

class VisionService
{
    /**
     * Gọi Flask API (đang chạy ngầm) để chuyển đổi Ảnh thành Vector AI 512 chiều
     */
    public function generateVector($imagePath)
    {
        // 1. Chuyển đường dẫn ảnh sang đường dẫn tuyệt đối
        $fullImagePath = realpath($imagePath);

        if (!$fullImagePath || !file_exists($fullImagePath)) {
            return ["status" => "error", "message" => "Không tìm thấy ảnh tại: " . $imagePath];
        }

        // 2. Cấu hình gọi API sang Flask (Python)
        $url = 'http://127.0.0.1:5000/scan';
        $data = array('image_path' => $fullImagePath);
        $payload = json_encode($data);

        // Khởi tạo cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ));

        // Timeout 15 giây để tránh treo Web nếu Python bị nghẽn
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        // Thực thi gọi API
        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // 3. Xử lý kết quả trả về
        if ($result === false) {
            return [
                "status" => "error",
                "message" => "Không kết nối được tới AI Server. Bạn đã chạy 'python api_vector.py' chưa? Lỗi: " . $error
            ];
        }

        if ($httpcode !== 200) {
            return [
                "status" => "error",
                "message" => "AI Server báo lỗi (Code $httpcode): " . substr($result, 0, 100)
            ];
        }


        $decodedResponse = json_decode($result, true);

        if (json_last_error() === JSON_ERROR_NONE && isset($decodedResponse['status'])) {
            return $decodedResponse; // Trả thẳng kết quả {"status": "success", "vector": [...]} về cho Controller
        }

        return ["status" => "error", "message" => "Dữ liệu AI trả về bị lỗi định dạng."];
    }
}
