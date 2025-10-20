<?php
// Include the database configuration file which contains the $conn connection
include('config.php');

// Check if 'id' is passed in the GET request
if (isset($_GET['id'])) {
    $productId = $_GET['id'];

    // Escape the product ID to prevent SQL injection
    $productId = $conn->real_escape_string($productId);

    // Query to get product data by ID
    $query = "SELECT * FROM order_products WHERE id = $productId";
    $result = $conn->query($query);

    // Check if the product was found
    if ($result->num_rows > 0) {
        $product = $result->fetch_assoc();
        echo json_encode(['success' => true, 'product' => $product]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No product ID provided']);
}
?>
