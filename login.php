<?php
session_start();
include 'config.php'; // mysqli $conn

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input_password = $_POST['password'] ?? '';

    // TODO: Khuyến nghị chuyển sang password_hash/password_verify.
    // Tạm thời vẫn MD5 để tương thích dữ liệu hiện có:
    $hashed_password = md5($input_password);

    // Chỉ tìm theo password là KHÔNG an toàn (ai biết pass là vào đc).
    // Khuyến nghị thêm username/sdt:
    // $stmt = $conn->prepare("SELECT id, full_name, position FROM users WHERE sdt = ? AND password = ?");
    // $stmt->bind_param("ss", $sdt, $hashed_password);
    // Ở đây để giữ đúng với code cũ:
    $stmt = $conn->prepare("SELECT id, full_name, position FROM users WHERE password = ? LIMIT 1");
    $stmt->bind_param("s", $hashed_password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($u = $result->fetch_assoc()) {
        // 1) Tạo session
        session_regenerate_id(true);
        $_SESSION['user_id']   = (int)$u['id'];
        $_SESSION['full_name'] = $u['full_name'];
        $_SESSION['position']  = $u['position'];

        // 2) Phát hành token mới -> ghi đè token cũ
        $token = bin2hex(random_bytes(32)); // 64 hex chars
        $upd = $conn->prepare("UPDATE users SET cookie_value = ? WHERE id = ?");
        $upd->bind_param("si", $token, $u['id']);
        $upd->execute();
        $upd->close();

        // 3) Set cookie remember_me (3 ngày)
        $cookieOpts = [
            'expires'  => time() + 259200,                  // 3 ngày
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),        // bật nếu HTTPS
            'httponly' => true,
            'samesite' => 'Strict'
        ];
        setcookie('remember_me', $token, $cookieOpts);

        // 4) Điều hướng theo role
        if (in_array($u['position'], ['admin','Đơn hàng Vinh','Đơn hàng HaNoi','Đơn hàng HCM'], true)) {
            header("Location: admin.php");
        } else {
            header("Location: xem_donhang.php");
        }

        $stmt->close();
        $conn->close();
        exit();
    } else {
        // Sai mật khẩu
        $stmt->close();
        $conn->close();
        echo "<script>alert('Mật khẩu không đúng!'); window.location.href='index.php';</script>";
        exit();
    }
}

$conn->close();
