<?php
// Include file cấu hình kết nối cơ sở dữ liệu
include '../config.php';

// Thiết lập header cho JSON và CORS nếu cần
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *"); // Có thể giới hạn nếu cần
header("Access-Control-Allow-Headers: Authorization");

// ====== Xác thực API KEY ======
$headers = getallheaders(); // Lấy tất cả header
$apiKeyHeader = isset($headers['Authorization']) ? trim($headers['Authorization']) : '';

// Định nghĩa API Key hợp lệ (có thể lưu trong biến môi trường hoặc CSDL)
$validApiKey = 'e8a781ff6fbe6be07a870cd2a6a8f445'; // Thay bằng API key thật

if ($apiKeyHeader !== 'Bearer ' . $validApiKey) {
    http_response_code(401); // Unauthorized
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Invalid API key'
    ]);
    exit;
}

// ====== Kiểm tra kết nối CSDL ======
if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal Server Error'
    ]);
    exit;
}

// ====== LẤY THAM SỐ view TỪ URL ======
$allowedViews = [1, 2];
if (isset($_GET['view']) && in_array((int)$_GET['view'], $allowedViews, true)) {
    // Nếu truyền view hợp lệ (1 hoặc 2)
    $views = [(int)$_GET['view']];
} else {
    // Mặc định lấy cả view = 1 và view = 2
    $views = $allowedViews;
}
// Chuyển mảng thành chuỗi để dùng trong IN(...)
$viewsList = implode(',', $views);

// ====== Truy vấn lấy dữ liệu sản phẩm dựa trên view ======
$sql    = "SELECT id, product_name, month, price FROM products WHERE view IN ($viewsList)";
$result = mysqli_query($conn, $sql);

// Xử lý kết quả trả về
$data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['product_name'] = htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8');
    $data[] = $row;
}

// Trả về kết quả JSON
echo json_encode([
    'status' => 'success',
    'data' => $data
]);

mysqli_close($conn);
?>
