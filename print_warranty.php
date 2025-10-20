<?php
// ====== CONFIG & LIBS ======
$servername = "localhost";
$username   = "kuchenvi_kythuatkuchen";
$password   = "sPY9vdvFrG8L68pJNf2d";
$dbname     = "kuchenvi_kythuatkuchen"; 
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die("Kết nối thất bại: " . $conn->connect_error); }
session_start();

if (file_exists(__DIR__ . '/vendora/autoload.php')) {
    require __DIR__ . '/vendora/autoload.php';
} else {
    require __DIR__ . '/vendor/autoload.php';
}

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Asia/Ho_Chi_Minh');

// ====== Helpers ======
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_vn_time($ts){
    if (!$ts) return '';
    $t = is_numeric($ts) ? (int)$ts : strtotime($ts);
    if ($t <= 0) return '';
    return date('H:i', $t) . ' ngày ' . date('j', $t) . ' tháng ' . date('n', $t) . ' năm ' . date('Y', $t);
}
function dmy($ts){
    if (!$ts) return '';
    $t = is_numeric($ts) ? (int)$ts : strtotime($ts);
    return $t > 0 ? date('d/m/Y', $t) : '';
}
function vnd($n){ return number_format((float)$n, 0, ',', '.'); }

// ====== Input ======
$reqId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($reqId <= 0) { die('Yêu cầu không hợp lệ.'); }

// ====== Query master ======
$sqlReq = "SELECT
            id,warranty_id, serial_number, serial_thanmay, product,
            full_name, phone_number, address,
            staff_received, branch,
            received_date, warranty_end, return_date, shipment_date,
            initial_fault_condition, product_fault_condition, product_quantity_description,
            collaborator_name, collaborator_phone, collaborator_address,
            DATE_FORMAT(Ngaytao, '%Y-%m-%d %H:%i:%s') AS Ngaytao_raw
          FROM warranty_requests_history
          WHERE id = ?";
$stmtReq = $conn->prepare($sqlReq);
$stmtReq->bind_param('i', $reqId);
$stmtReq->execute();
$req = $stmtReq->get_result()->fetch_assoc();
if (!$req) { die('Không tìm thấy phiếu bảo hành.'); }

// ====== Query details ======
$sqlDt = "SELECT id, error_type, solution, replacement, quantity, unit_price
          FROM warranty_request_details_history
          WHERE warranty_request_history_id = ?
          ORDER BY id ASC";
$stmtDt = $conn->prepare($sqlDt);
$stmtDt->bind_param('i', $reqId);
$stmtDt->execute();
$details = $stmtDt->get_result()->fetch_all(MYSQLI_ASSOC);

// ====== Company / branch mapping (mặc định KUCHEN) ======
$zones = [
  'HaNoi' => [
    'name'    => 'CÔNG TY TNHH KUCHEN VIỆT NAM - CHI NHÁNH HÀ NỘI',
    'sub'     => 'CƠ SỞ BẢO HÀNH HÀ NỘI',
    'address' => 'Số 136, đường Cổ Linh, P. Long Biên, TP. Hà Nội',
    'hotline' => '1900 8071', 'website' => 'kuchen.vn'
  ],
  'Vinh' => [
    'name'    => 'CÔNG TY TNHH KUCHEN VIỆT NAM',
    'sub'     => 'CƠ SỞ BẢO HÀNH THÀNH PHỐ VINH',
    'address' => 'Kuchen Building, Đ.Vinh-Cửa Lò, xóm 13, P.Vinh Phú, tỉnh Nghệ An',
    'hotline' => '1900 8071', 'website' => 'kuchen.vn'
  ],
  'HCM' => [
    'name'    => 'CÔNG TY TNHH KUCHEN VIỆT NAM - CHI NHÁNH HỒ CHÍ MINH',
    'sub'     => 'CƠ SỞ BẢO HÀNH HỒ CHÍ MINH',
    'address' => 'Lô A1_11 đường D5, KDC Phú Nhuận, phường Phước Long, TP. Hồ Chí Minh',
    'hotline' => '1900 8071', 'website' => 'kuchen.vn'
  ],
];

