<?php
require_once __DIR__ . '/config/database.php';

// 1. Ép kiểu dữ liệu về Integer. "undefined" hoặc rỗng sẽ trở thành 0.
$vid = (int)($_GET['vid'] ?? 0);

if ($vid <= 0) {
    die("<h3 style='text-align:center; margin-top:50px; color:red;'>LỖI: MÃ SẢN PHẨM KHÔNG HỢP LỆ!</h3>");
}

$conn = getConnection();

// 2. Tối ưu truy vấn: Chỉ lấy thông tin sản phẩm (ảnh, tên hãng, tên sản phẩm, màu sắc, size)
$sql = "SELECT 
            p.product_name, p.product_image,
            c.category_name as brand,
            pv.color, pv.size
        FROM product_variants pv
        JOIN products p ON pv.product_id = p.product_id
        JOIN categories c ON p.category_id = c.category_id
        WHERE pv.variant_id = $1";

$res = pg_query_params($conn, $sql, [$vid]);
if (!$res) {
    die("<h3 style='text-align:center; margin-top:50px; color:red;'>LỖI TRUY VẤN HỆ THỐNG!</h3>");
}

$item = pg_fetch_assoc($res);

// 3. Xử lý trường hợp không tìm thấy sản phẩm trong hệ thống
if (!$item) {
    die("
        <div style='text-align:center; margin-top:100px; font-family:sans-serif; padding: 20px;'>
            <h2 style='color:#e74c3c;'>SẢN PHẨM KHÔNG TỒN TẠI</h2>
            <p>Không tìm thấy thông tin sản phẩm cho mã QR này trong hệ thống.</p>
            <a href='javascript:history.back()' style='text-decoration:none; color:#007bff;'>← Quay lại</a>
        </div>
    ");
}

$imgName = trim($item['product_image']);
$imgSrc = $imgName ? "/Shoe_Warehouse/public/assets/img_product/" . rawurlencode($imgName) : "/Shoe_Warehouse/public/assets/images/placeholder.png";
// Nhận các thông số nhập kho bổ sung từ QR URL
$import_date = $_GET['import_date'] ?? null;
$staff_id = $_GET['staff_id'] ?? null;
$staff_name = $_GET['staff_name'] ?? null;

?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thông tin sản phẩm</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }

        .product-card {
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            background: #fff;
            transition: transform 0.3s ease;
        }

        .product-card:hover {
            transform: translateY(-5px);
        }

        .product-img-wrapper {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #eee;
        }

        .product-img {
            max-width: 100%;
            max-height: 280px;
            object-fit: contain;
            filter: drop-shadow(0 5px 15px rgba(0, 0, 0, 0.08));
        }

        .brand-badge {
            background: #e9ecef;
            color: #495057;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 6px 12px;
            border-radius: 50px;
            display: inline-block;
        }

        .spec-badge {
            background: #f1f3f5;
            color: #212529;
            font-weight: 500;
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #e9ecef;
        }

        .spec-label {
            color: #868e96;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
    </style>
</head>

<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="text-center mb-4">
                    <h3 class="fw-bold text-dark"><i class="fas fa-qrcode text-primary me-2"></i> TRUY XUẤT SẢN PHẨM</h3>
                    <p class="text-muted small">Thông tin chi tiết được truy xuất từ hệ thống</p>
                </div>

                <div class="card product-card border-0">
                    <div class="product-img-wrapper">
                        <img src="<?= $imgSrc ?>" onerror="this.src='/Shoe_Warehouse/public/assets/images/placeholder.png'" class="product-img" alt="Product Image">
                    </div>
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <span class="brand-badge mb-2"><?= htmlspecialchars($item['brand']) ?></span>
                            <h4 class="fw-bold text-dark mb-3"><?= htmlspecialchars($item['product_name']) ?></h4>
                        </div>

                        <div class="row g-3">
                            <div class="col-6">
                                <div class="spec-badge w-100 justify-content-center">
                                    <i class="fas fa-palette text-secondary"></i>
                                    <div>
                                        <span class="spec-label d-block text-start">Màu sắc</span>
                                        <strong><?= htmlspecialchars($item['color']) ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="spec-badge w-100 justify-content-center">
                                    <i class="fas fa-ruler-combined text-secondary"></i>
                                    <div>
                                        <span class="spec-label d-block text-start">Kích thước</span>
                                        <strong>Size <?= htmlspecialchars($item['size']) ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="spec-badge w-100 justify-content-center">
                                    <i class="fas fa-barcode text-secondary"></i>
                                    <div>
                                        <span class="spec-label d-block text-start">Mã QR</span>
                                        <strong><?= htmlspecialchars($vid) ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($import_date): ?>
                            <hr class="my-4 text-muted">

                            <div class="text-center">
                                <h5 class="fw-bold text-success mb-3">
                                    <i class="fas fa-check-circle me-2"></i>ĐÃ NHẬP KHO
                                </h5>
                                <p class="mb-2"><strong class="text-dark">Ngày nhập:</strong> <span class="text-secondary"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($import_date))) ?></span></p>
                                <?php if ($staff_id): ?>
                                    <p class="mb-2"><strong class="text-dark">Mã nhân viên nhập:</strong> <span class="text-secondary">#<?= htmlspecialchars($staff_id) ?></span></p>
                                <?php endif; ?>
                                <?php if ($staff_name): ?>
                                    <p><strong class="text-dark">Nhân viên thực hiện:</strong> <span class="text-secondary"><?= htmlspecialchars($staff_name) ?></span></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                    </div>
                    <div class="card-footer bg-white border-0 text-center pb-4">
                        <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm px-4 rounded-pill">
                            <i class="fas fa-arrow-left me-1"></i> Quay lại
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>