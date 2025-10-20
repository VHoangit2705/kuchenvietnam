<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once 'config.php';

// Lấy ID đơn hàng từ URL
$orderId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($orderId <= 0) { die("Đơn hàng không hợp lệ."); }

// Lấy thông tin đơn hàng
$stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) { die("Không tìm thấy đơn hàng."); }

// Giảm giá: lấy từ discount_code
$discountRaw = $order['discount_code'] ?? '';
$discountAmount = (is_numeric($discountRaw) ? (int)$discountRaw : 0);
$discountCode   = (is_numeric($discountRaw) ? '' : $discountRaw);

// Lấy danh sách sản phẩm
$sql = "SELECT * FROM order_products WHERE order_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <title>Xác nhận thông tin phiếu giao hàng</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5">
  <div class="text-center my-4">
    <button class="btn btn-primary btn-lg fw-bold px-4 py-3">
      Xác nhận thông tin phiếu giao hàng
    </button>
    <p class="mt-3 text-muted">
      Nhân viên chú ý kiểm tra điều chỉnh lại thông tin trước khi nhấn <strong>XÁC NHẬN</strong>.
    </p>
  </div>

  <h4 class="fw-bold mt-4">I. Thông tin khách hàng</h4>
  <form method="POST">
    <input type="hidden" name="order_db_id" value="<?php echo $orderId; ?>">

    <div class="mb-3">
      <label class="form-label fw-bold">Mã đơn hàng</label>
      <input type="text" class="form-control" value="<?php echo $order['order_code2']; ?>" readonly>
    </div>

    <div class="mb-3">
      <label class="form-label fw-bold">Tên Khách hàng</label>
      <input type="text" class="form-control" name="customer_name"
             value="<?php echo htmlspecialchars($order['customer_name']); ?>">
    </div>

    <div class="mb-3">
      <label class="form-label fw-bold">Ngày đặt hàng</label>
      <input type="date" class="form-control" name="created_at"
             value="<?php echo date('Y-m-d', strtotime($order['created_at'])); ?>">
    </div>

    <div class="mb-3">
      <label class="form-label fw-bold">SĐT khách hàng</label>
      <input type="text" class="form-control" name="customer_phone"
             value="<?php echo htmlspecialchars($order['customer_phone']); ?>">
    </div>

    <div class="mb-3">
      <label class="form-label fw-bold">Địa chỉ giao hàng</label>
      <input type="text" class="form-control" name="customer_address"
             value="<?php echo htmlspecialchars($order['customer_address']); ?>">
    </div>

    <h4 class="fw-bold mt-4">II. Danh sách sản phẩm</h4>
    <table class="table table-bordered mt-3">
      <thead>
        <tr>
          <th>STT</th>
          <th>Tên sản phẩm</th>
          <th>Số lượng</th>
          <th>Đơn giá (VNĐ)</th>
          <th>Thành tiền</th>
          <th>Ẩn khi in</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($products as $i => $product): ?>
  <?php 
    // Thành tiền gốc từ DB
    $subtotal = $product['price'];

    // Đơn giá chuẩn = Thành tiền / số lượng
    $unitPrice = ($product['quantity'] > 0) 
                  ? $subtotal / $product['quantity'] 
                  : 0;
  ?>
  <tr>
    <td><?php echo $i+1; ?></td>
    <td><?php echo htmlspecialchars($product['product_name']); ?></td>
    <td>
      <input type="hidden" name="product_id[]" value="<?php echo $product['id']; ?>">
      <input type="number" class="form-control" name="quantities[]"
             data-index="<?php echo $i; ?>" value="<?php echo $product['quantity']; ?>"
             onchange="calculateTotal(<?php echo $i; ?>)">
    </td>
    <td>
      <input type="text" class="form-control" name="prices[]"
             data-index="<?php echo $i; ?>"
             value="<?php echo number_format($unitPrice,0,',','.'); ?>"
             onchange="calculateTotal(<?php echo $i; ?>)">
    </td>
    <td>
      <input type="text" class="form-control" name="total[]"
             data-index="<?php echo $i; ?>"
             value="<?php echo number_format($subtotal,0,',','.'); ?>">
    </td>
    <td><input type="checkbox" name="hide_product[]" value="<?php echo $product['id']; ?>"></td>
  </tr>
