<?php
$guides = require __DIR__ . '/lab-guide.php';
$defaultLabs = ['/orders' => '01', '/profile' => '01', '/admin' => '02', '/feedback' => '03', '/avatar' => '05', '/dashboard' => '06', '/checkout' => '07', '/login' => '09', '/register' => '10'];
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$selectedLab = is_string($_GET['lab'] ?? null) ? $_GET['lab'] : ($defaultLabs[$currentPath] ?? '01');
$selectedLab = array_key_exists($selectedLab, $guides) ? $selectedLab : '01';
$guide = $guides[$selectedLab];
$isVulnerable = $mode === 'vulnerable';
$otherPort = $isVulnerable ? '8001' : '8000';
$guideUrl = $guide['route'] . '?lab=' . $selectedLab;
?>
<?php if (in_array($currentPath, ['/login', '/register'], true)): ?>
<div class="alert alert-info">
  <strong><?= $isVulnerable ? 'Bản có lỗ hổng · 8000' : 'Bản đã sửa · 8001' ?></strong>
  <?php if (isset($_GET['next'])): ?>
    · Đăng nhập bên dưới để tiếp tục bài <?= $selectedLab ?>: <?= \Lab\Http::escape($guide['name']) ?>.
  <?php else: ?>
    · Đăng nhập để vào các chức năng thực hành. <a href="/#findings">Xem hướng dẫn 10 bài</a>.
  <?php endif; ?>
</div>
<?php return; endif; ?>
<aside class="surface p-4 mb-4 border-start border-4 <?= $isVulnerable ? 'border-danger' : 'border-success' ?>" aria-label="Hướng dẫn thực hành">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-2">
    <h2 class="h5 mb-0">Bài <?= $selectedLab ?> · <?= \Lab\Http::escape($guide['name']) ?></h2>
    <span class="badge <?= $isVulnerable ? 'text-bg-danger' : 'text-bg-success' ?>"><?= $isVulnerable ? 'ĐANG Ở BẢN CÓ LỖ HỔNG · 8000' : 'ĐANG Ở BẢN ĐÃ SỬA · 8001' ?></span>
  </div>
  <p class="mb-2"><strong>Test ở đâu:</strong> <?= \Lab\Http::escape($guide['page']) ?>. <?= \Lab\Http::escape($guide['steps']) ?></p>
  <p class="mb-2"><strong>Dữ liệu / lệnh thử:</strong></p>
  <pre class="bg-light border rounded p-3"><?= \Lab\Http::escape($guide['payload']) ?></pre>
  <div class="row g-3 mb-3">
    <div class="col-md-6"><div class="border rounded p-3 h-100"><strong class="text-danger">Kỳ vọng ở bản lỗi</strong><p class="mb-0 mt-1"><?= \Lab\Http::escape($guide['vulnerable']) ?></p></div></div>
    <div class="col-md-6"><div class="border rounded p-3 h-100"><strong class="text-success">Kỳ vọng ở bản fixed</strong><p class="mb-0 mt-1"><?= \Lab\Http::escape($guide['fixed']) ?></p></div></div>
  </div>
  <p class="small text-muted">Đây là kết quả kỳ vọng để đối chiếu. Phản hồi thực tế xuất hiện khi bạn thực hiện thao tác bên dưới.</p>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-outline-primary" href="<?= $currentPath === $guide['route'] ? '#view' : \Lab\Http::escape($guideUrl) ?>"><?= $currentPath === $guide['route'] ? 'Đến ô thao tác bên dưới ↓' : 'Đến đúng trang test bài ' . $selectedLab ?></a>
    <a class="btn btn-sm btn-outline-secondary" data-lab-port="<?= $otherPort ?>" href="<?= \Lab\Http::escape($guideUrl) ?>"><?= $isVulnerable ? 'Mở cùng bài ở bản fixed → 8001' : 'Mở cùng bài ở bản lỗi → 8000' ?></a>
    <a class="btn btn-sm btn-outline-secondary" href="/#lab-<?= $selectedLab ?>">Xem bản đồ 10 lỗ hổng</a>
  </div>
  <?php if ($currentPath === '/feedback'): ?>
  <p class="small mt-3 mb-0"><a href="/feedback?lab=03">03 · SQL Injection: ô tìm kiếm bên phải</a> · <a href="/feedback?lab=04">04 · Stored XSS: ô gửi nội dung bên trái</a> · <a href="/feedback?lab=10">10 · Lộ thông tin: chi tiết lỗi</a></p>
  <?php endif; ?>
  <details class="mt-3"><summary class="fw-semibold">Lỗi ở đâu trong code? Sửa thế nào?</summary>
    <p class="mt-2 mb-1"><?= \Lab\Http::escape($guide['cause']) ?></p>
    <p class="small"><code>app-vulnerable/<?= \Lab\Http::escape($guide['source']) ?></code><br><code>app-fixed/<?= \Lab\Http::escape($guide['source']) ?></code></p>
    <strong class="text-danger">Cách viết có lỗi (đoạn rút gọn)</strong><pre class="bg-light border rounded p-3 mt-1"><?= \Lab\Http::escape($guide['before']) ?></pre>
    <strong class="text-success">Cách sửa (đoạn rút gọn)</strong><pre class="bg-light border rounded p-3 mt-1 mb-0"><?= \Lab\Http::escape($guide['after']) ?></pre>
  </details>
</aside>
