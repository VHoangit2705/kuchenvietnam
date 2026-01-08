<?php
require_once 'config.php';
require 'vendora/autoload.php';
session_start();

use Dompdf\Dompdf;
use Dompdf\Options;

// Kiểm tra ID đơn hàng
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Đơn hàng không hợp lệ.");
}
$orderId = intval($_GET['id']);

// Truy vấn đơn hàng
$sql = "SELECT * FROM orders WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) {
    die("Không tìm thấy đơn hàng.");
}

// Cập nhật trạng thái nếu cần
$stmta = $conn->prepare("UPDATE orders SET status_tracking = 'Đang giao hàng', note = ? WHERE id = ? AND status_tracking = '' ");
$stmta->bind_param("si", $_POST['note'], $orderId);
$stmta->execute();

// Lấy sản phẩm trong đơn
$sqlProducts = "SELECT * FROM order_products WHERE order_id = ?";
$stmt = $conn->prepare($sqlProducts);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// =================== 🔍 KIỂM TRA SẢN PHẨM VIEW = 3 ===================
$hasView3 = false;
if (!empty($products)) {
    $productNames = array_column($products, 'product_name');
    $placeholders = implode(',', array_fill(0, count($productNames), '?'));
    $types = str_repeat('s', count($productNames));
    $sqlCheckView = "SELECT COUNT(*) AS total FROM products WHERE view = 3 AND product_name IN ($placeholders)";
    $stmtCheck = $conn->prepare($sqlCheckView);
    $stmtCheck->bind_param($types, ...$productNames);
    $stmtCheck->execute();
    $resultCheck = $stmtCheck->get_result()->fetch_assoc();
    if ($resultCheck && $resultCheck['total'] > 0) {
        $hasView3 = true;
    }
}

// =================== THÔNG TIN CÔNG TY ===================
$zones = [
    'Đơn hàng HaNoi' => [
        'name' => 'CÔNG TY TNHH KUCHEN VIỆT NAM - CHI NHÁNH HÀ NỘI',
        'address' => 'Số 136, đường Cổ Linh, Q. Long Biên, Hà Nội',
        'hotline' => '19008071',
        'website' => 'kuchen.vn'
    ],
    'Đơn hàng Vinh' => [
        'name' => 'CÔNG TY TNHH KUCHEN VIỆT NAM',
        'address' => 'Tòa nhà Kuchen Building, xóm 13, P.Vinh Phú, tỉnh Nghệ An',
        'hotline' => '19008071',
        'website' => 'kuchen.vn'
    ],
    'Đơn hàng HCM' => [
        'name' => 'CÔNG TY TNHH KUCHEN VIỆT NAM - CHI NHÁNH HỒ CHÍ MINH',
        'address' => 'Lô A1_11 đường D5, KDC Phú Nhuận, phường Phước Long B, TP Thủ Đức',
        'hotline' => '19008071',
        'website' => 'kuchen.vn'
    ]
];

$orderZone = $order['zone'] ?? 'Đơn hàng Vinh';
$companyInfo = $zones[$orderZone] ?? $zones['Đơn hàng Vinh'];

// =================== ⚙️ GHI ĐÈ THÔNG TIN THEO VIEW ===================
$companyLogo = 'https://kuchenvietnam.vn/kuchen/khokuchen/hoadon/logokuchen.png';

// ===== Map địa chỉ theo zone =====
$zoneAddressMap = [
    'Đơn hàng HaNoi' => 'Số 136, đường Cổ Linh, Q. Long Biên, Hà Nội',
    'Đơn hàng Vinh'  => 'Tòa nhà Kuchen Building, xóm 13, P.Vinh Phú, tỉnh Nghệ An',
    'Đơn hàng HCM'   => 'Lô A1_11 đường D5, KDC Phú Nhuận, phường Phước Long B, TP Thủ Đức',
];

// Ghi đè địa chỉ theo zone (luôn luôn)
if (isset($zoneAddressMap[$orderZone])) {
    $companyInfo['address'] = $zoneAddressMap[$orderZone];
}

