$(document).ready(function () {
    // Helper function to show/hide modal fields based on selected status
    function updateModalFields(status, leadId, animate) {
        var salesEl = $('#sales-dropdown-' + leadId);
        var fuEl = $('#tc-followup-group-' + leadId);
        var svEl = $('#tc-sitevisit-group-' + leadId);
        var svPickupEl = $('#tc-sitevisit-pickup-' + leadId);
        var svFeedEl = $('#tc-sitevisit-feedback-' + leadId);
        var unitEl = $('#tc-unit-group-' + leadId);
        var dvEl = $('#tc-deal-value-' + leadId);
        var bkEl = $('#tc-booking-group-' + leadId);
        var drEl = $('#tc-dead-reason-' + leadId);

        var isSales = (status === 'Assigned to Sales');
        var isFollowUp = (status === 'Follow Up' || status === 'Site Visit Done');
        var isSiteVisit = (status === 'Site Visit Scheduled' || status === 'Site Visit Done');
        var isSVSched = (status === 'Site Visit Scheduled');
        var isSVDone = (status === 'Site Visit Done');
        var isNego = (status === 'Negotiation' || status === 'Converted' || status === 'Site Visit Done');
        var isConverted = (status === 'Converted');
        var isDead = (status === 'Dead');

        if (salesEl.length) {
            salesEl.find('select').attr('required', isSales);
            if (!isSales) salesEl.find('select').val('');
            if (animate) salesEl.stop(true, true)[isSales ? 'slideDown' : 'slideUp'](200);
            else salesEl.toggle(isSales);
        }

        if (fuEl.length) {
            if (animate) fuEl.stop(true, true)[isFollowUp ? 'slideDown' : 'slideUp'](200);
            else fuEl.toggle(isFollowUp);
        }

        if (svEl.length) {
            if (animate) svEl.stop(true, true)[isSiteVisit ? 'slideDown' : 'slideUp'](200);
            else svEl.toggle(isSiteVisit);
        }

        if (svPickupEl.length) {
            if (animate) svPickupEl.stop(true, true)[isSVSched ? 'slideDown' : 'slideUp'](200);
            else svPickupEl.toggle(isSVSched);
        }

        if (svFeedEl.length) {
            if (animate) svFeedEl.stop(true, true)[isSVDone ? 'slideDown' : 'slideUp'](200);
            else svFeedEl.toggle(isSVDone);
        }

        if (unitEl.length) {
            if (animate) unitEl.stop(true, true)[isNego ? 'slideDown' : 'slideUp'](200);
            else unitEl.toggle(isNego);
        }

        if (dvEl.length) {
            if (animate) dvEl.stop(true, true)[isNego ? 'slideDown' : 'slideUp'](200);
            else dvEl.toggle(isNego);
        }

        if (bkEl.length) {
            if (animate) bkEl.stop(true, true)[isConverted ? 'slideDown' : 'slideUp'](200);
            else bkEl.toggle(isConverted);
        }

        if (drEl.length) {
            drEl.find('select').attr('required', isDead);
            if (!isDead) drEl.find('select').val('');
            if (animate) drEl.stop(true, true)[isDead ? 'slideDown' : 'slideUp'](200);
            else drEl.toggle(isDead);
        }
    }

    // Toast Notification System
    function showCrmToast(message, type) {
        type = type || 'success';
        var container = $('#crmToastContainer');
        if (!container.length) {
            container = $('<div id="crmToastContainer"></div>').appendTo('body');
        }
        var icon = (type === 'success') ? 'fa-circle-check' : (type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-info');
        var toast = $('<div class="crm-toast crm-toast-' + type + '"><i class="fa-solid ' + icon + ' fa-lg"></i> <span>' + message + '</span></div>');
        container.append(toast);

        setTimeout(function () {
            toast.css({ opacity: 0, transform: 'translateY(-10px)' });
            setTimeout(function () { toast.remove(); }, 300);
        }, 4000);
    }

    // Status Color Map
    var leadStatusColorMap = {
        'INT': '#16a34a',
        'Interested': '#16a34a',
        'NI': '#dc2626',
        'Not Interested': '#dc2626',
        'New': '#0284c7',
        'In Progress': '#0891b2',
        'Follow Up': '#d97706',
        'OTH Call Back': '#ea580c',
        'OTH_CALL_BACK': '#ea580c',
        'Call Disconnect': '#e11d48',
        'Call Disconnected': '#e11d48',
        'OTH_BUSY': '#4f46e5',
        'Busy / Callback': '#4f46e5',
        'OTH_NOT_PICK': '#7c3aed',
        'RNR (No Response)': '#7c3aed',
        'OTH_SWITCH_OFF': '#64748b',
        'Switched Off': '#64748b',
        'Incoming Off': '#78716c',
        'Call Forwarding': '#0891b2',
        'OS': '#475569',
        'Out of Service': '#475569',
        'OTH_NETWORK': '#0d9488',
        'OTH': '#6b7280',
        'Invalid Number': '#9f1239',
        'Wrong Number': '#9f1239',
        'Site Visit Scheduled': '#9333ea',
        'Site Visit Done': '#059669',
        'Re-Visit Scheduled': '#a855f7',
        'Negotiation': '#c2410c',
        'Assigned to Sales': '#2563eb',
        'Forward to Sales': '#2563eb',
        'Booked / Token Paid': '#15803d',
        'Booked / Token': '#15803d',
        'Converted': '#14532d',
        'Dead': '#1e293b',
        'DND': '#0f172a'
    };

    // Update Dropdown Data Attribute & Color
    function updateDropdownColor(selectEl, newStatus) {
        if (!newStatus) newStatus = selectEl.val();
        selectEl.attr('data-current-status', newStatus);
        selectEl.data('current-status', newStatus);
        if (selectEl.length && selectEl[0]) {
            var color = leadStatusColorMap[newStatus] || '#0284c7';
            selectEl[0].style.setProperty('background-color', color, 'important');
            selectEl[0].style.setProperty('border-color', color, 'important');
            selectEl[0].style.setProperty('color', '#ffffff', 'important');
            selectEl[0].style.setProperty('font-weight', '700', 'important');
        }
    }

    // Initialize all table-inline-status on page load
    $('.table-inline-status').each(function () {
        var s = $(this).val() || $(this).attr('data-current-status');
        if (s) updateDropdownColor($(this), s);
    });

    // Quick Date Dropdown Auto-Populate Date Range Inputs
    $(document).on('change', '.quick-date-select, #quickDateFilter', function () {
        var val = $(this).val();
        var form = $(this).closest('form');
        var fromInput = form.find('input[name="from_date"]');
        var toInput = form.find('input[name="to_date"]');

        if (!fromInput.length || !toInput.length) return;

        var now = new Date();
        var formatDate = function(d) {
            var year = d.getFullYear();
            var month = String(d.getMonth() + 1).padStart(2, '0');
            var day = String(d.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        };

        if (val === 'today') {
            var todayStr = formatDate(now);
            fromInput.val(todayStr);
            toInput.val(todayStr);
        } else if (val === 'yesterday') {
            var yest = new Date();
            yest.setDate(yest.getDate() - 1);
            var yestStr = formatDate(yest);
            fromInput.val(yestStr);
            toInput.val(yestStr);
        } else if (val === 'last7') {
            var past7 = new Date();
            past7.setDate(past7.getDate() - 7);
            fromInput.val(formatDate(past7));
            toInput.val(formatDate(now));
        } else if (val === 'this_month') {
            var firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
            var lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0);
            fromInput.val(formatDate(firstDay));
            toInput.val(formatDate(lastDay));
        } else if (val === 'last_month') {
            var firstDayLastMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            var lastDayLastMonth = new Date(now.getFullYear(), now.getMonth(), 0);
            fromInput.val(formatDate(firstDayLastMonth));
            toInput.val(formatDate(lastDayLastMonth));
        } else if (val === '') {
            fromInput.val('');
            toInput.val('');
        }
    });

    // Direct Inline Lead Status Dropdown Change Handler (Zero Popups - Instant AJAX Save)
    $(document).on('change', '.table-inline-status', function () {
        var selectEl = $(this);
        var newStatus = selectEl.val();
        var prevStatus = selectEl.attr('data-current-status') || selectEl.data('current-status') || '';
        var leadId = selectEl.data('lead-id');

        selectEl.prop('disabled', true);
        $.post('api/update_lead_status.php', { lead_id: leadId, status: newStatus }, function (res) {
            selectEl.prop('disabled', false);
            if (res && res.status === 'success') {
                updateDropdownColor(selectEl, newStatus);
                selectEl.data('current-status', newStatus);
                selectEl.attr('data-current-status', newStatus);

                // Update Status Cell badge if present in the same table row
                var row = selectEl.closest('tr');
                var statusCell = row.find('.status-cell');
                if (statusCell.length) {
                    var badge = statusCell.find('.status-badge-val');
                    if (badge.length) {
                        badge.attr('class', 'badge status-badge-val ' + res.badge_class);
                        badge.html('<i class="fa-solid ' + res.badge_icon + ' me-1"></i>' + res.new_status);
                    }
                }

                showCrmToast(res.message || 'Status updated to [' + newStatus + ']!', 'success');
            } else {
                selectEl.val(prevStatus);
                updateDropdownColor(selectEl, prevStatus);
                showCrmToast((res && res.message) ? res.message : 'Error updating status', 'error');
            }
        }).fail(function () {
            selectEl.prop('disabled', false);
            selectEl.val(prevStatus);
            updateDropdownColor(selectEl, prevStatus);
            showCrmToast('Failed to update status. Please try again.', 'error');
        });
    });

    // Direct Inline Lead Priority Change Handler
    $(document).on('change', '.table-inline-priority', function () {
        var selectEl = $(this);
        var priority = selectEl.val();
        var leadId = selectEl.data('lead-id');

        selectEl.prop('disabled', true);
        $.post('api/update_lead_status.php', { lead_id: leadId, priority: priority }, function (res) {
            selectEl.prop('disabled', false);
            if (res && res.status === 'success') {
                selectEl.attr('data-priority', priority);
                selectEl.data('priority', priority);
                showCrmToast('Priority updated to [' + priority + ']!', 'success');
            } else {
                showCrmToast((res && res.message) ? res.message : 'Error updating priority', 'error');
            }
        }).fail(function () {
            selectEl.prop('disabled', false);
            showCrmToast('Failed to update priority.', 'error');
        });
    });

    // Direct Inline Follow-Up / Visit Date Change Handler
    $(document).on('change', '.table-inline-date', function () {
        var inputEl = $(this);
        var dateVal = inputEl.val();
        var leadId = inputEl.data('lead-id');

        inputEl.prop('disabled', true);
        $.post('api/update_lead_status.php', { lead_id: leadId, followup_date: dateVal, follow_up_at: dateVal }, function (res) {
            inputEl.prop('disabled', false);
            if (res && res.status === 'success') {
                showCrmToast('Follow-up schedule updated!', 'success');
            } else {
                showCrmToast((res && res.message) ? res.message : 'Error updating schedule', 'error');
            }
        }).fail(function () {
            inputEl.prop('disabled', false);
            showCrmToast('Failed to update schedule.', 'error');
        });
    });

    // Unified Excel Spreadsheet Cell Auto-Save Handler (.excel-cell-input, .excel-cell-select, .excel-cell-date)
    function saveExcelCell(cellEl) {
        var leadId = cellEl.data('lead-id');
        var field = cellEl.data('field');
        var val = cellEl.val();
        var initialVal = cellEl.data('initial-val');

        if (!leadId || !field) return;
        if (typeof initialVal !== 'undefined' && initialVal === val) {
            return; // No change
        }

        var tdCell = cellEl.closest('td');
        tdCell.addClass('cell-saving-glow');

        $.post('api/update_lead_cell.php', {
            lead_id: leadId,
            field_name: field,
            field_value: val
        }, function (res) {
            tdCell.removeClass('cell-saving-glow');
            if (res && res.status === 'success') {
                cellEl.data('initial-val', val);
                cellEl.attr('title', val);
                tdCell.addClass('cell-saved-glow');
                setTimeout(function () { tdCell.removeClass('cell-saved-glow'); }, 1200);
                showCrmToast(res.message || 'Saved successfully!', 'success');
            } else {
                showCrmToast((res && res.message) ? res.message : 'Error saving changes', 'error');
            }
        }).fail(function () {
            tdCell.removeClass('cell-saving-glow');
            showCrmToast('Failed to save cell update.', 'error');
        });
    }

    // Store initial value on focus to prevent redundant AJAX requests
    $(document).on('focusin', '.excel-cell-input, .excel-cell-select, .excel-cell-date', function () {
        $(this).data('initial-val', $(this).val());
    });

    // Bind change for selects and date inputs
    $(document).on('change', '.excel-cell-select, .excel-cell-date', function () {
        saveExcelCell($(this));
    });

    // Bind blur and Enter key for text inputs
    $(document).on('blur', '.excel-cell-input', function () {
        saveExcelCell($(this));
    });

    $(document).on('keypress', '.excel-cell-input', function (e) {
        if ($(this).is('textarea')) {
            if (e.which === 13 && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                $(this).blur();
            }
            return;
        }
        if (e.which === 13) {
            e.preventDefault();
            $(this).blur();
        }
    });

    // Direct Inline Quick Note Save Handler (on Enter or Save button)
    $(document).on('keypress', '.table-inline-note', function (e) {
        if (e.which === 13) { // Enter key
            e.preventDefault();
            var inputEl = $(this);
            var noteVal = inputEl.val().trim();
            var leadId = inputEl.data('lead-id');
            if (!noteVal || !leadId) return;

            inputEl.prop('disabled', true);
            $.post('api/add_note.php', { lead_id: leadId, note: noteVal }, function (res) {
                inputEl.prop('disabled', false);
                if (res && res.status === 'success') {
                    inputEl.val('');
                    inputEl.attr('placeholder', 'Saved: ' + noteVal.substring(0, 18) + '...');
                    showCrmToast('Note logged to lead history!', 'success');
                } else {
                    showCrmToast((res && res.message) ? res.message : 'Error adding note', 'error');
                }
            }).fail(function () {
                inputEl.prop('disabled', false);
                showCrmToast('Failed to save note.', 'error');
            });
        }
    });

    // Mark saved flag on modal submit
    $(document).on('submit', '.modal form', function () {
        var modal = $(this).closest('.modal');
        var leadId = $(this).find('input[name="lead_id"]').val();
        if (leadId) {
            var inlineSelect = $('.table-inline-status[data-lead-id="' + leadId + '"]');
            if (inlineSelect.length) {
                inlineSelect.data('saved-new-status', true);
            }
        }
    });

    // On status dropdown change in modal
    $(document).on('change', '.status-selector', function () {
        var status = $(this).val();
        var leadId = $(this).data('lead-id');
        updateModalFields(status, leadId, true);
    });

    // On modal open, sync fields instantly
    $(document).on('show.bs.modal shown.bs.modal', '.modal', function () {
        var statusSel = $(this).find('.status-selector');
        if (statusSel.length) {
            var status = statusSel.val();
            var leadId = statusSel.data('lead-id');
            updateModalFields(status, leadId, false);
        }
    });

    // Auto-hide success/error messages after 5 seconds
    setTimeout(function () {
        $('.alert').fadeOut('slow');
    }, 5000);

    function loadLeadNotesHistory(leadId) {
        $('#historyLoader').show();
        $('#historyContent').hide().empty();
        $('#quickNoteLeadId').val(leadId);

        $.get('api/get_notes.php', { lead_id: leadId }, function (data) {
            var notes = [];
            try {
                notes = typeof data === 'object' ? data : JSON.parse(data);
            } catch (e) {
                notes = [];
            }
            var html = '';

            if (notes.length === 0) {
                html = '<div class="text-center text-muted py-4"><i class="fa-regular fa-comments fa-2x mb-2 opacity-50 d-block"></i>No history/notes found for this lead. Type a note below to start the discussion!</div>';
            } else {
                html = '<div class="list-group list-group-flush">';
                notes.forEach(function (note) {
                    var date = new Date(note.created_at).toLocaleString();
                    var roleBadge = '';
                    if (note.role === 'admin') {
                        roleBadge = '<span class="badge bg-danger ms-1" style="font-size: 10px;"><i class="fa-solid fa-crown me-1 text-warning"></i>Admin Instruction</span>';
                    } else if (note.role === 'telecaller') {
                        roleBadge = '<span class="badge ms-1" style="font-size: 10px; background-color: #7c3aed; color: #fff;"><i class="fa-solid fa-headset me-1"></i>Telecaller</span>';
                    } else {
                        roleBadge = '<span class="badge bg-success ms-1" style="font-size: 10px;"><i class="fa-solid fa-briefcase me-1"></i>Sales</span>';
                    }

                    html += '<div class="list-group-item px-0 py-3 border-bottom">';
                    html += '<div class="d-flex justify-content-between mb-1">';
                    html += '<small class="fw-semibold text-dark">' + (note.user_name || 'User') + roleBadge + '</small>';
                    html += '<small class="text-muted" style="font-size: 11px;">' + date + '</small>';
                    html += '</div>';
                    if (note.is_html) {
                        html += '<div class="mb-0 text-dark small">' + note.note + '</div>';
                    } else {
                        html += '<p class="mb-0 text-dark small" style="white-space: pre-wrap;">' + note.note + '</p>';
                    }
                    html += '</div>';
                });
                html += '</div>';
            }

            $('#historyLoader').hide();
            $('#historyContent').html(html).fadeIn();
        });
    }

    // View History Modal Logic
    $(document).on('click', '.view-history-btn', function () {
        var leadId = $(this).data('lead-id');
        var historyModalEl = document.getElementById('historyModal');
        if (historyModalEl) {
            var modal = bootstrap.Modal.getInstance(historyModalEl) || new bootstrap.Modal(historyModalEl);
            modal.show();
            loadLeadNotesHistory(leadId);
        }
    });

    // Instant Reply / Add Note Form Handler inside History Modal
    $(document).on('submit', '#quickNoteReplyForm', function (e) {
        e.preventDefault();
        var leadId = $('#quickNoteLeadId').val();
        var noteText = $('#quickNoteText').val().trim();
        if (!leadId || !noteText) return;

        var submitBtn = $(this).find('button[type="submit"]');
        submitBtn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i>Sending...');

        $.post('api/add_note.php', { lead_id: leadId, note: noteText }, function (res) {
            submitBtn.prop('disabled', false).html('<i class="fa-solid fa-paper-plane me-1"></i>Send');
            if (res && res.status === 'success') {
                $('#quickNoteText').val('');
                loadLeadNotesHistory(leadId);
            } else {
                alert(res.message || 'Error adding note');
            }
        }).fail(function() {
            submitBtn.prop('disabled', false).html('<i class="fa-solid fa-paper-plane me-1"></i>Send');
            alert('Failed to send note. Please try again.');
        });
    });

    // Show/hide deal value or lost reason in close deal modal
    $(document).on('change', '.outcome-selector', function () {
        var status = $(this).val();
        var leadId = $(this).data('lead-id');
        var valueGroup = $('#deal-value-group-' + leadId);
        var reasonGroup = $('#dead-reason-group-' + leadId);

        if (status === 'Converted') {
            valueGroup.slideDown(200);
            valueGroup.find('input').attr('required', true);
            reasonGroup.slideUp(200);
            reasonGroup.find('select').attr('required', false).val('');
        } else if (status === 'Dead') {
            valueGroup.slideUp(200);
            valueGroup.find('input').attr('required', false).val('');
            reasonGroup.slideDown(200);
            reasonGroup.find('select').attr('required', true);
        }
    });

    // Robust Sidebar Toggle Handler for Mobile & Desktop (jQuery fallback)
    $(document).on('click touchstart', '#sidebarToggle, #sidebarToggle *', function (e) {
        if (e.type === 'touchstart') {
            $(this).data('touched', true);
        } else if (e.type === 'click' && $(this).data('touched')) {
            $(this).data('touched', false);
            return;
        }
        e.preventDefault();

        var isMobile = (window.innerWidth <= 992 || $(window).width() <= 992 || (window.matchMedia && window.matchMedia('(max-width: 992px)').matches));

        if (isMobile) {
            $('#sidebar').toggleClass('sidebar-open');
            $('body').toggleClass('sidebar-open');
            $('#sidebarOverlay, .sidebar-overlay').toggleClass('active');
        } else {
            $('body').toggleClass('sidebar-mini');
        }
    });

    // Overlay click dismisses mobile sidebar
    // Overlay Click Dismisses Sidebar
    $(document).on('click touchstart', '#sidebarOverlay, .sidebar-overlay', function (e) {
        e.preventDefault();
        $('#sidebar').removeClass('sidebar-open active');
        $('body').removeClass('sidebar-open');
        $('#sidebarOverlay, .sidebar-overlay').removeClass('active');
    });

    // Mobile nav link click dismisses sidebar
    $(document).on('click', '#sidebar a', function () {
        var isMobile = (window.innerWidth <= 992 || $(window).width() <= 992 || (window.matchMedia && window.matchMedia('(max-width: 992px)').matches));
        if (isMobile) {
            $('#sidebar').removeClass('sidebar-open active');
            $('body').removeClass('sidebar-open');
            $('#sidebarOverlay, .sidebar-overlay').removeClass('active');
        }
    });
});

// =========================================================================
// UNIVERSAL GLOBAL TOGGLE HANDLERS (SIDEBAR, PASSWORDS, CHECKBOXES, STATUS)
// =========================================================================

// 1. Rock-Solid Global Sidebar Toggle (Delegated - Works on ALL pages & button IDs)
(function() {
    function ensureOverlay() {
        if (!document.getElementById('sidebarOverlay') && !document.querySelector('.sidebar-overlay')) {
            var ov = document.createElement('div');
            ov.id = 'sidebarOverlay';
            ov.className = 'sidebar-overlay';
            document.body.appendChild(ov);
        }
    }

    function handleSidebarToggle(e) {
        var toggleBtn = e.target.closest('#sidebarToggle, #sidebarCollapse, .sidebar-toggle, [data-toggle="sidebar"], [data-bs-toggle="sidebar"], .btn-toggle-sidebar');
        if (!toggleBtn) return;

        e.preventDefault();
        e.stopPropagation();

        ensureOverlay();

        var sidebar = document.getElementById('sidebar');
        var overlay = document.getElementById('sidebarOverlay') || document.querySelector('.sidebar-overlay');
        var isMobile = (window.innerWidth <= 992 || (window.matchMedia && window.matchMedia('(max-width: 992px)').matches));

        if (isMobile) {
            document.body.classList.toggle('sidebar-open');
            if (sidebar) {
                sidebar.classList.toggle('sidebar-open');
                sidebar.classList.toggle('active');
            }
            if (overlay) overlay.classList.toggle('active');
        } else {
            document.body.classList.toggle('sidebar-mini');
            if (sidebar) sidebar.classList.toggle('active');
            try {
                localStorage.setItem('crm_sidebar_mini', document.body.classList.contains('sidebar-mini') ? '1' : '0');
            } catch(ex){}
        }
    }

    function handleGlobalDismiss(e) {
        var isOverlay = e.target.closest('#sidebarOverlay, .sidebar-overlay');
        var isNavLink = e.target.closest('#sidebar a');
        var isMobile = (window.innerWidth <= 992 || (window.matchMedia && window.matchMedia('(max-width: 992px)').matches));

        if ((isOverlay || isNavLink) && isMobile) {
            var sidebar = document.getElementById('sidebar');
            var overlay = document.getElementById('sidebarOverlay') || document.querySelector('.sidebar-overlay');
            if (sidebar) {
                sidebar.classList.remove('sidebar-open');
                sidebar.classList.remove('active');
            }
            document.body.classList.remove('sidebar-open');
            if (overlay) overlay.classList.remove('active');
        }
    }

    function restoreSidebarPreference() {
        ensureOverlay();
        var isMobile = (window.innerWidth <= 992 || (window.matchMedia && window.matchMedia('(max-width: 992px)').matches));
        if (isMobile) {
            document.body.classList.remove('sidebar-mini');
        } else {
            try {
                if (localStorage.getItem('crm_sidebar_mini') === '1') {
                    document.body.classList.add('sidebar-mini');
                }
            } catch(ex){}
        }
    }

    document.addEventListener('click', handleSidebarToggle);
    document.addEventListener('click', handleGlobalDismiss);
    window.addEventListener('resize', restoreSidebarPreference);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restoreSidebarPreference);
    } else {
        restoreSidebarPreference();
    }
})();

