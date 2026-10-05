<?php
ob_start(); // BỔ SUNG QUAN TRỌNG: Ngăn chặn lỗi header
session_start();

// 1. XỬ LÝ AJAX TẠI ĐÂY (TRƯỚC KHI HIỆN GIAO DIỆN)

if (isset($_GET['page'])) {

    if ($_GET['page'] === 'tickets' && isset($_GET['action'])) {
        require_once '../config/database.php';
        require_once '../app/models/TicketModel.php';
        require_once '../app/controllers/TicketController.php';
        require_once '../app/models/ExportModel.php';
        require_once '../app/controllers/ExportController.php';
        require_once '../app/models/ImportModel.php';
        require_once '../app/controllers/ImportController.php';

        $ticketAjax = new TicketController();
        $exportAjax = new ExportController();
        $importAjax = new ImportController();

        // 1. ROUTE PHIẾU CHUNG
        if ($_GET['action'] === 'get_products') {
            $ticketAjax->getProductsByBrandAjax();
            exit;
        }
        if ($_GET['action'] === 'get_variants') {
            $ticketAjax->getVariantsByProductAjax();
            exit;
        }
        if ($_GET['action'] === 'get_ticket_details') {
            $ticketAjax->getTicketDetailsAjax();
            exit;
        }
        if ($_GET['action'] === 'get_pending_counts') {
            $ticketAjax->getPendingCountsAjax();
            exit;
        }
        if ($_GET['action'] === 'update_status') {
            $ticketAjax->updateStatusAjax();
            exit;
        }

        // 2. ROUTE XUẤT KHO
        if ($_GET['action'] === 'get_my_exports') {
            $exportAjax->getMyExportsAjax();
            exit;
        }
        if ($_GET['action'] === 'update_export_progress') {
            $exportAjax->updateExportProgressAjax();
            exit;
        }
        if ($_GET['action'] === 'complete_export') {
            $exportAjax->completeExportAjax();
            exit;
        }
        if ($_GET['action'] === 'get_locations') {
            $exportAjax->getLocationsAjax();
            exit;
        }

        // 3. ROUTE NHẬP KHO
        if ($_GET['action'] === 'get_my_imports') {
            $importAjax->getMyImportsAjax();
            exit;
        }
        if ($_GET['action'] === 'save_temp_import') {
            $importAjax->saveTempImportAjax();
            exit;
        }
        if ($_GET['action'] === 'complete_import') {
            $importAjax->completeImportAjax();
            exit;
        }
        if ($_GET['action'] === 'startImportProcessAjax') {
            $importAjax->startImportProcessAjax();
            exit;
        }
        if ($_GET['action'] === 'updateTempImportAjax') {
            $importAjax->updateTempImportAjax();
            exit;
        }
        if ($_GET['action'] === 'decreaseTempImportAjax') {
            $importAjax->decreaseTempImportAjax();
            exit;
        }
        if ($_GET['action'] === 'finalizeImportAjax') {
            $importAjax->finalizeImportAjax();
            exit;
        }
        // ĐÂY LÀ ROUTE LẤY KỆ BỊ THIẾU:
        if ($_GET['action'] === 'get_putaway_locations') {
            $importAjax->getPutawayLocationsAjax();
            exit;
        }
    }

    if ($_GET['page'] === 'history-detail') {
        require_once __DIR__ . '/../app/controllers/TransactionController.php';
        $controller = new TransactionController();
        $controller->getDetailsAjax();
        exit;
    }

    // THÊM ĐOẠN NÀY CHO BÁO CÁO
    if ($_GET['page'] === 'report-detail') {
        require_once __DIR__ . '/../app/controllers/ReportController.php';
        $controller = new ReportController();
        $controller->getReportDetailsAjax();
        exit;
    }

    // --- BỔ SUNG: CHUYỂN CÁC YÊU CẦU AJAX CỦA PRODUCT LÊN ĐÂY ---
    if ($_GET['page'] === 'products' && isset($_GET['action'])) {
        require_once '../config/database.php';
        require_once '../app/models/ProductModel.php';
        require_once '../app/models/CategoryModel.php';
        require_once '../app/controllers/ProductController.php';

        $productControllerAjax = new ProductController();

        // 1. Action dành cho Manager (Thêm mẫu & Biến thể)
        if ($_GET['action'] === 'manager_add') {
            $productControllerAjax->managerAddProduct();
            exit;
        }
        if ($_GET['action'] === 'manager_add_variant') {
            $productControllerAjax->managerAddVariant();
            exit;
        }

        if ($_GET['action'] === 'getColorsAjax') {
            $productControllerAjax->getColorsAjax();
            exit;
        }
        if ($_GET['action'] === 'getSizesAjax') {
            $productControllerAjax->getSizesAjax();
            exit;
        }
        if ($_GET['action'] === 'export-ai') {
            $productControllerAjax->exportByAI();
            exit;
        }
        if ($_GET['action'] === 'scan-ai') {
            $productControllerAjax->scanWithAI();
            exit;
        }
        if ($_GET['action'] === 'get_mini_heatmap') {
            require_once '../app/models/WarehouseModel.php';
            require_once '../app/controllers/WarehouseController.php';
            $warehouseAjax = new WarehouseController();
            $warehouseAjax->getMiniWarehouseMap();
            exit;
        }
        if ($_GET['action'] === 'get_locations') {
            require_once '../app/models/ExportModel.php';
            require_once '../app/controllers/ExportController.php';
            $exportLocAjax = new ExportController();
            $exportLocAjax->getLocationsAjax();
            exit;
        }

        // FIX LỖI: Cần require và khởi tạo Warehouse riêng ở khối này vì nó tách biệt
        if ($_GET['action'] === 'search_map') {
            require_once '../app/models/WarehouseModel.php';
            require_once '../app/controllers/WarehouseController.php';
            $warehouseAjax = new WarehouseController();
            $warehouseAjax->ajaxSearchMap();
            exit;
        }
        if ($_GET['action'] === 'move_location') {
            require_once '../app/models/WarehouseModel.php';
            require_once '../app/controllers/WarehouseController.php';
            $warehouseAjax = new WarehouseController();
            $warehouseAjax->processMoveLocation();
            exit;
        }
    }
    // -----------------------------------------------------------
    // --- CHỨC NĂNG AJAX WAREHOUSE MAP (BỔ SUNG) ---
    if ($_GET['page'] === 'warehouse_map' && isset($_GET['action'])) {
        require_once '../config/database.php';
        require_once '../app/models/WarehouseModel.php';
        require_once '../app/controllers/WarehouseController.php';
        $warehouseAjax = new WarehouseController();

        if ($_GET['action'] === 'add_shelf') {
            $warehouseAjax->addShelfAjax();
            exit;
        }
        if ($_GET['action'] === 'delete_shelf') {
            $warehouseAjax->deleteShelfAjax();
            exit;
        }
        if ($_GET['action'] === 'toggle_shelf') {
            $warehouseAjax->toggleShelfAjax();
            exit;
        }
        if ($_GET['action'] === 'rename_shelf') {
            $warehouseAjax->renameShelfAjax();
            exit;
        }
    }
}




