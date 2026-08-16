    <!-- ============================================
          FOOTER
          ============================================ -->
    <div class="songket-strip songket-strip--footer" aria-hidden="true"></div>
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div>
                    <div class="footer-brand"><?= htmlspecialchars(getSetting('site_name', 'Nadhira Napoleon')) ?></div>
                    <p class="footer-description"><?= htmlspecialchars(getSetting('site_description', 'Pusat oleh-oleh premium khas Riau yang menghadirkan berbagai produk berkualitas. Kami berkomitmen memberikan pengalaman berbelanja terbaik dengan produk-produk pilihan yang menggugah selera.')) ?></p>
                    <div class="footer-social">
                        <?php $ig = getSetting('social_instagram', ''); if ($ig): ?>
                        <a href="https://instagram.com/<?= urlencode(ltrim($ig, '@')) ?>" aria-label="Instagram" target="_blank" rel="noopener">
                            <i class="fab fa-instagram"></i>
                        </a>
                        <?php endif; ?>
                        <?php $fb = getSetting('social_facebook', ''); if ($fb): ?>
                        <a href="https://facebook.com/<?= urlencode($fb) ?>" aria-label="Facebook" target="_blank" rel="noopener">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <?php endif; ?>
                        <?php $tt = getSetting('social_tiktok', ''); if ($tt): ?>
                        <a href="https://tiktok.com/@<?= urlencode(ltrim($tt, '@')) ?>" aria-label="TikTok" target="_blank" rel="noopener">
                            <i class="fab fa-tiktok"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <h4 class="footer-title">Menu</h4>
                    <ul class="footer-links">
                        <li><a href="#hero">Beranda</a></li>
                        <li><a href="#story">Tentang Kami</a></li>
                        <li><a href="#products">Produk</a></li>
                        <li><a href="#promo">Promo</a></li>
                        <li><a href="<?= SITE_URL ?>/pages/membership.php">Membership</a></li>
                        <li><a href="<?= SITE_URL ?>/pages/tracking.php">Tracking</a></li>
                        <li><a href="#branches">Cabang</a></li>
                        <li><a href="#contact">Kontak</a></li>
                        <li><a href="<?= SITE_URL ?>/pages/terms.php">Syarat &amp; Ketentuan</a></li>
                        <li><a href="<?= SITE_URL ?>/pages/privacy.php">Kebijakan Privasi</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="footer-title">Produk</h4>
                    <ul class="footer-links">
                        <li><a href="#">Napoleon</a></li>
                        <li><a href="#">Pancake Durian</a></li>
                        <li><a href="#">Mochi</a></li>
                        <li><a href="#">Cake</a></li>
                        <li><a href="#">Brownies</a></li>
                        <li><a href="#">Snack Premium</a></li>
                        <li><a href="#">Paket Oleh-Oleh</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="footer-title">Kontak Kami</h4>
                    <ul class="footer-contact">
                        <li>
                            <i class="fas fa-map-marker-alt"></i>
                            <span><?= nl2br(htmlspecialchars(getSetting('contact_address', 'Jl. Jenderal Sudirman No. 123, Pekanbaru, Riau'))) ?></span>
                        </li>
                        <li>
                            <i class="fas fa-phone-alt"></i>
                            <span><?= htmlspecialchars(getSetting('contact_phone', '0821-1234-5678')) ?></span>
                        </li>
                        <li>
                            <i class="fas fa-envelope"></i>
                            <span><?= htmlspecialchars(getSetting('contact_email', 'info@nadhiranapoleon.com')) ?></span>
                        </li>
                        <li>
                            <i class="fas fa-clock"></i>
                            <span><?= htmlspecialchars(getSetting('operational_hours', 'Setiap Hari, 08.00 - 21.00 WIB')) ?></span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <div class="footer-copyright">
                    &copy; <?= date('Y') ?> Nadhira Napoleon Pekanbaru. All rights reserved.
                </div>
                <div class="footer-payment">
                    <span style="color: rgba(255,248,240,0.5); font-size: var(--text-sm); margin-right: 8px;">Kami Menerima:</span>
                    <img src="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/visa.svg" alt="Visa" onerror="this.style.display='none'">
                    <img src="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/mastercard.svg" alt="Mastercard" onerror="this.style.display='none'">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="rgba(255,248,240,0.5)"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.94-.49-7-3.85-7-7.93s3.06-7.44 7-7.93v15.86zm2 0V4.07c3.94.49 7 3.85 7 7.93s-3.06 7.44-7 7.93z"/></svg>
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="rgba(255,248,240,0.5)"><path d="M21 18v1c0 1.1-.9 2-2 2H5c-1.11 0-2-.9-2-2V5c0-1.1.89-2 2-2h14c1.1 0 2 .9 2 2v1h-9c-1.11 0-2 .9-2 2v8c0 1.1.89 2 2 2h9zm-9-2h10V8H12v8zm4-2.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
                </div>
            </div>
        </div>
    </footer>

    <!-- ============================================
         FLOATING WHATSAPP BUTTON
         (label, pesan & link dapat diubah di Admin > Pengaturan)
         ============================================ -->
    <?php
    $waEnabled = trim(getSetting('wa_floating_enabled', '1'));
    $waLabel   = trim(getSetting('wa_floating_label', 'Chat Kami'));
    if ($waLabel === '') { $waLabel = 'Chat Kami'; }
    // Link kustom opsional; bila kosong, bangun otomatis dari nomor WhatsApp + pesan awal
    $waLink = trim(getSetting('wa_floating_link', ''));
    if ($waLink === '') {
        $waMsg = trim(getSetting('wa_floating_message', 'Halo Nadhira Napoleon, saya ingin bertanya tentang produk'));
        $waLink = getWhatsAppLink($waMsg);
    }
    ?>
    <?php if ($waEnabled === '1'): ?>
    <a href="<?= htmlspecialchars($waLink) ?>" 
       class="floating-whatsapp" 
       target="_blank" 
       rel="noopener" 
       aria-label="Hubungi via WhatsApp">
        <i class="fab fa-whatsapp"></i>
        <span class="floating-whatsapp-label"><?= htmlspecialchars($waLabel) ?></span>
    </a>
    <?php endif; ?>

    <!-- ============================================
         SCRIPTS
         ============================================ -->
    <script src="<?= ASSETS_URL ?>/js/main.js?v=2.0"></script>
    <script>
    function toggleUserDropdown(e) {
        e.stopPropagation();
        var dd = document.getElementById('userDropdown');
        if (dd) dd.classList.toggle('open');
    }
    document.addEventListener('click', function (event) {
        var dd = document.getElementById('userDropdown');
        if (dd && !event.target.closest('.user-dropdown-wrap')) {
            dd.classList.remove('open');
        }
    });
    </script>
</body>
</html>
