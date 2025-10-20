<?php
// ================== AUTH & SESSION (tuỳ chọn) ==================
// include 'auth.php';
session_start();
// if (!isset($_SESSION['full_name'])) { header("Location: index.php"); exit(); }

// ================== TIMEZONE ==================
date_default_timezone_set('Asia/Ho_Chi_Minh');

// ================== DB CONNECT ==================
$servername = "localhost";
$username   = "kuchenvi_kythuatkuchen";
$password   = "sPY9vdvFrG8L68pJNf2d";
$dbname     = "kuchenvi_kythuatkuchen";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die("Kết nối thất bại: " . $conn->connect_error); }
$conn->set_charset('utf8mb4');

// ================== KHAI BÁO NAMESPACE CHO PhpSpreadsheet (PHẢI ở mức file) ==================
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// ================== PhpSpreadsheet (chỉ load khi cần) ==================
$WILL_EXPORT = (isset($_GET['export']) && $_GET['export'] === 'excel');
if ($WILL_EXPORT) {
  require __DIR__ . '/vendor/autoload.php';
}

// ================== Helpers ==================
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function intv($v){ return (int)($v ?? 0); }
function to_ymd($dmy){
  $dmy = trim((string)$dmy);
  if ($dmy === '') return null;
  $dt = DateTime::createFromFormat('d/m/Y', $dmy);
  return $dt ? $dt->format('Y-m-d') : null;
}

// ========= ZONE CONFIG =========
// Chuẩn hoá danh sách branch được phép theo từng vùng.
// Bạn có thể thêm/bớt biến thể tên chi nhánh cho khớp dữ liệu thực tế.
$ZONE_BRANCHES = [
  'Đơn hàng Vinh' => [
    'KUCHEN VINH','HUROM VINH',
    'KUCHEN Vinh','HUROM Vinh',
    'VINH KUCHEN','VINH HUROM',
  ],
  'Đơn hàng HCM' => [
    'KUCHEN HCM','HUROM HCM',
    'KUCHEN TP.HCM','HUROM TP.HCM',
    'KUCHEN HO CHI MINH','HUROM HO CHI MINH',
  ],
  'Đơn hàng HaNoi' => [
    'KUCHEN HÀ NỘI','HUROM HÀ NỘI',
    'KUCHEN HANOI','HUROM HANOI',
    'KUCHEN HN','HUROM HN',
  ],
];

function current_position(): string {
  return trim((string)($_SESSION['position'] ?? ''));
}

function allowed_branches_for_position(string $pos, array $zoneMap): array {
  return $zoneMap[$pos] ?? []; // empty => admin/không giới hạn
}

// Thêm điều kiện WHERE theo zone (nếu có)
function append_zone_filter(string $where, array &$params, string &$types, string $pos, array $zoneMap): string {
  $allowed = allowed_branches_for_position($pos, $zoneMap);
  if (empty($allowed)) return $where; // không giới hạn (admin)
  // Tạo mệnh đề IN động
  $placeholders = implode(',', array_fill(0, count($allowed), '?'));
  $where .= " AND COALESCE(branch,'') IN ($placeholders) ";
  foreach ($allowed as $b) { $params[] = $b; $types .= 's'; }
  return $where;
}

// Kiểm tra quyền phê duyệt theo branch
function can_approve_branch(string $pos, string $branch, array $zoneMap): bool {
  $allowed = allowed_branches_for_position($pos, $zoneMap);
  if (empty($allowed)) return true; // admin/general → được
  return in_array($branch, $allowed, true);
}

// ========== Group key & merge theo replacement ==========
function build_group_key(array $row, string $replacementSet){
  $parts = [
    mb_strtolower(trim($row['product'] ?? ''), 'UTF-8'),
    mb_strtolower(trim($row['serial_number'] ?? ''), 'UTF-8'),
    mb_strtolower(trim($row['full_name'] ?? ''), 'UTF-8'),
    mb_strtolower(trim($row['phone_number'] ?? ''), 'UTF-8'),
    mb_strtolower(trim($row['staff_received'] ?? ''), 'UTF-8'),
    mb_strtolower(trim($row['branch'] ?? ''), 'UTF-8'),
    mb_strtolower(trim($row['error_type'] ?? ''), 'UTF-8'),   // ✅ thêm dòng này
    mb_strtolower(trim($replacementSet), 'UTF-8'),
  ];
  return hash('sha256', implode('||', $parts));
}



function fetch_replacements_map(mysqli $conn, array $historyIds): array {
  $map = [];
  if (empty($historyIds)) return $map;

  $place = implode(',', array_fill(0, count($historyIds), '?'));
  $types = str_repeat('i', count($historyIds));
  $sql = "SELECT warranty_request_history_id AS hid, replacement
          FROM warranty_request_details_history
          WHERE warranty_request_history_id IN ($place)";
  $stmt = $conn->prepare($sql);

  // bind dynamic
  $bind = [$types];
  foreach ($historyIds as $i) { $bind[] = $i; }
  $refs = [];
  foreach ($bind as $k => $v) { $refs[$k] = &$bind[$k]; }
  call_user_func_array([$stmt, 'bind_param'], $refs);

  $stmt->execute();
  $rs = $stmt->get_result();
  while($r = $rs->fetch_assoc()){
    $hid = (int)$r['hid'];
    $name = trim((string)($r['replacement'] ?? ''));
    if ($name !== '') {
      $map[$hid][] = $name;
    }
  }
  $stmt->close();

  foreach ($map as $hid => $arr) {
    $arr = array_filter(array_map(function($x){ return trim((string)$x); }, $arr), fn($x)=>$x!=='');
    $arr = array_values(array_unique($arr));
    sort($arr, SORT_NATURAL | SORT_FLAG_CASE);
    $map[$hid] = $arr;
  }
  return $map;
}

