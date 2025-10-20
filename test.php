<?php
// Nếu là AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');

    $text = trim($_GET['text'] ?? '');
    if ($text === '') {
        echo json_encode(['error' => 'Thiếu tham số text']);
        exit;
    }

    // Các tham số API mới
    $params = [
        'language'          => 'vi',
        'key'               => 'public_key', // ⚠️ thay bằng key thật
        'query'             => $text,
        'new_admin'         => 'true',
        'include_old_admin' => 'true',
    ];

    $url = 'https://maps.track-asia.com/api/v2/place/textsearch/json';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url . '?' . http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        echo json_encode(['error' => curl_error($ch)]);
        curl_close($ch);
        exit;
    }
    curl_close($ch);

    $data = json_decode($response, true);
    $results = [];

    if (isset($data['results'])) {
        foreach ($data['results'] as $f) {
            // Chỉ lấy 2 cấp hành chính: Tỉnh/Thành & Phường/Xã
            $province = $ward = '';
            if (!empty($f['address_components'])) {
                foreach ($f['address_components'] as $comp) {
                    if (in_array('administrative_area_level_1', $comp['types'] ?? [])) {
                        $province = $comp['long_name'] ?? '';
                    }
                    if (
                        in_array('administrative_area_level_2', $comp['types'] ?? []) || 
                        in_array('administrative_area_level_3', $comp['types'] ?? []) || 
                        in_array('locality', $comp['types'] ?? [])
                    ) {
                        $ward = $comp['long_name'] ?? '';
                    }
                }
            }

            $results[] = [
                'label'    => $f['formatted_address'] ?? '',
                'province' => $province,
                'ward'     => $ward,
            ];
        }
    }

    echo json_encode(['ok' => true, 'items' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Tìm kiếm địa chỉ</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 4 -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
    body { background:#f8f9fa; }
    .result-card { border:1px solid #e0e0e0; border-radius:4px; padding:12px; margin-bottom:8px; background:#fff; }
    .result-card h6 { margin:0 0 4px; }
    .result-card small { color:#666; }
  </style>
</head>
<body>
<div class="container py-5">
  <h3 class="mb-4">🔍 Tra cứu địa chỉ</h3>
  <form id="searchForm" class="form-inline mb-4">
    <input type="text" id="text" class="form-control mr-2 flex-fill" placeholder="Nhập địa chỉ (VD: 2 Nguyễn Huệ, Sài Gòn)">
    <button class="btn btn-primary" type="submit">Tìm kiếm</button>
  </form>

  <div id="results"></div>
</div>

<script>
document.getElementById('searchForm').addEventListener('submit', function(e){
    e.preventDefault();
    const text = document.getElementById('text').value.trim();
    if (!text) return;

    const resultsDiv = document.getElementById('results');
    resultsDiv.innerHTML = '<div class="text-muted">Đang tìm kiếm...</div>';

    fetch('?ajax=1&text=' + encodeURIComponent(text))
      .then(r => r.json())
      .then(d => {
        if (!d.ok) {
          resultsDiv.innerHTML = '<div class="alert alert-warning">Không tìm thấy kết quả.</div>';
          return;
        }
        if (d.items.length === 0) {
          resultsDiv.innerHTML = '<div class="alert alert-info">Không có kết quả phù hợp.</div>';
          return;
        }
        let html = '';
        d.items.forEach(it => {
         html += `
  <div class="result-card">
    <h6>${it.label}</h6>
    <small>
      🏙️ Tỉnh/Thành: ${it.province || '-'}<br>
      🏠 Phường/Xã: ${it.ward || '-'}
    </small>
  </div>`;
        });
        resultsDiv.innerHTML = html;
      })
      .catch(err => {
        resultsDiv.innerHTML = '<div class="alert alert-danger">Lỗi: '+err+'</div>';
      });
});
</script>
</body>
</html>
