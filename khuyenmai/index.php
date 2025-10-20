<?php
// index.php — Promotions Manager
// Nâng cấp: 
// - Auto điền giá (main & gift), tự cập nhật theo SL
// - Auto-focus
// - "Số suất KM" lên ngang "Kênh", mặc định 50, step 10
// - Chống trùng lặp khuyến mãi (server + cảnh báo trước khi lưu)
// - Ngày bắt đầu/kết thúc LUÔN lấy theo bản mới nhất trong CSDL (toàn hệ thống); khi “Dùng lại” combo cũng KHÔNG đổi ngày
// - Gợi ý combo gần giống hiển thị ngay dưới Quà tặng và TỰ ẨN sau 5 giây nếu không tương tác
// - Modal gần full-screen, footer dính dưới để không cần cuộn mới bấm Lưu

include '../auth.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['full_name'])) { header("Location: index.php"); exit(); }

include '../config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Ho_Chi_Minh');

// ===== Helpers =====
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function vnd($n){ return number_format((int)$n, 0, ',', '.'); }
function fromInputDT($s){ if(!$s) return null; return date('Y-m-d H:i:s', strtotime($s)); }
function json_norm($v){ return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION); }
function typeLabel($t){ $t=(int)$t; return $t===1?'Droppii':($t===2?'Shopee':'—'); }
function typeBadgeClass($t){ $t=(int)$t; return $t===1?'badge-info':($t===2?'badge-success':'badge-secondary'); }

