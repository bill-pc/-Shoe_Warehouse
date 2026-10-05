<?php
require_once __DIR__ . '/../models/CategoryModel.php';

class CategoryController
{
    private $categoryModel;

    public function __construct()
    {
        $this->categoryModel = new CategoryModel();
    }

    // Hiển thị danh sách hãng
    public function loadCategories()
    {
        return $this->categoryModel->getAll();
    }

    // Kiểm tra quyền MANAGER
    private function checkManager()
    {
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'MANAGER') {
            $_SESSION['error'] = "Bạn không có quyền thực hiện chức năng này!";
            header("Location: index.php?page=dashboard");
            exit;
        }
    }

    /**
     * Xử lý thêm hãng mới kèm Logo
     */
    public function addBrand()
    {
        $this->checkManager();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim($_POST['category_name']);
            $name = trim($_POST['category_name']);

            // Kiểm tra trùng lặp tên Hãng giày (Category)
            if ($this->categoryModel->isCategoryExists($name)) {
                $_SESSION['error'] = "Thêm thất bại! Hãng giày '" . htmlspecialchars($name) . "' đã tồn tại trên hệ thống.";
                header("Location: index.php?page=categories");
                exit;
            }

            $logo_name = 'default_brand.jpg';

            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === 0) {
                $file_tmp = $_FILES['logo']['tmp_name'];
                $file_name = $_FILES['logo']['name'];
                $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                $allowed_extensions = ['jpg', 'jpeg', 'png'];

                if (in_array($extension, $allowed_extensions)) {
                    // Tạo tên file duy nhất
                    $new_logo_name = strtolower(str_replace(' ', '_', $name)) . '_' . time() . '.' . $extension;
                    $target_path = "assets/img_logo/" . $new_logo_name;

                    if (move_uploaded_file($file_tmp, $target_path)) {
                        // Nếu upload thành công, cập nhật lại biến $logo_name để lưu vào DB
                        $logo_name = $new_logo_name;
                    } else {
                        $_SESSION['error'] = "Không thể lưu file ảnh vào thư mục!";
                        header("Location: index.php?page=categories");
                        exit;
                    }
                } else {
                    $_SESSION['error'] = "Chỉ chấp nhận định dạng JPG, PNG hoặc WEBP!";
                    header("Location: index.php?page=categories");
                    exit;
                }
            }

            if (!empty($name)) {
                // Lấy ID người dùng đang đăng nhập từ Session
                $userId = $_SESSION['user_id'] ?? null;

                if ($this->categoryModel->create($name, $logo_name, $userId)) {
                    $_SESSION['success'] = "Đã thêm hãng $name thành công!";
                } else {
                    $_SESSION['error'] = "Lỗi Database rồi bồ ơi!";
                }
            }

            header("Location: index.php?page=categories");
            exit;
        }
    }

    /**
     * Xử lý xóa hãng (Soft Delete)
     */
    public function delete($id)
    {
        $this->checkManager();

        // RÀNG BUỘC: Kiểm tra nếu hãng còn sản phẩm hoạt động thì không cho xóa
        if ($this->categoryModel->hasProducts($id)) {
            $_SESSION['error'] = "Không thể xóa hãng giày này vì vẫn còn sản phẩm đang tồn tại!";
            header("Location: index.php?page=categories");
            exit;
        }

        if ($this->categoryModel->delete($id)) {
            $_SESSION['success'] = "Đã dọn dẹp hãng giày thành công!";
        } else {
            $_SESSION['error'] = "Xóa không được bồ ơi, kiểm tra lại Database xem!";
        }
        header("Location: index.php?page=categories");
        exit;
    }


    // bật tắt trạng thái kinh doanh
    public function toggleStatus()
    {
        $this->checkManager();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id = $_POST['category_id'];
            $current = $_POST['current_status'];
            $newStatus = ($current == 't' || $current == 1) ? 'f' : 't';

            if ($this->categoryModel->updateStatus($id, $newStatus)) {
                // Cập nhật luôn toàn bộ sản phẩm theo trạng thái mới của danh mục
                $productModel = new ProductModel();
                $productModel->updateStatusByCategory($id, $newStatus);

                $_SESSION['success'] = ($newStatus === 'f')
                    ? "Đã tạm ngưng hãng và toàn bộ sản phẩm liên quan!"
                    : "Hãng đã hoạt động lại, toàn bộ sản phẩm đã được bật theo!";
            }
            header("Location: index.php?page=categories");
            exit;
        }
    }


    /**
     * Thay đổi logo danh mục (Hãng giày)
     */
    public function updateLogo()
    {
        $this->checkManager();

        if (isset($_POST['category_id']) && isset($_FILES['category_logo'])) {
            try {
                $category_id = intval($_POST['category_id']);
                $imageFile = $_FILES['category_logo'];

                if ($imageFile['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Lỗi khi tải file ảnh lên!");
                }

                // Lấy thông tin hãng cũ để xóa ảnh logo cũ tránh chiếm bộ nhớ
                $cat = $this->categoryModel->getById($category_id);
                if (!$cat) {
                    throw new Exception("Hãng giày không tồn tại!");
                }

                $oldLogo = $cat['logo'];
                $name = $cat['category_name'];

                // 1. Lưu file logo mới vào assets/img_logo/
                $extension = strtolower(pathinfo($imageFile['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png'];

                if (!in_array($extension, $allowed_extensions)) {
                    throw new Exception("Chỉ chấp nhận định dạng JPG, JPEG, PNG!");
                }

                // Tạo tên logo duy nhất theo chuẩn
                $new_logo_name = strtolower(str_replace(' ', '_', $name)) . '_' . time() . '.' . $extension;
                $target_path = "assets/img_logo/" . $new_logo_name;

                if (!move_uploaded_file($imageFile['tmp_name'], $target_path)) {
                    throw new Exception("Lỗi lưu file logo mới trên máy chủ.");
                }

                // 2. Cập nhật vào CSDL
                if ($this->categoryModel->updateLogo($category_id, $new_logo_name)) {
                    // Xóa file ảnh cũ nếu không phải là ảnh mặc định
                    if (!empty($oldLogo) && $oldLogo !== 'default_brand.png' && $oldLogo !== 'default_brand.jpg') {
                        $oldPath = "assets/img_logo/" . $oldLogo;
                        if (file_exists($oldPath)) {
                            unlink($oldPath);
                        }
                    }
                    $_SESSION['success'] = "Đã cập nhật ảnh đại diện danh mục thành công!";
                } else {
                    if (file_exists($target_path)) unlink($target_path);
                    throw new Exception("Lỗi cập nhật ảnh danh mục vào Database!");
                }
            } catch (Exception $e) {
                $_SESSION['error'] = "Lỗi: " . $e->getMessage();
            }

            header("Location: index.php?page=categories");
            exit;
        }
    }
}
