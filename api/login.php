
<?php
// Cho phép CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

// Nếu là preflight request thì dừng ở đây
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

session_start();
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *"); // cho phép FE gọi
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");

include '../config.php'; // mysqli $conn

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Nếu frontend gửi JSON thì phải đọc từ php://input
    $data = json_decode(file_get_contents("php://input"), true);
    $input_password = $data['password'] ?? '';

    $hashed_password = md5($input_password);

    $stmt = $conn->prepare("SELECT id, full_name, position FROM users WHERE password = ? LIMIT 1");
    $stmt->bind_param("s", $hashed_password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($u = $result->fetch_assoc()) {
        // Tạo session
        session_regenerate_id(true);
        $_SESSION['user_id']   = (int)$u['id'];
        $_SESSION['full_name'] = $u['full_name'];
        $_SESSION['position']  = $u['position'];

        // Tạo token
        $token = bin2hex(random_bytes(32));
        $upd = $conn->prepare("UPDATE users SET cookie_value = ? WHERE id = ?");
        $upd->bind_param("si", $token, $u['id']);
        $upd->execute();
        $upd->close();

        echo json_encode([
            "success" => true,
            "token" => $token,
            "user" => [
                "id" => $u['id'],
                "full_name" => $u['full_name'],
                "position" => $u['position']
            ]
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Mật khẩu không đúng!"
        ]);
    }

    $stmt->close();
    $conn->close();
    exit();
}

// Nếu không phải POST
echo json_encode(["success" => false, "message" => "Invalid request"]);
$conn->close();