// 2. Global Password Toggle Functions
window.togglePassword = function(inputId, iconElement) {
    var input = document.getElementById(inputId);
    if (!input) return;
    var icon = null;
    if (iconElement) {
        icon = (iconElement.tagName === 'I') ? iconElement : iconElement.querySelector('i');
    }
    if (input.type === 'password') {
        input.type = 'text';
        if (icon) {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        }
    } else {
        input.type = 'password';
        if (icon) {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
};

window.togglePasswordVisibility = function(inputId, btn) {
    window.togglePassword(inputId, btn);
};

window.toggleRowPassword = function(userId) {
    var mask = document.getElementById('pass_mask_' + userId);
    var real = document.getElementById('pass_real_' + userId);
    var icon = document.getElementById('pass_icon_' + userId);
    if (!mask || !real) return;
    if (real.classList.contains('d-none')) {
        mask.classList.add('d-none');
        real.classList.remove('d-none');
        if (icon) {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        }
    } else {
        real.classList.add('d-none');
        mask.classList.remove('d-none');
        if (icon) {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
};

// 3. Global Select-All Checkboxes Toggle
window.toggleAllCheckboxes = function(className, isChecked) {
    document.querySelectorAll('.' + className).forEach(function(cb) {
        cb.checked = isChecked;
        if (typeof $(cb).trigger === 'function') $(cb).trigger('change');
    });
};

// 4. Global Campaign Switch Toggle
window.toggleCampaign = function(campaignId, checkbox) {
    if (!campaignId) return;
    var isChecked = checkbox ? checkbox.checked : false;
    $.ajax({
        url: 'campaigns.php',
        type: 'POST',
        data: {
            'action': 'ajax_toggle_campaign',
            'campaign_id': campaignId
        },
        dataType: 'json',
        success: function(res) {
            if (res && res.success) {
                if (typeof showCrmToast === 'function') {
                    showCrmToast(res.message || 'Campaign status updated!', 'success');
                }
                var label = document.getElementById('status_label_' + campaignId);
                if (label) {
                    label.innerHTML = res.new_status === 'Active' 
                        ? '<span class="badge bg-success-subtle text-success border border-success px-2 py-1"><i class="fa-solid fa-circle me-1" style="font-size: 7px;"></i>Active</span>'
                        : '<span class="badge bg-secondary-subtle text-muted border px-2 py-1"><i class="fa-solid fa-pause me-1" style="font-size: 7px;"></i>Paused</span>';
                }
            } else {
                if (checkbox) checkbox.checked = !isChecked;
                if (typeof showCrmToast === 'function') {
                    showCrmToast((res && res.message) ? res.message : 'Error updating campaign status', 'error');
                } else {
                    alert((res && res.message) ? res.message : 'Error updating campaign status');
                }
            }
        },
        error: function() {
            if (checkbox) checkbox.checked = !isChecked;
            alert('Network error while updating campaign status.');
        }
    });
};

// 5. Telecaller Live Activity Heartbeat Engine (Sends pulse every 60s while CRM is active)
(function() {
    function sendTelecallerHeartbeat() {
        if (!navigator.onLine) return;
        $.ajax({
            url: 'api/telecaller_heartbeat.php',
            type: 'POST',
            dataType: 'json',
            success: function(res) {
                // Pulse recorded successfully
            },
            error: function() {}
        });
    }

    $(document).ready(function() {
        // Send initial pulse 3 seconds after page load
        setTimeout(sendTelecallerHeartbeat, 3000);
        // Then recurring pulse every 60 seconds
        setInterval(sendTelecallerHeartbeat, 60000);
    });
})();

// 6. Inline Customer Name Edit Handler (Telecaller & Admin)
$(document).on('click', '.btn-edit-name', function (e) {
    e.preventDefault();
    var wrapper = $(this).closest('.customer-name-wrapper');
    wrapper.find('.customer-name-text, .btn-edit-name').addClass('d-none');
    var editBox = wrapper.find('.customer-name-edit-box');
    editBox.removeClass('d-none').addClass('d-inline-flex');
    var input = editBox.find('.customer-name-input');
    input.focus().select();
});

$(document).on('click', '.btn-cancel-name', function (e) {
    e.preventDefault();
    var wrapper = $(this).closest('.customer-name-wrapper');
    var originalName = wrapper.find('.customer-name-text').text();
    wrapper.find('.customer-name-input').val(originalName);
    wrapper.find('.customer-name-edit-box').removeClass('d-inline-flex').addClass('d-none');
    wrapper.find('.customer-name-text, .btn-edit-name').removeClass('d-none');
});

function saveCustomerName(wrapper) {
    var leadId = wrapper.data('lead-id');
    var input = wrapper.find('.customer-name-input');
    var newName = input.val().trim();
    var oldName = wrapper.find('.customer-name-text').text();

    if (!leadId || !newName) {
        if (typeof showCrmToast === 'function') showCrmToast('Name cannot be empty', 'error');
        else alert('Name cannot be empty');
        return;
    }

    if (newName === oldName) {
        wrapper.find('.customer-name-edit-box').removeClass('d-inline-flex').addClass('d-none');
        wrapper.find('.customer-name-text, .btn-edit-name').removeClass('d-none');
        return;
    }

    input.prop('disabled', true);
    $.post('api/update_lead_cell.php', {
        lead_id: leadId,
        field_name: 'name',
        field_value: newName
    }, function (res) {
        input.prop('disabled', false);
        if (res && res.status === 'success') {
            wrapper.find('.customer-name-text').text(newName);
            wrapper.find('.customer-name-edit-box').removeClass('d-inline-flex').addClass('d-none');
            wrapper.find('.customer-name-text, .btn-edit-name').removeClass('d-none');
            
            // Also update WhatsApp greeting link if present in row
            var row = wrapper.closest('tr');
            var waLink = row.find('a[href*="whatsapp.com"]');
            if (waLink.length) {
                var currentHref = waLink.attr('href');
                waLink.attr('href', currentHref.replace(/text=Hello%20[^,]+,/, 'text=Hello%20' + encodeURIComponent(newName) + ','));
            }

            if (typeof showCrmToast === 'function') {
                showCrmToast('Customer name updated successfully!', 'success');
            }
        } else {
            var msg = (res && res.message) ? res.message : 'Error updating name';
            if (typeof showCrmToast === 'function') showCrmToast(msg, 'error');
            else alert(msg);
        }
    }).fail(function () {
        input.prop('disabled', false);
        if (typeof showCrmToast === 'function') showCrmToast('Server error while updating name', 'error');
        else alert('Server error while updating name');
    });
}

$(document).on('click', '.btn-save-name', function (e) {
    e.preventDefault();
    saveCustomerName($(this).closest('.customer-name-wrapper'));
});

$(document).on('keypress', '.customer-name-input', function (e) {
    if (e.which === 13) {
        e.preventDefault();
        saveCustomerName($(this).closest('.customer-name-wrapper'));
    }
});


