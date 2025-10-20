<?php
// CẤU HÌNH KẾT NỐI CSDL (điền thông tin của bạn vào đây)
include'../config.php';
if (!$conn) {
    die("Kết nối cơ sở dữ liệu thất bại: " . mysqli_connect_error());
}

// LẤY MÃ ĐƠN HÀNG từ URL (ví dụ: save_evaluation.php?id=123)
if (!isset($_GET['id'])) {
    die("Mã đơn hàng không được truyền.");
}
$orderId = $_GET['id'];
$delivery_person_id = $_GET['nv'];
// KIỂM TRA: Nếu đơn hàng đã có đánh giá, từ chối lưu thêm
$sqlCheck = "SELECT COUNT(*) as cnt FROM feedbacks WHERE order_id = ?";
$stmtCheck = mysqli_prepare($conn, $sqlCheck);
if (!$stmtCheck) {
    die("Prepare failed: " . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmtCheck, "i", $orderId);
mysqli_stmt_execute($stmtCheck);
$resultCheck = mysqli_stmt_get_result($stmtCheck);
$rowCheck = mysqli_fetch_assoc($resultCheck);
if ($rowCheck['cnt'] > 0) {
    die("Đánh giá cho đơn hàng này đã được lưu.");
}
mysqli_stmt_close($stmtCheck);

// LẤY DỮ LIỆU ĐÁNH GIÁ từ form (gửi qua phương thức POST)
$tc1 = isset($_POST['tc1']) ? trim($_POST['tc1']) : "";
$tc2 = isset($_POST['tc2']) ? trim($_POST['tc2']) : "";
$tc3 = isset($_POST['tc3']) ? trim($_POST['tc3']) : "";
$tc4 = isset($_POST['tc4']) ? trim($_POST['tc4']) : "";
$tc5 = isset($_POST['tc5']) ? trim($_POST['tc5']) : "";
$comment = isset($_POST['userFeedback']) ? trim($_POST['userFeedback']) : "";

// Hàm chuyển đổi đánh giá theo chữ thành điểm số
function getScore($rating) {
    switch ($rating) {
        case 'Hài lòng':
            return 1;
        case 'Bình thường':
            return 0.5;
        case 'Không hài lòng':
            return 0;
        default:
            return 0;
    }
}

$score1 = getScore($tc1);
$score2 = getScore($tc2);
$score3 = getScore($tc3);
$score4 = getScore($tc4);
$score5 = getScore($tc5);

// TÍNH TỔNG ĐIỂM ĐÁNH GIÁ (tối đa 5 nếu tất cả là "Hài lòng")
$totalScore = $score1 + $score2 + $score3 + $score4 + $score5;
$rating = $totalScore;

// LẤY ĐỊA CHỈ IP của người dùng
$ip = $_SERVER['REMOTE_ADDR'];

// CHÈN DỮ LIỆU ĐÁNH GIÁ VÀO CSDL (không lưu MAC)
$sqlInsert = "INSERT INTO feedbacks (order_id, delivery_person_id, rating, tc1, tc2, tc3, tc4, tc5, comment, ip)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmtInsert = mysqli_prepare($conn, $sqlInsert);
if (!$stmtInsert) {
    die("Prepare failed: " . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmtInsert, "iidsssssss", $orderId, $delivery_person_id, $rating, $tc1, $tc2, $tc3, $tc4, $tc5, $comment, $ip);
$resultInsert = mysqli_stmt_execute($stmtInsert);

if ($resultInsert) {
    header("Location: thank_you.php?orderId=" . urlencode($orderId));
    exit();
} else {
    echo "Có lỗi xảy ra khi lưu đánh giá: " . mysqli_stmt_error($stmtInsert);
}

mysqli_stmt_close($stmtInsert);
mysqli_close($conn);
?>
