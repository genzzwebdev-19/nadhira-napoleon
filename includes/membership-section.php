<?php
// ============================================
// MEMBERSHIP SECTION - REUSABLE PARTIAL
// Dipakai di: index.php (homepage) & pages/products.php (katalog)
// Self-contained: memuat sendiri data yang dibutuhkan.
// Variabel diberi prefix $ms_ agar tidak bertabrakan dengan halaman induk.
// ============================================
require_once __DIR__ . '/../config/database.php';

ensurePlanProducts(); // pastikan produk paket langganan tersedia
$msLevels = getMembershipLevels();
$msPlans = getMembershipPlans(true);
$msPlansByLevel = [];
foreach ($msPlans as $hp) {
    if (!empty($hp['product_id'])) $msPlansByLevel[$hp['level']][] = $hp;
}

// Data kartu member untuk pengguna yang login
$msUser = isLoggedIn() ? getCurrentUser() : null;
$msMyLevel = 'silver';
$msMyLevelDef = $msLevels['silver'];
$msNextLevel = null;
$msNextLevelDef = null;
$msProgressPct = 100;
$msRemaining = 0;
$msActiveSub = null;
if ($msUser) {
    $msConn = getConnection();
    $msMyLevel = $msUser['membership'] ?? 'silver';
    $msMyLevelDef = $msLevels[$msMyLevel] ?? $msLevels['silver'];
    $msSpent = (float)($msUser['total_spent'] ?? 0);
    $msNextLevel = getMembershipNextLevel($msMyLevel);
    if ($msNextLevel) {
        $msNextLevelDef = $msLevels[$msNextLevel];
        $msSpan = (float)$msNextLevelDef['min_spend'] - (float)$msMyLevelDef['min_spend'];
        $msProgressPct = $msSpan > 0 ? min(100, max(0, (($msSpent - (float)$msMyLevelDef['min_spend']) / $msSpan) * 100)) : 100;
        $msRemaining = max(0, (float)$msNextLevelDef['min_spend'] - $msSpent);
    }
    if ($msConn) {
        $msSubR = $msConn->query("SELECT * FROM membership_purchases WHERE user_id = " . (int)$msUser['id'] . " AND status = 'active' AND expires_at > NOW() ORDER BY expires_at DESC LIMIT 1");
        if ($msSubR && $msSubR->num_rows > 0) $msActiveSub = $msSubR->fetch_assoc();
    }
}

// Promo membership (diskon paket tahunan + countdown)
$msPromo = getMembershipPromo();

