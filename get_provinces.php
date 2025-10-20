<?php
// get_provinces.php
header('Content-Type: application/json; charset=UTF-8');
session_start();
require 'config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

try {
  $rs = $conn->query("SELECT province_id AS id, name FROM province ORDER BY name ASC");
  $rows = [];
  while ($r = $rs->fetch_assoc()) $rows[] = $r;
  echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
