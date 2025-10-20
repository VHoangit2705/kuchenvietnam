<?php
// auth.php
declare(strict_types=1);
session_start();
include 'config.php'; // mysqli $conn

/**
 * Gửi header no-cache để tránh trình duyệt cache trang logout
 */
function send_no_cache_headers(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

/**
 * Render trang thông báo (Bootstrap 5, responsive) + auto-redirect
 */
function render_logout_page(string $message, string $redirectUrl = 'index.php', int $seconds = 5): void {
    // Nếu header chưa gửi, set status & no-cache
    if (!headers_sent()) {
        http_response_code(401); // Unauthorized / login timeout
        send_no_cache_headers();
    }

    // HTML trang đẹp với BS5, có countdown + fallback noscript
    echo '<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Đăng nhập lại</title>
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Auto redirect fallback -->
  <meta http-equiv="refresh" content="'.htmlspecialchars((string)$seconds, ENT_QUOTES).' ;url='.htmlspecialchars($redirectUrl, ENT_QUOTES).'">
  <style>
    body { min-height: 100svh; }
    .fade-card { animation: fadeIn .35s ease both; }
    @keyframes fadeIn { from {opacity:0; transform: translateY(6px);} to {opacity:1; transform: translateY(0);} }
  </style>
</head>
<body class="bg-light">
  <div class="d-flex align-items-center justify-content-center min-vh-100 px-3">
    <div class="card shadow-sm fade-card" style="max-width: 560px; width: 100%;">
      <div class="card-body p-4 p-md-5 text-center">
        <!-- Icon -->
        <div class="mb-3">
          <svg width="56" height="56" viewBox="0 0 24 24" fill="none" role="img" aria-label="Thông báo">
            <path d="M12 2a7 7 0 0 1 7 7v3.586l1.707 1.707A1 1 0 0 1 20.999 16H3a1 1 0 0 1-.707-1.707L4 12.586V9a7 7 0 0 1 8-7Z" stroke="currentColor" stroke-width="1.5"/>
            <path d="M9 19a3 3 0 0 0 6 0" stroke="currentColor" stroke-width="1.5"/>
          </svg>
        </div>

        <h1 class="h4 mb-2">Yêu cầu đăng nhập lại</h1>
        <p class="text-secondary mb-4">'.htmlspecialchars($message, ENT_QUOTES).'</p>

        <div class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
          <a class="btn btn-primary px-4" href="'.htmlspecialchars($redirectUrl, ENT_QUOTES).'">Đăng nhập ngay</a>
          <a class="btn btn-outline-secondary px-4" href="javascript:history.back()">Quay lại trang trước</a>
        </div>

        <div class="mt-4 small text-secondary">
          Tự động chuyển sau <span id="countdown">'.(int)$seconds.'</span> giây…
        </div>
      </div>
    </div>
  </div>

  <script>
    (function(){
      var s = '.(int)$seconds.';
      var el = document.getElementById("countdown");
      var timer = setInterval(function(){
        s--;
        if (el) el.textContent = s;
        if (s <= 0) {
          clearInterval(timer);
          window.location.replace("'.addslashes($redirectUrl).'");
        }
      }, 1000);
    })();
  </script>

  <noscript>
    <div class="container py-3">
      <div class="alert alert-info mt-3" role="alert">
        Trình duyệt của bạn đang tắt JavaScript. Bạn sẽ được chuyển hướng tự động.
        Nếu không, hãy bấm nút <strong>Đăng nhập ngay</strong>.
      </div>
    </div>
  </noscript>
</body>
</html>';
    exit();
}

/**
 * Logout session + cookie, sau đó render trang đẹp
 */
function logout_and_redirect(
    string $msg = "Phiên của bạn đã hết hạn hoặc được thay thế bởi đăng nhập trên thiết bị khác. Vui lòng đăng nhập lại.",
    string $redirect = "index.php",
    int $seconds = 5
): void {
    // Huỷ session
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), "", time() - 42000, $params["path"], $params["domain"] ?? "", $params["secure"], $params["httponly"]);
        }
        session_destroy();
    }
    // Xoá remember_me
    setcookie("remember_me", "", time() - 3600, "/", "", !empty($_SERVER["HTTPS"]), true);

    // Hiển thị trang thông báo (BS5) + auto-redirect
    render_logout_page($msg, $redirect, $seconds);
}

/* ------------------------- AUTO LOGIN / VERIFY ------------------------- */

// 1) Nếu chưa có session mà có cookie -> auto login
if (empty($_SESSION['user_id']) && !empty($_COOKIE['remember_me'])) {
    $token = $_COOKIE['remember_me'];

    $sql = "SELECT id, full_name, position FROM users WHERE cookie_value = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($user = $res->fetch_assoc()) {
        // Tìm thấy token hợp lệ -> dựng lại session
        session_regenerate_id(true);
        $_SESSION['user_id']   = (int)$user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['position']  = $user['position'];

        // (Tuỳ chọn) Gia hạn cookie
        setcookie("remember_me", $token, [
            "expires"  => time() + 259200,     // 3 ngày
            "path"     => "/",
            "secure"   => !empty($_SERVER["HTTPS"]),
            "httponly" => true,
            "samesite" => "Strict"
        ]);
    } else {
        // Cookie không hợp lệ -> xóa & buộc login
        $stmt->close();
        $conn->close();
        logout_and_redirect();
    }
    $stmt->close();
}

// 2) Nếu đã có session, verify cookie hiện tại có còn khớp DB
if (!empty($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];

    // Ép có cookie ở trang bảo vệ (siết single-login)
    if (empty($_COOKIE['remember_me'])) {
        $conn->close();
        logout_and_redirect("Thiếu thông tin xác thực. Vui lòng đăng nhập lại.");
    }

    $token = $_COOKIE['remember_me'];

    $sql = "SELECT cookie_value FROM users WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $current = (string)($row['cookie_value'] ?? '');
        if (!hash_equals($current, (string)$token)) {
            // Token không khớp -> đã bị đăng nhập nơi khác ghi đè
            $stmt->close();
            $conn->close();
            logout_and_redirect();
        }
    } else {
        // Không tìm thấy user -> thoát
        $stmt->close();
        $conn->close();
        logout_and_redirect("Tài khoản không tồn tại hoặc đã bị vô hiệu hoá. Vui lòng liên hệ quản trị viên.");
    }
    $stmt->close();
}

// Qua được đây coi như hợp lệ -> tiếp tục render trang được include auth.php
