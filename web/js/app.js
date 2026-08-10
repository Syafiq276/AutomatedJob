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

// Automatically check scraper status & initialize job filters on dashboard load
document.addEventListener('DOMContentLoaded', () => {
    // 1. Initialize metadata badges and load filter state
    initJobMetadataBadges();
    loadFiltersFromURL();

    // 2. Poll scraper status
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

/**
 * Triggers cover letter generation for a specific job ID.
 */
function generateCoverLetter(jobId) {
    const btn = document.getElementById(`btn-gen-letter-${jobId}`);
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⚡ Generating...';

    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'generate_cover_letter', id: jobId })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            // Replace the Generate button with the View button
            btn.outerHTML = `
                <button class="btn btn-outline-info btn-custom" onclick="viewCoverLetter(${JSON.stringify(data.job).replace(/"/g, '&quot;')})">
                    📝 Cover Letter
                </button>
            `;
            // Open the visualizer immediately
            viewCoverLetter(data.job);
        } else {
            alert("Error: " + (data.error || "Failed to generate cover letter"));
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    })
    .catch(err => {
        console.error("Error generating cover letter:", err);
        alert("Failed to communicate with the API.");
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

/**
 * ══════════════════════════════════════════════════════════════
 *  JOB METADATA DETECTION & INTERACTIVE FILTER ENGINE
 * ══════════════════════════════════════════════════════════════
 */

/**
 * Extracts or detects Sector, Programme, and Job Type metadata for a job post.
 */
function detectJobMetaData(row) {
    const title = (row.dataset.title || '').toLowerCase();
    const company = (row.dataset.company || '').toLowerCase();
    const desc = (row.dataset.description || '').toLowerCase();
    const explicitSector = (row.dataset.sector || '').trim().toLowerCase();
    const explicitProgramme = (row.dataset.programme || '').trim().toLowerCase();
    const explicitJobType = (row.dataset.jobtype || '').trim().toLowerCase();

    const fullText = `${title} ${company} ${desc}`;

    // 1. Determine Programme Type (Mapped to dropdown keys)
    let programmeKey = 'fresh_grad';
    let programmeLabel = '🌱 Fresh Grad / Entry';

    if (explicitProgramme === 'graduate_programme' || explicitProgramme.includes('trainee') || explicitProgramme.includes('protege') || explicitProgramme.includes('protégé')) {
        programmeKey = 'graduate_programme';
        programmeLabel = '🌟 Trainee / Protégé';
    } else if (explicitProgramme === 'internship' || explicitProgramme.includes('intern')) {
        programmeKey = 'internship';
        programmeLabel = '🎯 Internship';
    } else if (explicitProgramme === 'experienced' || explicitProgramme.includes('senior') || explicitProgramme.includes('mid')) {
        programmeKey = 'experienced';
        programmeLabel = '💼 Mid / Senior';
    } else if (explicitProgramme === 'fresh_grad' || explicitProgramme.includes('fresh') || explicitProgramme.includes('entry')) {
        programmeKey = 'fresh_grad';
        programmeLabel = '🌱 Fresh Grad / Entry';
    } else if (/protege|protégé|management trainee|graduate programme|graduate program|trainee|cadet/i.test(fullText)) {
        programmeKey = 'graduate_programme';
        programmeLabel = '🌟 Trainee / Protégé';
    } else if (/intern|internship|industrial training|attachment/i.test(fullText)) {
        programmeKey = 'internship';
        programmeLabel = '🎯 Internship';
    } else if (/senior|lead|principal|head|manager/i.test(fullText)) {
        programmeKey = 'experienced';
        programmeLabel = '💼 Mid / Senior';
    } else {
        programmeKey = 'fresh_grad';
        programmeLabel = '🌱 Fresh Grad / Entry';
    }

    // 2. Determine Job / Employment Type (Mapped to dropdown keys)
    let jobTypeKey = 'full_time';
    let jobTypeLabel = '💼 Full-Time';

    if (explicitJobType === 'contract' || explicitJobType.includes('contract') || explicitJobType.includes('temp')) {
        jobTypeKey = 'contract';
        jobTypeLabel = '📝 Contract';
    } else if (explicitJobType === 'part_time' || explicitJobType.includes('part')) {
        jobTypeKey = 'part_time';
        jobTypeLabel = '⏱️ Part-Time';
    } else if (explicitJobType === 'internship' || explicitJobType.includes('intern')) {
        jobTypeKey = 'internship';
        jobTypeLabel = '🎓 Internship';
    } else if (explicitJobType === 'full_time' || explicitJobType.includes('full')) {
        jobTypeKey = 'full_time';
        jobTypeLabel = '💼 Full-Time';
    } else if (/contract|temporary|\btemp\b/i.test(fullText)) {
        jobTypeKey = 'contract';
        jobTypeLabel = '📝 Contract';
    } else if (/part-time|part time|freelance/i.test(fullText)) {
        jobTypeKey = 'part_time';
        jobTypeLabel = '⏱️ Part-Time';
    } else if (/intern|internship/i.test(fullText)) {
        jobTypeKey = 'internship';
        jobTypeLabel = '🎓 Internship';
    } else {
        jobTypeKey = 'full_time';
        jobTypeLabel = '💼 Full-Time';
    }

    // 3. Determine Sector (Mapped to dropdown keys)
    let sectorKey = 'it_web';
    let sectorLabel = '🌐 IT & Web Dev';

    if (explicitSector === 'data_ai' || explicitSector.includes('data') || explicitSector.includes('analytics')) {
        sectorKey = 'data_ai';
        sectorLabel = '📊 Data & Analytics';
    } else if (explicitSector === 'engineering' || explicitSector.includes('software') || explicitSector.includes('devops')) {
        sectorKey = 'engineering';
        sectorLabel = '⚙️ Software Eng';
    } else if (explicitSector === 'finance' || explicitSector.includes('finance') || explicitSector.includes('bank')) {
        sectorKey = 'finance';
        sectorLabel = '💼 Finance & Business';
    } else if (explicitSector === 'it_web' || explicitSector.includes('web') || explicitSector.includes('it') || explicitSector.includes('developer')) {
        sectorKey = 'it_web';
        sectorLabel = '🌐 IT & Web Dev';
    } else if (explicitSector === 'other' || explicitSector.includes('other')) {
        sectorKey = 'other';
        sectorLabel = '📌 Other Sector';
    } else if (/data|analyst|analytics|big data|\bbi\b|machine learning|\bai\b|\bsql\b/i.test(fullText) && !/web developer|php|laravel/i.test(title)) {
        sectorKey = 'data_ai';
        sectorLabel = '📊 Data & Analytics';
    } else if (/software engineer|devops|\bqa\b|tester|systems engineer|backend engineer/i.test(fullText)) {
        sectorKey = 'engineering';
        sectorLabel = '⚙️ Software Eng';
    } else if (/bank|finance|accountant|audit|business analyst|fintech/i.test(fullText)) {
        sectorKey = 'finance';
        sectorLabel = '💼 Finance & Business';
    } else if (/web|developer|php|laravel|codeigniter|frontend|backend|full stack|fullstack|react|wordpress|html|javascript|it executive|software/i.test(fullText)) {
        sectorKey = 'it_web';
        sectorLabel = '🌐 IT & Web Dev';
    } else {
        sectorKey = 'other';
        sectorLabel = '📌 Other Sector';
    }

    return {
        programmeKey, programmeLabel,
        jobTypeKey, jobTypeLabel,
        sectorKey, sectorLabel
    };
}

/**
 * Initializes metadata badges on each job row element.
 */
function initJobMetadataBadges() {
    const rows = document.querySelectorAll('.job-row');
    rows.forEach(row => {
        const meta = detectJobMetaData(row);
        
        // Save computed metadata in dataset attributes for fast filtering
        row.dataset.computedSector = meta.sectorKey;
        row.dataset.computedProgramme = meta.programmeKey;
        row.dataset.computedJobtype = meta.jobTypeKey;

        // Populate metadata badge UI container
        const container = row.querySelector('.metadata-badges-container');
        if (container) {
            container.innerHTML = `
                <span class="badge-tag badge-tag-sector">${meta.sectorLabel}</span>
                <span class="badge-tag badge-tag-programme">${meta.programmeLabel}</span>
                <span class="badge-tag badge-tag-jobtype">${meta.jobTypeLabel}</span>
            `;
        }
    });
}

/**
 * Applies search keyword, sector, programme, job type filters, and sort order.
 */
function applyJobFilters() {
    const searchVal = (document.getElementById('filter-search')?.value || '').trim().toLowerCase();
    const sortVal = document.getElementById('filter-sort')?.value || 'newest';
    const sectorVal = document.getElementById('filter-sector')?.value || 'all';
    const programmeVal = document.getElementById('filter-programme')?.value || 'all';
    const jobTypeVal = document.getElementById('filter-jobtype')?.value || 'all';

    const container = document.getElementById('jobs-container');
    if (!container) return;

    const rows = Array.from(container.querySelectorAll('.job-row'));
    let visibleCount = 0;

    rows.forEach(row => {
        const title = (row.dataset.title || '').toLowerCase();
        const company = (row.dataset.company || '').toLowerCase();
        const location = (row.dataset.location || '').toLowerCase();
        const desc = (row.dataset.description || '').toLowerCase();

        const sector = row.dataset.computedSector || 'all';
        const programme = row.dataset.computedProgramme || 'all';
        const jobType = row.dataset.computedJobtype || 'all';

        // Check match conditions
        const matchesSearch = !searchVal || title.includes(searchVal) || company.includes(searchVal) || location.includes(searchVal) || desc.includes(searchVal);
        const matchesSector = sectorVal === 'all' || sector === sectorVal;
        const matchesProgramme = programmeVal === 'all' || programme === programmeVal;
        const matchesJobType = jobTypeVal === 'all' || jobType === jobTypeVal;

        if (matchesSearch && matchesSector && matchesProgramme && matchesJobType) {
            row.classList.remove('d-none');
            visibleCount++;
        } else {
            row.classList.add('d-none');
        }
    });

    // Sort visible job rows safely
    rows.sort((a, b) => {
        const scoreA = parseFloat(a.dataset.score || 0) || 0;
        const scoreB = parseFloat(b.dataset.score || 0) || 0;
        const createdA = parseInt(a.dataset.created || 0) || 0;
        const createdB = parseInt(b.dataset.created || 0) || 0;

        if (sortVal === 'score') {
            return scoreB - scoreA;
        } else if (sortVal === 'oldest') {
            return createdA - createdB;
        } else {
            // Default: newest first
            return createdB - createdA;
        }
    });

    // Re-append sorted rows to container
    rows.forEach(row => container.appendChild(row));

    // Update job counter badge
    const countEl = document.getElementById('filtered-jobs-count');
    if (countEl) countEl.innerText = visibleCount;

    // Toggle empty state message
    const noMsg = document.getElementById('no-filtered-jobs-msg');
    if (noMsg) {
        if (visibleCount === 0 && rows.length > 0) {
            noMsg.classList.remove('d-none');
        } else {
            noMsg.classList.add('d-none');
        }
    }

    // Sync filter state with URL parameters (without refreshing page)
    updateURLParams({
        search: searchVal,
        sort: sortVal,
        sector: sectorVal,
        programme: programmeVal,
        jobtype: jobTypeVal
    });
}

    // Re-append sorted rows to container
    rows.forEach(row => container.appendChild(row));

    // Update job counter badge
    const countEl = document.getElementById('filtered-jobs-count');
    if (countEl) countEl.innerText = visibleCount;

    // Toggle empty state message
    const noMsg = document.getElementById('no-filtered-jobs-msg');
    if (noMsg) {
        if (visibleCount === 0 && rows.length > 0) {
            noMsg.classList.remove('d-none');
        } else {
            noMsg.classList.add('d-none');
        }
    }

    // Sync filter state with URL parameters (without refreshing page)
    updateURLParams({
        search: searchVal,
        sort: sortVal,
        sector: sectorVal,
        programme: programmeVal,
        jobtype: jobTypeVal
    });
}

/**
 * Resets all filter dropdowns and search input to default values.
 */
function resetJobFilters() {
    if (document.getElementById('filter-search')) document.getElementById('filter-search').value = '';
    if (document.getElementById('filter-sort')) document.getElementById('filter-sort').value = 'newest';
    if (document.getElementById('filter-sector')) document.getElementById('filter-sector').value = 'all';
    if (document.getElementById('filter-programme')) document.getElementById('filter-programme').value = 'all';
    if (document.getElementById('filter-jobtype')) document.getElementById('filter-jobtype').value = 'all';
    applyJobFilters();
}

/**
 * Updates URL search parameters for persistent bookmarking.
 */
function updateURLParams(params) {
    const url = new URL(window.location.href);
    Object.keys(params).forEach(key => {
        if (params[key] && params[key] !== 'all' && params[key] !== 'newest') {
            url.searchParams.set(key, params[key]);
        } else {
            url.searchParams.delete(key);
        }
    });
    window.history.replaceState({}, '', url.toString());
}

/**
 * Restores filter values from URL query parameters on initial page load.
 */
function loadFiltersFromURL() {
    const url = new URL(window.location.href);
    const search = url.searchParams.get('search');
    const sort = url.searchParams.get('sort');
    const sector = url.searchParams.get('sector');
    const programme = url.searchParams.get('programme');
    const jobtype = url.searchParams.get('jobtype');

    if (search && document.getElementById('filter-search')) document.getElementById('filter-search').value = search;
    if (sort && document.getElementById('filter-sort')) document.getElementById('filter-sort').value = sort;
    if (sector && document.getElementById('filter-sector')) document.getElementById('filter-sector').value = sector;
    if (programme && document.getElementById('filter-programme')) document.getElementById('filter-programme').value = programme;
    if (jobtype && document.getElementById('filter-jobtype')) document.getElementById('filter-jobtype').value = jobtype;

    applyJobFilters();
}

