<?php
/**
 * JobTracker Backend API Router
 * Handles matched job ingestion from python scraper
 * and application status changes from the dashboard UI.
 */

// ── Configuration & Security handshake ────────────────────────
$API_KEY = "my_secure_handshake_key_12345"; // Match this with your .env
$DB_FILE = __DIR__ . "/jobs.db";

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

// In case payload did not match routers
http_response_code(400);
echo json_encode(["error" => "Invalid Request Format"]);
exit;
