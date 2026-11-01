<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Mini Store · Bản đã sửa</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light"><main class="container py-5">
  <a href="/">← Mini Store</a>
  <h1 class="h2 mt-4">Bản đã sửa</h1>
  <p><a data-lab-port="8000" href="/">Bản gốc · localhost:8000</a> ·
    <a data-lab-port="8001" href="/">Bản đã sửa · localhost:8001</a></p>
  <p class="text-muted">Mã nguồn bản đã sửa nằm trong <code>app-fixed/</code>. Bảng dưới liệt kê file đã sửa cho từng lỗi.</p>
  <div class="table-responsive bg-white border rounded p-3">
    <table class="table mb-0"><thead><tr><th>Mục</th><th>Vị trí code đã sửa</th></tr></thead><tbody>
    <?php foreach ([
      ['01 · IDOR / BOLA', 'app-fixed/src/Application.php', 'Các route hồ sơ và đơn hàng'],
      ['02 · Phân quyền', 'app-fixed/src/Application.php', 'Route /api/admin/users'],
      ['03 · SQL Injection', 'app-fixed/src/Application.php', 'Truy vấn tìm kiếm feedback và người dùng'],
      ['04 · Stored XSS', 'app-fixed/templates/feedback.php; app-fixed/templates/app.php', 'Hiển thị nội dung đánh giá'],
      ['05 · Upload, path traversal và LFI', 'app-fixed/src/Application.php; app-fixed/src/CustomerTools.php', 'upload(), giới hạn thư mục và danh sách tài liệu được phép đọc'],
      ['06 · JWT', 'app-fixed/src/Auth.php; scripts/setup.php', 'token(), user() và tạo khóa ký'],
      ['07 · Logic thanh toán', 'app-fixed/src/Application.php', 'Route /api/checkout'],
      ['08 · CORS', 'app-fixed/src/Application.php', 'Xử lý Origin và OPTIONS trong run()'],
      ['09 · Giới hạn đăng nhập', 'app-fixed/src/Auth.php; app-fixed/database/schema.sql', 'login() và bảng login_limits'],
      ['10 · Lộ thông tin', 'app-fixed/public/index.php; app-fixed/src/Application.php', 'Xử lý ngoại lệ và lỗi đăng ký'],
      ['11 · SSTI', 'app-fixed/src/CustomerTools.php', 'gift(): thay thế biến trong lời nhắn, không biên dịch template'],
      ['12 · SSRF', 'app-fixed/src/CustomerTools.php', 'tracking(): giới hạn URL theo nhà vận chuyển, không theo redirect']
    ] as [$name, $source, $location]): ?>
      <tr><td><?= \Lab\Http::escape($name) ?></td><td><code><?= \Lab\Http::escape($source) ?></code><div class="small text-muted"><?= \Lab\Http::escape($location) ?></div></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
</main><script>
  for (const link of document.querySelectorAll('[data-lab-port]')) {
    const url = new URL(link.getAttribute('href'), location.origin);
    url.port = link.dataset.labPort;
    link.href = url.href;
  }
</script></body></html>
