<?php
session_start();
require 'config.php';

// Khuyến nghị: bật chế độ báo lỗi rõ ràng khi dev (có thể bỏ khi lên prod)
// mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if ($conn->connect_error) {
    $_SESSION['success_message'] = 'Lỗi kết nối CSDL: ' . $conn->connect_error;
    header('Location: admin.php');
    exit();
}

if (!isset($_SESSION['full_name'])) {
    $_SESSION['success_message'] = 'Bạn chưa đăng nhập.';
    header('Location: admin.php');
    exit();
}

$editedBy = $_SESSION['full_name'];

$order_id         = intval($_POST['order_id'] ?? 0);
$order_type       = trim($_POST['order_type'] ?? '');
$order_code1_in   = trim($_POST['order_code1'] ?? '');
$order_code2_in   = trim($_POST['order_code2'] ?? '');

$customer_name    = trim($_POST['customer_name'] ?? '');
$customer_phone   = trim($_POST['customer_phone'] ?? '');
$customer_address = trim($_POST['customer_address'] ?? '');
$agency_name      = trim($_POST['agency_name'] ?? '');
$agency_phone     = trim($_POST['agency_phone'] ?? '');
$note_admin       = trim($_POST['note_admin'] ?? '');

if ($order_id <= 0) {
    $_SESSION['success_message'] = 'ID đơn hàng không hợp lệ.';
    header('Location: admin.php');
    exit();
}

// Lấy dữ liệu cũ
$sqlOld = "SELECT * FROM orders WHERE id = ?";
$stmtOld = $conn->prepare($sqlOld);
$stmtOld->bind_param("i", $order_id);
$stmtOld->execute();
$resOld = $stmtOld->get_result();
if (!$resOld || $resOld->num_rows === 0) {
    $_SESSION['success_message'] = 'Không tìm thấy đơn hàng.';
    header('Location: admin.php');
    exit();
}
$old = $resOld->fetch_assoc();
$stmtOld->close();

// Rule: nếu chọn outside => code1 = chuỗi cố định
if ($order_type === 'outside') {
    $order_code1_in = 'Khách nhận hàng tại kho Droppii';
}

// Transaction
$conn->begin_transaction();

try {
    // Cập nhật đơn hàng (CHỈ prepare/bind/execute 1 lần)
    $sqlUp = "UPDATE orders
              SET `type` = ?, order_code1 = ?, order_code2 = ?,
                  customer_name = ?, customer_phone = ?, customer_address = ?,
                  agency_name = ?, agency_phone = ?, note_admin = ?
              WHERE id = ?";
    $stmtUp = $conn->prepare($sqlUp);
    // 9 string + 1 int  => "sssssssssi"
    $stmtUp->bind_param(
        "sssssssssi",
        $order_type,
        $order_code1_in,
        $order_code2_in,
        $customer_name,
        $customer_phone,
        $customer_address,
        $agency_name,
        $agency_phone,
        $note_admin,
        $order_id
    );
    $stmtUp->execute();
    $stmtUp->close();

    // Ghi lịch sử
    $changes = [];
    $map = [
        'type'             => ['label' => 'Loại đơn hàng',  'old' => $old['type'] ?? '',           'new' => $order_type],
        'order_code1'      => ['label' => 'Mã ĐVVC',         'old' => $old['order_code1'] ?? '',     'new' => $order_code1_in],
        'order_code2'      => ['label' => 'Mã đơn hàng',     'old' => $old['order_code2'] ?? '',     'new' => $order_code2_in],
        'customer_name'    => ['label' => 'Tên KH',          'old' => $old['customer_name'] ?? '',   'new' => $customer_name],
        'customer_phone'   => ['label' => 'SĐT KH',          'old' => $old['customer_phone'] ?? '',  'new' => $customer_phone],
        'customer_address' => ['label' => 'Địa chỉ',         'old' => $old['customer_address'] ?? '', 'new' => $customer_address],
        'agency_name'      => ['label' => 'Tên đại lý',      'old' => $old['agency_name'] ?? '',     'new' => $agency_name],
        'agency_phone'     => ['label' => 'SĐT đại lý',      'old' => $old['agency_phone'] ?? '',    'new' => $agency_phone],
        'note_admin'       => ['label' => 'Ghi chú admin',   'old' => $old['note_admin'] ?? '',      'new' => $note_admin],
    ];
    foreach ($map as $v) {
        if ((string)$v['old'] !== (string)$v['new']) {
            $changes[] = "{$v['label']}: '{$v['old']}' ➝ '{$v['new']}'";
        }
    }
    $comments = $changes
        ? ("Cập nhật thông tin khách hàng: " . implode("; ", $changes))
        : "Lưu lại không có thay đổi.";

    $sqlHist = "INSERT INTO order_edit_history
                (order_id, action_type, product_id, product_name, quantity, price, edited_by, comments)
                VALUES (?, 'update', NULL, '', 0, 0, ?, ?)";
    $stmtHist = $conn->prepare($sqlHist);
    $stmtHist->bind_param("iss", $order_id, $editedBy, $comments);
    $stmtHist->execute();
    $stmtHist->close();

    $conn->commit();

    $_SESSION['success_message'] = 'Đã cập nhật thông tin đơn hàng & lưu lịch sử.';
    header('Location: order_detail.php?id=' . $order_id);
    exit();

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['success_message'] = 'Lỗi: ' . $e->getMessage();
    header('Location: order_detail.php?id=' . $order_id);
    exit();
}
