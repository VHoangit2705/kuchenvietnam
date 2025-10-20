<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="logoblack.ico" type="image/x-icon">
    <title>Chăm sóc khách hàng</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <!-- CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet"/>
    <!-- JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
</head>
<body>
    <div class="container-fluid mt-5">
        <h2>DANH SÁCH ĐƠN HÀNG</h2>
        <?php
            // Kết nối đến MySQL
            include'../config.php';
             date_default_timezone_set('Asia/Ho_Chi_Minh');
            // Kiểm tra kết nối
            if ($conn->connect_error) {
                die("Kết nối thất bại: " . $mysqli->connect_error);
            }
            // Lấy danh sách khuyến mại
            $sql = "SELECT o.customer_name, o.customer_phone, o.customer_address, o.created_at, o.notif_date, op.product_name, op.order_id, op.quantity, p.reminder_time
                    FROM orders o 
                        JOIN order_products op ON o.id = op.order_id
                        JOIN products p ON op.product_name = p.product_name 
                   WHERE p.reminder_time IS NOT NULL
                        AND DATE(COALESCE(o.notif_date, o.created_at)) = DATE_SUB(CURDATE(), INTERVAL p.reminder_time MONTH) 
                        AND o.status = 'Đã quét QR' 
                        AND o.status_tracking = 'Giao thành công'
                    ORDER BY o.created_at ASC, o.customer_name ASC";
            $result = $conn->query($sql);

            //chuyển đổi thành json
            $data = $conn->query($sql);
            $datajson = [];  // =>>> data trả vê dạng json
            if ($data && $data->num_rows > 0) {
                while ($row = $data->fetch_assoc()) {
                    $datajson[] = $row;
                }
            } else {
                // Nếu không có dữ liệu hoặc lỗi
                $data = ['error' => $conn->error ?: 'Không có kết quả'];
            }
            // echo "<pre>";
            //     print_r($datajson);
            // echo "</pre>";
        ?>  
        <table class="table table-bordered table-hover text-center align-middle">
            <thead class="table-light">
                <tr>
                    <th>STT</th>
                    <th>Tên sản phẩm</th>
                    <!-- <th>Số lượng</th> -->
                    <th>Tên khách hàng</th>
                    <th>Số điện thoại</th>
                    <th>Địa chỉ</th>
                    <th>Ngày tạo đơn</th>
                    <th>Ngày nhắc gần nhất</th>
                </tr>
            </thead>
            <tbody>
               <?php
                    $stt = 1;
                    $grouped = [];
                    while ($row = $result->fetch_assoc()) {
                        $key = $row['order_id'];
                        $grouped[$key][] = $row;
                    }
                    foreach ($grouped as $rows) {
                        $rowspan = count($rows);
                        $first = true;
                        $count_number = true;
                        foreach ($rows as $row) {
                            echo "<tr>";
                            if ($first) {
                                echo "<td rowspan='{$rowspan}'>{$stt}</td>";
                                $stt++;
                            }
                            echo "<td style='text-align: left;'>{$row['product_name']}</td>";
                            // echo "<td>{$row['quantity']}</td>";
                            if ($first) {
                                echo "<td rowspan='{$rowspan}'>{$row['customer_name']}</td>";
                                echo "<td rowspan='{$rowspan}'>{$row['customer_phone']}</td>";
                                echo "<td rowspan='{$rowspan}' style='text-align: left;'>{$row['customer_address']}</td>";
                                echo "<td rowspan='{$rowspan}'>" . date('d/m/Y', strtotime($row['created_at'])) . "</td>";
                                echo "<td rowspan='{$rowspan}'>" . (!empty($row['notif_date']) ? date('d/m/Y', strtotime($row['notif_date'])) : '') . "</td>";
                                $first = false;
                            }
                            echo "</tr>";
                            
                        }
                    }
                    ?>
            </tbody>
        </table>
    </div>
</body>
</html>