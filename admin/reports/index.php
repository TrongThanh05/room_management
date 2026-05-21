<?php
// admin/reports/index.php — Báo cáo thống kê
$pageTitle = 'Báo cáo & Thống kê';
require_once __DIR__ . '/../../includes/admin_header.php';
$db = getDB();

// ── FILTER ──────────────────────────────────────────
$search_user = trim($_GET['user'] ?? '');
$date_from   = $_GET['date_from'] ?? '';
$date_to     = $_GET['date_to']   ?? '';
$price_min   = (int)($_GET['price_min'] ?? 0);
$price_max   = (int)($_GET['price_max'] ?? 0);
$sort_by     = $_GET['sort'] ?? 'newest';
$status      = $_GET['status'] ?? 'all';
$page_num    = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 15;
$month_stat  = (int)($_GET['month'] ?? date('n'));
$year_stat   = (int)($_GET['year']  ?? date('Y'));

// ── BUILD QUERY ──────────────────────────────────────────
// Lọc theo tin_dang (có tieu_de, gia, trang_thai) JOIN phong_tro
$where  = ["td.trang_thai != 'da_cho_thue'"];
$params = [];
$types  = '';

if ($search_user !== '') {
    $where[]  = "(u.ho_ten LIKE ? OR u.username LIKE ?)";
    $kw = "%$search_user%";
    $params[] = $kw; $params[] = $kw;
    $types   .= 'ss';
}
if ($date_from !== '') {
    $where[]  = "td.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
    $types   .= 's';
}
if ($date_to !== '') {
    $where[]  = "td.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types   .= 's';
}
if ($price_min > 0) {
    $where[]  = "td.gia >= ?";
    $params[] = $price_min;
    $types   .= 'i';
}
if ($price_max > 0) {
    $where[]  = "td.gia <= ?";
    $params[] = $price_max;
    $types   .= 'i';
}
if ($status != 'all') {
    $where[]  = "td.trang_thai = ?";
    $params[] = $status;
    $types   .= 's';
}

$order_map = [
    'newest'    => 'td.created_at DESC',
    'oldest'    => 'td.created_at ASC',
    'price_asc' => 'td.gia ASC',
    'price_desc'=> 'td.gia DESC',
    'views'     => 'td.luot_xem DESC',
];
$order = $order_map[$sort_by] ?? 'td.created_at DESC';
$whereSQL = implode(' AND ', $where);

$baseQ  = "FROM tin_dang td
            LEFT JOIN phong_tro p ON td.phong_tro_id = p.id
            LEFT JOIN users u ON p.user_id = u.id
            WHERE $whereSQL";
$countQ = "SELECT COUNT(*) $baseQ";
$listQ  = "SELECT td.id, td.tieu_de, td.gia, td.trang_thai, td.created_at, td.luot_xem,
                  p.dia_chi, u.ho_ten as chu_tro, u.username
           $baseQ ORDER BY $order";

// Count
if (!empty($params)) {
    $s = $db->prepare($countQ); $s->bind_param($types, ...$params); $s->execute();
    $total = $s->get_result()->fetch_row()[0]; $s->close();
} else {
    $total = $db->query($countQ)->fetch_row()[0];
}
$pg     = paginate($total, $per_page, $page_num);
$offset = $pg['offset'];
$sql    = $listQ . " LIMIT $per_page OFFSET $offset";

if (!empty($params)) {
    $s = $db->prepare($sql); $s->bind_param($types, ...$params); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
} else {
    $rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
}

// ── THỐNG KÊ THÁNG ─────────────────────────────────
$stat_month = $db->query(
    "SELECT COUNT(*) as total,
            SUM(gia_goc) as tong_gia,
            AVG(gia_goc) as avg_gia,
            SUM(CASE WHEN trang_thai='co_san' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN trang_thai='da_cho_thue' THEN 1 ELSE 0 END) as pending
     FROM phong_tro
     WHERE MONTH(created_at)=$month_stat AND YEAR(created_at)=$year_stat
       AND trang_thai != 'da_cho_thue'"
)->fetch_assoc();

