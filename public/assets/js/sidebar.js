document.addEventListener('DOMContentLoaded', function() {
    const chatContainer = document.getElementById('ai-chat-container');
    const chatBody = document.getElementById('ai-chat-body');
    const chatInput = document.getElementById('ai-chat-input');
    const btnOpen = document.getElementById('btn-open-ai');
    const btnMinimize = document.getElementById('ai-chat-minimize');
    const btnSend = document.getElementById('ai-chat-send');
    const logoutForm = document.getElementById('logout-form');

    // 1. Tải lịch sử chat từ SessionStorage
    const savedChat = sessionStorage.getItem('ai_chat_history');
    if (savedChat) {
        chatBody.innerHTML = savedChat;
        chatBody.scrollTop = chatBody.scrollHeight;
    }

    // 2. Click nút Sidebar -> Mở/Đóng
    if (btnOpen) {
        btnOpen.addEventListener('click', function(e) {
            e.preventDefault();
            chatContainer.classList.toggle('ai-chat-minimized');
            if (!chatContainer.classList.contains('ai-chat-minimized')) {
                chatInput.focus();
            }
        });
    }

    // 3. Click dấu trừ -> Thu nhỏ (Không mất lịch sử)
    btnMinimize.addEventListener('click', () => {
        chatContainer.classList.add('ai-chat-minimized');
    });

    // 4. Xác nhận và Xóa lịch sử khi Đăng xuất
    if (logoutForm) {
        logoutForm.addEventListener('submit', function(e) {
            // Hiển thị bảng thông báo xác nhận
            const confirmLogout = confirm("Bạn có chắc chắn muốn đăng xuất khỏi hệ thống không?");

            if (!confirmLogout) {
                // Nếu người dùng chọn "Cancel", chặn việc gửi form (không logout nữa)
                e.preventDefault();
            } else {
                // Nếu chọn "OK", xóa lịch sử chat rồi mới logout
                sessionStorage.removeItem('ai_chat_history');
            }
        });
    }

    // 5. Gửi tin nhắn
    async function sendMessage() {
        const text = chatInput.value.trim();
        if (!text) return;

        appendMessage('user', text);
        chatInput.value = '';

        const loadingId = 'loading-' + Date.now();
        appendMessage('bot', '<span class="typing">Đang truy vấn kho...</span>', loadingId);

        try {
            // Đổi thành phương thức POST và gửi dữ liệu dạng JSON
            const response = await fetch('http://127.0.0.1:8000/ask', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    query: text
                })
            });

            const data = await response.json();

            const reply = data.status === 'success' ? data.answer : "Lỗi từ AI Server.";
            document.getElementById(loadingId).innerHTML = reply;
        } catch (error) {
            console.error("Fetch error:", error);
            document.getElementById(loadingId).innerText = "Mất kết nối với AI Server (Cổng 8000).";
        }

        sessionStorage.setItem('ai_chat_history', chatBody.innerHTML);
    }

    function appendMessage(sender, text, id = '') {
        const msgDiv = document.createElement('div');
        msgDiv.className = sender === 'user' ? 'user-msg shadow-sm' : 'bot-msg shadow-sm';
        if (id) msgDiv.id = id;
        msgDiv.innerHTML = text;
        chatBody.appendChild(msgDiv);
        chatBody.scrollTop = chatBody.scrollHeight;
    }

    btnSend.addEventListener('click', sendMessage);
    chatInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') sendMessage();
    });
});