<?php include __DIR__ . '/../layouts/topbar.php'; ?>
<link rel="stylesheet" href="assets/css/warehouse_map.css">
<!-- sơ đồ kho -->
<div class="card p-4 warehouse-map-box border-0 mb-4 warehouse-map-glass position-relative">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <h3 class="text-dark mb-0 fw-bold"><i class="fas fa-map-marked-alt text-dark me-2"></i> Sơ Đồ Kho</h3>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'MANAGER'): ?>
                <button class="btn btn-sm btn-dark fw-bold ms-3" onclick="showAddShelfModal()">
                    <i class="fas fa-plus"></i> Thêm Kệ Mới
                </button>
            <?php endif; ?>
        </div>

        <div class="map-search-box position-relative flex-grow-1" style="max-width: 400px;">
            <input type="text" id="mapSearchInput" class="form-control custom-map-search" placeholder="Tìm tên giày hoặc brand...">
            <ul id="mapSearchSuggestions" class="list-group map-suggestions-list d-none"></ul>
        </div>

        <div class="d-flex flex-wrap gap-3 small fw-bold align-items-center">
            <span class="badge bg-secondary border border-secondary" style="padding: 10px 15px;">
                SỨC CHỨA TỔNG: <span class="text-warning fs-6"><?= $total_load ?? 0 ?></span> / <?= $total_capacity ?? 0 ?>
            </span>
            <span class="badge text-dark" style="background: rgba(255, 255, 255, 0.95); padding: 8px 12px; border: 1px solid black">Đầy</span>
            <span class="badge text-white" style="background: rgba(0, 0, 0, 0.7); padding: 8px 12px; border: 1px solid black">Trống</span>
        </div>
    </div>

    <div class="row g-4">
        <?php
        $displayShelves = $processedShelves ?? [];
        $vDict = $variantDict ?? ($stats['variantDict'] ?? []);

        // BƯỚC 1: TÌM SỐ Ô LỚN NHẤT CỦA TẤT CẢ CÁC KỆ (ĐỂ ĐỒNG BỘ CHIỀU RỘNG CÁC Ô)
        $globalMaxSlots = 0;
        foreach ($displayShelves as $s) {
            foreach ($s['layout'] as $tData) {
                $globalMaxSlots = max($globalMaxSlots, count($tData));
            }
        }
        if ($globalMaxSlots === 0) $globalMaxSlots = 6;

        // BƯỚC 2: LẶP QUA TỪNG KỆ ĐỂ RENDER
        foreach ($displayShelves as $shelf):
            $shelfName = $shelf['shelf_name'];
            $layout = $shelf['layout'];

            // TÍNH TOÁN SỐ TẦNG, SỐ Ô THỰC TẾ CỦA KỆ NÀY
            $localMaxTiers = 0;
            $localMaxSlots = 0;
            foreach (array_keys($layout) as $t) {
                $localMaxTiers = max($localMaxTiers, (int)$t);
            }
            foreach ($layout as $tData) {
                $localMaxSlots = max($localMaxSlots, count($tData));
            }
            if ($localMaxTiers === 0) $localMaxTiers = 4;
            if ($localMaxSlots === 0) $localMaxSlots = 6;
        ?>
            <?php
            $is_active = (isset($shelf['status']) && ($shelf['status'] === 't' || $shelf['status'] === true));
            ?>
            <div class="col-12 col-xl-6">
                <div class="shelf-wrapper p-3" style="<?= !$is_active ? 'opacity: 0.4; filter: grayscale(35%); transition: all 0.3s ease;' : 'transition: all 0.3s ease;' ?>">
                    <div class="d-flex justify-content-between mb-3 border-bottom border-secondary pb-2">
                        <div>
                            <div class="d-flex align-items-center mb-1">
                                <h4 class="text-white fw-bold mb-0">KỆ <?= $shelfName ?></h4>
                                <?php if (isset($shelf['status']) && ($shelf['status'] === 't' || $shelf['status'] === true)): ?>
                                    <span class="badge text-white ms-2" style="background: #000000; font-size: 0.75rem; border: 1px solid #000000;">Đang hoạt động</span>
                                <?php else: ?>
                                    <span class="badge text-dark ms-2" style="background: #ffffff; font-size: 0.75rem; border: 1px solid #000000;">Đã tạm ngưng</span>
                                <?php endif; ?>

                            </div>

                            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'MANAGER'): ?>
                                <!-- NÚT SỬA VÀ XÓA (CHỈ MANAGER MỚI THẤY) -->
                                <div class="d-flex flex-wrap gap-2 mt-2 mb-2">
                                    <button class="btn btn-sm btn-outline-dark p-1 px-2" style="font-size: 11px;" onclick="toggleShelfStatus('<?= $shelfName ?>')">
                                        <i class="fas fa-power-off"></i> Bật/Tắt
                                    </button>
                                    <button class="btn btn-sm btn-outline-dark p-1 px-2" style="font-size: 11px;" onclick="renameShelf(<?= $shelf['shelf_id'] ?>, '<?= htmlspecialchars($shelfName) ?>')">
                                        <i class="fas fa-edit"></i> Đổi tên
                                    </button>
                                    <button class="btn btn-sm btn-outline-dark p-1 px-2" style="font-size: 11px;" onclick="deleteShelf('<?= $shelfName ?>')">
                                        <i class="fas fa-trash"></i> Xóa
                                    </button>
                                </div>
                            <?php endif; ?>


                            <div class="d-flex flex-wrap gap-1 mt-1">
                                <?php foreach ($shelf['brand_counts'] as $bName => $bQty): ?>
                                    <span class="badge bg-dark border border-secondary small" style="font-size:10px;"><?= htmlspecialchars($bName) ?>: <?= $bQty ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="text-end text-white">
                            <small class="opacity-50">Sức chứa</small>
                            <div class="fw-bold"><?= $shelf['current_load'] ?> <span class="opacity-50">/ <?= $shelf['shelf_max_capacity'] ?></span></div>
                        </div>
                    </div>

                    <!-- BƯỚC 3: Vẽ lưới. Dùng globalMaxSlots để các ô bằng nhau, nhưng chỉ vẽ localMaxSlots ô -->
                    <div class="shelf-grid" style="grid-template-columns: 60px repeat(<?= $globalMaxSlots ?>, 1fr);">
                        <?php
                        for ($tier = $localMaxTiers; $tier >= 1; $tier--):
                            // Ép nhãn tầng luôn rớt xuống dòng mới bằng grid-column: 1
                            echo "<div class='tier-label text-white-50' style='grid-column: 1;'>T$tier</div>";
                            $slotsInTier = $layout[(string)$tier] ?? [];

                            for ($i = 1; $i <= $localMaxSlots; $i++):
                                $slotKey = str_pad($i, 2, '0', STR_PAD_LEFT);
                                $shoesInSlot = $slotsInTier[$slotKey] ?? [];
                                $slotCode = "{$shelfName}_{$tier}-{$slotKey}";
                                $occupancy = count($shoesInSlot);
                                $slotMax = (int)$shelf['slot_max'];
                                $fillPercent = ($slotMax > 0) ? ($occupancy / $slotMax) * 100 : 0;

                                // --- BẮT ĐẦU LOGIC CHI TIẾT SẢN PHẨM Ở POP-OVER ---
                                $detailHtml = "<div class='popover-inventory shadow-lg'>";
                                $detailHtml .= "<div class='d-flex justify-content-between align-items-center border-bottom border-secondary pb-2 mb-3'>
                                <span class='text-white fw-bold fs-5'>{$slotCode}</span>
                                <span class='badge bg-white text-dark py-1'>{$occupancy}/{$slotMax}</span>
                            </div>";

                                if ($occupancy == 0) {
                                    $detailHtml .= "<p class='text-white mb-0 text-center fs-6 py-2'>Ô trống</p>";
                                } else {
                                    $groupedShoes = array_count_values($shoesInSlot);
                                    foreach ($groupedShoes as $v_id => $qty) {
                                        $shoeData = $vDict[$v_id] ?? null;
                                        if ($shoeData) {
                                            $imgPath = "assets/img_product/" . htmlspecialchars($shoeData['product_image']);
                                            $proId = $shoeData['product_id'] ?? 0;
                                            $catId = $shoeData['category_id'] ?? 0;
                                            $targetLink = "index.php?page=products&category_id={$catId}&open_modal={$proId}&highlight_vid={$v_id}";

                                            $detailHtml .= "
                                                <div class='d-flex align-items-center mb-3 border-bottom pb-2' style='border-color: rgba(255,255,255,0.1) !important;'>
                                                    <a href='{$targetLink}' class='flex-shrink-0 me-2'>
                                                        <img src='{$imgPath}' class='popover-shoe-img rounded border border-secondary' 
                                                            style='width: 30px; height: 30px; object-fit: cover;'>
                                                    </a>
                                                    
                                                    <div class='text-start lh-sm text-white flex-grow-1' style='min-width: 0;'>
                                                        <a href='{$targetLink}' class='text-white text-decoration-none'>
                                                            <strong class='d-block text-truncate' style='font-size: 13px; max-width: 140px;'>
                                                                {$shoeData['product_name']}
                                                            </strong>
                                                        </a>
                                                        <span class='d-block mt-1' style='font-size: 12px; opacity: 0.7;'>
                                                            Sz: {$shoeData['size']} | {$shoeData['color']}
                                                        </span>
                                                    </div>
                                                    
                                                    <div class='ms-2 fw-bold text-black bg-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0' 
                                                        style='width: 25px; height: 25px; font-size: 12px;'>
                                                        {$qty}
                                                    </div>
                                                </div>";
                                        }
                                    }
                                }
                                $detailHtml .= "</div>";
                                // --- KẾT THÚC LOGIC CHI TIẾT ---
                        ?>
                                <div class="shelf-cell"
                                    style="--fill: <?= $fillPercent ?>%;"
                                    data-code="<?= $slotCode ?>"
                                    data-occupancy="<?= $occupancy ?>"
                                    data-max="<?= $slotMax ?>">
                                    <span><?= $slotKey ?></span>

                                    <?= $detailHtml ?>
                                </div>
                        <?php
                            endfor; // Kết thúc lặp Slot
                        endfor; // Kết thúc lặp Tầng
                        ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal Thêm Kệ (Lưới tương tác 12x12) -->
