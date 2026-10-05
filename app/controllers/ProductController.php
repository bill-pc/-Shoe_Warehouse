<?php
require_once __DIR__ . '/../../ai_services/VisionService.php';

class ProductController
{
    private $productModel;

    /**
     * Chức năng: Hàm khởi tạo (Constructor).
     * Tác dụng: Tự động gọi ProductModel ngay khi Controller được khởi tạo để sẵn sàng tương tác với cơ sở dữ liệu.
     */
    public function __construct()
    {
        $this->productModel = new ProductModel();
    }

    /**
     * Chức năng: Middleware kiểm tra quyền Quản lý.
     * Tác dụng: Bảo vệ các chức năng nhạy cảm (Xóa, Tạo Vector mẫu, Điều chuyển kho). Nếu là Staff cố tình truy cập sẽ bị đá văng về trang chủ.
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
     * Chức năng: Middleware kiểm tra quyền Nhân viên.
     * Tác dụng: Đảm bảo chỉ Nhân viên (STAFF) mới có quyền thực hiện các thao tác nhập/xuất kho thực tế hằng ngày.
     */
    private function checkStaff()
    {
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'STAFF') {
            $_SESSION['error'] = "Chỉ nhân viên (STAFF) mới có quyền xuất kho!";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
    }

    /**
     * Chức năng: Hiển thị danh sách sản phẩm theo Hãng (Category).
     * Tác dụng: Truy vấn dữ liệu Sản phẩm mẹ, lồng ghép các Biến thể (Size/Màu) và ánh xạ tìm vị trí thực tế của đôi giày trên bản đồ Kệ kho (JSONB) để đổ ra giao diện.
     */
    public function showByCategory()
    {
        $category_id = isset($_GET['category_id']) ? $_GET['category_id'] : null;

        if (!$category_id) {
            header("Location: index.php?page=categories");
            exit;
        }

        $categoryName = $this->productModel->getCategoryName($category_id);
        $products = $this->productModel->getByCategory($category_id);

        if (!empty($products)) {
            // Nạp WarehouseModel và gọi hàm đã được di chuyển sang đây
            require_once __DIR__ . '/../models/WarehouseModel.php';
            $warehouseModel = new WarehouseModel();
            $allLocations = $warehouseModel->getAllShelvesLocationsMap();

            foreach ($products as &$pro) {
                $pro['variants'] = $this->productModel->getVariantsByProductId($pro['product_id']);

                $pro['locations'] = [];
                if (!empty($pro['variants'])) {
                    foreach ($pro['variants'] as $var) {
                        $vid = $var['variant_id'];
                        $pro['locations'][$vid] = $allLocations[$vid] ?? [];
                    }
                }
            }
        }

        return [
            'products' => $products,
            'categoryName' => $categoryName
        ];
    }

    /**
     * Chức năng: Đảo trạng thái kinh doanh của Sản phẩm mẹ.
     * Tác dụng: Ẩn/Hiện sản phẩm trên hệ thống. Có ràng buộc không cho phép bật Sản phẩm nếu Hãng gốc của nó đang bị tắt.
     */
    public function toggleStatus()
    {
        $this->checkManager();
        $id = $_POST['product_id'];
        $category_id = $_POST['category_id'];
        $currentStatus = $_POST['current_status'];
        $newStatus = ($currentStatus == 't' || $currentStatus == 1) ? 'f' : 't';

        if ($newStatus === 't') {
            $categoryModel = new CategoryModel();
            $parentCat = $categoryModel->getById($category_id);

            if ($parentCat['status'] == 'f' || $parentCat['status'] == 0) {
                $_SESSION['browser_alert'] = "Không thể bật! Hãng giày này đang ngừng kinh doanh. Bạn phải bật hãng trước nhé!";
                header("Location: " . $_SERVER['HTTP_REFERER']);
                exit;
            }
        }

        if ($this->productModel->updateStatus($id, $newStatus)) {
            $_SESSION['success'] = "Cập nhật trạng thái thành công!";
        }

        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }

    /**
     * Chức năng: Đưa sản phẩm vào thùng rác (Xóa mềm).
     * Tác dụng: Đánh dấu is_deleted = true. Giữ lại dữ liệu thực tế để phục vụ đối soát AI và lịch sử kế toán.
     */
    public function softDelete()
    {
        $this->checkManager();
        $id = $_POST['product_id'];

        // RÀNG BUỘC: Kiểm tra nếu sản phẩm còn biến thể hoạt động thì không cho xóa
        if ($this->productModel->hasVariants($id)) {
            $_SESSION['error'] = "Không thể xóa sản phẩm này vì vẫn còn biến thể đang tồn tại!";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }

        if ($this->productModel->delete($id)) {
            $_SESSION['success'] = "Đã dọn dẹp sản phẩm thành công!";
        } else {
            $_SESSION['error'] = "Xóa sản phẩm thất bại!";
        }
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }


