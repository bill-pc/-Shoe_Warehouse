<link rel="stylesheet" href="assets/css/product.css">
<?php include __DIR__ . '/../layouts/topbar.php'; ?>
<?php
// Khai báo giá trị mặc định để báo cho VS Code biết biến này có tồn tại.
// Khi chạy thật, Controller sẽ truyền dữ liệu vào và ghi đè các giá trị này.
$categoryName = $categoryName ?? 'Chưa rõ danh mục';
$products = $products ?? [];
$cat = $cat ?? ['categoryName' => 'Chưa rõ'];
?>
<div class="container-fluid p-0">
    <?php if (isset($_SESSION['success'])): ?>
        <script>
            // Đợi giao diện load xong hoàn toàn
            document.addEventListener("DOMContentLoaded", function() {
                // Delay 100ms để trình duyệt chắc chắn đã vẽ xong UI
                setTimeout(function() {
                    alert(<?= json_encode($_SESSION['success']) ?>);
                }, 100);
            });
        </script>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                setTimeout(function() {
                    alert(<?= json_encode($_SESSION['error']) ?>);
                }, 100);
            });
        </script>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="index.php?page=categories" class="text-decoration-none text-muted">Danh mục</a></li>
                    <li class="breadcrumb-item active fw-bold" style="color: #000000;"><?= $categoryName ?></li>
                </ol>
            </nav>
            <h1 class="page-title fw-bold text-dark mb-0">Kho giày <?= $categoryName ?></h1>
        </div>

        <?php if ($_SESSION['role'] === 'MANAGER'): ?>
            <button class="btn btn-outline-dark btn-sm px-2 rounded-1 fw-bold" data-bs-toggle="modal" data-bs-target="#managerAddProductModal">
                <i class="fas fa-plus-circle me-2"></i> Khai báo mẫu mới (AI)
            </button>
        <?php endif; ?>
    </div>

    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-4 g-4">
        <?php if (!empty($products)): ?>
            <?php foreach ($products as $pro): ?>
                <div class="col">
                    <div class="card brand-card h-100  shadow-sm">
                        <div class="brand-logo-wrapper py-4 d-flex align-items-center justify-content-center position-relative"
                            style="height: 220px; background-color: #ffffff; border-radius: 12px 12px 0 0;">

                            <?php $isActive = ($pro['status'] == 't' || $pro['status'] === true || $pro['status'] == 1); ?>

                            <span class="position-absolute top-0 start-0 m-2 badge rounded-pill shadow-sm"
                                style="background-color: <?= $isActive ? '#ffffff' : '#000000' ?>; color: <?= $isActive ? '#000000' : '#ffffff' ?>; border: 1px solid black;">
                                <i class="fas <?= $isActive ? 'fa-check-circle' : 'fa-pause-circle' ?> me-1"></i>
                                <?= $isActive ? 'Đang kinh doanh' : 'Tạm ngưng' ?>
                            </span>
                            <?php if ($_SESSION['role'] === 'MANAGER'): ?>
                                <!-- Form thay đổi ảnh đại diện (ẩn) -->
                                <form id="form-update-avatar-<?= $pro['product_id'] ?>" action="index.php?page=products" method="POST" enctype="multipart/form-data" class="d-none">
                                    <input type="hidden" name="product_id" value="<?= $pro['product_id'] ?>">
                                    <input type="file" name="product_image" id="input-avatar-<?= $pro['product_id'] ?>" accept=".png, .jpg, .jpeg" onchange="submitAvatarChange(<?= $pro['product_id'] ?>)">
                                    <!-- BỔ SUNG LẠI DÒNG DƯỚI ĐÂY -->
                                    <input type="hidden" name="btn_update_avatar" value="1">
                                </form>


                                <!-- Nút sửa góc trên bên phải card ảnh -->
                                <button type="button" class="btn btn-sm btn-light position-absolute top-0 end-0 m-2 d-flex align-items-center justify-content-center shadow-sm"
                                    style="width: 72px; height: 28px; border: 1px solid #000000; z-index: 10;  color: #000000; font-size: 11px; font-weight: 700; border-radius: 20px; letter-spacing: 0.5px; transition: all 0.2s;"
                                    onclick="document.getElementById('input-avatar-<?= $pro['product_id'] ?>').click();"
                                    title="Thay đổi ảnh đại diện (Gọi AI sinh Vector mới)">
                                    SỬA ẢNH
                                </button>

                            <?php endif; ?>

                            <?php
                            $imageFile = !empty($pro['product_image']) ? $pro['product_image'] : 'default_shoe.png';
                            $imagePath = "assets/img_product/" . $imageFile;
                            ?>

                            <img src="<?= $imagePath ?>"
                                alt="<?= htmlspecialchars($pro['product_name']) ?>"
                                style="max-height: 160px; max-width: 90%; object-fit: contain; transition: all 0.3s; filter: <?= !$isActive ? 'grayscale(100%) opacity(0.5)' : 'none' ?>;"
                                onerror="this.src='https://via.placeholder.com/200x150?text=NO+IMAGE'">
                        </div>

                        <div class="card-body p-4 text-center d-flex flex-column">

                            <h5 class="fw-bold text-uppercase mb-3 <?= !$isActive ? 'text-muted' : 'text-dark' ?>">
                                <?= htmlspecialchars($pro['product_name'] ?? $cat['categoryName']) ?>
                            </h5>

                            <div class="mt-auto d-flex flex-row justify-content-center align-items-center gap-2 flex-nowrap w-100">

                                <button class="btn btn-outline-dark btn-sm px-2 rounded-1 fw-bold"
                                    data-bs-toggle="modal"
                                    data-bs-target="#detailModal<?= $pro['product_id'] ?>">
                                    Chi tiết
                                </button>

                                <?php if ($_SESSION['role'] === 'MANAGER'): ?>

                                    <button type="button" class="btn btn-outline-dark btn-sm px-2 rounded-1 fw-bold"
                                        data-bs-toggle="modal"
                                        data-bs-target="#managerAddVariantModal<?= $pro['product_id'] ?>"
                                        title="Khai báo biến thể mới">
                                        + Biến thể
                                    </button>

                                    <form action="index.php?page=products&category_id=<?= $pro['category_id'] ?>" method="POST" class="m-0 d-flex align-items-center" onsubmit="return confirm('Bạn có chắc đổi trạng thái kinh doanh sản phẩm này?')">
                                        <input type="hidden" name="product_id" value="<?= $pro['product_id'] ?>">
                                        <input type="hidden" name="category_id" value="<?= $pro['category_id'] ?>">
                                        <input type="hidden" name="current_status" value="<?= $pro['status'] ?>">
                                        <button type="submit" name="toggle_status" class="btn btn-link p-0 shadow-none"
                                            style="color: <?= $isActive ? '#000000' : '#adb5bd' ?>; text-decoration: none;" title="Đổi trạng thái">
                                            <i class="fas <?= $isActive ? 'fa-toggle-on' : 'fa-toggle-off' ?> fa-xl"></i>
                                        </button>
                                    </form>

                                    <form action="index.php?page=products&category_id=<?= $pro['category_id'] ?>" method="POST" class="m-0 d-flex align-items-center" onsubmit="return confirm('Bạn có chắc chắn muốn xóa đôi này không?')">
                                        <input type="hidden" name="product_id" value="<?= $pro['product_id'] ?>">
                                        <button type="submit" name="delete_product" class="btn btn-link p-0 shadow-none" style="color: grey;" title="Xóa sản phẩm">
                                            <i class="fas fa-trash-alt fa-lg"></i>
                                        </button>
                                    </form>

                                <?php endif; ?>

                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal fade" id="detailModal<?= $pro['product_id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg">
                            <div class="modal-header border-bottom-0 p-4">
                                <h5 class="modal-title fw-bold text-dark">
                                    Quản lý biến thể: <?= htmlspecialchars($pro['product_name']) ?>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>

                            <div class="modal-body p-4 pt-0">
                                <div class="table-responsive rounded-3 border">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th style="width: 100px;">Ảnh</th>
                                                <th>Mã SKU</th>
                                                <th>Màu sắc</th>
                                                <th class="text-center">Size</th>
                                                <th class="text-center">Số lượng</th>
                                                <th class="text-center">Vị trí kệ</th>
                                                <th class="text-center pe-4">Thao tác</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($pro['variants'])): ?>
                                                <?php foreach ($pro['variants'] as $var):
                                                    $vid = (string)$var['variant_id'];
                                                    $locs = $pro['locations'][$vid] ?? [];
                                                    $locsBase64 = base64_encode(json_encode($locs));
                                                ?>
                                                    <tr id="variant-row-<?= $vid ?>">
                                                        <td class="ps-4"><img src="<?= $imagePath ?>" class="rounded border shadow-sm" style="width: 50px; height: 35px; object-fit: cover;"></td>
                                                        <td class="small fw-bold"><?= $var['sku'] ?></td>
                                                        <td><?= htmlspecialchars($var['color']) ?></td>
                                                        <td class="text-center fw-bold"><span><?= $var['size'] ?></span></td>
                                                        <td class="text-center text-primary fw-bold"><?= $var['stock'] ?></td>

                                                        <td class="text-center">
                                                            <?php if (empty($locs)): ?>
                                                                <span class="badge bg-secondary">Chưa cất</span>
                                                            <?php else: ?>
                                                                <div class="d-flex flex-column gap-1 align-items-center">
                                                                    <?php foreach ($locs as $l): ?>
                                                                        <span class="badge text-dark border border-secondary" style="font-size:12px;">
                                                                            <?= $l['str'] ?>
                                                                        </span>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>

                                                        <td class="text-center pe-4">
                                                            <div class="d-flex justify-content-center gap-2 align-items-center">

                                                                <?php if ($_SESSION['role'] === 'MANAGER'): ?>
                                                                    <!-- chuyển vị trí -->
                                                                    <?php $is_active = ($var['variant_status'] === 't' || $var['variant_status'] === true || $var['variant_status'] === '1'); ?>

                                                                    <?php if (!empty($locs)): ?>
                                                                        <button type="button" class="btn btn-sm btn-outline-primary" title="Điều chuyển nội bộ"
                                                                            onclick="toggleMoveMap(<?= $vid ?>, '<?= $var['sku'] ?>', '<?= $locsBase64 ?>')">
                                                                            <i class="fas fa-exchange-alt"></i>
                                                                        </button>
                                                                    <?php endif; ?>

                                                                    <!-- tạm ngưng biến thể -->
                                                                    <form action="index.php?page=products&action=toggle_variant_status" method="POST" class="m-0"
                                                                        onsubmit="return confirm('<?= $is_active ? 'Bạn có chắc chắn muốn TẠM NGƯNG biến thể này?' : 'Bạn muốn KÍCH HOẠT LẠI biến thể này để kinh doanh?' ?>');">
                                                                        <input type="hidden" name="variant_id" value="<?= $var['variant_id'] ?>">
                                                                        <input type="hidden" name="current_status" value="<?= $is_active ? 1 : 0 ?>">
                                                                        <button type="submit" class="btn btn-sm <?= $is_active ? 'btn-success' : 'btn-secondary' ?>"
                                                                            title="<?= $is_active ? 'Đang hoạt động - Nhấn để tắt' : 'Đã tắt - Nhấn để bật' ?>">
                                                                            <i class="fas <?= $is_active ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i>
                                                                        </button>
                                                                    </form>

                                                                    <!-- xóa biến thể -->
                                                                    <form action="index.php?page=products&action=delete_variant" method="POST" class="m-0"
                                                                        onsubmit="return confirm('⚠️ CẢNH BÁO: Bạn có chắc chắn muốn XÓA biến thể này không?');">
                                                                        <input type="hidden" name="variant_id" value="<?= $var['variant_id'] ?>">
                                                                        <button type="submit" class="btn btn-sm btn-danger" title="Xóa biến thể">
                                                                            <i class="fas fa-trash-alt"></i>
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>

                                                                <!-- xem qr -->
                                                                <button type="button" class="btn btn-sm btn-outline-dark"
                                                                    onclick="printVariantQR('<?= $var['sku'] ?>', '<?= addslashes($pro['product_name']) ?>', '<?= $var['color'] ?>', '<?= $var['size'] ?>', '<?= $var['variant_id'] ?>')"
                                                                    title="In mã QR">
                                                                    <i class="fas fa-qrcode"></i>
                                                                </button>

                                                            </div>
                                                        </td>
                                                    </tr>

                                                    <tr id="map-row-<?= $vid ?>" class="d-none" style="background-color: #f8f9fa;">
                                                        <td colspan="7" class="p-0 border-0">
                                                            <div class="collapse" id="collapseMap-<?= $vid ?>">
                                                                <div class="card card-body m-3 border-primary shadow-sm bg-white" style="border-width: 2px !important;">
                                                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                                                        <h6 class="fw-bold mb-0 text-primary"><i class="fas fa-map-marked-alt me-2"></i> Điều chuyển nội bộ: <?= $var['sku'] ?></h6>
                                                                        <button type="button" class="btn-close btn-sm" onclick="closeMoveMap(<?= $vid ?>)"></button>
                                                                    </div>

                                                                    <div id="moveMapStatus-<?= $vid ?>" class="mb-3 position-sticky top-0 shadow-sm" style="z-index: 100;"></div>

                                                                    <div id="moveMapContainer-<?= $vid ?>" class="dynamic-map-container p-3 bg-light rounded border shadow-inner">
                                                                        <div class="text-center py-4 text-muted">
                                                                            <div class="spinner-border text-primary" role="status"></div> Đang tải bản đồ...
                                                                        </div>
                                                                    </div>

                                                                    <div class="text-end mt-3 border-top pt-3">
                                                                        <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4 me-2" onclick="closeMoveMap(<?= $vid ?>)">Hủy</button>
                                                                        <button type="button" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold" id="btnConfirmMove-<?= $vid ?>" onclick="executeVisualMove(<?= $vid ?>)" disabled>
                                                                            <i class="fas fa-check me-1"></i> Xác nhận Điều Chuyển
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="7" class="text-center py-5 text-muted">Chưa có biến thể!</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="modal-footer border-top-0 p-4">
                                <button type="button" class="btn btn-dark px-4 rounded-pill fw-bold" data-bs-dismiss="modal">Đóng</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal fade" id="managerAddVariantModal<?= $pro['product_id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg">
                            <div class="modal-header bg-success text-white border-bottom-0">
                                <h5 class="modal-title fw-bold"><i class="fas fa-tags me-2"></i> Khai báo Màu/Size mới</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <form action="index.php?page=products&action=manager_add_variant" method="POST"
                                onsubmit="return validateAddVariant(this);">
                                <div class="modal-body p-4">
                                    <p class="text-muted small mb-3">Thêm biến thể cho mẫu: <strong class="text-dark"><?= htmlspecialchars($pro['product_name']) ?></strong></p>

                                    <input type="hidden" name="product_id" value="<?= $pro['product_id'] ?>">
                                    <input type="hidden" name="category_id" value="<?= $pro['category_id'] ?>">
                                    <input type="hidden" name="brand_name" value="<?= htmlspecialchars($categoryName) ?>">
                                    <input type="hidden" name="product_name" value="<?= htmlspecialchars($pro['product_name']) ?>">

                                    <div class="row">
                                        <div class="col-6 mb-3">
                                            <label class="fw-bold mb-1 small">Màu sắc *</label>
                                            <input type="text" name="color" class="form-control" placeholder="VD: Trắng" required>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <label class="fw-bold mb-1 small">Size *</label>
                                            <!-- THAY ĐỔI MIN="1" Ở ĐÂY -->
                                            <input type="number" name="size" class="form-control text-center" placeholder="VD: 40" min="1" required>
                                        </div>
                                    </div>
                                    <div class="alert  py-2 mb-0 small">
                                        <i class="fas fa-exclamation-triangle me-1"></i> Tồn kho ban đầu sẽ là 0.
                                    </div>
                                </div>
                                <div class="modal-footer p-3 pt-0 border-top-0">
                                    <button type="button" class="btn btn-outline-dark rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#detailModal<?= $pro['product_id'] ?>">Hủy</button>
                                    <button type="submit" name="btn_add_variant" class="btn btn-outline-dark rounded-pill px-4 fw-bold">Xác nhận tạo</button>
                                </div>
                            </form>

                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="col-12 text-center py-5">
                <i class="fas fa-box-open fa-4x text-light mb-3"></i>
                <p class="text-muted">Hãng này hiện chưa có đôi giày nào!</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($_SESSION['browser_alert'])): ?>
    <script>
        setTimeout(function() {
            alert("⚠️ " + <?= json_encode($_SESSION['browser_alert']) ?>);
        }, 100);
    </script>
    <?php unset($_SESSION['browser_alert']); ?>
