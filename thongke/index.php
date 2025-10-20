<?php
include'../config.php';

// Kiểm tra kết nối
if (!$conn) {
    die("Kết nối thất bại: " . mysqli_connect_error());
}

?>
<?php
// Nhận giá trị từ form tìm kiếm (nếu có)
$productNameFilter = isset($_GET['product_name']) ? mysqli_real_escape_string($conn, $_GET['product_name']) : '';

// Tạo truy vấn với điều kiện lọc
$sql = "SELECT product_name, SUM(quantity) AS total_sold 
        FROM order_products";

if (!empty($productNameFilter)) {
    $sql .= " WHERE product_name LIKE '%$productNameFilter%'";
}

$sql .= " GROUP BY product_name ORDER BY total_sold DESC";

// Thực thi truy vấn
$result = mysqli_query($conn, $sql);
?>

<!-- Hiển thị kết quả -->
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Thống kê sản phẩm đã bán</title>
</head>
<body>
<h2>Thống kê số lượng sản phẩm đã bán</h2>

<!-- Form Tìm Kiếm -->
<form method="GET" action="">
    <label for="product_name">Tìm kiếm sản phẩm:</label>
    <input type="text" name="product_name" id="product_name" value="<?= htmlspecialchars($productNameFilter); ?>">
    <button type="submit">Lọc</button>
</form>

<!-- Bảng Kết Quả -->
<table border="1">
    <tr>
        <th>Tên sản phẩm</th>
        <th>Số lượng đã bán</th>
    </tr>
    <?php
    if (mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['product_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['total_sold']) . "</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='2'>Không có kết quả</td></tr>";
    }
    ?>
</table>

</body>
</html>

<?php
// Đóng kết nối
mysqli_close($conn);
?> 