<?php endforeach; ?>

      </tbody>
    </table>

    <h4 class="fw-bold mt-4">III. Thông tin thanh toán</h4>
    <div class="mb-3">
      <label class="form-label fw-bold">Mã giảm giá</label>
      <input type="text" class="form-control" name="discount_code"
             value="<?php echo number_format($discountAmount,0,',','.'); ?>" readonly>
    </div>

    <div class="mb-3">
      <label class="form-label fw-bold">Số tiền giảm (VNĐ)</label>
      <input type="text" class="form-control" id="discountAmountDisplay"
             value="<?php echo number_format($discountAmount,0,',','.'); ?>" readonly>
      <input type="hidden" id="discountAmount" value="<?php echo (int)$discountAmount; ?>">
    </div>

    <div class="mb-3">
      <label class="form-label fw-bold">Tổng trước giảm (VNĐ)</label>
      <input type="text" class="form-control" id="totalBeforeDiscount" value="0" readonly>
    </div>
    <div class="mb-3">
      <label class="form-label fw-bold">Tổng sau giảm (VNĐ)</label>
      <input type="text" class="form-control" id="grandTotal" value="0" readonly>
    </div>

    <div class="mb-3">
      <label class="form-label fw-bold">Hình thức thanh toán</label>
      <select class="form-select" id="paymentMethod" onchange="updatePaymentFields()">
        <option value="">-- Chọn hình thức --</option>
         <option value="bank_droppii">Đã thanh toán trên Droppii</option>
        <option value="bank">Chuyển khoản toàn bộ</option>
        <option value="cash">Tiền mặt toàn bộ</option>
        <option value="mixed">Thanh toán hỗn hợp</option>
        <option value="deposit">Thanh toán đặt cọc</option>
      </select>
    </div>

    <div id="paymentFields" class="mt-3"></div>

    <button type="button" class="btn btn-success mt-4 d-block mx-auto"
            onclick="handleConfirm()">Xác nhận và tiếp tục</button>
  </form>
</div>

<!-- Modal PDF -->
<div class="modal fade" id="pdfModal" tabindex="-1">
  <div class="modal-dialog modal-xl" style="max-width:95%;">
    <div class="modal-content">
      <div class="modal-body" style="height:90vh;">
        <div id="loadingText"
             style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
             font-weight:bold;font-size:1.5rem;color:#555;">Đang tải hoá đơn...</div>
        <iframe id="pdfIframe" src="" style="width:100%;height:100%;" frameborder="0"></iframe>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function unformatCurrency(v){return parseFloat((v||'').replace(/\./g,'').replace(/,/g,''))||0;}

function calcGrandTotal(){
  let sum=0;
  document.querySelectorAll('input[name="total[]"]').forEach(t=>sum+=unformatCurrency(t.value));
  let discount=unformatCurrency(document.getElementById('discountAmount').value||'0');
  let finalTotal=Math.max(sum-discount,0);

  document.getElementById('totalBeforeDiscount').value=sum.toLocaleString('vi-VN');
  document.getElementById('grandTotal').value=finalTotal.toLocaleString('vi-VN');

  return {original:sum,discount,finalTotal};
}

function calculateTotal(i){
  const q=parseInt(document.querySelector(`input[name="quantities[]"][data-index="${i}"]`).value)||0;
  const p=unformatCurrency(document.querySelector(`input[name="prices[]"][data-index="${i}"]`).value);
  document.querySelector(`input[name="total[]"][data-index="${i}"]`).value=(q*p).toLocaleString('vi-VN');
  calcGrandTotal();
}

