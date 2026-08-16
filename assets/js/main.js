/* ============================================
   NADHIRA NAPOLEON - MAIN JAVASCRIPT
   Premium Oleh-Oleh Khas Riau
   ============================================ */

'use strict';

// Wait for DOM to be ready
document.addEventListener('DOMContentLoaded', function() {
    initNavigation();
    initScrollEffects();
    initAOSAnimation();
    initFAQAccordion();
    initProductGallery();
    initQuantityButtons();
    initTabNavigation();
    initSmoothScroll();
    initHeroParallax();
    initCounterAnimation();
    initLazyLoading();
    initFloatingWhatsApp();
    initTestimonialSlider();
    initHeroSlider();
    initPaymentResult();
    initBranchDistances();
    initAnnouncementMarquee();
});

// ============================================
// ANNOUNCEMENT BAR MARQUEE (teks berjalan)
// Hanya berjalan bila teks lebih lebar dari bar
// ============================================
function initAnnouncementMarquee() {
    var boxes = document.querySelectorAll('[data-announcement-marquee]');
    if (!boxes.length) return;

    boxes.forEach(function (box) {
        if (box.classList.contains('marquee-off')) return; // dinonaktifkan di admin
        var track = box.querySelector('.top-announcement-marquee-track');
        var copy = box.querySelector('.ta-copy');
        if (!track || !copy) return;

        var update = function () {
            // data-active="false" => CSS mematikan animasi (teks muat / tidak perlu jalan)
            box.setAttribute('data-active', copy.scrollWidth > box.clientWidth ? 'true' : 'false');
        };
        update();
        window.addEventListener('resize', update);
        window.addEventListener('load', update);
        // Ukur ulang setelah font kustom selesai dimuat (lebar teks bisa berubah)
        if (document.fonts && document.fonts.ready && document.fonts.ready.then) {
            document.fonts.ready.then(update);
        }
    });
}

// ============================================
// INFO CABANG TERDEKAT DI HALAMAN PRODUK
// Dipakai oleh halaman katalog & detail produk:
// - Data cabang dirender server sebagai JSON di atribut data-branches
// - Lokasi customer diambil dari GPS sekali, di-cache 30 menit di localStorage
// - Jarak dihitung haversine di sisi client (tanpa request server)
// ============================================
function initBranchDistances() {
    var cards = document.querySelectorAll('[data-branches]');
    if (!cards.length) return;

    nnGetCachedLocation(function (lat, lng) {
        cards.forEach(function (card) {
            var branches = [];
            try { branches = JSON.parse(card.getAttribute('data-branches') || '[]'); } catch (e) { branches = []; }
            if (!branches.length) return;

            if (card.classList.contains('produk-card')) {
                renderCardBranchInfo(card, branches, lat, lng);
            } else if (card.classList.contains('branch-avail-panel')) {
                renderDetailBranchInfo(card, branches, lat, lng);
            }
        });
    });
}