<?php endif; ?>

<?php if ($_SESSION['role'] === 'MANAGER'): ?>
    <div class="modal fade" id="managerAddProductModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-dark text-white border-bottom-0 p-4">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-robot text-info me-2"></i> Khai báo mẫu gốc (Sinh Vector)
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="index.php?page=products&action=manager_add" method="POST" enctype="multipart/form-data"
                    onsubmit="return confirm('Hệ thống sẽ gửi ảnh lên AI để trích xuất Vector chuẩn. Bồ có chắc chắn muốn lưu mẫu này không?')">
                    <div class="modal-body p-4">

                        <div class="mb-3">
                            <label class="fw-bold mb-1 small">Hãng sản xuất (Brand)</label>
                            <input type="text" class="form-control fw-bold bg-light" value="<?= htmlspecialchars(strtoupper($categoryName)) ?>" readonly disabled>
                            <input type="hidden" name="category_id" value="<?= htmlspecialchars($_GET['category_id'] ?? '') ?>">
                        </div>


                        <div class="mb-3">
                            <label class="fw-bold mb-1 small">Tên dòng sản phẩm (Model Name) *</label>
                            <input type="text" name="product_name" class="form-control fw-bold" placeholder="VD: Air Force 1 '07" required>
                        </div>

                        <div class="mb-3">
                            <label class="fw-bold mb-1 small">Hình ảnh chuẩn của Hãng (Gốc) *</label>
                            <input type="file" name="master_image" id="master_image_input" class="form-control" accept=".png, .jpg, .jpeg" required onchange="previewManagerImage(this)">

                            <div class="mt-3 text-center p-2 border rounded bg-light d-none" id="manager_preview_container">
                                <p class="small text-muted mb-1">Ảnh đã chọn:</p>
                                <img id="manager_img_preview" src="#" alt="Preview" class="img-fluid rounded shadow-sm" style="max-height: 200px;">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0 p-4 pt-0">
                        <button type="button" class="btn btn-secondary px-4 rounded-pill fw-bold" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" name="btn_manager_add" class="btn btn-primary px-4 rounded-pill fw-bold">
                            <i class="fas fa-microchip me-1"></i> Lưu Mẫu & Sinh Vector
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Hàm xử lý hiện ảnh preview cho Manager
        function previewManagerImage(input) {
            const container = document.getElementById('manager_preview_container');
            const preview = document.getElementById('manager_img_preview');

            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    container.classList.remove('d-none');
                }
                reader.readAsDataURL(input.files[0]);
            } else {
                container.classList.add('d-none');
            }
        }
    </script>
<?php endif; ?>
<div class="modal fade" id="simpleQRModal" tabindex="-1" data-bs-backdrop="false" style="background: none;">
    <div class="modal-dialog modal-sm modal-dialog-centered" style="box-shadow: 0 10px 50px rgba(0,0,0,0.2);">
        <div class="modal-content" style="border: 2px solid #000;">
            <div class="modal-header p-2 border-0">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-0" id="qrContentArea">
            </div>
            <div class="modal-footer p-2 border-0">
                <button type="button" class="btn btn-dark w-100 fw-bold" onclick="startPrint()">IN MÃ QR</button>
            </div>
        </div>
    </div>
</div>

<style>
    /* CSS chỉ phục vụ việc in ấn, không ảnh hưởng giao diện lúc xem */
    @media print {
        body * {
            visibility: hidden;
        }

        #qrContentArea,
        #qrContentArea * {
            visibility: visible;
        }

        #qrContentArea {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            text-align: center;
        }
    }
</style>

<script src="assets/js/product.js"></script>