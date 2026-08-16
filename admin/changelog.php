<?php
$currentPage = 'changelog';
$pageTitle = 'Changelog';
require_once __DIR__ . '/layout.php';

// Read CHANGELOG file
$changelogPath = __DIR__ . '/../CHANGELOG.md';
$rawContent = '';
$mtime = '';
if (file_exists($changelogPath)) {
    $rawContent = file_get_contents($changelogPath);
    $mtime = date('d M Y H:i', filemtime($changelogPath));
}

// Simple markdown parser for changelog display
function parseChangelog($text) {
    $lines = explode("\n", $text);
    $html = '';
    $inList = false;
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // Skip empty lines
        if (empty($trimmed)) {
            if ($inList) {
                $html .= "</ul>\n";
                $inList = false;
            }
            continue;
        }
        
        // Headings
        if (preg_match('/^## (.+)/', $trimmed, $m)) {
            if ($inList) { $html .= "</ul>\n"; $inList = false; }
            // Version heading with date
            if (preg_match('/^\[(.+?)\]\s*[—–-]\s*(.+)/', $m[1], $vm)) {
                $html .= '<div class="cl-version">';
                $html .= '<span class="cl-version-tag">' . htmlspecialchars($vm[1]) . '</span>';
                $html .= '<span class="cl-version-date">' . htmlspecialchars($vm[2]) . '</span>';
                $html .= '</div>';
            } else {
                $html .= '<h2 class="cl-heading">' . htmlspecialchars($m[1]) . '</h2>';
            }
            continue;
        }
        if (preg_match('/^### (.+)/', $trimmed, $m)) {
            if ($inList) { $html .= "</ul>\n"; $inList = false; }
            $html .= '<h3 class="cl-subheading">' . htmlspecialchars($m[1]) . '</h3>';
            continue;
        }
        
        // List items
        if (preg_match('/^[-*]\s+(.+)/', $trimmed, $m)) {
            if (!$inList) {
                $html .= '<ul class="cl-list">';
                $inList = true;
            }
            $item = $m[1];
            // Bold
            $item = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $item);
            // Inline code
            $item = preg_replace('/`(.+?)`/', '<code>$1</code>', $item);
            // Emoji extraction for icon styling
            $emoji = '';
            if (preg_match('/^([\x{1F000}-\x{1FFFF}]|[\x{2600}-\x{27BF}]|[\x{2700}-\x{27BF}]|[\x{1F300}-\x{1F5FF}]|[\x{2190}-\x{21FF}])\s*/u', $item, $em)) {
                $emoji = $em[1];
                $item = substr($item, strlen($emoji));
            }
            $html .= '<li>';
            if ($emoji) {
                $html .= '<span class="cl-emoji">' . $emoji . '</span> ';
            }
            $html .= $item . '</li>';
            continue;
        }
        
        // Regular paragraph
        if ($inList) { $html .= "</ul>\n"; $inList = false; }
        $html .= '<p class="cl-text">' . htmlspecialchars($trimmed) . '</p>';
    }
    
    if ($inList) {
        $html .= "</ul>\n";
    }
    
    return $html;
}
?>

<style>
/* Changelog Page Styles */
.cl-container {
    max-width: 900px;
}

.cl-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 24px;
    padding: 20px 24px;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    border: 1px solid rgba(0,0,0,0.04);
}

.cl-header-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.cl-header-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, rgba(212,168,83,0.12), rgba(184,134,11,0.08));
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #D4A853;
}

.cl-header-text h2 {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 2px;
}

.cl-header-text p {
    font-size: 12px;
    color: var(--text-muted);
    margin: 0;
}

.cl-header-actions {
    display: flex;
    gap: 8px;
}

.cl-card {
    background: #fff;
    border-radius: 16px;
    padding: 32px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    border: 1px solid rgba(0,0,0,0.04);
    line-height: 1.8;
}

.cl-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-bottom: 20px;
    margin-bottom: 24px;
    border-bottom: 2px solid #f0ece6;
}

.cl-card-header i {
    font-size: 24px;
    color: #D4A853;
}

