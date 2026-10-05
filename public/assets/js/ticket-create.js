document.addEventListener('DOMContentLoaded', function () {
    // 1. Thêm dòng động
    const btnAddRow = document.getElementById('addRow');
    if (btnAddRow) {
        btnAddRow.addEventListener('click', function () {
            let tbody = document.querySelector('#detailTable tbody');
            let newRow = tbody.rows[0].cloneNode(true);

            // Xóa dữ liệu cũ trên dòng mới copy
            newRow.querySelector('.brand-select').value = '';

            // Đặt lại ảnh về mặc định
            let imgPreview = newRow.querySelector('.img-preview');
            if (imgPreview) imgPreview.src = 'assets/images/placeholder.png';

            let prodSelect = newRow.querySelector('.product-select');
            prodSelect.innerHTML = '<option value="">Chọn Hãng trước</option>';
            prodSelect.disabled = true;

            let varSelect = newRow.querySelector('.variant-select');
            varSelect.innerHTML = '<option value="">Chọn Mẫu trước</option>';
            varSelect.disabled = true;

            newRow.querySelector('input[type="number"]').value = '';

            // Nối dòng mới vào bảng
            tbody.appendChild(newRow);

            // Gắn lại sự kiện cho các dropdown của dòng mới
            attachEvents(newRow);
        });
    }

    // 2. Alert Confirm trước khi submit form tạo phiếu
    const ticketForm = document.getElementById('ticketForm');
    if (ticketForm) {
        ticketForm.addEventListener('submit', function (e) {
            if (!confirm('Bạn có chắc chắn muốn LƯU phiếu này không? Dữ liệu phiếu sẽ không thể chỉnh sửa sau khi lưu.')) {
                e.preventDefault();
            }
        });
    }

    // 3. Xử lý AJAX liên hoàn Dropdowns (Hãng -> Mẫu -> Biến thể)
    function attachEvents(row) {
        // Sự kiện Xóa dòng
        row.querySelector('.btn-remove').addEventListener('click', function () {
            if (document.querySelectorAll('#detailTable tbody tr').length > 1) {
                row.remove();
            } else {
                alert('Phải có ít nhất 1 dòng sản phẩm trong phiếu!');
            }
        });

        let brandSelect = row.querySelector('.brand-select');
        let prodSelect = row.querySelector('.product-select');
        let varSelect = row.querySelector('.variant-select');
        let imgPreview = row.querySelector('.img-preview'); // Bắt thẻ ảnh

        // Khi chọn Hãng (Brand) -> Load danh sách Mẫu giày
        brandSelect.addEventListener('change', function () {
            let brandId = this.value;
            prodSelect.innerHTML = '<option value="">Đang tải...</option>';
            varSelect.innerHTML = '<option value="">Chọn Mẫu trước</option>';
            varSelect.disabled = true;

            // Nếu đổi hãng thì reset ảnh
            if (imgPreview) imgPreview.src = 'assets/images/placeholder.png';

            if (brandId) {
                fetch(`index.php?page=tickets&action=get_products&brand_id=${brandId}`)
                    .then(res => res.json())
                    .then(data => {
                        prodSelect.disabled = false;
                        prodSelect.innerHTML = '<option value="">Chọn Mẫu Giày</option>';
                        data.forEach(p => {
                            prodSelect.innerHTML += `<option value="${p.product_id}">${p.product_name}</option>`;
                        });
                    });
            } else {
                prodSelect.disabled = true;
            }
        });

        // Khi chọn Mẫu giày -> Load danh sách Biến thể VÀ LẤY ẢNH
        prodSelect.addEventListener('change', function () {
            let prodId = this.value;
            varSelect.innerHTML = '<option value="">Đang tải...</option>';

            // Lấy loại phiếu từ input hidden trong form
            let ticketType = document.querySelector('input[name="ticket_type"]').value;

            if (prodId) {
                fetch(`index.php?page=tickets&action=get_variants&product_id=${prodId}&type=${ticketType}`)
                    .then(res => res.json())
                    .then(data => {
                        varSelect.disabled = false;
                        varSelect.innerHTML = '<option value="">Chọn Màu & Size</option>';

                        // LẤY ẢNH GẮN VÀO GIAO DIỆN Ở ĐÂY NÈ
                        if (data && data.length > 0 && data[0].product_image) {
                            imgPreview.src = `assets/img_product/${data[0].product_image}`;
                        } else {
                            imgPreview.src = 'assets/images/placeholder.png';
                        }

                        // Hiển thị danh sách
                        if (data.length === 0) {
                            varSelect.innerHTML = '<option value="">(Tạm hết hàng)</option>';
                            varSelect.disabled = true;
                        } else {
                            data.forEach(v => {
                                let stockInfo = `(Tồn: ${v.stock})`;
                                varSelect.innerHTML += `<option value="${v.variant_id}">${v.color} - Size ${v.size} ${stockInfo}</option>`;
                            });
                        }
                    });
            } else {
                varSelect.disabled = true;
                if (imgPreview) imgPreview.src = 'assets/images/placeholder.png';
            }
        });
    }

    // Kích hoạt sự kiện cho dòng đầu tiên khi trang vừa load xong
    let firstRow = document.querySelector('#detailTable tbody tr');
    if (firstRow) {
        attachEvents(firstRow);
    }

    // =========================================================
    // 4. XỬ LÝ MODAL ĐỔI NHÂN VIÊN TRONG BẢNG LỊCH SỬ PHIẾU
    // =========================================================
    const staffButtons = document.querySelectorAll('.btn-change-staff');
    if (staffButtons.length > 0) {
        staffButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                const ticketId = this.getAttribute('data-ticket-id');
                const ticketCode = this.getAttribute('data-ticket-code');
                const staffName = this.getAttribute('data-staff-name');

                document.getElementById('modal_ticket_id').value = ticketId;
                document.getElementById('modal_ticket_code').value = ticketCode;
                document.getElementById('modal_current_staff').value = staffName;
            });
        });
    }

    // =========================================================
    // 5. XỬ LÝ MODAL XEM CHI TIẾT PHIẾU BẰNG AJAX
    // =========================================================
    const viewButtons = document.querySelectorAll('.btn-view-details');
    if (viewButtons.length > 0) {
        viewButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                const ticketId = this.getAttribute('data-ticket-id');
                const ticketCode = this.getAttribute('data-ticket-code');

                document.getElementById('view_ticket_code').textContent = ticketCode;
                const tbody = document.getElementById('detailModalBody');
                tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><br>Đang tải dữ liệu...</td></tr>';

                fetch(`index.php?page=tickets&action=get_ticket_details&ticket_id=${ticketId}`)
                    .then(res => res.json())
                    .then(data => {
                        tbody.innerHTML = '';

                        if (data.status === 'error') {
                            tbody.innerHTML = `<tr><td colspan="9" class="text-danger fw-bold">${data.message}</td></tr>`;
                            return;
                        }

                        if (data.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="5" class="text-muted fst-italic">Phiếu này không có sản phẩm nào!</td></tr>';
                            return;
                        }

                        data.forEach(item => {
                            const imgSrc = item.product_image ? `assets/img_product/${item.product_image}` : 'assets/images/placeholder.png';
                            let expected = parseInt(item.quantity) || 0;
                            let actual = parseInt(item.processed_qty) || 0;
                            let diffNum = actual - expected;

                            let diffHtml = '';
                            let noteHtml = item.note ? `<span class="small text-muted">${item.note}</span>` : `<span class="text-black-50">-</span>`;

                            if (diffNum !== 0) {
                                let diffStr = diffNum > 0 ? `+${diffNum}` : `${diffNum}`;
                                let colorClass = diffNum > 0 ? 'text-info' : 'text-danger';
                                diffHtml = `<span class="fw-bold ${colorClass}">${diffStr}</span>`;
                            } else {
                                diffHtml = `<span class="text-success fw-bold"><i class="fas fa-check"></i> Khớp</span>`;
                            }

                            tbody.innerHTML += `
                                <tr>
                                    <td class="p-2">
                                        <img src="${imgSrc}" class="rounded shadow-sm border" style="width: 85px; height: 85px; object-fit: cover;">
                                    </td>
                                    <td class="fw-bold text-secondary text-uppercase" style="font-size: 0.8rem;">${item.brand}</td>
                                    <td >
                                        <div class="fw-bold text-primary" style="white-space: normal; line-height: 1.3;">
                                            ${item.product_name}
                                        </div>
                                    </td>
                                    <td>
                                        <div class="variant-tag">${item.color}</div>
                                        <div class="variant-tag mt-1">Size ${item.size}</div>
                                    </td>
                                    <td><span class="fw-bold fs-5">${expected}</span></td>
                                    <td><span class="fw-bold fs-5 ${diffNum !== 0 ? 'text-danger' : 'text-success'}">${actual}</span></td>
                                    <td>${diffHtml}</td>
                                    <td >
                                        <div style="white-space: normal; word-break: break-word; line-height: 1.4;">
                                            ${noteHtml}
                                        </div>
                                    </td>
                                    <td class="text-center pe-4">
                                        <button type="button" class="btn btn-sm btn-outline-dark"
                                            onclick="printImportTicketQR('${item.sku || ''}', '${item.product_name.replace(/'/g, "\\'").replace(/"/g, '\\"')}', '${item.color}', '${item.size}', '${item.variant_id}', '${item.import_date || ''}', '${item.staff_id || ''}', '${(item.staff_name || '').replace(/'/g, "\\'").replace(/"/g, '\\"')}')"
                                            title="In mã QR Nhập Kho">
                                            <i class="fas fa-qrcode"></i>
                                        </button>
                                    </td>

                                </tr>
                            `;

                        });
                    })
                    .catch(err => {
                        tbody.innerHTML = '<tr><td colspan="5" class="text-danger fw-bold">Lỗi kết nối máy chủ! Không thể tải dữ liệu.</td></tr>';
                        console.error(err);
                    });
            });
        });
    }

    // =========================================================
    // 6. XỬ LÝ REAL-TIME PUSHER: TỰ ĐỘNG CẬP NHẬT BẢNG LỊCH SỬ
    // =========================================================
    if (typeof Pusher !== 'undefined') {
        const pusherManager = new Pusher(PUSHER_CONFIG.key, { cluster: PUSHER_CONFIG.cluster, forceTLS: true });

        const channelManager = pusherManager.subscribe('warehouse-channel');

        channelManager.bind('ticket-status-changed', function (data) {
            console.log("Đã nhận tín hiệu Pusher:", data); // Bật để soi lỗi

            // Tìm đúng dòng của phiếu vừa bị thay đổi
            const row = document.getElementById('row-ticket-' + data.ticket_id);
            if (row) {
                // 1. Cập nhật nhãn trạng thái (Đổi màu Badge)
                const statusCell = row.querySelector('.ticket-status-cell');
                if (statusCell) {
                    let bgClass = 'bg-secondary';
                    if (data.status === 'PENDING') bgClass = 'bg-warning text-dark';
                    if (data.status === 'PROCESSING') bgClass = 'bg-info text-dark';
                    if (data.status === 'PAUSED') bgClass = 'bg-danger';
                    if (data.status === 'COMPLETED') bgClass = 'bg-success';
                    if (data.status === 'COMPLETE_DIFF') bgClass = 'bg-danger';
                    statusCell.innerHTML = `<span class="badge ${bgClass}">${data.status}</span>`;
                }

                // 2. Cập nhật thời gian hoàn thành (NẾU CÓ)
                if (['COMPLETED', 'COMPLETE_DIFF'].includes(data.status) && data.completed_at) {
                    const timeCell = row.querySelector('.ticket-time-cell');
                    if (timeCell) {
                        let colorClass = data.status === 'COMPLETE_DIFF' ? 'text-danger' : 'text-success';
                        timeCell.innerHTML = `<span class="${colorClass} fw-bold">${data.completed_at}</span>`;
                    }
                }

                // 3. Ẩn/Hiện nút Sửa NV và Xóa tùy theo trạng thái
                const btnChange = row.querySelector('.btn-change-staff');
                const formDelete = row.querySelector('.form-delete-ticket');

                // Xác định ô chứa các nút thao tác (ô cuối cùng của hàng)
                const actionCell = row.cells[row.cells.length - 1];

                if (data.status !== 'PENDING' && data.status !== 'PAUSED') {
                    // Ẩn cả nút chỉ định nhân viên và nút xóa
                    if (btnChange) btnChange.classList.add('d-none');
                    if (formDelete) formDelete.classList.add('d-none');

                    // Hiển thị nhãn "Khóa sửa" nếu chưa có sẵn trên giao diện
                    if (actionCell && !actionCell.querySelector('.lock-span')) {
                        const lockSpan = document.createElement('span');
                        lockSpan.className = 'text-muted small ms-1 lock-span';
                        lockSpan.innerHTML = '<i class="fas fa-lock"></i> Khóa sửa';
                        actionCell.appendChild(lockSpan);
                    }
                } else {
                    // Đang ở trạng thái PENDING hoặc PAUSED -> Cho phép sửa nhân viên
                    if (btnChange) btnChange.classList.remove('d-none');

                    // Chỉ hiện nút xóa khi trạng thái thực sự là PENDING
                    if (data.status === 'PENDING') {
                        if (formDelete) formDelete.classList.remove('d-none');
                    } else {
                        if (formDelete) formDelete.classList.add('d-none');
                    }

                    // Dọn dẹp nhãn "Khóa sửa" nếu có
                    const lockSpan = row.querySelector('.lock-span');
                    if (lockSpan) {
                        lockSpan.remove();
                    }
                }

            }
        });
    }

    // Các hàm in mã QR cho Chi Tiết Phiếu Nhập
    window.printImportTicketQR = function (sku, name, color, size, vid, importDate, staffId, staffName) {
        if (!vid || vid === 'undefined') {
            alert("Lỗi: Không tìm thấy ID biến thể!");
            return;
        }

        const baseUrl = window.QR_BASE_URL || "https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/";
        const targetUrl = `${baseUrl}check_QR.php?vid=${vid}&import_date=${encodeURIComponent(importDate)}&staff_id=${staffId}&staff_name=${encodeURIComponent(staffName)}`;
        const qrImageUrl = `https://quickchart.io/qr?text=${encodeURIComponent(targetUrl)}&size=250`;

        const formatDate = (dateStr) => {
            if (!dateStr) return 'Chờ xử lý';
            try {
                const d = new Date(dateStr);
                if (isNaN(d.getTime())) return dateStr;
                return d.toLocaleDateString('vi-VN') + ' ' + d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
            } catch (e) { return dateStr; }
        };

        const html = `
        <div class="text-center">
            <p class="mb-1"><strong>${name}</strong></p>
            <p class="small text-muted mb-2">${color} - Size ${size}</p>
            <img src="${qrImageUrl}" style="width: 250px; height: 250px;" class="border p-2 shadow-sm rounded">
            <p class="mt-3 mb-0 small text-secondary">MÃ TRUY XUẤT NHẬP KHO</p>
            <p class="fw-bold text-dark mb-1">SKU: ${sku}</p>
            <hr class="my-2">
            <div class="text-start small p-2  rounded text-dark" style="font-size: 0.8rem; line-height: 1.4;">
                <div><strong>Ngày nhập:</strong> ${formatDate(importDate)}</div>
                <div><strong>NV nhập:</strong> [#${staffId || ''}] ${staffName || 'Chưa rõ'}</div>
            </div>
        </div>
    `;

        document.getElementById('qrContentArea').innerHTML = html;
        const modal = new bootstrap.Modal(document.getElementById('simpleQRModal'));
        modal.show();
    };

    window.startPrint = function () {
        window.print();
    };

});