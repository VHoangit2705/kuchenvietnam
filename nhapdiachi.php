<?php
// ======================================================================
// Import địa chỉ Excel → cập nhật orders (province/district/wards)
// - UPDATE theo order_code2 "gần đúng": ABC123 sẽ phủ ABC123, ABC123 (1), ABC123-1, ABC123_1…
// ======================================================================

// 0) PHP errors (ẩn deprecated ${var} trên PHP 8.2)
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '1');

// 1) Autoload PhpSpreadsheet
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

// ======================================================================
// 2) Helpers — Chuẩn hoá & key hoá địa danh + mã đơn
// ======================================================================

/**
 * vn_norm: bỏ dấu + lower + gọn khoảng trắng + bỏ ký tự lạ
 */
function vn_norm(?string $s): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    $s = mb_strtolower($s, 'UTF-8');

    $replacements = [
        'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
        'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
        'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
        'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
        'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
        'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y',
        'đ'=>'d',
        'À'=>'a','Á'=>'a','Ạ'=>'a','Ả'=>'a','Ã'=>'a','Â'=>'a','Ầ'=>'a','Ấ'=>'a','Ậ'=>'a','Ẩ'=>'a','Ẫ'=>'a','Ă'=>'a','Ằ'=>'a','Ắ'=>'a','Ặ'=>'a','Ẳ'=>'a','Ẵ'=>'a',
        'È'=>'e','É'=>'e','Ẹ'=>'e','Ẻ'=>'e','Ẽ'=>'e','Ê'=>'e','Ề'=>'e','Ế'=>'e','Ệ'=>'e','Ể'=>'e','Ễ'=>'e',
        'Ì'=>'i','Í'=>'i','Ị'=>'i','Ỉ'=>'i','Ĩ'=>'i',
        'Ò'=>'o','Ó'=>'o','Ọ'=>'o','Ỏ'=>'o','Õ'=>'o','Ô'=>'o','Ồ'=>'o','Ố'=>'o','Ộ'=>'o','Ổ'=>'o','Ỗ'=>'o','Ơ'=>'o','Ờ'=>'o','Ớ'=>'o','Ợ'=>'o','Ở'=>'o','Ỡ'=>'o',
        'Ù'=>'u','Ú'=>'u','Ụ'=>'u','Ủ'=>'u','Ũ'=>'u','Ư'=>'u','Ừ'=>'u','Ứ'=>'u','Ự'=>'u','Ử'=>'u','Ữ'=>'u',
        'Ỳ'=>'y','Ý'=>'y','Ỵ'=>'y','Ỷ'=>'y','Ỹ'=>'y',
        'Đ'=>'d',
    ];
    $s = strtr($s, $replacements);

    // bỏ ký tự không phải chữ/số/khoảng trắng
    $s = preg_replace('~[^\p{L}\p{N}\s]+~u', ' ', $s);
    // gọn khoảng trắng
    $s = preg_replace('~\s+~u', ' ', $s);
    return trim($s);
}

/** Đổi La Mã → số, bỏ số 0 đứng đầu */
function vn_num_norm(string $s): string {
    $pad = ' ' . $s . ' ';
    $map = [
        ' i '=>' 1 ',' ii '=>' 2 ',' iii '=>' 3 ',' iv '=>' 4 ',' v '=>' 5 ',
        ' vi '=>' 6 ',' vii '=>' 7 ',' viii '=>' 8 ',' ix '=>' 9 ',' x '=>' 10 ',
    ];
    $pad = strtr($pad, $map);
    $pad = preg_replace('~\b0+(\d)\b~', '$1', $pad);  // 01 -> 1
    $pad = preg_replace('~\s+~', ' ', $pad);
    return trim($pad);
}

/**
 * Bỏ tiền tố hành chính & alias theo cấp (province|district|wards)
 */
