<?php
// ============================================
// MEMBERSHIP PAGE - NADHIRA NAPOLEON
// Program loyalitas: level, poin, progress & benefit
// ============================================
require_once '../config/database.php';

$conn = getConnection();
$page_title = 'Membership';
$meta_description = 'Program membership eksklusif Nadhira Napoleon: 4 level Silver, Gold, Platinum, dan Diamond dengan berbagai benefit menarik.';

$levels = getMembershipLevels();
$currentUser = isLoggedIn() ? getCurrentUser() : null;

// Benefit per level dari database (hanya yang aktif)
$benefitsByLevel = [];
if ($conn) {
    $r = $conn->query("SELECT * FROM membership_benefits WHERE is_active = 1 ORDER BY FIELD(membership_level,'silver','gold','platinum','diamond'), id");
    if ($r) {
        while ($b = $r->fetch_assoc()) {
            $benefitsByLevel[$b['membership_level']][] = $b;
        }
    }
}

// Data progress member yang login
$myLevel = null;
$myLevelDef = null;
$nextLevel = null;
$nextLevelDef = null;
$progressPct = 100;
$remaining = 0;

if ($currentUser) {
    $myLevel = $currentUser['membership'] ?? 'silver';
    $myLevelDef = $levels[$myLevel] ?? $levels['silver'];
    $spent = (float)($currentUser['total_spent'] ?? 0);

    $nextLevel = getMembershipNextLevel($myLevel);
    if ($nextLevel) {
        $nextLevelDef = $levels[$nextLevel];
        $span = (float)$nextLevelDef['min_spend'] - (float)$myLevelDef['min_spend'];
        $progressPct = $span > 0 ? min(100, max(0, (($spent - (float)$myLevelDef['min_spend']) / $span) * 100)) : 100;
        $remaining = max(0, (float)$nextLevelDef['min_spend'] - $spent);
    }
}

// Langganan berbayar yang sedang aktif
$activeSub = null;
if ($currentUser && $conn) {
    $subR = $conn->query("SELECT * FROM membership_purchases WHERE user_id = " . (int)$currentUser['id'] . " AND status = 'active' AND expires_at > NOW() ORDER BY expires_at DESC LIMIT 1");
    if ($subR && $subR->num_rows > 0) $activeSub = $subR->fetch_assoc();
}

include '../includes/header.php';
?>

