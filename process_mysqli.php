<?php
// Thiết lập mã hóa đầu ra
header('Content-Type: text/html; charset=utf-8');

// Cấu hình kết nối cho hai database
$dbConfig1 = [
    'host' => 'localhost',
    'username' => 'kuchenvi_kho_kuchen',
    'password' => 'HYvqPg65uJWQ3AhtKGdN',
    'dbname' => 'kuchenvi_kho_kuchen',
    'port' => 3306 // Cổng cho Database 1
];

$dbConfig2 = [
    'host' => 'localhost',
    'username' => 'kuchenvi_zalo_oa',
    'password' => '9qherKbdcTw4H5V8gUUt',
    'dbname' => 'kuchenvi_zalo_oa',
    'port' => 3307 // Cổng cho Database 2
];

// Hàm tạo kết nối
function connectDatabase($config)
{
    $conn = mysqli_connect($config['host'], $config['username'], $config['password'], $config['dbname'], $config['port']);
    if (!$conn) {
        die("Lỗi kết nối đến cơ sở dữ liệu: " . mysqli_connect_error());
    }
    // Đảm bảo kết nối sử dụng mã hóa UTF-8
    mysqli_set_charset($conn, 'utf8');
    return $conn;
}

// Kết nối đến hai cơ sở dữ liệu
$conn1 = connectDatabase($dbConfig1);
$conn2 = connectDatabase($dbConfig2);

// Lấy tất cả các hàng có `status_tracking = "Giao thành công"` từ bảng `orders`
$query1 = "SELECT customer_phone FROM orders WHERE status_tracking = 'Giao thành công'";
$result1 = mysqli_query($conn1, $query1);

if ($result1) {
    // Tạo danh sách số điện thoại đã chuyển đổi
    $phoneNumbers = [];
    while ($row = mysqli_fetch_assoc($result1)) {
        // Chuyển đổi số điện thoại
        $phone = $row['customer_phone'];
        if (substr($phone, 0, 1) === '0') {
            $convertedPhone = '84' . substr($phone, 1); // Thay số 0 đầu bằng 84
            $phoneNumbers[] = $convertedPhone;
        }
    }

    // Hiển thị danh sách số điện thoại đã chuyển đổi trong bảng
    echo "<h2>Danh sách số điện thoại đã chuyển đổi:</h2>";
    echo "<table border='1'>
            <tr><th>Số điện thoại đã chuyển đổi</th></tr>";
    foreach ($phoneNumbers as $phone) {
        echo "<tr><td>$phone</td></tr>";
    }
    echo "</table><br>";

} else {
    die("Lỗi truy vấn Database 1: " . mysqli_error($conn1));
}

// Lấy tất cả `recipient_id` từ bảng `webhook_data`
$query2 = "SELECT recipient_id FROM webhook_data";
$result2 = mysqli_query($conn2, $query2);

if ($result2) {
    // Lấy danh sách `recipient_id`
    $recipientIds = [];
    while ($row = mysqli_fetch_assoc($result2)) {
        $recipientIds[] = $row['recipient_id'];
    }

    // So sánh và in các số trùng
    $matchingPhones = array_intersect($phoneNumbers, $recipientIds);

    if (!empty($matchingPhones)) {
        echo "<h2>Số điện thoại trùng khớp:</h2>";
        echo "<table border='1'>
                <tr><th>Số điện thoại trùng khớp</th></tr>";
        foreach ($matchingPhones as $phone) {
            echo "<tr><td>$phone</td></tr>";
        }
        echo "</table>";
    } else {
        echo "Không có số điện thoại nào trùng khớp.";
    }
} else {
    die("Lỗi truy vấn Database 2: " . mysqli_error($conn2));
}

// Đóng kết nối
mysqli_close($conn1);
mysqli_close($conn2);
?>
