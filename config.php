<?php
$servername = "localhost";
$username = "kuchenvi_kho_kuchen";
$password = "HYvqPg65uJWQ3AhtKGdN";
$dbname = "kuchenvi_kho_kuchen"; 
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
} else {
    echo "";
}
if (!$conn->set_charset('utf8')) {
    die("Lỗi khi thiết lập charset: " . $conn->error);
} else {
    echo "";
}
?>