// 1. Gọi Database & Models trước
require_once '../config/database.php';
require_once '../app/models/UserModel.php';
require_once '../app/models/CategoryModel.php';
require_once '../app/models/ProductModel.php';
require_once '../app/models/ReportModel.php';
require_once '../app/models/WarehouseModel.php'; // ĐÃ THÊM

// 2. Gọi Controllers
require_once '../app/controllers/AuthController.php';
require_once '../app/controllers/UserController.php';
require_once '../app/controllers/CategoryController.php';
require_once '../app/controllers/ProductController.php';
require_once '../app/controllers/ReportController.php';
require_once '../app/controllers/WarehouseController.php';
require_once '../app/models/TicketModel.php';
require_once '../app/controllers/TicketController.php';


// ===== XỬ LÝ LOGIN (Phải xử lý trước khi kiểm tra Session) =====
$authController = new AuthController();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SESSION['user_id'])) {
    $error = $authController->handleLogin();
}

// ===== KIỂM TRA ĐĂNG NHẬP =====
if (!isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../app/views/pages/login.php';
    exit;
}

// ===== KHỞI TẠO CÁC CONTROLLER CÒN LẠI (Chỉ khi đã đăng nhập thành công) =====
$userController = new UserController();
$categoryController = new CategoryController();
$productController = new ProductController();
$reportController = new ReportController();
$warehouse_mapController = new WarehouseController(); // BÂY GIỜ GỌI SẼ KHÔNG LỖI NỮA
$ticketController = new TicketController();

// ===== XỬ LÝ LOGOUT =====
if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// ===== XỬ LÝ AJAX KHÁC =====
if ($page === 'search_ajax') {
    $productController->ajaxSearch();
    exit;
}

