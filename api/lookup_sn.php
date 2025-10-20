
<?php
// Tra cứu SN trong cơ sở dữ liệu
if (isset($_GET['sn'])) {
    $sn = $_GET['sn'];
    include '../config.php';

    // Kiểm tra kết nối
    if (!$conn) {
        die(json_encode(['error' => 'Database connection failed: ' . mysqli_connect_error()]));
    }

    // Chuẩn bị và thực thi truy vấn
    $sn = mysqli_real_escape_string($conn, $sn);
    $query = "SELECT product_id, product_name FROM serial_numbers WHERE sn = '$sn' LIMIT 1";
    $result = mysqli_query($conn, $query);

    // Kiểm tra kết quả
    header('Content-Type: application/json');
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        echo json_encode($row);
    } else {
        echo json_encode(['error' => 'Mã SN chưa có trong hệ thống']);
    }

    // Đóng kết nối
    mysqli_close($conn);
    exit;
}
?>
