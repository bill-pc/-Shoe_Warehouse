document.addEventListener('DOMContentLoaded', function () {
    // 1. Kiểm tra sự tồn tại của element để tránh lỗi "null" làm sập script
    const userContext = document.getElementById('user-context');
    const badgeImport = document.getElementById('badge-import');
    const badgeExport = document.getElementById('badge-export');

    // Nếu không tìm thấy các thẻ này (có thể là trang Login hoặc đang là quyền Manager) thì dừng
    if (!userContext || !badgeImport || !badgeExport) return;

    const userId = userContext.getAttribute('data-user-id');
    console.log("=== Đang lắng nghe tín hiệu cho Staff ID: " + userId + " ===");

    // 2. Hàm lấy số lượng phiếu chờ (AJAX)
    function updateBadges() {
        fetch('index.php?page=tickets&action=get_pending_counts')
            .then(res => res.json())
            .then(data => {
                // Cập nhật số lượng Nhập kho
                if (data.import > 0) {
                    badgeImport.innerText = data.import > 99 ? '99+' : data.import;
                    badgeImport.classList.remove('d-none');
                } else {
                    badgeImport.classList.add('d-none');
                }

                // Cập nhật số lượng Xuất kho
                if (data.export > 0) {
                    badgeExport.innerText = data.export > 99 ? '99+' : data.export;
                    badgeExport.classList.remove('d-none');
                } else {
                    badgeExport.classList.add('d-none');
                }
            })
            .catch(err => console.error("Lỗi cập nhật số lượng:", err));
    }

    // Chạy lần đầu khi vừa tải trang
    updateBadges();

    // 3. Cấu hình Pusher
    // Bật log để soi lỗi ngay trên Console trình duyệt
    Pusher.logToConsole = true;

    const pusher = new Pusher(PUSHER_CONFIG.key, {
        cluster: PUSHER_CONFIG.cluster,
        forceTLS: true
    });

    const channel = pusher.subscribe('warehouse-channel');

    // 4. Lắng nghe sự kiện
    channel.bind('new-ticket-' + userId, function (data) {
        console.log('%c [Pusher] Nhận tín hiệu mới: ', 'background: #222; color: #bada55', data.message);

        // Gọi hàm cập nhật số mà không cần F5
        updateBadges();

        // THÊM HIỆU ỨNG THÔNG BÁO CHO ĐỒ ÁN THÊM ĐIỂM
        // Cách 1: Dùng trình duyệt thông báo (Browser Notification)
        if (Notification.permission === "granted") {
            new Notification("Hệ thống kho giày", { body: data.message, icon: 'assets/img_logo/logo_nike.jpg' });
        } else if (Notification.permission !== "denied") {
            Notification.requestPermission();
        }

        // Cách 2: Hiển thị một cái Alert nhẹ nhàng trên góc màn hình
        // Nếu bồ có dùng thư viện Toastr hoặc SweetAlert2 thì gọi ở đây là cực đẹp
        console.warn("Nội dung thông báo: " + data.message);
    });


    // 5. Lắng nghe sự kiện cập nhật ngầm (Silent Refresh)
    // Tác dụng: Khi Manager đổi NV hoặc Xóa phiếu, NV cũ nhận tín hiệu này để tự trừ số Badge
    channel.bind('refresh-badge-' + userId, function (data) {
        console.log('%c [Pusher] Quản lý đã thu hồi hoặc xóa phiếu. Đang cập nhật lại số lượng... ', 'color: #ff9800');

        // Chỉ cập nhật con số, không hiện thông báo Alert/Notification
        updateBadges();
    });
});