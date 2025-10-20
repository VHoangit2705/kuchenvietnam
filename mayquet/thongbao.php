<?php
session_start();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thông Báo Thành Công</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h3>Thông Báo</h3>
        <?php
        if (isset($_SESSION['success_message'])) {
            echo '<div class="alert alert-success" role="alert">' . $_SESSION['success_message'] . '</div>';
            // Sau khi hiển thị thông báo, xóa session để tránh hiển thị lại
            unset($_SESSION['success_message']);
        }
        ?>
        <a href="xem_donhang.php" class="btn btn-primary">Quay lại trang quét đơn hàng</a>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.3/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <!-- Thêm script tự động quay lại trang chính sau 3 giây -->
    <script>
        setTimeout(function() {
            window.location.href = 'xem_donhang.php'; // Quay lại trang chính sau 3 giây
        }, 3000); // 3000ms = 3 giây
    </script>
</body>
</html>