// Chart — thống kê theo ngày trong tháng
$daily_chart = $db->query(
    "SELECT DAY(created_at) as ngay, COUNT(*) as so_luong
     FROM phong_tro
     WHERE MONTH(created_at)=$month_stat AND YEAR(created_at)=$year_stat
       AND trang_thai != 'da_cho_thue'
     GROUP BY ngay ORDER BY ngay"
)->fetch_all(MYSQLI_ASSOC);

// Top người đăng
$top_users = $db->query(
    "SELECT u.ho_ten, u.username, COUNT(p.id) as so_tin,
            SUM(p.gia_goc) as tong_gia
     FROM phong_tro p
     LEFT JOIN users u ON p.user_id = u.id
     WHERE p.trang_thai != 'da_cho_thue'
     GROUP BY p.user_id ORDER BY so_tin DESC LIMIT 5"
)->fetch_all(MYSQLI_ASSOC);

$baseUrl = BASE_URL . '/admin/reports/index.php?' . http_build_query(array_filter([
    'user' => $search_user, 'date_from' => $date_from, 'date_to' => $date_to,
    'price_min' => $price_min ?: '', 'price_max' => $price_max ?: '', 'sort' => $sort_by,
]));
?>

<div class="page-header">
    <h1 class="page-title"><span class="icon"><i class="bi bi-bar-chart-line"></i></span><?= $pageTitle ?></h1>
</div>

<!-- THÁNG STATS -->
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <!-- Chọn tháng -->
        <div class="data-card mb-4">
            <div class="data-card-header">
                <div class="data-card-title"><i class="bi bi-calendar3 me-2 text-warning"></i>Thống kê tháng</div>
                <form method="GET" action="" class="d-flex gap-2 align-items-center">
                    <?php // preserve other params ?>
                    <?php foreach (['user','date_from','date_to','price_min','price_max','sort'] as $k): if (!empty($_GET[$k])): ?>
                    <input type="hidden" name="<?= $k ?>" value="<?= e($_GET[$k]) ?>">
                    <?php endif; endforeach; ?>
                    <select name="month" class="form-control" style="width:auto">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m == $month_stat ? 'selected' : '' ?>>Tháng <?= $m ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="year" class="form-control" style="width:auto">
                        <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                        <option value="<?= $y ?>" <?= $y == $year_stat ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="btn-search"><i class="bi bi-filter"></i> Xem</button>
                </form>
                 
            <div style="width: 95%; margin: auto; padding:1.25rem 1.5rem 1.5rem">
                <canvas id="statusChart" height="230" style="display:block;width:100%"></canvas>
            </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Summary cards -->
        <div class="row g-3">
            <div class="col-12">
                <div class="stat-card">
                    <div class="stat-card-icon orange"><i class="bi bi-newspaper"></i></div>
                    <div>
                        <div class="stat-card-label">Tin đăng tháng <?= $month_stat ?>/<?= $year_stat ?></div>
                        <div class="stat-card-value"><?= number_format($stat_month['total']) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="stat-card">
                    <div class="stat-card-icon green"><i class="bi bi-check-circle"></i></div>
                    <div>
                        <div class="stat-card-label">Đã duyệt</div>
                        <div class="stat-card-value"><?= $stat_month['approved'] ?? 0 ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="stat-card">
                    <div class="stat-card-icon blue"><i class="bi bi-hourglass"></i></div>
                    <div>
                        <div class="stat-card-label">Chờ duyệt</div>
                        <div class="stat-card-value"><?= $stat_month['pending'] ?? 0 ?></div>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="stat-card">
                    <div class="stat-card-icon purple"><i class="bi bi-cash-coin"></i></div>
                    <div>
                        <div class="stat-card-label">Giá thuê TB</div>
                        <div class="stat-card-value" style="font-size:1.2rem">
                            <?= $stat_month['avg_gia'] ? formatPrice($stat_month['avg_gia']) : '—' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- TOP USERS -->
