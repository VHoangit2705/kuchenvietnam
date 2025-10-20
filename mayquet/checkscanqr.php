<?php
session_start();
if (!isset($_SESSION['full_name'])) {
    header("Location: index.php");
    exit();
}
include '../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_code'])) {
    $orderCode = $_POST['order_code'];

    // Kiểm tra trạng thái đơn hàng và lấy thông tin sản phẩm
    $sqlCheck = "
        SELECT o.order_code2, o.created_at, op.product_name, op.quantity, o.status 
        FROM orders o
        INNER JOIN order_products op ON o.id = op.order_id
        WHERE (o.order_code2 = ? OR o.order_code1 = ?)
    ";
    $stmt = $conn->prepare($sqlCheck);
    $stmt->bind_param('ss', $orderCode, $orderCode);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $order = [];
        while ($row = $result->fetch_assoc()) {
            $order[] = $row; // Store each product for the order
        }

        // Check if the order status is "Đang chờ quét QR"
        if ($order[0]['status'] === 'Đang chờ quét QR') {
            // Cập nhật trạng thái thành "Đang quét QR"
            $sqlUpdate = "UPDATE orders SET status = 'Đang quét QR' WHERE order_code2 = ? or order_code1 = ? ";
            $stmt = $conn->prepare($sqlUpdate);
            $stmt->bind_param('ss', $orderCode, $orderCode);
            $stmt->execute();

            // Display order details
            echo '
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
                <title>Thông tin đơn hàng</title>
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
                    .order-details {
                        font-size: 1rem;
                        margin-top: 20px;
                    }
                    .countdown {
                        font-size: 1.5rem;
                        font-weight: bold;
                        color: #d9534f;
                        text-align: center;
                        margin-top: 20px;
                    }
                </style>
            </head>
            <body>
                <div class="alert-container">
                    <div class="alert alert-success">
                        <h4 class="alert-heading">Thông tin đơn hàng</h4>
                        <p><strong>Mã đơn hàng:</strong> ' . htmlspecialchars($order[0]['order_code2']) . '</p>
                        <p><strong>Ngày tạo:</strong> ' . htmlspecialchars($order[0]['created_at']) . '</p>';

            // Display the products in a table
            echo '
                        <table class="table table-bordered mt-3">
                            <thead>
                                <tr>
                                    <th>Tên sản phẩm</th>
                                    <th>Số lượng</th>
                                </tr>
                            </thead>
                            <tbody>';
            
            foreach ($order as $product) {
                echo '
                    <tr>
                        <td>' . htmlspecialchars($product['product_name']) . '</td>
                        <td>' . htmlspecialchars($product['quantity']) . '</td>
                    </tr>';
            }
            
            echo '
                            </tbody>
                        </table>';

            echo '
                        <div class="countdown" id="countdown">Sẵn sàng quét trong vòng 5s...</div>
                        <div class="order-details">
                            <p>Đơn hàng này đang chờ quét QR. Chúng tôi sẽ chuyển trang sau 5 giây.</p>
                        </div>
                    </div>
                </div>

                <script>
                    let countdown = 5;
                    const countdownElement = document.getElementById("countdown");

                    const countdownInterval = setInterval(function() {
                        countdown--;
                        countdownElement.textContent = "Sẵn sàng quét trong vòng " + countdown + "s...";

                        if (countdown <= 0) {
                            clearInterval(countdownInterval);
                            window.location.href = "scanqr2.php?order_code=' . urlencode($orderCode) . '";
                        }
                    }, 1000);
                </script>
            </body>
            </html>';
            exit();
        } else {
            echo '
            <!DOCTYPE html>
            <html lang="en">
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
                        <p>Đơn hàng này đã được xử lý hoặc đang được quét.</p>
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
        echo '
        <!DOCTYPE html>
        <html lang="en">
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
                    <p>Không tìm thấy đơn hàng.</p>
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
}
?>