    /**
     * Chức năng: Tìm kiếm trực tiếp sản phẩm (Giao tiếp qua AJAX).
     * Tác dụng: Hứng từ khóa từ thanh Topbar, tìm kiếm theo tên, màu, size, SKU và trả về mảng JSON để hiển thị gợi ý (Dropdown) mà không cần load lại trang.
     */
    public function ajaxSearch()
    {
        $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';

        if (empty($keyword)) {
            echo json_encode([]);
            exit;
        }

        $results = $this->productModel->searchProducts($keyword);
        header('Content-Type: application/json');
        echo json_encode($results ? $results : []);
        exit;
    }

    /**
     * Chức năng: Manager khai báo mẫu sản phẩm gốc.
     * Tác dụng: Tạo sản phẩm mới trong bảng 'products' và gọi AI để lưu mảng Vector chuẩn vào cột 'image_embedding'.
     */
    public function managerAddProduct()
    {
        $this->checkManager(); // Chặn Staff truy cập

        if (isset($_POST['btn_manager_add']) && isset($_FILES['master_image'])) {
            $db = $this->productModel->getConnection();
            pg_query($db, "BEGIN");

            try {
                // Lấy ID Hãng từ ô Select trong Modal
                $category_id = $_POST['category_id'];
                $product_name = trim($_POST['product_name']);
                $userId = $_SESSION['user_id'];

                if (empty($category_id)) throw new Exception("Vui lòng chọn Hãng sản xuất!");

                // 1. Lưu file ảnh chuẩn vào kho lưu trữ
                $imageFile = $_FILES['master_image'];
                $ext = pathinfo($imageFile['name'], PATHINFO_EXTENSION);
                // KIỂM TRA ĐỊNH DẠNG FILE CHỈ PHẢI LÀ JPG, PNG
                $allowed_extensions = ['jpg', 'jpeg', 'png'];
                if (!in_array($ext, $allowed_extensions)) {
                    throw new Exception("Định dạng file không hợp lệ! Chỉ chấp nhận ảnh .png hoặc .jpg");
                }
                $imageName = time() . '_master_' . uniqid() . '.' . ($ext ?: 'jpg');
                $targetPath = "assets/img_product/" . $imageName;

                if (!move_uploaded_file($imageFile['tmp_name'], $targetPath)) {
                    throw new Exception("Lỗi lưu file ảnh gốc.");
                }

                // 2. GỌI AI SINH VECTOR CHUẨN
                $vision = new VisionService();
                $aiResponse = $vision->generateVector($targetPath);

                if ($aiResponse['status'] !== 'success') {
                    if (file_exists($targetPath)) unlink($targetPath);
                    throw new Exception("AI không phân tích được ảnh: " . $aiResponse['message']);
                }
                $vectorArray = $aiResponse['vector'];

                // 3. TẠO SẢN PHẨM MẸ VÀ LƯU VECTOR VÀO DATABASE
                $productId = $this->productModel->create($product_name, $category_id, $imageName, $userId);
                if (!$productId) throw new Exception("Lỗi khi ghi dữ liệu sản phẩm!");

                // Cập nhật ADN hình ảnh vào cột image_embedding
                $this->productModel->updateImageEmbedding($productId, $vectorArray);

                pg_query($db, "COMMIT");
                $_SESSION['success'] = "Đã lưu mẫu sản phẩm và sinh Vector thành công!";
            } catch (Exception $e) {
                pg_query($db, "ROLLBACK");
                $_SESSION['error'] = "Lỗi: " . $e->getMessage();
            }

            // Quay lại đúng trang của hãng vừa thêm hoặc hãng đang xem
            header("Location: index.php?page=products&category_id=" . $category_id);
            exit;
        }
    }