function merge_rows_with_replacements(array $rows, array $repMap): array {
  $out = [];
  $seen = [];
  foreach ($rows as $row) {
    $hid = (int)($row['id'] ?? 0);
    $reps = $repMap[$hid] ?? [];
    $repsText = empty($reps) ? '' : implode(' | ', $reps);
    $key = build_group_key($row, $repsText);

    if (!isset($seen[$key])) {
      $row['aggregated_ids'] = [$hid];
      $row['dup_count']      = 1;
      $row['replacements_text'] = $repsText;
      $seen[$key] = $key;
      $out[$key]  = $row;
    } else {
      $out[$key]['dup_count'] += 1;
      $out[$key]['aggregated_ids'][] = $hid;
    }
  }

  foreach ($out as &$r) {
    $ids = $r['aggregated_ids'] ?? [];
    sort($ids);
    $r['aggregated_ids_text'] = implode(',', $ids);
  }
  unset($r);

  $rowsMerged = array_values($out);
  usort($rowsMerged, function($a,$b){
    $da = $a['Ngaytao'] ?? '';
    $db = $b['Ngaytao'] ?? '';
    if ($da === $db) {
      return ((int)$b['id']) <=> ((int)$a['id']);
    }
    return strcmp($db, $da); // DESC
  });
  return $rowsMerged;
}

// ================== AJAX: Load chi tiết theo request_id ==================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'details') {
  header('Content-Type: application/json; charset=UTF-8');
  $reqId = intv($_GET['id'] ?? 0);

  // Lấy request
  $sqlReq = "SELECT
                id, serial_number, serial_thanmay, product,
                full_name, phone_number, address,
                staff_received,
                DATE_FORMAT(received_date, '%d/%m/%Y %H:%i')   AS received_date_fmt,
                DATE_FORMAT(warranty_end, '%d/%m/%Y %H:%i')    AS warranty_end_fmt,
                branch,
                DATE_FORMAT(return_date, '%d/%m/%Y %H:%i')     AS return_date_fmt,
                DATE_FORMAT(shipment_date, '%d/%m/%Y %H:%i')   AS shipment_date_fmt,
                initial_fault_condition, product_fault_condition,
                product_quantity_description,
                image_upload, video_upload,
                type, collaborator_id, collaborator_name, collaborator_phone, collaborator_address,
                save_img, save_video,
                DATE_FORMAT(Ngaytao, '%d/%m/%Y %H:%i')         AS Ngaytao_fmt,
                status, view, print_request,
                approved_by
             FROM warranty_requests_history
             WHERE id = ?";
  $stmtReq = $conn->prepare($sqlReq);
  $stmtReq->bind_param('i', $reqId);
  $stmtReq->execute();
  $resReq = $stmtReq->get_result();
  $req = $resReq->fetch_assoc();
  $stmtReq->close();

  if (!$req) { echo json_encode(['ok'=>false, 'error'=>'Không tìm thấy yêu cầu hợp lệ.']); exit; }

  // Kiểm tra quyền xem (theo zone); nếu không có quyền → vẫn cho xem chi tiết, chỉ hạn chế xác nhận (logic dưới phần save).
  // Nếu muốn chặn xem ngoài zone, mở comment dưới:
  // if (!can_approve_branch(current_position(), (string)$req['branch'], $ZONE_BRANCHES)) {
  //   echo json_encode(['ok'=>false, 'error'=>'Bạn không có quyền xem yêu cầu ngoài vùng.']); exit;
  // }

  $sqlDt = "SELECT
              id, warranty_request_id, error_type, solution, replacement, quantity, unit_price
            FROM warranty_request_details_history
            WHERE warranty_request_history_id = ?
            ORDER BY id ASC";
  $stmtDt = $conn->prepare($sqlDt);
  $stmtDt->bind_param('i', $reqId);
  $stmtDt->execute();
  $resDt = $stmtDt->get_result();
  $rows = [];
  while ($r = $resDt->fetch_assoc()) { $rows[] = $r; }
  $stmtDt->close();

  echo json_encode(['ok'=>true, 'request'=>$req, 'details'=>$rows], JSON_UNESCAPED_UNICODE);
  exit;
}