function vn_strip_admin(string $s, string $level): string {
    $s = ' ' . vn_norm($s) . ' ';

    if ($level === 'province') {
        $s = preg_replace('~\b(tinh|thanh pho|tp|t p)\b~u', ' ', $s);
        $s = strtr($s, [
            ' sai gon ' => ' ho chi minh ',
            ' tphcm '   => ' ho chi minh ',
            ' tp hcm '  => ' ho chi minh ',
            ' hcm '     => ' ho chi minh ',
            ' hn '      => ' ha noi ',
        ]);
    } elseif ($level === 'district') {
        $s = preg_replace('~\b(quan|q|huyen|h|thi xa|tx|thi tran|tt)\b~u', ' ', $s);
        $s = vn_num_norm($s);
    } elseif ($level === 'wards') {
        $s = preg_replace('~\b(phuong|p|xa|x|thi tran|tt)\b~u', ' ', $s);
        $s = vn_num_norm($s);
    }

    $s = preg_replace('~\s+~u', ' ', $s);
    return trim($s);
}

/** Key so khớp cho mỗi cấp (norm + strip) */
function vn_key_for_level(string $s, string $level): string {
    return vn_strip_admin($s, $level);
}

/**
 * Lấy "mã gốc" từ order_code2
 * - Bỏ đuôi: " (123)", "-123", "_123", " copy", " copy 2"
 */
function order_base(string $code): string {
    $base = trim($code);
    // bỏ "(n)" ở cuối
    $base = preg_replace('/\s*\(\d+\)\s*$/u', '', $base);
    // bỏ "-n" hoặc "_n" ở cuối
    $base = preg_replace('/[-_]\d+\s*$/u', '', $base);
    // bỏ " copy" hoặc " copy n" ở cuối (không phân biệt hoa/thường)
    $base = preg_replace('/\s*copy(\s*\d+)?\s*$/iu', '', $base);
    return trim($base ?? '');
}

// ======================================================================
// 3) Đọc Excel — C=order_code2, F=Province, G=District, H=Wards
// ======================================================================
function importExcel(string $filePath): array {
    $type        = IOFactory::identify($filePath);
    $reader      = IOFactory::createReader($type);
    $spreadsheet = $reader->load($filePath);
    $rows        = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

    $out = [];
    foreach ($rows as $i => $r) {
        if ($i === 1) continue; // bỏ header

        $orderCode2   = trim($r['G'] ?? '');
        $provinceName = trim($r['L'] ?? '');
        $districtName = trim($r['M'] ?? '');
        $wardsName    = trim($r['N'] ?? '');
        $agencyName   = trim($r['P'] ?? '');
        $agencyPhone  = trim($r['Q'] ?? '');
// Nếu không rỗng và chưa bắt đầu bằng 0 thì thêm 0
if ($agencyPhone !== '' && $agencyPhone[0] !== '0') {
    $agencyPhone = '0' . $agencyPhone;
}


        if ($orderCode2 === '') continue;
        if ($provinceName === '' && $districtName === '' && $wardsName === '' 
            && $agencyName === '' && $agencyPhone === '') continue;

        $out[] = [
            'order_code2'   => $orderCode2,
            'province_name' => $provinceName,
            'district_name' => $districtName,
            'wards_name'    => $wardsName,
            'agency_name'   => $agencyName,
            'agency_phone'  => $agencyPhone,
        ];
    }
    return $out;
}

