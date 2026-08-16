<?php
// ============================================
// ARTIKEL DETAIL PAGE
// Menampilkan konten artikel lengkap dari database
// Website Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';

$conn = getConnection();
$slug = $_GET['slug'] ?? '';

// Get article by slug
$article = null;
if ($slug && $conn) {
    $slug = $conn->real_escape_string($slug);
    $result = $conn->query("SELECT * FROM articles WHERE slug = '$slug' AND is_published = 1 LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $article = $result->fetch_assoc();
    }
}

// If article not found, redirect or show 404
if (!$article) {
    header('HTTP/1.0 404 Not Found');
    $page_title = 'Artikel Tidak Ditemukan';
    $meta_description = 'Artikel yang Anda cari tidak ditemukan.';
    include '../includes/header.php';
    ?>
    <section style="min-height: 80vh; display: flex; align-items: center; padding-top: calc(var(--navbar-total-height, 80px) + 8px);">
        <div class="container">
            <div style="text-align: center; max-width: 500px; margin: 0 auto;">
                <i class="fas fa-newspaper" style="font-size: 4rem; color: var(--soft-gold); opacity: 0.5; margin-bottom: var(--space-xl);"></i>
                <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; margin-bottom: var(--space-md);">
                    Artikel <span class="gold-text">Tidak Ditemukan</span>
                </h1>
                <p style="color: var(--text-muted); margin-bottom: var(--space-2xl);">
                    Maaf, artikel yang Anda cari tidak tersedia atau telah dihapus.
                </p>
                <a href="<?= SITE_URL ?>#articles" class="btn btn-primary btn-lg">
                    <i class="fas fa-arrow-left"></i>
                    Kembali ke Artikel
                </a>
            </div>
        </div>
    </section>
    <?php
    include '../includes/footer.php';
    exit;
}

$page_title = htmlspecialchars($article['title']);
$meta_description = htmlspecialchars($article['excerpt'] ?: strip_tags(substr($article['content'], 0, 160)));
$meta_image = $article['image'] ?: ASSETS_URL . '/images/og-image.jpg';
include '../includes/header.php';

// Get related articles (same category or latest)
$relatedArticles = [];
if ($conn) {
    $relatedQuery = $conn->query("SELECT slug, title, excerpt, image, published_at, content FROM articles WHERE is_published = 1 AND slug != '$slug' ORDER BY published_at DESC LIMIT 3");
    if ($relatedQuery) {
        while ($row = $relatedQuery->fetch_assoc()) {
            $relatedArticles[] = $row;
        }
    }
}

// Format date
$pubDate = date('d F Y', strtotime($article['published_at']));
$readTime = ceil(str_word_count(strip_tags($article['content'])) / 200) . ' min read';
?>

