<?php
// get_wards.php?district_id=xx
header('Content-Type: application/json; charset=UTF-8');
session_start();
require 'config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$did = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
if ($did <= 0) { echo json_encode(['success'=>true,'data'=>[]]); exit; }

try {
  $stmt = $conn->prepare("SELECT wards_id AS id, name FROM wards WHERE district_id=? ORDER BY name ASC");
  $stmt->bind_param('i', $did);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) $rows[] = $r;
  echo json_encode(['success'=>true, 'data'=>$rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
