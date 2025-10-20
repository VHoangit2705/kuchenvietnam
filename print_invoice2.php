<?php
require_once 'config.php';
require 'vendora/autoload.php';
session_start(); // ⚠️ Đảm bảo bạn đã khởi động session
use Dompdf\Dompdf;
use Dompdf\Options;

// Kiểm tra ID đơn hàng
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Đơn hàng không hợp lệ.");
}

$orderId = intval($_GET['id']);

// Truy vấn dữ liệu đơn hàng
$sql = "SELECT * FROM orders WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    die("Không tìm thấy đơn hàng.");
}

// Kiểm tra zone của đơn hàng
$zone = $order['zone'] ?? '';

// Truy vấn sản phẩm trong đơn hàng kèm điều kiện kiểm tra is_promotion
$sqlProducts = "SELECT op.quantity, op.product_name, p.name, p.model, p.exp, op.is_promotion, p.view 
                FROM order_products op
                JOIN products p ON op.product_name = p.product_name
                WHERE op.order_id = ? 
                AND p.print != 1";
$stmt = $conn->prepare($sqlProducts);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Lấy ngày hiện tại theo định dạng yêu cầu
$currentDateFormatted = date('d/m/Y');
// Ghi lịch sử in phiếu
$printedBy = $_SESSION['full_name'] ?? $_SERVER['REMOTE_ADDR']; // fallback nếu không có session
$historyNote = "In phiếu BH lúc " . date("Y-m-d H:i:s");
$loai_phieu= '2';
$historySql = "INSERT INTO print_history (order_id, printed_by, note, loai_phieu) VALUES (?, ?, ?, ?)";
$historyStmt = $conn->prepare($historySql);
if ($historyStmt) {
    $historyStmt->bind_param("issi", $orderId, $printedBy, $historyNote, $loai_phieu);
    $historyStmt->execute();
}
foreach ($products as &$product) {
    if ($zone === "Đơn hàng HCM" && $product['is_promotion'] == 1) {
        $product['km'] = "(KM)"; // Nếu là sản phẩm khuyến mãi và zone HCM thì in "KM"
    } else {
        $product['km'] = "";   // Ngược lại, để trống
    }
}
unset($product); // Giải phóng biến tham chiếu
// Khởi tạo HTML động cho mỗi sản phẩm
$html = '
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PBH ' . htmlspecialchars($order['order_code2']) . '</title>
    <style>
        body {
            font-family: "dejavu serif", normal;
            margin: 0;
            padding: 0;
            position: relative;
        }
        .container {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            font-size: 16px;
        }
        .text {
            position: absolute;
            top: 146px; /* Vị trí theo trục y */
            left: 125px; /* Vị trí theo trục x */
            font-size: 16px; /* Kích thước chữ */
        }
        .text-a {
            position: absolute;
            top: 186px; /* Vị trí theo trục y */
            left: 125px; /* Vị trí theo trục x */
            font-size: 16px; /* Kích thước chữ */
        }
        .text-b {
            position: absolute;
            top: 186px; /* Vị trí theo trục y */
            left: 552px; /* Vị trí theo trục x */
            font-size: 16px; /* Kích thước chữ */
        }
        .text-c {
            position: absolute;
            top: 385px; /* Vị trí theo trục y */
            left: 245px; /* Vị trí theo trục x */
            font-size: 16px; /* Kích thước chữ */
        }
        .text-d {
            position: absolute;
            top: 415px; /* Vị trí theo trục y */
            left: 255px; /* Vị trí theo trục x */
            font-size: 16px; /* Kích thước chữ */
        }
        .qr-box {
    position: absolute;
    top: 435px;
    left: 470px;
    width: 100px;
    height: 100px;
}
.qr-box img {
    width: 80%;
    height: auto;
}
.qr-text {
    position: absolute;
    top: 450px;
    left: 580px;
    width: 160px;
    font-size: 12px;
    color: #000;
    line-height: 1.4;
}
    </style>
</head>
<body>';

// Tạo nội dung cho từng sản phẩm
foreach ($products as $product) {
    for ($i = 0; $i < $product['quantity']; $i++) {
        $html .= '
        <div class="text">' . htmlspecialchars($product['name']) . ' ' . htmlspecialchars($product['km']) . '</div>
        <div class="text-a">' . htmlspecialchars($product['model']) . '</div>
        <div class="text-b">' . htmlspecialchars($order['order_code2']) . '</div>
        <div class="text-c">' . htmlspecialchars($currentDateFormatted) . '</div>
        <div class="text-d">' . htmlspecialchars($product['exp']) . '</div>';

        // Nếu sản phẩm có view = 3 hoặc view = 1 thì thêm QR code
if ((int)$product['view'] === 3 || (int)$product['view'] === 1) {
    if ((int)$product['view'] === 3) {
        //'https://baohanh.hurom-vietnam.vn/dang-ki-bao-hanh-hurom/order_lookup.php?order_code2='. urlencode($order['order_code2']);
        $linkQR = 'https://baohanh.hurom-vietnam.vn/dang-ki-bao-hanh-hurom/order_lookup.php?order_code2='. urlencode($order['order_code2']);
    } elseif ((int)$product['view'] === 1) {
        $linkQR = 'https://kuchenvietnam.vn/dang-ki-bao-hanh-kuchen/order_lookup.php?order_code2=' 
                  . urlencode($order['order_code2']);
    }

    $qrImage = 'https://api.qrserver.com/v1/create-qr-code/?data=' . urlencode($linkQR) . '&size=100x100';
    
    $html .= '
        <div class="qr-box"><img src="' . $qrImage . '" alt="QR Code" /></div>
        <div class="qr-text">Quý khách vui lòng quét mã QR để kích hoạt bảo hành điện tử</div>';
}

$html .= '<div style="page-break-after: always;"></div>';

    }
}


$html .= '</body></html>';

// Xác định khổ giấy A4
$paperSize = 'A4'; // A4 size
$options = new Options();
$options->set('defaultFont', 'dejavu serif');
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);

// Nạp nội dung HTML vào Dompdf
$dompdf->loadHtml($html);

// Cài đặt khổ giấy là A4 (khổ dọc)
$dompdf->setPaper($paperSize, 'portrait');

// Xuất file PDF
$dompdf->render();

// Tải xuống file PDF
$dompdf->stream("file_mau.pdf", ["Attachment" => false]);
?>
