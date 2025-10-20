<?php
session_start();
if (!isset($_SESSION['full_name'])) {
    header("Location: index.php");
    exit();
}
include 'config.php';

// Cài đặt header cho CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=print_orders.csv');

// Mở file pointer cho output
$output = fopen('php://output', 'w');

// Ghi tiêu đề cột
fputcsv($output, array('ID', 'Product Name', 'Zone', 'Quantity', 'Operator', 'Status', 'Created At'));

// Lấy dữ liệu lệnh in
$sql = "SELECT id, product_name, zone, quantity, operator, status, created_at FROM print_orders ORDER BY created_at DESC";
$result = $conn->query($sql);
while($row = $result->fetch_assoc()){
    fputcsv($output, $row);
}
fclose($output);
exit();
?>
