<?php
session_start();
// Xử lý đăng nhập qua POST
include '../config.php'; // File kết nối cơ sở dữ liệu
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input_password = $_POST['password'];
    $hashed_password = md5($input_password); // Sử dụng password_hash() nếu có thể

    $stmt = $conn->prepare("SELECT full_name, position, id FROM users WHERE password = ?");
    $stmt->bind_param("s", $hashed_password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $_SESSION['full_name'] = $row['full_name'];
        $_SESSION['position'] = $row['position'];

        // Tạo cookie "remember_me"
        $cookie_value = md5(uniqid($row['id'], true));
        setcookie('remember_me', $cookie_value, time() + 259200, "/", "", isset($_SERVER['HTTPS']), true);

        // Lưu cookie vào cơ sở dữ liệu
        $update_stmt = $conn->prepare("UPDATE users SET cookie_value = ? WHERE id = ?");
        $update_stmt->bind_param("si", $cookie_value, $row['id']);
        $update_stmt->execute();

        // Chuyển hướng
        if ($row['position'] === 'admin' || $row['position'] === 'Đơn hàng Vinh' || $row['position'] === 'Đơn hàng HaNoi' || $row['position'] === 'Đơn hàng HCM') {
            header("Location: admin.php");
        } else {
            header("Location: xem_donhang.php");
        }
        $stmt->close();
        $update_stmt->close();
        $conn->close();
        exit();
    } else {
        echo "<script>alert('Mật khẩu không đúng!'); window.location.href='index.php';</script>";
    }
}

$conn->close();
?>
