<?php
session_start();

// Chỉ admin mới được phép truy cập chức năng chuyển trạng thái
if (!isset($_SESSION['position']) || $_SESSION['position'] !== 'admin') {
    header("Location: index.php");
    exit();
}

include 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Kiểm tra xem biến 'id' có tồn tại hay không
    if (!isset($_POST['id']) || empty($_POST['id'])) {
        die("Không có id đơn hàng.");
    }

    $orderId = $_POST['id'];
    $newStatus = "Đã quét QR";

    // Chuẩn bị câu lệnh cập nhật trạng thái dựa theo id
    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("si", $newStatus, $orderId);

    if ($stmt->execute()) {
        echo "<div class='container mt-4'>";
        echo "<div class='alert alert-success text-center' role='alert'>";
        echo "Cập nhật trạng thái đơn hàng thành công!";
        echo "</div>";
        echo "<div class='text-center'>";
        echo "<a href='order_detail.php?id=$orderId' class='btn btn-primary'>Quay lại chi tiết đơn hàng</a>";
        echo "</div>";
        echo "</div>";
    } else {
        die("Lỗi cập nhật: " . $stmt->error);
    }

    $stmt->close();
    $conn->close();
    exit();
} else {
    // Nếu yêu cầu gửi bằng GET: hiển thị xác nhận chuyển trạng thái
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        die("Không có id đơn hàng.");
    }
    $orderId = $_GET['id'];
    ?>
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title>Xác nhận chuyển trạng thái</title>
        <!-- Sử dụng Bootstrap 4 từ CDN -->
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
        <style>
            body {
                background-color: #f7f7f7;
            }
            .confirm-card {
                max-width: 500px;
                margin: 80px auto;
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            }
            .confirm-card .card-header {
                background-color: #007bff;
                color: #fff;
                text-align: center;
                font-size: 1.25rem;
            }
            .confirm-card .card-body p {
                font-size: 1.1rem;
            }
            .confirm-card .card-footer {
                text-align: center;
                font-size: 0.9rem;
                color: #6c757d;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="card confirm-card mt-5">
                <div class="card-header">
                    Xác nhận chuyển trạng thái đơn hàng
                </div>
                <div class="card-body">
                    <p>
                        Bạn có chắc muốn chuyển trạng thái của đơn hàng có định danh <strong><?php echo htmlspecialchars($orderId); ?></strong> sang <strong>"Đã hoàn tất"</strong> không?
                    </p>
                    <form method="POST" action="change_status.php" onsubmit="return confirm('Bạn chắc chắn chuyển trạng thái đơn hàng này?');">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($orderId); ?>">
                        <div class="form-group">
                            <button type="submit" class="btn btn-success btn-block">Đồng ý</button>
                        </div>
                        <div class="form-group">
                            <a href="javascript:history.back()" class="btn btn-secondary btn-block">Hủy</a>
                        </div>
                    </form>
                </div>
                <div class="card-footer">
                    &copy; <?php echo date('Y'); ?> Hệ thống quản lý đơn hàng
                </div>
            </div>
        </div>

        <!-- JavaScript cần thiết của Bootstrap 4 -->
        <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
        <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    </body>
    </html>
    <?php
}
?>
