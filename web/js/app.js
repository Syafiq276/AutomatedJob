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
