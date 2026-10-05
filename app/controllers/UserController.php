<?php
require_once __DIR__ . '/../models/UserModel.php';

class UserController
{
    private $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }


    // Hiển thị trang danh sách nhân viên
    public function loadEmployees()
    {
        // Lấy dữ liệu từ GET request
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $role = isset($_GET['role']) ? trim($_GET['role']) : '';

        return $users = $this->userModel->getAllUsers($search, $role);
    }

    // Hàm kiểm tra quyền 
    private function checkManager()
    {
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'MANAGER') {
            $_SESSION['error'] = "Bạn không có quyền thực hiện chức năng này!";
            header("Location: index.php?page=dashboard");
            exit;
        }
    }

    // Hàm kiểm tra quyền ADMIN
    private function checkAdmin()
    {
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
            $_SESSION['error'] = "Bạn không có quyền thực hiện chức năng này!";
            header("Location: index.php?page=dashboard");
            exit;
        }
    }

    // Xử lý thêm nhân viên mới
    public function add()
    {
        $this->checkAdmin();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = [
                'full_name' => trim($_POST['full_name']),
                'password' => $_POST['password'],
                'role' => $_POST['role'],
                'status' => isset($_POST['status']) ? true : false
            ];

            // Gọi model để tạo mới nhân viên (ID sẽ được tự động sinh ngẫu nhiên 6 số bên trong Model)
            $random_id = $this->userModel->addUser($data);

            if ($random_id !== false) {
                $_SESSION['success'] = "Thêm nhân viên thành công! Mã ID nhân viên mới là: " . $random_id . ". Mật khẩu mặc định ban đầu là: 12345";
                header("Location: index.php?page=employees");
                exit;
            } else {
                $_SESSION['error'] = "Thêm nhân viên thất bại, vui lòng thử lại!";
                $users = $this->userModel->getAllUsers();
                require __DIR__ . '/../views/pages/employees.php';
            }
        }
    }



    // Xử lý xóa mềm nhân viên
    public function delete($user_id)
    {
        $this->checkAdmin();

        // Ngăn chặn tự xóa chính mìn
        if ($user_id == $_SESSION['user_id']) {
            $_SESSION['error'] = "Bạn không thể tự xóa chính mình đâu nha!";
            header("Location: index.php?page=employees");
            exit;
        }

        // Tiến hành xóa mềm
        if ($this->userModel->deleteUser($user_id)) {
            $_SESSION['success'] = "Đã xóa nhân viên thành công!";
        } else {
            $_SESSION['error'] = "Xóa nhân viên thất bại!";
        }
        header("Location: index.php?page=employees");
        exit;
    }


    // Xử lý cập nhật nhân viên
    public function update()
    {
        $this->checkAdmin();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $user_id = $_POST['user_id'];
            $status = isset($_POST['status']); // Checkbox được tích = true

            // Chặn tự khóa chính mình
            if ($user_id == $_SESSION['user_id'] && !$status) {
                $_SESSION['error'] = "Bạn không được tự vô hiệu hóa tài khoản của chính mình!";
            } else {
                if ($this->userModel->updateUserStatus($user_id, $status)) {
                    $_SESSION['success'] = "Đã cập nhật trạng thái nhân viên thành công!";
                } else {
                    $_SESSION['error'] = "Cập nhật thất bại, vui lòng thử lại.";
                }
            }
            header("Location: index.php?page=employees");
            exit;
        }
    }

    // lấy thông tin user
    public function getProfile()
    {
        $user_id = $_SESSION['user_id'];
        return $this->userModel->getUserById($user_id);
    }


    // cập nhật thông tin cá nhân
    public function UpdateProfile()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_update_profile'])) {
            $user_id = $_SESSION['user_id'];
            $old_pass = $_POST['old_password'];
            $is_ajax = isset($_POST['ajax_update']); // Kiểm tra cờ AJAX

            // 1. Kiểm tra mật khẩu cũ
            if (!$this->userModel->verifyOldPassword($user_id, $old_pass)) {
                $msg = "Mật khẩu xác thực không chính xác!";

                if ($is_ajax) {
                    if (ob_get_length()) ob_clean(); // Xóa sạch rác
                    header('Content-Type: application/json');
                    echo json_encode(["status" => "error", "message" => $msg]);
                    exit; // DỪNG LẠI NGAY, không cho chạy xuống dưới hay redirect
                }

                $_SESSION['error'] = $msg;
                header("Location: " . $_SERVER['HTTP_REFERER']);
                exit;
            }

            $data = [
                'full_name' => $_POST['full_name'],
                'phone_number' => $_POST['phone_number'],
                'address' => $_POST['address'],
                'new_password' => $_POST['new_password']
            ];


            // 2. Cập nhật
            if ($this->userModel->updateProfile($user_id, $data)) {
                $msg = "Cập nhật hồ sơ thành công!";
                if ($is_ajax) {
                    if (ob_get_length()) ob_clean();
                    header('Content-Type: application/json');
                    echo json_encode(["status" => "success", "message" => $msg]);
                    exit;
                }
                $_SESSION['success'] = $msg;
            } else {
                $msg = "Lỗi kỹ thuật, không lưu được dữ liệu.";
                if ($is_ajax) {
                    if (ob_get_length()) ob_clean();
                    header('Content-Type: application/json');
                    echo json_encode(["status" => "error", "message" => $msg]);
                    exit;
                }
                $_SESSION['error'] = $msg;
            }

            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
    }
}