$branchKey = 'Vinh';
$brLower = mb_strtolower((string)($req['branch'] ?? ''), 'UTF-8');
if (strpos($brLower, 'hà nội') !== false || strpos($brLower, 'hanoi') !== false) $branchKey = 'HaNoi';
elseif (strpos($brLower, 'hcm') !== false || strpos($brLower, 'hồ chí minh') !== false || strpos($brLower, 'hcmc') !== false) $branchKey = 'HCM';

$company = $zones[$branchKey];

// ====== HUROM switch (brand/branch có chữ HUROM) ======
$isHurom = (strpos($brLower, 'hurom') !== false);
if (!$isHurom) {
    $brandLower = mb_strtolower((string)($_GET['brand'] ?? ''), 'UTF-8');
    $isHurom = (strpos($brandLower, 'hurom') !== false);
}
if ($isHurom) {
    $company['name']    = 'CÔNG TY TNHH ĐỒNG TÂM HR';
    $company['hotline'] = '1900.9056 - 0334.957.577';
    $company['website'] = 'hurom-vietnam.vn';
}

// ====== Prepare view data ======
$serial = trim(($req['serial_number'] ?? '')) ?: ($req['serial_thanmay'] ?? '');
$warrantyStatus = '';
if (!empty($req['warranty_end'])) {
    $warrantyStatus = (strtotime($req['warranty_end']) >= time()) ? ' (Còn hạn bảo hành)' : ' (Hết hạn bảo hành)';
}
$inText = 'In lúc ' . fmt_vn_time(time());

// Ghi chú: ưu tiên miêu tả hiện trạng
$noteParts = array_filter([
  trim((string)$req['initial_fault_condition']),
  trim((string)$req['product_fault_condition']),
  trim((string)$req['product_quantity_description']),
]);
$noteText = implode(' | ', $noteParts);

// ====== Build rows ======
// ====== Build rows ======
$total = 0;
$rowsHtml = '';
$i = 1;

foreach ($details as $d) {
    $qty  = (int)($d['quantity'] ?? 0);
    $unit = (float)($d['unit_price'] ?? 0);
    $line = $qty * $unit;
    $total += $line;

    $replacement = trim((string)($d['replacement'] ?? ''));
$errorType   = trim((string)($d['error_type'] ?? ''));

$displayReplacement = $replacement !== '' ? $replacement : (' ');

$rowsHtml .= '
  <tr>
    <td style="text-align:center;">'. $i++ .'</td>
    <td>'. esc($errorType) .'</td>
    <td>'. esc($displayReplacement) .'</td>
    <td style="text-align:center;">'. ($qty ?: 0) .'</td>
    <td style="text-align:right;">'. vnd($unit) .'</td>
  </tr>';

}


// ❌ BỎ đoạn "Nếu chưa có dữ liệu thì thêm 1 dòng (Chưa có chi tiết)"

// Thêm 5 dòng trống để bảng cân đối
for ($j = 0; $j < 3; $j++) {
    $rowsHtml .= '
      <tr>
        <td style="text-align:center;">&nbsp;</td>
        <td>&nbsp;</td>
        <td>&nbsp;</td>
        <td style="text-align:center;">&nbsp;</td>
        <td style="text-align:right;">&nbsp;</td>
      </tr>';
}



// ====== Assets ======
$logoKuchen = 'https://kuchenvietnam.vn/kuchen/khokuchen/hoadon/logokuchen.png';
$logoHurom  = 'https://kuchenvietnam.vn/kuchen/khokuchen/hoadon/hurom.webp';
$logo = $isHurom ? $logoHurom : $logoKuchen;