// Lokasi customer: pakai cache localStorage (30 menit) jika ada, kalau tidak minta GPS sekali.
function nnGetCachedLocation(cb) {
    try {
        var raw = localStorage.getItem('nn_cust_loc');
        if (raw) {
            var loc = JSON.parse(raw);
            if (loc && loc.lat && loc.lng && (Date.now() - loc.ts) < 30 * 60 * 1000) {
                cb(parseFloat(loc.lat), parseFloat(loc.lng));
                return;
            }
        }
    } catch (e) {}

    var isSecureCtx = window.isSecureContext === true;
    var isLocalHost = location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    if (!navigator.geolocation || (!isSecureCtx && !isLocalHost)) { cb(null, null); return; }

    navigator.geolocation.getCurrentPosition(function (pos) {
        try {
            localStorage.setItem('nn_cust_loc', JSON.stringify({ lat: pos.coords.latitude, lng: pos.coords.longitude, ts: Date.now() }));
        } catch (e) {}
        cb(pos.coords.latitude, pos.coords.longitude);
    }, function () { cb(null, null); }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
}

function nnHaversineKm(lat1, lng1, lat2, lng2) {
    var R = 6371;
    var dLat = (lat2 - lat1) * Math.PI / 180;
    var dLng = (lng2 - lng1) * Math.PI / 180;
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function nnFormatKm(d) {
    if (d == null || isNaN(d)) return '';
    if (d < 1) return Math.round(d * 1000) + ' m';
    return d.toFixed(1).replace('.', ',') + ' km';
}

function nnEsc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Kartu katalog: tampilkan cabang terdekat (+ jumlah cabang lain)
function renderCardBranchInfo(card, branches, lat, lng) {
    var el = card.querySelector('.produk-card-branches');
    if (!el) return;

    if (lat != null && lng != null) {
        branches = branches.filter(function (b) { return parseFloat(b.lat) && parseFloat(b.lng); });
        if (!branches.length) { el.innerHTML = '<i class="fas fa-store" aria-hidden="true"></i> <span>Tersedia di beberapa cabang</span>'; return; }
        branches.forEach(function (b) { b._d = nnHaversineKm(lat, lng, parseFloat(b.lat), parseFloat(b.lng)); });
        branches.sort(function (a, b) { return a._d - b._d; });
        var top = branches[0];
        var names = branches.map(function (b) { return b.name; }).join(', ');
        var extra = branches.length > 1 ? ' <em>+' + (branches.length - 1) + ' cabang</em>' : '';
        el.innerHTML = '<i class="fas fa-store" aria-hidden="true"></i> <span title="Tersedia di: ' + nnEsc(names) + '">' +
            nnEsc(top.name) + ' ' + nnFormatKm(top._d) + extra + '</span>';
    } else {
        var names = branches.map(function (b) { return b.name; }).join(', ');
        el.innerHTML = '<i class="fas fa-store" aria-hidden="true"></i> <span title="Tersedia di: ' + nnEsc(names) + '">' +
            'Tersedia di ' + branches.length + ' cabang</span>';
    }
}

// Panel detail produk: isi jarak tiap cabang, urutkan terdekat, tandai badge Terdekat
function renderDetailBranchInfo(panel, branches, lat, lng) {
    var list = panel.querySelector('.branch-avail-list');
    if (!list) return;
    var items = Array.prototype.slice.call(list.querySelectorAll('.branch-avail-item'));
    if (!items.length) return;

    if (lat != null && lng != null) {
        var withDist = [];
        items.forEach(function (item) {
            var bLat = parseFloat(item.getAttribute('data-lat'));
            var bLng = parseFloat(item.getAttribute('data-lng'));
            var d = (bLat && bLng) ? nnHaversineKm(lat, lng, bLat, bLng) : null;
            withDist.push({ item: item, d: d });
        });
        withDist.sort(function (a, b) { return (a.d == null ? 1e9 : a.d) - (b.d == null ? 1e9 : b.d); });
        withDist.forEach(function (entry, idx) {
            var distEl = entry.item.querySelector('.branch-avail-dist');
            if (distEl) distEl.textContent = entry.d != null ? nnFormatKm(entry.d) : '';
            var badge = entry.item.querySelector('.branch-nearest-badge');
            if (badge) {
                if (idx === 0 && entry.d != null) { badge.style.display = 'inline-flex'; badge.textContent = 'Terdekat'; }
                else { badge.style.display = 'none'; }
            }
            list.appendChild(entry.item); // urut ulang DOM
        });
    } else {
        var names = branches.map(function (b) { return b.name; }).join(', ');
        var hint = panel.querySelector('.branch-avail-hint');
        if (hint) hint.innerHTML = 'Aktifkan lokasi untuk melihat jarak dari posisi Anda. Tersedia di: <strong>' + nnEsc(names) + '</strong>';
    }
}

// ============================================
// HASIL PEMBAYARAN (redirect dari Snap Midtrans)
// Setelah pembayaran selesai, pengguna diarahkan ke homepage
// dengan parameter ?pay=success|pending|error → tampilkan toast.
// ============================================
function initPaymentResult() {
    const params = new URLSearchParams(window.location.search);
    const pay = params.get('pay');
    if (!pay) return;

    let message = '';
    let type = 'info';

    if (pay === 'success') {
        message = 'Pembayaran berhasil! Terima kasih telah berbelanja di Nadhira Napoleon 🎉';
        type = 'success';
    } else if (pay === 'pending') {
        message = 'Pembayaran sedang diproses. Status pesanan Anda akan diperbarui otomatis.';
        type = 'info';
    } else if (pay === 'error') {
        message = 'Pembayaran gagal atau dibatalkan. Silakan coba bayar kembali dari invoice Anda.';
        type = 'error';
    }

    if (message) {
        showToast(message, type, 6000);
    }

    // Bersihkan parameter URL agar toast tidak muncul lagi saat halaman di-refresh
    const url = new URL(window.location.href);
    url.searchParams.delete('pay');
    history.replaceState(null, '', url.toString());
}

// ============================================
// NAVIGATION
// ============================================
function initNavigation() {
    const navbar = document.querySelector('.navbar');
    const toggle = document.querySelector('.navbar-toggle');
    const menu = document.querySelector('.navbar-menu');
    const overlay = document.querySelector('.navbar-overlay');

    if (!navbar) return;

    // Toggle mobile menu
    if (toggle) {
        toggle.addEventListener('click', function() {
            this.classList.toggle('active');
            menu.classList.toggle('active');
            overlay.classList.toggle('active');
            document.body.style.overflow = menu.classList.contains('active') ? 'hidden' : '';
        });
    }

    // Close menu on overlay click
    if (overlay) {
        overlay.addEventListener('click', function() {
            toggle.classList.remove('active');
            menu.classList.remove('active');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        });
    }

    // Close menu on link click
    if (menu) {
        menu.querySelectorAll('.navbar-link').forEach(link => {
            link.addEventListener('click', function() {
                toggle.classList.remove('active');
                menu.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            });
        });
    }

    // Navbar scroll effect
    let lastScroll = 0;
    window.addEventListener('scroll', function() {
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 100) {
            navbar.classList.add('scrolled');
        } else {
            navbar.classList.remove('scrolled');
        }
        
        lastScroll = currentScroll;
    }, { passive: true });
}

// ============================================
// SCROLL EFFECTS
// ============================================
function initScrollEffects() {
    // Active nav link based on scroll
    const sections = document.querySelectorAll('section[id]');
    const navLinks = document.querySelectorAll('.navbar-link');

    if (sections.length === 0) return;

    window.addEventListener('scroll', function() {
        let current = '';
        
        sections.forEach(section => {
            const sectionTop = section.offsetTop - 150;
            if (pageYOffset >= sectionTop) {
                current = section.getAttribute('id');
            }
        });

        navLinks.forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href') === '#' + current) {
                link.classList.add('active');
            }
        });
    }, { passive: true });
}