// =================== 📌 XÁC ĐỊNH VIEW TRONG ĐƠN (FIX VIEW 5 BỊ RỚT) ===================
$viewsInOrder = [];

if (!empty($products)) {

    // 1️⃣ Ưu tiên product_id
    $productIds = array_values(array_filter(
        array_column($products, 'product_id'),
        fn($id) => (int)$id > 0
    ));

    if (!empty($productIds)) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));

        $sql = "SELECT DISTINCT view FROM products WHERE id IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$productIds);
        $stmt->execute();

        $viewsInOrder = array_column(
            $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
            'view'
        );
    }

    // 2️⃣ FALLBACK: nếu vẫn chưa xác định được view → dò theo product_name
    if (empty($viewsInOrder)) {
        $names = array_unique(array_column($products, 'product_name'));

        $likes = [];
        $params = [];
        foreach ($names as $n) {
            $likes[]  = "product_name LIKE ?";
            $params[] = "%" . $n . "%";
        }

        if (!empty($likes)) {
            $sql = "SELECT DISTINCT view 
                    FROM products 
                    WHERE (" . implode(' OR ', $likes) . ")
                      AND view IN (3,5,6)";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            $stmt->execute();

            $viewsInOrder = array_column(
                $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
                'view'
            );
        }
    }
}


/**
 * Ưu tiên VIEW:
 * 3 (HUROM/Đồng Tâm HR)
 * > 6 (OMADA)
 * > 5 (BOHEMIA)
 */
if (in_array(3, $viewsInOrder)) {

    // ===== VIEW = 3 | HUROM VIỆT NAM =====
    if ($orderZone === 'Đơn hàng Vinh') {
        $companyInfo['name'] = 'CÔNG TY TNHH ĐỒNG TÂM HR - Chi nhánh Vinh';
    } elseif ($orderZone === 'Đơn hàng HCM') {
        $companyInfo['name'] = 'CÔNG TY TNHH ĐỒNG TÂM HR - Chi nhánh Hồ Chí Minh';
    } else {
        $companyInfo['name'] = 'CÔNG TY TNHH ĐỒNG TÂM HR - HUROM VIỆT NAM';
    }
    $companyInfo['website'] = 'hurom-vietnam.vn';
    $companyInfo['hotline'] = '19009056';
    // Giữ nguyên hotline + website
    $companyLogo = 'https://kuchenvietnam.vn/kuchen/khokuchen/hoadon/hurom.webp';
} elseif (in_array(6, $viewsInOrder)) {

    // ===== VIEW = 6 | OMADA =====
    if ($orderZone === 'Đơn hàng Vinh') {
        $companyInfo['name'] = 'CÔNG TY TNHH ĐỒNG TÂM HR - OMADA VIỆT NAM chi nhánh Vinh';
    } elseif ($orderZone === 'Đơn hàng HCM') {
        $companyInfo['name'] = 'CÔNG TY TNHH ĐỒNG TÂM HR - OMADA VIỆT NAM chi nhánh Hồ Chí Minh';
    } else {
        $companyInfo['name'] = 'CÔNG TY TNHH ĐỒNG TÂM HR - OMADA VIỆT NAM';
    }

    $companyInfo['website'] = 'omada.vn';
    $companyInfo['hotline'] = '19009056';
    $companyLogo            = 'https://kuchenvietnam.vn/kuchen/khokuchen/hoadon/omada.jpg';
} elseif (in_array(5, $viewsInOrder)) {

    // ===== VIEW = 5 | BOHEMIA =====
    if ($orderZone === 'Đơn hàng Vinh') {
        $companyInfo['name'] = 'CÔNG TY TNHH TMXD ĐỒNG TÂM - Chi nhánh Vinh';
    } elseif ($orderZone === 'Đơn hàng HCM') {
        $companyInfo['name'] = 'CÔNG TY TNHH TMXD ĐỒNG TÂM - Chi nhánh Hồ Chí Minh';
    } else {
        $companyInfo['name'] = 'CÔNG TY TNHH TMXD ĐỒNG TÂM - BOHEMIA VIỆT NAM';
    }
    $companyInfo['website'] = 'bohemiaroyalcrystal.vn';
    $companyInfo['hotline'] = '19009056';
    $companyLogo            = 'https://kuchenvietnam.vn/kuchen/khokuchen/hoadon/bohemia.png';
}