// ===== Bootstrap DB: audit table =====
$conn->query("
  CREATE TABLE IF NOT EXISTS promotion_audits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    promotion_id INT NOT NULL,
    action ENUM('create','update','delete') NOT NULL,
    changed_by VARCHAR(255) NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    change_summary TEXT NULL,
    before_json LONGTEXT NULL,
    after_json  LONGTEXT NULL,
    KEY idx_promo (promotion_id),
    KEY idx_time (changed_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ===== Gifts helpers =====
function get_gifts_by_promo($conn, $pid){
  $arr = [];
  $stmt = $conn->prepare("SELECT gift_product, gift_qty, gift_value FROM promotion_gifts WHERE promotion_id=? ORDER BY id ASC");
  $stmt->bind_param('i', $pid);
  $stmt->execute();
  $res = $stmt->get_result();
  while($r = $res->fetch_assoc()){
    $arr[] = [
      'gift_product' => (string)$r['gift_product'],
      'gift_qty'     => (int)$r['gift_qty'],
      'gift_value'   => (int)$r['gift_value'],
    ];
  }
  return $arr;
}
function normalize_gifts($gifts){
  $g = array_map(function($x){
    return [
      'gift_product' => (string)($x['gift_product'] ?? ''),
      'gift_qty'     => (int)($x['gift_qty'] ?? 0),
      'gift_value'   => (int)($x['gift_value'] ?? 0),
    ];
  }, $gifts ?? []);
  usort($g, function($a,$b){
    return [$a['gift_product'],$a['gift_qty'],$a['gift_value']] <=> [$b['gift_product'],$b['gift_qty'],$b['gift_value']];
  });
  return $g;
}

// ===== Audit =====
function diff_summary($before, $after){
  $changed = [];
  foreach (['main_product','main_qty','main_value','start_at','end_at','qty','highlight','type','note','coupon_type','coupon_code','coupon_value','coupon_note'] as $k){
    $bv = $before[$k] ?? null; $av = $after[$k] ?? null;
    if ((string)$bv !== (string)$av){ $changed[] = $k; }
  }
  $bg = normalize_gifts($before['gifts'] ?? []); $ag = normalize_gifts($after['gifts'] ?? []);
  if (json_norm($bg) !== json_norm($ag)){ $changed[] = 'gifts'; }
  return $changed ? ('Changed: '.implode(', ',$changed)) : 'No changes';
}
function write_audit($conn, $pid, $action, $by, $before, $after){
  $sum = diff_summary($before, $after);
  $bj=json_norm($before); $aj=json_norm($after);
  $stmt = $conn->prepare("INSERT INTO promotion_audits (promotion_id, action, changed_by, change_summary, before_json, after_json) VALUES (?,?,?,?,?,?)");
  $stmt->bind_param('isssss', $pid, $action, $by, $sum, $bj, $aj);
  $stmt->execute();
}

// ===== Duplication helpers =====
function promo_signature($arr){
  // Chỉ dùng các field “định danh” KM để chống trùng (không tính highlight, note, qty)
  $keys = ['main_product','main_qty','main_value','type','coupon_type','coupon_code','coupon_value','coupon_note','gifts'];
  $sig = [];
  foreach ($keys as $k){ $sig[$k] = $arr[$k] ?? null; }
  return hash('sha256', json_norm($sig));
}
function build_promo_array($rowFields){
  return [
    'main_product'=>$rowFields['main_product'],
    'main_qty'    =>(int)$rowFields['main_qty'],
    'main_value'  =>(int)$rowFields['main_value'],
    'gift_product'=>$rowFields['gift_product'] ?? '',
    'gift_qty'    =>(int)($rowFields['gift_qty'] ?? 0),
    'gift_value'  =>(int)($rowFields['gift_value'] ?? 0),
    'start_at'    =>$rowFields['start_at'],
    'end_at'      =>$rowFields['end_at'],
    'qty'         =>(int)$rowFields['qty'],
    'highlight'   =>(int)$rowFields['highlight'],
    'type'        =>(int)$rowFields['type'],
    'note'        =>$rowFields['note'] ?? '',
    'coupon_type' =>$rowFields['coupon_type'] ?? '',
    'coupon_code' =>$rowFields['coupon_code'] ?? '',
    'coupon_value'=>(int)($rowFields['coupon_value'] ?? 0),
    'coupon_note' =>$rowFields['coupon_note'] ?? '',
    'gifts'       =>normalize_gifts($rowFields['gifts'] ?? []),
  ];
}
function overlap_query_and_compare($conn, $idExclude, $afterArr){
  $sql = "SELECT * FROM promotions 
          WHERE main_product=? 
            AND id<>?
            AND start_at <= ? 
            AND end_at   >= ?
          ORDER BY id DESC LIMIT 50";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('siss', $afterArr['main_product'], $idExclude, $afterArr['end_at'], $afterArr['start_at']);
  $stmt->execute();
  $rs = $stmt->get_result();
  $sigNew = promo_signature($afterArr);
  while($row = $rs->fetch_assoc()){
    $giftsOld = get_gifts_by_promo($conn, (int)$row['id']);
    $cand = build_promo_array(array_merge($row, ['gifts'=>$giftsOld]));
    if (promo_signature($cand) === $sigNew){
      return (int)$row['id'];
    }
  }
  return 0;
}
function find_similar_promos($conn, $mainProduct, $limit=5){
  $stmt = $conn->prepare("SELECT * FROM promotions WHERE main_product=? ORDER BY id DESC LIMIT ?");
  $stmt->bind_param('si', $mainProduct, $limit);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while($r = $res->fetch_assoc()){
    $gid = (int)$r['id'];
    $out[] = array_merge($r, ['gifts'=>get_gifts_by_promo($conn,$gid)]);
  }
  return $out;
}
function latest_dates($conn, $mainProduct=null){
  // vẫn hỗ trợ both, nhưng client sẽ LUÔN gọi bản "toàn hệ thống"
  if ($mainProduct && trim($mainProduct) !== ''){
    $stmt = $conn->prepare("SELECT start_at, end_at FROM promotions WHERE main_product=? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $mainProduct);
  } else {
    $stmt = $conn->prepare("SELECT start_at, end_at FROM promotions ORDER BY id DESC LIMIT 1");
  }
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  return $row ?: null;
}

// ===== Actions (POST) =====
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'create' || $action === 'update') {
      // Common inputs
      $mainProduct = trim($_POST['main_product'] ?? '');
      $mainQty     = max(1, (int)$_POST['main_qty'] ?? 1);
      $mainValue   = (int)($_POST['main_value'] ?? 0);

      $sa          = fromInputDT($_POST['start_at'] ?? '');
      $ea          = fromInputDT($_POST['end_at'] ?? '');
      $qty         = (int)($_POST['qty'] ?? 0);
      $hl          = isset($_POST['highlight']) ? 1 : 0;
      $type        = (int)($_POST['type'] ?? 2);
      if (!in_array($type, [1,2], true)) $type = 2;

      $note        = $_POST['note'] ?? '';

      // Coupon (optional)
      $couponType  = trim($_POST['coupon_type'] ?? '');
      $couponCode  = trim($_POST['coupon_code'] ?? '');
      $couponValue = (int)($_POST['coupon_value'] ?? 0);
      $couponNote  = trim($_POST['coupon_note'] ?? '');
      $createdBy   = $_SESSION['full_name'] ?? '';

      // Gifts (arrays)
      $gift_products = $_POST['gift_product'] ?? [];
      $gift_qtys     = $_POST['gift_qty'] ?? [];
      $gift_values   = $_POST['gift_value'] ?? [];

      $gifts = [];
      $count = max(count($gift_products), count($gift_qtys), count($gift_values));
      for ($i=0; $i<$count; $i++) {
        $gp = trim($gift_products[$i] ?? '');
        if ($gp === '') continue;
        $gq = max(1, (int)($gift_qtys[$i] ?? 1));
        $gv = (int)($gift_values[$i] ?? 0);
        $gifts[] = ['gift_product'=>$gp, 'gift_qty'=>$gq, 'gift_value'=>$gv]; // gift_value = đơn giá
      }
      $giftsNorm = normalize_gifts($gifts);

      // Legacy 1st gift
      $legacyGiftProduct = $giftsNorm[0]['gift_product'] ?? '';
      $legacyGiftQty     = $giftsNorm[0]['gift_qty'] ?? 0;
      $legacyGiftValue   = $giftsNorm[0]['gift_value'] ?? 0;

      // Chuẩn hoá "after" để kiểm tra trùng lặp
      $afterForDup = build_promo_array([
        'main_product'=>$mainProduct,'main_qty'=>$mainQty,'main_value'=>$mainValue,
        'gift_product'=>$legacyGiftProduct,'gift_qty'=>$legacyGiftQty,'gift_value'=>$legacyGiftValue,
        'start_at'=>$sa,'end_at'=>$ea,'qty'=>$qty,'highlight'=>$hl,'type'=>$type,
        'note'=>$note,'coupon_type'=>$couponType,'coupon_code'=>$couponCode,'coupon_value'=>$couponValue,'coupon_note'=>$couponNote,
        'gifts'=>$giftsNorm
      ]);
      $excludeId = ($action==='update') ? (int)($_POST['id'] ?? 0) : 0;
      $dupId = overlap_query_and_compare($conn, $excludeId, $afterForDup);
      if ($dupId > 0){
        throw new Exception('Khuyến mãi trùng lặp với bản hiện có (#'.$dupId.'). Vui lòng điều chỉnh thời gian hoặc nội dung.');
      }

      // Bắt đầu ghi DB
      $conn->begin_transaction();
      if ($action === 'create') {
        $sql = "INSERT INTO promotions
                (main_product, main_qty, main_value,
                 gift_product, gift_qty, gift_value,
                 start_at, end_at, qty, highlight, type,
                 note, coupon_type, coupon_code, coupon_value, coupon_note,
                 created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
          'siisiissiiisssiss',
          $mainProduct, $mainQty, $mainValue,
          $legacyGiftProduct, $legacyGiftQty, $legacyGiftValue,
          $sa, $ea, $qty, $hl, $type,
          $note, $couponType, $couponCode, $couponValue, $couponNote,
          $createdBy
        );
        $stmt->execute();
        $pid = $stmt->insert_id;

        if (!empty($giftsNorm)) {
          $stmtG = $conn->prepare("INSERT INTO promotion_gifts (promotion_id, gift_product, gift_qty, gift_value) VALUES (?,?,?,?)");
          foreach ($giftsNorm as $g) {
            $gp = $g['gift_product']; $gq = $g['gift_qty']; $gv = $g['gift_value'];
            $stmtG->bind_param('isii', $pid, $gp, $gq, $gv);
            $stmtG->execute();
          }
        }

        $after = $afterForDup;
        write_audit($conn, $pid, 'create', $createdBy, [], $after);
        $conn->commit();
        $_SESSION['flash'] = 'Đã thêm khuyến mãi.';

      } else { // update
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception('Thiếu ID.');

        $stmtB = $conn->prepare("SELECT * FROM promotions WHERE id=? LIMIT 1");
        $stmtB->bind_param('i', $id); $stmtB->execute();
        $beforeRow = $stmtB->get_result()->fetch_assoc();
        if (!$beforeRow) throw new Exception('Không tìm thấy khuyến mãi để cập nhật.');
        $beforeGifts = get_gifts_by_promo($conn, $id);
        $before = build_promo_array(array_merge($beforeRow, ['gifts'=>$beforeGifts]));

        $sql = "UPDATE promotions SET
                main_product=?, main_qty=?, main_value=?,
                gift_product=?, gift_qty=?, gift_value=?,
                start_at=?, end_at=?, qty=?, highlight=?, type=?,
                note=?, coupon_type=?, coupon_code=?, coupon_value=?, coupon_note=?
                WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
          'siisiissiiisssisi',
          $mainProduct, $mainQty, $mainValue,
          $legacyGiftProduct, $legacyGiftQty, $legacyGiftValue,
          $sa, $ea, $qty, $hl, $type,
          $note, $couponType, $couponCode, $couponValue, $couponNote,
          $id
        );
        $stmt->execute();

        $conn->query("DELETE FROM promotion_gifts WHERE promotion_id=".$id);
        if (!empty($giftsNorm)) {
          $stmtG = $conn->prepare("INSERT INTO promotion_gifts (promotion_id, gift_product, gift_qty, gift_value) VALUES (?,?,?,?)");
          foreach ($giftsNorm as $g) {
            $gp = $g['gift_product']; $gq = $g['gift_qty']; $gv = $g['gift_value'];
            $stmtG->bind_param('isii', $id, $gp, $gq, $gv);
            $stmtG->execute();
          }
        }

        $after = $afterForDup;
        if (diff_summary($before,$after) !== 'No changes'){ write_audit($conn, $id, 'update', $createdBy, $before, $after); }

        $conn->commit();
        $_SESSION['flash'] = 'Đã cập nhật khuyến mãi.';
      }
      header("Location: index.php"); exit();
    }

    elseif ($action === 'delete') {
      $id = (int)$_POST['id'];
      $stmtB = $conn->prepare("SELECT * FROM promotions WHERE id=? LIMIT 1");
      $stmtB->bind_param('i',$id); $stmtB->execute();
      $rowB = $stmtB->get_result()->fetch_assoc();

      $stmt = $conn->prepare("DELETE FROM promotions WHERE id=?");
      $stmt->bind_param('i',$id); $stmt->execute();

      if ($rowB){
        $before = build_promo_array(array_merge($rowB, ['gifts'=>get_gifts_by_promo($conn, $id)]));
        write_audit($conn, $id, 'delete', ($_SESSION['full_name'] ?? ''), $before, []);
      }

      $_SESSION['flash'] = 'Đã xóa khuyến mãi.';
      header("Location: index.php"); exit();

    } elseif ($action === 'toggle_highlight') {
      $stmt = $conn->prepare("UPDATE promotions SET highlight = 1 - highlight WHERE id=?");
      $id = (int)$_POST['id'];
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $_SESSION['flash'] = 'Đã đổi trạng thái nổi bật.';
      header("Location: index.php"); exit();

    } 
    // ===== AJAX tiện ích =====
    elseif ($action === 'fetch_gifts') {
      header('Content-Type: application/json; charset=utf-8');
      $pid = (int)($_POST['promotion_id'] ?? 0);
      $rowsG = [];
      if ($pid > 0) {
        $rs = $conn->prepare("SELECT gift_product, gift_qty, gift_value FROM promotion_gifts WHERE promotion_id=? ORDER BY id ASC");
        $rs->bind_param('i', $pid);
        $rs->execute();
        $res = $rs->get_result();
        while($r = $res->fetch_assoc()){ $rowsG[] = $r; }
      }
      echo json_encode(['ok'=>true, 'data'=>$rowsG], JSON_UNESCAPED_UNICODE);
      exit;

    } elseif ($action === 'fetch_audits') {
      header('Content-Type: application/json; charset=utf-8');
      $pid = (int)($_POST['promotion_id'] ?? 0);
      $rowsA = [];
      if ($pid > 0) {
        $rs = $conn->prepare("SELECT id, action, changed_by, changed_at, change_summary FROM promotion_audits WHERE promotion_id=? ORDER BY changed_at DESC, id DESC");
        $rs->bind_param('i', $pid);
        $rs->execute();
        $res = $rs->get_result();
        while($r = $res->fetch_assoc()){ $rowsA[] = $r; }
      }
      echo json_encode(['ok'=>true, 'data'=>$rowsA], JSON_UNESCAPED_UNICODE);
      exit;

    } elseif ($action === 'fetch_latest_dates') {
      header('Content-Type: application/json; charset=utf-8');
      $mp = trim($_POST['main_product'] ?? '');
      $row = latest_dates($conn, $mp!==''?$mp:null);
      echo json_encode(['ok'=>true, 'data'=>$row], JSON_UNESCAPED_UNICODE);
      exit;

    } elseif ($action === 'fetch_recent_promos') {
      header('Content-Type: application/json; charset=utf-8');
      $mp = trim($_POST['main_product'] ?? '');
      if ($mp===''){ echo json_encode(['ok'=>true,'data'=>[]]); exit; }
      $list = find_similar_promos($conn, $mp, 5);
      echo json_encode(['ok'=>true,'data'=>$list], JSON_UNESCAPED_UNICODE);
      exit;

    } elseif ($action === 'check_duplicate') {
      header('Content-Type: application/json; charset=utf-8');
      $payload = $_POST; // nhận dạng create/edit
      $idEx = (int)($payload['id'] ?? 0);

      $mainProduct = trim($payload['main_product'] ?? '');
      $mainQty     = max(1, (int)($payload['main_qty'] ?? 1));
      $mainValue   = (int)($payload['main_value'] ?? 0);
      $sa          = fromInputDT($payload['start_at'] ?? '');
      $ea          = fromInputDT($payload['end_at'] ?? '');
      $type        = (int)($payload['type'] ?? 2);
      $note        = $payload['note'] ?? '';
      $qty         = (int)($payload['qty'] ?? 0);
      $hl          = isset($payload['highlight']) ? 1 : 0;

      $couponType  = trim($payload['coupon_type'] ?? '');
      $couponCode  = trim($payload['coupon_code'] ?? '');
      $couponValue = (int)($payload['coupon_value'] ?? 0);
      $couponNote  = trim($payload['coupon_note'] ?? '');

      $gift_products = $payload['gift_product'] ?? [];
      $gift_qtys     = $payload['gift_qty'] ?? [];
      $gift_values   = $payload['gift_value'] ?? [];

      $gifts = [];
      $count = max(count($gift_products), count($gift_qtys), count($gift_values));
      for ($i=0; $i<$count; $i++) {
        $gp = trim($gift_products[$i] ?? '');
        if ($gp === '') continue;
        $gq = max(1, (int)($gift_qtys[$i] ?? 1));
        $gv = (int)($gift_values[$i] ?? 0);
        $gifts[] = ['gift_product'=>$gp, 'gift_qty'=>$gq, 'gift_value'=>$gv];
      }
      $giftsNorm = normalize_gifts($gifts);

      $after = build_promo_array([
        'main_product'=>$mainProduct,'main_qty'=>$mainQty,'main_value'=>$mainValue,
        'gift_product'=>$giftsNorm[0]['gift_product'] ?? '', 'gift_qty'=>$giftsNorm[0]['gift_qty'] ?? 0, 'gift_value'=>$giftsNorm[0]['gift_value'] ?? 0,
        'start_at'=>$sa,'end_at'=>$ea,'qty'=>$qty,'highlight'=>$hl,'type'=>$type,
        'note'=>$note,'coupon_type'=>$couponType,'coupon_code'=>$couponCode,'coupon_value'=>$couponValue,'coupon_note'=>$couponNote,
        'gifts'=>$giftsNorm
      ]);

      $dupId = overlap_query_and_compare($conn, $idEx, $after);
      echo json_encode(['ok'=>true, 'duplicate'=> ($dupId>0), 'dup_id'=>$dupId], JSON_UNESCAPED_UNICODE);
      exit;
    }

  } catch (Exception $e) {
    if ($conn->errno === 0) { /* no-op */ }
    if ($conn->in_transaction) { $conn->rollback(); }
    $_SESSION['flash_err'] = 'Lỗi: ' . $e->getMessage();
    header("Location: index.php"); exit();
  }
}