<div class="modal fade" id="addShelfModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold text-info"><i class="fas fa-plus-square me-2"></i>THÊM KỆ MỚI</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label text-white-50 fw-bold small">Tên/Mã Kệ (Vd: A, B1)</label>
                    <input type="text" id="new_shelf_name" class="form-control bg-black text-white border-secondary" placeholder="Tên kệ...">
                </div>
                <div class="mb-3">
                    <label class="form-label text-white-50 fw-bold small">Sức chứa tối đa của 1 ô (Số lượng)</label>
                    <input type="number" id="new_shelf_capacity" class="form-control bg-black text-white border-secondary" value="4" min="1">
                </div>
                <div class="mb-3">
                    <label class="form-label text-info fw-bold small">Vẽ Kích Thước (Tầng x Ô)</label>
                    <div class="text-white-50 small mb-2">Rê chuột vào lưới để chọn kích thước. Đang chọn: <span id="grid_result" class="text-warning fw-bold">4 Tầng x 6 Ô</span></div>

                    <!-- Vùng Render lưới tự động JS -->
                    <div id="shelf_grid_container" style="display: grid; grid-template-columns: repeat(12, 1fr); gap: 2px; max-width: 100%; border: 1px solid #444; padding: 2px; background: #222;">
                    </div>
                </div>
                <input type="hidden" id="selected_tiers" value="4">
                <input type="hidden" id="selected_slots" value="6">
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Hủy</button>
                <button type="button" class="btn btn-info fw-bold" onclick="submitAddShelf()">Lưu Kệ Mới</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/warehouse_map.js"></script>