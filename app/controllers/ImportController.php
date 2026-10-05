<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../models/ImportModel.php';

class ImportController
{
    private $model;

    public function __construct()
    {
        $this->model = new ImportModel();
        date_default_timezone_set('Asia/Ho_Chi_Minh');
    }

    /**
     * Chức năng: Đồng bộ màu sắc Phiếu (Sync Realtime).
     */
    private function triggerManagerSync($ticket_id, $status, $completed_at = null)
    {
        // NẠP CẤU HÌNH PUSHER ĐỘNG
        $pusherConfig = require __DIR__ . '/../../config/pusherconfig.php';
        try {
            $options = [
                'cluster' => $pusherConfig['cluster'],
                'useTLS' => true
            ];

            $pusher = new Pusher\Pusher(
                $pusherConfig['key'],
                $pusherConfig['secret'],
                $pusherConfig['app_id'],
                $options
            );

            $payload = ['ticket_id' => $ticket_id, 'status' => $status];
            if ($completed_at) $payload['completed_at'] = $completed_at;
            $pusher->trigger('warehouse-channel', 'ticket-status-changed', $payload);
        } catch (Exception $e) {
        }
    }


    /**
     * Chức năng: Route trả JSON list phiếu Nhập cho AI.
     * Tác dụng: Nạp vào Popup danh sách Nhập.
     */
    public function getMyImportsAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        $staff_id = $_SESSION['user_id'];
        $data = $this->model->getMyImports($staff_id);

        echo json_encode($data);
        exit;
    }

    /**
     * Chức năng: Truy xuất danh sách các vị trí ô kệ phù hợp để thực hiện việc cất hàng (Putaway).
     * Tác dụng: Trả về dữ liệu JSON bao gồm các ô đang chứa sản phẩm này và các ô còn trống để nhân viên kho chọn vị trí nhập hàng trên giao diện.
     */
    public function getPutawayLocationsAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        $variant_id = isset($_GET['variant_id']) ? (int)$_GET['variant_id'] : 0;
        $ticket_id = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
        $quantity = isset($_GET['quantity']) ? (int)$_GET['quantity'] : 0;

        $data = $this->model->getPutawayLocations($variant_id, $ticket_id, $quantity);

        echo json_encode($data);
        exit;
    }

    /**
     * Chức năng: Lưu Form biến thể do AI nhận diện hoặc sửa tay.
     * Tác dụng: Xử lý request từ Javascript, đẩy vào Database và trả về mã QR.
     */
    public function saveTempImportAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        // 1. Nhận dữ liệu từ form (Đã bỏ 2 biến status và discrepancy_type cũ)
        $ticket_id = $_POST['ticket_id'];
        $detail_id = $_POST['detail_id'];
        $variant_id = $_POST['variant_id'];
        $actual_qty = $_POST['actual_qty'];
        $note = $_POST['note'];
        $image = $_POST['scanned_image'];
        $staff_id = $_SESSION['user_id'];
        $putaway_locations = $_POST['putaway_locations'] ?? null;

        // 2. Gọi Model lưu dữ liệu
        $result = $this->model->saveTempImport(
            $ticket_id,
            $detail_id,
            $variant_id,
            $actual_qty,
            $image,
            $note,
            $staff_id,
            $putaway_locations
        );

        // 3. Xử lý kết quả mảng trả về từ Model (Chứa qr_code)
        if (is_array($result) && $result['status'] === 'success') {
            echo json_encode([
                'status' => 'success',
                'qr_code' => $result['qr_code'] // Đẩy QR code về cho import.js hiển thị
            ]);
        } else {
            // Lấy câu thông báo lỗi từ Model nếu có
            $msg = is_array($result) ? ($result['message'] ?? 'Lỗi chưa xác định') : 'Lỗi hệ thống';
            echo json_encode(['status' => 'error', 'message' => $msg]);
        }
        exit;
    }

    /**
     * Chức năng: Route Submit hoàn tất Phiếu.
     * Tác dụng: Phân xử logic cộng số giày, trigger Realtime, tắt Session PHP.
     */
    public function completeImportAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        $ticket_id = $_POST['ticket_id'];
        $user_id = $_SESSION['user_id'];

        $final_status = $this->model->completeImportTicket($ticket_id, $user_id);

        if ($final_status !== false) {
            echo json_encode(['status' => 'success', 'final_status' => $final_status]);

            $size = ob_get_length();
            header("Content-Length: $size");
            header('Connection: close');
            ob_end_flush();
            @ob_flush();
            flush();
            if (session_id()) session_write_close();

            $this->triggerManagerSync($ticket_id, $final_status, date('d/m/Y H:i'));
        } else {
            // Lấy thông báo đối soát không khớp chi tiết từ Model
            $msg = $_SESSION['import_error'] ?? 'Lỗi chốt sổ kho.';
            unset($_SESSION['import_error']);
            echo json_encode(['status' => 'error', 'message' => $msg]);
        }
        exit;
    }


    /**
     * Chức năng: Endpoint mở luồng AI mới.
     * Tác dụng: Khởi tạo temp session để giữ chỗ data nhập kho.
     */
    public function startImportProcessAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $this->model->startImportProcess($_POST['ticket_id'] ?? 0)]);
        exit;
    }

    /** * Chức năng: Cập nhật số lượng thực nhập (+1) khi AI quét trúng.
     * Tác dụng: Tăng số lượng trong bảng tạm và lưu vết ảnh quét.
     */
    public function updateTempImportAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        $res = $this->model->updateImportTemp($_POST['ticket_id'], $_POST['variant_id'], $_POST['image_url']);
        if ($res) {
            echo json_encode(['success' => true, 'actual_qty' => $res['actual_qty']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Sản phẩm này không có trong phiếu!']);
        }
        exit;
    }

    /** * Chức năng: Giảm số lượng thực nhập (-1) trong bảng tạm.
     * Tác dụng: Hỗ trợ tính năng Undo khi nhân viên quét nhầm ảnh.
     */
    public function decreaseTempImportAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => (bool)$this->model->decreaseImportTemp($_POST['ticket_id'], $_POST['variant_id'])]);
        exit;
    }

    /** * Chức năng: Chốt sổ phiếu nhập kho (Phiên bản cũ).
     * Tác dụng: Chuyển dữ liệu từ bảng tạm sang tồn kho vật lý và cập nhật thời gian hoàn thành.
     */
    public function finalizeImportAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        $ticket_id = $_POST['ticket_id'];
        $user_id = $_SESSION['user_id'];
        $res = $this->model->finalizeImport($ticket_id, $user_id);
        if ($res) {
            $this->triggerManagerSync($ticket_id, 'COMPLETED', date('d/m/Y H:i'));
        }
        echo json_encode(['success' => $res]);
        exit;
    }
}
