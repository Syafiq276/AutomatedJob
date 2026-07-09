<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║             JOBTRACKER WEB DASHBOARD — v1.0                  ║
 * ║  Single-file PHP+SQLite API & UI Dashboard                   ║
 * ║  Built with Bootstrap 5 (Custom Dark Glassmorphic Theme)      ║
 * ║  Includes PDF Export (via html2pdf.js) & Status Tracking     ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

// ── Configuration & Secret handshake ──────────────────────────
$API_KEY = "my_secure_handshake_key_12345"; // Change this to your secure token
$DB_FILE = __DIR__ . "/jobs.db";

// ── Database Setup ────────────────────────────────────────────
try {
    $db = new PDO("sqlite:" . $DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create tables if they do not exist
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
        status TEXT DEFAULT 'pending', -- pending, applied, archived
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// ── API Router ────────────────────────────────────────────────
$request_uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

// Parse incoming JSON data
$input = json_decode(file_get_contents('php://input'), true);

if ($method === 'POST') {
    // 1. API: Scraper push match endpoint
    if (isset($input['api_key'])) {
        if ($input['api_key'] !== $API_KEY) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(["error" => "Unauthorized API key"]);
            exit;
        }

        try {
            // Check if job url already exists to avoid duplicate entries
            $check = $db->prepare("SELECT id FROM jobs WHERE url = :url OR (title = :title AND company = :company)");
            $check->execute([
                ':url' => $input['url'] ?? '',
                ':title' => $input['title'],
                ':company' => $input['company']
            ]);
            
            if ($check->fetch()) {
                header('Content-Type: application/json');
                echo json_encode(["status" => "ignored", "message" => "Duplicate entry"]);
                exit;
            }

            // Insert new match
            $stmt = $db->prepare("INSERT INTO jobs (title, company, location, url, source, score, salary, posted_date, description, cover_letter) 
                                  VALUES (:title, :company, :location, :url, :source, :score, :salary, :posted_date, :description, :cover_letter)");
            $stmt->execute([
                ':title' => $input['title'],
                ':company' => $input['company'],
                ':location' => $input['location'] ?? '',
                ':url' => $input['url'] ?? '',
                ':source' => $input['source'] ?? 'Unknown',
                ':score' => floatval($input['score'] ?? 0.0),
                ':salary' => $input['salary'] ?? '',
                ':posted_date' => $input['posted_date'] ?? '',
                ':description' => $input['description'] ?? '',
                ':cover_letter' => $input['cover_letter'] ?? ''
            ]);

            header('Content-Type: application/json');
            echo json_encode(["status" => "success", "id" => $db->lastInsertId()]);
            exit;
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
            exit;
        }
    }
    
    // 2. API: Update status endpoint
    if (isset($input['action']) && $input['action'] === 'update_status') {
        try {
            $stmt = $db->prepare("UPDATE jobs SET status = :status WHERE id = :id");
            $stmt->execute([
                ':status' => $input['status'],
                ':id' => intval($input['id'])
            ]);
            header('Content-Type: application/json');
            echo json_encode(["status" => "success"]);
            exit;
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
            exit;
        }
    }
}

// ── Retrieve Data for Dashboard UI ─────────────────────────────
$status_filter = $_GET['status'] ?? 'pending';
if (!in_array($status_filter, ['pending', 'applied', 'archived'])) {
    $status_filter = 'pending';
}

$stmt = $db->prepare("SELECT * FROM jobs WHERE status = :status ORDER BY score DESC, created_at DESC");
$stmt->execute([':status' => $status_filter]);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate global metrics
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
    <!-- html2pdf.js CDN (for client-side PDF download) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    
    <style>
        :root {
            --bg-color: #0c0f17;
            --card-bg: rgba(22, 28, 45, 0.45);
            --card-border: rgba(255, 255, 255, 0.08);
            --accent-primary: #3b82f6;
            --accent-green: #10b981;
            --text-main: #f3f4f6;
            --text-secondary: #9ca3af;
        }

        body {
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(at 10% 20%, rgba(59, 130, 246, 0.1) 0px, transparent 50%),
                radial-gradient(at 90% 80%, rgba(16, 185, 129, 0.05) 0px, transparent 50%);
            color: var(--text-main);
            font-family: 'Outfit', sans-serif;
            min-height: 100vh;
        }

        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(16px) saturate(120%);
            -webkit-backdrop-filter: blur(16px) saturate(120%);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
            transition: transform 0.2s ease, border-color 0.2s ease;
        }

        .glass-card:hover {
            border-color: rgba(59, 130, 246, 0.3);
        }

        .stat-card {
            padding: 1.5rem;
            text-align: center;
        }

        .circle-progress-container {
            position: relative;
            width: 80px;
            height: 80px;
            margin: 0 auto;
        }

        .circle-progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 1.1rem;
            font-weight: 700;
        }

        svg.progress-ring {
            width: 80px;
            height: 80px;
            transform: rotate(-90deg);
        }

        .progress-ring__circle {
            transition: stroke-dashoffset 0.35s;
            transform-origin: 50% 50%;
        }

        .nav-pills .nav-link {
            color: var(--text-secondary);
            border-radius: 8px;
            padding: 0.5rem 1.25rem;
            border: 1px solid transparent;
            transition: all 0.2s ease;
        }

        .nav-pills .nav-link.active {
            background-color: var(--accent-primary);
            color: #fff;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
        }

        .nav-pills .nav-link:hover:not(.active) {
            border-color: rgba(255, 255, 255, 0.1);
            color: var(--text-main);
        }

        .job-row {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .job-row:last-child {
            border-bottom: none;
        }

        .badge-source {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
            border-radius: 6px;
        }

        .badge-jobstreet { background-color: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-indeed { background-color: rgba(59, 130, 246, 0.2); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3); }
        .badge-linkedin { background-color: rgba(139, 92, 246, 0.2); color: #a78bfa; border: 1px solid rgba(139, 92, 246, 0.3); }

        .btn-custom {
            border-radius: 8px;
            font-weight: 500;
            padding: 0.4rem 0.8rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }

        /* Virtual Cover Letter Sheet styling */
        .cover-letter-paper {
            background-color: white;
            color: #2c3e50;
            font-family: 'Times New Roman', Times, serif;
            font-size: 11pt;
            line-height: 1.5;
            padding: 2.5cm;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            border-radius: 4px;
            max-width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
        }

        .cover-letter-modal-header {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .view-pane {
            display: none;
        }

        .view-pane.active-pane {
            display: block;
        }
    </style>
</head>
<body class="py-4">

<div class="container" id="main-interface">
    <!-- Header -->
    <header class="d-flex justify-content-between align-items-center mb-5 flex-wrap gap-3">
        <div>
            <h1 class="h3 fw-bold mb-0">💼 JobTracker Web</h1>
            <p class="text-secondary small mb-0">Syafiq's Background Job Scraper & Cover Letter Manager</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span class="badge bg-success py-2 px-3 rounded-pill">Status: Monitoring</span>
        </div>
    </header>

    <!-- Metrics row -->
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

    <!-- Controller Tabs -->
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

    <!-- Listings -->
    <section class="glass-card p-2">
        <?php if (empty($jobs)): ?>
            <div class="text-center py-5 text-secondary">
                <p class="mb-0">No job matches found in this category.</p>
                <small class="small text-muted">Scraped listings above the threshold will automatically sync here.</small>
            </div>
        <?php else: ?>
            <?php foreach ($jobs as $job): ?>
                <div class="job-row">
                    <div class="d-flex gap-3 align-items-center flex-wrap">
                        <!-- Match Circular Widget -->
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

                    <!-- Action buttons -->
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

<!-- Dedicated Visualiser Page (renders cover letter on letterhead format) -->
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

    <!-- Job Info Box -->
    <div class="glass-card p-4 mb-4">
        <h4 id="vis-title" class="fw-bold mb-1">Role Title</h4>
        <h5 id="vis-company" class="text-info mb-2">Company Name</h5>
        <div id="vis-meta" class="text-secondary small">Meta details...</div>
    </div>

    <!-- Letter Visual Render -->
    <div class="cover-letter-paper-wrapper py-3">
        <div id="letter-content" class="cover-letter-paper">
            <!-- Dynamic Letter Body Inject -->
        </div>
    </div>
</div>

<!-- JS Controller Logic -->
<script>
    let activeJob = null;

    // Changes job status via API request
    function changeStatus(id, newStatus) {
        fetch('index.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'update_status',
                id: id,
                status: newStatus
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                window.location.reload();
            }
        })
        .catch(err => console.error("Error updating status:", err));
    }

    // Displays the Visualiser pane and injects job letter details
    function viewCoverLetter(job) {
        activeJob = job;
        
        document.getElementById('vis-title').innerText = job.title;
        document.getElementById('vis-company').innerText = job.company;
        document.getElementById('vis-meta').innerHTML = `📍 Location: ${job.location} | Source: ${job.source} | Match Score: ${job.score}%`;
        
        // Convert letter newlines into HTML line breaks
        const formattedBody = job.cover_letter.replace(/\n/g, '<br>');
        document.getElementById('letter-content').innerHTML = formattedBody;

        // Pane Switch
        document.getElementById('main-interface').classList.add('d-none');
        document.getElementById('cover-letter-visualiser').classList.add('active-pane');
        window.scrollTo(0, 0);
    }

    // Restores Main Dashboard View
    function showDashboard() {
        document.getElementById('main-interface').classList.remove('d-none');
        document.getElementById('cover-letter-visualiser').classList.remove('active-pane');
        activeJob = null;
    }

    // Copies cover letter to user clipboard
    function copyLetterText() {
        if (!activeJob) return;
        navigator.clipboard.writeText(activeJob.cover_letter)
            .then(() => alert("📋 Cover letter copied to clipboard!"))
            .catch(err => alert("Could not copy text: " + err));
    }

    // Generates a high-quality PDF using client-side html2pdf.js CDN
    function downloadPDF() {
        if (!activeJob) return;
        
        const element = document.getElementById('letter-content');
        
        // Generate formatted file name
        const cleanCompany = activeJob.company.replace(/[^a-z0-9]/gi, '_').toLowerCase();
        const cleanTitle = activeJob.title.replace(/[^a-z0-9]/gi, '_').toLowerCase();
        const filename = `cover_letter_${cleanCompany}_${cleanTitle}.pdf`;

        // html2pdf configs for standard A4 printing letterheads
        const opt = {
            margin:       0.5,
            filename:     filename,
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2 },
            jsPDF:        { unit: 'in', format: 'letter', orientation: 'portrait' }
        };

        html2pdf().set(opt).from(element).save();
    }
</script>
</body>
</html>
