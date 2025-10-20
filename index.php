<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();
include 'config.php'; // File kết nối cơ sở dữ liệu

if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}

// Tự động đăng nhập bằng cookie "remember_me"
if (isset($_COOKIE['remember_me']) && $_COOKIE['remember_me'] !== '') {
    $cookie_value = $_COOKIE['remember_me'];

    // Kiểm tra cookie trong cơ sở dữ liệu
    $stmt = $conn->prepare("SELECT full_name, position FROM users WHERE cookie_value = ?");
    if (!$stmt) {
        die("Lỗi truy vấn: " . $conn->error);
    }

    $stmt->bind_param("s", $cookie_value);
    if (!$stmt->execute()) {
        die("Lỗi thực thi: " . $stmt->error);
    }

    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $_SESSION['full_name'] = $row['full_name'];
        $_SESSION['position'] = $row['position'];

        // Chuyển hướng
        if ($row['position'] === 'admin' || $row['position'] === 'Đơn hàng Vinh' || $row['position'] === 'Đơn hàng HaNoi' || $row['position'] === 'Đơn hàng HCM') {
            header("Location: admin.php");
        } else {
            header("Location: xem_donhang.php");
        }
        $stmt->close();
        $conn->close();
        exit();
    } else {
        // Xóa cookie không hợp lệ
        setcookie("remember_me", "", time() - 3600, "/");
        echo "<script>alert('Phiên đăng nhập không hợp lệ. Vui lòng đăng nhập lại!'); window.location.href='index.php';</script>";
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Nhập - KHO KUCHEN</title>
    <link rel="icon" href="logoblack.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: #f8f9fa;
        }
        .login-container {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2 class="text-center">ĐĂNG NHẬP HỆ THỐNG ĐƠN HÀNG - QR BẢO HÀNH </h2>
        <form action="login.php" method="POST">
            <div class="form-group">
                <label for="password">Mật Khẩu</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Đăng Nhập</button>
        </form>
    </div>
    <script type="text/javascript">
    // Assuming you have the full name available in PHP
    var fullName = "<?php echo $_SESSION['full_name']; ?>"; // Get full name from PHP session
    // Send the full name to the Android app
    Android.setFullName(fullName);
</script>

</body>
</html>
