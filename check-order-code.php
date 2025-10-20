<?php
// Thiết lập loại nội dung trả về là JSON
header('Content-Type: application/json');

// Kiểm tra phương thức yêu cầu là POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Lấy dữ liệu JSON từ yêu cầu
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['orderCode'])) {
        // Nếu không có orderCode, trả về lỗi
        echo json_encode(['error' => 'orderCode is required']);
        exit;
    }
    $orderCode = $input['orderCode'];

    // Kết nối CSDL
    include 'config.php'; // Tệp chứa thông tin kết nối DB

    if ($conn->connect_error) {
        // Nếu có lỗi kết nối, trả về thông báo lỗi
        die(json_encode(['isUnique' => false, 'error' => $conn->connect_error]));
    }

    // Kiểm tra mã trong bảng orders
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE order_code2 = ?");
    $stmt->bind_param("s", $orderCode);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    $conn->close();

    // Nếu không có bản ghi trùng, trả về isUnique = true
    if ($count == 0) {
        echo json_encode(['isUnique' => true]);
    } else {
        echo json_encode(['isUnique' => false]);
    }

} else {
    // Nếu không phải POST request, trả về lỗi
    echo json_encode(['error' => 'Invalid request method']);
}
?>