// ====== HTML ======
$html = '
<!doctype html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Phieu tiep nhan bao hanh #'. esc($reqId) .'</title>
<style>
@page { margin: 24px; }
body { font-family: DejaVu Sans, sans-serif; font-size: 13px; }
table { width: 100%; border-collapse: collapse; }
p { margin: 0; line-height: 1.35; }
.h1 { font-size: 20px; font-weight: 700; text-align:center; margin: 8px 0 0 0; }
.small { font-size: 12px; color: #333; }
.td { padding: 6px; vertical-align: middle; }
.header td { padding: 4px 6px; }
.table-bordered { border: 1px solid #000; }
.table-bordered th, .table-bordered td { border: 1px solid #000; padding: 4px 6px; }
.text-center { text-align:center; }
.text-right { text-align:right; }
.bold { font-weight:700; }
.hr { border-top: 1px solid #000; margin: 4px 0; }
</style>
</head>
<body>

<table class="header">
  <tr>
    <td style="width:70px;">
      <img src="'. esc($logo) .'" style="width:60px;">
    </td>
    <td>
      <p class="bold">'. esc($company['name']) .'</p>
      <p>Đ/C: '. esc($company['address']) .'</p>
      <p>Hotline: '. esc($company['hotline']) .' - Website: '. esc($company['website']) .'</p>
    </td>
  </tr>
</table>

<p class="h1">PHIẾU TIẾP NHẬN BẢO HÀNH</p>
<p class="small" style="text-align:center;"><i>'. esc($inText) .' - Số phiếu: '. esc($req['warranty_id']) .'</i></p>

<table style="margin-top: 8px;">
  <tr>
    <td class="td" style="width:50%;">
      <p><b>Họ và tên KH:</b> '. esc($req['full_name'] ?? '-') .'</p>
      <p><b>Số điện thoại:</b> '. esc($req['phone_number'] ?? '-') .'</p>
      <p><b>Địa chỉ KH:</b> '. esc($req['address'] ?? '-') .'</p>';

      // --- CTV block: CHỈ tên & SĐT (địa chỉ sẽ chuyển sang cột phải) ---
      if (!empty($req['collaborator_name']) || !empty($req['collaborator_phone'])) {
          $html .= '
          <div style="height:6px;"></div>
          <p><b>CTV:</b> '. esc(trim(($req['collaborator_name'] ?? ''))) .' - '. esc(trim(($req['collaborator_phone'] ?? ''))) .'</p>';
      }

$html .= '
    </td>
    <td class="td" style="width:50%;">
      <p><b>Serial:</b> '. esc($serial ?: '-') .'</p>
      <p><b>Ngày xuất kho:</b> '. esc(dmy($req['shipment_date'])) . esc($warrantyStatus) .'</p>
      <p><b>Sản phẩm:</b> '. esc($req['product'] ?? '-') .'</p>';
      // >>> BỎ dòng Kỹ thuật viên, THAY bằng Địa chỉ CTV ngay tại đây <<<
      if (!empty($req['collaborator_address'])) {
        $html .= '<p><b>Địa chỉ CTV:</b> '. esc(trim($req['collaborator_address'])) .'</p>';
      }
$html .= '
    </td>
  </tr>
</table>

<table class="table-bordered" style="margin-top:10px;">
  <thead>
    <tr>
      <th style="width:20px; text-align:center;">STT</th>
      <th style="width:140px;">Lỗi</th>
      <th style="width:260px;">Linh kiện thay thế</th>
      <th style="width:20px; text-align:center;">SL</th>
      <th style="width:40px; text-align:right;">Đơn giá</th>
    </tr>
  </thead>
  <tbody>
    '. $rowsHtml .'
  </tbody>
  <tfoot>
    <tr>
      <td colspan="4" class="bold text-center">TỔNG TIỀN</td>
      <td class="text-right">'. vnd($total) .'</td>
    </tr>
  </tfoot>
</table>


<table style="margin-top:14px;">
  <tr>
    <td class="text-center" style="width:33%;">
      <p class="bold">Khách hàng</p>
      <p class="small">(Ký, ghi rõ họ tên)</p>
      <p style="margin-top:60px;"></p>
    </td>
    <td class="text-center" style="width:33%;">
      <p class="bold">Nhân viên kỹ thuật</p>
      <p class="small">(Ký, ghi rõ họ tên)</p>
      <p style="margin-top:40px;">'. esc($req['staff_received'] ?? '') .'</p>
    </td>
    <td class="text-center" style="width:33%;">
      <p class="bold">Nhân viên xuất kho</p>
      <p class="small">(Ký, ghi rõ họ tên)</p>
      <p style="margin-top:40px;">'. esc($_SESSION['full_name'] ?? '') .'</p>
    </td>
  </tr>
</table>

</body>
</html>
';

// ====== Render PDF ======
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('phieu-bao-hanh-'. $reqId .'.pdf', ['Attachment' => false]);
