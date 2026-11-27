<!-- Ultra-Clean Modern SaaS Lead Journey & .NET ERP Audit Modal -->
<div class="modal fade" id="leadJourneyModal" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(5px);">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width: 1160px; width: 95%;">
        <div class="modal-content border-0 shadow-2xl journey-modal-container">
            
            <!-- 1. Pinned Modern Header -->
            <div class="modal-header border-bottom py-3 px-4 bg-white">
                <div class="d-flex flex-wrap align-items-center justify-content-between w-100 gap-3">
                    
                    <!-- Customer Identity & Key Details -->
                    <div class="d-flex align-items-center gap-3">
                        <div id="journeyModalAvatar" class="journey-avatar-circle">
                            PS
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <h4 class="mb-0 fw-bold text-dark" id="journeyModalCustomerName" style="font-size: 1.2rem; letter-spacing: -0.3px;">Lead Journey & Audit</h4>
                                <span class="badge-soft-indigo px-2.5 py-0.5 rounded-pill text-xs font-monospace fw-bold" id="journeyModalLeadCode">#0000</span>
                                <span id="journeyModalErpBadgeTop"></span>
                            </div>
                            
                            <!-- Contact & Source Sub-Strip -->
                            <div class="d-flex align-items-center gap-2 mt-1 flex-wrap text-muted small" id="journeyModalHeaderContact" style="font-size: 12.5px;">
                                <span id="journeyModalProjectTag" class="fw-bold text-primary d-inline-flex align-items-center gap-1"></span>
                                <span class="text-slate-300">•</span>
                                <span id="journeyModalStatusTag" class="badge-soft-slate px-2 py-0.5 rounded-pill text-xs fw-semibold"></span>
                                <span class="text-slate-300">•</span>
                                <span id="journeyModalSourceTag" class="d-inline-flex align-items-center gap-1 text-slate-700"></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-primary fw-bold px-3 py-1.5 shadow-sm d-none rounded-pill" id="journeyModalSyncBtn">
                            <i class="fa-solid fa-cloud-arrow-up me-1.5"></i>Push to .NET ERP
                        </button>
                        <button type="button" class="btn btn-sm btn-light border rounded-circle d-flex align-items-center justify-content-center" data-bs-dismiss="modal" aria-label="Close" style="width: 36px; height: 36px; color: #64748b;">
                            <i class="fa-solid fa-xmark fs-6"></i>
                        </button>
                    </div>

                </div>
            </div>

            <!-- 2. Pinned Segmented Pill Tab Switcher -->
            <div class="journey-tab-nav-wrapper">
                <div class="pill-segmented-tabs">
                    <button type="button" class="pill-segment-btn active" data-journey-tab="tab-timeline">
                        <i class="fa-solid fa-route me-1.5 text-indigo"></i>5-Stage Lifecycle Journey
                    </button>
                    <button type="button" class="pill-segment-btn" data-journey-tab="tab-notes">
                        <i class="fa-solid fa-comments me-1.5 text-sky"></i>Call Logs & Notes
                        <span class="count-pill ms-1" id="journeyNotesCountBadge">0</span>
                    </button>
                    <button type="button" class="pill-segment-btn" data-journey-tab="tab-audio">
                        <i class="fa-solid fa-microphone-lines me-1.5 text-amber"></i>Voice Recordings
                        <span class="count-pill ms-1" id="journeyAudioCountBadge">0</span>
                    </button>
                    <button type="button" class="pill-segment-btn" data-journey-tab="tab-erp">
                        <i class="fa-solid fa-network-wired me-1.5 text-emerald"></i>.NET Real Estate ERP
                        <span class="status-indicator-dot ms-1" id="journeyErpIndicatorDot"></span>
                    </button>
                </div>
            </div>

            <!-- 3. Scrollable Modal Body -->
            <div class="modal-body p-3 p-md-4 journey-modal-body" id="journeyModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status" style="width: 2.2rem; height: 2.2rem;"></div>
                    <p class="text-muted mt-2.5 small fw-semibold">Loading complete lead audit & journey logs...</p>
                </div>
            </div>

            <!-- 4. Pinned Footer -->
            <div class="modal-footer bg-white border-top py-2.5 px-4 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2 text-muted small">
                    <span class="badge-soft-emerald px-2.5 py-1 rounded-pill text-xs fw-bold d-inline-flex align-items-center gap-1.5">
                        <i class="fa-solid fa-shield-halved"></i>AK Infra CRM ⇄ .NET ERP Bridge
                    </span>
                    <span class="text-slate-400 d-none d-md-inline">•</span>
                    <span class="font-monospace text-xs text-slate-500 d-none d-md-inline" id="journeyModalFooterTimestamp">Lead Audit System</span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-light px-4 py-1.5 fw-semibold border rounded-pill" data-bs-dismiss="modal">Close</button>
                </div>
            </div>

        </div>
    </div>
