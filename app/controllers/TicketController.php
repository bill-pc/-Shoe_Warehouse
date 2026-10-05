<?php
// Thêm autoload vào đầu file controller để gọi thư viện Pusher
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../models/TicketModel.php';

class TicketController
{
    private $model;

    /**
     * Hàm khởi tạo: Tự động gọi TicketModel để tương tác với Database
     */
    public function __construct()
    {
        $this->model = new TicketModel();
        date_default_timezone_set('Asia/Ho_Chi_Minh');
    }

    /**
     * Chức năng: Middleware bảo vệ Route, kiểm tra quyền Quản lý.
     * Tác dụng: Đá văng user không phải là MANAGER ra khỏi các trang tạo phiếu.
     */
    private function checkManager()
    {
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'MANAGER') {
            $_SESSION['error'] = "Bạn không có quyền thực hiện chức năng này!";
            header("Location: index.php?page=dashboard");
            exit;
        }
    }

    /**
     * Chức năng: Middleware bảo vệ Route, kiểm tra quyền Nhân viên.
     * Tác dụng: Đá văng user không phải là STAFF ra khỏi các màn hình thao tác kho.
     */
    private function checkStaff()
    {
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'STAFF') {
            $_SESSION['error'] = "Bạn không có quyền truy cập trang này!";
            header("Location: index.php?page=dashboard");
            exit;
        }
    }

        // ======================================================
    // 0. CÁC HÀM GỌI BỘ ĐÀM (PUSHER) - REALTIME
    // ======================================================

    /**
     * Chức năng: Gửi thông báo Push Notification (Alert) trên giao diện nhân viên.
     */
    private function triggerPusherNotification($staff_id, $message)
    {
        // NẠP CẤU HÌNH PUSHER ĐỘNG
        $pusherConfig = require __DIR__ . '/../../config/pusherconfig.php';
        try {
            $options = array(
                'cluster' => $pusherConfig['cluster'],
                'useTLS' => true
            );

            $pusher = new Pusher\Pusher(
                $pusherConfig['key'],
                $pusherConfig['secret'],
                $pusherConfig['app_id'],
                $options
            );

            $pusher->trigger('warehouse-channel', 'new-ticket-' . $staff_id, [
                'message' => $message
            ]);
        } catch (Exception $e) {
            error_log("Lỗi gửi Pusher: " . $e->getMessage());
        }
    }

    /**
     * Chức năng: Đồng bộ màu sắc trạng thái thẻ Phiếu về máy Quản lý không cần F5.
     */
    private function triggerManagerSync($ticket_id, $status, $completed_at = null)
    {
        // NẠP CẤU HÌNH PUSHER ĐỘNG
        $pusherConfig = require __DIR__ . '/../../config/pusherconfig.php';
        try {
            $options = array(
                'cluster' => $pusherConfig['cluster'],
                'useTLS' => true
            );

            $pusher = new Pusher\Pusher(
                $pusherConfig['key'],
                $pusherConfig['secret'],
                $pusherConfig['app_id'],
                $options
            );

            $payload = [
                'ticket_id' => $ticket_id,
                'status' => $status
            ];

            if ($completed_at) {
                $payload['completed_at'] = $completed_at;
            }

            $pusher->trigger('warehouse-channel', 'ticket-status-changed', $payload);
        } catch (Exception $e) {
            error_log("Lỗi gửi Pusher Sync: " . $e->getMessage());
        }
    }

    /**
     * Chức năng: Phát yêu cầu Reset lại số lượng Badge Đỏ ngầm không làm phiền màn hình.
     */
    private function triggerPusherSilentUpdate($staff_id)
    {
        // NẠP CẤU HÌNH PUSHER ĐỘNG
        $pusherConfig = require __DIR__ . '/../../config/pusherconfig.php';
        try {
            if (!$staff_id) return;
            $options = array(
                'cluster' => $pusherConfig['cluster'],
                'useTLS' => true
            );

            $pusher = new Pusher\Pusher(
                $pusherConfig['key'],
                $pusherConfig['secret'],
                $pusherConfig['app_id'],
                $options
            );

            $pusher->trigger('warehouse-channel', 'refresh-badge-' . $staff_id, ['refresh' => true]);
        } catch (Exception $e) {
        }
    }


    // ======================================================
    // 1. CÁC HÀM XỬ LÝ GIAO DIỆN & SUBMIT FORM (TRUYỀN THỐNG)
    // ======================================================

    /**
     * Chức năng: Prepare Data để tải giao diện tạo phiếu mới.
     * Tác dụng: Đóng gói danh sách nhân viên, dữ liệu filter, mảng phiếu chờ trả về View.
     */
    public function create($type)
    {
        $staffs = $this->model->getStaffs();
        $brands = $this->model->getBrands();
        $auto_code = $this->model->generateTicketCode($type);

        // Sinh mã lô hàng tự động cho phiếu Xuất kho
        $auto_batch = '';
        if ($type === 'EXPORT') {
            $auto_batch = $this->model->generateExportBatchCode();
        }

        $suggestions = [];
        if ($type === 'IMPORT') {
            $suggestions = $this->model->getLowStockSuggestions();
        }

        $status = $_GET['filter_status'] ?? '';
        $start_date = !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
        $end_date = !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

        $manager_id = null;
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'MANAGER') {
            $manager_id = $_SESSION['user_id'];
        }
        $tickets = $this->model->getAllTickets($type, $status, $start_date, $end_date, $manager_id);

        return [
            'staffs' => $staffs,
            'brands' => $brands,
            'type' => $type,
            'auto_code' => $auto_code,
            'auto_batch' => $auto_batch, // Chuyển biến sang View
            'suggestions' => $suggestions,
            'tickets' => $tickets,
            'filter_status' => $status,
            'start_date' => $start_date,
            'end_date' => $end_date
        ];
    }


    /**
     * Chức năng: Lưu phiếu mới vào CSDL khi bấm Submit Form.
     * Tác dụng: Ép mảng sản phẩm vào DB và bắn Pusher báo có việc đến thiết bị nhân viên.
     */
    public function saveTicket()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $ticket_code = $_POST['ticket_code'];
            $batch_code = $_POST['batch_code'];
            $type = $_POST['ticket_type'];
            $staff_id = $_POST['staff_id'];
            $manager_id = $_SESSION['user_id'];

            $details = [];
            if (isset($_POST['variant_id']) && is_array($_POST['variant_id'])) {
                for ($i = 0; $i < count($_POST['variant_id']); $i++) {
                    $details[] = [
                        'variant_id' => $_POST['variant_id'][$i],
                        'quantity' => $_POST['quantity'][$i]
                    ];
                }
            }

            $result = $this->model->createTicket($ticket_code, $batch_code, $type, $manager_id, $staff_id, $details);

            if ($result === true) {
                $loaiPhieu = ($type === 'IMPORT') ? "Nhập kho" : "Xuất kho";
                $this->triggerPusherNotification($staff_id, "Quản lý vừa giao cho bạn 1 phiếu $loaiPhieu mới!");
                header("Location: index.php?page=ticket_create&type={$type}&msg=create_success");
                exit;
            } elseif ($result === 'out_of_stock') {
                header("Location: index.php?page=ticket_create&type={$type}&msg=out_of_stock");
                exit;
            } else {
                header("Location: index.php?page=ticket_create&type={$type}&msg=error");
                exit;
            }
        }
    }

    /**
     * Chức năng: Xem lịch sử các phiếu của riêng Staff truy cập.
     * Tác dụng: Hỗ trợ màn hình quản lý công tác cá nhân.
     */
    public function staffHistory()
    {
        $this->checkStaff();

        $staff_id = $_SESSION['user_id'];
        $status = $_GET['filter_status'] ?? '';
        $start_date = $_GET['start_date'] ?? '';
        $end_date = $_GET['end_date'] ?? '';

        $tickets = $this->model->getStaffTickets($staff_id, $status, $start_date, $end_date);

        return [
            'tickets' => $tickets,
            'filter_status' => $status,
            'start_date' => $start_date,
            'end_date' => $end_date
        ];
    }

    /**
     * Chức năng: Đổi chủ (Giao lại phiếu) cho nhân viên khác.
     * Tác dụng: Thay staff_id trong DB, bắn push alert cho người mới và push silent cập nhật icon cho người bị mất quyền.
     */
    public function reassignStaff()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $ticket_id = $_POST['ticket_id'];
            $new_staff_id = $_POST['new_staff_id'];
            $return_type = $_POST['return_type'];

            $old_staff_id = $this->model->updateStaffInTicket($ticket_id, $new_staff_id);

            if ($old_staff_id !== false) {
                $this->triggerPusherNotification($new_staff_id, "Bạn vừa được bàn giao xử lý 1 phiếu kho mới!");

                if ($old_staff_id && $old_staff_id != $new_staff_id) {
                    $this->triggerPusherSilentUpdate($old_staff_id);
                }

                header("Location: index.php?page=ticket_create&type={$return_type}&msg=reassign_success");
                exit;
            } else {
                header("Location: index.php?page=ticket_create&type={$return_type}&msg=error");
                exit;
            }
        }
    }

    /**
     * Chức năng: Xóa (Ẩn tạm thời) phiếu lỗi.
     * Tác dụng: Gọi Model đánh cờ Delete và thu hồi lại kho nếu là Phiếu Xuất.
     */
    public function deleteTicket()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $ticket_id = $_POST['ticket_id'];
            $return_type = $_POST['return_type'];

            $staff_id_to_refresh = $this->model->softDeleteTicket($ticket_id);

            if ($staff_id_to_refresh !== false) {
                if (is_numeric($staff_id_to_refresh)) {
                    $this->triggerPusherSilentUpdate($staff_id_to_refresh);
                }

                header("Location: index.php?page=ticket_create&type={$return_type}&msg=delete_success");
                exit;
            } else {
                header("Location: index.php?page=ticket_create&type={$return_type}&msg=error");
                exit;
            }
        }
    }

    // ======================================================
    // 2. CÁC HÀM AJAX CHUNG (JSON ENDPOINTS)
    // ======================================================

    /**
     * Chức năng: Lấy JSON danh sách Mẫu Giày lọc bằng Brand ID.
     * Tác dụng: Gọi từ jQuery để hiện các select option khi tạo phiếu.
     */
    public function getProductsByBrandAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        try {
            $brand_id = isset($_GET['brand_id']) ? intval($_GET['brand_id']) : 0;
            if (!$brand_id) {
                echo json_encode(["status" => "error", "message" => "Thiếu mã hãng."]);
                exit;
            }

            $products = $this->model->getProductsByBrand($brand_id);
            echo json_encode($products ?: []);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Lỗi hệ thống: " . $e->getMessage()]);
        }
        exit;
    }

    /**
     * Chức năng: Lấy JSON danh sách Size, Màu theo mã Sản phẩm.
     * Tác dụng: Auto fill các tùy chọn và hiển thị tồn kho hiện tại lên form nhập liệu.
     */
    public function getVariantsByProductAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        try {
            $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
            $type = isset($_GET['type']) ? $_GET['type'] : '';

            if (!$product_id) {
                echo json_encode(["status" => "error", "message" => "Thiếu mã sản phẩm."]);
                exit;
            }

            $variants = $this->model->getVariantsByProduct($product_id, $type);
            echo json_encode($variants ?: []);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Lỗi hệ thống: " . $e->getMessage()]);
        }
        exit;
    }

    /**
     * Chức năng: Cung cấp JSON mảng chi tiết toàn bộ các dòng hàng trong 1 Mã Phiếu.
     * Tác dụng: Vẽ lên bảng chi tiết (Modal xem phiếu) hoặc giao diện Quét Hàng.
     */
    public function getTicketDetailsAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        try {
            $ticket_id = isset($_GET['ticket_id']) ? intval($_GET['ticket_id']) : 0;

            if (!$ticket_id) {
                echo json_encode(["status" => "error", "message" => "Thiếu mã phiếu."]);
                exit;
            }

            $details = $this->model->getTicketDetails($ticket_id);
            echo json_encode($details ?: []);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Lỗi hệ thống: " . $e->getMessage()]);
        }
        exit;
    }

    /**
     * Chức năng: Request số lượng phiếu (Import/Export) đang mở để hiển thị Badge đỏ.
     * Tác dụng: Liên tục kiểm tra bằng JS trên Header Topbar báo hiệu công việc chưa xử lý.
     */
    public function getPendingCountsAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        $staff_id = $_SESSION['user_id'] ?? 0;
        if (!$staff_id) {
            echo json_encode(['import' => 0, 'export' => 0]);
            exit;
        }

        $counts = $this->model->getPendingCounts($staff_id);
        echo json_encode([
            'import' => $counts['IMPORT'] ?? 0,
            'export' => $counts['EXPORT'] ?? 0
        ]);
        exit;
    }

    /**
     * Chức năng: API Đổi trạng thái qua lại của bất kỳ loại Phiếu nào (Pending, Paused, v.v.).
     * Tác dụng: Hỗ trợ nhấn Cập nhật Nhanh không cần form gửi POST đi, cập nhật tức thì màn Quản lý.
     */
    public function updateStatusAjax()
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        $ticket_id = $_REQUEST['ticket_id'];
        $status = $_REQUEST['status'];

        $this->model->updateTicketStatus($ticket_id, $status);
        $this->triggerManagerSync($ticket_id, $status);

        echo json_encode(['status' => 'success']);
        exit;
    }
}
