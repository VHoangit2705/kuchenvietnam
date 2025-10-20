<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// export_excel.php
include 'auth.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['full_name'])) { header("Location: index.php"); exit(); }

include 'config.php';
$conn->set_charset('utf8mb4');

// PhpSpreadsheet
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// ===== Helpers =====
function esc_like(mysqli $c, $s){ return $c->real_escape_string($s ?? ''); }
function getZoneFilterSql(mysqli $conn, string $position): string {
    if (in_array($position, ['Đơn hàng Vinh', 'Đơn hàng HaNoi', 'Đơn hàng HCM'], true)) {
        return " AND o.zone = '" . $conn->real_escape_string($position) . "'";
    }
    return '';
}

// ===== Session & Filters =====
$position   = $_SESSION['position'] ?? '';
$zoneFilter = getZoneFilterSql($conn, $position);

$filters = [
    'order_code'     => $_GET['order_code']     ?? '',
    'customer_name'  => $_GET['customer_name']  ?? '',
    'customer_phone' => $_GET['customer_phone'] ?? '',
    'agency_phone'   => $_GET['agency_phone']   ?? '',
    'status'         => $_GET['status']         ?? '',
    'status_tracking'=> $_GET['status_tracking']?? '',
    'type'           => $_GET['type']           ?? '',
    'zone'           => $_GET['zone']           ?? '',
    'start_date'     => $_GET['start_date']     ?? '',
    'end_date'       => $_GET['end_date']       ?? ''
];

// WHERE chung
$whereParts = ["1=1"];
if ($zoneFilter) $whereParts[] = substr($zoneFilter, 5);

foreach (['order_code' => 'order_code2', 'customer_name' => 'customer_name', 'customer_phone' => 'customer_phone', 'agency_phone' => 'agency_phone'] as $k => $col) {
    if ($filters[$k] !== '') {
        $val = esc_like($conn, $filters[$k]);
        $whereParts[] = "$col LIKE '%$val%'";
    }
}
foreach (['status', 'status_tracking', 'type', 'zone'] as $k) {
    if ($filters[$k] !== '') {
        $val = $conn->real_escape_string($filters[$k]);
        $whereParts[] = "$k = '$val'";
    }
}
if ($filters['start_date'] !== '' && $filters['end_date'] !== '') {
    $sd = $conn->real_escape_string($filters['start_date']);
    $ed = $conn->real_escape_string($filters['end_date']);
    $whereParts[] = "DATE(o.created_at) BETWEEN '$sd' AND '$ed'";
}

// Tab/type condition
$activeTab = $_GET['active_tab'] ?? 'all';
$typeCondition = '';
if ($activeTab !== 'all') {
    if ($activeTab === 'marketplace') {
        $typeCondition = " AND (o.type IN ('shopee','lazada','tiktok')) ";
    } else {
        $typeCondition = " AND o.type = '".$conn->real_escape_string($activeTab)."' ";
    }
}
$WHERE_COMMON = implode(' AND ', $whereParts);

// ===== Columns selection =====
// Hỗ trợ cả 2 kiểu: ?columns[]=... hoặc ?columns=a,b,c
$columns = [];
if (isset($_GET['columns'])) {
    if (is_array($_GET['columns'])) {
        $columns = array_values(array_filter(array_map('trim', $_GET['columns'])));
    } else {
        $csv = trim((string)$_GET['columns']);
        if ($csv !== '') {
            $columns = array_values(array_filter(array_map('trim', explode(',', $csv))));
        }
    }
}
// Nếu không có gì truyền lên -> mặc định như cũ
if (!$columns) {
    $columns = ['ma_dvvc','ma_don_hang','ten_khach','sdt_khach','ten_dai_ly','sdt_dai_ly','dia_chi','san_pham','so_luong'];
}

// Map key -> [SQL expression, header label]
$COL_MAP = [
  'ma_dvvc'      => ['o.order_code1',                                              'Mã ĐVVC'],
  'ma_don_hang'  => ['o.order_code2',                                              'Mã đơn hàng'],
  'ten_khach'    => ['o.customer_name',                                            'Tên KH'],
  'sdt_khach'    => ['o.customer_phone',                                           'SĐT KH'],
  'ten_dai_ly'   => ['COALESCE(o.agency_name, \'\')',                              'Tên đại lý'],
  'sdt_dai_ly'   => ['COALESCE(o.agency_phone, \'\')',                             'SĐT đại lý'],
  'dia_chi'      => ['COALESCE(o.customer_address, \'\')',                         'Địa chỉ'],
  'san_pham'     => ['od.product_name',                                            'Sản phẩm'],
  'so_luong'     => ['od.quantity',                                                'Số lượng'],
  // Nâng cấp:
  'don_gia'      => ['CASE WHEN od.quantity>0 THEN COALESCE(od.price,0)/od.quantity ELSE 0 END', 'Đơn giá'],
  'thanh_tien'   => ['COALESCE(od.price,0)',                                       'Thành tiền'],
  'discount_code'=> ['COALESCE(o.discount_code, \'\')',                            'Mã giảm giá'],
];

// Giữ thứ tự & chỉ lấy key hợp lệ
$colsFinal = [];
foreach ($columns as $key) {
    if (isset($COL_MAP[$key])) $colsFinal[] = $key;
}
if (!$colsFinal) {
    $colsFinal = ['ma_dvvc','ma_don_hang','ten_khach','sdt_khach','ten_dai_ly','sdt_dai_ly','dia_chi','san_pham','so_luong'];
}

