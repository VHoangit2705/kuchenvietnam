<?php
session_start();
if (!isset($_SESSION['full_name'])) {
    header("Location: index.php");
    exit();
}
include 'config.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Danh sách lệnh in</title>
    <style>
        body { font-family: Arial, sans-serif; }
        .container { width: 80%; margin: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table, th, td { border: 1px solid #ddd; }
        th, td { padding: 8px; text-align: center; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
<div class="container">
    <h1>Danh sách lệnh in</h1>
    <?php
    $sql = "SELECT id, product_name, zone, quantity, operator, status, created_at FROM print_orders ORDER BY created_at DESC";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        echo "<table>
                <tr>
                  <th>ID</th>
                  <th>Tên sản phẩm</th>
                  <th>Kho</th>
                  <th>Số lượng</th>
                  <th>Người gửi</th>
                  <th>Trạng thái</th>
                  <th>Ngày gửi</th>
                </tr>";
        while($row = $result->fetch_assoc()){
            echo "<tr>
                    <td>{$row['id']}</td>
                    <td>{$row['product_name']}</td>
                    <td>{$row['zone']}</td>
                    <td>{$row['quantity']}</td>
                    <td>{$row['operator']}</td>
                    <td>{$row['status']}</td>
                    <td>{$row['created_at']}</td>
                  </tr>";
        }
        echo "</table>";
    } else {
        echo "Không có lệnh in nào.";
    }
    ?>
    <br>
    <form method="POST" action="export_print_orders.php">
        <button type="submit">Xuất danh sách lệnh in (CSV)</button>
    </form>
    <br>
    <a href="dashboard.php">Quay lại Dashboard</a>
</div>
</body>
</html>
