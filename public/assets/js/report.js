document.addEventListener('DOMContentLoaded', function () {
    const colorPrimary = '#4e73df';
    const colorSuccess = '#1cc88a';

    // 1. VẼ BIỂU ĐỒ 
    if (typeof dayData !== 'undefined' && document.getElementById('daysChart') && dayData.length > 0) {
        new Chart(document.getElementById('daysChart'), {
            type: 'bar',
            data: {
                labels: dayData.map(d => new Date(d.work_date).toLocaleDateString('vi-VN')),
                datasets: [
                    {
                        label: 'Nhập kho',
                        data: dayData.map(d => d.total_import),
                        backgroundColor: '#4e73df',
                        borderRadius: 4,
                        barPercentage: 0.8,
                        categoryPercentage: 0.8
                    },
                    {
                        label: 'Xuất kho',
                        data: dayData.map(d => d.total_export),
                        backgroundColor: '#1cc88a',
                        borderRadius: 4,
                        barPercentage: 0.8,
                        categoryPercentage: 0.8
                    }
                ]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'bottom' },
                    tooltip: { mode: 'index', intersect: false }
                },
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Số lượng (đôi)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    if (typeof brandData !== 'undefined' && document.getElementById('brandChart') && brandData.length > 0) {
        new Chart(document.getElementById('brandChart'), {
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
            options: { maintainAspectRatio: false, cutout: '70%' }
        });
    }


    if (typeof flowData !== 'undefined' && document.getElementById('productFlowChart') && flowData.length > 0) {
        new Chart(document.getElementById('productFlowChart'), {
            type: 'bar',
            data: {
                labels: flowData.map(p => p.product_name),
                datasets: [
                    { label: 'Nhập', data: flowData.map(p => p.total_import), backgroundColor: colorPrimary },
                    { label: 'Xuất', data: flowData.map(p => p.total_export), backgroundColor: colorSuccess }
                ]
            },
            options: { indexAxis: 'y', maintainAspectRatio: false }
        });
    }
    // 2. LOGIC MỞ MODAL XEM CHI TIẾT NGÀY
    document.querySelectorAll('.btn-open-report-detail').forEach(btn => {
        btn.addEventListener('click', async function () {
            const date = this.dataset.date;
            const modalEl = document.getElementById('modalReportDetail');

            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            const tableBody = document.getElementById('reportDetailBody');
            const totalCount = document.getElementById('totalItemsCount');

            // Đưa ngày tháng lên header
            document.getElementById('reportDetailDate').innerText = new Date(date).toLocaleDateString('vi-VN');
            totalCount.innerText = "Đang tải dữ liệu...";

            // Đồng bộ spinner giống bên trang Lịch sử nhân viên
            tableBody.innerHTML = '<tr><td colspan="7" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><br><span class="d-block mt-2 text-muted">Đang tải dữ liệu...</span></td></tr>';

            modal.show();

            try {
                const response = await fetch(`index.php?page=report-detail&date=${date}`);
                const data = await response.json();

                if (data.length > 0) {
                    let html = '';
                    html += `<tr class="border-0"><td colspan="7" class="py-3 border-0"></td></tr>`;
                    data.forEach(item => {
                        const time = new Date(item.created_at).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });

                        // GIỮ NGUYÊN logic hiển thị màu sắc nguyên bản của bạn
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
                    totalCount.style.color = "#4e73df"; // Đồng bộ màu chữ sang xanh Primary chuyên nghiệp

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
    });


    // 3. LOGIC LỌC TỒN KHO THEO BRAND
    const brandFilter = document.getElementById('brandFilter');
    const inventoryTitle = document.getElementById('inventoryTitle');
    const productCards = document.querySelectorAll('.inv-product-card');

    function updateInventoryDisplay() {
        if (!brandFilter || !inventoryTitle) return;

        const selectedBrand = brandFilter.value;
        let totalStock = 0;

        productCards.forEach(card => {
            const cardBrand = card.getAttribute('data-brand');
            if (selectedBrand === 'ALL' || cardBrand === selectedBrand) {
                card.style.display = 'block';
                const variants = card.querySelectorAll('.variant-stock');
                variants.forEach(v => {
                    totalStock += parseInt(v.innerText);
                });
            } else {
                card.style.display = 'none';
            }
        });

        if (selectedBrand === 'ALL') {
            inventoryTitle.innerHTML = `
                <i class="bi bi-box-seam me-2"></i>Phân Tích Tồn Kho 
                <span class="ms-2 badge bg-primary-soft text-primary fw-bold" style="font-size: 0.9rem; padding: 5px 12px; border-radius: 50px; background-color: #e7f1ff;">
                    Tất cả: ${totalStock.toLocaleString()} đôi
                </span>`;
        } else {
            inventoryTitle.innerHTML = `
                Phân Tích Tồn Kho 
                <span class="ms-2 text-muted fw-normal" style="font-size: 0.85rem;">|</span>
                <span class="ms-2 badge bg-light text-dark border fw-medium" style="padding: 5px 12px; border-radius: 6px;">
                    Hãng: <b class="text-primary">${selectedBrand}</b>
                </span>
                <span class="ms-2 badge bg-light text-dark border fw-medium" style="padding: 5px 12px; border-radius: 6px;">
                    Tổng tồn: <b class="text-danger">${totalStock.toLocaleString()} đôi</b>
                </span>`;
        }
    }

    if (brandFilter) {
        updateInventoryDisplay();
        brandFilter.addEventListener('change', updateInventoryDisplay);
    }


    // ==========================================
    // 4. LOGIC XEM CHI TIẾT SẢN PHẨM THEO THƯƠNG HIỆU
    // ==========================================
    const btnShowBrandDetail = document.getElementById('btnShowBrandDetailCharts');
    const brandDetailRow = document.getElementById('brandDetailChartsRow');
    const brandDetailSelect = document.getElementById('brandDetailSelect');

    if (btnShowBrandDetail && brandDetailRow && brandDetailSelect && typeof inventoryData !== 'undefined') {
        // Gom nhóm dữ liệu theo: Thương hiệu -> Sản phẩm mẹ -> Tổng tồn kho (cộng dồn từ các biến thể)
        const brandProductMap = {};
        inventoryData.forEach(item => {
            const brand = item.category_name;
            const pName = item.product_name;
            const stock = parseInt(item.stock) || 0;

            if (!brandProductMap[brand]) {
                brandProductMap[brand] = {};
            }
            if (!brandProductMap[brand][pName]) {
                brandProductMap[brand][pName] = 0;
            }
            brandProductMap[brand][pName] += stock;
        });

        // Tự động render danh sách các thương hiệu vào select combobox
        const uniqueBrands = Object.keys(brandProductMap).sort();
        brandDetailSelect.innerHTML = '';
        uniqueBrands.forEach(brand => {
            const opt = document.createElement('option');
            opt.value = brand;
            opt.innerText = brand;
            brandDetailSelect.appendChild(opt);
        });

        let brandProductsChartInstance = null;

        // Hàm vẽ / cập nhật biểu đồ cột
        function renderBrandProductsChart(brandName) {
            const productsData = brandProductMap[brandName] || {};
            const labels = Object.keys(productsData);
            const data = Object.values(productsData);

            const ctx = document.getElementById('brandProductsChart');
            if (!ctx) return;

            // Nếu đã có biểu đồ trước đó, hủy đi để vẽ lại mới tránh chồng chéo
            if (brandProductsChartInstance) {
                brandProductsChartInstance.destroy();
            }

            brandProductsChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: `Tổng tồn kho sản phẩm mẹ`,
                        data: data,
                        backgroundColor: '#fd7e14', // Màu cam nổi bật sang trọng
                        borderRadius: 6,
                        barPercentage: 0.5,
                        categoryPercentage: 0.6
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true, position: 'top' },
                        tooltip: { callbacks: { label: context => ` ${context.raw} đôi` } }
                    },
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'Tổng tồn (đôi)' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        // Sự kiện click nút "Xem thêm"
        btnShowBrandDetail.addEventListener('click', function () {
            if (brandDetailRow.classList.contains('d-none')) {
                brandDetailRow.classList.remove('d-none');
                btnShowBrandDetail.innerHTML = 'Ẩn chi tiết <i class="fas fa-arrow-up ms-1"></i>';
                btnShowBrandDetail.classList.replace('btn-outline-primary', 'btn-outline-secondary');

                // Vẽ mặc định thương hiệu đầu tiên
                if (uniqueBrands.length > 0) {
                    brandDetailSelect.value = uniqueBrands[0];
                    document.getElementById('brandDetailChartTitle').innerText = `Sản Phẩm Thuộc Thương Hiệu: ${uniqueBrands[0]}`;
                    renderBrandProductsChart(uniqueBrands[0]);
                }

                // Cuộn màn hình mượt mà xuống khu vực biểu đồ chi tiết vừa mở
                setTimeout(() => {
                    brandDetailRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 100);
            } else {
                brandDetailRow.classList.add('d-none');
                btnShowBrandDetail.innerHTML = 'Xem thêm <i class="fas fa-arrow-right ms-1"></i>';
                btnShowBrandDetail.classList.replace('btn-outline-secondary', 'btn-outline-primary');
            }
        });

        // Sự kiện thay đổi thương hiệu trên Combobox
        brandDetailSelect.addEventListener('change', function () {
            renderBrandProductsChart(this.value);
            document.getElementById('brandDetailChartTitle').innerText = `Sản Phẩm Thuộc Thương Hiệu: ${this.value}`;
        });
    }


    // ==========================================
    // TỰ ĐỘNG MỞ BIỂU ĐỒ CHI TIẾT THƯƠNG HIỆU KHI ĐI TỪ DASHBOARD SANG
    // ==========================================
    if (window.location.search.includes('show_brand_detail=1')) {
        setTimeout(() => {
            const btnShowBrandDetail = document.getElementById('btnShowBrandDetailCharts');
            const brandDetailRow = document.getElementById('brandDetailChartsRow');
            if (btnShowBrandDetail && brandDetailRow && brandDetailRow.classList.contains('d-none')) {
                btnShowBrandDetail.click();
            }
        }, 300); // Tạo độ trễ nhỏ để Chart.js vẽ xong mượt mà
    }

});


