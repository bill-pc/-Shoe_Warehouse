document.addEventListener("DOMContentLoaded", function () {

    // Bắt sự kiện khi click vào nút "Sửa"
    const editButtons = document.querySelectorAll('.btn-edit-user');

    editButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            // Lấy chuỗi JSON từ thuộc tính data-user
            const userDataString = this.getAttribute('data-user');
            const user = JSON.parse(userDataString);

            // Đổ dữ liệu vào các ô input trong Modal Edit
            document.getElementById('edit_user_id').value = user.user_id;
            document.getElementById('edit_full_name').value = user.full_name;

            // Format ngày giờ
            if (user.created_at) {
                let date = new Date(user.created_at);
                let formattedDate = date.toLocaleDateString('vi-VN') + ' ' +
                    date.toLocaleTimeString('vi-VN', {
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                document.getElementById('edit_created_at').value = formattedDate;
            }

            // Xử lý Checkbox Trạng thái
            const isActive = (user.status === 't' || user.status === true || user.status === 1);
            document.getElementById('edit_status').checked = isActive;
        });
    });


    // ========================================================
    // TÍNH NĂNG TÌM KIẾM REAL-TIME (TÌM TỚI ĐÂU HIỆN TỚI ĐÓ)
    // ========================================================
    const searchInput = document.getElementById('searchInput');
    const roleFilter = document.getElementById('roleFilter');
    const btnResetFilter = document.getElementById('btnResetFilter');
    const employeeRows = document.querySelectorAll('.employee-row');
    const noResultRow = document.getElementById('noResultRow');

    function filterEmployees() {
        if (!searchInput || !roleFilter) return;

        // Lấy từ khóa và vai trò, xóa sạch khoảng trắng dư thừa
        const keyword = searchInput.value.toLowerCase().trim();
        const role = roleFilter.value.toLowerCase().trim();
        let visibleCount = 0;

        employeeRows.forEach(row => {
            // Lấy dữ liệu và làm sạch ngay lập tức
            const id = row.querySelector('.col-id').textContent.toLowerCase().trim();
            const name = row.querySelector('.col-name').textContent.toLowerCase().trim();
            const rowRole = row.querySelector('.col-role').textContent.toLowerCase().trim();

            // Kiểm tra khớp từ khóa (chỉ so sánh ID và Tên)
            const matchKeyword = keyword === "" || id.includes(keyword) || name.includes(keyword);

            // Kiểm tra khớp vai trò
            const matchRole = role === "" || rowRole === role;

            if (matchKeyword && matchRole) {
                row.style.display = ''; // Hiện dòng
                visibleCount++;
            } else {
                row.style.display = 'none'; // Ẩn dòng
            }
        });

        // Xử lý hiện dòng "Không tìm thấy" nếu không có kết quả nào
        if (noResultRow) {
            noResultRow.style.display = visibleCount === 0 ? '' : 'none';
        }
    }


    // Gắn sự kiện (Lắng nghe khi người dùng gõ chữ hoặc đổi Option)
    if (searchInput) searchInput.addEventListener('keyup', filterEmployees);
    if (roleFilter) roleFilter.addEventListener('change', filterEmployees);

    // Xử lý nút Reset
    if (btnResetFilter) {
        btnResetFilter.addEventListener('click', function () {
            searchInput.value = '';
            roleFilter.value = '';
            filterEmployees(); // Trả lại bảng nguyên trạng
        });
    }
});