function updatePaymentFields(){
  const method=document.getElementById('paymentMethod').value;
  const total=calcGrandTotal().finalTotal;
  let html='';
  if(method==='bank') html=`<p class="text-success">Chuyển khoản toàn bộ ${total.toLocaleString('vi-VN')} VNĐ.</p>`;
  if(method==='cash') html=`<p class="text-success">Tiền mặt toàn bộ ${total.toLocaleString('vi-VN')} VNĐ.</p>`;
  if(method==='mixed') html=`<div class="mb-2"><label>Số tiền mặt</label>
      <input type="number" class="form-control" id="cashPart" oninput="calcMixed(${total})"></div>
      <div class="mb-2"><label>Số tiền chuyển khoản</label>
      <input type="text" class="form-control" id="bankPart" readonly></div>`;
  if(method==='deposit') html=`<div class="mb-2"><label>Số tiền đặt cọc</label>
      <input type="number" class="form-control" id="depositAmount"></div>
      <div class="mb-2"><label>Hình thức đặt cọc</label>
      <select class="form-select" id="depositType"><option value="cash">Tiền mặt</option>
      <option value="bank">Online (QR)</option></select></div>`;
  document.getElementById('paymentFields').innerHTML=html;
}

function calcMixed(total){
  const cash=parseInt(document.getElementById('cashPart').value)||0;
  const bank=Math.max(total-cash,0);
  document.getElementById('bankPart').value=bank.toLocaleString('vi-VN');
}

function handleConfirm(){
  const form=document.querySelector('form');
  const method=document.getElementById('paymentMethod').value;

  // Bắt buộc chọn hình thức thanh toán
  if(!method){
    alert("Vui lòng chọn hình thức thanh toán trước khi xác nhận!");
    document.getElementById('paymentMethod').focus();
    return;
  }

  const params=new URLSearchParams();
  params.append('id',form.order_db_id.value);
  params.append('customer_name',form.customer_name.value);
  params.append('customer_address',form.customer_address.value);
  params.append('customer_phone',form.customer_phone.value);
  params.append('created_at',form.created_at.value);
  params.append('note',form.note?.value||'');

  // products
  document.querySelectorAll('input[name="product_id[]"]').forEach((input,i)=>{
    const id=input.value;
    const qty=parseInt(document.querySelectorAll('input[name="quantities[]"]')[i].value)||0;
    const price=unformatCurrency(document.querySelectorAll('input[name="prices[]"]')[i].value);
    const total=unformatCurrency(document.querySelectorAll('input[name="total[]"]')[i].value);
    const hide=document.querySelectorAll('input[name="hide_product[]"]')[i].checked?'1':'0';
    params.append(`quantities[${id}]`,qty);
    params.append(`prices[${id}]`,price);
    params.append(`total[${id}]`,total);
    params.append(`hide_product[${id}]`,hide);
  });

  // discount
  const totals=calcGrandTotal();
  params.append('discount_code',form.discount_code.value);
  params.append('discount_amount',totals.discount);
  params.append('total_before_discount',totals.original);
  params.append('total_after_discount',totals.finalTotal);

  // payment
  params.append('payment_method',method);
  if(method==='bank') params.append('bank_amount',totals.finalTotal);
  if(method==='mixed'){
    const cash=parseInt(document.getElementById('cashPart').value)||0;
    params.append('bank_amount',totals.finalTotal-cash);
  }
  if(method==='deposit'){
    params.append('deposit_amount',parseInt(document.getElementById('depositAmount').value)||0);
    params.append('deposit_type',document.getElementById('depositType').value);
  }

  // load PDF
  const url='print_invoice.php?'+params.toString();
  const iframe=document.getElementById('pdfIframe');
  const loading=document.getElementById('loadingText');
  loading.style.display='block'; iframe.style.display='none';
  iframe.onload=()=>{loading.style.display='none';iframe.style.display='block';};
  iframe.src=url;
  new bootstrap.Modal(document.getElementById('pdfModal')).show();
}


document.addEventListener("DOMContentLoaded",calcGrandTotal);
// Khi modal PDF bị đóng -> quay về admin.php
// Khi modal PDF bị đóng -> quay về trang trước đó
document.getElementById('pdfModal').addEventListener('hidden.bs.modal', function () {
  window.history.back();
});
document.addEventListener("keydown", function(e) {
  if (e.ctrlKey && e.key === "p") {
    e.preventDefault(); // chặn in toàn trang

    let iframe = document.getElementById("pdfIframe");
    if (iframe && iframe.contentWindow) {
      iframe.contentWindow.focus();
      iframe.contentWindow.print(); // chỉ in PDF
    }
  }
});

</script>
</body>
</html>
