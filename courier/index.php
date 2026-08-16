<?php
// ============================================
// PANEL KURIR - Nadhira Napoleon
// Kurir login lalu mengaktifkan "Live GPS" agar
// posisinya terlihat real-time oleh customer &
// admin. Juga menampilkan pesanan yang ditugaskan.
// ============================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/rbac.php';

$conn = getConnection();

if (!isLoggedIn()) {
    header('Location: ' . SITE_URL . '/auth/login.php?redirect=/courier/index.php');
    exit;
}

$courier = getCourierForUser((int)$_SESSION['user_id']);
$isCourier = (bool)$courier;

// Pesanan yang ditugaskan ke kurir ini (aktif)
$assignedOrders = [];
if ($isCourier) {
    $cid = (int)$courier['id'];
    $r = $conn->query("SELECT * FROM orders
        WHERE courier_id = $cid AND order_status IN ('processing','shipped')
        ORDER BY created_at DESC LIMIT 30");
    if ($r) while ($row = $r->fetch_assoc()) $assignedOrders[] = $row;
}

$branch = null;
if ($isCourier && !empty($courier['branch_id'])) {
    $br = $conn->query("SELECT * FROM branches WHERE id = " . (int)$courier['branch_id'] . " LIMIT 1");
    if ($br && $br->num_rows > 0) $branch = $br->fetch_assoc();
}

$latestLoc = $isCourier ? getLatestCourierLocation((int)$courier['id']) : null;
$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Kurir — <?= htmlspecialchars(SITE_NAME) ?></title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #F7F7F3;
            color: #2C1810;
            padding-bottom: 60px;
        }
        .topbar {
            background: linear-gradient(135deg, #2C1810, #5C3A1E);
            color: #FFE400;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 500;
            box-shadow: 0 4px 20px rgba(0,0,0,.25);
        }
        .topbar h1 { font-size: 17px; font-weight: 700; color: #fff; }
        .topbar .sub { font-size: 12px; color: rgba(255,255,255,.7); }
        .topbar a { color: #FFE400; text-decoration: none; font-size: 13px; font-weight: 600; }
        .wrap { max-width: 860px; margin: 0 auto; padding: 18px 16px; }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 16px;
            box-shadow: 0 2px 12px rgba(184,148,15,.08);
        }
        .card h2 { font-size: 15px; font-weight: 700; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .card h2 i { color: #D4A030; }
        .badge {
            display: inline-block; padding: 4px 12px; border-radius: 20px;
            font-size: 12px; font-weight: 600;
        }
        .badge.on { background: #D1FAE5; color: #065F46; }
        .badge.off { background: #FEE2E2; color: #991B1B; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px 22px; border: none; border-radius: 50px; cursor: pointer;
            font-size: 14px; font-weight: 600; text-decoration: none; transition: all .2s;
        }
        .btn-gold { background: linear-gradient(135deg, #D4A030, #B8940F); color: #fff; }
        .btn-gold:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(184,148,15,.35); }
        .btn-ghost { background: #F0F0EA; color: #2C1810; }
        .btn-danger { background: #EF4444; color: #fff; }
        .btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
        #courier-map { height: 340px; border-radius: 12px; border: 1px solid #E8E8D8; }
        .status-line { font-size: 13px; color: #666; margin-top: 10px; display: flex; align-items: center; gap: 8px; }
        .status-line i { color: #D4A030; }
        .order-item {
            border: 1px solid #E8E8D8; border-radius: 12px; padding: 14px;
            margin-bottom: 10px; display: flex; justify-content: space-between; gap: 10px;
        }
        .order-item .num { font-weight: 700; color: #B8940F; }
        .order-item .addr { font-size: 13px; color: #555; margin-top: 4px; }
        .order-item .st { font-size: 12px; color: #888; margin-top: 4px; }
        .order-item a { color: #B8940F; font-size: 12px; text-decoration: none; white-space: nowrap; }
        .empty { text-align: center; padding: 30px 10px; color: #999; font-size: 14px; }
        .info-row { display: flex; justify-content: space-between; font-size: 13px; padding: 4px 0; }
        .info-row b { color: #2C1810; }
        .footer-note { text-align: center; font-size: 12px; color: #aaa; padding: 10px; }
    </style>
</head>
<body>

<?php if (!$isCourier): ?>
    <div class="topbar">
        <div>
            <h1>Panel Kurir</h1>
            <div class="sub"><?= htmlspecialchars(SITE_NAME) ?></div>
        </div>
        <a href="<?= SITE_URL ?>/auth/logout.php">Keluar</a>
    </div>
    <div class="wrap">
        <div class="card" style="text-align:center; padding: 40px 20px;">
            <i class="fas fa-truck" style="font-size: 42px; color: #D4A030; opacity:.5; margin-bottom: 14px;"></i>
            <h2 style="justify-content:center;">Akun Ini Bukan Kurir</h2>
            <p style="font-size:14px; color:#666; margin: 8px 0 18px;">
                Halo <b><?= htmlspecialchars($user['full_name'] ?? '') ?></b>, akun Anda belum terdaftar sebagai kurir.<br>
                Silakan hubungi admin toko untuk didaftarkan.
            </p>
            <a href="<?= SITE_URL ?>" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Kembali ke Website</a>
        </div>
    </div>
<?php else: ?>
    <div class="topbar">
        <div>
            <h1><i class="fas fa-truck-fast"></i> Panel Kurir</h1>
            <div class="sub"><?= htmlspecialchars($courier['name']) ?></div>
        </div>
        <a href="<?= SITE_URL ?>/auth/logout.php"><i class="fas fa-sign-out-alt"></i> Keluar</a>
    </div>

    <div class="wrap">
        <!-- Status GPS -->
        <div class="card">
            <h2><i class="fas fa-satellite-dish"></i> Live GPS <span id="gps-badge" class="badge off">OFF</span></h2>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button id="btn-live" class="btn btn-gold" onclick="startLive()"><i class="fas fa-play"></i> Mulai Live GPS</button>
                <button id="btn-stop" class="btn btn-danger" style="display:none;" onclick="stopLive()"><i class="fas fa-stop"></i> Stop</button>
            </div>
            <div class="status-line">
                <i class="fas fa-circle" id="dot" style="color:#bbb;"></i>
                <span id="gps-status">GPS nonaktif — aktifkan untuk mulai mengirim posisi.</span>
            </div>
            <div class="status-line" id="acc-line" style="display:none;">
                <i class="fas fa-crosshairs"></i>
                <span id="acc-text"></span>
            </div>
        </div>

        <!-- Peta -->
        <div class="card">
            <h2><i class="fas fa-map-marked-alt"></i> Posisi Saya & Pengiriman</h2>
            <div id="courier-map"></div>
            <div class="status-line">
                <i class="fas fa-store"></i>
                <span>Cabang: <b><?= $branch ? htmlspecialchars($branch['name']) : '-' ?></b></span>
            </div>
        </div>

        <!-- Pesanan Ditugaskan -->
        <div class="card">
            <h2><i class="fas fa-clipboard-list"></i> Pesanan Ditugaskan (<?= count($assignedOrders) ?>)</h2>
            <?php if (empty($assignedOrders)): ?>
                <div class="empty"><i class="fas fa-inbox" style="font-size:26px; margin-bottom:8px; display:block; opacity:.4;"></i>Belum ada pesanan yang ditugaskan ke Anda.</div>
            <?php else: foreach ($assignedOrders as $o): ?>
                <div class="order-item">
                    <div style="flex:1;">
                        <div class="num"><?= htmlspecialchars($o['order_number']) ?></div>
                        <div class="addr"><?= htmlspecialchars($o['shipping_address']) ?><?= $o['shipping_city'] ? ', ' . htmlspecialchars($o['shipping_city']) : '' ?></div>
                        <div class="st">
                            <?= htmlspecialchars($o['customer_name']) ?> · <?= htmlspecialchars($o['customer_phone']) ?> ·
                            Status: <b><?= ucfirst($o['order_status']) ?></b>
                        </div>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-end;">
                        <?php if (!empty($o['latitude']) && !empty($o['longitude'])): ?>
                        <a href="https://www.google.com/maps?q=<?= (float)$o['latitude'] ?>,<?= (float)$o['longitude'] ?>" target="_blank">
                            <i class="fas fa-map-marker-alt"></i> Maps
                        </a>
                        <?php endif; ?>
                        <a href="<?= SITE_URL ?>/pages/tracking.php?order=<?= urlencode($o['order_number']) ?>&email=<?= urlencode($o['customer_email']) ?>" target="_blank">
                            <i class="fas fa-eye"></i> Tracking
                        </a>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="footer-note">
            <i class="fas fa-shield-alt"></i> Posisi GPS hanya dikirim saat Live GPS aktif · <?= htmlspecialchars(SITE_NAME) ?>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    var AJAX_BASE = '<?= SITE_URL ?>';
    var CURRENT = { lat: <?= $latestLoc ? (float)$latestLoc['latitude'] : 'null' ?>, lng: <?= $latestLoc ? (float)$latestLoc['longitude'] : 'null' ?> };
    var ORDERS = <?= json_encode(array_map(function ($o) {
        return [
            'number'  => $o['order_number'],
            'lat'     => (float)$o['latitude'],
            'lng'     => (float)$o['longitude'],
            'address' => $o['shipping_address'],
            'status'  => $o['order_status'],
        ];
    }, $assignedOrders), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var map, meMarker, watchId = null, lastSent = 0, liveOn = false;
    var customerMarkers = [];

    function init() {
        var center = CURRENT.lat ? [CURRENT.lat, CURRENT.lng] : [0.5071, 101.4478];
        map = L.map('courier-map').setView(center, 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        var meIcon = L.divIcon({ className: '', html: '<div style="background:#2563EB;color:#fff;width:34px;height:34px;border-radius:50%;border:3px solid #fff;box-shadow:0 3px 12px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;"><i class="fas fa-motorcycle" style="font-size:16px;"></i></div>', iconSize: [34, 34], iconAnchor: [17, 17] });
        meMarker = L.marker(center, { icon: meIcon }).addTo(map).bindPopup('<b>Posisi saya</b>');

        var custIcon = L.divIcon({ className: '', html: '<div style="background:#EF4444;color:#fff;width:26px;height:26px;border-radius:50%;border:2px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center;"><i class="fas fa-user" style="font-size:12px;"></i></div>', iconSize: [26, 26], iconAnchor: [13, 13] });
        (ORDERS || []).forEach(function (o) {
            if (!o.lat || !o.lng) return;
            L.marker([o.lat, o.lng], { icon: custIcon }).addTo(map)
                .bindPopup('<b>' + escHtml(o.number) + '</b><br>' + escHtml(o.address));
        });

        if (CURRENT.lat) map.setView([CURRENT.lat, CURRENT.lng], 14);
    }

    function setStatus(text, on) {
        document.getElementById('gps-status').textContent = text;
        document.getElementById('dot').style.color = on ? '#059669' : '#bbb';
        document.getElementById('gps-badge').className = 'badge ' + (on ? 'on' : 'off');
        document.getElementById('gps-badge').textContent = on ? 'LIVE' : 'OFF';
    }

    function sendPosition(lat, lng, acc) {
        var now = Date.now();
        if (now - lastSent < 8000) return; // throttle ~8 detik
        lastSent = now;
        var fd = new FormData();
        fd.append('latitude', lat);
        fd.append('longitude', lng);
        fd.append('accuracy', acc || 0);
        fetch(AJAX_BASE + '/ajax/courier-location.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) { if (!res.ok && res.error) console.warn(res.error); })
            .catch(function () {});
    }

    function startLive() {
        if (!navigator.geolocation) {
            alert('Browser Anda tidak mendukung geolocation.');
            return;
        }
        liveOn = true;
        document.getElementById('btn-live').style.display = 'none';
        document.getElementById('btn-stop').style.display = 'inline-flex';
        document.getElementById('acc-line').style.display = 'flex';
        setStatus('Mencari sinyal GPS...', true);

        watchId = navigator.geolocation.watchPosition(function (pos) {
            var lat = pos.coords.latitude, lng = pos.coords.longitude, acc = pos.coords.accuracy;
            CURRENT.lat = lat; CURRENT.lng = lng;
            meMarker.setLatLng([lat, lng]);
            map.setView([lat, lng], map.getZoom() < 15 ? 15 : map.getZoom());
            document.getElementById('acc-text').textContent = 'Akurasi ± ' + Math.round(acc) + ' m · dikirim ' + new Date().toLocaleTimeString('id-ID');
            sendPosition(lat, lng, acc);
            setStatus('Live GPS aktif — posisi dikirim ke server.', true);
        }, function (err) {
            var msg = 'Gagal membaca GPS.';
            if (err.code === 1) msg = 'Izin lokasi ditolak. Izinkan akses lokasi lalu coba lagi.';
            else if (err.code === 2) msg = 'Sinyal GPS tidak tersedia.';
            else if (err.code === 3) msg = 'Waktu pencarian sinyal habis.';
            setStatus('⚠️ ' + msg, false);
        }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 15000 });
    }

    function stopLive() {
        if (watchId !== null) navigator.geolocation.clearWatch(watchId);
        watchId = null; liveOn = false;
        document.getElementById('btn-live').style.display = 'inline-flex';
        document.getElementById('btn-stop').style.display = 'none';
        document.getElementById('acc-line').style.display = 'none';
        setStatus('GPS nonaktif — posisi terakhir tetap tersimpan.', false);
    }

    init();
    </script>
<?php endif; ?>
</body>
</html>
