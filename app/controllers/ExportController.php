<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../models/ExportModel.php';

class ExportController
{
    private $model;

    public function __construct()
    {
        $this->model = new ExportModel();
        date_default_timezone_set('Asia/Ho_Chi_Minh');
    }

    /**
     * Chức năng: Kêu gọi thiết bị Manager F5 màu của Card Giao Việc.
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
            if ($completed_at) {
                $payload['completed_at'] = $completed_at;
            }
            $pusher->trigger('warehouse-channel', 'ticket-status-changed', $payload);
        } catch (Exception $e) {
        }
    }


    /** Chức năng: Đổ mảng Xuất kho cho Javascript */
    public function getMyExportsAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        $staff_id = $_SESSION['user_id'];
        $data = $this->model->getMyExports($staff_id);
        echo json_encode($data);
        exit;
    }

    /** Chức năng: Update + số lượng hoàn tất thủ công */
    public function updateExportProgressAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        $detail_id = $_POST['detail_id'];
        $picked_qty = (int)$_POST['picked_qty'];
        $picked_locations = $_POST['picked_locations'] ?? null; // Chuỗi JSON vị trí đã chọn

        if ($this->model->updateExportProgress($detail_id, $picked_qty, $picked_locations)) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Lỗi cập nhật CSDL']);
        }
        exit;
    }


    /** Chức năng: Dò map Layout Kệ theo mã Giày */
    public function getLocationsAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        $variant_id = $_GET['variant_id'];
        $data = $this->model->getLocationsFromJSON($variant_id);
        echo json_encode($data);
        exit;
    }

    /** Chức năng: Dứt điểm quá trình thu hồi phiếu. Hoàn tất trừ mảng trên layout. */
    public function completeExportAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        $ticket_id = $_REQUEST['ticket_id'] ?? 0;
        // Lấy ID người dùng thực hiện xuất kho từ session
        $user_id = $_SESSION['user_id'] ?? null;

        if ($ticket_id > 0) {
            try {
                // Truyền thêm biến $user_id để ghi nhận lịch sử giao dịch
                if ($this->model->completeExportTicket($ticket_id, $user_id)) {
                    $completed_at = date('d/m/Y H:i');
                    $this->triggerManagerSync($ticket_id, 'COMPLETED', $completed_at);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Lỗi trừ kho thực tế.']);
                }
            } catch (Exception $e) {
                // Hiển thị thông báo lỗi thực tế cực kỳ chi tiết
                echo json_encode(['status' => 'error', 'message' => 'Lỗi trừ kho thực tế: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Mã phiếu không hợp lệ.']);
        }
        exit;
    }
}