// Cho phép override qua URL
$customerName    = $_GET['customer_name'] ?? $order['customer_name'];
$customerAddress = $_GET['customer_address'] ?? $order['customer_address'];
$note            = $_GET['note'] ?? $order['note'];
$paymentMethod   = $_GET['payment_method'] ?? '';
$noteAdmin       = $_GET['note_admin'] ?? '';
$bankAmount      = $_GET['bank_amount'] ?? 0;
$depositAmount   = $_GET['deposit_amount'] ?? 0;
$depositType     = $_GET['deposit_type'] ?? '';
// ====== QR THANH TOÁN ======
$qrPayLink = '';
if (
    $paymentMethod === 'bank'
    || ($paymentMethod === 'mixed' && $bankAmount > 0)
    || ($paymentMethod === 'deposit' && $depositType === 'bank' && $depositAmount > 0)
) {

    $amount = $bankAmount ?: $depositAmount ?: 0;
    if ($paymentMethod === 'bank') {
        $des = "TTDH  " . $order['order_code2'];
    } elseif ($paymentMethod === 'mixed') {
        $des = "TT phan con lai DH " . $order['order_code2'];
    } elseif ($paymentMethod === 'deposit') {
        $des = "Dat coc DH " . $order['order_code2'];
    } elseif ($paymentMethod === 'bank_droppii') {
        $des = "TTDH tren droppii " . $order['order_code2'];
    } else {
        $des = "TTDH " . $order['order_code2'];
    }

    // Tạo link QR
    $qrPayLink = "https://qr.sepay.vn/img?acc=116615609999"
        . "&bank=VietinBank"
        . "&amount=" . urlencode($amount)
        . "&des=" . urlencode($des);
}

// Debug QR trước
$debugInfo = '';
if ($qrPayLink) {
    $debugInfo = '<p style="color:green">QR link: ' . $qrPayLink . '</p>';
} else {
    $debugInfo = '<p style="color:red">⚠️ Không tạo được QR (method=' . $paymentMethod . ', bank=' . $bankAmount . ', deposit=' . $depositAmount . ', type=' . $depositType . ')</p>';
}


// Ngày hiện tại
$currentDateFormatted = 'Ngày ' . date('d') . ' tháng ' . date('m') . ' năm ' . date('Y');

// Lưu lịch sử in
$printedBy = $_SESSION['full_name'] ?? $_SERVER['REMOTE_ADDR'];
$historyNote = "In hoá đơn lúc " . date("Y-m-d H:i:s");
$loai_phieu = '1';
$historySql = "INSERT INTO print_history (order_id, printed_by, note, loai_phieu) VALUES (?, ?, ?, ?)";
$historyStmt = $conn->prepare($historySql);
if ($historyStmt) {
    $historyStmt->bind_param("issi", $orderId, $printedBy, $historyNote, $loai_phieu);
    $historyStmt->execute();
}

// Cập nhật payment_method + note_admin
$noteAdminFinal = $paymentMethod;
if (!empty($des)) {
    $noteAdminFinal .= " | " . $des;
}

$update = $conn->prepare("UPDATE orders SET payment_method = ?, note_admin = ? WHERE id = ?");
$update->bind_param("ssi", $paymentMethod, $noteAdminFinal, $orderId);
$update->execute();