<div class="data-card mb-4">
    <div class="data-card-header">
        <div class="data-card-title"><i class="bi bi-trophy me-2 text-warning"></i>Top người đăng nhiều tin nhất</div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>#</th><th>Tài khoản</th><th>Số tin đăng</th><th>Tổng giá trị</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($top_users as $i => $tu): ?>
            <tr>
                <td>
                    <?php if ($i === 0): ?><span style="font-size:1.2rem">🥇</span>
                    <?php elseif ($i === 1): ?><span style="font-size:1.2rem">🥈</span>
                    <?php elseif ($i === 2): ?><span style="font-size:1.2rem">🥉</span>
                    <?php else: echo $i + 1; endif; ?>
                </td>
                <td>
                    <strong><?= e($tu['ho_ten']) ?></strong>
                    <div style="font-size:.78rem;color:var(--admin-muted)">@<?= e($tu['username']) ?></div>
                </td>
                <td><strong style="color:var(--admin-primary)"><?= $tu['so_tin'] ?></strong> tin</td>
                <td><?= formatPrice($tu['tong_gia']) ?></td>
                <td>
                    <a href="<?= BASE_URL ?>/admin/reports/index.php?user=<?= urlencode($tu['ho_ten']) ?>" class="btn-action view">Xem tin</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- FILTER TÌM KIẾM TIN ĐĂNG -->
<div class="data-card mb-4">
    <div class="data-card-header">
        <div class="data-card-title"><i class="bi bi-funnel me-2 text-warning"></i>Tìm kiếm & Sắp xếp tin đăng</div>
    </div>
    <div style="padding:1.25rem">
        <form method="GET" action="">
            <?php // preserve month/year ?>
            <input type="hidden" name="month" value="<?= $month_stat ?>">
            <input type="hidden" name="year"  value="<?= $year_stat ?>">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" style="font-size:.85rem;font-weight:600">Người đăng</label>
                    <input type="text" name="user" class="form-control" value="<?= e($search_user) ?>" placeholder="Tên người đăng...">
                </div>
                <div class="col-md-2">
                    <label class="form-label" style="font-size:.85rem;font-weight:600">Từ ngày</label>
                    <input type="date" name="date_from" class="form-control" value="<?= e($date_from) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" style="font-size:.85rem;font-weight:600">Đến ngày</label>
                    <input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" style="font-size:.85rem;font-weight:600">Giá từ (đ)</label>
                    <input type="number" name="price_min" class="form-control" value="<?= $price_min ?: '' ?>" placeholder="0" step="100000">
                </div>
                <div class="col-md-2">
                    <label class="form-label" style="font-size:.85rem;font-weight:600">Giá đến (đ)</label>
                    <input type="number" name="price_max" class="form-control" value="<?= $price_max ?: '' ?>" placeholder="∞" step="100000">
                </div>
                <div class="col-md-3">
                    <label class="form-label" style="font-size:.85rem;font-weight:600">Sắp xếp theo</label>
                    <select name="sort" class="form-select">
                        <option value="newest"    <?= $sort_by === 'newest'     ? 'selected' : '' ?>>⏰ Mới nhất</option>
                        <option value="oldest"    <?= $sort_by === 'oldest'     ? 'selected' : '' ?>>📅 Cũ nhất</option>
                        <option value="price_asc" <?= $sort_by === 'price_asc'  ? 'selected' : '' ?>>💰 Giá tăng dần</option>
                        <option value="price_desc"<?= $sort_by === 'price_desc' ? 'selected' : '' ?>>💰 Giá giảm dần</option>
                        <option value="views"     <?= $sort_by === 'views'      ? 'selected' : '' ?>>👁 Xem nhiều nhất</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" style="font-size:.85rem;font-weight:600">Trạng thái tin</label>
                    <select name="status" class="form-select">
                        <option value="all"     <?= $status === 'all'      ? 'selected' : '' ?>>Tất cả</option>
                        <option value="cho_duyet"    <?= $status === 'cho_duyet'     ? 'selected' : '' ?>>Chờ duyệt</option>
                        <option value="da_duyet"    <?= $status === 'da_duyet'     ? 'selected' : '' ?>>Đã duyệt</option>
                        <option value="bi_tu_choi" <?= $status === 'bi_tu_choi'  ? 'selected' : '' ?>>Đã từ chối</option>
                        <option value="an"<?= $status === 'an' ? 'selected' : '' ?>>Đã ẩn</option>
                    </select>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn-admin-primary"><i class="bi bi-search"></i> Tìm kiếm</button>
                    <a href="<?= BASE_URL ?>/admin/reports/index.php" class="btn-admin-secondary"><i class="bi bi-x"></i> Xóa lọc</a>
                    <span style="margin-left:auto;font-size:.875rem;color:var(--admin-muted);align-self:center">
                        <strong><?= $total ?></strong> kết quả
                    </span>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- DANH SÁCH TIN ĐĂNG -->