<section style="padding-top: calc(var(--navbar-total-height, 130px) + 8px); min-height: 100vh;">
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?= SITE_URL ?>">Home</a>
            <span class="separator">/</span>
            <span class="current">Membership</span>
        </div>

        <!-- ============ HEADER ============ -->
        <div style="text-align: center; max-width: 760px; margin: 0 auto var(--space-3xl);" data-aos="fade-up">
            <span class="section-tag">Program Loyalitas</span>
            <h1 style="font-family: var(--font-display); font-size: var(--text-5xl); font-weight: 700; margin-bottom: var(--space-md);">
                Membership <span class="gold-text">Nadhira Napoleon</span>
            </h1>
            <p style="color: var(--text-muted); font-size: var(--text-lg); line-height: 1.8;">
                Setiap pembelanjaan mengumpulkan <strong>poin</strong> dan meningkatkan <strong>level</strong> Anda.
                Semakin tinggi level, semakin besar voucher & keuntungan yang dinikmati.
            </p>
        </div>

        <?php if ($currentUser): ?>
        <!-- ============ KARTU MEMBER SAYA ============ -->
        <div class="member-card" data-aos="fade-up">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-lg);">
                <div style="display: flex; align-items: center; gap: var(--space-lg); flex-wrap: wrap;">
                    <div class="member-level-icon <?= $myLevel ?>">
                        <i class="fas <?= $myLevelDef['icon'] ?>"></i>
                    </div>
                    <div>
                        <p style="font-size: var(--text-sm); color: rgba(247,247,243,0.7); margin-bottom: 2px;">Halo, <?= htmlspecialchars($currentUser['full_name']) ?> 👋</p>
                        <h2 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; color: var(--text-white); margin: 0;">
                            Member <?= $myLevelDef['label'] ?>
                        </h2>
                        <span class="member-mini-badge <?= $myLevel ?>"><i class="fas <?= $myLevelDef['icon'] ?>"></i> <?= $myLevelDef['label'] ?></span>
                        <?php if ($activeSub): ?>
                        <div class="member-sub-line">
                            <i class="fas fa-calendar-check"></i> Langganan <?= ucfirst($activeSub['level']) ?> (<?= $activeSub['period'] === 'yearly' ? 'Tahunan' : 'Bulanan' ?>) aktif s.d. <?= formatDate($activeSub['expires_at'], 'd F Y') ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display: flex; gap: var(--space-2xl); flex-wrap: wrap;">
                    <div style="text-align: center;">
                        <div class="member-stat-value"><?= number_format((int)($currentUser['points'] ?? 0)) ?></div>
                        <div class="member-stat-label">Poin Saya</div>
                    </div>
                    <div style="text-align: center;">
                        <div class="member-stat-value">Rp <?= number_format((float)($currentUser['total_spent'] ?? 0), 0, ',', '.') ?></div>
                        <div class="member-stat-label">Total Belanja</div>
                    </div>
                </div>
            </div>

            <?php if ($nextLevel): ?>
            <!-- Progress ke level berikutnya -->
            <div style="margin-top: var(--space-2xl);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-md); flex-wrap: wrap; gap: var(--space-sm);">
                    <p style="font-size: var(--text-sm); color: rgba(247,247,243,0.8); margin: 0;">
                        <i class="fas fa-arrow-up"></i> Progress menuju <strong><?= $nextLevelDef['label'] ?></strong>
                    </p>
                    <p style="font-size: var(--text-sm); color: rgba(247,247,243,0.8); margin: 0;">
                        Sisa belanja <strong>Rp <?= number_format($remaining, 0, ',', '.') ?></strong> lagi
                    </p>
                </div>
                <div class="level-progress-track">
                    <div class="level-progress-bar <?= $nextLevel ?>" style="width: <?= $progressPct ?>%;"></div>
                </div>
                <div style="display: flex; justify-content: space-between; margin-top: var(--space-sm); font-size: var(--text-xs); color: rgba(247,247,243,0.6);">
                    <span>Rp <?= number_format($myLevelDef['min_spend'], 0, ',', '.') ?></span>
                    <span><?= $progressPct >= 100 ? 'Level berikutnya siap! 🎉' : round($progressPct) . '%' ?></span>
                    <span>Rp <?= number_format($nextLevelDef['min_spend'], 0, ',', '.') ?></span>
                </div>
            </div>
            <?php else: ?>
            <div style="margin-top: var(--space-xl); padding: var(--space-lg); background: rgba(255,228,0,0.12); border: 1px solid rgba(255,228,0,0.3); border-radius: var(--radius-md);">
                <p style="margin: 0; color: var(--soft-gold); font-weight: 600;">
                    <i class="fas fa-crown"></i> Anda berada di level tertinggi! Terima kasih atas loyalitas Anda. 🏆
                </p>
            </div>
            <?php endif; ?>

            <div style="margin-top: var(--space-xl); display: flex; gap: var(--space-md); flex-wrap: wrap;">
                <a href="<?= SITE_URL ?>/pages/products.php" class="btn btn-primary btn-sm"><i class="fas fa-shopping-bag"></i> Belanja & Kumpulkan Poin</a>
                <a href="<?= SITE_URL ?>/auth/profile.php" class="btn btn-outline btn-sm" style="border-color: rgba(255,228,0,0.4); color: var(--soft-gold);"><i class="fas fa-user"></i> Profil Saya</a>
            </div>
        </div>
        <?php else: ?>
        <!-- CTA untuk pengunjung -->
        <div style="text-align: center; padding: var(--space-2xl); background: var(--warm-white); border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); margin-bottom: var(--space-3xl);" data-aos="fade-up">
            <div style="width: 72px; height: 72px; margin: 0 auto var(--space-lg); background: var(--soft-gold-gradient); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; color: var(--soft-gold);">
                <i class="fas fa-crown"></i>
            </div>
            <h2 style="font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 600; margin-bottom: var(--space-sm);">Bergabung Gratis, Langsung Jadi Member</h2>
            <p style="color: var(--text-muted); max-width: 520px; margin: 0 auto var(--space-xl);">
                Daftar akun gratis dan langsung menjadi member <strong>Silver</strong>. Mulai kumpulkan poin dari setiap pembelian!
            </p>
            <div style="display: flex; justify-content: center; gap: var(--space-md); flex-wrap: wrap;">
                <a href="<?= SITE_URL ?>/auth/register.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> Daftar Sekarang</a>
                <a href="<?= SITE_URL ?>/auth/login.php" class="btn btn-outline"><i class="fas fa-sign-in-alt"></i> Sudah Punya Akun</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============ LEVEL MEMBERSHIP ============ -->
        <div style="text-align: center; margin-bottom: var(--space-2xl);" data-aos="fade-up">
            <span class="section-tag">Pilih Level Anda</span>
            <h2 style="font-family: var(--font-display); font-size: var(--text-4xl); font-weight: 700; margin-bottom: var(--space-sm);">Level <span class="gold-text">Membership</span></h2>
            <p style="color: var(--text-muted); max-width: 560px; margin: 0 auto;">Level otomatis naik saat total belanja Anda mencapai syarat level berikutnya.</p>
        </div>

        <div class="membership-levels-grid">
            <?php foreach ($levels as $key => $def):
                $isCurrent = $currentUser && $myLevel === $key;
                $benefits = $benefitsByLevel[$key] ?? [];
                $minSpend = $def['min_spend'];
            ?>
            <div class="level-card <?= $isCurrent ? 'current' : '' ?> <?= $key ?>" data-aos="fade-up" data-aos-delay="<?= 100 * (array_search($key, array_keys($levels))) ?>">
                <?php if ($isCurrent): ?>
                <div class="level-card-flag"><i class="fas fa-crown"></i> Level Kamu</div>
                <?php endif; ?>
                <div class="level-card-icon <?= $key ?>">
                    <i class="fas <?= $def['icon'] ?>"></i>
                </div>
                <h3 style="font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 700; margin-bottom: 4px;">
                    <?= $def['label'] ?>
                </h3>
                <p style="font-size: var(--text-sm); color: var(--text-muted); margin-bottom: var(--space-md);">
                    <?= $minSpend > 0 ? 'Min. belanja <strong>Rp ' . number_format($minSpend, 0, ',', '.') . '</strong>' : 'Gratis untuk semua member' ?>
                </p>
                <p style="font-size: var(--text-xs); color: var(--warm-orange); font-weight: 600; margin-bottom: var(--space-lg);">
                    <i class="fas fa-star"></i> Poin x<?= $def['multiplier'] ?> dari setiap pembelian
                </p>

                <ul class="level-benefits">
                    <?php if (!empty($benefits)): ?>
                        <?php foreach ($benefits as $b): ?>
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <div>
                                <strong><?= htmlspecialchars($b['benefit_name']) ?></strong>
                                <?php if (!empty($b['benefit_description'])): ?>
                                <small><?= htmlspecialchars($b['benefit_description']) ?></small>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li><i class="fas fa-check-circle"></i><div><strong>Manfaat sedang disiapkan</strong></div></li>
                    <?php endif; ?>
                </ul>

                <?php if (!$currentUser): ?>
                <a href="<?= SITE_URL ?>/auth/register.php" class="btn btn-outline btn-sm w-full" style="margin-top: var(--space-xl);">Daftar Jadi <?= $def['label'] ?></a>
                <?php elseif ($myLevel === $key): ?>
                <div class="level-card-cta-current"><i class="fas fa-check"></i> Level Aktif Anda</div>
                <?php elseif (array_search($key, array_keys($levels)) < array_search($myLevel, array_keys($levels))): ?>
                <div class="level-card-cta-past"><i class="fas fa-lock-open"></i> Sudah Terlewati</div>
                <?php else: ?>
                <a href="<?= SITE_URL ?>/pages/products.php" class="btn btn-outline btn-sm w-full" style="margin-top: var(--space-xl);">Belanja untuk Naik Level</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ============ LANGGANAN MEMBERSHIP (BERBAYAR) ============ -->
        <?php
        ensurePlanProducts(); // pastikan produk paket langganan tersedia
        $promo = getMembershipPromo();
        $plans = getMembershipPlans(true);
        if (!empty($plans)):
            $plansByLevel = [];
            foreach ($plans as $pl) {
                if (!empty($pl['product_id'])) $plansByLevel[$pl['level']][] = $pl;
            }
        ?>
        <div style="margin-top: var(--space-4xl);" data-aos="fade-up">
            <div style="text-align: center; margin-bottom: var(--space-2xl);">
                <span class="section-tag">Langganan Membership</span>
                <h2 style="font-family: var(--font-display); font-size: var(--text-4xl); font-weight: 700; margin-bottom: var(--space-sm);">Beli <span class="gold-text">Membership</span></h2>
                <p style="color: var(--text-muted); max-width: 620px; margin: 0 auto;">
                    Langsung nikmati level premium tanpa menunggu total belanja. Paket aktif otomatis setelah pembayaran Anda diverifikasi.
                </p>
            </div>

            <div class="grid grid-3">
                <?php foreach ($plansByLevel as $lvl => $plansArr): $lvlDef = $levels[$lvl]; ?>
                <div class="plan-card <?= $lvl ?>">
                    <div class="plan-card-header <?= $lvl ?>">
                        <i class="fas <?= $lvlDef['icon'] ?>"></i>
                        <h3 style="font-family: var(--font-display); font-size: var(--text-xl); font-weight: 700; color: var(--text-white); margin: 0;">Membership <?= $lvlDef['label'] ?></h3>
                    </div>
                    <div style="padding: var(--space-xl); display: flex; flex-direction: column; gap: var(--space-md); flex: 1;">
                        <?php foreach ($plansArr as $pl):
                            $isYearly = $pl['period'] === 'yearly';
                            $promoP = membershipPromoPrice($pl['price'], $pl['period'], $promo);
                        ?>
                        <div class="plan-option">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <strong style="font-size: var(--text-base);"><?= $isYearly ? 'Tahunan' : 'Bulanan' ?></strong>
                                    <?php if ($isYearly): ?>
                                    <?php if ($promoP): ?>
                                    <span class="plan-save promo"><i class="fas fa-bolt"></i> Promo -<?= (int)$promoP['pct'] ?>%</span>
                                    <?php else: ?>
                                    <span class="plan-save"><i class="fas fa-tag"></i> Hemat 2 bulan</span>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="plan-price">
                                    <?php if ($promoP): ?><span class="original">Rp <?= number_format((float)$pl['price'], 0, ',', '.') ?></span><?php endif; ?>
                                    Rp <?= number_format($promoP ? $promoP['price'] : (float)$pl['price'], 0, ',', '.') ?><small>/<?= $isYearly ? 'tahun' : 'bulan' ?></small>
                                </div>
                                <div style="font-size: var(--text-xs); color: var(--text-muted);">Aktif <?= (int)$pl['duration_days'] ?> hari</div>
                            </div>
                            <button class="btn btn-primary btn-sm" onclick="buyMembership(<?= (int)$pl['product_id'] ?>, '<?= $pl['period'] ?>')">
                                <i class="fas fa-crown"></i> Langganan
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <p style="text-align: center; font-size: var(--text-sm); color: var(--text-muted); margin-top: var(--space-xl);">
                <i class="fas fa-shield-alt"></i> Level otomatis diperpanjang jika Anda berlangganan ulang sebelum masa aktif habis.
            </p>
        </div>
        <?php endif; ?>

        <!-- ============ CARA KERJA ============ -->
        <div class="grid grid-3" style="margin-top: var(--space-4xl);" data-aos="fade-up">
            <div style="text-align: center; padding: var(--space-2xl); background: var(--warm-white); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
                <div style="width: 64px; height: 64px; margin: 0 auto var(--space-lg); background: var(--soft-gold-gradient); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--soft-gold);">
                    <i class="fas fa-shopping-bag"></i>
                </div>
                <h4 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 600; margin-bottom: var(--space-sm);">1. Belanja</h4>
                <p style="font-size: var(--text-sm); color: var(--text-muted); margin: 0;">Lakukan pembelian dengan akun member Anda</p>
            </div>
            <div style="text-align: center; padding: var(--space-2xl); background: var(--warm-white); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
                <div style="width: 64px; height: 64px; margin: 0 auto var(--space-lg); background: var(--soft-gold-gradient); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--soft-gold);">
                    <i class="fas fa-coins"></i>
                </div>
                <h4 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 600; margin-bottom: var(--space-sm);">2. Kumpulkan Poin</h4>
                <p style="font-size: var(--text-sm); color: var(--text-muted); margin: 0;">Setiap Rp 10.000 = 1 poin × multiplier level<br><strong style="color: var(--warm-orange);">1 poin = Rp 100</strong> saat ditukar di checkout (maks <?= POINT_MAX_PCT ?>%)</p>
            </div>
            <div style="text-align: center; padding: var(--space-2xl); background: var(--warm-white); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
                <div style="width: 64px; height: 64px; margin: 0 auto var(--space-lg); background: var(--soft-gold-gradient); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--soft-gold);">
                    <i class="fas fa-arrow-trend-up"></i>
                </div>
                <h4 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 600; margin-bottom: var(--space-sm);">3. Naik Level</h4>
                <p style="font-size: var(--text-sm); color: var(--text-muted); margin: 0;">Level otomatis naik sesuai total belanja</p>
            </div>
        </div>
    </div>
</section>

<?php include '../includes/footer.php'; ?>