// ================== AJAX: Lưu chi tiết (UPSERT) + PHÂN QUYỀN THEO VÙNG ==================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'save_details') {
  header('Content-Type: application/json; charset=UTF-8');
  $raw  = file_get_contents('php://input');
  $data = json_decode($raw, true);

  $reqId = intv($data['id'] ?? 0);
  $items = $data['items'] ?? [];

  if ($reqId <= 0) { echo json_encode(['ok'=>false, 'error'=>'Thiếu ID yêu cầu']); exit; }

  // Lấy branch của request để kiểm tra quyền duyệt
  $stmtChk = $conn->prepare("SELECT branch FROM warranty_requests_history WHERE id = ?");
  $stmtChk->bind_param('i', $reqId);
  $stmtChk->execute();
  $branchRow = $stmtChk->get_result()->fetch_assoc();
  $stmtChk->close();

  $branchVal = (string)($branchRow['branch'] ?? '');
  if (!can_approve_branch(current_position(), $branchVal, $ZONE_BRANCHES)) {
    echo json_encode(['ok'=>false, 'error'=>'Bạn không có quyền duyệt yêu cầu thuộc chi nhánh ngoài vùng.']); exit;
  }

  $conn->begin_transaction();
  try {
    // 1) Lấy danh sách id hiện có + lấy sẵn warranty_request_id nếu có
    $oldIds = [];
    $wrid   = 0;
    $q1 = $conn->prepare("SELECT id, warranty_request_id FROM warranty_request_details_history WHERE warranty_request_history_id = ?");
    $q1->bind_param('i', $reqId);
    $q1->execute();
    $r1 = $q1->get_result();
    while($row = $r1->fetch_assoc()){
      $oldIds[] = (int)$row['id'];
      if(!$wrid && !empty($row['warranty_request_id'])) $wrid = (int)$row['warranty_request_id'];
    }
    $q1->close();

    if(!$wrid){ $wrid = $reqId; }

    // 2) Upsert
    $incomingIds = [];
    foreach ($items as $it) {
      $detailId = intv($it['id'] ?? 0);
      $name     = trim((string)($it['name'] ?? ''));
      $qty      = (int)($it['qty'] ?? 0);
      $price    = (float)($it['price'] ?? 0);
      $err      = trim((string)($it['err'] ?? ''));
      $sol      = trim((string)($it['sol'] ?? ''));

      // Cho phép lưu khi có error_type hoặc replacement
if (($name === '' && $err === '') || $qty <= 0) { continue; }


      if ($detailId > 0) {
        $incomingIds[] = $detailId;
        $upd = $conn->prepare("
          UPDATE warranty_request_details_history
             SET error_type = ?, solution = ?, replacement = ?, quantity = ?, unit_price = ?
           WHERE id = ? AND warranty_request_history_id = ?
        ");
        $upd->bind_param('sssidii', $err, $sol, $name, $qty, $price, $detailId, $reqId);
        $upd->execute();
        $upd->close();
      } else {
        $ins = $conn->prepare("
          INSERT INTO warranty_request_details_history
            (warranty_request_history_id, warranty_request_id, error_type, solution, replacement, quantity, unit_price)
          VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->bind_param('iisssid', $reqId, $wrid, $err, $sol, $name, $qty, $price);
        $ins->execute();
        $ins->close();
      }
    }

    // 3) DELETE các dòng đã bị xoá, nhưng giữ lại nếu solution = 'Sửa chữa tại chỗ (lỗi nhẹ)'
if (!empty($oldIds)) {
  $toDelete = array_values(array_diff($oldIds, $incomingIds));
  if (!empty($toDelete)) {
    $place = implode(',', array_fill(0, count($toDelete), '?'));
    $types = str_repeat('i', count($toDelete) + 1); // +1 cho reqId
    $sqlDel = "DELETE FROM warranty_request_details_history
               WHERE warranty_request_history_id = ? 
                 AND id IN ($place)
                 AND solution <> 'Sửa chữa tại chỗ (lỗi nhẹ)'";   // ✅ giữ lại dòng sửa chữa nhẹ
    $stmtDel = $conn->prepare($sqlDel);
    $bindParams = [$types, $reqId];
    foreach ($toDelete as $x) { $bindParams[] = $x; }
    $refs = [];
    foreach ($bindParams as $k => $v) { $refs[$k] = &$bindParams[$k]; }
    call_user_func_array([$stmtDel, 'bind_param'], $refs);
    $stmtDel->execute();
    $stmtDel->close();
  }
}


    $conn->commit();
    echo json_encode(['ok'=>true]);
  } catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(['ok'=>false, 'error'=>'Lưu thất bại: '.$e->getMessage()]);
  }
  exit;
}

// ================== ACTION: Xác nhận đã nhận lệnh in (có kiểm tra vùng) ==================
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ack_print') {
  $reqId = intv($_POST['id'] ?? 0);
  $approver = trim((string)($_SESSION['position'] ?? 'unknown'));

  // Lấy branch để check quyền
  $stmtB = $conn->prepare("SELECT branch FROM warranty_requests_history WHERE id = ?");
  $stmtB->bind_param('i', $reqId);
  $stmtB->execute();
  $branchRow = $stmtB->get_result()->fetch_assoc();
  $stmtB->close();

  if (!$branchRow) {
    $flash = 'Yêu cầu không hợp lệ.';
  } else {
    $branchVal = (string)$branchRow['branch'];
    if (!can_approve_branch($approver, $branchVal, $ZONE_BRANCHES)) {
      $flash = 'Bạn không có quyền xác nhận yêu cầu thuộc chi nhánh ngoài vùng.';
    } else {
      $sqlAck = "UPDATE warranty_requests_history
                 SET print_request = 2, approved_by = ?
                 WHERE id = ?";
      $stmt = $conn->prepare($sqlAck);
      $stmt->bind_param('si', $approver, $reqId);
      $ok = $stmt->execute();
      $stmt->close();
      $flash = $ok ? ('Đã xác nhận và ghi nhận người duyệt: ' . esc($approver) . ' cho yêu cầu #'.$reqId)
                   : 'Xác nhận thất bại hoặc yêu cầu không hợp lệ.';
    }
  }
}

// ================== Input lọc & phân trang ==================
$phone = trim($_GET['phone'] ?? '');
$staff = trim($_GET['staff'] ?? '');
$df    = trim($_GET['df'] ?? '');
$dt    = trim($_GET['dt'] ?? '');

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 15;
$offset = ($page - 1) * $limit;

$where  = " WHERE 1=1 ";
$params = [];
$types  = '';

if ($phone !== '') {
  $where  .= " AND COALESCE(phone_number,'') LIKE CONCAT('%', ?, '%') ";
  $params[] = $phone; $types .= 's';
}
if ($staff !== '') {
  $where  .= " AND COALESCE(staff_received,'') LIKE CONCAT('%', ?, '%') ";
  $params[] = $staff; $types .= 's';
}
$dfYmd = to_ymd($df);
$dtYmd = to_ymd($dt);
if ($dfYmd && $dtYmd) {
  $where  .= " AND DATE(Ngaytao) BETWEEN ? AND ? ";
  array_push($params, $dfYmd, $dtYmd); $types .= 'ss';
} elseif ($dfYmd) {
  $where  .= " AND DATE(Ngaytao) >= ? ";
  $params[] = $dfYmd; $types .= 's';
} elseif ($dtYmd) {
  $where  .= " AND DATE(Ngaytao) <= ? ";
  $params[] = $dtYmd; $types .= 's';
}

// Thêm filter theo zone
$position = current_position();
$where = append_zone_filter($where, $params, $types, $position, $ZONE_BRANCHES);

// ======= Count (trước gộp) =======
$sqlCount = "SELECT COUNT(*) AS cnt
             FROM warranty_requests_history
             {$where}";