// ===== Filters & pagination (GET) =====
$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';
$q    = trim($_GET['q'] ?? '');
$onlyHL = isset($_GET['only_hl']) ? 1 : 0;
$ftype = isset($_GET['ftype']) ? (int)$_GET['ftype'] : 0;

$where = "WHERE 1=1";
$params = []; $types = '';
if ($from !== '') { $where .= " AND start_at >= ?"; $types.='s'; $params[] = fromInputDT($from.'T00:00'); }
if ($to   !== '') { $where .= " AND end_at   <= ?"; $types.='s'; $params[] = fromInputDT($to.'T23:59'); }
if ($q !== '')     { $where .= " AND (main_product LIKE ? OR gift_product LIKE ?)"; $types.='ss'; $like='%'.$q.'%'; $params[]=$like; $params[]=$like; }
if ($onlyHL)       { $where .= " AND highlight = 1"; }
if (in_array($ftype, [1,2], true)) { $where .= " AND type = ?"; $types.='i'; $params[]=$ftype; }

$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page-1)*$perPage;

// Count
$sqlCount = "SELECT COUNT(*) AS c FROM promotions $where";
$stmtC = $conn->prepare($sqlCount);
if ($types) $stmtC->bind_param($types, ...$params);
$stmtC->execute();
$total = (int)($stmtC->get_result()->fetch_assoc()['c'] ?? 0);
$totalPages = max(1, (int)ceil($total / $perPage));

// Data
$sqlData = "SELECT * FROM promotions $where ORDER BY start_at DESC, id DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sqlData);
if ($types) { $types2 = $types.'ii'; $params2 = array_merge($params, [$perPage, $offset]); $stmt->bind_param($types2, ...$params2); }
else { $stmt->bind_param('ii', $perPage, $offset); }
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Preload gifts
$ids = array_column($rows, 'id');
$giftMap = [];
if (!empty($ids)) {
  $in = implode(',', array_map('intval', $ids));
  $rs = $conn->query("SELECT promotion_id, gift_product, gift_qty, gift_value FROM promotion_gifts WHERE promotion_id IN ($in) ORDER BY id ASC");
  while($r = $rs->fetch_assoc()){ $giftMap[(int)$r['promotion_id']][] = $r; }
}
foreach ($rows as $r) {
  $pid = (int)$r['id'];
  if (empty($giftMap[$pid]) && !empty($r['gift_product'])) {
    $giftMap[$pid] = [[
      'gift_product' => $r['gift_product'],
      'gift_qty'     => (int)$r['gift_qty'],
      'gift_value'   => (int)$r['gift_value'],
    ]];
  }
}

// Nạp products + price map
$products=[]; $productMap=[];
$popularDays = isset($_GET['popular_days']) ? (int)$_GET['popular_days'] : 180;
if ($popularDays < 1 || $popularDays > 3650) $popularDays = 180;
$sinceDate = (new DateTime("-{$popularDays} days"))->format('Y-m-d 00:00:00');
try{
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $fetchTopByView=function(mysqli $conn,string $sinceDate,int $view,int $limit=5){
    $sql="SELECT p.product_name,p.price,
                 COALESCE(SUM(CASE WHEN o.created_at >= ? THEN op.quantity ELSE 0 END),0) AS sold_qty_recent,
                 COALESCE(SUM(op.quantity),0) AS sold_qty_all
          FROM products p
          LEFT JOIN order_products op ON op.product_name=p.product_name
          LEFT JOIN orders o ON o.id=op.order_id
          WHERE p.product_name<>'' AND p.view=?
          GROUP BY p.product_name,p.price
          ORDER BY sold_qty_recent DESC, sold_qty_all DESC, p.product_name ASC
          LIMIT ?";
    $stmt=$conn->prepare($sql); $stmt->bind_param('sii',$sinceDate,$view,$limit);
    $stmt->execute(); $rs=$stmt->get_result(); $rows=[];
    while($r=$rs->fetch_assoc()){ $rows[]=$r; } $stmt->close(); return $rows;
  };
  foreach ([['view'=>1,'limit'=>100],['view'=>3,'limit'=>20],['view'=>4,'limit'=>20],['view'=>2,'limit'=>50]] as $vp){
    $group=$fetchTopByView($conn,$sinceDate,(int)$vp['view'],(int)$vp['limit']);
    foreach ($group as $r){
      $name=trim((string)$r['product_name']); if($name==='') continue;
      $price=(int)$r['price'];
      $products[]=['product_name'=>$name,'price'=>$price,'sold_qty_recent'=>(int)$r['sold_qty_recent'],'sold_qty_all'=>(int)$r['sold_qty_all']];
      if(!array_key_exists($name,$productMap)){ $productMap[$name]=$price; }
    }
  }
}catch(Throwable $e){ try{
  foreach ([1,3,4,2] as $v){
    $rs=$conn->query("SELECT product_name,price FROM products WHERE product_name<>'' AND view={$v} ORDER BY product_name ASC LIMIT 5");
    while($r=$rs->fetch_assoc()){
      $name=trim((string)$r['product_name']); if($name==='') continue; $price=(int)$r['price'];
      $products[]=['product_name'=>$name,'price'=>$price,'sold_qty_recent'=>0,'sold_qty_all'=>0];
      if(!array_key_exists($name,$productMap)){ $productMap[$name]=$price; }
    }
  }
} catch(Throwable $e2){} }

