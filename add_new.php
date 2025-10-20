<?php
session_start();
if (!isset($_SESSION['full_name'])) {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Nhập số lượng tem in mới</title>
    <style>
        body { font-family: Arial, sans-serif; }
        .container { width: 80%; margin: auto; }
        label { display: block; margin: 10px 0 5px; }
        input, select { width: 100%; padding: 8px; margin-bottom: 10px; }
        button { padding: 10px 20px; background-color: #28a745; color: white; border: none; cursor: pointer; }
        button:hover { background-color: #218838; }
    </style>
</head>
<body>
<div class="container">
    <h1>Nhập số lượng tem in mới</h1>
    <form method="POST" action="process.php">
        <input type="hidden" name="action" value="add_new">
        <label for="product_name_new">Tên sản phẩm:</label>
        <input type="text" name="product_name_new" id="product_name_new" required>
        
        <label for="zone_new">Chọn kho nhập tem:</label>
        <select name="zone_new" id="zone_new" required>
            <option value="Đơn hàng Vinh">Đơn hàng Vinh</option>
            <option value="Đơn hàng HaNoi">Đơn hàng HaNoi</option>
            <option value="Đơn hàng HCM">Đơn hàng HCM</option>
        </select>
        
        <label for="quantity_new">Số lượng tem in mới:</label>
        <input type="number" name="quantity_new" id="quantity_new" required min="1">
        
        <button type="submit">Thêm tem in mới</button>
    </form>
    <br>
    <a href="dashboard.php">Quay lại Dashboard</a>
</div>
</body>
</html>