// ======================================================================
// 4) Nạp danh mục địa giới vào RAM (nhiều map để so khớp an toàn)
// ======================================================================
function loadGeoMaps(mysqli $conn): array {
    $maps = [
        'province_by_name'      => [], // norm(name) => province_id
        'province_by_key'       => [], // key(strip province) => province_id
        'district_by_province'  => [], // [pid][norm(name)] => district_id
        'district_key_by_prov'  => [], // [pid][key(strip district)] => district_id
        'wards_by_district'     => [], // [did][norm(name)] => wards_id
        'wards_key_by_district' => [], // [did][key(strip wards)] => wards_id
    ];

    // Provinces
    $rs = $conn->query("SELECT province_id, name FROM province");
    while ($row = $rs->fetch_assoc()) {
        $pid = (int)$row['province_id'];
        $n1  = vn_norm($row['name']);
        $k1  = vn_key_for_level($row['name'], 'province');
        $maps['province_by_name'][$n1] = $pid;
        $maps['province_by_key'][$k1]  = $pid;
    }
    $rs->free();

    // Districts
    $rs = $conn->query("SELECT district_id, province_id, name FROM district");
    while ($row = $rs->fetch_assoc()) {
        $did = (int)$row['district_id'];
        $pid = (int)$row['province_id'];
        $n1  = vn_norm($row['name']);
        $k1  = vn_key_for_level($row['name'], 'district');

        if (!isset($maps['district_by_province'][$pid])) $maps['district_by_province'][$pid] = [];
        if (!isset($maps['district_key_by_prov'][$pid])) $maps['district_key_by_prov'][$pid] = [];

        $maps['district_by_province'][$pid][$n1] = $did;
        $maps['district_key_by_prov'][$pid][$k1] = $did;
    }
    $rs->free();

    // Wards
    $rs = $conn->query("SELECT wards_id, district_id, name FROM wards");
    while ($row = $rs->fetch_assoc()) {
        $wid = (int)$row['wards_id'];
        $did = (int)$row['district_id'];
        $n1  = vn_norm($row['name']);
        $k1  = vn_key_for_level($row['name'], 'wards');

        if (!isset($maps['wards_by_district'][$did]))         $maps['wards_by_district'][$did] = [];
        if (!isset($maps['wards_key_by_district'][$did]))     $maps['wards_key_by_district'][$did] = [];

        $maps['wards_by_district'][$did][$n1] = $wid;
        $maps['wards_key_by_district'][$did][$k1] = $wid;
    }
    $rs->free();

    return $maps;
}

// ======================================================================
// 5) So khớp 3 cấp: exact(norm) → exact(key) (an toàn)
// ======================================================================
function matchGeos(array $row, array $maps): array {
    $p_raw = vn_norm($row['province_name']);
    $p_key = vn_key_for_level($row['province_name'], 'province');

    $d_raw = vn_norm($row['district_name']);
    $d_key = vn_key_for_level($row['district_name'], 'district');

    $w_raw = vn_norm($row['wards_name']);
    $w_key = vn_key_for_level($row['wards_name'], 'wards');

    $province_id = null;
    $district_id = null;
    $wards_id    = null;
    $noteSteps   = [];

    // Province
    if ($p_raw !== '' && isset($maps['province_by_name'][$p_raw])) {
        $province_id = $maps['province_by_name'][$p_raw];
        $noteSteps[] = 'Province ✔ exact(norm)';
    } elseif ($p_key !== '' && isset($maps['province_by_key'][$p_key])) {
        $province_id = $maps['province_by_key'][$p_key];
        $noteSteps[] = 'Province ✔ exact(key)';
    } else {
        if ($p_raw !== '' || $p_key !== '') $noteSteps[] = 'Province ✖';
    }

    // District (ràng buộc theo province_id)
    if ($province_id !== null && ($d_raw !== '' || $d_key !== '')) {
        $byNorm = $maps['district_by_province'][$province_id] ?? [];
        $byKey  = $maps['district_key_by_prov'][$province_id] ?? [];

        if ($d_raw !== '' && isset($byNorm[$d_raw])) {
            $district_id = $byNorm[$d_raw];
            $noteSteps[] = 'District ✔ exact(norm)';
        } elseif ($d_key !== '' && isset($byKey[$d_key])) {
            $district_id = $byKey[$d_key];
            $noteSteps[] = 'District ✔ exact(key)';
        } else {
            $noteSteps[] = 'District ✖';
        }
    }

    // Wards (ràng buộc theo district_id)
    if ($district_id !== null && ($w_raw !== '' || $w_key !== '')) {
        $byNorm = $maps['wards_by_district'][$district_id] ?? [];
        $byKey  = $maps['wards_key_by_district'][$district_id] ?? [];

        if ($w_raw !== '' && isset($byNorm[$w_raw])) {
            $wards_id = $byNorm[$w_raw];
            $noteSteps[] = 'Wards ✔ exact(norm)';
        } elseif ($w_key !== '' && isset($byKey[$w_key])) {
            $wards_id = $byKey[$w_key];
            $noteSteps[] = 'Wards ✔ exact(key)';
        } else {
            $noteSteps[] = 'Wards ✖';
        }
    }

    return [
        'province_id' => $province_id,
        'district_id' => $district_id,
        'wards_id'    => $wards_id,
        'note'        => implode(' | ', $noteSteps),
    ];
}