.cl-card-header h3 {
    font-family: 'Playfair Display', serif;
    font-size: 20px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0;
}

.cl-card-header small {
    margin-left: auto;
    font-size: 12px;
    color: var(--text-muted);
}

/* Version tags */
.cl-version {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px dashed #e8e3dd;
}

.cl-version:first-of-type {
    margin-top: 0;
}

.cl-version-tag {
    display: inline-block;
    padding: 4px 14px;
    background: linear-gradient(135deg, #D4A853, #B8860B);
    color: #fff;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 700;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
}

.cl-version-date {
    font-size: 13px;
    color: var(--text-muted);
    font-weight: 500;
}

/* Section headings */
.cl-heading {
    font-family: 'Playfair Display', serif;
    font-size: 22px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 32px 0 20px;
    padding-bottom: 8px;
    border-bottom: 2px solid #f0ece6;
}

.cl-subheading {
    font-family: 'Inter', sans-serif;
    font-size: 15px;
    font-weight: 600;
    color: #D4A853;
    margin: 24px 0 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.cl-subheading::before {
    content: '';
    display: inline-block;
    width: 4px;
    height: 18px;
    background: linear-gradient(135deg, #D4A853, #B8860B);
    border-radius: 2px;
}

/* Lists */
.cl-list {
    list-style: none;
    padding: 0;
    margin: 0 0 16px;
}

.cl-list li {
    position: relative;
    padding: 6px 0 6px 28px;
    font-size: 14px;
    color: #444;
    line-height: 1.7;
}

.cl-list li::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 14px;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #D4A853;
    opacity: 0.5;
}

.cl-list li strong {
    color: var(--text-dark);
}

.cl-list li code {
    background: #f5f2ed;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 12px;
    color: #B8860B;
    font-family: 'Courier New', monospace;
}

.cl-emoji {
    font-size: 16px;
    margin-right: 4px;
}

.cl-text {
    font-size: 14px;
    color: var(--text-muted);
    margin: 8px 0;
}

/* Empty state */
.cl-empty {
    text-align: center;
    padding: 60px 20px;
}

.cl-empty i {
    font-size: 48px;
    color: #ddd;
    margin-bottom: 16px;
}

.cl-empty h3 {
    font-family: 'Playfair Display', serif;
    font-size: 20px;
    color: var(--text-dark);
    margin-bottom: 8px;
}

.cl-empty p {
    font-size: 14px;
    color: var(--text-muted);
    margin: 0;
}

/* View raw button */
.cl-raw-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #f5f2ed;
    border: 1px solid #e5e0db;
    border-radius: 10px;
    font-size: 13px;
    color: #666;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}

.cl-raw-btn:hover {
    background: #ede8e2;
    border-color: #D4A853;
    color: #D4A853;
}
</style>

<div class="cl-container">
    <!-- Header -->
    <div class="cl-header">
        <div class="cl-header-info">
            <div class="cl-header-icon">
                <i class="fas fa-history"></i>
            </div>
            <div class="cl-header-text">
                <h2>Catatan Perubahan</h2>
                <p>Semua perubahan dan pengembangan pada website tercatat di sini</p>
            </div>
        </div>
        <div class="cl-header-actions">
            <a href="../CHANGELOG.md" target="_blank" class="cl-raw-btn">
                <i class="fas fa-file-alt"></i> Lihat File Asli
            </a>
        </div>
    </div>

    <!-- Changelog Content -->
    <div class="cl-card">
        <div class="cl-card-header">
            <i class="fas fa-clipboard-list"></i>
            <h3>Changelog</h3>
            <?php if ($mtime): ?>
            <small>Terakhir diperbarui: <?= $mtime ?></small>
            <?php endif; ?>
        </div>

        <?php if ($rawContent): ?>
            <?= parseChangelog($rawContent) ?>
        <?php else: ?>
            <div class="cl-empty">
                <i class="fas fa-file-alt"></i>
                <h3>Belum Ada Catatan</h3>
                <p>File CHANGELOG.md belum tersedia atau masih kosong.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

</main></div></body></html>
<?php exit; ?>
