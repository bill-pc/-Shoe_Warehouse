<?php
$error = isset($error) ? $error : '';
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập Smart Warehouse</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/login.css">
</head>

<body class="d-flex flex-column min-vh-100 m-0 p-0">

    <header class="w-100 shadow-sm position-relative z-3">
        <?php include __DIR__ . '/../layouts/header.php'; ?>
    </header>

    <main class="flex-grow-1 container-fluid p-0 d-flex login-bg">
        <div class="row g-0 w-100">

            <div class="col-lg-8 d-none d-lg-flex align-items-center justify-content-center position-relative left-panel">
                <div class="overlay-blur"></div>
                <div class="z-2 text-center text-white px-4">
                    <h1 class="hero-title display-2 fw-bold mb-3">Shoe Warehouse AI</h1>
                    <p class="fs-5 fw-light opacity-75">Hệ thống quản lý kho thông minh thế hệ mới</p>
                </div>
            </div>

            <div class="col-lg-4 d-flex align-items-center justify-content-center right-panel">
                <div class="login-box w-100 px-4 px-xl-5">

                    <div class="text-center mb-5">
                        <h2 class="fw-bold text-white mb-1">SMART WAREHOUSE</h2>
                    </div>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-glass-danger alert-glass-blink text-center small py-2 rounded-1 border-0" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <strong>LỖI:</strong> <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="custom-label">Mã nhân viên (ID)</label>
                            <input type="text" name="user_id" class="form-control rounded-3" placeholder="Nhập mã nhân viên gồm 6 chữ số..." required pattern="\d{6}" title="Mã nhân viên phải gồm 6 chữ số">
                        </div>


                        <div class="mb-4">
                            <label class="custom-label">Mật khẩu</label>
                            <input type="password" name="password" class="form-control rounded-3" placeholder="Nhập mật khẩu..." required>
                        </div>

                        <button type="submit" name="login" class="btn btn-login w-100 py-3 rounded-3 fw-bold fs-6">
                            ĐĂNG NHẬP
                        </button>
                    </form>

                </div>
            </div>
        </div>
    </main>

    <footer class="w-100 bg-light border-top">
        <?php include __DIR__ . '/../layouts/footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>