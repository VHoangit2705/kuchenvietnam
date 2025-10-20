<?php
/**
 * update_return.php
 * API xử lý hàng hoàn về:
 *  - Đánh dấu đơn / mã BH cần hoàn (pending)
 *  - Admin duyệt → snapshot → xóa warranty → cập nhật orders
 *  - Admin từ chối → (NEW) snapshot đầy đủ → trả cờ check_return về 0
 *  - Admin xóa số seri của các dòng đã bị từ chối
 */

header('Content-Type: application/json; charset=UTF-8');
session_start();

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include 'config.php';
$conn->set_charset('utf8mb4');

if ($conn->connect_errno) {
  http_response_code(500);
  echo json_encode(['error' => 'DB connect error: ' . $conn->connect_error]);
  exit;
}

$position  = $_SESSION['position'] ?? '';
$isAdmin   = in_array($position, ['admin','Admin','ADMIN'], true);
$actorName = $_SESSION['full_name'] ?? 'unknown_user';

// ===== Đọc input =====
$body    = file_get_contents('php://input');
$request = json_decode($body, true) ?: [];

$orderCodes        = $request['order_codes']          ?? [];
$warrantyCodes     = $request['warranty_codes']       ?? [];
$approveIds        = $request['approve_ids']          ?? [];
$rejectIds         = $request['reject_ids']           ?? [];
$rejectReason      = trim($request['reject_reason'] ?? '');
$reason            = trim($request['reason'] ?? '');
$deleteRejectedIds = $request['delete_rejected_ids']  ?? [];   // NEW

$orderCodes        = array_values(array_filter(array_map('strval', $orderCodes)));
$warrantyCodes     = array_values(array_filter(array_map('strval', $warrantyCodes)));
$approveIds        = array_values(array_filter(array_map('intval', $approveIds)));
$rejectIds         = array_values(array_filter(array_map('intval', $rejectIds)));
$deleteRejectedIds = array_values(array_filter(array_map('intval', $deleteRejectedIds)));

if (
  empty($orderCodes) &&
  empty($warrantyCodes) &&
  empty($approveIds) &&
  empty($rejectIds) &&
  empty($deleteRejectedIds)
) {
  http_response_code(400);
  echo json_encode(['error' => 'Không có dữ liệu xử lý.']);
  exit;
}

// Helper placeholders
$placeholders = function(int $n) { return implode(',', array_fill(0, $n, '?')); };