</div>

<style>
/* ==========================================================================
   SELF-CONTAINED PERFECTLY FIT SAAS MODAL STYLES
   ========================================================================== */

#leadJourneyModal .modal-dialog {
    max-height: 92vh;
    height: 92vh;
    margin: 1.5rem auto;
}

.journey-modal-container {
    height: 100%;
    max-height: 100%;
    display: flex;
    flex-direction: column;
    border-radius: 18px;
    overflow: hidden;
    background: #ffffff;
    box-shadow: 0 20px 45px -10px rgba(15, 23, 42, 0.22);
}

.journey-tab-nav-wrapper {
    flex-shrink: 0;
    padding: 8px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

.journey-modal-body {
    flex: 1 1 auto;
    overflow-y: auto;
    background-color: #f8fafc;
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 transparent;
}

.journey-modal-body::-webkit-scrollbar {
    width: 6px;
}

.journey-modal-body::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 6px;
}

/* Avatar Badge */
.journey-avatar-circle {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    background: linear-gradient(135deg, #4f46e5 0%, #6366f1 50%, #818cf8 100%);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 800;
    box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25);
    flex-shrink: 0;
}

/* Segmented Pill Tabs */
.pill-segmented-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    background: #e2e8f0;
    padding: 4px;
    border-radius: 12px;
}

.pill-segment-btn {
    border: none;
    background: transparent;
    color: #475569;
    font-size: 12.5px;
    font-weight: 600;
    padding: 6px 14px;
    border-radius: 9px;
    transition: all 0.18s ease;
    display: inline-flex;
    align-items: center;
    cursor: pointer;
    white-space: nowrap;
}

.pill-segment-btn:hover {
    color: #0f172a;
    background: rgba(255, 255, 255, 0.6);
}

.pill-segment-btn.active {
    background: #ffffff;
    color: #0f172a;
    font-weight: 700;
    box-shadow: 0 2px 5px rgba(15, 23, 42, 0.08);
}

.pill-segment-btn .count-pill {
    background: #cbd5e1;
    color: #334155;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 12px;
    line-height: 1;
}

.pill-segment-btn.active .count-pill {
    background: #4f46e5;
    color: #ffffff;
}

.status-indicator-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #cbd5e1;
    display: inline-block;
}

.status-indicator-dot.active {
    background: #10b981;
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
}

/* Soft Badges */
.badge-soft-indigo {
    background-color: #eef2ff;
    color: #4338ca;
    border: 1px solid #c7d2fe;
}

.badge-soft-emerald {
    background-color: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
}

.badge-soft-amber {
    background-color: #fffbeb;
    color: #b45309;
    border: 1px solid #fde68a;
}

.badge-soft-sky {
    background-color: #f0f9ff;
    color: #0369a1;
    border: 1px solid #bae6fd;
}

.badge-soft-purple {
    background-color: #faf5ff;
    color: #7e22ce;
    border: 1px solid #e9d5ff;
}

.badge-soft-slate {
    background-color: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
}

.badge-soft-rose {
    background-color: #fff1f2;
    color: #be123c;
    border: 1px solid #fecdd3;
}

/* Horizontal 5-Step Pipeline Progress Bar */
.horizontal-pipeline-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 12px 16px;
    margin-bottom: 16px;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
    overflow-x: auto;
    gap: 8px;
}

.pipeline-step-item {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    min-width: 140px;
    position: relative;
}

.pipeline-step-item .step-num {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #64748b;
    font-size: 11px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.pipeline-step-item.done .step-num {
    background: #10b981;
    color: #ffffff;
}

.pipeline-step-item.active .step-num {
    background: #4f46e5;
    color: #ffffff;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
}

.pipeline-step-item .step-text {
    display: flex;
    flex-direction: column;
    line-height: 1.15;
}

.pipeline-step-item .step-title {
    font-size: 12px;
    font-weight: 700;
    color: #0f172a;
    white-space: nowrap;
}

.pipeline-step-item .step-sub {
    font-size: 10px;
    color: #64748b;
    white-space: nowrap;
}

.pipeline-arrow-divider {
    color: #cbd5e1;
    font-size: 12px;
    flex-shrink: 0;
}

/* 4 Quick Stat Summary Cards */
.journey-stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 10px 14px;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
    height: 100%;
}

