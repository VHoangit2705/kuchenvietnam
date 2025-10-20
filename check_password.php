<?php
// Kết nối đến cơ sở dữ liệu
include('config.php'); // Thay 'config.php' bằng file kết nối CSDL của bạn

// Kiểm tra xem có dữ liệu gửi đến hay không
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    // Lấy mật khẩu người dùng nhập vào
    $password = $_POST['password'];

    // Mã hóa mật khẩu người dùng nhập vào bằng MD5
    $hashedPassword = md5($password);

    // Tạo câu lệnh chuẩn bị SQL để tìm kiếm thông tin người dùng
    $stmt = $conn->prepare("SELECT * FROM users WHERE password = ?");
    $stmt->bind_param("s", $hashedPassword); // Gắn giá trị mật khẩu MD5 vào câu lệnh

    // Thực thi câu lệnh
    $stmt->execute();

    // Lấy kết quả trả về
    $stmt->store_result();

    // Kiểm tra xem có người dùng nào khớp không
    if ($stmt->num_rows > 0) {
        // Nếu tìm thấy người dùng, trả về thành công
        $response = array('success' => true);
    } else {
        // Nếu không tìm thấy, trả về lỗi
        $response = array('success' => false, 'message' => 'Mật khẩu không đúng');
    }

    // Đóng kết nối
    $stmt->close();
    $conn->close();

    // Trả về kết quả dưới dạng JSON
    echo json_encode($response);
}
?>
