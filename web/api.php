<?php
/**
 * JobTracker Backend API Router
 * Handles matched job ingestion from python scraper
 * and application status changes from the dashboard UI.
 */

// ── Configuration & Security handshake ────────────────────────
$API_KEY = "my_secure_handshake_key_12345"; // Match this with your .env
$DB_FILE = __DIR__ . "/jobs.db";

// GitHub actions workflow trigger configurations
$GITHUB_PAT      = "YOUR_GITHUB_PAT_HERE"; // Paste your GitHub Personal Access Token here
$GITHUB_REPO     = "Syafiq276/AutomatedJob"; // Your GitHub username/repo
$GITHUB_WORKFLOW = "scrape_jobs.yml";       // Scraper workflow filename

/**
 * Generic helper to query the GitHub REST API using curl
 */
function make_github_request($url, $pat, $method = 'GET', $post_fields = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $pat,
        "Accept: application/vnd.github+json",
        "User-Agent: JobAgent-Dashboard",
        "X-GitHub-Api-Version: 2022-11-28",
        "Content-Type: application/json"
    ]);
    if ($post_fields !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_fields));
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        "code" => $http_code,
        "body" => json_decode($response, true) ?: $response
    ];
}

header('Content-Type: application/json');

// Parse incoming request payload
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true);

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method Not Allowed"]);
    exit;
}

// Establish SQLite database connection
try {
    $db = new PDO("sqlite:" . $DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Auto-initialize DB schema if someone hits the API first
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
    http_response_code(500);
    echo json_encode(["error" => "Database Connection Error: " . $e->getMessage()]);
    exit;
}

// ── ENDPOINT 1: Sync new matched jobs from Scraper ─────────────
if (isset($input['api_key'])) {
    if ($input['api_key'] !== $API_KEY) {
        http_response_code(401);
        echo json_encode(["error" => "Unauthorized API key handshake"]);
        exit;
    }

    // Required fields check
    if (empty($input['title']) || empty($input['company'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing required job parameters (title/company)"]);
        exit;
    }

    try {
        // Prevent duplicate entries using url or identical title + company
        $check = $db->prepare("SELECT id FROM jobs WHERE (url = :url AND url != '') OR (title = :title AND company = :company)");
        $check->execute([
            ':url' => $input['url'] ?? '',
            ':title' => $input['title'],
            ':company' => $input['company']
        ]);
        
        if ($check->fetch()) {
            echo json_encode(["status" => "ignored", "message" => "Duplicate entry detected"]);
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

        echo json_encode(["status" => "success", "id" => $db->lastInsertId()]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database Save Error: " . $e->getMessage()]);
        exit;
    }
}

// ── ENDPOINT 2: Update application status ───────────────────────
if (isset($input['action']) && $input['action'] === 'update_status') {
    if (empty($input['id']) || empty($input['status'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing parameters for update_status action"]);
        exit;
    }

    try {
        $stmt = $db->prepare("UPDATE jobs SET status = :status WHERE id = :id");
        $stmt->execute([
            ':status' => $input['status'],
            ':id' => intval($input['id'])
        ]);
        echo json_encode(["status" => "success"]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database Status Update Error: " . $e->getMessage()]);
        exit;
    }
}

// ── ACTION 1: Trigger Scraper (Workflow Dispatch) ────────────────
if (isset($input['action']) && $input['action'] === 'trigger_scraper') {
    if (empty($GITHUB_PAT) || $GITHUB_PAT === "YOUR_GITHUB_PAT_HERE") {
        http_response_code(400);
        echo json_encode(["error" => "GitHub PAT token is not configured. Please paste your GitHub PAT in web/api.php."]);
        exit;
    }

    $url = "https://api.github.com/repos/{$GITHUB_REPO}/actions/workflows/{$GITHUB_WORKFLOW}/dispatches";
    $res = make_github_request($url, $GITHUB_PAT, 'POST', ["ref" => "main"]);

    if ($res['code'] === 204) {
        echo json_encode(["status" => "success", "message" => "Scraper run requested successfully!"]);
    } else {
        http_response_code(500);
        $err = is_array($res['body']) ? ($res['body']['message'] ?? json_encode($res['body'])) : $res['body'];
        echo json_encode(["error" => "GitHub API Error ({$res['code']}): " . $err]);
    }
    exit;
}

// ── ACTION 2: Get Scraper Status (Workflow Runs) ─────────────────
if (isset($input['action']) && $input['action'] === 'get_scraper_status') {
    if (empty($GITHUB_PAT) || $GITHUB_PAT === "YOUR_GITHUB_PAT_HERE") {
        http_response_code(400);
        echo json_encode(["error" => "GitHub PAT token is not configured. Please paste your GitHub PAT in web/api.php."]);
        exit;
    }

    $url = "https://api.github.com/repos/{$GITHUB_REPO}/actions/runs?per_page=1";
    $res = make_github_request($url, $GITHUB_PAT, 'GET');

    if ($res['code'] === 200 && isset($res['body']['workflow_runs'][0])) {
        $run = $res['body']['workflow_runs'][0];
        echo json_encode([
            "status" => "success",
            "run_status" => $run['status'], // e.g. queued, in_progress, completed
            "conclusion" => $run['conclusion'], // e.g. success, failure, cancelled
            "updated_at" => $run['updated_at']
        ]);
    } else {
        http_response_code(500);
        $err = is_array($res['body']) ? ($res['body']['message'] ?? json_encode($res['body'])) : $res['body'];
        echo json_encode(["error" => "Failed to fetch status from GitHub: " . $err]);
    }
    exit;
}

// In case payload did not match routers
http_response_code(400);
echo json_encode(["error" => "Invalid Request Format"]);
exit;