// Ẩn sản phẩm nếu cần
$hideProducts = isset($_GET['hide_product']) ? $_GET['hide_product'] : [];
//<img src="https://api.qrserver.com/v1/create-qr-code/?data='. htmlspecialchars($order['order_code2']) .'&amp;size=100x100" style="width: 100%;" alt="" title="" />
$html = '
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hóa đơn: ' . htmlspecialchars($order['order_code2']) . '</title>
    <style>
    @page {
        margin: 20; /* Loại bỏ lề mặc định khi in */
    }

    body {
        font-family: "dejavu serif", normal;
        margin: 0;
        padding: 0;
        position: relative;
    }

    table {
        width: 100%; /* Hoặc 100% nếu muốn vừa khít trang */
        border-collapse: collapse;
        margin: 0;
        padding: 0;
    }

    td {
        padding: 5px;
        vertical-align: middle;
    }

    .logo {
        width: 60px;
    }

    .qr-code {
        width: 60px;
    }

    .content {
        text-align: left;
    }

    p {
        font-size: 14px;
        margin: 0;
        line-height: 1.2;
    }

    a {
        color: #0000FF;
    }

    h1 {
        font-size: 24px;
        font-weight: bold;
        margin-top: 10px;
        text-align: center;
    }

    .no-border {
        border: none;
    }

    .table-bordered {
        width: 100%;
        border: 1px solid #000;
    }

    .table-bordered th,
    .table-bordered td {
        border: 1px solid #000;
        padding: 2px;
        text-align: left;
    }
</style>
</head>
<body>
    

    <table class="no-border">
    <tr>
        <td class="logo">
            <img src="' . $companyLogo . '" alt="Logo công ty" style="width: 100%;">
        </td>
        <td class="content" colspan="2">
    <p><strong>' . htmlspecialchars($companyInfo['name']) . '</strong></p>
    <p>Đ/C: ' . htmlspecialchars($companyInfo['address']) . '</p>
    <p>Hotline: ' . htmlspecialchars($companyInfo['hotline']) . ' - Website: ' . htmlspecialchars($companyInfo['website']) . '</p>
</td>

        <td class="qr-code">
        <img src="https://api.qrserver.com/v1/create-qr-code/?data=' . htmlspecialchars($order['order_code2']) . '&amp;size=100x100" style="width: 100%;" alt="" title="" />
    </td>
    </tr>
    <tr>
        <td colspan="3">
            <h1 class="fw-bold mb-4" style="margin-bottom: 0;">PHIẾU GIAO HÀNG</h1>
            <p style="font-size: 13px; margin: 0; text-align: center;"><i>' . htmlspecialchars($currentDateFormatted) . '</i></p>
        </td>
    </tr>
</table>

    <p>
    <table style="border-collapse: collapse;">
        <tr>
    <td style="width: 50%; word-wrap: break-word;">
        <p><strong>Mã đơn hàng:</strong> ' . htmlspecialchars($order['order_code2']) . '</p>
    </td>
    <td style="width: 50%; word-wrap: break-word;">
        <p><strong>Ngày đặt hàng:</strong> &nbsp;' . date('d/m/Y', strtotime($order['created_at'])) . '</p>
    </td>
</tr>

    </table>
</p>
    <p>
    <table style="border-collapse: collapse;">
        <tr>
    <td style="width: 50%; word-wrap: break-word;">
        <p><strong>Tên khách hàng:</strong> ' . htmlspecialchars($customerName) . '</p>
    </td>
    <td style="width: 50%; word-wrap: break-word;">
        <p><strong>SĐT khách hàng:</strong> ' . htmlspecialchars($order['customer_phone']) . '</p>
    </td>
</tr>

    </table>
</p>
    <p><span style="color:white;">-</span><strong>Địa chỉ:</strong> ' . htmlspecialchars($customerAddress) . '</p>
    <p><span style="color:white;">-</span><strong>Ghi chú:</strong> ' . htmlspecialchars($note) . '</p>
   
    <table class="table table-bordered mt-4" style="border-radius: 2px; overflow: hidden; width: 102%;">
        <thead>
   <tr>
    <td style="font-weight: bold; text-align: center; font-size: small;">STT</td>
    <td style="font-weight: bold; text-align: center; font-size: small;">Sản phẩm</td>
    <td style="font-weight: bold; text-align: center; font-size: small;">Số lượng</td>
    <td style="font-weight: bold; text-align: center; font-size: small;">Giá bán</td>
    <td style="font-weight: bold; text-align: center; font-size: small;">Thành tiền</td>
</tr>
</thead>

<tbody>';