// ======================================================================
// 6) UPDATE orders theo order_code2 "gần đúng" (phủ biến thể)
// ======================================================================
function updateOrders(mysqli $conn, array $rows, array $maps): array {
    $results = [];

    $sqlWhereSuffix = " WHERE order_code2 = ?
                        OR order_code2 LIKE CONCAT(?, ' (', '%', ')')
                        OR order_code2 LIKE CONCAT(?, '-%')
                        OR order_code2 LIKE CONCAT(?, '_%')";

    $stmtProvince = $conn->prepare("UPDATE orders SET province = ? " . $sqlWhereSuffix);
    $stmtDistrict = $conn->prepare("UPDATE orders SET district = ? " . $sqlWhereSuffix);
    $stmtWards    = $conn->prepare("UPDATE orders SET wards    = ? " . $sqlWhereSuffix);
    $stmtAgency   = $conn->prepare("UPDATE orders SET agency_name = ?, agency_phone = ? " . $sqlWhereSuffix);

    foreach ($rows as $r) {
        $matches = matchGeos($r, $maps);
        $base    = order_base($r['order_code2']); 

        $okP = $okD = $okW = $okA = false;
        $affP = $affD = $affW = $affA = 0;
        $err = [];

        if (!empty($matches['province_id']) && $base !== '') {
            $pid = (int)$matches['province_id'];
            $stmtProvince->bind_param('issss', $pid, $base, $base, $base, $base);
            if ($stmtProvince->execute()) { $okP = true; $affP = $stmtProvince->affected_rows; }
            else { $err[] = 'province: '.$stmtProvince->error; }
        }

        if (!empty($matches['district_id']) && $base !== '') {
            $did = (int)$matches['district_id'];
            $stmtDistrict->bind_param('issss', $did, $base, $base, $base, $base);
            if ($stmtDistrict->execute()) { $okD = true; $affD = $stmtDistrict->affected_rows; }
            else { $err[] = 'district: '.$stmtDistrict->error; }
        }

        if (!empty($matches['wards_id']) && $base !== '') {
            $wid = (int)$matches['wards_id'];
            $stmtWards->bind_param('issss', $wid, $base, $base, $base, $base);
            if ($stmtWards->execute()) { $okW = true; $affW = $stmtWards->affected_rows; }
            else { $err[] = 'wards: '.$stmtWards->error; }
        }

        // ✅ Update agency_name + agency_phone nếu có dữ liệu
        if (($r['agency_name'] !== '' || $r['agency_phone'] !== '') && $base !== '') {
            $stmtAgency->bind_param('ssssss', 
                $r['agency_name'], $r['agency_phone'], 
                $base, $base, $base, $base
            );
            if ($stmtAgency->execute()) { $okA = true; $affA = $stmtAgency->affected_rows; }
            else { $err[] = 'agency: '.$stmtAgency->error; }
        }

        $results[] = [
            'order_code2_excel' => $r['order_code2'],
            'order_code2_base'  => $base,
            'province_name'     => $r['province_name'],
            'district_name'     => $r['district_name'],
            'wards_name'        => $r['wards_name'],
            'agency_name'       => $r['agency_name'],
            'agency_phone'      => $r['agency_phone'],
            'province_id'       => $matches['province_id'],
            'district_id'       => $matches['district_id'],
            'wards_id'          => $matches['wards_id'],
            'matched_note'      => $matches['note'],
            'updated'           => sprintf(
                'P:%s(%d) D:%s(%d) W:%s(%d) A:%s(%d)', 
                $okP?'✔':'—',$affP,$okD?'✔':'—',$affD,$okW?'✔':'—',$affW,$okA?'✔':'—',$affA
            ),
            'error'             => implode(' | ', $err),
        ];
    }

    $stmtProvince->close();
    $stmtDistrict->close();
    $stmtWards->close();
    $stmtAgency->close();

    return $results;
}

