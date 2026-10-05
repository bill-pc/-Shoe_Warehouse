<?php
$page = $page ?? 'dashboard';
?>

<aside class="sidebar">
    <div class="logo">
        SMART<br>WAREHOUSE
    </div>

    <div class="menu">
        <p class="menu-title">MENU CHÍNH</p>

        <ul>
            <?php if (isset($_SESSION['role']) && strtoupper($_SESSION['role']) !== 'ADMIN'): ?>
                <li class="<?= $page === 'dashboard' ? 'active' : '' ?>">
                    <a href="index.php?page=dashboard">Tổng quan</a>
                </li>
            <?php endif; ?>

            <?php if (isset($_SESSION['role']) && strtoupper($_SESSION['role']) !== 'ADMIN'): ?>
                <li class="<?= $page === 'categories' ? 'active' : '' ?>">
                    <a href="index.php?page=categories">
                        <?= ($_SESSION['role'] === 'MANAGER') ? 'Quản lý danh mục' : 'Quản lý sản phẩm' ?>
                    </a>
                </li>
            <?php endif; ?>

            <?php if (isset($_SESSION['role']) && strtoupper($_SESSION['role']) === 'ADMIN'): ?>
                <li class="<?= $page === 'employees' ? 'active' : '' ?>">
                    <a href="index.php?page=employees">Quản lí Nhân viên</a>
                </li>
            <?php endif; ?>

            <?php if (isset($_SESSION['role']) && strtoupper($_SESSION['role']) === 'MANAGER'): ?>
                <li class="<?= $page === 'report' ? 'active' : '' ?>">
                    <a href="index.php?page=report">Xem thống kê kho</a>
                </li>
            <?php endif; ?>

            <?php if (isset($_SESSION['role']) && strtoupper($_SESSION['role']) === 'MANAGER'): ?>
                <li class="<?= $page === 'history' ? 'active' : '' ?>">
                    <a href="index.php?page=history">Lịch sử xuất nhập</a>
                </li>
            <?php endif; ?>

            <!-- MENU QUẢN LÝ PHIẾU KHO -->
            <?php if (isset($_SESSION['role']) && strtoupper($_SESSION['role']) === 'MANAGER'): ?>
                <li class="nav-item <?= in_array($page, ['ticket_create', 'ticket_list']) ? 'active' : '' ?>">
                    <a href="#" class="d-flex justify-content-between align-items-center" data-bs-toggle="collapse" data-bs-target="#ticketMenu" aria-expanded="false">
                        <span>Quản lý Phiếu Kho</span>
                        <i class="fas fa-caret-down"></i>
                    </a>
                    <ul class="collapse list-unstyled ms-3 mt-2 <?= in_array($page, ['ticket_create', 'ticket_list']) ? 'show' : '' ?>" id="ticketMenu">
                        <li class="mb-2">
                            <a href="index.php?page=ticket_create&type=IMPORT" class="ticket-link <?= ($page === 'ticket_create' && isset($_GET['type']) && $_GET['type'] === 'IMPORT') ? 'ticket-active' : '' ?>">
                                + Tạo phiếu nhập
                            </a>
                        </li>
                        <li class="mb-2">
                            <a href="index.php?page=ticket_create&type=EXPORT" class="ticket-link <?= ($page === 'ticket_create' && isset($_GET['type']) && $_GET['type'] === 'EXPORT') ? 'ticket-active' : '' ?>">
                                + Tạo phiếu xuất
                            </a>
                        </li>
                    </ul>
                </li>
            <?php endif; ?>

            <?php if (isset($_SESSION['role']) && strtoupper($_SESSION['role']) !== 'ADMIN'): ?>
                <li class="<?= $page === 'warehouse_map' ? 'active' : '' ?>">
                    <a href="index.php?page=warehouse_map">
                        <?= ($_SESSION['role'] === 'MANAGER') ? 'Quản lý kệ hàng' : 'Xem vị trí kệ hàng' ?>
                    </a>
                </li>
            <?php endif; ?>

            <?php if (isset($_SESSION['role']) && strtoupper($_SESSION['role']) === 'STAFF'): ?>
                <li class="<?= $page === 'staff_ticket_history' ? 'active' : '' ?>">
                    <a href="index.php?page=staff_ticket_history">
                        Lịch sử công việc
                    </a>
                </li>
            <?php endif; ?>

            <!-- <?php if (isset($_SESSION['role']) && strtoupper($_SESSION['role']) !== 'ADMIN'): ?>
                <li>
                    <a href="#" id="btn-open-ai" class="<?= $page === 'ai-prediction' ? 'active' : '' ?>">Trợ lý AI</a>
                </li>
            <?php endif; ?> -->
        </ul>
    </div>

    <form method="POST" action="index.php" class="mt-3" id="logout-form">
        <button type="submit" name="logout" class="logout w-100">Đăng xuất</button>
    </form>
</aside>

<?php if (isset($_SESSION['role']) && strtoupper($_SESSION['role']) !== 'ADMIN'): ?>
    <!-- Giao diện AI Chatbot -->
    <div id="ai-chat-container" class="ai-chat-minimized">
        <div class="ai-chat-header d-flex justify-content-between align-items-center px-3 py-2 bg-dark text-white">
            <div class="d-flex align-items-center">
                <div class="bg-success rounded-circle me-2 animate-pulse" style="width: 8px; height: 8px;"></div>
                <span class="fw-bold" style="font-size: 13px; letter-spacing: 0.5px;">SMART AI ASSISTANT</span>
            </div>
            <button id="ai-chat-minimize" class="btn btn-sm text-white p-0 border-0">
                <i class="fas fa-minus"></i>
            </button>
        </div>

        <div id="ai-chat-body" class="p-3 bg-white">
            <div class="bot-msg shadow-sm">Chào bạn Tôi là trợ lý AI. Bạn cần hỏi gì về hàng hóa hay tồn kho không?</div>
        </div>

        <div class="ai-chat-footer p-2 bg-light border-top">
            <div class="input-group">
                <input type="text" id="ai-chat-input" class="form-control form-control-sm border-0 bg-transparent" placeholder="Hỏi về kho (VD: Nike size 40 còn mấy đôi?)..." autocomplete="off">
                <button id="ai-chat-send" class="btn btn-link text-primary p-0 px-2">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Load script riêng của AI Chatbot -->
<script src="assets/js/sidebar.js"></script>