// history.js
document.addEventListener('DOMContentLoaded', function () {
    const detailModalElement = document.getElementById('modalDetail');
    if (!detailModalElement) return; // Bảo vệ lỗi nếu không tìm thấy modal

    const detailModal = new bootstrap.Modal(detailModalElement);
    const tableBody = document.getElementById('detailTableBody');
    const productNameEl = document.getElementById('detailProductName');

    document.querySelectorAll('.btn-view-detail').forEach(button => {
        button.addEventListener('click', async function () {
            // Lấy thông tin từ data attributes (Bổ sung ref)
            const date = this.dataset.date;
            const pid = this.dataset.pid;
            const uid = this.dataset.uid;
            const type = this.dataset.type;
            const pname = this.dataset.pname;
            const ref = this.dataset.ref || '';

            // Hiển thị tên sản phẩm lên modal
            productNameEl.innerText = pname;
            tableBody.innerHTML = '<tr><td colspan="4" class="text-center py-3"><div class="spinner-border spinner-border-sm text-light"></div> Đang tải...</td></tr>';
            detailModal.show();

            try {
                // Gọi API lấy chi tiết (Gửi kèm reference_id)
                const response = await fetch(`index.php?page=history-detail&date=${date}&product_id=${pid}&user_id=${uid}&type=${type}&reference_id=${ref}`);
                const data = await response.json();

                if (data.length > 0) {
                    let html = '';
                    data.forEach(row => {
                        // Thiết lập đường dẫn ảnh sản phẩm
                        const imgSrc = row.product_image ? `assets/img_product/${row.product_image}` : 'assets/images/placeholder.png';
                        // Tính toán class màu sắc
                        const colorClass = type === 'IMPORT' ? 'text-success' : 'text-danger';
                        const sign = type === 'IMPORT' ? '+' : '-';

                        html += `
                            <tr>
                                <td class="ps-3 py-2">
                                    <img src="${imgSrc}" class="rounded border border-secondary shadow-sm" style="width: 55px; height: 55px; object-fit: cover;">
                                </td>
                                <td class="fw-bold">${row.size}</td>
                                <td><span class="badge border border-light text-light px-2 py-1">${row.color}</span></td>
                                <td class="text-center fw-bold ${colorClass}">
                                    ${sign}${row.quantity}
                                </td>
                            </tr>
                        `;
                    });
                    tableBody.innerHTML = html;
                } else {
                    tableBody.innerHTML = '<tr><td colspan="4" class="text-center py-3">Không có chi tiết.</td></tr>';
                }
            } catch (error) {
                tableBody.innerHTML = '<tr><td colspan="4" class="text-center py-3 text-danger">Lỗi kết nối máy chủ.</td></tr>';
            }
        });
    });
});