// === Đặt 4 cột SL -> Đơn giá -> Thành tiền -> Mã giảm giá ở CUỐI ===
$tailGroup = ['so_luong','don_gia','thanh_tien','discount_code'];

// Giữ nguyên thứ tự xuất hiện của các cột KHÁC
$others = [];
foreach ($colsFinal as $k) {
    if (!in_array($k, $tailGroup, true)) {
        $others[] = $k;
    }
}

// Lấy các cột trong nhóm đuôi theo đúng thứ tự chuẩn, chỉ lấy những cột đã được chọn
$tail = [];
foreach ($tailGroup as $k) {
    if (in_array($k, $colsFinal, true)) {
        $tail[] = $k;
    }
}

// Ghép lại: cột khác trước, rồi 4 cột đuôi theo thứ tự chuẩn
$colsFinal = array_merge($others, $tail);

// các cột khác giữ nguyên theo vị trí hiện tại (+100 để đứng sau nhóm ưu tiên)
$base = 100;
foreach ($colsFinal as $i => $k) {
    if (!isset($priority[$k])) $priority[$k] = $base + $i;
}
usort($colsFinal, function($a,$b) use ($priority){
    return ($priority[$a] <=> $priority[$b]);
});

// Xây SELECT động theo thứ tự đã chọn
$selectParts = [];
foreach ($colsFinal as $k) {
    $selectParts[] = $COL_MAP[$k][0] . " AS `" . $k . "`";
}
$sql = "
    SELECT
        " . implode(",\n        ", $selectParts) . "
    FROM orders o
    LEFT JOIN order_products od ON od.order_id = o.id
    WHERE $WHERE_COMMON $typeCondition
    ORDER BY o.id DESC, od.id ASC
";

// Query
$res = $conn->query($sql);

// ===== Excel =====
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Bao_cao_don_hang');

// ===== Tiêu đề báo cáo =====
$from = $filters['start_date'] ?: date('Y-m-d');
$to   = $filters['end_date']   ?: date('Y-m-d');
$title = "BÁO CÁO ĐƠN HÀNG (Từ {$from} đến {$to})";

// Hàng 1: Tiêu đề (gộp ô)
$totalCols = count($colsFinal) + 1; // +1 vì có cột STT
$sheet->mergeCellsByColumnAndRow(1, 1, $totalCols, 1);
$sheet->setCellValueByColumnAndRow(1, 1, $title);
$sheet->getStyleByColumnAndRow(1, 1, $totalCols, 1)->getFont()->setBold(true)->setSize(14);
$sheet->getStyleByColumnAndRow(1, 1, $totalCols, 1)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// ===== Header (bắt đầu từ hàng 2) =====
$headerRow = 2;
$colIdx = 1;

// STT
$sheet->setCellValueByColumnAndRow($colIdx, $headerRow, 'STT'); $colIdx++;

// Header theo cột đã chọn
foreach ($colsFinal as $k) {
    $sheet->setCellValueByColumnAndRow($colIdx, $headerRow, $COL_MAP[$k][1]);
    $colIdx++;
}

// Style header: xanh biển nhạt, chữ đen, đậm, căn giữa
$headerStyle = $sheet->getStyleByColumnAndRow(1, $headerRow, $totalCols, $headerRow);
$headerStyle->getFont()->setBold(true)->getColor()->setARGB('FF000000');
$headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDEEFF'); // xanh biển nhạt

// ===== Data (từ hàng 3) =====
$dataStartRow = $headerRow + 1;
$rowNum = $dataStartRow;
$stt = 1;

if ($res) {
    while ($r = $res->fetch_assoc()) {
        $colIdx = 1;

        // STT
        $sheet->setCellValueByColumnAndRow($colIdx, $rowNum, $stt); 
        $colIdx++;

        foreach ($colsFinal as $k) {
            $val = $r[$k];

            // Mã ĐVVC: nếu toàn số => thêm ' ở đầu để giữ nguyên định dạng trong Excel
            if ($k === 'ma_dvvc' && preg_match('/^\d+$/', (string)$val)) {
                $val = "'".$val;
            }

            if (in_array($k, ['so_luong'], true)) {
                // số lượng: số nguyên
                $sheet->setCellValueExplicitByColumnAndRow($colIdx, $rowNum, (float)$val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                $sheet->getStyleByColumnAndRow($colIdx, $rowNum)->getNumberFormat()->setFormatCode('#,##0');
            } elseif (in_array($k, ['don_gia','thanh_tien'], true)) {
                // tiền: số có format
                $num = (float)$val;
                $sheet->setCellValueExplicitByColumnAndRow($colIdx, $rowNum, $num, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                $sheet->getStyleByColumnAndRow($colIdx, $rowNum)->getNumberFormat()->setFormatCode('#,##0'); // VND không ký hiệu
            } else {
                $sheet->setCellValueByColumnAndRow($colIdx, $rowNum, $val);
            }
            $colIdx++;
        }

        $stt++;
        $rowNum++;
    }
}

// Auto-size cột
for ($i=1; $i<=$totalCols; $i++) {
    $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
}

// Viền toàn bảng (từ header đến hết dữ liệu)
$lastRow = max($rowNum - 1, $headerRow); // nếu không có dữ liệu vẫn kẻ viền header
$range = $sheet->getStyleByColumnAndRow(1, $headerRow, $totalCols, $lastRow);
$range->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF000000');

// Xuất file
if (ob_get_length()) { ob_end_clean(); }
$now = date('Ymd_His');
$fileName = "Bao_cao_don_hang_{$now}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$fileName.'"');
header('Cache-Control: max-age=0');

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit;
