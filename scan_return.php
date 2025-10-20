<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <title>Quét SN & Hiển thị Đơn hàng</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="logoblack.ico" type="image/x-icon">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body { font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; margin: 0; }
    .order-code-btn { background-color: #007bff; color: #fff; border: none; padding: 10px 20px; border-radius: 5px; width: 100%; max-width: 520px; margin: 0 auto 1rem; display: block; }
    .order-code-btn:hover { background-color: #0056b3; }
    .scanner-wrapper { position: relative; width: 100%; max-width: 520px; margin: 0 auto 1rem; }
    .scanner-overlay { position: absolute; top: 50%; left: 50%; width: 180px; height: 120px; transform: translate(-50%, -50%); border: 2px dashed rgba(255,0,0,0.7); border-radius: 8px; pointer-events: none; z-index: 10; }
    #qr-reader { width: 100%; border: 2px solid #333; border-radius: 8px; overflow: hidden; }
    #scan-message { width: 100%; max-width: 520px; margin: 0 auto 1rem; text-align: center; min-height: 1.5em; font-weight: 500; }
    .result-table-wrapper { max-width: 980px; margin: 0 auto; }
    .action-cell { white-space: nowrap; }
    .small-muted { font-size: 12px; color: #666; }
    @media (max-width: 576px) {
      .scanner-overlay { width: 140px; height: 90px; }
      #scan-message { font-size: 0.9em; }
    }
  </style>
</head>
<body>

  <button class="order-code-btn">
    <i class="fas fa-qrcode me-1"></i>
    Vui lòng quét mã vạch hoặc QR sản phẩm trả về!
  </button>

  <div class="scanner-wrapper">
    <div id="qr-reader"></div>
    <div class="scanner-overlay"></div>
  </div>

  <div id="scan-message"></div>

  <!-- Nhập tay (fallback) -->
  <div class="d-flex justify-content-center mb-3 gap-2" style="gap:8px">
    <input id="manualSN" class="form-control" style="max-width:320px" placeholder="Nhập SN hoặc dán URL có ?serial=">
    <button id="manualAdd" class="btn btn-outline-primary">Thêm</button>
  </div>

  <div class="result-table-wrapper">
    <div class="table-responsive">
      <table id="scan-result-table" class="table table-striped table-bordered align-middle">
        <thead class="table-dark">
          <tr>
            <th style="width:48px">#</th>
            <th>SN</th>
            <th>Mã đơn</th>
            <th>Khách hàng</th>
            <th>Điện thoại</th>
            <th>Sản phẩm</th>
            <th class="text-end">SL</th>
            <th>Ngày quét</th>
            <th class="action-cell">Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <!-- JS sẽ chèn dòng vào đây -->
        </tbody>
      </table>
    </div>

    <!-- Thêm nút quay về -->
<div class="d-flex justify-content-between mt-3">
  <div class="small-muted" id="rowCount">0 mục</div>
  <div>
    <a href="xem_donhang.php" class="btn btn-secondary me-2">
      <i class="fas fa-arrow-left"></i> Quay về
    </a>
    <button id="clearAll" class="btn btn-light me-2">
      <i class="fas fa-broom"></i> Xoá tất cả
    </button>
    <button id="confirmReturn" class="btn btn-warning">
      <i class="fas fa-undo-alt"></i> Xác nhận hoàn hàng
    </button>
  </div>
</div>

<!-- Thêm màn hình thông báo -->
<div id="successScreen" class="text-center p-5" style="display:none">
  <div class="alert alert-success">
    <h4 class="alert-heading">✅ Hoàn tất!</h4>
    <p>Các sản phẩm đã được đánh dấu hoàn hàng thành công.</p>
  </div>
  <a href="xem_donhang.php" class="btn btn-primary">
    <i class="fas fa-arrow-left"></i> Về trang đơn hàng
  </a>
</div>


  <!-- Âm thanh phản hồi -->
  <audio id="scanSound" src="beep.mp3" preload="auto"></audio>
  <audio id="scanSoundError" src="beepstop.mp3" preload="auto"></audio>

  <!-- html5-qrcode -->
  <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
  <!-- FontAwesome (cho icon) -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>

  <script>
    const scanSound      = document.getElementById('scanSound');
    const scanSoundError = document.getElementById('scanSoundError');
    const msgEl          = document.getElementById('scan-message');
    const tableBody      = document.querySelector('#scan-result-table tbody');
    const rowCountEl     = document.getElementById('rowCount');
    const confirmBtn     = document.getElementById('confirmReturn');
    const clearBtn       = document.getElementById('clearAll');
    const manualSN       = document.getElementById('manualSN');
    const manualAdd      = document.getElementById('manualAdd');

    const scannedSet     = new Set();   // tránh trùng theo SN
    let lastProcessedSN  = null;

    function showMessage(text, isError = false) {
      msgEl.textContent = text;
      msgEl.style.color = isError ? '#b02a37' : '#155724';
    }
    function clearMessage() { showMessage(''); }

    function playOk()    { try { scanSound.currentTime = 0; scanSound.play(); } catch{} }
    function playError() { try { scanSoundError.currentTime = 0; scanSoundError.play(); } catch{} }

    function normalizeKey(k){
      return k.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
    }

    // Bóc SN từ chuỗi: hỗ trợ URL ?serial=, ?sn=, ?s=, “SN: ...”, chỉ số, mã chữ-số
    function extractSN(raw) {
      if (!raw) return '';
      raw = String(raw).trim();

      // Nếu là URL -> ưu tiên query serial/sn/s
      try {
        const u = new URL(raw);
        for (const [k, v] of u.searchParams.entries()) {
          const nk = normalizeKey(k);
          if (['serial','sn','s'].includes(nk)) {
            const sv = (v || '').trim();
            if (sv) return sv.replace(/[^0-9A-Za-z]/g,'');
          }
        }
      } catch {}

      // Dạng "SN: 123..." -> lấy số sau SN:
      if (/^SN:/i.test(raw)) {
        const d = raw.replace(/^SN:\s*/i,'').replace(/[^0-9A-Za-z]/g,'');
        if (d) return d;
      }

      // Nếu toàn số/chữ-số -> giữ lại chữ-số (loại ký tự khác)
      const cleaned = raw.replace(/[^0-9A-Za-z]/g,'');
      return cleaned;
    }

    function updateRowCount(){
      const n = tableBody.querySelectorAll('tr').length;
      rowCountEl.textContent = n + ' mục';
    }

    // Thêm một hàng; lưu snapshot ở dataset để gửi lên server
    function appendRow(info, sn) {
      const idx = tableBody.children.length + 1;
      const tr  = document.createElement('tr');

      // Chuẩn hoá các field (fallback rỗng)
      const item = {
        sn: sn || '',
        order_code2: info.order_code2 || '',
        customer_name: info.customer_name || '',
        customer_phone: info.customer_phone || '',
        product_name: info.product_name || '',
        quantity: Number(info.quantity || 1),
        scan_date: info.scan_date || new Date().toISOString().slice(0,19).replace('T',' '),
        order_id: info.order_id || null,
        warranty_id: info.warranty_id || null,
        order_product_id: info.order_product_id || null
      };

      // Lưu snapshot vào dataset (JSON)
      tr.dataset.item = JSON.stringify(item);

      tr.innerHTML = `
        <td class="text-muted">${idx}</td>
        <td><code>${item.sn}</code></td>
        <td>${escapeHtml(item.order_code2)}</td>
        <td>${escapeHtml(item.customer_name)}</td>
        <td>${escapeHtml(item.customer_phone)}</td>
        <td>${escapeHtml(item.product_name)}</td>
        <td class="text-end">${item.quantity}</td>
        <td>${escapeHtml(item.scan_date)}</td>
        <td class="action-cell">
          <button class="btn btn-sm btn-outline-danger btn-del" title="Xoá dòng">
            <i class="fas fa-trash"></i>
          </button>
        </td>
      `;

      // Sự kiện xoá dòng
      tr.querySelector('.btn-del').addEventListener('click', () => {
        scannedSet.delete(item.sn);
        tr.remove();
        renumberRows();
        updateRowCount();
      });

      tableBody.prepend(tr);
      renumberRows();
      updateRowCount();
    }

    function renumberRows(){
      const rows = Array.from(tableBody.querySelectorAll('tr'));
      rows.forEach((tr, i) => tr.querySelector('td:first-child').textContent = rows.length - i);
    }

    // Fetch thông tin theo SN
    async function fetchInfoBySN(sn) {
      // Nếu bạn đặt file cùng domain, nên dùng đường dẫn tương đối để tránh CORS.
      const url = `https://kuchenvietnam.vn/kuchen/khokuchen/api/find_order_by_sn.php?sn=${encodeURIComponent(sn)}`;
      const res = await fetch(url, { cache: 'no-store' });
      if (!res.ok) {
        let err = {};
        try { err = await res.json(); } catch {}
        throw new Error(err.error || `HTTP ${res.status}`);
      }
      const info = await res.json();
      // Kỳ vọng có tối thiểu order_code2; nếu không, coi như không tìm thấy
      if (!info || !info.order_code2) {
        throw new Error('Không tìm thấy đơn cho SN này');
      }
      return info;
    }

    async function processSNInput(raw){
      clearMessage();
      const sn = extractSN(raw);

      if (!sn) {
        showMessage('❌ SN không hợp lệ', true);
        playError();
        return;
      }
      if (scannedSet.has(sn)) {
        showMessage(`⚠️ SN "${sn}" đã quét rồi`, true);
        playError();
        return;
      }

      try {
        const info = await fetchInfoBySN(sn);
        scannedSet.add(sn);
        appendRow(info, sn);
        playOk();
      } catch (e) {
        showMessage('❌ ' + e.message, true);
        playError();
      }
    }

    // ========== QR setup ==========
    const html5QrCode = new Html5Qrcode("qr-reader");
    const config = {
      fps: 20,
      qrbox: { width: 320, height: 120 },
      formatsToSupport: [
        Html5QrcodeSupportedFormats.QR_CODE,
        Html5QrcodeSupportedFormats.CODE_128,
        Html5QrcodeSupportedFormats.CODE_39,
        Html5QrcodeSupportedFormats.EAN_13
      ]
    };

    function startQrScanner() {
      html5QrCode.start(
        { facingMode: { exact: "environment" } },
        config,
        onScanSuccess,
        err => console.warn("Lỗi tạm thời:", err)
      ).catch(err1 => {
        console.warn("Không hỗ trợ exact environment:", err1);
        html5QrCode.start(
          { facingMode: "environment" },
          config,
          onScanSuccess,
          err => console.warn("Lỗi quét:", err)
        ).catch(err2 => {
          console.warn("Không khởi động được với facingMode:", err2);
          Html5Qrcode.getCameras()
            .then(devices => {
              if (!devices.length) return showMessage("Không tìm thấy camera", true);
              let cameraId;
              if (devices.length === 2) cameraId = devices[1].id;
              else {
                const rear = devices.find(d => /back|rear|environment/i.test(d.label));
                cameraId = rear ? rear.id : devices[0].id;
              }
              html5QrCode.start(
                cameraId, config, onScanSuccess,
                err => console.error("Lỗi quét:", err)
              ).catch(err3 => {
                console.error("Không thể khởi động camera:", err3);
                showMessage("❌ Không thể khởi động camera", true);
              });
            })
            .catch(err4 => {
              console.error("Không lấy được danh sách camera:", err4);
              showMessage("❌ Lỗi khi truy cập camera", true);
            });
        });
      });
    }

    async function onScanSuccess(decodedText) {
      const sn = extractSN(decodedText);
      if (sn === lastProcessedSN) return; // chặn spam frame
      lastProcessedSN = sn;
      await processSNInput(sn);
      // reset lastProcessedSN sau 600ms để không bỏ sót mã mới
      setTimeout(() => { lastProcessedSN = null; }, 600);
    }

    startQrScanner();

    // ========== Manual input ==========
    manualAdd.addEventListener('click', () => {
      const raw = manualSN.value.trim();
      if (!raw) return;
      processSNInput(raw).finally(() => manualSN.value = '');
    });
    manualSN.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        manualAdd.click();
      }
    });

    // ========== Confirm ==========
    confirmBtn.addEventListener('click', async () => {
      const rows = Array.from(tableBody.querySelectorAll('tr'));
      if (!rows.length) {
        showMessage('⚠️ Chưa có dòng nào để hoàn.', true);
        return;
      }

      const orderCodesSet   = new Set();
      const warrantyCodesSet= new Set();
      const items           = [];

      for (const tr of rows) {
        const item = JSON.parse(tr.dataset.item || '{}');
        if (item.order_code2) orderCodesSet.add(item.order_code2);
        if (item.sn)          warrantyCodesSet.add(item.sn);
        items.push(item);
      }

      confirmBtn.disabled = true;
      confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Đang cập nhật…';

      try {
        const res = await fetch('update_return.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            // Tương thích cũ:
            order_codes: Array.from(orderCodesSet),
            warranty_codes: Array.from(warrantyCodesSet),
            reason: "Khách hoàn trả hàng về kho chờ phê duyệt",
            // Mới (snapshot; backend có thể đọc để lưu return_history):
            items: items
          })
        });

        const j = await res.json();
        if (!res.ok) throw new Error(j.error || 'HTTP ' + res.status);

        showMessage(`✅ Đã đánh dấu ${j.updated ?? (j.history_records_inserted || 0)} dòng dữ liệu đã quét.`, false);
        // Tuỳ bạn: có thể clear bảng sau khi đánh dấu
        // clearAllRows();
        // Ẩn bảng kết quả + hiển thị màn hình thành công
document.querySelector('.result-table-wrapper').style.display = 'none';
document.getElementById('successScreen').style.display = 'block';
      } catch (e) {
        showMessage('❌ ' + e.message, true);
      } finally {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-undo-alt"></i> Xác nhận hoàn hàng';
      }
    });

    // ========== Clear ==========
    function clearAllRows(){
      tableBody.innerHTML = '';
      scannedSet.clear();
      updateRowCount();
    }
    clearBtn.addEventListener('click', clearAllRows);

    // ========== Helpers ==========
    function escapeHtml(s){
      if (s == null) return '';
      return String(s)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
    }
  </script>
</body>
</html>
