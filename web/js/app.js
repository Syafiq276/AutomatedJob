/**
 * JobTracker Web Dashboard JavaScript controller
 * Manages view switching, updates statuses via API requests,
 * and handles PDF generation/downloading via html2pdf.js.
 */

let activeJob = null;

/**
 * Changes job application status (applied, archived, pending) by querying the backend API.
 * @param {number} id 
 * @param {string} newStatus 
 */
function changeStatus(id, newStatus) {
    fetch('api.php', {
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
        } else {
            alert("Error: " + (data.error || "Failed to update status"));
        }
    })
    .catch(err => {
        console.error("Error communicating with API:", err);
        alert("Failed to communicate with the server.");
    });
}

/**
 * Displays the cover letter visualizer page and injects letter content
 * @param {Object} job 
 */
function viewCoverLetter(job) {
    activeJob = job;
    
    document.getElementById('vis-title').innerText = job.title;
    document.getElementById('vis-company').innerText = job.company;
    document.getElementById('vis-meta').innerHTML = `📍 Location: ${job.location} | Source: ${job.source} | Match Score: ${job.score}%`;
    
    // Replace text newlines with HTML breaks for visualization
    const formattedBody = job.cover_letter.replace(/\n/g, '<br>');
    document.getElementById('letter-content').innerHTML = formattedBody;

    // Switch active dashboard pane view
    document.getElementById('main-interface').classList.add('d-none');
    document.getElementById('cover-letter-visualiser').classList.add('active-pane');
    window.scrollTo(0, 0);
}

/**
 * Switches view back to the main listings dashboard
 */
function showDashboard() {
    document.getElementById('main-interface').classList.remove('d-none');
    document.getElementById('cover-letter-visualiser').classList.remove('active-pane');
    activeJob = null;
}

/**
 * Copies the text representation of the cover letter to clipboard
 */
function copyLetterText() {
    if (!activeJob) return;
    navigator.clipboard.writeText(activeJob.cover_letter)
        .then(() => alert("📋 Cover letter text copied to clipboard!"))
        .catch(err => alert("Could not copy text: " + err));
}

/**
 * Converts the visual cover letter sheet component directly into an A4 PDF.
 * Downloads the resulting PDF client-side.
 */
function downloadPDF() {
    if (!activeJob) return;
    
    const element = document.getElementById('letter-content');
    
    // Create safe filename strings
    const cleanCompany = activeJob.company.replace(/[^a-z0-9]/gi, '_').toLowerCase();
    const cleanTitle = activeJob.title.replace(/[^a-z0-9]/gi, '_').toLowerCase();
    const filename = `cover_letter_${cleanCompany}_${cleanTitle}.pdf`;

    // html2pdf rendering configurations
    const opt = {
        margin:       0.5,
        filename:     filename,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2 },
        jsPDF:        { unit: 'in', format: 'letter', orientation: 'portrait' }
    };

    html2pdf().set(opt).from(element).save();
}

let progressInterval = null;
let pollInterval = null;
let currentProgress = 0;

/**
 * Triggers the GitHub Actions scraper workflow.
 */
function triggerScraper() {
    const btn = document.getElementById('btn-trigger-scraper');
    btn.disabled = true;
    btn.innerHTML = '🔄 Starting...';

    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'trigger_scraper' })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            startProgressTracking(0);
        } else {
            alert("Error: " + (data.error || "Failed to trigger scraper"));
            btn.disabled = false;
            btn.innerHTML = '🔄 Run Scraper Now';
        }
    })
    .catch(err => {
        console.error("Error triggering scraper:", err);
        alert("Failed to communicate with the API.");
        btn.disabled = false;
        btn.innerHTML = '🔄 Run Scraper Now';
    });
}

/**
 * Starts the animated progress bar and registers status polling.
 */
function startProgressTracking(startVal = 0) {
    const container = document.getElementById('scraper-progress-container');
    const btn = document.getElementById('btn-trigger-scraper');
    
    container.classList.remove('d-none');
    btn.disabled = true;
    btn.innerHTML = '⚡ Scraper Running...';

    currentProgress = startVal;
    updateProgressBar(currentProgress, "Initializing GitHub Action runner...");

    // Clear any existing intervals
    clearInterval(progressInterval);
    clearInterval(pollInterval);

    // Simulate progress: advance from 0% to 90% over 60 seconds (approx 1.5% per second)
    const duration = 60; // seconds
    const intervalTime = 1000; // ms
    const increment = 90 / duration;

    progressInterval = setInterval(() => {
        if (currentProgress < 90) {
            currentProgress += increment;
            let statusMsg = "Scraping job listings...";
            if (currentProgress > 30) statusMsg = "Extracting job details & descriptions...";
            if (currentProgress > 60) statusMsg = "Running ATS matching engine...";
            if (currentProgress > 80) statusMsg = "Generating cover letters via Gemini...";
            updateProgressBar(Math.min(90, Math.round(currentProgress)), statusMsg);
        }
    }, intervalTime);

    // Poll status from GitHub every 5 seconds
    pollInterval = setInterval(pollStatus, 5000);
}

/**
 * Updates progress bar width, percentage, and label status text.
 */
function updateProgressBar(percent, statusMsg) {
    document.getElementById('scraper-progress-bar').style.width = percent + '%';
    document.getElementById('progress-percent').innerText = percent + '%';
    document.getElementById('progress-status').innerText = statusMsg;
}

/**
 * Queries the API to check the current GitHub workflow run status.
 */
function pollStatus() {
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'get_scraper_status' })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            const runStatus = data.run_status;
            const conclusion = data.conclusion;

            if (runStatus === 'completed') {
                clearInterval(progressInterval);
                clearInterval(pollInterval);

                if (conclusion === 'success') {
                    updateProgressBar(100, "Scraper run successful! Syncing new matches...");
                    setTimeout(() => {
                        window.location.reload();
                    }, 2500);
                } else {
                    updateProgressBar(90, `Scraper finished with status: ${conclusion || 'unknown'}`);
                    resetTriggerButton();
                }
            } else if (runStatus === 'queued') {
                updateProgressBar(Math.max(5, Math.round(currentProgress)), "GitHub Action queued in cloud runner...");
            }
        }
    })
    .catch(err => console.error("Error polling scraper status:", err));
}

function resetTriggerButton() {
    const btn = document.getElementById('btn-trigger-scraper');
    btn.disabled = false;
    btn.innerHTML = '🔄 Run Scraper Now';
}

// Automatically check scraper status on dashboard load (restores progress bar if running)
document.addEventListener('DOMContentLoaded', () => {
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'get_scraper_status' })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            const runStatus = data.run_status;
            if (runStatus === 'queued' || runStatus === 'in_progress') {
                // If a run is already active, resume tracking from 30%
                startProgressTracking(30);
            }
        }
    })
    .catch(err => console.error("Initial scraper status check failed:", err));
});