// ============================================
// AOS ANIMATION (Custom implementation)
// ============================================
function initAOSAnimation() {
    const animatedElements = document.querySelectorAll('[data-aos]');
    
    if (animatedElements.length === 0) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('aos-animate');
                observer.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    });

    animatedElements.forEach(el => observer.observe(el));
}

// ============================================
// FAQ ACCORDION
// ============================================
function initFAQAccordion() {
    const faqItems = document.querySelectorAll('.faq-item');

    faqItems.forEach(item => {
        const question = item.querySelector('.faq-question');
        
        if (!question) return;

        question.addEventListener('click', function() {
            const isActive = item.classList.contains('active');
            
            // Close all
            faqItems.forEach(i => i.classList.remove('active'));
            
            // Toggle current
            if (!isActive) {
                item.classList.add('active');
            }
        });
    });
}

// ============================================
// PRODUCT GALLERY
// ============================================
function initProductGallery() {
    const mainImage = document.querySelector('.product-gallery-main img');
    const thumbs = document.querySelectorAll('.product-gallery-thumb');

    if (!mainImage || thumbs.length === 0) return;

    thumbs.forEach(thumb => {
        thumb.addEventListener('click', function() {
            const imgSrc = this.querySelector('img').getAttribute('src');
            mainImage.setAttribute('src', imgSrc);
            
            thumbs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
        });
    });
}

// ============================================
// QUANTITY BUTTONS
// ============================================
function initQuantityButtons() {
    const qtyBtns = document.querySelectorAll('.qty-btn');

    qtyBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const input = this.parentElement.querySelector('.qty-input');
            if (!input) return;

            let value = parseInt(input.value) || 0;
            
            if (this.classList.contains('minus')) {
                value = Math.max(1, value - 1);
            } else {
                value = Math.min(99, value + 1);
            }
            
            input.value = value;
        });
    });
}

// ============================================
// TAB NAVIGATION
// ============================================
function initTabNavigation() {
    const tabNavs = document.querySelectorAll('.tab-nav');

    tabNavs.forEach(nav => {
        const items = nav.querySelectorAll('.tab-nav-item');
        const parent = nav.parentElement;
        const contents = parent.querySelectorAll('.tab-content');

        items.forEach(item => {
            item.addEventListener('click', function() {
                const target = this.getAttribute('data-tab');
                
                items.forEach(i => i.classList.remove('active'));
                this.classList.add('active');
                
                contents.forEach(content => {
                    content.classList.remove('active');
                    if (content.getAttribute('data-tab-content') === target) {
                        content.classList.add('active');
                    }
                });
            });
        });
    });
}

// ============================================
// SMOOTH SCROLL
// ============================================
function initSmoothScroll() {
    // Handle both #hash links AND full URL + hash links (e.g. http://localhost/nad#promo)
    document.querySelectorAll('a[href*="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (href === '#' || href.endsWith('#')) return;
            
            // Extract the hash (part after #)
            const hashIndex = href.indexOf('#');
            const hash = href.substring(hashIndex);
            if (!hash || hash === '#') return;
            
            // Check if target exists on current page
            const target = document.querySelector(hash);
            if (!target) return;
            
            // Only smooth-scroll if target is on the SAME page
            const pagePath = href.substring(0, hashIndex);
            if (pagePath && !window.location.href.includes(pagePath)) return;
            
            e.preventDefault();
            
            const navbarHeight = document.querySelector('.navbar')?.offsetHeight || 0;
            const targetPosition = target.offsetTop - navbarHeight;
            
            window.scrollTo({
                top: targetPosition,
                behavior: 'smooth'
            });
        });
    });
}

// ============================================
// HERO PARALLAX
// ============================================
function initHeroParallax() {
    const hero = document.querySelector('.hero');
    const heroContent = document.querySelector('.hero-content');

    if (!hero || !heroContent) return;

    window.addEventListener('scroll', function() {
        const scrollPosition = window.pageYOffset;
        if (scrollPosition < hero.offsetHeight) {
            heroContent.style.transform = `translateY(${scrollPosition * 0.3}px)`;
            heroContent.style.opacity = 1 - (scrollPosition / hero.offsetHeight);
        }
    }, { passive: true });
}