// ===== 4. XỬ LÝ CÁC YÊU CẦU POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($page === 'employees') {
        if (isset($_POST['add_user'])) {
            $userController->add();
            exit;
        } elseif (isset($_POST['delete'])) {
            $userController->delete($_POST['user_id']);
            exit;
        } elseif (isset($_POST['update'])) {
            $userController->update();
            exit;
        }
    } elseif ($page === 'categories') {
        if (isset($_POST['add_category'])) {
            $categoryController->addBrand();
            exit;
        } elseif (isset($_POST['delete'])) {
            $categoryController->delete($_POST['category_id']);
            exit;
        } elseif (isset($_POST['toggle_status'])) {
            $categoryController->toggleStatus();
            exit;
        } elseif (isset($_POST['btn_update_logo'])) {
            $categoryController->updateLogo();
            exit;
        }
    } elseif ($page === 'products') {
        // Nút của Staff (Nhập kho qua AI - Chỉ cộng dồn)
        if (isset($_POST['add_product'])) {
            $productController->add();
            exit;
        }
        // Nút của Manager (Thay đổi ảnh đại diện sản phẩm & sinh lại Vector AI)
        if (isset($_POST['btn_update_avatar'])) {
            $productController->updateAvatar();
            exit;
        }
        // Nút của Manager (Khai báo mẫu mới)
        if (isset($_POST['btn_manager_add'])) {
            $productController->managerAddProduct();
            exit;
        }
        // Nút của Manager (Thêm biến thể Màu/Size)
        if (isset($_POST['btn_add_variant'])) {
            $productController->managerAddVariant();
            exit;
        }
        if (isset($_POST['toggle_status'])) {
            $productController->toggleStatus();
            exit;
        }
        if (isset($_POST['delete_product'])) {
            $productController->softDelete();
            exit;
        }
        if (isset($_POST['export_stock'])) {
            $productController->exportStock();
            exit;
        }
    } elseif ($page === 'report') {
        if (isset($_POST['btn_filter_date'])) {
            $reportController->filterByDate($_POST['start_date'], $_POST['end_date']);
            exit;
        }
    } elseif ($page === 'ticket_create') {
        if (isset($_POST['save_ticket'])) {
            $ticketController->saveTicket();
            exit;
        }
        // Xử lý Xóa mềm (từ form nút Xóa thùng rác)
        elseif (isset($_POST['delete_ticket'])) {
            $ticketController->deleteTicket();
            exit;
        }
    } elseif ($page === 'ticket_reassign') {
        // Xử lý Đổi nhân viên (từ form của Modal)
        $ticketController->reassignStaff();
        exit;
    }

    if (isset($_POST['btn_update_profile'])) {
        $userController->UpdateProfile();
        exit;
    }
}

// CÁC URL GET ACTION XÓA/SỬA
if ($page === 'products') {
    if (isset($_GET['action']) && $_GET['action'] === 'toggle_variant_status') {
        $productController->toggleVariantStatus();
        exit;
    }
    if (isset($_GET['action']) && $_GET['action'] === 'delete_variant') {
        $productController->deleteVariant();
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Warehouse</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    <link rel="stylesheet" href="assets/css/header.css">
    <link rel="stylesheet" href="assets/css/categories.css">
</head>

<body class="m-0 p-0">
    <div class="app">
        <?php require_once __DIR__ . '/../app/views/layouts/sidebar.php'; ?>
        <div class="main">
            <?php include __DIR__ . '/../app/views/layouts/header.php'; ?>
            <div class="content">
                <div class="page-body">
                    <?php
                    switch ($page) {
                        case 'get_brand_data':
                            $reportController->getBrandDataAjax();
                            break;

                        case 'get_product_data':
                            $reportController->getProductDataAjax();
                            break;

                        case 'get_variant_data':
                            $reportController->getVariantDataAjax();
                            break;

                        case 'employees':
                            if ($_SESSION['role'] !== 'ADMIN') {
                                header("Location: index.php?page=dashboard");
                                exit;
                            }
                            $users = $userController->loadEmployees();
                            require_once __DIR__ . '/../app/views/pages/employees.php';
                            break;

                        case 'categories':
                            $categories = $categoryController->loadCategories();
                            require_once __DIR__ . '/../app/views/pages/categories.php';
                            break;

                        case 'products':
                            $data = $productController->showByCategory();
                            $products = $data['products'];
                            $categoryName = $data['categoryName'];
                            require_once __DIR__ . '/../app/views/pages/products.php';
                            break;

                        case 'report':
                            $data = $reportController->statistics();
                            extract($data);
                            require_once __DIR__ . '/../app/views/pages/report.php';
                            break;

                        case 'history':
                            require_once __DIR__ . '/../app/controllers/TransactionController.php';
                            $controller = new TransactionController();
                            $data = $controller->index();
                            extract($data);
                            require_once __DIR__ . '/../app/views/pages/history.php';
                            break;

                        case 'warehouse_map':
                            // GỌI HÀM TỪ WAREHOUSE CONTROLLER
                            $warehouseData = $warehouse_mapController->index();
                            extract($warehouseData);
                            require_once __DIR__ . '/../app/views/pages/warehouse_map.php';
                            break;

                        case 'dashboard':
                        case 'default':
                        default:
                            if (isset($_SESSION['role']) && $_SESSION['role'] === 'ADMIN') {
                                header("Location: index.php?page=employees");
                                exit;
                            }
                            $data = $reportController->index();
                            extract($data);
                            require_once __DIR__ . '/../app/views/pages/dashboard.php';
                            break;


                        case 'ticket_create':
                            $data = $ticketController->create($_GET['type'] ?? 'IMPORT');
                            extract($data);
                            require_once __DIR__ . '/../app/views/pages/create_tickets.php';
                            break;

                        case 'staff_ticket_history':
                            if ($_SESSION['role'] !== 'STAFF') {
                                header("Location: index.php?page=dashboard");
                                exit;
                            }
                            $data = $ticketController->staffHistory();
                            extract($data);
                            require_once __DIR__ . '/../app/views/pages/history_ticket_staff.php';
                            break;
                    }
                    ?>
                </div>
            </div>
            <?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>