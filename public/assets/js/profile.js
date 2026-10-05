    //chặn việc load lại trang và xử lý dữ liệu ngầm.
    document.getElementById('formUpdateProfile').addEventListener('submit', async function(e) {
        e.preventDefault();

        if (!confirm('Xác nhận lưu thay đổi?')) return;

        const formData = new FormData(this);
        // BẮT BUỘC PHẢI CÓ 2 DÒNG NÀY
        formData.append('ajax_update', '1');
        formData.append('btn_update_profile', '1');

        const msgContainer = document.getElementById('profile-msg-container');
        const submitBtn = this.querySelector('button[name="btn_update_profile"]');

        submitBtn.disabled = true;
        submitBtn.innerHTML = 'Đang lưu...';

        try {
            const response = await fetch(this.action, {
                method: 'POST',
                body: formData
            });

            // Đoạn này để bồ debug: Nếu server trả về lỗi (không phải JSON), nó sẽ hiện ra code HTML
            const text = await response.text();
            try {
                const res = JSON.parse(text); // Cố gắng biến chữ thành JSON

                if (res.status === 'success') {
                    msgContainer.innerHTML = `<div class="alert alert-success py-2 rounded-1 mb-3 alert-glass-blink">${res.message}</div>`;
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    msgContainer.innerHTML = `<div class="alert alert-danger py-2 rounded-1 mb-3 alert-glass-blink">${res.message}</div>`;
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = 'Lưu Dữ Liệu';
                    this.querySelector('input[name="old_password"]').value = '';
                }
            } catch (e) {
                console.error("Server trả về rác:", text); // Xem rác ở Console
                msgContainer.innerHTML = `<div class="alert alert-danger py-2 mb-3">Lỗi: Server trả về HTML thay vì JSON.</div>`;
                submitBtn.disabled = false;
            }
        } catch (err) {
            msgContainer.innerHTML = `<div class="alert alert-danger py-2 mb-3">Lỗi kết nối server!</div>`;
            submitBtn.disabled = false;
        }
    });