// ============================================
// COUNTER ANIMATION
// ============================================
function initCounterAnimation() {
    const counters = document.querySelectorAll('[data-counter]');

    if (counters.length === 0) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const target = entry.target;
                const targetValue = parseInt(target.getAttribute('data-counter'));
                const duration = 2000;
                const step = Math.ceil(targetValue / (duration / 16));
                let current = 0;

                const increment = setInterval(() => {
                    current += step;
                    if (current >= targetValue) {
                        current = targetValue;
                        clearInterval(increment);
                    }
                    
                    // Format with suffix
                    if (targetValue >= 1000000) {
                        target.textContent = (current / 1000000).toFixed(1) + 'Jt+';
                    } else if (targetValue >= 1000) {
                        target.textContent = (current / 1000).toFixed(0) + 'RB+';
                    } else {
                        target.textContent = current + '+';
                    }
                }, 16);

                observer.unobserve(target);
            }
        });
    }, { threshold: 0.5 });

    counters.forEach(counter => observer.observe(counter));
}

// ============================================
// LAZY LOADING
// ============================================
function initLazyLoading() {
    const images = document.querySelectorAll('img[data-src]');

    if (images.length === 0) return;

    const imageObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.setAttribute('src', img.getAttribute('data-src'));
                img.addEventListener('load', function() {
                    this.classList.add('loaded');
                });
                img.removeAttribute('data-src');
                imageObserver.unobserve(img);
            }
        });
    }, {
        rootMargin: '100px 0px'
    });

    images.forEach(img => imageObserver.observe(img));
}

// ============================================
// FLOATING WHATSAPP
// ============================================
function initFloatingWhatsApp() {
    const whatsapp = document.querySelector('.floating-whatsapp');

    if (!whatsapp) return;

    // Show/hide on scroll
    window.addEventListener('scroll', function() {
        const scrollPosition = window.pageYOffset;
        if (scrollPosition > 500) {
            whatsapp.style.opacity = '1';
            whatsapp.style.pointerEvents = 'all';
        } else {
            whatsapp.style.opacity = '0';
            whatsapp.style.pointerEvents = 'none';
        }
    }, { passive: true });
}

