<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= \Lab\Http::escape($title) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root { --ink: #172033; --muted: #697386; --line: #d8dee8; --soft: #f5f7fb; --accent: #0f766e; --warn: #b42318; }
    body { color: var(--ink); background: var(--soft); }
    .topbar { background: #101828; }
    .brand-dot { width: 12px; height: 12px; border-radius: 50%; background: #ef4444; display: inline-block; }
    .page-shell { max-width: 1180px; margin: 0 auto; padding: 28px 16px 48px; }
    .surface { background: #fff; border: 1px solid var(--line); border-radius: 8px; }
    .metric { min-height: 96px; }
    .muted { color: var(--muted); }
    .table td, .table th { vertical-align: middle; }
    .avatar-preview { width: 96px; height: 96px; object-fit: cover; border-radius: 8px; border: 1px solid var(--line); background: #eef2f7; }
    .banner { background: linear-gradient(135deg, #fff, #eef8f6); border-bottom: 1px solid var(--line); }
    .status-pill { border: 1px solid #fecaca; color: var(--warn); background: #fff5f5; border-radius: 999px; padding: 4px 10px; font-size: 13px; }
    pre { white-space: pre-wrap; word-break: break-word; }
  </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-dark topbar">
    <div class="container-fluid px-3 px-lg-4">
      <a class="navbar-brand d-flex align-items-center gap-2" href="/dashboard">
        <span class="brand-dot"></span>
        <span>Mini Store</span>
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="navMenu">
        <div class="navbar-nav me-auto">
          <a class="nav-link" href="/">Trang chủ</a>
          <a class="nav-link" href="/dashboard">Dashboard</a>
          <a class="nav-link" href="/profile">Profile</a>
          <a class="nav-link" href="/orders">Orders</a>
          <a class="nav-link" href="/avatar">Avatar</a>
          <a class="nav-link" href="/feedback">Feedback</a>
          <a class="nav-link" href="/checkout">Checkout</a>
          <a class="nav-link" href="/gift-card">Thiệp quà tặng</a>
          <a class="nav-link" href="/shipping">Vận đơn</a>
          <a class="nav-link" href="/help">Trợ giúp</a>
          <a class="nav-link" href="/admin">Admin</a>
          <a class="nav-link" data-lab-port="8001" href="/fixes">Bản đã sửa</a>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span class="status-pill"><?= \Lab\Http::escape($mode) ?></span>
          <span id="navUser" class="text-white-50 small"></span>
          <button id="logoutBtn" class="btn btn-sm btn-outline-light" type="button">Logout</button>
        </div>
      </div>
    </div>
  </nav>

  <section class="banner">
    <div class="page-shell py-4">
      <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
        <div>
          <h1 class="h3 mb-1"><?= \Lab\Http::escape($title) ?></h1>
          <p class="mb-0 muted">Mua sắm và quản lý tài khoản của bạn.</p>
        </div>
        <div class="text-lg-end small muted">
          Sample login: <strong><?= \Lab\Http::escape($login_hint) ?></strong>
        </div>
      </div>
    </div>
  </section>

  <main class="page-shell">
    <div id="alertBox" class="alert d-none" role="alert"></div>
    <div id="view"></div>
  </main>

  <script>
    const MODE = "<?= \Lab\Http::escape($mode) ?>";
    const CHECKOUT_ALLOWS_AMOUNT = <?= $checkout_allows_amount ? "true" : "false" ?>;
    const state = { token: localStorage.getItem(`${MODE}_token`) || "", user: null };
    const view = document.getElementById("view");
    const alertBox = document.getElementById("alertBox");
    const navUser = document.getElementById("navUser");

    function setAlert(message, type = "danger") {
      alertBox.className = `alert alert-${type}`;
      alertBox.textContent = message;
      if (!message) alertBox.classList.add("d-none");
    }

    function authHeaders(extra = {}) {
      const headers = { ...extra };
      if (state.token) headers.Authorization = `Bearer ${state.token}`;
      return headers;
    }

    async function api(path, options = {}) {
      const response = await fetch(path, {
        ...options,
        headers: authHeaders(options.headers || {})
      });
      const text = await response.text();
      let body = null;
      try { body = text ? JSON.parse(text) : null; } catch { body = text; }
      if (!response.ok) {
        const detail = body && body.detail ? body.detail : JSON.stringify(body);
        const error = new Error(`HTTP ${response.status}: ${detail}`);
        error.status = response.status;
        error.body = body;
        throw error;
      }
      return body;
    }

    function saveToken(token) {
      state.token = token;
      localStorage.setItem(`${MODE}_token`, token);
    }

    function logout() {
      localStorage.removeItem(`${MODE}_token`);
      state.token = "";
      state.user = null;
      location.href = "/login";
    }

    function requireLogin() {
      if (!state.token && !["/login", "/register"].includes(location.pathname)) {
        location.href = '/login?next=' + encodeURIComponent(location.pathname + location.search);
        return false;
      }
      return true;
    }

    async function loadMe() {
      if (!state.token) return null;
      try {
        state.user = await api("/api/me");
        navUser.textContent = `${state.user.username} (${state.user.role})`;
        return state.user;
      } catch {
        localStorage.removeItem(`${MODE}_token`);
        state.token = "";
        navUser.textContent = "";
        return null;
      }
    }

    function html(strings, ...values) {
      return strings.map((s, i) => s + (values[i] ?? "")).join("");
    }

    function esc(value) {
      return String(value ?? "").replace(/[&<>"']/g, (char) => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        "\"": "&quot;",
        "'": "&#39;"
      }[char]));
    }

    function authForm(type) {
      const isLogin = type === "login";
      view.innerHTML = html`
        <div class="row justify-content-center">
          <div class="col-md-7 col-lg-5">
            <form id="authForm" class="surface p-4">
              <h2 class="h4 mb-3">${isLogin ? "Login" : "Register"}</h2>
              <label class="form-label">Username</label>
              <input class="form-control mb-3" name="username" required value="${isLogin ? "alice" : ""}">
              <label class="form-label">Password</label>
              <input class="form-control mb-3" name="password" type="password" required value="${isLogin ? "<?= $mode === "vulnerable" ? "alice123" : "alice12345" ?>" : ""}">
              ${isLogin ? "" : `
                <label class="form-label">Email</label>
                <input class="form-control mb-3" name="email" type="email" required>
                <label class="form-label">Full name</label>
                <input class="form-control mb-3" name="full_name">
              `}
              <button class="btn btn-primary w-100" type="submit">${isLogin ? "Login" : "Create account"}</button>
              <div class="mt-3 small">
                ${isLogin ? `<a href="/register">Create account</a>` : `<a href="/login">Back to login</a>`}
              </div>
            </form>
          </div>
        </div>`;
      document.getElementById("authForm").addEventListener("submit", async (event) => {
        event.preventDefault();
        setAlert("");
        const data = Object.fromEntries(new FormData(event.target).entries());
        try {
          if (isLogin) {
            const result = await api("/api/login", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ username: data.username, password: data.password })
            });
            saveToken(result.access_token);
            const target = new URL(new URLSearchParams(location.search).get("next") || "/dashboard", location.origin);
            location.href = target.origin === location.origin ? target.href : "/dashboard";
          } else {
            await api("/api/register", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify(data)
            });
            setAlert("Account created. You can log in now.", "success");
            location.href = "/login";
          }
        } catch (error) {
          setAlert(error.message);
        }
      });
    }

    async function dashboard() {
      const me = await loadMe();
      const orders = await api("/api/orders");
      view.innerHTML = html`
        <div class="row g-3 mb-4">
          <div class="col-md-4"><div class="surface p-3 metric"><div class="muted small">Signed in as</div><div class="h4">${esc(me.username)}</div><div>${esc(me.email)}</div></div></div>
          <div class="col-md-4"><div class="surface p-3 metric"><div class="muted small">Role</div><div class="h4">${esc(me.role)}</div><div>Your account membership.</div></div></div>
          <div class="col-md-4"><div class="surface p-3 metric"><div class="muted small">Orders</div><div class="h4">${orders.length}</div><div><a href="/orders">View orders</a></div></div></div>
        </div>
        <div class="surface p-4">
          <h2 class="h5 mb-3">Recent orders</h2>
          ${ordersTable(orders)}
        </div>`;
    }

    function ordersTable(orders) {
      if (!orders.length) return `<p class="muted mb-0">No orders found.</p>`;
      return html`
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>ID</th><th>Item</th><th>Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
              ${orders.map(order => `
                <tr>
                  <td>${esc(order.id)}</td>
                  <td>${esc(order.item)}</td>
                  <td>$${Number(order.amount).toFixed(2)}</td>
                  <td>${esc(order.status)}</td>
                  <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/orders/${order.id}/view">Open</a></td>
                </tr>`).join("")}
            </tbody>
          </table>
        </div>`;
    }

    async function profile() {
      const me = await loadMe();
      view.innerHTML = html`
        <form id="profileForm" class="surface p-4">
          <h2 class="h5 mb-3">Profile</h2>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Username</label>
              <input class="form-control" value="${esc(me.username)}" disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label">Role</label>
              <input class="form-control" value="${esc(me.role)}" disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input class="form-control" name="email" value="${esc(me.email)}">
            </div>
            <div class="col-md-6">
              <label class="form-label">Full name</label>
              <input class="form-control" name="full_name" value="${esc(me.full_name)}">
            </div>
          </div>
          <button class="btn btn-primary mt-3" type="submit">Save profile</button>
        </form>`;
      document.getElementById("profileForm").addEventListener("submit", async (event) => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.target).entries());
        try {
          await api("/api/me", { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify(data) });
          setAlert("Profile saved.", "success");
        } catch (error) {
          setAlert(error.message);
        }
      });
    }

    async function orders() {
      const list = await api("/api/orders");
      view.innerHTML = html`
        <div class="surface p-4"><h2 class="h5 mb-3">Đơn hàng của bạn</h2>${ordersTable(list)}</div>`;
    }

    async function orderDetail() {
      const match = location.pathname.match(/\/orders\/(\d+)\/view/);
      const orderId = match ? match[1] : "1";
      try {
        const order = await api(`/api/orders/${orderId}`);
        view.innerHTML = html`
          <div class="surface p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h2 class="h5 mb-0">Order #${esc(order.id)}</h2>
              <a class="btn btn-sm btn-outline-secondary" href="/orders">Back</a>
            </div>
            <dl class="row mb-0">
              <dt class="col-sm-3">Owner user ID</dt><dd class="col-sm-9">${esc(order.user_id)}</dd>
              <dt class="col-sm-3">Item</dt><dd class="col-sm-9">${esc(order.item)}</dd>
              <dt class="col-sm-3">Amount</dt><dd class="col-sm-9">$${Number(order.amount).toFixed(2)}</dd>
              <dt class="col-sm-3">Status</dt><dd class="col-sm-9">${esc(order.status)}</dd>
            </dl>
          </div>`;
      } catch (error) {
        view.innerHTML = `<div class="surface p-4"><h2 class="h5">Order #${esc(orderId)}</h2><pre class="mb-0">${esc(error.message)}</pre></div>`;
      }
    }

    async function avatar() {
      const me = await loadMe();
      view.innerHTML = html`
        <div class="surface p-4">
          <h2 class="h5 mb-3">Avatar</h2>
          <div class="d-flex align-items-center gap-3 mb-3">
            <img class="avatar-preview" src="${esc(me.avatar || "https://placehold.co/160x160/eef2f7/172033?text=Avatar")}" alt="Avatar preview">
            <div><div class="fw-semibold">${esc(me.username)}</div><div class="muted small">${esc(me.avatar || "No avatar uploaded")}</div></div>
          </div>
          <form id="avatarForm">
            <label class="form-label" for="uploadFolder">Thư mục</label>
            <select id="uploadFolder" class="form-select mb-3" name="folder"><option value="">Chung</option><option value="profile">Hồ sơ</option></select>
            <input class="form-control mb-3" type="file" name="file" required>
            <button class="btn btn-primary" type="submit">Upload avatar</button>
          </form>
        </div>`;
      document.getElementById("avatarForm").addEventListener("submit", async (event) => {
        event.preventDefault();
        const form = new FormData(event.target);
        try {
          const result = await fetch("/api/avatar", { method: "POST", headers: authHeaders(), body: form });
          const body = await result.json();
          if (!result.ok) throw new Error(`HTTP ${result.status}: ${body.detail || JSON.stringify(body)}`);
          setAlert(`Uploaded: ${body.avatar_url}`, "success");
          setTimeout(() => location.reload(), 500);
        } catch (error) {
          setAlert(error.message);
        }
      });
    }

    async function feedback() {
      view.innerHTML = html`
        <div class="row g-3">
          <div class="col-lg-5">
            <form id="feedbackForm" class="surface p-4">
              <h2 class="h5 mb-3">Chia sẻ trải nghiệm</h2>
              <label class="form-label" for="feedbackMessage">Đánh giá của bạn</label>
              <textarea id="feedbackMessage" class="form-control mb-3" name="message" rows="5" required></textarea>
              <button class="btn btn-primary" type="submit">Gửi đánh giá</button>
              <a class="btn btn-outline-secondary ms-2" href="/feedback-wall">Tất cả đánh giá</a>
            </form>
          </div>
          <div class="col-lg-7"><div class="surface p-4">
            <h2 class="h5 mb-3">Đánh giá khách hàng</h2>
            <form id="searchFeedback" class="d-flex gap-2 mb-3">
              <input class="form-control" name="search" placeholder="Tìm kiếm đánh giá" aria-label="Tìm kiếm đánh giá">
              <button class="btn btn-outline-primary flex-shrink-0" type="submit">Tìm kiếm</button>
            </form>
            <div id="feedbackList" aria-live="polite"></div>
          </div></div>
        </div>`;
      let requestNumber = 0;
      async function loadFeedback(search = '') {
        const currentRequest = ++requestNumber;
        const list = document.getElementById('feedbackList');
        list.textContent = 'Đang tải đánh giá…';
        try {
          const items = await api('/api/feedback?search=' + encodeURIComponent(search));
          if (currentRequest !== requestNumber) return;
          if (!items.length) {
            list.textContent = 'Không tìm thấy đánh giá phù hợp.';
            return;
          }
          list.innerHTML = items.map(item => `
            <article class="border rounded-2 p-3 mb-2">
              <div class="small muted">${esc(item.username || 'user')} · ${esc(item.created_at)}</div>
              <div class="feedback-message"></div>
            </article>`).join('');
          [...list.querySelectorAll('.feedback-message')].forEach((node, index) => {
            if (MODE === 'vulnerable') node.innerHTML = items[index].message;
            else node.textContent = items[index].message;
          });
        } catch (error) {
          if (currentRequest !== requestNumber) return;
          list.textContent = 'Không thể tải đánh giá.';
          setAlert(error.message);
        }
      }
      document.getElementById('feedbackForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        setAlert('');
        try {
          await api('/api/feedback', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: new FormData(event.target).get('message') })
          });
          event.target.reset();
          await loadFeedback();
          setAlert('Cảm ơn bạn đã gửi đánh giá.', 'success');
        } catch (error) { setAlert(error.message); }
      });
      document.getElementById('searchFeedback').addEventListener('submit', async (event) => {
        event.preventDefault();
        setAlert('');
        await loadFeedback(new FormData(event.target).get('search'));
      });
      await loadFeedback();
    }

    async function admin() {
      view.innerHTML = html`
        <div class="surface p-4">
          <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
            <h2 class="h5 mb-0">Admin Users</h2>
            <form id="adminSearch" class="d-flex gap-2">
              <input class="form-control" name="search" placeholder="Search users">
              <button class="btn btn-outline-primary" type="submit">Search</button>
            </form>
          </div>
          <div id="adminUsers"></div>
        </div>`;
      async function loadUsers(search = "") {
        try {
          const users = await api(`/api/admin/users?search=${encodeURIComponent(search)}`);
          document.getElementById("adminUsers").innerHTML = `
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th></tr></thead>
                <tbody>${users.map(user => `<tr><td>${esc(user.id)}</td><td>${esc(user.username)}</td><td>${esc(user.email)}</td><td>${esc(user.role)}</td></tr>`).join("")}</tbody>
              </table>
            </div>`;
        } catch (error) {
          document.getElementById("adminUsers").innerHTML = `<pre class="mb-0">${esc(error.message)}</pre>`;
        }
      }
      document.getElementById("adminSearch").addEventListener("submit", async (event) => {
        event.preventDefault();
        await loadUsers(new FormData(event.target).get("search"));
      });
      await loadUsers();
    }

    async function checkout() {
      view.innerHTML = html`
        <form id="checkoutForm" class="surface p-4">
          <h2 class="h5 mb-3">Checkout</h2>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Product</label>
              <select class="form-select" name="product_id">
                <option value="1">Wireless Mouse</option>
                <option value="2">USB-C Hub</option>
                <option value="3">Pentest Notebook</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Quantity</label>
              <input class="form-control" name="quantity" type="number" min="1" value="1">
            </div>
            <div class="col-md-4">
              <div class="form-label">Tổng tiền</div>
              <p id="checkoutTotal" class="fw-semibold mb-0" aria-live="polite"></p>
            </div>
          </div>
          <button class="btn btn-primary mt-3" type="submit">Place order</button>
          <pre id="checkoutResult" class="mt-3 mb-0"></pre>
        </form>`;
      const form = document.getElementById('checkoutForm');
      const prices = { 1: 19.99, 2: 39.99, 3: 12.50 };
      const requestedProduct = new URLSearchParams(location.search).get('product');
      if (Object.hasOwn(prices, requestedProduct)) form.elements.product_id.value = requestedProduct;
      function total() {
        return Math.round(prices[form.elements.product_id.value] * Number(form.elements.quantity.value) * 100) / 100;
      }
      function updateTotal() { document.getElementById('checkoutTotal').textContent = '$' + total().toFixed(2); }
      form.addEventListener('input', updateTotal);
      updateTotal();
      form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const raw = Object.fromEntries(new FormData(event.target).entries());
        const data = { product_id: Number(raw.product_id), quantity: Number(raw.quantity) };
        if (CHECKOUT_ALLOWS_AMOUNT) data.amount = total();
        try {
          const result = await api("/api/checkout", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(data) });
          document.getElementById("checkoutResult").textContent = `Đặt hàng thành công · Đơn #${result.order_id} · $${Number(result.charged_amount).toFixed(2)}`;
        } catch (error) {
          document.getElementById("checkoutResult").textContent = error.message;
        }
      });
    }

    function giftCard() {
      view.innerHTML = html`
        <div class="row g-3">
          <div class="col-lg-6"><form id="giftForm" class="surface p-4">
            <h2 class="h5 mb-3">Thiệp quà tặng</h2>
            <label class="form-label" for="giftRecipient">Người nhận</label>
            <input id="giftRecipient" class="form-control mb-3" name="recipient" maxlength="120" required>
            <label class="form-label" for="giftTemplate">Lời nhắn</label>
            <textarea id="giftTemplate" class="form-control mb-3" name="template" rows="6" maxlength="2000" required></textarea>
            <button class="btn btn-primary" type="submit">Xem trước</button>
          </form></div>
          <div class="col-lg-6"><div class="surface p-4"><h2 class="h5 mb-3">Nội dung thiệp</h2><p id="giftPreview" class="mb-0" style="white-space:pre-wrap" aria-live="polite">Chưa có nội dung.</p></div></div>
        </div>`;
      document.getElementById('giftForm').addEventListener('submit', async event => {
        event.preventDefault();
        setAlert('');
        const preview = document.getElementById('giftPreview');
        preview.textContent = '';
        try {
          const result = await api('/api/gift-card/preview', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.fromEntries(new FormData(event.target)))
          });
          preview.textContent = result.preview;
        } catch (error) { setAlert(error.message); }
      });
    }

    function shipping() {
      view.innerHTML = html`
        <div class="surface p-4">
          <h2 class="h5 mb-3">Tra cứu vận đơn</h2>
          <form id="trackingForm">
            <label class="form-label" for="trackingUrl">Liên kết vận đơn</label>
            <div class="d-flex flex-wrap gap-2">
              <input id="trackingUrl" class="form-control" style="flex:1;min-width:220px" type="url" name="url" value="http://delivery-service/tracking/DEMO1001" required>
              <button class="btn btn-primary" type="submit">Tra cứu</button>
            </div>
          </form>
          <pre id="trackingResult" class="mt-3 mb-0" aria-live="polite"></pre>
        </div>`;
      document.getElementById('trackingForm').addEventListener('submit', async event => {
        event.preventDefault();
        setAlert('');
        const output = document.getElementById('trackingResult');
        output.textContent = 'Đang tra cứu…';
        try {
          const result = await api('/api/shipping/track', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url: new FormData(event.target).get('url') })
          });
          try {
            const tracking = JSON.parse(result.content);
            output.textContent = tracking.tracking_id && tracking.status
              ? `Vận đơn: ${tracking.tracking_id}\nĐơn vị: ${tracking.carrier}\nTình trạng: ${tracking.status}`
              : JSON.stringify(tracking, null, 2);
          } catch { output.textContent = result.content; }
        } catch (error) { output.textContent = ''; setAlert(error.message); }
      });
    }

    async function help() {
      view.innerHTML = html`
        <div class="surface p-4">
          <h2 class="h5 mb-3">Trợ giúp</h2>
          <div class="d-flex gap-3 mb-3"><a href="/help?article=shipping.php">Giao hàng</a><a href="/help?article=returns.php">Đổi trả</a></div>
          <p id="helpContent" class="mb-0" style="white-space:pre-wrap"></p>
        </div>`;
      const article = new URLSearchParams(location.search).get('article') || 'shipping.php';
      const result = await api('/api/help?article=' + encodeURIComponent(article));
      document.getElementById('helpContent').textContent = result.content;
    }

    async function route() {
      setAlert("");
      document.getElementById("logoutBtn").addEventListener("click", logout);
      if (!requireLogin()) return;
      if (state.token) await loadMe();
      const path = location.pathname;
      if (path === "/login") return authForm("login");
      if (path === "/register") return authForm("register");
      if (path === "/profile") return profile();
      if (path === "/orders") return orders();
      if (/^\/orders\/\d+\/view$/.test(path)) return orderDetail();
      if (path === "/avatar") return avatar();
      if (path === "/feedback") return feedback();
      if (path === "/admin") return admin();
      if (path === "/checkout") return checkout();
      if (path === "/gift-card") return giftCard();
      if (path === "/shipping") return shipping();
      if (path === "/help") return help();
      return dashboard();
    }

    for (const link of document.querySelectorAll('[data-lab-port]')) {
      const url = new URL(link.getAttribute('href'), location.origin);
      url.port = link.dataset.labPort;
      link.href = url.href;
    }
    route().catch(error => setAlert(error.message));
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