    /**
     * Chức năng: Thay đổi ảnh đại diện sản phẩm & sinh lại Vector AI.
     * Tác dụng: Cho phép Manager tải lên ảnh mới cho sản phẩm có sẵn, gửi ảnh đến Flask AI để sinh Vector mới và cập nhật Database.
     */
    public function updateAvatar()
    {
        $this->checkManager();

        if (isset($_POST['product_id']) && isset($_FILES['product_image'])) {
            $db = $this->productModel->getConnection();
            pg_query($db, "BEGIN");

            try {
                $product_id = intval($_POST['product_id']);
                $imageFile = $_FILES['product_image'];

                if ($imageFile['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Lỗi khi tải file ảnh lên!");
                }

                // Lấy thông tin sản phẩm cũ để xóa ảnh cũ (tránh rác bộ nhớ)
                $sqlGet = "SELECT product_image, category_id FROM products WHERE product_id = $1";
                $resGet = pg_query_params($db, $sqlGet, [$product_id]);
                $pro = pg_fetch_assoc($resGet);
                if (!$pro) {
                    throw new Exception("Sản phẩm không tồn tại!");
                }

                $category_id = $pro['category_id'];
                $oldImage = $pro['product_image'];

                // 1. Lưu file ảnh mới vào assets/img_product/
                $ext = pathinfo($imageFile['name'], PATHINFO_EXTENSION);

                //  KIỂM TRA ĐỊNH DẠNG FILE CHỈ PHẢI LÀ JPG, PNG

                $allowed_extensions = ['jpg', 'jpeg', 'png'];
                if (!in_array($ext, $allowed_extensions)) {
                    throw new Exception("Định dạng file không hợp lệ! Chỉ chấp nhận ảnh .png hoặc .jpg");
                }
                $imageName = time() . '_master_' . uniqid() . '.' . ($ext ?: 'jpg');
                $targetPath = "assets/img_product/" . $imageName;

                if (!move_uploaded_file($imageFile['tmp_name'], $targetPath)) {
                    throw new Exception("Lỗi lưu file ảnh mới trên máy chủ.");
                }

                // 2. Gọi Flask AI Service sinh Vector mới từ ảnh mới
                $vision = new VisionService();
                $aiResponse = $vision->generateVector($targetPath);

                if ($aiResponse['status'] !== 'success') {
                    if (file_exists($targetPath)) unlink($targetPath);
                    throw new Exception("AI không phân tích được ảnh mới: " . $aiResponse['message']);
                }
                $vectorArray = $aiResponse['vector'];

                // 3. Cập nhật tên ảnh mới vào bảng products
                $sqlUpdate = "UPDATE products SET product_image = $1 WHERE product_id = $2";
                $resUpdate = pg_query_params($db, $sqlUpdate, [$imageName, $product_id]);
                if (!$resUpdate) {
                    throw new Exception("Lỗi cập nhật ảnh sản phẩm vào Database!");
                }

                // 4. Cập nhật Vector mới vào cột image_embedding
                $this->productModel->updateImageEmbedding($product_id, $vectorArray);

                // Xóa file ảnh cũ nếu không phải là ảnh mặc định để tiết kiệm bộ nhớ
                if (!empty($oldImage) && $oldImage !== 'default_shoe.png') {
                    $oldPath = "assets/img_product/" . $oldImage;
                    if (file_exists($oldPath)) {
                        unlink($oldPath);
                    }
                }

                pg_query($db, "COMMIT");
                $_SESSION['success'] = "Đã cập nhật ảnh đại diện và sinh lại Vector AI thành công!";
            } catch (Exception $e) {
                pg_query($db, "ROLLBACK");
                $_SESSION['error'] = "Lỗi: " . $e->getMessage();
            }

            // Điều hướng quay lại trang hãng sản phẩm
            header("Location: index.php?page=products&category_id=" . $category_id);
            exit;
        }
    }



    /**
     * CHỨC NĂNG DÀNH RIÊNG CHO MANAGER: Khai báo Biến thể (Màu/Size) mới
     * Tác dụng: Tạo SKU chuẩn và ghi nhận danh mục Màu/Size, tồn kho khởi điểm = 0.
     */
    /**
     * CHỨC NĂNG DÀNH RIÊNG CHO MANAGER: Khai báo Biến thể (Màu/Size) mới
     * Tác dụng: Tạo SKU chuẩn và ghi nhận danh mục Màu/Size, tồn kho khởi điểm = 0.
     */
    public function managerAddVariant()
    {
        $this->checkManager();
        if (isset($_POST['btn_add_variant'])) {
            $db = $this->productModel->getConnection();
            pg_query($db, "BEGIN");
            try {
                $product_id = $_POST['product_id'];
                $category_id = $_POST['category_id'];
                $brand_name = $_POST['brand_name'];
                $product_name = $_POST['product_name'];
                $color = $this->upperCaseColor($_POST['color']);
                $size = trim($_POST['size']);

                // RÀNG BUỘC SIZE GIÀY PHẢI LỚN HƠN 0 Ở BACKEND

                if (floatval($size) <= 0) {
                    throw new Exception("Size giày bắt buộc phải lớn hơn 0!");
                }
                // Kiểm tra xem biến thể này đã tồn tại chưa
                $existingVariant = $this->productModel->findVariant($product_id, $size, $color);
                if ($existingVariant) {
                    throw new Exception("Biến thể Màu [$color] - Size [$size] đã tồn tại trong mẫu này rồi!");
                }

                // Sinh mã SKU chuẩn
                $colorCode = $this->getColorCode($color);
                $cleanName = $this->removeAccents($product_name);

                // CHỈ LẤY CHỮ CÁI ĐẦU CỦA TÊN SẢN PHẨM (VD: Nike Air Force 1 -> NAF1)
                $initials = '';
                foreach (explode(' ', $cleanName) as $w) {
                    if (!empty($w)) $initials .= strtoupper(substr($w, 0, 1));
                }

                // GHÉP MÃ: 2 chữ đầu tên Hãng + Viết tắt SP + Mã Màu + Size
                $sku = strtoupper(substr($brand_name, 0, 2)) . "-" . $initials . "-" . $colorCode . "-" . $size;

                // Tạo biến thể với tồn kho = 0
                $resVar = $this->productModel->createVariant($product_id, $size, $color, 0, $sku);
                if (!$resVar) throw new Exception("Lỗi khi ghi dữ liệu biến thể vào hệ thống!");

                pg_query($db, "COMMIT");
                $_SESSION['success'] = "Đã khai báo thành công biến thể: $color - Size $size";
            } catch (Exception $e) {
                pg_query($db, "ROLLBACK");
                $_SESSION['error'] = "Lỗi: " . $e->getMessage();
            }

            header("Location: index.php?page=products&category_id=" . $_POST['category_id']);
            exit;
        }
    }

    /**
     * Chức năng: Lưu dữ liệu nhập kho thực tế (Dành cho STAFF).
     * Tác dụng: CHỈ CHO PHÉP nhập kho dựa trên dữ liệu Manager đã khai báo.
     * Cấm tuyệt đối việc tạo mẫu mới hoặc màu/size mới.
     */
    public function add()
    {
        $this->checkStaff();
        if (isset($_POST['add_product'])) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');

            $userId = $_SESSION['user_id'] ?? null;
            if (!$userId) {
                echo json_encode(["status" => "error", "message" => "Hết phiên đăng nhập!"]);
                exit;
            }

            $db = $this->productModel->getConnection();
            pg_query($db, "BEGIN");

            try {
                $brandName = isset($_POST['brand_name']) ? trim($_POST['brand_name']) : '';
                $product_name = trim($_POST['product_name']);
                $color = trim($this->upperCaseColor($_POST['color']));
                $size = trim($_POST['size']);
                $stock_input = intval($_POST['stock']);

                if (empty($brandName) || empty($product_name)) {
                    throw new Exception("Dữ liệu sản phẩm không hợp lệ!");
                }

                // 1. Kiểm tra Mẫu Giày (Product) đã được Manager khai báo chưa?
                $existingProduct = $this->productModel->findExistingProduct($brandName, $product_name);
                if (!$existingProduct) {
                    throw new Exception("Mẫu giày [$product_name] chưa được Quản lý khai báo. Bạn không có quyền khởi tạo mẫu mới!");
                }

                $productId = $existingProduct['product_id'];

                // 2. Kiểm tra Biến thể (Màu/Size) đã được Manager khai báo chưa?
                $existingVariant = $this->productModel->findVariant($productId, $size, $color);
                if (!$existingVariant) {
                    throw new Exception("Màu [$color] - Size [$size] chưa được Quản lý khai báo cho mẫu giày này! Vui lòng báo Quản lý thêm biến thể trước khi nhập kho.");
                }

                $variant_id = $existingVariant['variant_id'];

                // 3. Thực hiện DUY NHẤT 1 việc: Cộng dồn tồn kho
                $res = $this->productModel->addStock($variant_id, $stock_input);
                if (!$res) throw new Exception("Lỗi hệ thống khi cập nhật số lượng!");

                $hanoiTime = $this->getHanoiTime();
                $this->productModel->logTransaction('IMPORT', $variant_id, $stock_input, $userId, $hanoiTime);

                // 4. Lưu vị trí Kệ kho (JSONB)
                if (!empty($_POST['putaway_data'])) {
                    $putawayArray = json_decode($_POST['putaway_data'], true);
                    if (is_array($putawayArray) && count($putawayArray) > 0) {
                        $this->productModel->savePutawayToShelves($putawayArray, $variant_id);
                    }
                }

                pg_query($db, "COMMIT");
                echo json_encode(["status" => "success", "message" => "Đã nhập kho thành công $stock_input đôi ($color - $size) vào mẫu $product_name."]);
                exit;
            } catch (Exception $e) {
                pg_query($db, "ROLLBACK");
                echo json_encode(["status" => "error", "message" => $e->getMessage()]);
                exit;
            }
        }
    }