// ============================================
// TOAST NOTIFICATION
// ============================================
function showToast(message, type = 'info', duration = 4000) {
    const container = document.querySelector('.toast-container');
    if (!container) return;

    const icons = {
        success: '✓',
        error: '✕',
        info: 'ℹ'
    };

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <span class="toast-icon">${icons[type]}</span>
        <span class="toast-message">${message}</span>
        <button class="toast-close">&times;</button>
    `;

    container.appendChild(toast);

    // Close button
    toast.querySelector('.toast-close').addEventListener('click', function() {
        toast.remove();
    });

    // Auto remove
    setTimeout(() => {
        if (toast.parentElement) {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => toast.remove(), 300);
        }
    }, duration);
}

// ============================================
// ADD TO CART (AJAX)
// ============================================
function addToCart(productId, quantity = 1) {
    const formData = new FormData();
    formData.append('action', 'add_to_cart');
    formData.append('product_id', productId);
    formData.append('quantity', quantity);

    fetch(AJAX_URL + '/cart.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCartCount(data.cart_count);
            showToast('Produk berhasil ditambahkan ke keranjang!', 'success');
        } else {
            showToast(data.message || 'Gagal menambahkan produk', 'error');
        }
    })
    .catch(error => {
        showToast('Terjadi kesalahan. Silakan coba lagi.', 'error');
    });
}

// ============================================
// UPDATE CART COUNT
// ============================================
function updateCartCount(count) {
    const badges = document.querySelectorAll('.cart-count');
    badges.forEach(badge => {
        badge.textContent = count;
        badge.style.transform = 'scale(1.3)';
        setTimeout(() => {
            badge.style.transform = 'scale(1)';
        }, 200);
    });
}

// ============================================
// BUY MEMBERSHIP PLAN (AJAX)
// Paket langganan premium: wajib login, tambah ke keranjang, lalu arahkan ke keranjang
// ============================================
function buyMembership(productId, period) {
    if (!window.NN_LOGGED_IN) {
        if (confirm('Anda harus login terlebih dahulu untuk berlangganan membership. Login sekarang?')) {
            window.location.href = SITE_URL + '/auth/login.php';
        }
        return;
    }

    const formData = new FormData();
    formData.append('action', 'add_to_cart');
    formData.append('product_id', productId);
    formData.append('quantity', 1);

    fetch(AJAX_URL + '/cart.php', {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            showToast('Paket membership ditambahkan ke keranjang!', 'success');
            updateCartCount(data.cart_count || '');
            setTimeout(function() {
                window.location.href = SITE_URL + '/pages/cart.php';
            }, 900);
        } else {
            showToast(data.message || 'Gagal menambahkan paket', 'error');
        }
    })
    .catch(function() {
        showToast('Terjadi kesalahan. Silakan coba lagi.', 'error');
    });
}

// ============================================
// BUY PACKAGE (AJAX) - Paket Spesial homepage
// Tambah paket ke keranjang lalu arahkan ke halaman keranjang
// ============================================
function buyPackage(productId) {
    if (!productId) {
        showToast('Paket tidak tersedia saat ini', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'add_to_cart');
    formData.append('product_id', productId);
    formData.append('quantity', 1);

    fetch(AJAX_URL + '/cart.php', {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            showToast('Paket ditambahkan ke keranjang!', 'success');
            updateCartCount(data.cart_count || '');
            setTimeout(function() {
                window.location.href = SITE_URL + '/pages/cart.php';
            }, 900);
        } else {
            showToast(data.message || 'Gagal menambahkan paket', 'error');
        }
    })
    .catch(function() {
        showToast('Terjadi kesalahan. Silakan coba lagi.', 'error');
    });
}

// ============================================
// PROMO COUNTDOWN TIMER (multi-timer)
// Dipakai untuk banner promo membership & kartu promo hari ini
// ============================================
function startPromoTimers() {
    const timers = document.querySelectorAll('.promo-card-timer[data-end]');
    if (!timers.length) return;

    function updateAllTimers() {
        const now = new Date().getTime();

        timers.forEach(function(timer) {
            const endDate = new Date(timer.getAttribute('data-end')).getTime();
            const diff = endDate - now;

            if (diff <= 0) {
                timer.querySelector('[data-timer="days"]').textContent = '00';
                timer.querySelector('[data-timer="hours"]').textContent = '00';
                timer.querySelector('[data-timer="minutes"]').textContent = '00';
                timer.querySelector('[data-timer="seconds"]').textContent = '00';
                return;
            }

            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);

            timer.querySelector('[data-timer="days"]').textContent = String(days).padStart(2, '0');
            timer.querySelector('[data-timer="hours"]').textContent = String(hours).padStart(2, '0');
            timer.querySelector('[data-timer="minutes"]').textContent = String(minutes).padStart(2, '0');
            timer.querySelector('[data-timer="seconds"]').textContent = String(seconds).padStart(2, '0');
        });
    }

    updateAllTimers();
    setInterval(updateAllTimers, 1000);
}

document.addEventListener('DOMContentLoaded', startPromoTimers);

// ============================================
// TOGGLE WISHLIST - Visual Heart Toggle
// ============================================

// Update a single heart icon based on product ID
function setWishlistIcon(productId, inWishlist) {
    const iconClass = inWishlist ? 'fas fa-heart' : 'far fa-heart';
    const buttons = document.querySelectorAll('[data-product-id="' + productId + '"]');
    buttons.forEach(function(btn) {
        const icon = btn.querySelector('.wishlist-icon');
        if (icon) icon.className = iconClass + ' wishlist-icon';
    });
}

function toggleWishlist(productId, btn) {
    // Optimistic visual toggle
    const currentIcon = btn ? btn.querySelector('.wishlist-icon') : null;
    const wasFilled = currentIcon && currentIcon.classList.contains('fas');

    if (currentIcon) {
        currentIcon.className = (wasFilled ? 'far' : 'fas') + ' fa-heart wishlist-icon';
    }

    const formData = new FormData();
    formData.append('action', 'toggle_wishlist');
    formData.append('product_id', productId);

    fetch(AJAX_URL + '/wishlist.php', {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            setWishlistIcon(productId, data.added);
            if (data.added) {
                showToast('Produk ditambahkan ke wishlist!', 'success');
            } else {
                showToast('Produk dihapus dari wishlist.', 'info');
            }
            updateWishlistCount();
        } else if (data.redirect) {
            window.location.href = data.redirect;
        } else {
            setWishlistIcon(productId, wasFilled);
        }
    })
    .catch(function() {
        setWishlistIcon(productId, wasFilled);
    });
}

// Load wishlist state on page load and sync icons + count
function initWishlistIcons() {
    const wishlistBadge = document.getElementById('wishlistCount');
    if (!wishlistBadge) return;

    const formData = new FormData();
    formData.append('action', 'get_wishlist');

    fetch(AJAX_URL + '/wishlist.php', {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success && data.data) {
            const count = data.data.length;
            const badges = document.querySelectorAll('.wishlist-count');
            badges.forEach(function(badge) {
                badge.textContent = count > 0 ? count : '';
            });
            const wishlistIds = {};
            data.data.forEach(function(item) {
                wishlistIds[item.product_id] = true;
            });
            document.querySelectorAll('[data-product-id]').forEach(function(btn) {
                const pid = btn.getAttribute('data-product-id');
                if (pid && wishlistIds[pid]) {
                    const icon = btn.querySelector('.wishlist-icon');
                    if (icon) icon.className = 'fas fa-heart wishlist-icon';
                }
            });
        }
    });
}

// Update wishlist count badge (after toggle)
function updateWishlistCount() {
    const formData = new FormData();
    formData.append('action', 'get_wishlist');

    fetch(AJAX_URL + '/wishlist.php', {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success && data.data) {
            const count = data.data.length;
            const badges = document.querySelectorAll('.wishlist-count');
            badges.forEach(function(badge) {
                badge.textContent = count > 0 ? count : '';
                badge.style.transform = 'scale(1.3)';
                setTimeout(function() {
                    badge.style.transform = 'scale(1)';
                }, 200);
            });
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    initWishlistIcons();
});

// ============================================
// GSAP ANIMATIONS (if GSAP is loaded)
// ============================================
function initGsapAnimations() {
    if (typeof gsap === 'undefined') return;

    // Hero section entrance
    gsap.from('.hero-content', {
        duration: 1.5,
        y: 60,
        opacity: 0,
        ease: 'power3.out'
    });

    // Floating animation for decorative elements
    gsap.to('.hero-decoration-1', {
        duration: 3,
        y: 20,
        ease: 'power1.inOut',
        yoyo: true,
        repeat: -1
    });

    gsap.to('.hero-decoration-2', {
        duration: 4,
        y: -20,
        ease: 'power1.inOut',
        yoyo: true,
        repeat: -1
    });
}

// Initialize GSAP if loaded
window.addEventListener('load', function() {
    if (typeof gsap !== 'undefined') {
        initGsapAnimations();
    }
});

// ============================================
// VIDEO GALLERY - Global state for modal navigation
// ============================================
let videoModalState = {
    currentIndex: 0,
    videoList: []
};

document.addEventListener('DOMContentLoaded', function() {
    // Read video list from gallery container
    const gallery = document.querySelector('.video-gallery');
    if (gallery && gallery.getAttribute('data-video-list')) {
        try {
            videoModalState.videoList = JSON.parse(gallery.getAttribute('data-video-list'));
        } catch(e) {}
    }

    // Click handler for video thumbnails
    document.querySelectorAll('[data-video-url]').forEach(function(el) {
        el.addEventListener('click', function() {
            const url = this.getAttribute('data-video-url');
            const index = parseInt(this.getAttribute('data-video-index')) || 0;
            if (url) openVideoModal(url, index);
        });
    });
});

// ============================================
// VIDEO MODAL / LIGHTBOX
// ============================================
function openVideoModal(url, index) {
    const modal = document.getElementById('videoModal');
    const content = document.getElementById('videoModalContent');
    const titleEl = document.getElementById('videoModalTitle');

    if (!modal || !content) return;

    videoModalState.currentIndex = (index !== undefined) ? index : 0;

    content.innerHTML = '';
    if (titleEl) titleEl.textContent = '';

    loadVideo(url);

    // Add navigation buttons
    addNavButtons(modal);

    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', handleVideoModalEscape);
    updateNavButtons();
}

function loadVideo(url) {
    const content = document.getElementById('videoModalContent');
    const titleEl = document.getElementById('videoModalTitle');
    if (!content) return;

    content.innerHTML = '';
    if (titleEl) titleEl.textContent = '';

    let embedUrl = '';
    let platform = 'other';

    const ytMatch = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]+)/);
    if (ytMatch) {
        embedUrl = 'https://www.youtube.com/embed/' + ytMatch[1] + '?autoplay=1&rel=0';
        platform = 'youtube';
    }

    const igMatch = url.match(/instagram\.com\/(?:p|reel)\/([a-zA-Z0-9_-]+)/);
    if (igMatch) {
        const cleanPath = url.indexOf('/p/') !== -1 ? 'p' : 'reel';
        embedUrl = 'https://www.instagram.com/' + cleanPath + '/' + igMatch[1] + '/embed';
        platform = 'instagram';
    }

    if (embedUrl) {
        const iframe = document.createElement('iframe');
        iframe.setAttribute('src', embedUrl);
        iframe.setAttribute('frameborder', '0');
        iframe.setAttribute('allowfullscreen', 'true');
        iframe.setAttribute('allow', 'autoplay; fullscreen');
        content.appendChild(iframe);
        if (titleEl) {
            titleEl.textContent = (videoModalState.currentIndex + 1) + '/' + videoModalState.videoList.length + ' — ' + (platform.charAt(0).toUpperCase() + platform.slice(1));
        }
    } else {
        window.open(url, '_blank', 'noopener');
        return;
    }
}

function addNavButtons(modal) {
    // Only add if not already present
    if (modal.querySelector('.video-modal-prev')) return;

    const navHtml = `
        <button class="video-modal-nav video-modal-prev" onclick="navigateVideo(-1)" aria-label="Video sebelumnya">
            <i class="fas fa-chevron-left"></i>
        </button>
        <button class="video-modal-nav video-modal-next" onclick="navigateVideo(1)" aria-label="Video selanjutnya">
            <i class="fas fa-chevron-right"></i>
        </button>
    `;
    modal.insertAdjacentHTML('afterbegin', navHtml);
}

function navigateVideo(direction) {
    const total = videoModalState.videoList.length;
    if (total === 0) return;

    let newIndex = videoModalState.currentIndex + direction;
    if (newIndex < 0) newIndex = total - 1;
    if (newIndex >= total) newIndex = 0;

    videoModalState.currentIndex = newIndex;
    const url = videoModalState.videoList[newIndex];
    if (url) {
        loadVideo(url);
        updateNavButtons();
    }
}

function updateNavButtons() {
    const prev = document.querySelector('.video-modal-prev');
    const next = document.querySelector('.video-modal-next');
    const total = videoModalState.videoList.length;
    if (total <= 1) {
        if (prev) prev.style.display = 'none';
        if (next) next.style.display = 'none';
        return;
    }
    if (prev) prev.style.display = '';
    if (next) next.style.display = '';
}

function closeVideoModal(event) {
    if (event && event.target !== event.currentTarget) return;
    const modal = document.getElementById('videoModal');
    const content = document.getElementById('videoModalContent');
    if (!modal) return;
    modal.classList.remove('active');
    if (content) content.innerHTML = '';
    document.body.style.overflow = '';
    document.removeEventListener('keydown', handleVideoModalEscape);
    // Remove nav buttons for next open
    const navs = modal.querySelectorAll('.video-modal-nav');
    navs.forEach(function(n) { n.remove(); });
}

function handleVideoModalEscape(e) {
    if (e.key === 'Escape') closeVideoModal();
}

// ============================================
// TESTIMONIAL SLIDER / CAROUSEL
// ============================================
function initTestimonialSlider() {
    const slider = document.getElementById('testimonialSlider');
    if (!slider) return;

    const track = document.getElementById('testimonialTrack');
    const dotsContainer = document.getElementById('testimonialDots');
    const prevBtn = document.getElementById('testPrev');
    const nextBtn = document.getElementById('testNext');
    const slides = track.querySelectorAll('.testimonial-slide');
    const totalSlides = slides.length;

    if (totalSlides <= 1) {
        if (prevBtn) prevBtn.style.display = 'none';
        if (nextBtn) nextBtn.style.display = 'none';
        return;
    }

    let currentIndex = 0;
    let autoplayInterval = null;
    const AUTOPLAY_DELAY = 5000; // 5 seconds

    // Play/Pause toggle button
    const toggleBtn = document.getElementById('testToggle');
    let isPlaying = true;

    function updateToggleIcon() {
        if (!toggleBtn) return;
        const icon = toggleBtn.querySelector('i');
        if (icon) {
            icon.className = isPlaying ? 'fas fa-pause' : 'fas fa-play';
        }
        toggleBtn.classList.toggle('paused', !isPlaying);
        toggleBtn.setAttribute('aria-label', isPlaying ? 'Jeda putar otomatis' : 'Putar otomatis');
    }

    function toggleAutoplay() {
        isPlaying = !isPlaying;
        if (isPlaying) {
            startAutoplay();
        } else {
            stopAutoplay();
        }
        updateToggleIcon();
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleAutoplay);
    }

    // Override startAutoplay to track state
    const originalStartAutoplay = startAutoplay;
    const originalStopAutoplay = stopAutoplay;

    startAutoplay = function() {
        originalStartAutoplay();
        isPlaying = true;
        updateToggleIcon();
    };

    stopAutoplay = function() {
        originalStopAutoplay();
        isPlaying = false;
        updateToggleIcon();
    };

    // Create dots
    for (let i = 0; i < totalSlides; i++) {
        const dot = document.createElement('button');
        dot.className = 'dot' + (i === 0 ? ' active' : '');
        dot.setAttribute('aria-label', 'Testimonial ke-' + (i + 1));
        dot.addEventListener('click', function() {
            goToSlide(i);
            resetAutoplay();
        });
        dotsContainer.appendChild(dot);
    }

    function goToSlide(index) {
        currentIndex = index;
        track.style.transform = 'translateX(-' + (index * 100) + '%)';

        // Update dots
        const dots = dotsContainer.querySelectorAll('.dot');
        dots.forEach(function(d, i) {
            d.classList.toggle('active', i === index);
        });
    }

    function nextSlide() {
        const next = (currentIndex + 1) % totalSlides;
        goToSlide(next);
    }

    function prevSlide() {
        const prev = (currentIndex - 1 + totalSlides) % totalSlides;
        goToSlide(prev);
    }

    function startAutoplay() {
        stopAutoplay();
        autoplayInterval = setInterval(nextSlide, AUTOPLAY_DELAY);
    }

    function stopAutoplay() {
        if (autoplayInterval) {
            clearInterval(autoplayInterval);
            autoplayInterval = null;
        }
    }

    function resetAutoplay() {
        startAutoplay();
    }

    // Event listeners
    if (prevBtn) prevBtn.addEventListener('click', function() { prevSlide(); resetAutoplay(); });
    if (nextBtn) nextBtn.addEventListener('click', function() { nextSlide(); resetAutoplay(); });

    // Pause on hover — respect manual toggle
    let isHoverPausing = false;
    slider.addEventListener('mouseenter', function() {
        if (!isPlaying) return; // Already manually paused
        isHoverPausing = true;
        stopAutoplay();
    });
    slider.addEventListener('mouseleave', function() {
        if (!isHoverPausing) return;
        isHoverPausing = false;
        if (!isPlaying) return; // Manually paused during hover
        startAutoplay();
    });

    // Touch / swipe support
    let touchStartX = 0;
    let touchEndX = 0;

    slider.addEventListener('touchstart', function(e) {
        touchStartX = e.changedTouches[0].screenX;
        stopAutoplay();
    }, { passive: true });

    slider.addEventListener('touchend', function(e) {
        touchEndX = e.changedTouches[0].screenX;
        const diff = touchStartX - touchEndX;
        if (Math.abs(diff) > 50) {
            if (diff > 0) {
                nextSlide();
            } else {
                prevSlide();
            }
        }
        startAutoplay();
    }, { passive: true });

    // Keyboard navigation — listen on document, only when slider is visible
    function handleSliderKeydown(e) {
        const rect = slider.getBoundingClientRect();
        const isVisible = rect.top < window.innerHeight && rect.bottom > 0;
        if (!isVisible) return;
        if (e.key === 'ArrowLeft') { e.preventDefault(); prevSlide(); resetAutoplay(); }
        if (e.key === 'ArrowRight') { e.preventDefault(); nextSlide(); resetAutoplay(); }
    }
    document.addEventListener('keydown', handleSliderKeydown);

    // Start autoplay
    startAutoplay();
}

// ============================================
// HERO SLIDER / CAROUSEL
// ============================================
function initHeroSlider() {
    const slider = document.getElementById('heroSlider');
    if (!slider) return;

    const slides = slider.querySelectorAll('.hero-slide');
    const totalSlides = slides.length;
    if (totalSlides <= 1) return;

    const dotsContainer = document.getElementById('heroSliderDots');
    const prevBtn = document.getElementById('heroSliderPrev');
    const nextBtn = document.getElementById('heroSliderNext');

    let currentIndex = 0;
    let autoplayInterval = null;
    const AUTOPLAY_DELAY = 6000; // 6 detik

    // Build dots
    for (let i = 0; i < totalSlides; i++) {
        const dot = document.createElement('button');
        dot.className = 'dot' + (i === 0 ? ' active' : '');
        dot.setAttribute('aria-label', 'Slide ke-' + (i + 1));
        dot.addEventListener('click', function() {
            goToSlide(i);
            resetAutoplay();
        });
        if (dotsContainer) dotsContainer.appendChild(dot);
    }

    function goToSlide(index) {
        currentIndex = index;
        slides.forEach(function(slide, i) {
            slide.classList.toggle('active', i === index);
        });
        if (dotsContainer) {
            const dots = dotsContainer.querySelectorAll('.dot');
            dots.forEach(function(d, i) {
                d.classList.toggle('active', i === index);
            });
        }
    }

    function nextSlide() {
        goToSlide((currentIndex + 1) % totalSlides);
    }

    function prevSlide() {
        goToSlide((currentIndex - 1 + totalSlides) % totalSlides);
    }

    function startAutoplay() {
        stopAutoplay();
        autoplayInterval = setInterval(nextSlide, AUTOPLAY_DELAY);
    }

    function stopAutoplay() {
        if (autoplayInterval) {
            clearInterval(autoplayInterval);
            autoplayInterval = null;
        }
    }

    function resetAutoplay() {
        startAutoplay();
    }

    // Arrows
    if (prevBtn) prevBtn.addEventListener('click', function() { prevSlide(); resetAutoplay(); });
    if (nextBtn) nextBtn.addEventListener('click', function() { nextSlide(); resetAutoplay(); });

    // Pause on hover
    const heroSection = document.querySelector('.hero');
    if (heroSection) {
        heroSection.addEventListener('mouseenter', function() {
            stopAutoplay();
        });
        heroSection.addEventListener('mouseleave', function() {
            startAutoplay();
        });
    }

    // Touch / swipe support
    let touchStartX = 0;
    let touchEndX = 0;

    slider.addEventListener('touchstart', function(e) {
        touchStartX = e.changedTouches[0].screenX;
        stopAutoplay();
    }, { passive: true });

    slider.addEventListener('touchend', function(e) {
        touchEndX = e.changedTouches[0].screenX;
        const diff = touchStartX - touchEndX;
        if (Math.abs(diff) > 50) {
            if (diff > 0) {
                nextSlide();
            } else {
                prevSlide();
            }
        }
        startAutoplay();
    }, { passive: true });

    // Keyboard navigation
    function handleHeroKeydown(e) {
        const rect = slider.getBoundingClientRect();
        const isVisible = rect.top < window.innerHeight && rect.bottom > 0;
        if (!isVisible) return;
        if (e.key === 'ArrowLeft') { e.preventDefault(); prevSlide(); resetAutoplay(); }
        if (e.key === 'ArrowRight') { e.preventDefault(); nextSlide(); resetAutoplay(); }
    }
    document.addEventListener('keydown', handleHeroKeydown);

    // Start autoplay
    startAutoplay();
}
