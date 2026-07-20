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
$GITHUB_REPO = "Syafiq276/AutomatedJob"; // Your GitHub username/repo
$GITHUB_WORKFLOW = "scrape_jobs.yml";       // Scraper workflow filename
$GEMINI_API_KEY = "YOUR_GEMINI_API_KEY_HERE"; // Paste your Gemini API key here


/**
 * Generic helper to query the GitHub REST API using curl
 */
function make_github_request($url, $pat, $method = 'GET', $post_fields = null)
{
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

/**
 * Calls Gemini API to write a tailored cover letter based on the candidate profile and JD
 */
function generate_cover_letter_via_gemini($title, $company, $location, $description, $api_key)
{
    $prompt = "You are a professional career consultant helping a fresh graduate write a concise, compelling cover letter.

CANDIDATE PROFILE:
- Name: Muhammad Syafiq Norhazwan Bin Nor Ramzi
- Email: fathazwan14@gmail.com
- Phone: +60-1157217903
- Degree: Bachelor of IT (Hons.), Big Data, UiTM Arau — CGPA 3.51
- Target Roles: Junior Web Developer, Junior PHP Developer, Junior Laravel Developer, Junior Software Developer, Junior Data Analyst, IT Executive
- Primary Skills: Laravel, PHP, JavaScript, MySQL, RESTful APIs, Git, Docker, CodeIgniter, React
- Key Projects:
  * ClockWise (HRMS): Solo full-stack Laravel app deployed on Render — automated payroll & attendance
  * JomOrder (POS): Laravel F&B ordering system with AI-augmented development
  * Internship at Goolee Sdn Bhd: Built Trainer Development Management System (WordPress + PHP)
- Notice Period: Immediate

JOB DETAILS:
- Role: " . $title . "
- Company: " . $company . "
- Location: " . $location . "
- Job Description:
" . $description . "

INSTRUCTIONS:
1. Write a formal, tailored cover letter (maximum 250-300 words).
2. Highlight relevant skills (Laravel, PHP, SQL, Javascript, POS, HRMS) that match the job description.
3. Keep the tone professional, enthusiastic, and confident.
4. Output ONLY the cover letter in clean markdown format (do not include any conversational preamble or markdown code blocks like ```markdown).";

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $api_key;
    $post_data = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt]
                ]
            ]
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);

    $response = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $res_json = json_decode($response, true);
        if (isset($res_json['candidates'][0]['content']['parts'][0]['text'])) {
            return [
                "success" => true,
                "text" => trim($res_json['candidates'][0]['content']['parts'][0]['text'])
            ];
        }
    }

    $error_msg = "HTTP Status " . $http_code;
    if ($curl_err) {
        $error_msg .= " (cURL Error: " . $curl_err . ")";
    } else {
        $res_json = json_decode($response, true);
        if (isset($res_json['error']['message'])) {
            $error_msg .= ": " . $res_json['error']['message'];
        } else {
            $error_msg .= ": " . substr(strip_tags($response), 0, 150);
        }
    }

    return [
        "success" => false,
        "error" => $error_msg
    ];
}

header('Content-Type: application/json');

// Parse incoming request payload
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

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

// ── ACTION 3: Generate Cover Letter via Gemini ───────────────────
if (isset($input['action']) && $input['action'] === 'generate_cover_letter') {
    if (empty($input['id'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing job ID"]);
        exit;
    }
    if (empty($GEMINI_API_KEY) || $GEMINI_API_KEY === "YOUR_GEMINI_API_KEY_HERE") {
        http_response_code(400);
        echo json_encode(["error" => "Gemini API key is not configured in web/api.php."]);
        exit;
    }

    try {
        // Fetch job details
        $stmt = $db->prepare("SELECT * FROM jobs WHERE id = :id");
        $stmt->execute([':id' => intval($input['id'])]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            http_response_code(404);
            echo json_encode(["error" => "Job not found"]);
            exit;
        }

        // Call Gemini API to write the cover letter
        $res = generate_cover_letter_via_gemini(
            $job['title'],
            $job['company'],
            $job['location'],
            $job['description'],
            $GEMINI_API_KEY
        );

        if (!$res['success']) {
            http_response_code(502);
            echo json_encode(["error" => "Gemini API Error: " . $res['error']]);
            exit;
        }

        $letter = $res['text'];

        // Save cover letter to database
        $update_stmt = $db->prepare("UPDATE jobs SET cover_letter = :cover_letter WHERE id = :id");
        $update_stmt->execute([
            ':cover_letter' => $letter,
            ':id' => $job['id']
        ]);

        // Fetch updated job details to return
        $job['cover_letter'] = $letter;

        echo json_encode([
            "status" => "success",
            "job" => $job
        ]);
        exit;

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database Error: " . $e->getMessage()]);
        exit;
    }
}

// In case payload did not match routers
http_response_code(400);
echo json_encode(["error" => "Invalid Request Format"]);
exit;