<section class="article-detail-section">
    <div class="container container-narrow">
        <!-- Breadcrumb -->
        <div class="breadcrumb" data-aos="fade-up">
            <a href="<?= SITE_URL ?>">Home</a>
            <span class="separator">/</span>
            <a href="<?= SITE_URL ?>#articles">Artikel</a>
            <span class="separator">/</span>
            <span class="current"><?= htmlspecialchars($article['title']) ?></span>
        </div>

        <!-- Article Header -->
        <header class="article-header" data-aos="fade-up">
            <div class="article-meta-top">
                <span class="article-category-badge">
                    <i class="fas fa-pen-fancy"></i>
                    <?= htmlspecialchars($article['author'] ?: 'Nadhira Napoleon') ?>
                </span>
                <span class="article-date-badge">
                    <i class="far fa-calendar-alt"></i>
                    <?= $pubDate ?>
                </span>
                <span class="article-read-badge">
                    <i class="far fa-clock"></i>
                    <?= $readTime ?>
                </span>
            </div>

            <h1 class="article-title"><?= htmlspecialchars($article['title']) ?></h1>

            <p class="article-excerpt"><?= htmlspecialchars($article['excerpt']) ?></p>
        </header>

        <!-- Featured Image -->
        <?php if ($article['image']): ?>
        <div class="article-image-wrapper" data-aos="fade-up">
            <img src="<?= htmlspecialchars($article['image']) ?>" 
                 alt="<?= htmlspecialchars($article['title']) ?>" 
                 class="article-featured-image" 
                 loading="lazy">
        </div>
        <?php endif; ?>

        <!-- Article Content -->
        <div class="article-content" data-aos="fade-up">
            <?= $article['content'] ?>

            <!-- Author Box -->
            <div class="article-author-box">
                <div class="article-author-avatar">
                    <i class="fas fa-user-circle"></i>
                </div>
                <div class="article-author-info">
                    <div class="article-author-name"><?= htmlspecialchars($article['author'] ?: 'Nadhira Napoleon') ?></div>
                    <div class="article-author-role">Penulis</div>
                    <p class="article-author-bio">Nadhira Napoleon adalah pusat oleh-oleh premium khas Riau yang menghadirkan berbagai produk berkualitas dengan cita rasa autentik Melayu Riau.</p>
                </div>
            </div>

            <!-- Share Buttons -->
            <div class="article-share">
                <span class="article-share-label">Bagikan artikel ini:</span>
                <div class="article-share-buttons">
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode(SITE_URL . '/pages/artikel.php?slug=' . $article['slug']) ?>" 
                       class="share-btn share-facebook" target="_blank" rel="noopener" 
                       aria-label="Bagikan ke Facebook">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <a href="https://twitter.com/intent/tweet?text=<?= urlencode($article['title']) ?>&url=<?= urlencode(SITE_URL . '/pages/artikel.php?slug=' . $article['slug']) ?>" 
                       class="share-btn share-twitter" target="_blank" rel="noopener" 
                       aria-label="Bagikan ke Twitter">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <a href="https://wa.me/?text=<?= urlencode($article['title'] . ' - ' . SITE_URL . '/pages/artikel.php?slug=' . $article['slug']) ?>" 
                       class="share-btn share-whatsapp" target="_blank" rel="noopener" 
                       aria-label="Bagikan ke WhatsApp">
                        <i class="fab fa-whatsapp"></i>
                    </a>
                    <button class="share-btn share-copy" onclick="copyArticleLink(event)" aria-label="Salin link">
                        <i class="fas fa-link"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <div class="article-navigation" data-aos="fade-up">
            <a href="<?= SITE_URL ?>#articles" class="btn btn-outline btn-lg">
                <i class="fas fa-arrow-left"></i>
                Semua Artikel
            </a>
        </div>
    </div>
</section>

<!-- Related Articles -->
<?php if (!empty($relatedArticles)): ?>
<section class="related-articles-section">
    <div class="container">
        <div class="section-tag" data-aos="fade-up">Artikel Lainnya</div>
        <h2 class="section-title" data-aos="fade-up" data-aos-delay="100">Artikel <span class="gold-text">Terbaru</span></h2>
        <p class="section-subtitle" data-aos="fade-up" data-aos-delay="150">Jangan lewatkan artikel menarik lainnya</p>

        <div class="grid grid-3">
            <?php $artDelay = 0; foreach ($relatedArticles as $rel): ?>
            <div class="card" data-aos="fade-up" data-aos-delay="<?= $artDelay ?>">
                <img src="<?= htmlspecialchars($rel['image'] ?: 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=600&q=80') ?>" 
                     alt="<?= htmlspecialchars($rel['title']) ?>" 
                     class="card-image" style="height: 200px;" loading="lazy">
                <div class="card-body">
                    <small style="color: var(--soft-gold); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 1px;">
                        <?= date('d M Y', strtotime($rel['published_at'])) ?>
                    </small>
                    <h3 class="card-title" style="font-size: var(--text-lg); margin-top: var(--space-sm);">
                        <?= htmlspecialchars($rel['title']) ?>
                    </h3>
                    <p class="card-text"><?= htmlspecialchars(mb_substr($rel['excerpt'] ?: strip_tags($rel['content'] ?? ''), 0, 100)) ?>...</p>
                    <a href="?slug=<?= urlencode($rel['slug']) ?>" class="btn btn-outline btn-sm">
                        Baca Selengkapnya
                    </a>
                </div>
            </div>
            <?php $artDelay += 100; endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Copy Link Script -->
<script>
function copyArticleLink(e) {
    const btn = e.currentTarget;
    const url = window.location.href;
    navigator.clipboard.writeText(url).then(() => {
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i>';
        setTimeout(() => { btn.innerHTML = original; }, 2000);
    });
}
</script>

