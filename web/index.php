<?php
/**
 * JobTracker Web Dashboard UI Controller
 * Serves the HTML listings interface, statistics dashboard, and cover letter visualizer.
 * Styled with Bootstrap 5 and custom dark glassmorphic styling sheets.
 */

$DB_FILE = __DIR__ . "/jobs.db";

try {
    $db = new PDO("sqlite:" . $DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Auto-create database schema on first dashboard load
    $db->exec("CREATE TABLE IF NOT EXISTS jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        company TEXT NOT NULL,
        location TEXT NOT NULL,
        url TEXT,
        source TEXT,
        score REAL,
        salary TEXT,
        posted_date TEXT,
        description TEXT,
        cover_letter TEXT,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// Get active navigation category status
$status_filter = $_GET['status'] ?? 'pending';
if (!in_array($status_filter, ['pending', 'applied', 'archived'])) {
    $status_filter = 'pending';
}

// Fetch matches matching active filter status
$stmt = $db->prepare("SELECT * FROM jobs WHERE status = :status ORDER BY score DESC, created_at DESC");
$stmt->execute([':status' => $status_filter]);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate metrics across categories
$total_pending  = $db->query("SELECT COUNT(*) FROM jobs WHERE status = 'pending'")->fetchColumn();
$total_applied  = $db->query("SELECT COUNT(*) FROM jobs WHERE status = 'applied'")->fetchColumn();
$total_archived = $db->query("SELECT COUNT(*) FROM jobs WHERE status = 'archived'")->fetchColumn();
$avg_score      = $db->query("SELECT AVG(score) FROM jobs")->fetchColumn() ?: 0.0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JobTracker Web Dashboard</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts: Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <!-- Custom Theme Overrides -->
    <link href="css/style.css" rel="stylesheet">
    <!-- html2pdf.js (A4 PDF download helper) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body class="py-4">

<div class="container" id="main-interface">
    <!-- Header -->
    <header class="d-flex justify-content-between align-items-center mb-5 flex-wrap gap-3">
        <div>
            <h1 class="h3 fw-bold mb-0">💼 JobTracker Web</h1>
            <p class="text-secondary small mb-0">Syafiq's Background Job Scraper &amp; Cover Letter Manager</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span id="scraper-status-badge" class="badge bg-success py-2 px-3 rounded-pill">Status: Monitoring</span>
            <button id="btn-trigger-scraper" class="btn btn-primary rounded-pill px-3 py-2 btn-custom d-flex align-items-center gap-2" onclick="triggerScraper()">
                🔄 Run Scraper Now
            </button>
        </div>
    </header>

    <!-- Scraper Live Progress Bar -->
    <div id="scraper-progress-container" class="p-3 glass-card d-none mb-5">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="small fw-bold text-light" id="progress-status">Initializing scraper...</span>
            <span class="small text-info fw-bold" id="progress-percent">0%</span>
        </div>
        <div class="progress" style="height: 8px; background-color: rgba(255,255,255,0.1); border-radius: 4px; overflow: hidden;">
            <div id="scraper-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-info" role="progressbar" style="width: 0%"></div>
        </div>
    </div>

    <!-- Metrics Row -->
    <section class="row g-4 mb-5">
        <div class="col-md-3">
            <div class="glass-card stat-card">
                <div class="text-secondary small mb-1">Total Scraped</div>
                <div class="display-6 fw-bold"><?php echo ($total_pending + $total_applied + $total_archived); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="glass-card stat-card">
                <div class="text-secondary small mb-1">Pending Review</div>
                <div class="display-6 fw-bold text-warning"><?php echo $total_pending; ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="glass-card stat-card">
                <div class="text-secondary small mb-1">Applied</div>
                <div class="display-6 fw-bold text-success"><?php echo $total_applied; ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="glass-card stat-card">
                <div class="text-secondary small mb-1">Avg Match Score</div>
                <div class="display-6 fw-bold text-info"><?php echo round($avg_score, 1); ?>%</div>
            </div>
        </div>
    </section>

    <!-- Navigation Pills -->
    <section class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <ul class="nav nav-pills gap-2">
            <li class="nav-item">
                <a class="nav-link <?php echo $status_filter === 'pending' ? 'active' : ''; ?>" href="?status=pending">
                    📥 Pending Action (<?php echo $total_pending; ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $status_filter === 'applied' ? 'active' : ''; ?>" href="?status=applied">
                    ✅ Applied (<?php echo $total_applied; ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $status_filter === 'archived' ? 'active' : ''; ?>" href="?status=archived">
                    📁 Archive (<?php echo $total_archived; ?>)
                </a>
            </li>
        </ul>
    </section>

    <!-- Job Listings Panel -->
    <section class="glass-card p-2">
        <?php if (empty($jobs)): ?>
            <div class="text-center py-5 text-secondary">
                <p class="mb-0">No job matches found in this category.</p>
                <small class="small text-muted font-monospace">API endpoint: web/api.php</small>
            </div>
        <?php else: ?>
            <?php foreach ($jobs as $job): ?>
                <div class="job-row">
                    <div class="d-flex gap-3 align-items-center flex-wrap">
                        <!-- Match Score Progress SVG -->
                        <div class="circle-progress-container">
                            <svg class="progress-ring">
                                <circle class="progress-ring__circle" stroke="#222f3e" stroke-width="6" fill="transparent" r="34" cx="40" cy="40"/>
                                <circle class="progress-ring__circle" stroke="<?php echo $job['score'] >= 85 ? '#10b981' : '#3b82f6'; ?>" stroke-width="6" stroke-dasharray="213.6" stroke-dashoffset="<?php echo 213.6 - (213.6 * ($job['score'] / 100)); ?>" fill="transparent" r="34" cx="40" cy="40"/>
                            </svg>
                            <span class="circle-progress-text"><?php echo round($job['score']); ?>%</span>
                        </div>

                        <div>
                            <h2 class="h5 fw-bold mb-1 d-flex align-items-center gap-2 flex-wrap">
                                <?php echo htmlspecialchars($job['title']); ?>
                                <span class="badge badge-source badge-<?php echo strtolower($job['source']); ?>">
                                    <?php echo htmlspecialchars($job['source']); ?>
                                </span>
                            </h2>
                            <p class="mb-1 text-light-50">
                                🏢 <b><?php echo htmlspecialchars($job['company']); ?></b> · 📍 <?php echo htmlspecialchars($job['location']); ?>
                            </p>
                            <div class="small text-secondary">
                                <?php if ($job['salary']): ?>💰 <?php echo htmlspecialchars($job['salary']); ?> · <?php endif; ?>
                                🕘 Sync time: <?php echo date('d M Y, h:i A', strtotime($job['created_at'])); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Row Action triggers -->
                    <div class="d-flex align-items-center gap-2">
                        <button class="btn btn-outline-info btn-custom" onclick="viewCoverLetter(<?php echo htmlspecialchars(json_encode($job)); ?>)">
                            📝 Cover Letter
                        </button>
                        
                        <?php if ($job['url']): ?>
                            <a href="<?php echo htmlspecialchars($job['url']); ?>" target="_blank" class="btn btn-outline-light btn-custom">
                                🔗 Job Link
                            </a>
                        <?php endif; ?>

                        <?php if ($job['status'] === 'pending'): ?>
                            <button class="btn btn-success btn-custom" onclick="changeStatus(<?php echo $job['id']; ?>, 'applied')">
                                Mark Applied
                            </button>
                            <button class="btn btn-outline-danger btn-custom" onclick="changeStatus(<?php echo $job['id']; ?>, 'archived')">
                                Archive
                            </button>
                        <?php elseif ($job['status'] === 'applied'): ?>
                            <button class="btn btn-outline-warning btn-custom" onclick="changeStatus(<?php echo $job['id']; ?>, 'pending')">
                                Revert Pending
                            </button>
                        <?php elseif ($job['status'] === 'archived'): ?>
                            <button class="btn btn-outline-success btn-custom" onclick="changeStatus(<?php echo $job['id']; ?>, 'pending')">
                                Restore
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>

<!-- Visualiser pane (Letterhead representation) -->
<div class="container py-4 view-pane" id="cover-letter-visualiser">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <button class="btn btn-outline-light" onclick="showDashboard()">
            ← Back to Dashboard
        </button>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" onclick="downloadPDF()">
                📥 Export PDF
            </button>
            <button class="btn btn-success" onclick="copyLetterText()">
                📋 Copy Text
            </button>
        </div>
    </div>

    <!-- Job Header Block -->
    <div class="glass-card p-4 mb-4">
        <h4 id="vis-title" class="fw-bold mb-1">Role Title</h4>
        <h5 id="vis-company" class="text-info mb-2">Company Name</h5>
        <div id="vis-meta" class="text-secondary small">Metadata details...</div>
    </div>

    <!-- Letter Visual Sheet rendering -->
    <div class="cover-letter-paper-wrapper py-3">
        <div id="letter-content" class="cover-letter-paper">
            <!-- Dynamic Letter Body Inject -->
        </div>
    </div>
</div>

<!-- App Controller Script -->
<script src="js/app.js"></script>
</body>
</html>