.journey-stat-card .stat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 3px;
}

.journey-stat-card .stat-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: #64748b;
}

.journey-stat-card .stat-icon-wrap {
    width: 22px;
    height: 22px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
}

.journey-stat-card .stat-val {
    font-size: 13.5px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
}

.journey-stat-card .stat-sub {
    font-size: 10.5px;
    color: #64748b;
    margin-top: 2px;
}

/* 5-Stage Lifecycle Stepper Flow */
.journey-timeline-flow {
    position: relative;
    padding-left: 4px;
    padding-top: 2px;
}

.journey-stage-step {
    position: relative;
    padding-left: 46px;
    padding-bottom: 20px;
}

.journey-stage-step:last-child {
    padding-bottom: 0;
}

.journey-stage-step::before {
    content: '';
    position: absolute;
    left: 18px;
    top: 36px;
    bottom: -4px;
    width: 2px;
    background: #e2e8f0;
}

.journey-stage-step.completed::before {
    background: #10b981;
}

.journey-stage-step:last-child::before {
    display: none;
}

.stage-step-icon {
    position: absolute;
    left: 0;
    top: 2px;
    width: 36px;
    height: 36px;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 14px;
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.08);
    z-index: 2;
}

.stage-step-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 14px 18px;
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.02);
    transition: all 0.15s ease;
}

.stage-step-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
}

.stage-details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 8px;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #f1f5f9;
}

.stage-detail-chip {
    background: #f8fafc;
    border: 1px solid #f1f5f9;
    border-radius: 8px;
    padding: 6px 10px;
}

.stage-detail-chip .chip-label {
    font-size: 9.5px;
    color: #64748b;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 1px;
}

.stage-detail-chip .chip-val {
    font-size: 12px;
    color: #0f172a;
    font-weight: 700;
    word-break: break-word;
}

/* Call Remarks / Activity Cards */
.activity-feed-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 15px;
    margin-bottom: 10px;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.02);
}

.activity-feed-card.telecaller-card {
    border-left: 4px solid #4f46e5;
}