try {
  $conn->begin_transaction();

  $updatedOrderIds     = [];
  $updatedWarrantyIds  = [];
  $historyInserted     = 0;
  $approvedCount       = 0;
  $rejectedCount       = 0;
  $deletedWarranties   = 0;

  // ======================================================
  // 1) ĐÁNH DẤU CẦN HOÀN (pending)
  // ======================================================
  if (!empty($orderCodes)) {
    $in = $placeholders(count($orderCodes));
    $sql = "
      SELECT o.id
      FROM orders o
      LEFT JOIN return_history rh 
        ON rh.order_id = o.id AND rh.action IN ('order_return','warranty_return')
      WHERE o.order_code2 IN ($in)
        AND rh.id IS NULL
      FOR UPDATE
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($orderCodes)), ...$orderCodes);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
      $updatedOrderIds[] = (int)$r['id'];
    }
    $stmt->close();

    if (!empty($updatedOrderIds)) {
      $idList = implode(',', $updatedOrderIds);
      $conn->query("UPDATE orders SET check_return = 1 WHERE id IN ($idList)");
    }
  }

  if (!empty($warrantyCodes)) {
    $in = $placeholders(count($warrantyCodes));
    $sql = "
      SELECT pw.id
      FROM product_warranties pw
      LEFT JOIN return_history rh 
        ON rh.warranty_id = pw.id AND rh.action = 'warranty_return'
      WHERE pw.warranty_code IN ($in)
        AND rh.id IS NULL
      FOR UPDATE
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($warrantyCodes)), ...$warrantyCodes);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
      $updatedWarrantyIds[] = (int)$r['id'];
    }
    $stmt->close();

    if (!empty($updatedWarrantyIds)) {
      $idList = implode(',', $updatedWarrantyIds);
      $conn->query("UPDATE product_warranties SET check_return = 1 WHERE id IN ($idList)");
    }
  }

  // Map warranty_id -> order_id (để ghi lịch sử)
  $warrantyToOrder = [];
  if (!empty($updatedWarrantyIds)) {
    $in = implode(',', array_map('intval', $updatedWarrantyIds));
    $sql = "
      SELECT pw.id AS wid, op.order_id AS oid
      FROM product_warranties pw
      LEFT JOIN order_products op ON op.id = pw.order_product_id
      WHERE pw.id IN ($in)
    ";
    $rs = $conn->query($sql);
    while ($row = $rs->fetch_assoc()) {
      $warrantyToOrder[(int)$row['wid']] = $row['oid'] ? (int)$row['oid'] : null;
    }
  }

  // Ghi history tránh trùng
  if (!empty($updatedWarrantyIds)) {
    $sql = "
      INSERT INTO return_history
        (order_id, warranty_id, scanned_by, action_time, action, reason)
      SELECT ?, ?, ?, NOW(), 'warranty_return', ?
      FROM DUAL
      WHERE NOT EXISTS (
        SELECT 1 FROM return_history rh
        WHERE (
          (rh.order_id IS NULL AND ? IS NULL) OR rh.order_id = ?
        ) AND (
          (rh.warranty_id IS NULL AND ? IS NULL) OR rh.warranty_id = ?
        )
        AND rh.action IN ('order_return','warranty_return')
      )
    ";
    $stmt = $conn->prepare($sql);
    foreach ($updatedWarrantyIds as $wid) {
      $oid = $warrantyToOrder[$wid] ?? null;
      // order_id, warranty_id, scanned_by, reason, oidNULLchk, oid, widNULLchk, wid
      $stmt->bind_param("iissiiii", $oid, $wid, $actorName, $reason, $oid, $oid, $wid, $wid);
      $stmt->execute();
      if ($stmt->affected_rows > 0) $historyInserted++;
    }
    $stmt->close();
  } elseif (!empty($updatedOrderIds)) {
    $sql = "
      INSERT INTO return_history
        (order_id, warranty_id, scanned_by, action_time, action, reason)
      SELECT ?, NULL, ?, NOW(), 'order_return', ?
      FROM DUAL
      WHERE NOT EXISTS (
        SELECT 1 FROM return_history rh
        WHERE (
          (rh.order_id IS NULL AND ? IS NULL) OR rh.order_id = ?
        ) AND rh.warranty_id IS NULL
        AND rh.action IN ('order_return','warranty_return')
      )
    ";
    $stmt = $conn->prepare($sql);
    foreach ($updatedOrderIds as $oid) {
      $stmt->bind_param("issii", $oid, $actorName, $reason, $oid, $oid);
      $stmt->execute();
      if ($stmt->affected_rows > 0) $historyInserted++;
    }
    $stmt->close();
  }

  // ======================================================
  // 2) ADMIN DUYỆT
  // ======================================================
  if (!empty($approveIds)) {
    $in = $placeholders(count($approveIds));
    $sql = "
      SELECT id, order_id, warranty_id
      FROM return_history
      WHERE id IN ($in) AND action IN ('warranty_return','order_return')
      FOR UPDATE
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('i', count($approveIds)), ...$approveIds);
    $stmt->execute();
    $rs = $stmt->get_result();

    $validIds = []; $orderIdsToUpdate = []; $warrantyIdsToDelete = [];
    while ($row = $rs->fetch_assoc()) {
      $validIds[] = (int)$row['id'];
      if (!empty($row['order_id']))    $orderIdsToUpdate[]    = (int)$row['order_id'];
      if (!empty($row['warranty_id'])) $warrantyIdsToDelete[] = (int)$row['warranty_id'];
    }
    $stmt->close();

    if (!empty($validIds)) {
      // Snapshot (từ pw/op + fallback order qua op.order_id)
      $inIds = $placeholders(count($validIds));
      $sqlSnap = "
        UPDATE return_history rh
        LEFT JOIN product_warranties pw ON rh.warranty_id = pw.id
        LEFT JOIN order_products op     ON pw.order_product_id = op.id
        LEFT JOIN orders o              ON op.order_id = o.id
        SET 
          rh.product_name   = COALESCE(rh.product_name,   op.product_name),
          rh.quantity       = COALESCE(rh.quantity,       op.quantity),
          rh.order_code2    = COALESCE(rh.order_code2,    o.order_code2),
          rh.customer_name  = COALESCE(rh.customer_name,  o.customer_name),
          rh.customer_phone = COALESCE(rh.customer_phone, o.customer_phone)
        WHERE rh.id IN ($inIds)
      ";
      $stmt = $conn->prepare($sqlSnap);
      $stmt->bind_param(str_repeat('i', count($validIds)), ...$validIds);
      $stmt->execute();
      $stmt->close();

      // Cập nhật action
      $approvedReason = 'Đã đồng ý hoàn hàng';
      $sql = "
        UPDATE return_history
        SET action = 'return_accepted',
            reason = ?,
            approved_by = ?,
            action_time = NOW()
        WHERE id IN ($inIds)
      ";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param('ss' . str_repeat('i', count($validIds)), $approvedReason, $actorName, ...$validIds);
      $stmt->execute();
      $approvedCount = $stmt->affected_rows;
      $stmt->close();

      // Xóa warranties nếu có
      if (!empty($warrantyIdsToDelete)) {
        $warrantyIdsToDelete = array_values(array_unique($warrantyIdsToDelete));
        $inW = $placeholders(count($warrantyIdsToDelete));
        $sql = "DELETE FROM product_warranties WHERE id IN ($inW)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($warrantyIdsToDelete)), ...$warrantyIdsToDelete);
        $stmt->execute();
        $stmt->close();
      }

      // Cập nhật orders
      if (!empty($orderIdsToUpdate)) {
        $orderIdsToUpdate = array_values(array_unique($orderIdsToUpdate));
        $inO = $placeholders(count($orderIdsToUpdate));
        $sql = "
          UPDATE orders
          SET check_return = 0,
              status_tracking = 'Đã trả'
          WHERE id IN ($inO)
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($orderIdsToUpdate)), ...$orderIdsToUpdate);
        $stmt->execute();
        $stmt->close();
      }
    }
  }

  // ======================================================
  // 3) ADMIN TỪ CHỐI (NEW: snapshot đầy đủ trước khi set reject_return)
  // ======================================================
  if (!empty($rejectIds)) {
    if ($rejectReason === '') {
      throw new Exception('Thiếu reject_reason khi từ chối.');
    }

    $in = $placeholders(count($rejectIds));
    $sql = "
      SELECT id, order_id, warranty_id
      FROM return_history
      WHERE id IN ($in) AND action IN ('warranty_return','order_return')
      FOR UPDATE
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('i', count($rejectIds)), ...$rejectIds);
    $stmt->execute();
    $rs = $stmt->get_result();

    $validIds = []; $orderIdsReject = []; $warrantyIdsReject = [];
    while ($row = $rs->fetch_assoc()) {
      $validIds[] = (int)$row['id'];
      if (!empty($row['order_id']))    $orderIdsReject[]    = (int)$row['order_id'];
      if (!empty($row['warranty_id'])) $warrantyIdsReject[] = (int)$row['warranty_id'];
    }
    $stmt->close();

    if (!empty($validIds)) {
      // SNAPSHOT: cover cả hai tình huống:
      // - Có warranty: đi pw -> op -> orders(op.order_id)
      // - Không có warranty (order_return): dùng trực tiếp rh.order_id
      $inIds = $placeholders(count($validIds));
      $sqlSnapReject = "
        UPDATE return_history rh
        LEFT JOIN product_warranties pw ON rh.warranty_id = pw.id
        LEFT JOIN order_products op     ON pw.order_product_id = op.id
        LEFT JOIN orders o              ON o.id = COALESCE(op.order_id, rh.order_id)
        SET 
          rh.product_name   = COALESCE(rh.product_name,   op.product_name),
          rh.quantity       = COALESCE(rh.quantity,       op.quantity),
          rh.order_code2    = COALESCE(rh.order_code2,    o.order_code2),
          rh.customer_name  = COALESCE(rh.customer_name,  o.customer_name),
          rh.customer_phone = COALESCE(rh.customer_phone, o.customer_phone)
        WHERE rh.id IN ($inIds)
      ";
      $stmt = $conn->prepare($sqlSnapReject);
      $stmt->bind_param(str_repeat('i', count($validIds)), ...$validIds);
      $stmt->execute();
      $stmt->close();

      // Cập nhật action -> reject_return
      $sql = "
        UPDATE return_history
        SET action = 'reject_return',
            reason = ?,
            approved_by = ?,
            action_time = NOW()
        WHERE id IN ($inIds)
      ";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param('ss' . str_repeat('i', count($validIds)), $rejectReason, $actorName, ...$validIds);
      $stmt->execute();
      $rejectedCount = $stmt->affected_rows;
      $stmt->close();

      // Trả cờ check_return về 0
      if (!empty($orderIdsReject)) {
        $orderIdsReject = array_values(array_unique($orderIdsReject));
        $inO = $placeholders(count($orderIdsReject));
        $sql = "UPDATE orders SET check_return = 0 WHERE id IN ($inO)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($orderIdsReject)), ...$orderIdsReject);
        $stmt->execute();
        $stmt->close();
      }
      if (!empty($warrantyIdsReject)) {
        $warrantyIdsReject = array_values(array_unique($warrantyIdsReject));
        $inW = $placeholders(count($warrantyIdsReject));
        $sql = "UPDATE product_warranties SET check_return = 0 WHERE id IN ($inW)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($warrantyIdsReject)), ...$warrantyIdsReject);
        $stmt->execute();
        $stmt->close();
      }
    }
  }

  // ======================================================
  // 4) ADMIN XÓA SỐ SERI (chỉ cho các dòng đã reject_return)
  // ======================================================
  if (!empty($deleteRejectedIds)) {
    if (!$isAdmin) {
      throw new Exception('Chỉ Admin mới có quyền xóa số seri của dòng bị từ chối.');
    }

    $in = $placeholders(count($deleteRejectedIds));
    $sql = "
      SELECT rh.id AS rh_id, rh.warranty_id
      FROM return_history rh
      WHERE rh.id IN ($in)
        AND rh.action = 'reject_return'
        AND rh.warranty_id IS NOT NULL
      FOR UPDATE
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('i', count($deleteRejectedIds)), ...$deleteRejectedIds);
    $stmt->execute();
    $rs = $stmt->get_result();

    $warrantyIds = []; $eligibleRhIds = [];
    while ($row = $rs->fetch_assoc()) {
      $eligibleRhIds[] = (int)$row['rh_id'];
      $warrantyIds[]   = (int)$row['warranty_id'];
    }
    $stmt->close();

    if (!empty($warrantyIds)) {
      $warrantyIds = array_values(array_unique($warrantyIds));
      $inW = $placeholders(count($warrantyIds));
      $sql = "DELETE FROM product_warranties WHERE id IN ($inW)";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param(str_repeat('i', count($warrantyIds)), ...$warrantyIds);
      $stmt->execute();
      $deletedWarranties = $stmt->affected_rows;
      $stmt->close();

      // Log audit action
      $inRh = $placeholders(count($eligibleRhIds));
      $sql = "
        INSERT INTO return_history (order_id, warranty_id, scanned_by, approved_by, action_time, action, reason)
        SELECT rh.order_id, rh.warranty_id, ?, ?, NOW(), 'admin_delete_rejected_warranty', 'Admin xóa số seri sau khi bị từ chối'
        FROM return_history rh
        WHERE rh.id IN ($inRh)
      ";
      $stmt = $conn->prepare($sql);
      $bindTypes = 'ss' . str_repeat('i', count($eligibleRhIds));
      $stmt->bind_param($bindTypes, $actorName, $actorName, ...$eligibleRhIds);
      $stmt->execute();
      $stmt->close();
    }
  }

  // ======================================================
  // COMMIT
  // ======================================================
  $conn->commit();

  echo json_encode([
    'success'                    => true,
    'orders_updated'             => count($updatedOrderIds),
    'product_warranties_updated' => count($updatedWarrantyIds),
    'history_records_inserted'   => $historyInserted,
    'approved_updated'           => $approvedCount,
    'rejected_updated'           => $rejectedCount,
    'deleted_warranties'         => $deletedWarranties,
    'updated'                    => $historyInserted + $approvedCount + $rejectedCount + $deletedWarranties
  ]);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['error' => 'Transaction failed: ' . $e->getMessage()]);
  exit;
}
