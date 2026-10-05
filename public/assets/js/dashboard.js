// File: public/assets/js/warehouse_map.js
document.addEventListener('DOMContentLoaded', function () {
    // ==========================================
    //LOGIC CHI TIẾT THỐNG KÊ (THẺ CLICKABLE)
    // ==========================================
    const modalEl = document.getElementById('detailModal');
    if (!modalEl) return;

    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    const bsModal = new bootstrap.Modal(modalEl);

    // CLICK CARD CẤP 1
    document.querySelectorAll('.clickable-card').forEach(card => {
        card.addEventListener('click', function () {
            const type = this.getAttribute('data-type');
            const title = this.querySelector('p').innerText;

            modalTitle.innerText = 'Phân tích chi tiết: ' + title;
            modalTitle.style.color = '#ffffff';
            modalContent.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-info" role="status"></div><p class="mt-2 text-white-50">Đang nạp dữ liệu...</p></div>';
            bsModal.show();

            fetch(`index.php?page=get_brand_data&type=${type}`)
                .then(res => res.json())
                .then(data => {
                    if (data.length === 0) {
                        modalContent.innerHTML = '<p class="text-center p-4 text-white-50">Không có dữ liệu cho mục này.</p>';
                        return;
                    }

                    let html = `
                    <table class="table align-middle text-white" style="border-color: rgba(255,255,255,0.1);">
                        <thead>
                            <tr>
                                <th>Thương hiệu</th>
                                <th class="text-end">Số lượng</th>
                                <th class="text-center">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>`;

                    data.forEach(item => {
                        html += `
                        <tr class="brand-row" data-id="${item.category_id}" data-type="${type}">
                            <td class="fw-bold">${item.brand}</td>
                            <td class="text-end fw-bold text-info">${parseInt(item.total).toLocaleString()}</td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-info btn-get-products" data-id="${item.category_id}" data-type="${type}">
                                    Chi tiết <i class="fas fa-chevron-down ms-1"></i>
                                </button>
                            </td>
                        </tr>
                        <tr id="brand-detail-${item.category_id}" class="d-none">
                            <td colspan="3" class="p-3 bg-dark">
                                <div class="product-container p-2 rounded border border-secondary">Đang tải sản phẩm...</div>
                            </td>
                        </tr>`;
                    });
                    html += '</tbody></table>';
                    modalContent.innerHTML = html;
                });
        });
    });

    // CLICK CARD CẤP 2
    modalContent.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-get-products');
        if (!btn) return;

        const brandId = btn.getAttribute('data-id');
        const type = btn.getAttribute('data-type');
        const childBox = document.getElementById(`brand-detail-${brandId}`);
        const icon = btn.querySelector('i');

        if (childBox.classList.contains('d-none')) {
            childBox.classList.remove('d-none');
            icon.classList.replace('fa-chevron-down', 'fa-chevron-up');

            fetch(`index.php?page=get_product_data&brand_id=${brandId}&type=${type}`)
                .then(res => res.json())
                .then(products => {
                    let pTable = `
                    <table class="table table-sm table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="text-white-50">Mẫu giày</th>
                                <th class="text-end text-white-50">Tổng</th>
                                <th class="text-center text-white-50">Biến thể</th>
                            </tr>
                        </thead>
                        <tbody>`;
                    products.forEach(p => {
                        pTable += `
                        <tr class="product-row" data-id="${p.product_id}" data-type="${type}">
                            <td>${p.product_name}</td>
                            <td class="text-end text-warning fw-bold">${p.total}</td>
                            <td class="text-center">
                                <button class="btn btn-sm text-info p-0 btn-get-variants" style="text-decoration: underline;">Xem</button>
                            </td>
                        </tr>
                        <tr id="product-detail-${p.product_id}" class="d-none">
                            <td colspan="3" class="p-3" style="background: rgba(0,0,0,0.2);">
                                <div class="variant-container"></div>
                            </td>
                        </tr>`;
                    });
                    pTable += '</tbody></table>';
                    childBox.querySelector('.product-container').innerHTML = pTable;
                });
        } else {
            childBox.classList.add('d-none');
            icon.classList.replace('fa-chevron-up', 'fa-chevron-down');
        }
    });

    // CLICK CARD CẤP 3
    modalContent.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-get-variants');
        if (!btn) return;

        const row = btn.closest('.product-row');
        const productId = row.getAttribute('data-id');
        const type = row.getAttribute('data-type');
        const variantBox = document.getElementById(`product-detail-${productId}`);

        if (variantBox.classList.contains('d-none')) {
            variantBox.classList.remove('d-none');
            btn.innerText = 'Đóng';

            fetch(`index.php?page=get_variant_data&product_id=${productId}&type=${type}`)
                .then(res => res.json())
                .then(variants => {
                    let vHtml = '<div class="row g-2">';
                    variants.forEach(v => {
                        vHtml += `
                        <div class="col-4">
                            <div class="p-2 border border-secondary rounded text-center small" style="background: rgba(255,255,255,0.05);">
                                <span class="text-white-50">Size ${v.size} - ${v.color}</span><br>
                                <strong class="text-white">${v.total} đôi</strong>
                            </div>
                        </div>`;
                    });
                    vHtml += '</div>';
                    variantBox.querySelector('.variant-container').innerHTML = vHtml;
                });
        } else {
            variantBox.classList.add('d-none');
            btn.innerHTML = 'Xem';
        }
    })


    // ==========================================
    // VẼ BIỂU ĐỒ TRÒN THỐNG KÊ THƯƠNG HIỆU CHO DASHBOARD
    // ==========================================
    if (typeof brandData !== 'undefined' && document.getElementById('dashboardBrandChart') && brandData.length > 0) {
        new Chart(document.getElementById('dashboardBrandChart'), {
            type: 'doughnut',
            data: {
                labels: brandData.map(d => d.brand),
                datasets: [{
                    data: brandData.map(d => d.total_stock),
                    backgroundColor: [
                        '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b',
                        '#fd7e14', '#6f42c1', '#e83e8c', '#20c997', '#0d6efd',
                        '#6610f2', '#17a2b8', '#28a745', '#ffc107', '#dc3545',
                        '#5a5c69', '#858796', '#a5a6b4', '#d1d2dd'
                    ]
                }]
            },
            options: {
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: {
                        display: true,
                        position: 'right', // Hiển thị danh mục chú thích bên phải rất thoáng
                        labels: {
                            boxWidth: 12,
                            padding: 12,
                            font: {
                                size: 11,
                                weight: '500'
                            }
                        }
                    }
                }
            }
        });
    }


    // ==========================================
    // LOGIC MỞ MODAL XEM CHI TIẾT GIAO DỊCH HÔM NAY (ĐỒNG BỘ DỮ LIỆU)
    // ==========================================
    const btnTodayDetail = document.querySelector('.btn-open-report-detail');
    if (btnTodayDetail) {
        btnTodayDetail.addEventListener('click', async function () {
            const date = this.dataset.date;
            const modalEl = document.getElementById('modalReportDetail');
            if (!modalEl) return;

            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            const tableBody = document.getElementById('reportDetailBody');
            const totalCount = document.getElementById('totalItemsCount');

            document.getElementById('reportDetailDate').innerText = new Date(date).toLocaleDateString('vi-VN');
            totalCount.innerText = "Đang tải dữ liệu...";

            // Đồng bộ spinner và khoảng cách Bootstrap
            tableBody.innerHTML = '<tr><td colspan="7" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><br><span class="d-block mt-2 text-muted">Đang tải dữ liệu...</span></td></tr>';

            modal.show();

            try {
                const response = await fetch(`index.php?page=report-detail&date=${date}`);
                const data = await response.json();

                if (data.length > 0) {
                    let html = '';
                    // Giãn cách thead & tbody bằng Bootstrap pure class
                    html += `<tr class="border-0"><td colspan="7" class="py-3 border-0"></td></tr>`;

                    data.forEach(item => {
                        const time = new Date(item.created_at).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });

                        // Giữ nguyên lô-gích hiển thị màu sắc nguyên bản của bạn
                        let badgeStyle = '';
                        if (item.transaction_type === 'IMPORT') {
                            badgeStyle = 'background-color: #000000 !important; color: #ffffff !important;';
                        } else {
                            badgeStyle = 'background-color: #ffffff !important; color: #000000 !important; border: 1px solid #000000 !important;';
                        }

                        html += `
                        <tr>
                            <td class="small py-3">${time}</td>
                            <td class="py-3"><span class="badge" style="${badgeStyle}">${item.transaction_type}</span></td>
                            <td class="fw-bold py-3">${item.brand}</td>
                            <td class="py-3">${item.product_name}</td>
                            <td class="py-3">Sz: ${item.size} | ${item.color}</td>
                            <td class="text-center fw-bold py-3">${item.quantity} đôi</td>
                            <td class="small py-3">${item.staff}</td>
                        </tr>`;
                    });

                    tableBody.innerHTML = html;
                    totalCount.innerText = "Tổng cộng: " + data.length + " lượt giao dịch";
                    totalCount.style.color = "#4e73df";

                } else {
                    tableBody.innerHTML = '<tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-box-open fa-3x mb-3 opacity-50 d-block mx-auto"></i>Không có giao dịch nào được ghi nhận.</td></tr>';
                    totalCount.innerText = "0 lượt giao dịch";
                }
            } catch (error) {
                console.error("Lỗi Fetch:", error);
                tableBody.innerHTML = '<tr><td colspan="7" class="text-danger fw-bold text-center py-4"><i class="fas fa-exclamation-triangle me-2"></i>Lỗi kết nối máy chủ.</td></tr>';
                totalCount.innerText = "Lỗi tải dữ liệu";
            }
        });
    }

});