if (!empty($msPlansByLevel)):
?>
<section id="membership" class="home-membership bg-songket-dark">
    <div class="container">
        <?php if ($msPromo): ?>
        <!-- ============ BANNER PROMO MEMBERSHIP ============ -->
        <div class="membership-promo-banner" data-aos="fade-up">
            <div class="membership-promo-info">
                <span class="membership-promo-badge"><i class="fas fa-bolt"></i> Diskon -<?= (int)$msPromo['discount'] ?>% Paket Tahunan</span>
                <h3 class="membership-promo-title"><?= htmlspecialchars($msPromo['title']) ?></h3>
                <p class="membership-promo-desc"><?= htmlspecialchars($msPromo['desc']) ?></p>
            </div>
            <div class="membership-promo-right">
                <div class="promo-card-timer" data-end="<?= htmlspecialchars($msPromo['end']) ?>">
                    <div class="promo-timer-item">
                        <span class="promo-timer-value" data-timer="days">00</span>
                        <span class="promo-timer-label">Hari</span>
                    </div>
                    <div class="promo-timer-item">
                        <span class="promo-timer-value" data-timer="hours">00</span>
                        <span class="promo-timer-label">Jam</span>
                    </div>
                    <div class="promo-timer-item">
                        <span class="promo-timer-value" data-timer="minutes">00</span>
                        <span class="promo-timer-label">Menit</span>
                    </div>
                    <div class="promo-timer-item">
                        <span class="promo-timer-value" data-timer="seconds">00</span>
                        <span class="promo-timer-label">Detik</span>
                    </div>
                </div>
                <a href="#home-plans" class="btn btn-primary btn-sm"><i class="fas fa-crown"></i> Klaim Promo</a>
            </div>
        </div>
        <?php endif; ?>

        <div style="text-align: center; margin-bottom: var(--space-2xl);" data-aos="fade-up">
            <span class="section-tag" style="color: var(--soft-gold);">Membership Premium</span>
            <h2 style="font-family: var(--font-display); font-size: var(--text-4xl); font-weight: 700; color: var(--text-white); margin-bottom: var(--space-sm);">
                Jadi Member <span class="gold-text">Premium</span>
            </h2>
            <p style="color: rgba(247,247,243,0.7); max-width: 640px; margin: 0 auto; line-height: 1.8;">
                Langsung nikmati level Gold, Platinum, atau Diamond tanpa menunggu total belanja —
                lengkap dengan poin berlipat &amp; benefit eksklusif. Paket aktif otomatis setelah pembayaran Anda diverifikasi.
            </p>
        </div>

        <?php if ($msUser): ?>
        <!-- ============ KARTU MEMBER SAYA ============ -->
        <div class="member-card home-member-card" data-aos="fade-up">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-lg);">
                <div style="display: flex; align-items: center; gap: var(--space-lg); flex-wrap: wrap;">
                    <div class="member-level-icon <?= $msMyLevel ?>">
                        <i class="fas <?= $msMyLevelDef['icon'] ?>"></i>
                    </div>
                    <div>
                        <p style="font-size: var(--text-sm); color: rgba(247,247,243,0.7); margin-bottom: 2px;">Halo, <?= htmlspecialchars($msUser['full_name']) ?> 👋</p>
                        <h2 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; color: var(--text-white); margin: 0;">
                            Member <?= $msMyLevelDef['label'] ?>
                        </h2>
                        <span class="member-mini-badge <?= $msMyLevel ?>"><i class="fas <?= $msMyLevelDef['icon'] ?>"></i> <?= $msMyLevelDef['label'] ?></span>
                        <?php if ($msActiveSub): ?>
                        <div class="member-sub-line">
                            <i class="fas fa-calendar-check"></i> Langganan <?= ucfirst($msActiveSub['level']) ?> (<?= $msActiveSub['period'] === 'yearly' ? 'Tahunan' : 'Bulanan' ?>) aktif s.d. <?= formatDate($msActiveSub['expires_at'], 'd F Y') ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display: flex; gap: var(--space-2xl); flex-wrap: wrap;">
                    <div style="text-align: center;">
                        <div class="member-stat-value"><?= number_format((int)($msUser['points'] ?? 0)) ?></div>
                        <div class="member-stat-label">Poin Saya</div>
                    </div>
                    <div style="text-align: center;">
                        <div class="member-stat-value">Rp <?= number_format((float)($msUser['total_spent'] ?? 0), 0, ',', '.') ?></div>
                        <div class="member-stat-label">Total Belanja</div>
                    </div>
                </div>
            </div>

            <?php if ($msNextLevel): ?>
            <div style="margin-top: var(--space-xl);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-sm); flex-wrap: wrap; gap: var(--space-sm);">
                    <p style="font-size: var(--text-sm); color: rgba(247,247,243,0.8); margin: 0;">
                        <i class="fas fa-arrow-up"></i> Progress menuju <strong><?= $msNextLevelDef['label'] ?></strong>
                    </p>
                    <p style="font-size: var(--text-sm); color: rgba(247,247,243,0.8); margin: 0;">
                        Sisa belanja <strong>Rp <?= number_format($msRemaining, 0, ',', '.') ?></strong> lagi
                    </p>
                </div>
                <div class="level-progress-track">
                    <div class="level-progress-bar <?= $msNextLevel ?>" style="width: <?= $msProgressPct ?>%;"></div>
                </div>
                <div style="display: flex; justify-content: space-between; margin-top: var(--space-xs); font-size: var(--text-xs); color: rgba(247,247,243,0.6);">
                    <span>Rp <?= number_format($msMyLevelDef['min_spend'], 0, ',', '.') ?></span>
                    <span><?= $msProgressPct >= 100 ? 'Level berikutnya siap! 🎉' : round($msProgressPct) . '%' ?></span>
                    <span>Rp <?= number_format($msNextLevelDef['min_spend'], 0, ',', '.') ?></span>
                </div>
            </div>
            <?php else: ?>
            <div style="margin-top: var(--space-xl); padding: var(--space-md) var(--space-lg); background: rgba(255,228,0,0.12); border: 1px solid rgba(255,228,0,0.3); border-radius: var(--radius-md);">
                <p style="margin: 0; color: var(--soft-gold); font-weight: 600;">
                    <i class="fas fa-crown"></i> Anda berada di level tertinggi! Terima kasih atas loyalitas Anda. 🏆
                </p>
            </div>
            <?php endif; ?>

            <div style="margin-top: var(--space-xl); display: flex; gap: var(--space-md); flex-wrap: wrap;">
                <a href="<?= SITE_URL ?>/pages/products.php" class="btn btn-primary btn-sm"><i class="fas fa-shopping-bag"></i> Belanja &amp; Kumpulkan Poin</a>
                <a href="<?= SITE_URL ?>/pages/membership.php" class="btn btn-outline btn-sm" style="border-color: rgba(255,228,0,0.4); color: var(--soft-gold);"><i class="fas fa-crown"></i> Lihat Detail Membership</a>
            </div>
        </div>
        <?php else: ?>
        <!-- CTA untuk pengunjung -->
        <div style="display: flex; align-items: center; justify-content: space-between; gap: var(--space-md); flex-wrap: wrap; padding: var(--space-lg) var(--space-xl); background: rgba(255,228,0,0.08); border: 1px solid rgba(255,228,0,0.3); border-radius: var(--radius-lg); margin-bottom: var(--space-2xl);" data-aos="fade-up">
            <div style="display: flex; align-items: center; gap: var(--space-md); flex-wrap: wrap;">
                <i class="fas fa-gift" style="color: var(--soft-gold); font-size: 1.4rem;"></i>
                <span style="color: rgba(247,247,243,0.85); font-size: var(--text-sm);">
                    Belum jadi member? <strong style="color: var(--soft-gold);">Daftar gratis</strong> &amp; kumpulkan poin dari setiap belanja — tukar jadi diskon di checkout!
                </span>
            </div>
            <div style="display: flex; gap: var(--space-sm); flex-wrap: wrap;">
                <a href="<?= SITE_URL ?>/auth/register.php" class="btn btn-primary btn-sm"><i class="fas fa-user-plus"></i> Daftar Gratis</a>
                <a href="<?= SITE_URL ?>/auth/login.php" class="btn btn-outline btn-sm" style="border-color: rgba(255,228,0,0.4); color: var(--soft-gold);"><i class="fas fa-sign-in-alt"></i> Login</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="grid grid-3" id="home-plans">
            <?php foreach ($msPlansByLevel as $lvl => $plansArr): $lvlDef = $msLevels[$lvl]; ?>
            <div class="plan-card <?= $lvl ?>" data-aos="fade-up" data-aos-delay="<?= 100 * (array_search($lvl, ['gold', 'platinum', 'diamond']) ?: 0) ?>">
                <div class="plan-card-header <?= $lvl ?>">
                    <i class="fas <?= $lvlDef['icon'] ?>"></i>
                    <h3 style="font-family: var(--font-display); font-size: var(--text-xl); font-weight: 700; color: var(--text-white); margin: 0;">Membership <?= $lvlDef['label'] ?></h3>
                </div>
                <div style="padding: var(--space-xl); display: flex; flex-direction: column; gap: var(--space-md); flex: 1;">
                    <div style="text-align: center; padding-bottom: var(--space-md); border-bottom: 1px dashed var(--soft-grey);">
                        <div style="font-size: var(--text-xs); color: var(--warm-orange); font-weight: 600; margin-bottom: 4px;">
                            <i class="fas fa-star"></i> Poin x<?= $lvlDef['multiplier'] ?> dari setiap pembelian
                        </div>
                        <div style="font-size: var(--text-xs); color: var(--text-muted);">
                            Min. belanja <?= $lvlDef['min_spend'] > 0 ? 'Rp ' . number_format($lvlDef['min_spend'], 0, ',', '.') : 'Gratis' ?>
                        </div>
                    </div>
                    <?php foreach ($plansArr as $pl):
                        $isYearly = $pl['period'] === 'yearly';
                        $promoP = membershipPromoPrice($pl['price'], $pl['period'], $msPromo);
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
                        </div>
                        <button class="btn btn-primary btn-sm" onclick="buyMembership(<?= (int)$pl['product_id'] ?>, '<?= $pl['period'] === 'yearly' ? 'yearly' : 'monthly' ?>')">
                            <i class="fas fa-crown"></i> Langganan
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="text-align: center; margin-top: var(--space-2xl);" data-aos="fade-up">
            <a href="<?= SITE_URL ?>/pages/membership.php" class="btn btn-outline btn-lg" style="border-color: rgba(255,228,0,0.4); color: var(--soft-gold);">
                <i class="fas fa-crown"></i> Pelajari Program Membership
            </a>
        </div>
    </div>
</section>
<?php endif; ?>