<div class="data-card">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Tiêu đề</th>
                    <th>Người đăng</th>
                    <th>Giá</th>
                    <th>Trạng thái</th>
                    <th>Lượt xem</th>
                    <th>Ngày đăng</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">Không có dữ liệu</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= $r['id'] ?></td>
                <td style="max-width:200px">
                    <div style="font-weight:600;overflow:hidden;white-space:nowrap;text-overflow:ellipsis" title="<?= e($r['tieu_de']) ?>">
                        <?= e($r['tieu_de']) ?>
                    </div>
                    <div style="font-size:.78rem;color:var(--admin-muted)"><?= e($r['dia_chi'] ?? '') ?></div>
                </td>
                <td><?= e($r['chu_tro'] ?? '—') ?></td>
                <td style="font-weight:700;color:var(--admin-primary);white-space:nowrap"><?= formatPrice($r['gia']) ?></td>
                <td><?= tinDangStatusBadge($r['trang_thai']) ?></td>
                <td style="text-align:center"><?= number_format($r['luot_xem']) ?></td>
                <td style="white-space:nowrap"><?= formatDateTime($r['created_at']) ?></td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="<?= BASE_URL ?>/room-detail.php?id=<?= $r['id'] ?>" class="btn-action view" target="_blank">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="<?= BASE_URL ?>/admin/tin-dang/edit.php?id=<?= $r['id'] ?>" class="btn-action edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="p-3 border-top">
        <?php renderPagination($pg, $baseUrl); ?>
    </div>
</div>

