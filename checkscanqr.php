<?php
session_start();
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_code'])) {
    $orderCode = $_POST['order_code'];

    // Kiểm tra trạng thái đơn hàng
    $sqlCheck = "SELECT id, status FROM orders WHERE order_code2 = ? OR order_code1 = ?";
    $stmt = $conn->prepare($sqlCheck);
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param('ss', $orderCode, $orderCode);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $order = $result->fetch_assoc();
        $orderId = $order['id'];
        $status = $order['status'];

        if ($status === 'Đang chờ quét QR') {
            // Cập nhật trạng thái thành "Đang quét QR"
            $sqlUpdate = "UPDATE orders SET status = 'Đang quét QR', lock_timestamp = NOW() WHERE order_code2 = ? OR order_code1 = ?";
            $stmt = $conn->prepare($sqlUpdate);
            if (!$stmt) {
                die("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param('ss', $orderCode, $orderCode);
            $stmt->execute();

            // Chuyển hướng đến trang quét QR
            header("Location: scanqr2.php?order_code=" . urlencode($orderCode));
            exit;
        } else {
            // Trạng thái không hợp lệ → lấy người quét + thời gian
            $sqlQuet = "
                SELECT pw.created_at, pw.name 
                FROM order_products op 
                JOIN product_warranties pw ON pw.order_product_id = op.id 
                WHERE op.order_id = ? 
                ORDER BY pw.created_at DESC LIMIT 1
            ";
            $stmt = $conn->prepare($sqlQuet);
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $resQuet = $stmt->get_result();

            $nguoiQuet = 'Không xác định';
            $thoigianQuet = 'Không có dữ liệu';

            if ($resQuet->num_rows > 0) {
                $rowQuet = $resQuet->fetch_assoc();
                $nguoiQuet = htmlspecialchars($rowQuet['name']);
                $thoigianQuet = date('d/m/Y H:i', strtotime($rowQuet['created_at']));
            }

            // Thông báo lỗi + trạng thái + người quét + thời gian
            echo '
            <!DOCTYPE html>
            <html lang="vi">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
                <title>Thông báo</title>
                <style>
                    .alert-container {
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        height: 100vh;
                        background-color: #f8f9fa;
                    }
                    .alert {
                        font-size: 1.2rem;
                        text-align: center;
                        padding: 1.5rem;
                        border-radius: 0.5rem;
                    }
                </style>
            </head>
            <body>
                <div class="alert-container">
                    <div class="alert alert-warning">
                        <h4 class="alert-heading">Thông báo</h4>
                        <p>Đơn hàng này không thể quét vì đang ở trạng thái: <strong>' . htmlspecialchars($status) . '</strong></p>
                        <p>Người quét gần nhất: <strong>' . $nguoiQuet . '</strong></p>
                        <p>Thời gian quét: <strong>' . $thoigianQuet . '</strong></p>
                        <hr>
                        <p class="mb-0">Bạn sẽ được chuyển về trang xem đơn hàng trong 5 giây...</p>
                    </div>
                </div>
                <script>
                    setTimeout(function() {
                        window.location.href = "xem_donhang.php";
                    }, 5000);
                </script>
            </body>
            </html>';
            exit();
        }
    } else {
        // Không tìm thấy đơn hàng
        echo '
        <!DOCTYPE html>
        <html lang="vi">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
            <title>Thông báo</title>
            <style>
                .alert-container {
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    height: 100vh;
                    background-color: #f8f9fa;
                }
                .alert {
                    font-size: 1.2rem;
                    text-align: center;
                    padding: 1.5rem;
                    border-radius: 0.5rem;
                }
            </style>
        </head>
        <body>
            <div class="alert-container">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">Thông báo</h4>
                    <p>Không tìm thấy đơn hàng trong hệ thống. Vui lòng báo bộ phận đi đơn kiểm tra.</p>
                    <hr>
                    <p class="mb-0">Bạn sẽ được chuyển về trang xem đơn hàng trong 5 giây...</p>
                </div>
            </div>
            <script>
                setTimeout(function() {
                    window.location.href = "xem_donhang.php";
                }, 8000);
            </script>
        </body>
        </html>';
        exit();
    }
}
?>