// ======================================================================
// 7) Controller: nhận file, import + update + render preview
// ======================================================================
$data = [];
$preview = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['excel_file']['tmp_name'])) {
    include 'config.php';            // $conn = new mysqli(...)
    $conn->set_charset('utf8mb4');

    $data    = importExcel($_FILES['excel_file']['tmp_name']);
    $maps    = loadGeoMaps($conn);
    $preview = updateOrders($conn, $data, $maps);

    $conn->close();
}

?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Import địa chỉ → Cập nhật Orders</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .small { font-size: .9rem; }
    .code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
  </style>
</head>
<body>
<div class="container my-5">
  <h1 class="text-center mb-4">Import địa chỉ Excel → Province/District/Wards cho Orders</h1>

  <div class="card mb-4">
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <div class="row g-2 align-items-end">
          <div class="col-md-9">
            <label class="form-label">Chọn file Excel (.xls, .xlsx)</label>
            <input type="file" name="excel_file" accept=".xls,.xlsx" class="form-control" required>
            <div class="form-text">Cột C = Mã đơn hàng (order_code2), F = Tỉnh/TP, G = Quận/Huyện, H = Phường/Xã.</div>
          </div>
          <div class="col-md-3 d-grid">
            <button class="btn btn-primary">Import &amp; Cập nhật</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <?php if (!empty($preview)): ?>
    <div class="alert alert-info">
      Đã xử lý <b><?= count($preview) ?></b> dòng. “P/D/W(x)” = cập nhật ✔ hoặc bỏ qua —, kèm số bản ghi bị ảnh hưởng.
    </div>

    <div class="table-responsive">
      <table class="table table-bordered table-sm align-middle">
        <thead class="table-light">
  <tr>
    <th>#</th>
    <th>order_code2 (Excel)</th>
    <th>Base dùng UPDATE</th>
    <th>L: Province</th>
    <th>M: District</th>
    <th>N: Wards</th>
    <th>P: Agency name</th>
    <th>Q: Agency phone</th>
    <th>province_id</th>
    <th>district_id</th>
    <th>wards_id</th>
    <th>Matched note</th>
    <th>Updated (P/D/W/A)</th>
    <th>Lỗi</th>
  </tr>
</thead>
<tbody>
  <?php foreach ($preview as $i => $r): ?>
    <tr>
      <td><?= $i+1 ?></td>
      <td class="code"><?= htmlspecialchars($r['order_code2_excel']) ?></td>
      <td class="code"><?= htmlspecialchars($r['order_code2_base']) ?></td>
      <td><?= htmlspecialchars($r['province_name']) ?></td>
      <td><?= htmlspecialchars($r['district_name']) ?></td>
      <td><?= htmlspecialchars($r['wards_name']) ?></td>
      <td><?= htmlspecialchars($r['agency_name']) ?></td>
      <td><?= htmlspecialchars($r['agency_phone']) ?></td>
      <td><?= htmlspecialchars((string)$r['province_id']) ?></td>
      <td><?= htmlspecialchars((string)$r['district_id']) ?></td>
      <td><?= htmlspecialchars((string)$r['wards_id']) ?></td>
      <td class="small"><?= htmlspecialchars($r['matched_note']) ?></td>
      <td class="small"><?= htmlspecialchars($r['updated']) ?></td>
      <td class="text-danger small"><?= htmlspecialchars($r['error']) ?></td>
    </tr>
  <?php endforeach; ?>
</tbody>

      </table>
    </div>
  <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <div class="alert alert-warning">Không có dòng hợp lệ để xử lý.</div>
  <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