<script>
(function () {
    // ── Dữ liệu từ PHP ──────────────────────────────────────────
    const dailyRaw    = <?= json_encode(array_values($daily_chart)) ?>;
    const daysInMonth = new Date(<?= $year_stat ?>, <?= $month_stat ?>, 0).getDate();
    const monthLabel  = 'Tháng <?= $month_stat ?>/<?= $year_stat ?>';

    // ── Nhóm theo tuần (mỗi tuần ~7 ngày) ───────────────────────
    // Tính số tuần: luôn 4 tuần + tuần 5 nếu daysInMonth > 28
    const weekSize = 7;
    const numWeeks = Math.ceil(daysInMonth / weekSize);   // 4 hoặc 5

    // Khởi tạo nhóm
    const groups = Array.from({ length: numWeeks }, (_, wi) => {
        const start = wi * weekSize + 1;
        const end   = Math.min(start + weekSize - 1, daysInMonth);
        return { label: `${start}–${end}`, count: 0 };
    });

    // Phân phối số liệu vào tuần
    dailyRaw.forEach(d => {
        const day = +d.ngay;
        const wi  = Math.floor((day - 1) / weekSize);
        if (wi >= 0 && wi < numWeeks) groups[wi].count += +d.so_luong;
    });

    // ── Vẽ canvas ───────────────────────────────────────────────
    function draw() {
        const canvas = document.getElementById('statusChart');
        if (!canvas) return;

        const ratio = window.devicePixelRatio || 1;
        const W     =  canvas.parentElement.clientWidth;
        const H     = +canvas.getAttribute('height');
        canvas.width  = W * ratio;
        canvas.height = H * ratio;
        const ctx = canvas.getContext('2d');
        ctx.scale(ratio, ratio);

        // Padding
        const PL = 44, PR = 16, PT = 28, PB = 48;
        const cW = W - PL - PR;
        const cH = H - PT - PB;

        ctx.clearRect(0, 0, W, H);

        const maxVal = Math.max(...groups.map(g => g.count + 10), 1);
        const GRID   = 4;   // số đường lưới
        const COLOR_GRID = 'rgba(150,150,150,0.18)';
        const COLOR_TEXT = '#64748b';
        const COLOR_BAR  = '#f97316';

        // Đường lưới + nhãn Y
        ctx.save();
        ctx.setLineDash([4, 4]);
        for (let i = 0; i <= GRID; i++) {
            const y = PT + cH - (i / GRID) * cH;
            ctx.strokeStyle = COLOR_GRID;
            ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(PL, y); ctx.lineTo(PL + cW, y); ctx.stroke();
            ctx.fillStyle = COLOR_TEXT;
            ctx.font = '11px Inter,sans-serif';
            ctx.textAlign = 'right';
            ctx.fillText(Math.round(maxVal * i / GRID), PL - 7, y + 4);
        }
        ctx.restore();

        // Trục X
        ctx.strokeStyle = 'rgba(150,150,150,0.3)';
        ctx.lineWidth = 1;
        ctx.setLineDash([]);
        ctx.beginPath();
        ctx.moveTo(PL, PT + cH); ctx.lineTo(PL + cW, PT + cH);
        ctx.stroke();

        // Cột
        const gap  = cW / numWeeks;
        const barW = gap * 0.55;

        groups.forEach((g, i) => {
            const x  = PL + i * gap + (gap - barW) / 2;
            const bH = g.count === 0 ? 3 : Math.max(6, (g.count / maxVal) * cH);
            const y  = PT + cH - bH;
            const r  = Math.min(7, barW / 2);

            // Gradient fill
            const grad = ctx.createLinearGradient(0, y, 0, y + bH);
            grad.addColorStop(0, COLOR_BAR);
            grad.addColorStop(1, 'rgba(249,115,22,0.25)');
            ctx.fillStyle = g.count === 0 ? '#e2e8f0' : grad;

            // Rounded top rect
            ctx.beginPath();
            ctx.moveTo(x + r, y);
            ctx.lineTo(x + barW - r, y);
            ctx.quadraticCurveTo(x + barW, y, x + barW, y + r);
            ctx.lineTo(x + barW, y + bH);
            ctx.lineTo(x, y + bH);
            ctx.lineTo(x, y + r);
            ctx.quadraticCurveTo(x, y, x + r, y);
            ctx.closePath();
            ctx.fill();

            // Số trên cột
            if (g.count > 0) {
                ctx.fillStyle = COLOR_BAR;
                ctx.font = 'bold 12px Inter,sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText(g.count, x + barW / 2, y - 6);
            }

            // Nhãn trục X: khoảng ngày
            ctx.fillStyle = COLOR_TEXT;
            ctx.font = '11px Inter,sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(g.label, x + barW / 2, PT + cH + 18);
        });

        // Nhãn dưới cùng
        ctx.fillStyle = COLOR_TEXT;
        ctx.font = '11.5px Inter,sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Ngày trong ' + monthLabel, PL + cW / 2, H - 6);
    }

    document.addEventListener('DOMContentLoaded', draw);
    window.addEventListener('resize', draw);
})();
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