// Gán giá trị từ URL vào mảng sản phẩm
if (isset($_GET['prices']) && isset($_GET['quantities']) && isset($_GET['total'])) {
    foreach ($products as &$product) {
        // Nếu giá và số lượng có trong URL, thay thế vào sản phẩm
        if (isset($_GET['prices'][$product['id']])) {
            $product['price'] = floatval($_GET['prices'][$product['id']]);
        }
        if (isset($_GET['quantities'][$product['id']])) {
            $product['quantity'] = intval($_GET['quantities'][$product['id']]);
        }
        if (isset($_GET['total'][$product['id']])) {
            $product['total'] = intval($_GET['total'][$product['id']]);
        }
    }
    unset($product); // Giải phóng tham chiếu
}
$totalAmount = 0;
foreach ($products as &$product) {
    $product['km'] = (!empty($product['is_promotion']) && (int)$product['is_promotion'] === 1)
        ? ' (KM)'
        : '';
}
unset($product);

foreach ($products as $index => $product) {
    // Check if the product is hidden based on URL parameter
    $hideProduct = isset($hideProducts[$product['id']]) && $hideProducts[$product['id']] == 1;

    // If the product is selected to be hidden, skip it
    if ($hideProduct) {
        continue;
    }

    // Lấy giá trị từ URL
    $priceFromUrl = isset($_GET['prices'][$product['id']]) ? floatval($_GET['prices'][$product['id']]) : $product['price'];
    $totalFromUrl = isset($_GET['total'][$product['id']]) ? floatval($_GET['total'][$product['id']]) : $priceFromUrl * $product['quantity'];

    // Kiểm tra nếu `total` được chỉnh sửa, giữ nguyên giá trị của nó và tính ngược lại đơn giá
    if (isset($_GET['total'][$product['id']])) {
        $total = $totalFromUrl; // Thành tiền từ URL (chỉnh sửa)
        $price = $priceFromUrl; // Tính lại đơn giá
    } else {
        $price = $priceFromUrl; // Đơn giá từ URL
        $total = $price * $product['quantity']; // Thành tiền tính theo đơn giá
    }

    // Tính tổng số tiền (chỉ cộng nếu không phải khuyến mãi)
    $totalAmount += $total;
    // Tạo bảng HTML
    $html .= '
    <tr>
        <td style="text-align: center; font-size: small;">' . ($index + 1) . '</td>
        <td style="text-align: left; font-size: small;">'
        . htmlspecialchars($product['product_name'] . $product['km']) .
        '</td>

        <td style="text-align: center; font-size: small;">' . $product['quantity'] . '</td>
        <td style="text-align: center; font-size: small;">' . number_format($price, 0, ',', '.') . '</td>
        <td style="text-align: center; font-size: small;">' . number_format($total, 0, ',', '.') . '</td>
    </tr>';
}




$html .= '
    </tbody>
        <tfoot>
            <tr>
                <td colspan="4" style="text-align: center; font-weight: bold; text-transform: uppercase;">Tổng tiền</td>
                <td style="text-align: center; font-size: small;">' . number_format($totalAmount, 0, ',', '.') . ' VNĐ</td>
            </tr>
        </tfoot>
    </table>';


$html .= '<table style="width: 100%; border: none; margin-top: 5px;">
        <tr>
            <td style="width: 40%; text-align: center; vertical-align: top;">
                <p><strong>Khách hàng ký nhận</strong><br>
                <span style="font-size: 14px;">(Ký, ghi rõ họ tên)</span></p>
            </td>

            <td style="width:20%;text-align:center">';
if ($qrPayLink) {
    $html .= '<img src="' . $qrPayLink . '" style="width:95px;height:95px"><br>
              <span style="font-size:11px">QR Thanh toán</span>';
}
$html .= '</td>

            <td style="width: 40%; text-align: center; vertical-align: top;">
                <p><strong>Người giao hàng</strong><br>
                <span style="font-size: 14px;">(Ký, ghi rõ họ tên)</span></p>
            </td>
        </tr>
    </table>
</body>
</html>';

// Tính tổng số sản phẩm
$productCount = count($products);

// Xác định khổ giấy
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