    /**
     * Chức năng: Helper mã hóa màu sắc ra chữ cái (VD: Trắng -> WHI).
     * Tác dụng: Phục vụ cho việc ghép nối tạo mã SKU chuyên nghiệp.
     */
    private function getColorCode($vnColor)
    {
        $map = [
            'Trắng' => 'WHI',
            'Đen' => 'BLA',
            'Đỏ' => 'RED',
            'Xanh dương' => 'BLU',
            'Xám' => 'GRE',
            'Xanh lá' => 'GRN',
            'Vàng' => 'YEL',
            'Hồng' => 'PIN',
            'Nâu' => 'BRO',
            'Cam' => 'ORA'
        ];
        return $map[$vnColor] ?? strtoupper(substr($this->removeAccents($vnColor), 0, 3));
    }

    /**
     * Chức năng: Chuẩn hóa tên màu sắc thành chữ in hoa toàn bộ (Hỗ trợ Unicode tiếng Việt).
     */
    private function upperCaseColor($color)
    {
        return mb_strtoupper(trim($color), 'UTF-8');
    }


    /**
     * Chức năng: Helper xóa dấu tiếng Việt.
     * Tác dụng: Dùng để làm gọn tên Hãng/SP chuẩn bị cho việc trích xuất ký tự làm mã SKU.
     */
    private function removeAccents($str)
    {
        $accents = array(
            'a' => 'á|à|ả|ã|ạ|ă|ắ|ằ|ẳ|ẵ|ặ|â|ấ|ầ|ẩ|ẫ|ậ',
            'd' => 'đ',
            'e' => 'é|è|ẻ|ẽ|ẹ|ê|ế|ề|ể|ễ|ệ',
            'i' => 'í|ì|ỉ|ĩ|ị',
            'o' => 'ó|ò|ỏ|õ|ọ|ô|ố|ồ|ổ|ỗ|ộ|ơ|ớ|ờ|ở|ỡ|ợ',
            'u' => 'ú|ù|ủ|ũ|ư|ứ|ừ|ử|ữ|ự',
            'y' => 'ý|ỳ|ỷ|ỹ|ỵ',
        );
        foreach ($accents as $non_accent => $accent) {
            $str = preg_replace("/($accent)/i", $non_accent, $str);
        }
        return $str;
    }