$stmtC = $conn->prepare($sqlCount);
if ($types) { $stmtC->bind_param($types, ...$params); }
$stmtC->execute();
$total_before_merge = (int)($stmtC->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmtC->close();
$totalPages = max(1, (int)ceil($total_before_merge / $limit));

// ======= Lấy danh sách (theo trang) =======
$sqlList = "SELECT
              id, serial_number, product,
              full_name, phone_number, staff_received, branch,
              status,
              Ngaytao,
              DATE_FORMAT(Ngaytao, '%d/%m/%Y %H:%i') AS Ngaytao_fmt,
              approved_by, print_request
            FROM warranty_requests_history
            {$where}
            ORDER BY Ngaytao DESC, id DESC
            LIMIT ? OFFSET ?";
$stmtL = $conn->prepare($sqlList);
if ($types) {
  $types2 = $types.'ii';
  $bind = array_merge($params, [$limit, $offset]);
  $stmtL->bind_param($types2, ...$bind);
} else {
  $stmtL->bind_param('ii', $limit, $offset);
}
$stmtL->execute();
$tmpRes = $stmtL->get_result();

$rawRows = [];
$idsThisPage = [];
while($r = $tmpRes->fetch_assoc()){
  $rawRows[] = $r;
  $idsThisPage[] = (int)$r['id'];
}
$stmtL->close();

// Map replacement cho các id trong trang
$repMapPage = fetch_replacements_map($conn, $idsThisPage);
// Gộp trong phạm vi trang
$rowsMergedPage = merge_rows_with_replacements($rawRows, $repMapPage);
$total_after_merge_this_page = count($rowsMergedPage);

// ================== EXPORT EXCEL (áp dụng cùng filter zone) ==================
if ($WILL_EXPORT) {
  $sqlAll = "SELECT
                id, serial_number, product,
                full_name, phone_number, staff_received, branch,
                status,
                Ngaytao,
                DATE_FORMAT(Ngaytao, '%d/%m/%Y %H:%i') AS Ngaytao_fmt,
                approved_by, print_request
             FROM warranty_requests_history
             {$where} 
             AND print_request = 2
             ORDER BY Ngaytao DESC, id DESC";
  $stmtAll = $conn->prepare($sqlAll);
  if ($types) { $stmtAll->bind_param($types, ...$params); }
  $stmtAll->execute();
  $rsAll = $stmtAll->get_result();
  $allRows = [];
  $allIds  = [];
  while($row = $rsAll->fetch_assoc()){
    $allRows[] = $row;
    $allIds[]  = (int)$row['id'];
  }
  $stmtAll->close();

  $repMapAll = fetch_replacements_map($conn, $allIds);
  $rowsMergedAll = merge_rows_with_replacements($allRows, $repMapAll);

  // Tạo file Excel
  $spreadsheet = new Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle('Bao_cao_xuat_kho');

  $headers = [
    'A' => 'STT',
    'B' => 'ID (đại diện)',
    'C' => 'Danh sách ID (gộp)',
    'D' => 'Số lần lặp (gộp)',
    'E' => 'Sản phẩm',
    'F' => 'Serial',
    'G' => 'Khách hàng',
    'H' => 'SĐT',
    'I' => 'Kỹ thuật viên',
    'J' => 'Chi nhánh',
    'K' => 'Trạng thái',
    'L' => 'Ngày gửi yêu cầu',
    'M' => 'Người duyệt',
    'N' => 'Bộ linh kiện thay thế (đã gộp)'
  ];
  foreach ($headers as $col => $text) {
    $sheet->setCellValue($col.'1', $text);
  }

  $sheet->getStyle('A1:N1')->getFont()->setBold(true);
  $sheet->getStyle('A1:N1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
  $sheet->getStyle('A1:N1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9F2FF');
  $sheet->getStyle('A1:N1')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

  $rowIdx = 2; $stt = 1;
  foreach ($rowsMergedAll as $r) {
    $sheet->setCellValueExplicit('A'.$rowIdx, $stt, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
    $sheet->setCellValue('B'.$rowIdx, (int)$r['id']);
    $sheet->setCellValue('C'.$rowIdx, (string)($r['aggregated_ids_text'] ?? (string)$r['id']));
    $sheet->setCellValue('D'.$rowIdx, (int)($r['dup_count'] ?? 1));
    $sheet->setCellValue('E'.$rowIdx, $r['product'] ?? '');
    $sheet->setCellValue('F'.$rowIdx, $r['serial_number'] ?? '');
    $sheet->setCellValue('G'.$rowIdx, $r['full_name'] ?? '');
    $sheet->setCellValue('H'.$rowIdx, $r['phone_number'] ?? '');
    $sheet->setCellValue('I'.$rowIdx, $r['staff_received'] ?? '');
    $sheet->setCellValue('J'.$rowIdx, $r['branch'] ?? '');
    $sheet->setCellValue('K'.$rowIdx, $r['status'] ?? '');
    $sheet->setCellValue('L'.$rowIdx, $r['Ngaytao_fmt'] ?? '');
    $sheet->setCellValue('M'.$rowIdx, $r['approved_by'] ?? '');
    $sheet->setCellValue('N'.$rowIdx, $r['replacements_text'] ?? '');

    $sheet->getStyle('A'.$rowIdx.':N'.$rowIdx)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_HAIR);
    $sheet->getStyle('A'.$rowIdx.':N'.$rowIdx)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

    $rowIdx++; $stt++;
  }

  foreach (range('A','N') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
  }

  $filename = 'Baocaoxuatkho_linh_kien_bao_hanh'.date('Ymd_His').'.xlsx';
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  header('Cache-Control: max-age=0');
  $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
  $writer->save('php://output');
  exit;
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Quản lý xuất kho linh kiện bảo hành</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap 4 (CDN) -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">

  <style>
    .table-sticky thead th { position: sticky; top: 0; z-index: 2; background: #fff; box-shadow: 0 1px 0 rgba(0,0,0,.05); }
    .sticky-actions { position: sticky; right: 0; background: #fff; z-index: 1; }
    .badge-soft { background: #e9f2ff; color:#0b5ed7; border:1px solid #cfe2ff; font-weight: 500; padding:.35rem .5rem; border-radius: .25rem; }
    .nowrap { white-space: nowrap; }

    .suggest-box{max-height:220px; overflow:auto; border:1px solid #e5e5e5; border-radius:4px;}
    .suggest-item{padding:.35rem .5rem; cursor:pointer;}
    .suggest-item:hover{background:#f6f8fa;}

    .pm-name-wrap{ position:relative; }
    .pm-row-suggest{
      position:absolute; top:100%; left:0; right:0;
      z-index:1060; background:#fff;
      border:1px solid #e5e5e5; border-radius:4px;
      max-height:220px; overflow:auto; display:none;
    }
    .pm-row-suggest .item{ padding:.35rem .5rem; cursor:pointer; }
    .pm-row-suggest .item:hover{ background:#f6f8fa; }
  </style>
</head>
<body class="bg-light">
  <!-- NAVBAR -->
  <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom">
    <div class="container-fluid">
      <span class="navbar-brand font-weight-bold mb-0">⚙️ DUYỆT IN PHIẾU VÀ XUẤT KHO LINH KIỆN BẢO HÀNH</span>

      <div class="ml-auto d-flex align-items-center">
        <?php $pos = current_position(); ?>
        <?php if ($pos): ?>
          <span class="badge badge-info mr-2">Vùng: <?= esc($pos) ?></span>
        <?php endif; ?>
        <a href="admin.php" class="btn btn-outline-secondary btn-sm">Trang chủ</a>
      </div>
    </div>
  </nav>

  <div class="container-fluid my-4">
    <!-- FLASH -->
    <?php if ($flash): ?>
      <div class="alert alert-info alert-dismissible fade show" role="alert">
        <?= esc($flash) ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
    <?php endif; ?>

    <!-- FILTER CARD -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <form class="form-row" method="get">
          <div class="col-md-3 mb-2">
            <label class="small text-muted mb-1">SĐT khách hàng</label>
            <input type="text" class="form-control" name="phone" value="<?= esc($phone) ?>" placeholder="Ví dụ: 09xx...">
          </div>
          <div class="col-md-3 mb-2">
            <label class="small text-muted mb-1">Tên kỹ thuật viên</label>
            <input type="text" class="form-control" name="staff" value="<?= esc($staff) ?>" placeholder="Ví dụ: Nguyễn Văn A">
          </div>
          <div class="col-md-2 mb-2">
            <label class="small text-muted mb-1">Từ ngày (d/m/Y)</label>
            <input type="text" class="form-control" name="df" value="<?= esc($df) ?>" placeholder="01/09/2025">
          </div>
          <div class="col-md-2 mb-2">
            <label class="small text-muted mb-1">Đến ngày (d/m/Y)</label>
            <input type="text" class="form-control" name="dt" value="<?= esc($dt) ?>" placeholder="10/09/2025">
          </div>
          <div class="col-md-2 mb-2 d-flex align-items-end">
            <button class="btn btn-primary mr-2" type="submit">Lọc</button>
            <a class="btn btn-outline-secondary mr-2" href="?">Xoá lọc</a>
            <?php
              $q = array_filter([
                'phone'=>$phone ?: null,
                'staff'=>$staff ?: null,
                'df'=>$df ?: null,
                'dt'=>$dt ?: null,
                'export'=>'excel'
              ]);
              $exportUrl = '?'.http_build_query($q);
            ?>
            <a class="btn btn-success" href="<?= esc($exportUrl) ?>">Xuất Excel</a>
          </div>
        </form>
      </div>
    </div>

    <!-- LIST CARD -->
    <div class="card shadow-sm">
      <div class="card-header bg-white">
        <div class="d-flex align-items-center">
          <div class="font-weight-bold">Danh sách yêu cầu</div>
          <div class="ml-auto text-muted small">
            Tổng (trước gộp): <strong><?= number_format($total_before_merge) ?></strong>
            <span class="mx-2">•</span>
            Sau gộp (trang này): <strong><?= number_format($total_after_merge_this_page) ?></strong>
          </div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 table-sticky">
          <thead class="thead-light">
            <tr class="text-uppercase small">
              <th class="nowrap">#</th>
              <th class="nowrap">ID</th>
              <th class="nowrap">SL</th>
              <th class="nowrap">Serial</th>
              <th class="nowrap">Sản phẩm</th>
              <th class="nowrap">Thông tin khách hàng</th>
              <th class="nowrap">Kỹ thuật viên</th>
              <th class="nowrap">Chi nhánh</th>
              <th class="nowrap">Ngày gửi yêu cầu</th>
              <th class="nowrap">Bộ linh kiện thay thế</th>
              <th class="nowrap sticky-actions text-right" style="min-width:240px;">Tác vụ</th>
            </tr>
          </thead>
          <tbody>
          <?php
            $rowNo = 0;
            foreach($rowsMergedPage as $row):
              $rowNo++;
              $isApproved = ((int)($row['print_request'] ?? 0) === 2) || !empty($row['approved_by']);

              // Quyền xác nhận theo vùng + branch của dòng
              $rowBranch = (string)($row['branch'] ?? '');
              $canApproveThisRow = can_approve_branch($position, $rowBranch, $ZONE_BRANCHES);
          ?>
            <tr class="align-middle">
              <td class="nowrap"><?= $rowNo ?></td>
              <td class="nowrap font-weight-bold">#<?= (int)$row['id'] ?></td>
              <td class="nowrap"><?= (int)($row['dup_count'] ?? 1) ?></td>

              <td class="nowrap"><span class="badge-soft"><?= esc($row['serial_number'] ?? '-') ?></span></td>
              <td class="nowrap"><?= esc($row['product'] ?? '-') ?></td>
              <td class="nowrap">
                <?= esc($row['full_name'] ?? '-') ?>
                <?php if (!empty($row['phone_number'])): ?>
                  <small class="text-muted d-block"><?= esc($row['phone_number']) ?></small>
                <?php endif; ?>
              </td>
              <td class="nowrap"><?= esc($row['staff_received'] ?? '-') ?></td>
              <td class="nowrap"><?= esc($row['branch'] ?? '-') ?></td>
              
              <td class="nowrap"><?= esc($row['Ngaytao_fmt'] ?? '') ?></td>
              <td class="nowrap"><?= esc($row['replacements_text'] ?? '') ?></td>

              <td class="nowrap sticky-actions text-right">
                <button class="btn btn-outline-primary btn-sm mr-1"
                        data-toggle="modal" data-target="#detailModal"
                        data-id="<?= (int)$row['id'] ?>">
                  Xem chi tiết
                </button>

                <?php if ($isApproved): ?>
                  <button class="btn btn-warning btn-sm" type="button" disabled title="Đã duyệt, không thể xác nhận lại">Đã duyệt</button>
                <?php else: ?>
                  <?php if ($canApproveThisRow): ?>
                    <button class="btn btn-success btn-sm"
                            type="button"
                            onclick="openPartsModal(<?= (int)$row['id'] ?>)">
                      Xác nhận
                    </button>
                  <?php else: ?>
                    <button class="btn btn-outline-secondary btn-sm" type="button" disabled
                      title="Bạn không có quyền xác nhận yêu cầu thuộc chi nhánh ngoài vùng">
                      Không có quyền
                    </button>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rowsMergedPage)): ?>
            <tr><td colspan="13" class="text-center text-muted py-4">Không có yêu cầu nào.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white">
          <nav>
            <ul class="pagination pagination-sm mb-0">
              <?php
                $buildLink = function($p) use ($phone, $staff, $df, $dt){
                  $q = http_build_query(array_filter([
                    'phone'=>$phone ?: null,
                    'staff'=>$staff ?: null,
                    'df'=>$df ?: null,
                    'dt'=>$dt ?: null,
                    'page'=>$p
                  ]));
                  return '?'.$q;
                };
              ?>
              <li class="page-item <?= $page<=1?'disabled':'' ?>">
                <a class="page-link" href="<?= $buildLink(max(1,$page-1)) ?>">«</a>
              </li>
              <?php
                $start = max(1, $page-2);
                $end   = min($totalPages, $page+2);
                for($i=$start; $i<=$end; $i++):
              ?>
              <li class="page-item <?= $i===$page?'active':'' ?>">
                <a class="page-link" href="<?= $buildLink($i) ?>"><?= $i ?></a>
              </li>
              <?php endfor; ?>
              <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
                <a class="page-link" href="<?= $buildLink(min($totalPages,$page+1)) ?>">»</a>
              </li>
            </ul>
          </nav>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Modal chi tiết -->
  <div class="modal fade" id="detailModal" tabindex="-1" role="dialog" aria-labelledby="detailLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h6 class="modal-title" id="detailLabel">Chi tiết yêu cầu</h6>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div id="detailBody" class="p-2">
            <div class="text-center text-muted py-4">Đang tải...</div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-dismiss="modal">Đóng</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal chọn linh kiện khi duyệt (ẨN cột Lỗi & Giải pháp nhưng giữ logic) -->
  <div class="modal fade" id="partsModal" tabindex="-1" role="dialog" aria-labelledby="partsLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h6 class="modal-title" id="partsLabel">Chọn linh kiện xuất kho</h6>
          <button type="button" class="close" data-dismiss="modal" aria-label="Đóng">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>Tìm nhanh (thêm dòng mới) — nhập ≥ 2 ký tự</label>
            <input type="text" class="form-control" id="pmSearch" placeholder="VD: lưỡi dao, mô tơ...">
            <div id="pmSuggest" class="suggest-box mt-2 d-none"></div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm table-bordered">
              <thead class="thead-light">
                <tr class="text-uppercase small">
                  <th style="width:42px">#</th>
                  <th>Tên linh kiện</th>
                  <th style="width:110px" class="text-right">Đơn giá</th>
                  <th style="width:90px"  class="text-right">SL</th>
                  <th style="width:130px" class="text-right">Thành tiền</th>
                  <th style="width:60px"></th>
                </tr>
              </thead>
              <tbody id="pmBody"></tbody>
              <tfoot>
                <tr>
                  <th colspan="4" class="text-right">Tổng cộng</th>
                  <th class="text-right" id="pmGrand">0</th>
                  <th></th>
                </tr>
              </tfoot>
            </table>
          </div>
          <button class="btn btn-outline-secondary btn-sm" type="button" id="pmAddEmpty">+ Thêm dòng trống</button>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-dismiss="modal">Hủy</button>
          <button class="btn btn-success" id="pmSave">Lưu &amp; In</button>
        </div>
      </div>
    </div>
  </div>

  <!-- JS: jQuery + Popper + Bootstrap 4 -->
  <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
  <script>
  function escapeHtml(s){
    if (s === null || s === undefined) return '';
    return String(s)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }
  function mapProductItem(x){
    return {
      id:    x.id || x.product_id || x.code || 0,
      name:  x.name || x.product_name || x.title || '',
      price: parseFloat(x.price || x.unit_price || x.gia || 0)
    };
  }
  function debounce(fn, ms){
    let t; return function(...args){ clearTimeout(t); t=setTimeout(()=>fn.apply(this,args), ms); };
  }

  // ================== Modal CHI TIẾT ==================
  $('#detailModal').on('show.bs.modal', function (event) {
    var $trigger = $(event.relatedTarget || this);
    var id = $trigger.data('id') || $trigger.getAttribute('data-id');
    if(!id){ return; }

    var $body = document.getElementById('detailBody');
    $body.innerHTML = '<div class="text-center text-muted py-4">Đang tải...</div>';

    fetch('?ajax=details&id=' + encodeURIComponent(id))
      .then(r => r.json())
      .then(data => {
        if (!data.ok) {
          $body.innerHTML = '<div class="alert alert-warning">'+ (data.error || 'Lỗi tải dữ liệu') +'</div>';
          return;
        }
        const req = data.request || {};
        const rows = data.details || [];

        let html = `
          <ul class="list-group list-group-flush mb-3">
            <li class="list-group-item d-flex">
              <div class="mr-3"><strong>ID</strong></div>
              <div>#${req.id ?? ''}</div>
            </li>
            <li class="list-group-item">
              <div class="d-flex flex-wrap">
                <div class="mr-3"><strong>Sản phẩm:</strong> ${escapeHtml(req.product ?? '-')}</div>
              </div>
            </li>
            <li class="list-group-item">
              <div class="d-flex flex-wrap">
                <div class="mr-3"><strong>Cộng tác viên:</strong> ${escapeHtml(req.collaborator_name ?? '-')}</div>
                <div class="mr-3"><strong>SĐT CTV:</strong> ${escapeHtml(req.collaborator_phone ?? '-')}</div>
              </div>
            </li>
            <li class="list-group-item">
              <div class="d-flex flex-wrap">
                <div class="mr-3"><strong>Chi nhánh:</strong> ${escapeHtml(req.branch ?? '-')}</div>
                <div class="mr-3"><strong>Trạng thái:</strong> ${escapeHtml(req.status ?? '-')}</div>
                <div class="mr-3"><strong>Ngày tạo yêu cầu:</strong> ${escapeHtml(req.Ngaytao_fmt ?? '')}</div>
                ${req.approved_by ? `<div class="mr-3"><strong>Người duyệt (vùng):</strong> ${escapeHtml(req.approved_by)}</div>` : ``}
              </div>
            </li>
          </ul>
        `;

        html += `
          <h6 class="mb-2">Danh sách linh kiện / xử lý</h6>
          <div class="table-responsive">
            <table class="table table-sm table-bordered">
              <thead class="thead-light">
                <tr class="text-uppercase small">
                  <th style="width:60px;">#</th>
                  <th>Lỗi (error_type)</th>
                  <th>Giải pháp (solution)</th>
                  <th>Thay thế (replacement)</th>
                  <th class="text-right" style="width:100px;">SL</th>
                  <th class="text-right" style="width:140px;">Đơn giá</th>
                </tr>
              </thead>
              <tbody>
        `;

        if (rows.length === 0) {
          html += `<tr><td colspan="6" class="text-center text-muted">Chưa có chi tiết.</td></tr>`;
        } else {
          rows.forEach((r, idx) => {
            html += `
              <tr>
                <td>${idx+1}</td>
                <td>${escapeHtml(r.error_type ?? '')}</td>
                <td>${escapeHtml(r.solution ?? '')}</td>
                <td>${escapeHtml(r.replacement ?? '')}</td>
                <td class="text-right">${(r.quantity ?? '')}</td>
                <td class="text-right">${(r.unit_price ?? '')}</td>
              </tr>
            `;
          });
        }
        html += `</tbody></table></div>`;
        $body.innerHTML = html;
      })
      .catch(() => {
        $body.innerHTML = '<div class="alert alert-danger">Không thể tải dữ liệu.</div>';
      });
  });

  // ================== Modal CHỌN/SỬA LINH KIỆN ==================
  let PM_REQ_ID = 0;
  const PRODUCTS_API = '../trungtambaohanh3/get_products2.php';

  function openPartsModal(id){
    PM_REQ_ID = id;
    document.getElementById('pmSearch').value = '';
    document.getElementById('pmSuggest').classList.add('d-none');
    document.getElementById('pmSuggest').innerHTML = '';
    document.getElementById('pmBody').innerHTML = '';
    document.getElementById('pmGrand').innerText = '0';

    fetch('?ajax=details&id=' + encodeURIComponent(id))
      .then(r=>r.json()).then(d=>{
        if(d.ok){
          (d.details||[]).forEach(it=>{
            addRow({
              id:   it.id,
              name: it.replacement || '',
              price: parseFloat(it.unit_price||0),
              err:  it.error_type || '',
              sol:  it.solution || ''
            }, parseInt(it.quantity||1));
          });
          recalcGrand();
        }
      }).finally(()=>$('#partsModal').modal('show'));
  }

  const pmSearch  = document.getElementById('pmSearch');
  const pmSuggest = document.getElementById('pmSuggest');

  pmSearch.addEventListener('input', function(){
    const kw = this.value.trim();
    if (kw.length < 2){ pmSuggest.classList.add('d-none'); pmSuggest.innerHTML=''; return; }
    fetch(PRODUCTS_API + '?kw=' + encodeURIComponent(kw))
      .then(r=>r.json())
      .then(data=>{
        let items = Array.isArray(data) ? data : (data && Array.isArray(data.items) ? data.items : []);
        const html = items.map(x=>{
          const it = mapProductItem(x);
          const price = isNaN(it.price)?0:it.price;
          return `<div class="suggest-item" data-name="${escapeHtml(it.name)}" data-price="${price}">
                    ${escapeHtml(it.name)} <span class="text-muted">- ${price.toLocaleString('vi-VN')}</span>
                  </div>`;
        }).join('');
        pmSuggest.innerHTML = html || '<div class="p-2 text-muted">Không có kết quả</div>';
        pmSuggest.classList.remove('d-none');
      })
      .catch(()=>{
        pmSuggest.innerHTML = '<div class="p-2 text-danger">Lỗi tải dữ liệu</div>';
        pmSuggest.classList.remove('d-none');
      });
  });

  pmSuggest.addEventListener('click', function(e){
    const item = e.target.closest('.suggest-item');
    if(!item) return;
    const name = item.getAttribute('data-name') || '';
    const price= parseFloat(item.getAttribute('data-price')||'0') || 0;
    addRow({name, price, err:'', sol:''}, 1);
    pmSuggest.classList.add('d-none');
    pmSearch.value = '';
    recalcGrand();
  });

  document.getElementById('pmAddEmpty').addEventListener('click', function(){
    addRow({name:'', price:0, err:'', sol:''}, 1);
  });

  function addRow(prod, qty){
    const tbody = document.getElementById('pmBody');
    const tr = document.createElement('tr');
    const _id = parseInt(prod.id || 0) || 0;

    tr.innerHTML = `
      <td class="align-middle text-center"></td>

      <td>
        <div class="pm-name-wrap">
          <input type="hidden" class="pm-id"  value="${_id}">
          <input type="hidden" class="pm-err" value="${escapeHtml(prod.err||'')}">
          <input type="hidden" class="pm-sol" value="${escapeHtml(prod.sol||'')}">

          <input class="form-control form-control-sm pm-name"
                 value="${escapeHtml(prod.name||'')}"
                 placeholder="Nhập/tìm linh kiện (≥2 ký tự)">
          <div class="pm-row-suggest"></div>
        </div>
      </td>

      <td class="text-right">
        <input type="number" class="form-control form-control-sm pm-price" min="0" step="1000" value="${isNaN(prod.price)?0:prod.price}">
      </td>

      <td class="text-right">
        <input type="number" class="form-control form-control-sm pm-qty" min="1" step="1" value="${qty||1}">
      </td>

      <td class="text-right align-middle pm-amt">0</td>
      <td class="text-center"><button class="btn btn-sm btn-link text-danger pm-del" title="Xoá">&times;</button></td>
    `;
    tbody.appendChild(tr);
    renumberRows();

    tr.querySelector('.pm-price').addEventListener('input', recalcGrand);
    tr.querySelector('.pm-qty').addEventListener('input', recalcGrand);
    tr.querySelector('.pm-del').addEventListener('click', function(){ tr.remove(); renumberRows(); recalcGrand(); });

    attachRowSuggest(tr);
    recalcGrand();
  }

  function attachRowSuggest(tr){
    const nameInput  = tr.querySelector('.pm-name');
    const priceInput = tr.querySelector('.pm-price');
    const box        = tr.querySelector('.pm-row-suggest');

    const doSearch = debounce(function(){
      const kw = nameInput.value.trim();
      if (kw.length < 2){ box.style.display='none'; box.innerHTML=''; return; }

      fetch(PRODUCTS_API + '?kw=' + encodeURIComponent(kw))
        .then(r=>r.json())
        .then(data=>{
          let items = Array.isArray(data) ? data : (data && Array.isArray(data.items) ? data.items : []);
          const html = items.map(x=>{
            const it = mapProductItem(x);
            const p  = isNaN(it.price)?0:it.price;
            return `<div class="item" data-name="${escapeHtml(it.name)}" data-price="${p}">
                      ${escapeHtml(it.name)} <span class="text-muted">- ${p.toLocaleString('vi-VN')}</span>
                    </div>`;
          }).join('') || '<div class="p-2 text-muted">Không có kết quả</div>';
          box.innerHTML = html;
          box.style.display = 'block';
        })
        .catch(()=>{
          box.innerHTML = '<div class="p-2 text-danger">Lỗi tải dữ liệu</div>';
          box.style.display = 'block';
        });
    }, 250);

    nameInput.addEventListener('input', doSearch);
    nameInput.addEventListener('focus', doSearch);
    nameInput.addEventListener('keydown', e=>{ if(e.key==='Escape'){ box.style.display='none'; } });

    box.addEventListener('click', e=>{
      const it = e.target.closest('.item'); if(!it) return;
      const name = it.getAttribute('data-name') || '';
      const price= parseFloat(it.getAttribute('data-price')||'0')||0;
      nameInput.value  = name;
      priceInput.value = price;
      box.style.display = 'none';
      recalcGrand();
    });

    document.addEventListener('click', (ev)=>{ if(!tr.contains(ev.target)) box.style.display='none'; });
  }

  function renumberRows(){
    const rows = document.querySelectorAll('#pmBody tr');
    rows.forEach((tr,idx)=>{ tr.children[0].textContent = (idx+1); });
  }

  function recalcGrand(){
    let grand = 0;
    document.querySelectorAll('#pmBody tr').forEach(tr=>{
      const price = parseFloat(tr.querySelector('.pm-price').value||'0') || 0;
      const qty   = parseInt(tr.querySelector('.pm-qty').value||'0') || 0;
      const amt = price*qty;
      tr.querySelector('.pm-amt').textContent = (amt).toLocaleString('vi-VN');
      grand += amt;
    });
    document.getElementById('pmGrand').textContent = grand.toLocaleString('vi-VN');
  }

  // Lưu & In
  document.getElementById('pmSave').addEventListener('click', function(){
    if(!PM_REQ_ID){ alert('Thiếu ID yêu cầu'); return; }

    const items = [];
    document.querySelectorAll('#pmBody tr').forEach(tr=>{
      const id    = parseInt(tr.querySelector('.pm-id').value||'0')||0;
      const name  = (tr.querySelector('.pm-name').value||'').trim();
      const err   = (tr.querySelector('.pm-err').value||'').trim();
      const sol   = (tr.querySelector('.pm-sol').value||'').trim();
      const price = parseFloat(tr.querySelector('.pm-price').value||'0')||0;
      const qty   = parseInt(tr.querySelector('.pm-qty').value||'0')||0;
      if(name && qty>0){
        items.push({id, name, price, qty, err, sol});
      }
    });

    fetch('?ajax=save_details', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id: PM_REQ_ID, items})
    })
    .then(r=>r.json())
    .then(d=>{
      if(!d.ok){ alert(d.error || 'Lưu chi tiết thất bại'); throw new Error('save_failed'); }
      const fd = new FormData();
      fd.append('action','ack_print');
      fd.append('id', PM_REQ_ID);
      return fetch('', {method:'POST', body: fd});
    })
    .then(()=> {
      $('#partsModal').modal('hide');
      const url = 'https://kuchenvietnam.vn/kuchen/khokuchen/print_warranty.php?id=' + encodeURIComponent(PM_REQ_ID);
      window.open(url, '_blank');
      setTimeout(()=>{ location.reload(); }, 600);
    })
    .catch(()=> alert('Có lỗi xảy ra, vui lòng thử lại'));
  });
  </script>
</body>
</html>
<?php
$conn->close();