// Header chiến dịch (optional)
$hdr = '';
if ($from && $to) {
  $hdr = sprintf('KM %sh %s đến %sh ngày %s',
    date('H', strtotime($from.' 05:00')), date('d/m', strtotime($from)),
    date('H', strtotime($to.' 24:00')),  date('d/m', strtotime($to))
  );
}

// ===== Cảnh báo thay đổi 7 ngày gần đây =====
$recentChanges=[]; $badgeIds=[];
try{
  $rs = $conn->query("
    SELECT pa.*, p.main_product
    FROM promotion_audits pa
    LEFT JOIN promotions p ON p.id = pa.promotion_id
    WHERE pa.changed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY pa.changed_at DESC, pa.id DESC
    LIMIT 200
  ");
  while($r=$rs->fetch_assoc()){
    $recentChanges[]=$r;
    if ($r['action']==='update'){ $badgeIds[(int)$r['promotion_id']]=true; }
  }
}catch(Exception $e){}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Quản lý Khuyến mãi</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 4 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.css">
  <style>
    body{background:#f8f9fa}
    .table-km th, .table-km td{vertical-align:middle}
    .row-highlight{background:#CCFFFF !important;color:#000}
    .row-highlight a.btn{color:#000}
    .header-title{color:#c00;font-weight:700;text-transform:uppercase}
    .shadow-card{border:none;border-radius:.5rem;box-shadow:0 1px 10px rgba(0,0,0,.08)}
    .small-hint{font-size:12px;color:#6c757d}
    .gift-chip{display:inline-block;padding:2px 6px;border-radius:12px;background:#eef;border:1px solid #dde;margin:2px 2px}
    .muted{color:#6c757d}
    .btn-ghost{background:#fff;border:1px dashed #bbb}
    .totals{font-weight:600}
    .coupon-badge{display:inline-block;padding:2px 8px;border-radius:12px;background:#ffe;border:1px solid #eed;margin:2px 2px}
    .badge-recent{background:#ffc107;color:#222;font-weight:600}
    .audit-list{max-height:260px;overflow:auto}
    .prewrap{white-space:pre-wrap}
    .table-km .group-header td {
      background:#f1f7ff;border-top:2px solid #2f6fed;border-bottom:1px solid #dbe7ff;
      font-weight:700;text-align:center;vertical-align:middle;text-transform:uppercase;
    }
    .table-km .group-gap td { padding: 6px 0; background: transparent; border: 0; }
    .badge-range{display:inline-block;font-size:.75rem;background:#2f6fed;color:#fff;padding:2px 8px;border-radius:999px;margin-right:.5rem;font-weight:700}

    /* ===== Modal gần full-screen + footer dính ===== */
    .modal-xl{ max-width: 95% !important; }
    .modal-dialog.modal-xl .modal-content{ max-height: calc(100vh - 32px); }
    .modal-dialog.modal-xl.modal-dialog-scrollable .modal-body{ max-height: calc(100vh - 180px); overflow-y:auto; }
    .modal-dialog .modal-footer{ position: sticky; bottom: 0; background: #fff; z-index: 5; }

    /* Phụ: tổng từng dòng quà */
    .gift-line-total{font-size:12px;color:#6c757d}
    /* Khung gợi ý gần giống */
    .similar-box{background:#f8fbff;border:1px dashed #cfe2ff;padding:8px;border-radius:8px}
  </style>
</head>
<body>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="fas fa-tags mr-2 text-danger"></i>Quản lý Khuyến mãi</h4>
    <div>
      <a href="https://kuchenvietnam.vn/kuchen/khokuchen/" class="btn btn-outline-secondary btn-sm"><i class="fas fa-home"></i> Trang chủ</a>
      <a href="logout.php" class="btn btn-danger btn-sm"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
    </div>
  </div>

  <!-- CẢNH BÁO thay đổi 7 ngày -->
  <?php if(!empty($recentChanges)): ?>
    <div id="recentAlert" class="card shadow-card mb-3 border-warning">
      <div class="card-header bg-warning font-weight-bold">
        <i class="fas fa-bell"></i> Cảnh báo thay đổi 7 ngày gần đây
      </div>
      <div class="card-body p-2">
        <div class="audit-list">
          <?php foreach($recentChanges as $ch): ?>
            <div class="px-2 py-1 border-bottom">
              <div class="small">
                <b>#<?= (int)$ch['promotion_id'] ?></b> — <?= esc($ch['main_product'] ?? '(đã xóa)') ?>,
                <span class="badge badge-light"><?= esc($ch['action']) ?></span>,
                bởi <b><?= esc($ch['changed_by']) ?></b> lúc <?= date('d/m/Y H:i', strtotime($ch['changed_at'])) ?>
              </div>
              <?php if(!empty($ch['change_summary'])): ?>
                <div class="small text-muted prewrap"><?= esc($ch['change_summary']) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="small-hint mt-2">Chỉ hiển thị tối đa 7 ngày gần nhất.</div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Bộ lọc -->
  <div class="card shadow-card mb-3">
    <div class="card-body">
      <form class="form-inline" method="get">
        <label class="mr-2 mb-2">Từ ngày</label>
        <input type="date" class="form-control mr-3 mb-2" name="from" value="<?= esc($from) ?>">
        <label class="mr-2 mb-2">Đến ngày</label>
        <input type="date" class="form-control mr-3 mb-2" name="to" value="<?= esc($to) ?>">
        <input type="text" class="form-control mr-3 mb-2" name="q" placeholder="Tìm SP chính / quà tặng" style="min-width:280px" value="<?= esc($q) ?>">

        <div class="form-check mr-3 mb-2">
          <input class="form-check-input" type="checkbox" id="only_hl" name="only_hl" <?= $onlyHL?'checked':'' ?>>
          <label class="form-check-label" for="only_hl">Chỉ hàng nổi bật</label>
        </div>

        <label class="mr-2 mb-2">Kênh</label>
        <select name="ftype" class="form-control mr-3 mb-2" style="min-width:160px">
          <option value="0" <?= $ftype===0?'selected':'' ?>>Tất cả</option>
          <option value="1" <?= $ftype===1?'selected':'' ?>>Droppii</option>
          <option value="2" <?= $ftype===2?'selected':'' ?>>Shopee</option>
        </select>

        <button class="btn btn-primary mb-2"><i class="fas fa-search"></i> Lọc</button>
        <a class="btn btn-outline-secondary ml-2 mb-2" href="index.php"><i class="fas fa-undo"></i> Xóa lọc</a>
        <button type="button" class="btn btn-success ml-auto mb-2" data-toggle="modal" data-target="#createModal"><i class="fas fa-plus"></i> Thêm khuyến mãi</button>
      </form>
      <?php if($hdr): ?><div class="mt-2 header-title text-center"><?= esc($hdr) ?></div><?php endif; ?>
    </div>
  </div>

  <!-- Flash -->
  <?php if(!empty($_SESSION['flash'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <?= esc($_SESSION['flash']); unset($_SESSION['flash']); ?>
      <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
  <?php endif; ?>
  <?php if(!empty($_SESSION['flash_err'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <?= esc($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?>
      <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
  <?php endif; ?>

  <!-- Bảng -->
  <div class="card shadow-card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-bordered table-striped mb-0 table-km">
          <thead class="thead-light text-center">
            <tr>
              <th>STT</th>
              <th style="min-width:240px">SẢN PHẨM CHÍNH</th>
              <th>GIÁ TRỊ SP</th>
              <th style="min-width:340px">QUÀ TẶNG</th>
              <th style="min-width:140px">TỪ NGÀY</th>
              <th style="min-width:140px">ĐẾN NGÀY</th>
              <th>SL KM</th>
              <th style="min-width:220px">Hành động</th>
            </tr>
          </thead>
          <tbody>
<?php if($rows):
    $stt = ($page - 1) * $perPage + 1;
    $formatVN = function($s){ return $s ? date('d/m/Y H:i', strtotime($s)) : ''; };
    $lastRange = null;
    foreach($rows as $r):
        $pid   = (int)$r['id'];
        $gifts = $giftMap[$pid] ?? [];
        $giftTotalValue=0; $giftTotalQty=0;
        foreach ($gifts as $g){ $giftTotalValue += ((int)$g['gift_qty'])*((int)$g['gift_value']); $giftTotalQty += (int)$g['gift_qty']; }
        $recentBadge = !empty($badgeIds[$pid]);
        $rangeKey = ($r['start_at'] ?? '') . '|' . ($r['end_at'] ?? '');
        if ($rangeKey !== $lastRange):
            $nhanTu  = $formatVN($r['start_at']); $nhanDen = $formatVN($r['end_at']);
            if ($lastRange !== null): ?>
              <tr class="group-gap"><td colspan="8"></td></tr>
            <?php endif; ?>
            <tr class="group-header">
              <td colspan="8">
                <div class="d-flex justify-content-between align-items-center">
                  <div>
                    <span class="badge badge-range">Khoảng ngày</span>
                    <strong>TỪ:</strong> <?= $nhanTu ?> &nbsp; — &nbsp; <strong>ĐẾN:</strong> <?= $nhanDen ?>
                  </div>
                </div>
              </td>
            </tr>
        <?php $lastRange = $rangeKey; endif;
        $badgeClass = typeBadgeClass($r['type'] ?? 0); $badgeText  = typeLabel($r['type'] ?? 0);
        ?>
        <tr class="<?= $r['highlight'] ? 'row-highlight' : '' ?>">
          <td class="text-center"><?= $stt++ ?></td>
          <td>
            <div class="font-weight-bold d-flex align-items-center">
              <span><?= esc($r['main_product']) ?></span>
              <span class="badge <?= $badgeClass ?> ml-2" title="Kênh"><?= esc($badgeText) ?></span>
              <?php if($recentBadge): ?><span class="badge badge-recent ml-1">Mới</span><?php endif; ?>
            </div>
            <?php if($r['note']): ?><small class="d-block" style="color:blue;"><?= esc($r['note']) ?></small><?php endif; ?>
            <small class="muted d-block" style="color:red;">Mua <?= (int)($r['main_qty'] ?? 1) ?> tặng <?= (int)$giftTotalQty ?></small>

            <?php if(!empty($r['coupon_type']) && !empty($r['coupon_code'])): ?>
              <div class="coupon-badge">
                <?php if($r['coupon_type']==='shipping'): ?>
                  Mã <b>freeship</b>: <?= esc($r['coupon_code']) ?> <?php if((int)$r['coupon_value']>0): ?> — tối đa <?= vnd($r['coupon_value']) ?>đ<?php endif; ?>
                <?php else: ?>
                  Mã <b>giảm giá</b>: <?= esc($r['coupon_code']) ?> <?php if((int)$r['coupon_value']>0): ?> — <?= vnd($r['coupon_value']) ?>đ<?php endif; ?>
                <?php endif; ?>
                <?php if(!empty($r['coupon_note'])): ?> — <i><?= esc($r['coupon_note']) ?></i><?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
          <td class="text-right"><?= vnd($r['main_value']) ?></td>
          <td>
            <?php if(!empty($gifts)): ?>
              <?php foreach($gifts as $g): ?>
                <span class="gift-chip">
                  <?= esc($g['gift_product']) ?> &times; <?= (int)$g['gift_qty'] ?>
                  <?php if((int)$g['gift_value']>0): ?> — <?= vnd($g['gift_value']) ?>đ<?php endif; ?>
                </span>
              <?php endforeach; ?>
              <div class="mt-1 totals">Tổng quà: <?= vnd($giftTotalValue) ?>đ</div>
            <?php else: ?><span class="text-muted">Không có quà tặng</span><?php endif; ?>
          </td>
          <td class="text-center"><?= $formatVN($r['start_at']) ?></td>
          <td class="text-center"><?= $formatVN($r['end_at']) ?></td>
          <td class="text-center"><?= (int)$r['qty'] ?></td>
          <td class="text-center">
            <button class="btn btn-sm btn-outline-primary" title="Sửa"
                    data-toggle="modal" data-target="#editModal"
                    data-json='<?= esc(json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'>
              <i class="fas fa-edit"></i>
            </button>
            <button class="btn btn-sm btn-outline-secondary" title="Lịch sử"
                    data-toggle="modal" data-target="#auditModal" data-pid="<?= $pid ?>">
              <i class="fas fa-history"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger" title="Tô màu"
                    onclick="postAction('toggle_highlight', {id:<?= $pid ?>})">
              <i class="fas fa-highlighter"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger" title="Xoá"
                    onclick="if(confirm('Xoá khuyến mãi này?')) postAction('delete', {id:<?= $pid ?>});">
              <i class="fas fa-trash"></i>
            </button>
          </td>
        </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="8" class="text-center text-muted">Chưa có khuyến mãi.</td></tr>
<?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php if($totalPages>1): ?>
      <div class="card-footer bg-white">
        <nav aria-label="page">
          <ul class="pagination justify-content-center mb-0">
            <?php
              $qs = $_GET; $prev = max(1,$page-1); $next = min($totalPages,$page+1);
              $mk = function($p) use ($qs){ $qs['page']=$p; return 'index.php?'.http_build_query($qs); };
            ?>
            <li class="page-item <?= $page==1?'disabled':'' ?>"><a class="page-link" href="<?= esc($mk(1)) ?>">Trang đầu</a></li>
            <li class="page-item <?= $page==1?'disabled':'' ?>"><a class="page-link" href="<?= esc($mk($prev)) ?>">Trước</a></li>
            <li class="page-item disabled"><span class="page-link"><?= $page ?>/<?= $totalPages ?></span></li>
            <li class="page-item <?= $page==$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= esc($mk($next)) ?>">Sau</a></li>
            <li class="page-item <?= $page==$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= esc($mk($totalPages)) ?>">Trang cuối</a></li>
          </ul>
        </nav>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- datalist sản phẩm -->
<datalist id="productsList">
  <?php foreach ($products as $p): ?>
    <option value="<?= esc($p['product_name']) ?>"></option>
  <?php endforeach; ?>
</datalist>

<!-- Modal: Create -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
    <form method="post" id="formCreate">
      <input type="hidden" name="action" value="create">
      <div class="modal-header">
        <h5 class="modal-title">Thêm khuyến mãi</h5>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Sản phẩm chính</label>
            <div class="input-group">
              <input name="main_product" id="create_main_product" class="form-control" list="productsList" placeholder="Gõ tên để chọn…" required>
              <div class="input-group-append"><button class="btn btn-outline-secondary" type="button" id="btnFillMainPrice">Lấy giá</button></div>
            </div>
            <small class="text-muted d-block">Chọn SP → tự lấy đơn giá; đổi SL thì giá trị cập nhật.</small>
          </div>
          <div class="form-group col-md-3">
            <label>SL mua</label>
            <input name="main_qty" id="create_main_qty" type="number" min="1" value="1" class="form-control" required>
          </div>
          <div class="form-group col-md-3">
            <label>Giá trị SP (VND)</label>
            <input name="main_value" id="create_main_value" type="number" min="0" class="form-control" required>
          </div>
           
          <div class="col-12"><hr><strong>Quà tặng</strong> <small class="text-muted">(có thể thêm nhiều dòng; tự điền giá theo sản phẩm)</small></div>
          <div class="col-12" id="create_gifts_wrap"></div>
          <!-- Gợi ý gần giống -->
          <div class="col-12 mt-2">
            <div id="create_similar" class="similar-box d-none">
              <div class="font-weight-bold mb-1"><i class="fas fa-magic"></i> Gợi ý combo gần giống</div>
              <div id="create_similar_list" class="small"></div>
            </div>
          </div>
          <div class="col-12 mt-1">
            <span class="totals">Tổng giá trị quà: <span id="create_total_gift_vnd">0</span> đ</span>
          </div>
          <div class="col-12 mt-2">
            <button type="button" class="btn btn-ghost btn-sm" id="btnCreateAddGift"><i class="fas fa-plus"></i> Thêm quà</button>
          </div>

          <div class="form-group col-md-6 mt-3">
            <label>Từ ngày (ngày & giờ)</label>
            <input name="start_at" id="create_start_at" type="datetime-local" class="form-control" required>
          </div>
          <div class="form-group col-md-6 mt-3">
            <label>Đến ngày (ngày & giờ)</label>
            <input name="end_at" id="create_end_at" type="datetime-local" class="form-control" required>
          </div>

          <!-- Kênh + Số suất cùng hàng -->
          <div class="form-group col-md-4">
            <label>Kênh hiển thị</label>
            <select name="type" id="create_type" class="form-control" required>
              <option value="2" selected>Shopee</option>
              <option value="1">Droppii</option>
            </select>
          </div>
          <div class="form-group col-md-4">
            <label>Số suất khuyến mãi</label>
            <input name="qty" id="create_qty" type="number" min="0" step="10" value="50" class="form-control" required>
            <small class="text-muted">Mặc định 50, mỗi lần tăng +10.</small>
          </div>
          <div class="form-group col-md-4">
            <label>Ghi chú</label>
            <input name="note" id="create_note" class="form-control" placeholder="VD: chỉ áp dụng online/...">
          </div>

          <!-- Coupon -->
          <div class="col-12"><hr><strong>Mã ưu đãi (tuỳ chọn)</strong></div>
          <div class="form-group col-md-3">
            <label>Loại mã</label>
            <select name="coupon_type" id="create_coupon_type" class="form-control">
              <option value="">Không dùng</option>
              <option value="discount">Mã giảm giá</option>
              <option value="shipping">Mã giảm vận chuyển</option>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label>Mã</label>
            <input name="coupon_code" id="create_coupon_code" class="form-control" placeholder="VD: GIAM50K">
          </div>
          <div class="form-group col-md-3">
            <label>Giá trị (VND)</label>
            <input name="coupon_value" id="create_coupon_value" type="number" min="0" class="form-control" placeholder="0 = không cố định">
          </div>
          <div class="form-group col-md-3">
            <label>Ghi chú mã</label>
            <input name="coupon_note" id="create_coupon_note" class="form-control" placeholder="VD: Tối đa 30k...">
          </div>

          <div class="form-group col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="hlCreate" name="highlight">
              <label for="hlCreate" class="form-check-label">Đánh dấu nổi bật</label>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary"><i class="fas fa-save"></i> Lưu</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Đóng</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Modal: Edit -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
    <form method="post" id="formEdit">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="edit_id">
      <div class="modal-header">
        <h5 class="modal-title">Sửa khuyến mãi</h5>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Sản phẩm chính</label>
            <div class="input-group">
              <input name="main_product" id="edit_main_product" class="form-control" list="productsList" required>
              <div class="input-group-append"><button class="btn btn-outline-secondary" type="button" id="btnEditFillMainPrice">Lấy giá</button></div>
            </div>
            <small class="text-muted d-block">Chọn SP → tự lấy đơn giá; đổi SL thì giá trị cập nhật.</small>
          </div>
          <div class="form-group col-md-3">
            <label>SL mua</label>
            <input name="main_qty" id="edit_main_qty" type="number" min="1" class="form-control" required>
          </div>
          <div class="form-group col-md-3">
            <label>Giá trị SP (VND)</label>
            <input name="main_value" id="edit_main_value" type="number" min="0" class="form-control" required>
          </div>

          <div class="col-12"><hr><strong>Quà tặng</strong></div>
          <div class="col-12" id="edit_gifts_wrap"></div>
          <div class="col-12 mt-1">
            <span class="totals">Tổng giá trị quà: <span id="edit_total_gift_vnd">0</span> đ</span>
          </div>
          <div class="col-12 mt-2">
            <button type="button" class="btn btn-ghost btn-sm" id="btnEditAddGift"><i class="fas fa-plus"></i> Thêm quà</button>
          </div>

          <div class="form-group col-md-6 mt-3">
            <label>Từ ngày (ngày & giờ)</label>
            <input name="start_at" id="edit_start_at" type="datetime-local" class="form-control" required>
          </div>
          <div class="form-group col-md-6 mt-3">
            <label>Đến ngày (ngày & giờ)</label>
            <input name="end_at" id="edit_end_at" type="datetime-local" class="form-control" required>
          </div>

          <!-- Kênh + Số suất cùng hàng -->
          <div class="form-group col-md-4">
            <label>Kênh hiển thị</label>
            <select name="type" id="edit_type" class="form-control" required>
              <option value="2">Shopee</option>
              <option value="1">Droppii</option>
            </select>
          </div>
          <div class="form-group col-md-4">
            <label>Số suất khuyến mãi</label>
            <input name="qty" id="edit_qty" type="number" min="0" step="10" class="form-control" required>
            <small class="text-muted">Mỗi lần tăng +10.</small>
          </div>
          <div class="form-group col-md-4">
            <label>Ghi chú</label>
            <input name="note" id="edit_note" class="form-control">
          </div>

          <!-- Gợi ý gần giống -->
          <div class="col-12 mt-2">
            <div id="edit_similar" class="similar-box d-none">
              <div class="font-weight-bold mb-1"><i class="fas fa-magic"></i> Gợi ý combo gần giống</div>
              <div id="edit_similar_list" class="small"></div>
            </div>
          </div>

          <!-- Coupon -->
          <div class="col-12"><hr><strong>Mã ưu đãi (tuỳ chọn)</strong></div>
          <div class="form-group col-md-3">
            <label>Loại mã</label>
            <select name="coupon_type" id="edit_coupon_type" class="form-control">
              <option value="">Không dùng</option>
              <option value="discount">Mã giảm giá</option>
              <option value="shipping">Mã giảm vận chuyển</option>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label>Mã</label>
            <input name="coupon_code" id="edit_coupon_code" class="form-control">
          </div>
          <div class="form-group col-md-3">
            <label>Giá trị (VND)</label>
            <input name="coupon_value" id="edit_coupon_value" type="number" min="0" class="form-control">
          </div>
          <div class="form-group col-md-3">
            <label>Ghi chú mã</label>
            <input name="coupon_note" id="edit_coupon_note" class="form-control">
          </div>

          <div class="form-group col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="edit_highlight" name="highlight">
              <label for="edit_highlight" class="form-check-label">Đánh dấu nổi bật</label>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary"><i class="fas fa-save"></i> Lưu</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Đóng</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Modal: Audit History -->
<div class="modal fade" id="auditModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Lịch sử thay đổi khuyến mãi</h5>
      <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
    </div>
    <div class="modal-body">
      <div id="auditList" class="audit-list"></div>
      <div class="small-hint mt-2">Lưu trữ không giới hạn; cảnh báo nổi bật hiển thị 7 ngày gần nhất.</div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-dismiss="modal">Đóng</button>
    </div>
  </div></div>
</div>

<form id="postActionForm" method="post" class="d-none">
  <input type="hidden" name="action" id="pa_action">
  <input type="hidden" name="id" id="pa_id">
</form>

<!-- jQuery phải trước Bootstrap -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ===== datalist price map =====
const PRODUCT_MAP = <?php echo json_encode($productMap, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
function hasPrice(name){ return name && Object.prototype.hasOwnProperty.call(PRODUCT_MAP, name); }
function getPrice(name){ return hasPrice(name) ? Number(PRODUCT_MAP[name]||0) : 0; }
function fmtVND(n){ n = (+n)||0; return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }

// ===== generic form post =====
function postAction(act, data){
  const f = document.getElementById('postActionForm');
  document.getElementById('pa_action').value = act;
  document.getElementById('pa_id').value = (data && data.id !== undefined) ? data.id : '';
  f.submit();
}

// ===== Gift row template (gift_value = đơn giá). Có hiển thị tổng dòng =====
function giftRow(prefix, idx, values){
  const val = Object.assign({gift_product:'', gift_qty:1, gift_value:0}, values||{});
  return `
  <div class="form-row gift-row" data-idx="${idx}">
    <div class="form-group col-md-6">
      <label>Quà tặng</label>
      <div class="input-group">
        <input name="gift_product[]" class="form-control ${prefix}_gift_product" list="productsList" value="${$('<div>').text(val.gift_product).html()}" placeholder="Gõ tên để chọn…">
        <div class="input-group-append">
          <button class="btn btn-outline-secondary btnGiftGetPrice" type="button">Lấy giá</button>
        </div>
      </div>
      <div class="gift-line-total"></div>
    </div>
    <div class="form-group col-md-2">
      <label>SL</label>
      <input name="gift_qty[]" type="number" min="1" class="form-control ${prefix}_gift_qty" value="${val.gift_qty}">
    </div>
    <div class="form-group col-md-3">
      <label>Giá trị (VND) / 1 SP</label>
      <input name="gift_value[]" type="number" min="0" class="form-control ${prefix}_gift_value" value="${val.gift_value}">
    </div>
    <div class="form-group col-md-1 d-flex align-items-end">
      <button type="button" class="btn btn-outline-danger btn-sm btnRemoveGift"><i class="fas fa-times"></i></button>
    </div>
  </div>`;
}

// ===== Tổng quà + tổng từng dòng (UI) =====
function bindGiftTotals(prefix){
  const $wrap = (prefix==='create') ? $('#create_gifts_wrap') : $('#edit_gifts_wrap');
  const $total = (prefix==='create') ? $('#create_total_gift_vnd') : $('#edit_total_gift_vnd');

  function recalc(){
    let sum = 0;
    $wrap.find('.gift-row').each(function(){
      const $r = $(this);
      const qty = parseInt($r.find(`.${prefix}_gift_qty`).val(),10) || 0;
      const unit = parseInt($r.find(`.${prefix}_gift_value`).val(),10) || 0;
      const line = qty * unit;
      sum += line;
      $r.find('.gift-line-total').text(qty>0 && unit>0 ? `Thành tiền: ${fmtVND(line)} đ` : '');
    });
    $total.text(fmtVND(sum));
  }

  // Auto lấy giá khi chọn sản phẩm quà
  $wrap.off('change.gift blur.gift', `.${prefix}_gift_product`).on('change.gift blur.gift', `.${prefix}_gift_product`, function(){
    const $row = $(this).closest('.gift-row');
    const name = $(this).val().trim();
    if (hasPrice(name)){
      $row.find(`.${prefix}_gift_value`).val(getPrice(name)); // đơn giá
      recalc();
    }
  });

  // Nút "Lấy giá"
  $wrap.off('click', '.btnGiftGetPrice').on('click', '.btnGiftGetPrice', function(){
    const $row = $(this).closest('.gift-row');
    const name = $row.find(`.${prefix}_gift_product`).val().trim();
    if (hasPrice(name)){
      $row.find(`.${prefix}_gift_value`).val(getPrice(name));
      recalc();
    }
  });

  // Input thay đổi → tính lại tổng
  $wrap.off('input.gift', '.gift-row input').on('input.gift', '.gift-row input', recalc);

  // Xóa dòng
  $wrap.off('click', '.btnRemoveGift').on('click', '.btnRemoveGift', function(){
    $(this).closest('.gift-row').remove(); recalc();
  });

  return { recalc };
}

// ===== Đổi SQL datetime -> input[type=datetime-local] value =====
function dtLocalFromSQL(sql){
  if(!sql) return '';
  const d = new Date(sql.replace(' ','T'));
  if (isNaN(d.getTime())) return '';
  const pad = n => String(n).padStart(2,'0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
function setDates(prefix, startSQL, endSQL){
  const $s = (prefix==='create') ? $('#create_start_at') : $('#edit_start_at');
  const $e = (prefix==='create') ? $('#create_end_at')   : $('#edit_end_at');
  const s = dtLocalFromSQL(startSQL), e = dtLocalFromSQL(endSQL);
  if (s) $s.val(s);
  if (e) $e.val(e);
}

// ===== Lấy mốc thời gian MỚI NHẤT TOÀN HỆ THỐNG và đặt cho form (KHÔNG phụ thuộc SP) =====
function setDefaultTimesFromLatest(prefix){
  $.post('', {action:'fetch_latest_dates', main_product: ''}, function(resp){
    if (resp && resp.ok && resp.data){
      setDates(prefix, resp.data.start_at, resp.data.end_at);
    }
  }, 'json');
}

// ===== Gợi ý combo gần giống (render + apply, auto-ẩn sau 5s nếu không tương tác) =====
function renderSimilarBox(prefix, list){
  const $box  = (prefix==='create') ? $('#create_similar') : $('#edit_similar');
  const $list = (prefix==='create') ? $('#create_similar_list') : $('#edit_similar_list');

  // Dọn box nếu không có data
  if (!list || !list.length){
    $box.addClass('d-none'); 
    $list.html('');
    const t = $box.data('simTimer'); if (t) clearTimeout(t);
    return;
  }

  const html = list.map(function(p){
    const gifts = (p.gifts||[]).map(g => `${g.gift_product} × ${g.gift_qty}${g.gift_value>0?(' — '+fmtVND(g.gift_value)+'đ'):''}`).join('; ');
    const coupon = p.coupon_type && p.coupon_code ? 
      (p.coupon_type==='shipping' ? `Freeship: ${p.coupon_code}${p.coupon_value>0?(' — tối đa '+fmtVND(p.coupon_value)+'đ'):''}` :
                                    `Giảm giá: ${p.coupon_code}${p.coupon_value>0?(' — '+fmtVND(p.coupon_value)+'đ'):''}`) : '';
    return `
      <div class="p-2 border rounded mb-2">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div><b>${$('<div>').text(p.main_product).html()}</b> — SL mua ${p.main_qty}, Giá trị: ${fmtVND(p.main_value)}đ</div>
            ${gifts?`<div class="text-muted small">Quà: ${$('<div>').text(gifts).html()}</div>`:''}
            ${coupon?`<div class="text-muted small">Mã: ${$('<div>').text(coupon).html()}</div>`:''}
            <div class="text-muted small">Thời gian: ${p.start_at} → ${p.end_at}</div>
          </div>
          <div>
            <button type="button" class="btn btn-sm btn-outline-primary btnApplySimilar" data-item='${JSON.stringify(p)}'>
              Dùng lại
            </button>
          </div>
        </div>
      </div>`;
  }).join('');

  $list.html(html);
  $box.removeClass('d-none');

  // Auto-hide sau 5 giây nếu không tương tác
  const old = $box.data('simTimer'); if (old) clearTimeout(old);
  const scheduleHide = ()=> {
    const tid = setTimeout(()=>{ $box.addClass('d-none'); }, 5000);
    $box.data('simTimer', tid);
  };
  scheduleHide();
  $box.off('.simint')
    .on('mouseenter.simint focusin.simint touchstart.simint mousemove.simint click.simint', function(){
      const t = $box.data('simTimer'); if (t) clearTimeout(t);
    })
    .on('mouseleave.simint focusout.simint', function(){
      scheduleHide();
    });

  // Áp dụng combo khi click — KHÔNG đổi ngày hiện tại
  $list.find('.btnApplySimilar').off('click').on('click', function(){
    const p = $(this).data('item');

    const fillMain = (pre)=>{
      const prefix = pre;
      const $mp = (pre==='create') ? $('#create_main_product') : $('#edit_main_product');
      const $mq = (pre==='create') ? $('#create_main_qty') : $('#edit_main_qty');
      const $mv = (pre==='create') ? $('#create_main_value') : $('#edit_main_value');
      const $tp = (pre==='create') ? $('#create_type') : $('#edit_type');
      const $nt = (pre==='create') ? $('#create_note') : $('#edit_note');
      const $ct = (pre==='create') ? $('#create_coupon_type') : $('#edit_coupon_type');
      const $cc = (pre==='create') ? $('#create_coupon_code') : $('#edit_coupon_code');
      const $cv = (pre==='create') ? $('#create_coupon_value') : $('#edit_coupon_value');
      const $cn = (pre==='create') ? $('#create_coupon_note') : $('#edit_coupon_note');
      const $wrap = (pre==='create') ? $('#create_gifts_wrap') : $('#edit_gifts_wrap');

      $mp.val(p.main_product).data('unit', p.main_qty>0 ? (p.main_value/p.main_qty) : 0);
      $mq.val(p.main_qty || 1).trigger('change');
      $mv.val(p.main_value || 0);
      $tp.val(p.type || 2);
      $nt.val(p.note || '');
      $ct.val(p.coupon_type || '');
      $cc.val(p.coupon_code || '');
      $cv.val(p.coupon_value || 0);
      $cn.val(p.coupon_note || '');

      $wrap.empty();
      (p.gifts||[{gift_product:'',gift_qty:1,gift_value:0}]).forEach((g,i)=> $wrap.append(giftRow(prefix, i, g)));
      if (prefix==='create' && window.createTotal){ createTotal.recalc(); }
      if (prefix==='edit' && window.editTotal){ editTotal.recalc(); }
    };

    fillMain(prefix);
  });
}

function fetchSimilar(prefix, mainProduct){
  $.post('', {action:'fetch_recent_promos', main_product: mainProduct}, function(resp){
    if (resp && resp.ok){ renderSimilarBox(prefix, resp.data||[]); }
  }, 'json');
}

// ===== Auto-pricing cho SP chính: main_value = đơn giá * SL =====
// (KHÔNG tự đổi ngày theo sản phẩm; ngày đã set theo bản mới nhất toàn hệ thống và giữ nguyên)
function setupMainAutoPricing(prefix){
  const $name = (prefix==='create') ? $('#create_main_product') : $('#edit_main_product');
  const $qty  = (prefix==='create') ? $('#create_main_qty')    : $('#edit_main_qty');
  const $val  = (prefix==='create') ? $('#create_main_value')  : $('#edit_main_value');
  const $btn  = (prefix==='create') ? $('#btnFillMainPrice')   : $('#btnEditFillMainPrice');

  function recomputeFromMap(){
    const n = $name.val().trim();
    const q = parseInt($qty.val(),10) || 1;
    if (hasPrice(n)){
      const unit = getPrice(n);
      $name.data('unit', unit);
      $val.val(unit * q);
    }
    // Chỉ gợi ý combo gần giống; KHÔNG thay đổi ngày
    if (n){ fetchSimilar(prefix, n); }
  }
  function onQtyChange(){
    const unit = Number($name.data('unit') || 0);
    const q = parseInt($qty.val(),10) || 1;
    if (unit>0){ $val.val(unit * q); }
    else { recomputeFromMap(); }
  }

  // Auto-focus tên SP khi mở modal
  setTimeout(()=>{ $name.trigger('focus'); }, 150);

  // Bind
  $name.off('change blur').on('change blur', recomputeFromMap);
  $btn .off('click').on('click', recomputeFromMap);
  $qty .off('input change').on('input change', onQtyChange);
}

// ===== Validate trùng lặp (client) trước khi submit =====
function bindPreSubmitCheck(formId){
  const $form = $(formId);
  $form.off('submit.dup').on('submit.dup', function(e){
    e.preventDefault();
    const formData = $form.serializeArray();
    const payload = {};
    formData.forEach(i => {
      if (payload[i.name] !== undefined){
        if (!Array.isArray(payload[i.name])) payload[i.name] = [payload[i.name]];
        payload[i.name].push(i.value);
      } else {
        payload[i.name] = i.value;
      }
    });
    payload['action'] = 'check_duplicate';
    $.post('', payload, function(resp){
      if (resp && resp.ok && resp.duplicate){
        alert('Khuyến mãi trùng lặp với bản hiện có (#'+resp.dup_id+'). Vui lòng điều chỉnh!');
      } else {
        // Không trùng → submit thật
        $form.off('submit.dup');
        $form.trigger('submit');
      }
    }, 'json');
  });
}

// ===== Create modal behaviour =====
$('#createModal').on('shown.bs.modal', function(){
  const $wrap = $('#create_gifts_wrap');
  $wrap.empty().append(giftRow('create', 0, {}));
  $wrap.find('.create_gift_product').first().trigger('focus');

  // Ngày mặc định = KM mới nhất toàn hệ thống
  setDefaultTimesFromLatest('create');
  // default type Shopee; qty mặc định 50
  $('#create_qty').val(50);

  // Thêm dòng quà
  $('#btnCreateAddGift').off('click').on('click', function(){
    const idx = $wrap.children('.gift-row').length;
    $wrap.append(giftRow('create', idx, {}));
    setTimeout(()=>{ $wrap.find('.create_gift_product').last().trigger('focus'); }, 50);
    createTotal.recalc();
  });

  // Auto-fill giá SP chính & cập nhật theo SL + gợi ý khi chọn SP
  setupMainAutoPricing('create');

  window.createTotal = bindGiftTotals('create');
  createTotal.recalc();

  // Ràng buộc chống trùng lặp trước khi submit
  bindPreSubmitCheck('#formCreate');
});

// ===== Utils: lấy object từ data-json an toàn =====
function getDataJson($btn){
  let item = $btn.data('json');
  if (typeof item === 'string') {
    try { item = JSON.parse(item); } catch (err) { console.error('JSON parse error:', err, item); item = {}; }
  } else if (item && typeof item === 'object') {
    item = Object.assign({}, item);
  } else { item = {}; }
  return item;
}

// ===== Edit modal behaviour =====
$('#editModal').on('show.bs.modal', function (e) {
  const $btn  = $(e.relatedTarget);
  const item  = getDataJson($btn);

  $('#edit_id').val(item.id || '');
  $('#edit_main_product').val(item.main_product || '');
  $('#edit_main_qty').val(item.main_qty || 1);
  $('#edit_main_value').val(item.main_value || 0);

  // datetime: ưu tiên dữ liệu hiện tại; nếu rỗng, fill theo mới nhất toàn hệ thống
  const s = item.start_at ? (new Date(String(item.start_at).replace(' ','T')).toISOString().slice(0,16)) : '';
  const e2 = item.end_at ? (new Date(String(item.end_at).replace(' ','T')).toISOString().slice(0,16)) : '';
  if (s) $('#edit_start_at').val(s); else setDefaultTimesFromLatest('edit');
  if (e2) $('#edit_end_at').val(e2);

  $('#edit_qty').val(typeof item.qty !== 'undefined' ? item.qty : 50).attr('step','10');
  $('#edit_note').val(item.note || '');
  $('#edit_highlight').prop('checked', Number(item.highlight) === 1);

  $('#edit_coupon_type').val(item.coupon_type || '');
  $('#edit_coupon_code').val(item.coupon_code || '');
  $('#edit_coupon_value').val(item.coupon_value || 0);
  $('#edit_coupon_note').val(item.coupon_note || '');

  const t = parseInt(item.type || 2, 10);
  $('#edit_type').val([1,2].includes(t) ? t : 2);

  setTimeout(()=>{ $('#edit_main_product').trigger('focus'); }, 150);

  // load gifts via AJAX
  const $wrap = $('#edit_gifts_wrap').html('<div class="text-muted my-2">Đang tải quà…</div>');
  $.post('', {action:'fetch_gifts', promotion_id: item.id || 0}, function(resp){
    $wrap.empty();
    let data = (resp && resp.ok && Array.isArray(resp.data)) ? resp.data : [];
    if (!data.length && item.gift_product && String(item.gift_product).trim() !== '') {
      data.push({gift_product:item.gift_product, gift_qty:(item.gift_qty||1), gift_value:(item.gift_value||0)});
    }
    if (!data.length) { data.push({gift_product:'', gift_qty:1, gift_value:0}); }
    data.forEach((g, i)=> $wrap.append(giftRow('edit', i, g)));

    // Auto-pricing SP chính trong EDIT và gợi ý theo sản phẩm
    setupMainAutoPricing('edit');

    window.editTotal = bindGiftTotals('edit');
    editTotal.recalc();

    setTimeout(()=>{ $wrap.find('.edit_gift_product').first().trigger('focus'); }, 50);

    // Gợi ý gần giống của sản phẩm hiện tại
    if (item.main_product){ fetchSimilar('edit', item.main_product); }
  }, 'json');

  // Chống trùng lặp trước khi submit
  bindPreSubmitCheck('#formEdit');
});

// Nút thêm quà ở Edit (ngoài modal event)
$('#btnEditAddGift').off('click').on('click', function(){
  const $wrap = $('#edit_gifts_wrap');
  const idx = $wrap.children('.gift-row').length;
  $wrap.append(giftRow('edit', idx, {}));
  setTimeout(()=>{ $wrap.find('.edit_gift_product').last().trigger('focus'); }, 50);
  if (window.editTotal) editTotal.recalc();
});

// Audit modal
$('#auditModal').on('show.bs.modal', function(e){
  const $btn = $(e.relatedTarget);
  const pid = parseInt($btn.data('pid') || 0, 10);
  const $list = $('#auditList').html('<div class="text-muted">Đang tải lịch sử…</div>');
  $.post('', {action:'fetch_audits', promotion_id: pid}, function(resp){
    if (!resp || !resp.ok || !Array.isArray(resp.data) || !resp.data.length){
      $list.html('<div class="text-muted">Chưa có lịch sử.</div>');
      return;
    }
    const rows = resp.data.map(a => `
      <div class="border-bottom py-2">
        <div class="small">
          <span class="badge badge-light">${a.action}</span>
          bởi <b>${$('<div>').text(a.changed_by||'').html()}</b>
          lúc ${$('<div>').text(a.changed_at||'').html()}
        </div>
        ${a.change_summary ? `<div class="small text-muted prewrap">${$('<div>').text(a.change_summary).html()}</div>` : ''}
      </div>
    `).join('');
    $list.html(rows);
  }, 'json');
});

// Tự ẩn cảnh báo sau ~60 giây
$(function(){
  const $alert = $('#recentAlert');
  if ($alert.length){ setTimeout(()=>{ $alert.fadeOut(500); }, 60000); }
});
</script>
</body>
</html>