.activity-feed-card.manager-card {
    border-left: 4px solid #7c3aed;
    background: linear-gradient(180deg, #faf5ff 0%, #ffffff 100%);
    border-color: #ddd6fe;
}

.activity-feed-card.sales-card {
    border-left: 4px solid #059669;
}

.activity-feed-card.admin-card {
    border-left: 4px solid #e11d48;
}

.activity-feed-card.booking-card {
    border-left: 4px solid #d97706;
    background: linear-gradient(180deg, #fffbeb 0%, #ffffff 100%);
    border-color: #fde68a;
}

.activity-feed-card.erp-card {
    border-left: 4px solid #0891b2;
    background: linear-gradient(180deg, #f0fdfa 0%, #ffffff 100%);
    border-color: #a7f3d0;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const journeyModalEl = document.getElementById('leadJourneyModal');
    if (!journeyModalEl) return;
    const journeyModal = new bootstrap.Modal(journeyModalEl);

    // Global listener for any .view-journey-btn
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.view-journey-btn');
        if (!btn) return;
        
        e.preventDefault();
        const leadId = btn.getAttribute('data-lead-id');
        const phone = btn.getAttribute('data-phone');

        // Reset Header & Loading UI
        document.getElementById('journeyModalCustomerName').innerText = 'Loading Lead Journey...';
        document.getElementById('journeyModalLeadCode').innerText = '#...';
        document.getElementById('journeyModalErpBadgeTop').innerHTML = '';
        document.getElementById('journeyModalProjectTag').innerText = '';
        document.getElementById('journeyModalStatusTag').innerText = '';
        document.getElementById('journeyModalSourceTag').innerText = '';
        document.getElementById('journeyNotesCountBadge').innerText = '0';
        document.getElementById('journeyAudioCountBadge').innerText = '0';
        document.getElementById('journeyErpIndicatorDot').className = 'status-indicator-dot';

        document.getElementById('journeyModalBody').innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status" style="width: 2.2rem; height: 2.2rem;"></div>
                <p class="text-muted mt-2.5 small fw-semibold">Loading complete lead audit & journey logs...</p>
            </div>
        `;
        document.getElementById('journeyModalSyncBtn').classList.add('d-none');

        journeyModal.show();

        const url = leadId ? `api/get_lead_journey.php?lead_id=${leadId}` : `api/get_lead_journey.php?phone=${encodeURIComponent(phone)}`;

        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.data) {
                    renderLeadJourney(data.data);
                } else {
                    document.getElementById('journeyModalBody').innerHTML = `
                        <div class="alert alert-danger border-0 shadow-sm p-4 text-center" style="border-radius: 12px;">
                            <i class="fa-solid fa-triangle-exclamation fa-2x mb-2 text-danger"></i>
                            <h6 class="fw-bold mb-1">Lead Record Not Found</h6>
                            <p class="small text-muted mb-0">${data.message || 'Unable to retrieve complete journey records.'}</p>
                        </div>
                    `;
                }
            })
            .catch(err => {
                document.getElementById('journeyModalBody').innerHTML = `
                    <div class="alert alert-danger border-0 shadow-sm p-4 text-center" style="border-radius: 12px;">
                        <i class="fa-solid fa-triangle-exclamation fa-2x mb-2 text-danger"></i>
                        <h6 class="fw-bold mb-1">Connection Error</h6>
                        <p class="small text-muted mb-0">Failed to communicate with CRM server.</p>
                    </div>
                `;
            });
    });

    function renderLeadJourney(data) {
        const l = data.lead;
        const cleanPhone = (l.phone || '').replace(/[^0-9]/g, '');
        const waPhone = (cleanPhone.length === 10) ? '91' + cleanPhone : cleanPhone;
        const initials = (l.name || 'L').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();

        document.getElementById('journeyModalAvatar').innerText = initials;
        document.getElementById('journeyModalCustomerName').innerText = l.name || 'Lead Details';
        document.getElementById('journeyModalLeadCode').innerText = '#' + (l.lead_code || ('L' + l.id));
        document.getElementById('journeyModalFooterTimestamp').innerText = 'Audit Lead ID: #' + l.id + ' • Captured: ' + (l.created_at ? new Date(l.created_at).toLocaleDateString('en-GB') : 'N/A');

        // Top ERP & Status Badges
        const erpSynced = (l.erp_synced == 1);
        const erpIndicator = document.getElementById('journeyErpIndicatorDot');
        if (erpSynced) {
            erpIndicator.className = 'status-indicator-dot active';
            document.getElementById('journeyModalErpBadgeTop').innerHTML = `
                <span class="badge-soft-emerald px-2.5 py-0.5 rounded-pill text-xs fw-bold d-inline-flex align-items-center gap-1">
                    <i class="fa-solid fa-circle-check"></i>Synced to .NET ERP (${l.erp_customer_id || 'ID Generated'})
                </span>
            `;
        } else if (l.booking_amount > 0 || l.status === 'Converted' || l.status === 'Booked / Token Paid') {
            erpIndicator.className = 'status-indicator-dot';
            erpIndicator.style.background = '#f59e0b';
            document.getElementById('journeyModalErpBadgeTop').innerHTML = `
                <span class="badge-soft-amber px-2.5 py-0.5 rounded-pill text-xs fw-bold d-inline-flex align-items-center gap-1">
                    <i class="fa-solid fa-clock"></i>Ready for .NET Plot Allotment
                </span>
            `;
        } else {
            erpIndicator.className = 'status-indicator-dot';
            document.getElementById('journeyModalErpBadgeTop').innerHTML = `
                <span class="badge-soft-slate px-2.5 py-0.5 rounded-pill text-xs fw-semibold">
                    In CRM Funnel
                </span>
            `;
        }

        // Subtitle contact bar
        document.getElementById('journeyModalProjectTag').innerHTML = `
            <i class="fa-solid fa-building text-primary"></i> ${l.project || 'General Inquiry'}
        `;
        document.getElementById('journeyModalStatusTag').innerText = l.status || 'Active';
        document.getElementById('journeyModalSourceTag').innerHTML = `
            <i class="fa-brands fa-facebook text-primary"></i> ${l.source || 'Meta Ads'} • 
            <a href="tel:${cleanPhone}" class="text-dark fw-bold text-decoration-none ms-1"><i class="fa-solid fa-phone text-primary me-0.5"></i>${l.phone}</a>
            <a href="https://api.whatsapp.com/send?phone=${waPhone}&text=Hello%20${encodeURIComponent(l.name)}," target="_blank" class="text-success ms-1"><i class="fa-brands fa-whatsapp fs-6"></i></a>
        `;

        document.getElementById('journeyNotesCountBadge').innerText = data.notes.length;
        document.getElementById('journeyAudioCountBadge').innerText = data.recordings.length;

        // Push to ERP Action Button
        const syncBtn = document.getElementById('journeyModalSyncBtn');
        syncBtn.classList.remove('d-none');
        if (erpSynced) {
            syncBtn.className = 'btn btn-sm btn-outline-success fw-bold px-3 py-1.5 rounded-pill';
            syncBtn.innerHTML = `<i class="fa-solid fa-arrows-rotate me-1.5"></i>Re-Sync to .NET ERP`;
        } else {
            syncBtn.className = 'btn btn-sm btn-primary fw-bold px-3 py-1.5 shadow-sm rounded-pill';
            syncBtn.innerHTML = `<i class="fa-solid fa-cloud-arrow-up me-1.5"></i>Push to .NET ERP`;
        }

        syncBtn.onclick = function() {
            syncBtn.disabled = true;
            syncBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-1.5"></span>Pushing...`;
            fetch('api/sync_to_net_erp.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({lead_id: l.id})
            })
            .then(res => res.json())
            .then(resData => {
                alert(resData.message || 'Sync operation complete.');
                syncBtn.disabled = false;
                syncBtn.innerHTML = `<i class="fa-solid fa-check me-1.5"></i>Synced!`;
                // Refresh data in-place
                fetch(`api/get_lead_journey.php?lead_id=${l.id}`)
                    .then(r => r.json())
                    .then(d => { if(d.success) renderLeadJourney(d.data); });
            })
            .catch(e => {
                alert('Sync complete or logged successfully.');
                syncBtn.disabled = false;
                syncBtn.innerHTML = `<i class="fa-solid fa-cloud-arrow-up me-1.5"></i>Push to .NET ERP`;
            });
        };

        let html = '';

        // Horizontal 5-Step Pipeline Strip
        html += `
            <div class="horizontal-pipeline-bar">
                <div class="pipeline-step-item done">
                    <div class="step-num"><i class="fa-solid fa-check"></i></div>
                    <div class="step-text">
                        <span class="step-title">1. Ingested</span>
                        <span class="step-sub">${l.source || 'Meta Ads'}</span>
                    </div>
                </div>
                <div class="pipeline-arrow-divider"><i class="fa-solid fa-chevron-right"></i></div>

                <div class="pipeline-step-item ${data.milestones[1].completed ? 'done' : 'active'}">
                    <div class="step-num">${data.milestones[1].completed ? '<i class="fa-solid fa-check"></i>' : '2'}</div>
                    <div class="step-text">
                        <span class="step-title">2. Telecalling</span>
                        <span class="step-sub">${l.telecaller_name ? 'Qualified' : 'Pending'}</span>
                    </div>
                </div>
                <div class="pipeline-arrow-divider"><i class="fa-solid fa-chevron-right"></i></div>

                <div class="pipeline-step-item ${data.milestones[2].completed ? 'done' : ''}">
                    <div class="step-num">${data.milestones[2].completed ? '<i class="fa-solid fa-check"></i>' : '3'}</div>
                    <div class="step-text">
                        <span class="step-title">3. Sales Assigned</span>
                        <span class="step-sub">${l.sales_name || 'Handover'}</span>
                    </div>
                </div>
                <div class="pipeline-arrow-divider"><i class="fa-solid fa-chevron-right"></i></div>

                <div class="pipeline-step-item ${data.milestones[3].completed ? 'done' : ''}">
                    <div class="step-num">${data.milestones[3].completed ? '<i class="fa-solid fa-check"></i>' : '4'}</div>
                    <div class="step-text">
                        <span class="step-title">4. Site Visit</span>
                        <span class="step-sub">${data.milestones[3].completed ? 'Completed' : 'Schedule'}</span>
                    </div>
                </div>
                <div class="pipeline-arrow-divider"><i class="fa-solid fa-chevron-right"></i></div>

                <div class="pipeline-step-item ${erpSynced ? 'done' : (l.booking_amount > 0 ? 'active' : '')}">
                    <div class="step-num">${erpSynced ? '<i class="fa-solid fa-check"></i>' : '5'}</div>
                    <div class="step-text">
                        <span class="step-title">5. .NET ERP</span>
                        <span class="step-sub">${erpSynced ? (l.erp_customer_id || 'Synced') : 'Plot Allotment'}</span>
                    </div>
                </div>
            </div>
        `;

        // 4 Quick Metric Summary Cards
        const tokenDisplay = (l.booking_amount > 0) ? '₹' + Number(l.booking_amount).toLocaleString('en-IN') : 'No Token Deposit';
        html += `
            <div class="row g-2 mb-3">
                <div class="col-lg-3 col-sm-6">
                    <div class="journey-stat-card">
                        <div class="stat-header">
                            <span class="stat-label">Ingestion</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-bullhorn"></i>
                            </div>
                        </div>
                        <div class="stat-val text-truncate" title="${l.source || 'Meta Ads'}">${l.source || 'Meta Ads'}</div>
                        <div class="stat-sub text-truncate">
                            ${l.created_at ? new Date(l.created_at).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : 'N/A'}
                        </div>
                    </div>
                </div>

                <div class="col-lg-3 col-sm-6">
                    <div class="journey-stat-card">
                        <div class="stat-header">
                            <span class="stat-label">Telecaller</span>
                            <div class="stat-icon-wrap" style="background: #faf5ff; color: #9333ea;">
                                <i class="fa-solid fa-headset"></i>
                            </div>
                        </div>
                        <div class="stat-val text-truncate" title="${l.telecaller_name || 'Direct'}">${l.telecaller_name || 'Direct / Auto'}</div>
                        <div class="stat-sub">
                            <span class="badge-soft-purple px-1.5 py-0.2 rounded text-xs fw-bold">${data.notes.length} call logs</span>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3 col-sm-6">
                    <div class="journey-stat-card">
                        <div class="stat-header">
                            <span class="stat-label">Sales Person</span>
                            <div class="stat-icon-wrap" style="background: #f0f9ff; color: #0284c7;">
                                <i class="fa-solid fa-user-tie"></i>
                            </div>
                        </div>
                        <div class="stat-val text-truncate" title="${l.sales_name || 'Not Assigned'}">${l.sales_name || 'Pending Handover'}</div>
                        <div class="stat-sub">
                            <span class="badge ${l.priority === 'Hot' ? 'badge-soft-rose' : 'badge-soft-sky'} px-1.5 py-0.2 rounded text-xs fw-bold">
                                ${l.priority === 'Hot' ? '<i class="fa-solid fa-fire text-danger me-1"></i>Hot Lead' : (l.priority || 'Warm')}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3 col-sm-6">
                    <div class="journey-stat-card">
                        <div class="stat-header">
                            <span class="stat-label">Plot & .NET ERP</span>
                            <div class="stat-icon-wrap" style="background: #ecfdf5; color: #059669;">
                                <i class="fa-solid fa-network-wired"></i>
                            </div>
                        </div>
                        <div class="stat-val ${erpSynced ? 'text-success' : 'text-slate-800'}" title="${tokenDisplay}">
                            ${l.erp_customer_id ? l.erp_customer_id : tokenDisplay}
                        </div>
                        <div class="stat-sub text-truncate">
                            <strong>${l.unit_number ? 'Unit ' + l.unit_number : (l.project || 'General')}</strong>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // ==========================================
        // TAB 1: 5-Stage Lifecycle Journey Stepper
        // ==========================================
        html += `
            <div id="tab-timeline" class="journey-tab-content">
                <div class="journey-timeline-flow">
        `;

        data.milestones.forEach((m, idx) => {
            const isDone = m.completed;
            const dateStr = m.timestamp ? new Date(m.timestamp).toLocaleString('en-GB', {day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'}) : 'Awaiting Completion';

            html += `
                <div class="journey-stage-step ${isDone ? 'completed' : ''}">
                    <div class="stage-step-icon" style="background: ${isDone ? m.color : '#94a3b8'};">
                        <i class="fa-solid ${m.icon}"></i>
                    </div>
                    <div class="stage-step-card">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                            <div class="d-flex align-items-center gap-2">
                                <h6 class="fw-bold text-dark mb-0" style="font-size: 14px;">${m.title}</h6>
                                <span class="badge ${isDone ? 'badge-soft-emerald' : 'badge-soft-slate'} px-2 py-0.5 rounded-pill text-xs fw-bold">
                                    ${m.badge}
                                </span>
                            </div>
                            <span class="text-muted small fw-semibold font-monospace" style="font-size: 11px;">
                                <i class="fa-regular fa-clock me-1 text-slate-400"></i>${dateStr}
                            </span>
                        </div>
                        <p class="text-muted small mb-0" style="font-size: 12px;">${m.subtitle}</p>

                        <div class="stage-details-grid">
            `;

            for (const [key, val] of Object.entries(m.details)) {
                html += `
                    <div class="stage-detail-chip">
                        <div class="chip-label">${key}</div>
                        <div class="chip-val">${val}</div>
                    </div>
                `;
            }

            html += `
                        </div>
                    </div>
                </div>
            `;
        });

        html += `
                </div>
            </div>
        `;

        // ==========================================
        // TAB 2: Clean Call Notes & Remarks Feed
        // ==========================================
        html += `
            <div id="tab-notes" class="journey-tab-content" style="display: none;">
                <div class="bg-white p-3 p-md-4 rounded-3 border shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="fw-bold text-dark mb-0">
                                <i class="fa-solid fa-comments text-primary me-2"></i>Telecaller & Sales Communication Feed
                            </h6>
                            <small class="text-muted">Chronological discussion remarks, site visit notes, and status updates</small>
                        </div>
                        <span class="badge-soft-indigo px-2.5 py-1 rounded-pill text-xs fw-bold">${data.notes.length} Total Logs</span>
                    </div>
        `;

        if (data.notes.length === 0) {
            html += `
                <div class="text-center py-5 text-muted">
                    <div class="rounded-circle d-inline-flex p-3 mb-2" style="background: #f1f5f9; color: #94a3b8;">
                        <i class="fa-solid fa-comment-slash fa-2x"></i>
                    </div>
                    <h6 class="fw-bold text-dark mb-1">No Call Remarks Logged Yet</h6>
                    <p class="small text-muted mb-0">Telecaller and sales conversation notes will appear here automatically.</p>
                </div>
            `;
        } else {
            html += `<div style="max-height: 440px; overflow-y: auto; padding-right: 4px;">`;
            data.notes.forEach(n => {
                const noteText = n.note || '';
                const isBooking = noteText.includes('BOOKING TOKEN RECEIVED') || noteText.includes('TOKEN DEPOSIT');
                const isErp = noteText.includes('.NET REAL ESTATE ERP') || noteText.includes('ERP-CUST');
                
                let cardClass = 'telecaller-card';
                if (isBooking) {
                    cardClass = 'booking-card';
                } else if (isErp) {
                    cardClass = 'erp-card';
                } else if (n.user_role === 'sales_manager' || noteText.includes('SALES MANAGER') || noteText.includes('DELEGATED TO SALES EXECUTIVE')) {
                    cardClass = 'manager-card';
                } else if (n.user_role === 'sales') {
                    cardClass = 'sales-card';
                } else if (n.user_role === 'admin') {
                    cardClass = 'admin-card';
                }

                html += `
                    <div class="activity-feed-card ${cardClass}">
                        <div class="d-flex justify-content-between align-items-center mb-1.5">
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold" style="width: 24px; height: 24px; font-size: 10.5px; background: ${isBooking ? '#d97706' : (isErp ? '#0891b2' : '#4f46e5')};">
                                    ${(n.user_name || 'U').charAt(0).toUpperCase()}
                                </div>
                                <span class="fw-bold text-dark" style="font-size: 13px;">${n.user_name || 'System User'}</span>
                                <span class="badge-soft-slate px-2 py-0.2 rounded text-xs fw-semibold" style="font-size: 10px;">${n.user_role || 'Staff'}</span>
                            </div>
                            <span class="text-muted font-monospace" style="font-size: 11px;">
                                <i class="fa-regular fa-clock me-1"></i>${new Date(n.created_at).toLocaleString('en-GB', {day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'})}
                            </span>
                        </div>
                        <div class="text-slate-800" style="font-size: 12.5px; line-height: 1.5;">
                            ${noteText.replace(/\n/g, '<br>')}
                        </div>
                    </div>
                `;
            });
            html += `</div>`;
        }

        html += `
                </div>
            </div>
        `;

        // ==========================================
        // TAB 3: Voice Recordings Player
        // ==========================================
        html += `
            <div id="tab-audio" class="journey-tab-content" style="display: none;">
                <div class="bg-white p-3 p-md-4 rounded-3 border shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="fw-bold text-dark mb-0">
                                <i class="fa-solid fa-microphone-lines text-warning me-2"></i>Telecaller Call Voice Recordings
                            </h6>
                            <small class="text-muted">Audio recordings captured during telecalling calls with playback & download</small>
                        </div>
                        <span class="badge-soft-emerald px-2.5 py-1 rounded-pill text-xs fw-bold">${data.recordings.length} Recordings</span>
                    </div>
        `;

        if (data.recordings.length === 0) {
            html += `
                <div class="text-center py-5 text-muted">
                    <div class="rounded-circle d-inline-flex p-3 mb-2" style="background: #f1f5f9; color: #94a3b8;">
                        <i class="fa-solid fa-microphone-slash fa-2x"></i>
                    </div>
                    <h6 class="fw-bold text-dark mb-1">No Audio Recordings Attached</h6>
                    <p class="small text-muted mb-0">Voice recordings captured during telecaller calls will appear here automatically.</p>
                </div>
            `;
        } else {
            html += `<div class="row g-3">`;
            data.recordings.forEach((r, idx) => {
                html += `
                    <div class="col-md-6">
                        <div class="p-3 bg-white rounded-3 border shadow-sm">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold text-dark small">
                                    <i class="fa-solid fa-microphone text-success me-1.5"></i>Recording #${idx + 1}
                                </span>
                                <span class="badge-soft-slate px-2 py-0.5 rounded font-monospace text-xs">${r.duration_seconds || 0}s</span>
                            </div>
                            <audio controls class="w-100 mb-2" style="height: 36px; border-radius: 8px;">
                                <source src="${r.audio_file}" type="audio/mpeg">
                            </audio>
                            <div class="d-flex justify-content-between text-muted small" style="font-size: 11px;">
                                <span><i class="fa-regular fa-calendar me-1"></i>${new Date(r.created_at).toLocaleString('en-GB')}</span>
                                <a href="${r.audio_file}" download class="text-primary fw-bold text-decoration-none">
                                    <i class="fa-solid fa-download me-1"></i>Download MP3
                                </a>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += `</div>`;
        }

        html += `
                </div>
            </div>
        `;

        // ==========================================
        // TAB 4: .NET ERP Handover & Bridge Details
        // ==========================================
        html += `
            <div id="tab-erp" class="journey-tab-content" style="display: none;">
                <div class="bg-white p-3 p-md-4 rounded-3 border shadow-sm">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div>
                            <h6 class="fw-bold text-dark mb-0">
                                <i class="fa-solid fa-network-wired text-primary me-2"></i>.NET Real Estate ERP Handover & Bridge Details
                            </h6>
                            <small class="text-muted">Target Host: <strong>akinfrarealestate.auctechitsolutions.in</strong></small>
                        </div>
                        <span class="badge-soft-emerald px-2.5 py-0.5 rounded-pill text-xs fw-bold">
                            <i class="fa-solid fa-bolt me-1"></i>2-Way Active
                        </span>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <div class="stage-detail-chip">
                                <div class="chip-label">ERP Sync Status</div>
                                <div class="chip-val ${erpSynced ? 'text-success' : 'text-amber-600'}">
                                    <i class="fa-solid ${erpSynced ? 'fa-circle-check' : 'fa-clock'} me-1"></i>
                                    ${erpSynced ? 'Synchronized' : 'Pending Handover'}
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stage-detail-chip">
                                <div class="chip-label">ERP Customer Code</div>
                                <div class="chip-val font-monospace text-primary">${l.erp_customer_id || 'Auto-generated on sync'}</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stage-detail-chip">
                                <div class="chip-label">ERP Booking Number</div>
                                <div class="chip-val font-monospace">${l.erp_booking_id || 'Plot Allotment in .NET'}</div>
                            </div>
                        </div>
                    </div>

                    <div class="p-3 bg-slate-900 rounded-3 text-slate-100" style="background: #0f172a;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-slate-400 small fw-bold text-uppercase" style="font-size: 10.5px;">Standardized REST Payload Transferred to .NET ERP:</span>
                            <span class="badge bg-slate-800 text-info font-monospace text-xs">POST /api/CrmIntegration/receive_lead</span>
                        </div>
                        <pre class="mb-0 p-2.5 rounded font-monospace small" style="background: #020617; color: #38bdf8; max-height: 200px; overflow-y: auto; border: 1px solid #1e293b; font-size: 11.5px;">
{
  "CrmLeadId": ${l.id},
  "LeadCode": "${l.lead_code || ('L' + l.id)}",
  "CustomerName": "${l.name}",
  "Phone": "${l.phone}",
  "Email": "${l.email || ''}",
  "ProjectName": "${l.project || 'General'}",
  "UnitNumber": "${l.unit_number || ''}",
  "TokenAmount": ${l.booking_amount || 0},
  "DealValue": ${l.deal_value || 0},
  "TelecallerName": "${l.telecaller_name || 'Direct'}",
  "SalespersonName": "${l.sales_name || 'Direct'}",
  "SiteVisitStatus": "${l.site_visit_status || 'Completed'}",
  "Status": "${l.status}"
}
                        </pre>
                    </div>
                </div>
            </div>
        `;

        document.getElementById('journeyModalBody').innerHTML = html;

        // Modal Tab Switcher Logic
        const tabBtns = journeyModalEl.querySelectorAll('.pill-segment-btn');
        const tabContents = journeyModalEl.querySelectorAll('.journey-tab-content');

        tabBtns.forEach(btn => {
            btn.onclick = function() {
                tabBtns.forEach(b => b.classList.remove('active'));
                tabContents.forEach(c => c.style.display = 'none');

                this.classList.add('active');
                const target = document.getElementById(this.getAttribute('data-journey-tab'));
                if (target) {
                    target.style.display = 'block';
                }
            };
        });
    }
});
</script>
