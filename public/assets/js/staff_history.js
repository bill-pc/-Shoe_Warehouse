document.addEventListener('DOMContentLoaded', function () {
    window.viewTicketDetails = async function (ticketId, ticketCode) {
        // Cập nhật mã phiếu lên UI
        document.getElementById('modalTicketCode').innerText = ticketCode;
        const tbody = document.getElementById('ticketDetailBody');
        tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><br>Đang tải dữ liệu...</td></tr>';

        // Mở Modal
        const modal = new bootstrap.Modal(document.getElementById('staffTicketDetailModal'));
        modal.show();

        try {
            const response = await fetch(`index.php?page=tickets&action=get_ticket_details&ticket_id=${ticketId}`);
            const data = await response.json();

            tbody.innerHTML = '';

            if (data.status === 'error') {
                tbody.innerHTML = `<tr><td colspan="9" class="text-danger fw-bold">${data.message}</td></tr>`;
                return;
            }

            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-muted fst-italic">Phiếu này không có sản phẩm nào!</td></tr>';
                return;
            }

            let rows = '';
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

                rows += `
                    <tr>
                        <td class="p-2">
                            <img src="${imgSrc}" class="rounded shadow-sm border" style="width: 85px; height: 85px; object-fit: cover;">
                        </td>
                        <td class="fw-bold text-secondary text-uppercase" style="font-size: 0.8rem;">${item.brand}</td>
                        <td>
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
                        <td>
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

            tbody.innerHTML = rows;

        } catch (error) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-danger fw-bold">Lỗi kết nối máy chủ! Không thể tải dữ liệu.</td></tr>';
            console.error(error);
        }
    };

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