<style>
/* Article Detail Styles */
.article-detail-section {
    padding: calc(var(--navbar-total-height, 96px) + 8px) 0 var(--space-xl);
}

.container-narrow {
    max-width: 800px;
    margin: 0 auto;
}

.article-header {
    margin-bottom: var(--space-2xl);
}

.article-meta-top {
    display: flex;
    gap: var(--space-md);
    flex-wrap: wrap;
    margin-bottom: var(--space-lg);
}

.article-category-badge,
.article-date-badge,
.article-read-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    background: var(--bg-cream);
    border-radius: var(--radius-full);
    font-size: var(--text-sm);
    color: var(--text-muted);
}

.article-category-badge {
    background: var(--soft-gold-gradient);
    color: var(--text-white);
    font-weight: 500;
}

.article-title {
    font-family: var(--font-display);
    font-size: var(--text-4xl);
    font-weight: 700;
    line-height: 1.2;
    margin-bottom: var(--space-lg);
    color: var(--text-dark);
}

.article-excerpt {
    font-size: var(--text-lg);
    color: var(--text-muted);
    line-height: 1.7;
}

.article-image-wrapper {
    border-radius: var(--radius-xl);
    overflow: hidden;
    margin-bottom: var(--space-2xl);
    box-shadow: var(--shadow-lg);
}

.article-featured-image {
    width: 100%;
    height: auto;
    max-height: 500px;
    object-fit: cover;
    display: block;
}

.article-content {
    font-size: var(--text-base);
    line-height: 1.9;
    color: var(--text-secondary);
}

.article-content p {
    margin-bottom: var(--space-lg);
}

.article-content strong {
    color: var(--text-dark);
    font-weight: 600;
}

.article-content ul,
.article-content ol {
    margin-bottom: var(--space-lg);
    padding-left: var(--space-xl);
}

.article-content li {
    margin-bottom: var(--space-sm);
}

/* Author Box */
.article-author-box {
    display: flex;
    gap: var(--space-lg);
    padding: var(--space-xl);
    background: var(--warm-white);
    border-radius: var(--radius-xl);
    margin: var(--space-3xl) 0;
    box-shadow: var(--shadow-sm);
}

.article-author-avatar i {
    font-size: 4rem;
    color: var(--soft-gold);
}

.article-author-name {
    font-family: var(--font-display);
    font-size: var(--text-lg);
    font-weight: 600;
    color: var(--text-dark);
}

.article-author-role {
    font-size: var(--text-sm);
    color: var(--soft-gold);
    font-weight: 500;
    margin-bottom: var(--space-sm);
}

.article-author-bio {
    font-size: var(--text-sm);
    color: var(--text-muted);
    line-height: 1.6;
}

/* Share Buttons */
.article-share {
    padding: var(--space-xl) 0;
    border-top: 1px solid var(--soft-grey);
    border-bottom: 1px solid var(--soft-grey);
    margin-bottom: var(--space-xl);
}

.article-share-label {
    display: block;
    font-weight: 600;
    margin-bottom: var(--space-md);
    color: var(--text-dark);
}

.article-share-buttons {
    display: flex;
    gap: var(--space-sm);
}

.share-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: var(--radius-full);
    border: none;
    cursor: pointer;
    transition: var(--transition-base);
    color: var(--text-white);
    font-size: var(--text-lg);
    text-decoration: none;
}

.share-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.share-facebook { background: #1877F2; }
.share-twitter { background: #1DA1F2; }
.share-whatsapp { background: #25D366; }
.share-copy { background: var(--text-muted); }

/* Article Navigation */
.article-navigation {
    text-align: center;
    margin: var(--space-2xl) 0;
}

/* Related Articles */
.related-articles-section {
    background: var(--bg-cream);
    padding: var(--space-4xl) 0;
}

/* Responsive */
@media (max-width: 768px) {
    .article-title {
        font-size: var(--text-2xl);
    }
    .article-meta-top {
        gap: var(--space-sm);
    }
    .article-author-box {
        flex-direction: column;
        text-align: center;
    }
}
</style>

<?php include '../includes/footer.php'; ?>