    /**
     * So khớp hình ảnh thực tế với Database (Dành cho STAFF).
     * Nhân viên up ảnh lô hàng, AI trích xuất Vector tạm và dò tìm trong Database (Khoảng cách Cosine) để xem nó giống với mẫu nào mà Manager đã khai báo.
     */
    public function scanWithAI()
    {
        ob_start();
        header('Content-Type: application/json');

        try {
            if (!isset($_FILES['images']) || empty($_FILES['images']['name'][0])) {
                throw new Exception("Không tìm thấy tệp tin hình ảnh.");
            }

            // Tải cấu hình tỉ lệ phần trăm nhận diện AI từ file config
            $configPath = __DIR__ . '/../../config/PercentMatching.php';
            $minSim = 0.80;
            $highSim = 0.95;
            if (file_exists($configPath)) {
                $matchingConfig = require $configPath;
                $minSim = isset($matchingConfig['min_similarity']) ? (float)$matchingConfig['min_similarity'] : 0.80;
                $highSim = isset($matchingConfig['high_similarity']) ? (float)$matchingConfig['high_similarity'] : 0.95;
            }

            $vision = new VisionService();
            $dir = 'assets/img_temp/';
            if (!is_dir($dir)) mkdir($dir, 0777, true);

            $results = [];
            $files = $_FILES['images'];
            $fileCount = count($files['name']);
            $limit = min($fileCount, 3); // Giới hạn batch size tránh cháy server

            // Đọc ticket_id gửi từ client lên để lọc gợi ý
            $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;

            for ($i = 0; $i < $limit; $i++) {
                $tmpName = $files['tmp_name'][$i];
                $tempImageName = time() . '_' . uniqid() . '.jpg';
                $tempPath = $dir . $tempImageName;

                if (!move_uploaded_file($tmpName, $tempPath)) {
                    $results[] = ["status" => "error", "message" => "Lỗi lưu file cục bộ."];
                    continue;
                }

                // 1. Phải gọi AI để lấy Vector từ ảnh tạm thì mới có cái để so sánh
                $aiResponse = $vision->generateVector($tempPath);

                if ($aiResponse['status'] === 'success') {
                    $vectorArray = $aiResponse['vector'];

                    // 2. Tìm kiếm trong Database xem có giống mẫu Manager khai báo không
                    $matches = $this->productModel->findTopMatchesByAI($vectorArray, 3);

                    // --- BỔ SUNG: BỘ LỌC THÔNG MINH THEO PHIẾU NHẬP ---
                    if ($ticket_id > 0 && !empty($matches)) {
                        $allowedNames = $this->productModel->getProductNamesInTicket($ticket_id);

                        $filteredMatches = [];
                        foreach ($matches as $match) {
                            $score = (float)$match['similarity_score'];
                            // Nếu độ tin cậy từ ngưỡng tối thiểu đến dưới ngưỡng khớp cao (đọc từ cấu hình)
                            if ($score >= $minSim && $score < $highSim) {
                                $matchName = mb_strtolower(trim($match['product_name']), 'UTF-8');
                                // Chỉ giữ lại nếu sản phẩm có tên khớp với tên trong phiếu nhập
                                if (in_array($matchName, $allowedNames)) {
                                    $filteredMatches[] = $match;
                                }
                            } else {
                                // Các mốc khác (>= $highSim hoặc < $minSim) giữ nguyên
                                $filteredMatches[] = $match;
                            }
                        }
                        $matches = $filteredMatches;
                    }
                    // ------------------------------------------------------------------

                    $itemData = [
                        "temp_image" => $tempImageName,
                        "matches"    => $matches,
                        "similarity" => 0,
                        "status"     => "new"
                    ];

                    if (!empty($matches)) {
                        $topMatch = $matches[0];
                        $topScore = (float)$topMatch['similarity_score'];
                        $itemData["similarity"] = round($topScore * 100, 2);

                        // The frontend can safely auto-select the variant when the
                        // product is present in the active ticket. Keep "match"
                        // for high-confidence results for compatibility.
                        if ($topScore >= $highSim) {
                            $itemData["status"] = "match";
                            $itemData["colors"] = $this->productModel->getColorsByProduct($topMatch['product_id']);
                            $itemData["product"] = $topMatch;
                        } else {
                            $itemData["status"] = "confirm";
                        }
                    }

                    $results[] = $itemData;
                } else {
                    $results[] = [
                        "status" => "error",
                        "message" => "AI Error: " . substr($aiResponse['message'], 0, 100)
                    ];
                }
            }

            ob_clean();
            echo json_encode(["status" => "success", "data" => $results]);
        } catch (Exception $e) {
            if (ob_get_length()) ob_clean();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        exit;
    }


    /**
     * Chức năng: API Phục vụ UI tự động điền Form.
     * Tác dụng: Trả về danh sách các màu đã được khai báo của một Product cụ thể.
     */
    public function getColorsAjax()
    {
        ob_start();
        header('Content-Type: application/json');

        try {
            $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
            $colors = ($product_id > 0) ? $this->productModel->getColorsByProduct($product_id) : [];
            ob_clean();
            echo json_encode($colors);
        } catch (Exception $e) {
            ob_clean();
            echo json_encode([]);
        }
        exit;
    }

    /**
     * Chức năng: Xuất kho thủ công bằng nút bấm trực tiếp.
     * Tác dụng: Xử lý trừ kho CSDL (PostgreSQL), dọn dẹp vị trí giày trong tủ kệ (Cập nhật chuỗi JSONB) và lưu lịch sử giao dịch.
     */
    public function exportStock()
    {
        $this->checkStaff();
        if (isset($_POST['export_stock'])) {
            $variant_id = $_POST['variant_id'];
            $quantity = intval($_POST['quantity']);
            $user_id = $_SESSION['user_id'];
            $category_id = $_POST['category_id'];

            $db = $this->productModel->getConnection();
            pg_query($db, "BEGIN");

            try {
                $res1 = $this->productModel->removeStock($variant_id, $quantity);
                if (!$res1 || pg_affected_rows($res1) == 0) throw new Exception("Không đủ tồn kho!");

                $isRemovedFromShelf = $this->productModel->removePutawayFromShelves($variant_id, $quantity);
                if (!$isRemovedFromShelf) {
                    throw new Exception("Lỗi đồng bộ: Không tìm thấy đủ số lượng giày này trên kệ để rút!");
                }

                $hanoiTime = $this->getHanoiTime();
                $res2 = $this->productModel->logTransaction('EXPORT', $variant_id, $quantity, $user_id, $hanoiTime);
                if (!$res2) throw new Exception("Lỗi ghi nhật ký giao dịch!");

                pg_query($db, "COMMIT");
                $_SESSION['success'] = "Xuất kho thành công, đã lưu nhật ký và dọn vị trí trên kệ!";
            } catch (Exception $e) {
                pg_query($db, "ROLLBACK");
                $_SESSION['error'] = "Lỗi: " . $e->getMessage();
            }

            header("Location: index.php?page=products&category_id=" . $category_id);
            exit;
        }
    }

    /**
     * Chức năng: Bật/Tắt trạng thái kinh doanh của riêng một biến thể (Màu/Size).
     * Tác dụng: Chặn kích hoạt lại (bật) nếu Sản phẩm hoặc Hãng giày cha đang bị tạm ngưng kinh doanh.
     */
    public function toggleVariantStatus()
    {
        $this->checkManager();

        if (isset($_POST['variant_id']) && isset($_POST['current_status'])) {
            $variant_id = $_POST['variant_id'];
            $currentStatus = $_POST['current_status'];
            $newStatus = ($currentStatus == 1 || $currentStatus == 't') ? 'false' : 'true';

            // CHẶN KÍCH HOẠT BIẾN THỂ NẾU SẢN PHẨM HOẶC HÃNG ĐANG BỊ TẮT
            if ($newStatus === 'true') {
                $sqlCheck = "SELECT p.status AS product_status, c.status AS category_status, p.product_name, c.category_name
                             FROM product_variants pv
                             JOIN products p ON pv.product_id = p.product_id
                             JOIN categories c ON p.category_id = c.category_id
                             WHERE pv.variant_id = $1";

                $resCheck = pg_query_params($this->productModel->getConnection(), $sqlCheck, [(int)$variant_id]);
                $rowCheck = $resCheck ? pg_fetch_assoc($resCheck) : null;

                if ($rowCheck) {
                    $cStatus = ($rowCheck['category_status'] === 't' || $rowCheck['category_status'] == 1 || $rowCheck['category_status'] === true);
                    $pStatus = ($rowCheck['product_status'] === 't' || $rowCheck['product_status'] == 1 || $rowCheck['product_status'] === true);

                    // 1. Chặn nếu Hãng đang bị tạm ngưng
                    if (!$cStatus) {
                        $_SESSION['browser_alert'] = "Không thể kích hoạt! Hãng giày '" . htmlspecialchars($rowCheck['category_name']) . "' đang ngừng kinh doanh. Bạn phải kích hoạt Hãng trước!";
                        header("Location: " . $_SERVER['HTTP_REFERER']);
                        exit;
                    }

                    // 2. Chặn nếu Sản phẩm mẹ đang bị tạm ngưng
                    if (!$pStatus) {
                        $_SESSION['browser_alert'] = "Không thể kích hoạt! Sản phẩm '" . htmlspecialchars($rowCheck['product_name']) . "' đang ngừng kinh doanh. Bạn phải kích hoạt Sản phẩm mẹ trước!";
                        header("Location: " . $_SERVER['HTTP_REFERER']);
                        exit;
                    }
                }
            }

            if ($this->productModel->updateVariantStatus($variant_id, $newStatus)) {
                $_SESSION['success'] = "Đã thay đổi trạng thái biến thể!";
            } else {
                $_SESSION['error'] = "Lỗi khi cập nhật trạng thái!";
            }
        }
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }

    /**
     * Chức năng: Đưa biến thể vào thùng rác.
     * Tác dụng: Đánh dấu xóa mềm, đồng thời càn quét giải phóng toàn bộ không gian mà biến thể này đang chiếm giữ trên tất cả các kệ kho.
     */
    public function deleteVariant()
    {
        $this->checkManager();

        if (isset($_POST['variant_id'])) {
            $variant_id = $_POST['variant_id'];
            $db = $this->productModel->getConnection();

            pg_query($db, "BEGIN");
            try {
                $res1 = $this->productModel->softDeleteVariant($variant_id);
                if (!$res1) throw new Exception("Lỗi khi xóa biến thể!");

                // Truyền tham số null -> Lệnh cho Model rút sạch sành sanh mọi kệ chứa Variant này
                $this->productModel->removePutawayFromShelves($variant_id, null);

                pg_query($db, "COMMIT");
                $_SESSION['success'] = "Đã xóa biến thể và giải phóng không gian kệ thành công!";
            } catch (Exception $e) {
                pg_query($db, "ROLLBACK");
                $_SESSION['error'] = "Lỗi: " . $e->getMessage();
            }
        }

        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }

    /**
     * Chức năng: Căn chỉnh múi giờ.
     * Tác dụng: Đảm bảo thời gian log ghi vào Postgres luôn là giờ Việt Nam bất kể máy chủ đang host ở đâu.
     */
    private function getHanoiTime()
    {
        date_default_timezone_set('Asia/Ho_Chi_Minh');
        return date('Y-m-d H:i:s');
    }

    /**
     * Chức năng: Lấy danh sách size hiện có để UI AI Export gọi.
     * Tác dụng: Trả về JSON chứa size và tồn kho dựa theo Product và Màu.
     */
    public function getSizesAjax()
    {
        ob_start();
        header('Content-Type: application/json');

        try {
            $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
            $color = isset($_GET['color']) ? trim($_GET['color']) : '';

            if ($product_id <= 0 || empty($color)) {
                echo json_encode([]);
                exit;
            }

            $sql = "SELECT variant_id, size, stock FROM product_variants 
                    WHERE product_id = $1 AND color ILIKE $2 AND is_deleted = false AND stock > 0
                    ORDER BY size ASC";

            $res = pg_query_params($this->productModel->getConnection(), $sql, [$product_id, $color]);
            $variants = $res ? pg_fetch_all($res) : [];

            ob_clean();
            echo json_encode($variants ?: []);
        } catch (Exception $e) {
            ob_clean();
            echo json_encode([]);
        }
        exit;
    }

    /**
     * Chức năng: Xử lý xuất hàng loạt bằng giao diện AI Export.
     * Tác dụng: Hứng luồng array (nhiều màu, nhiều size) từ Front-end gửi lên, duyệt qua từng món để trừ DB và dọn kệ kho, sau đó đóng gói trả về JSON.
     */
    public function exportByAI()
    {
        $this->checkStaff();

        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');

        if (isset($_POST['export_stock_ai_multi'])) {
            $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

            $colors = isset($_POST['colors']) ? $_POST['colors'] : [];
            $sizes = isset($_POST['sizes']) ? $_POST['sizes'] : [];
            $quantities = isset($_POST['quantities']) ? $_POST['quantities'] : [];

            $user_id = $_SESSION['user_id'];

            if (empty($colors) || count($colors) !== count($sizes)) {
                echo json_encode(["status" => "error", "message" => "Dữ liệu gửi lên không hợp lệ."]);
                exit;
            }

            $db = $this->productModel->getConnection();
            pg_query($db, "BEGIN");

            try {
                $hanoiTime = $this->getHanoiTime();

                for ($i = 0; $i < count($colors); $i++) {
                    $color = trim($colors[$i]);
                    $size = trim($sizes[$i]);
                    $quantity = intval($quantities[$i]);

                    if ($quantity <= 0) throw new Exception("Số lượng xuất ở một dòng không hợp lệ.");

                    $variant = $this->productModel->findVariant($product_id, $size, $color);
                    if (!$variant) {
                        throw new Exception("Không tìm thấy Size {$size} - Màu {$color} trong kho!");
                    }

                    $variant_id = $variant['variant_id'];

                    $res1 = $this->productModel->removeStock($variant_id, $quantity);
                    if (!$res1 || pg_affected_rows($res1) == 0) {
                        throw new Exception("Không đủ tồn kho cho Size {$size} - Màu {$color}!");
                    }

                    $isRemovedFromShelf = $this->productModel->removePutawayFromShelves($variant_id, $quantity);
                    if (!$isRemovedFromShelf) {
                        throw new Exception("Lỗi đồng bộ sơ đồ kho đối với Size {$size} - Màu {$color}!");
                    }

                    $res2 = $this->productModel->logTransaction('EXPORT', $variant_id, $quantity, $user_id, $hanoiTime);
                    if (!$res2) {
                        throw new Exception("Lỗi hệ thống: Không thể ghi nhận lịch sử giao dịch.");
                    }
                }

                pg_query($db, "COMMIT");
                echo json_encode(["status" => "success"]);
            } catch (Exception $e) {
                pg_query($db, "ROLLBACK");
                echo json_encode(["status" => "error", "message" => $e->getMessage()]);
            }
            exit;
        }
    }
}
