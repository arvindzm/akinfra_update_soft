<?php
/**
 * Dynamic OS (Other Site / Out of Station) Selection & Management Modal
 * Clean dynamic list (4 to 5 starter sites), fully manageable by Admin
 */
$os_dynamic_locations = function_exists('getOsLocationsList') ? getOsLocationsList($pdo ?? null) : [
    'Kanpur Road',
    'Raebareli Road',
    'Sultanpur Road',
    'Faizabad Road',
    'Sitapur Road',
    'Other Site'
];
$user_role_for_os = isset($user['role']) ? $user['role'] : '';
$can_manage_os = in_array($user_role_for_os, ['admin', 'super_admin', 'telecaller', 'sales_manager']);
?>

<!-- Dynamic OS Site Selection & Management Modal -->
<div class="modal fade" id="osDirectoryModal" tabindex="-1" aria-labelledby="osDirectoryModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%); padding: 16px 20px;">
                <div class="d-flex align-items-center">
                    <div class="me-3 d-flex align-items-center justify-content-center bg-white bg-opacity-20 rounded-circle" style="width: 42px; height: 42px;">
                        <i class="fa-solid fa-map-location-dot fs-5 text-white"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="modal-title fw-bold mb-0 text-white" id="osDirectoryModalLabel">Select OS Site Location</h5>
                        </div>
                        <div class="small text-white text-opacity-85 mt-0.5" id="osModalLeadInfo" style="font-size: 12px;">
                            Choose or add OS site location for this lead
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" id="osModalCloseBtn"></button>
            </div>
            
            <div class="modal-body p-4" style="background-color: #f8fafc;">
                <!-- Current Selected Banner -->
                <div id="osCurrentSelectionBanner" class="alert alert-primary py-2 px-3 mb-3 d-flex align-items-center justify-content-between" style="display:none; border-radius: 10px; font-size: 13px;">
                    <div>
                        <i class="fa-solid fa-bookmark me-1.5 text-primary"></i> Current Choice: <strong id="osCurrentSelectionText">-</strong>
                    </div>
                    <span class="badge bg-primary text-white">Active</span>
                </div>

                <!-- Add New Site (Dynamic Admin Feature) -->
                <div class="mb-3 p-3 bg-white border rounded-3 shadow-sm">
                    <label class="form-label fw-bold text-dark small mb-1.5 d-flex align-items-center justify-content-between">
                        <span><i class="fa-solid fa-plus-circle text-primary me-1.5"></i>Add New OS Site (Dynamic)</span>
                        <span class="text-muted fw-normal" style="font-size: 11px;">Admin can add sites anytime</span>
                    </label>
                    <div class="input-group">
                        <input type="text" id="newOsLocationInlineInput" class="form-control" placeholder="e.g. Ayodhya Road, Hardoi Road, Varanasi..." maxlength="100" autocomplete="off">
                        <button class="btn btn-primary px-3 fw-bold d-flex align-items-center gap-1.5" type="button" id="btnSaveNewOsLocation">
                            <i class="fa-solid fa-plus"></i>
                            <span>Add & Select</span>
                        </button>
                    </div>
                    <div id="osAddFeedback" class="small mt-1.5 d-none"></div>
                </div>

                <!-- List of Dynamic OS Locations -->
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <label class="form-label fw-bold text-secondary text-uppercase mb-0" style="font-size: 11px; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-list-check me-1"></i> Available OS Sites (<span id="osTotalCountBadge"><?php echo count($os_dynamic_locations); ?></span>)
                    </label>
                    <span class="text-muted small" style="font-size: 11px;">Click to select</span>
                </div>

                <div class="os-options-container shadow-sm border rounded-3 bg-white p-2" style="max-height: 280px; overflow-y: auto;">
                    <div id="osOptionsList" class="d-flex flex-column gap-1.5">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>

                <div class="mt-3 p-2.5 rounded bg-info-subtle border border-info-subtle small text-dark d-flex align-items-start gap-2" style="font-size: 12px;">
                    <i class="fa-solid fa-circle-info text-info mt-0.5"></i>
                    <div>
                        <strong>Dynamic Sync:</strong> Kisi bhi option par click karte hi lead ka status <strong>OS</strong> save ho jayega aur location auto-save ho jayegi. Admin naye sites kabhi bhi add kar sakta hai.
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light px-4 py-2.5 d-flex align-items-center justify-content-between border-top">
                <span class="text-muted small" style="font-size: 11.5px;">Press <kbd class="bg-white border">Esc</kbd> to close</span>
                <button type="button" class="btn btn-secondary px-3 py-1.5 fw-semibold rounded-pill" data-bs-dismiss="modal" id="osModalCancelBtn">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.os-site-btn {
    padding: 10px 14px;
    border-radius: 10px;
    border: 1.5px solid #e2e8f0;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    display: flex;
    align-items: center;
    justify-content: space-between;
    text-align: left;
    width: 100%;
}
.os-site-btn:hover {
    background-color: #eff6ff !important;
    border-color: #3b82f6 !important;
    transform: translateX(2px);
}
.os-site-btn.is-selected {
    background-color: #eff6ff !important;
    border-color: #2563eb !important;
    box-shadow: 0 0 0 1px #2563eb;
}
.os-site-btn .os-site-label {
    font-size: 14px;
    color: #1e293b;
    font-weight: 600;
}
.os-site-btn.is-selected .os-site-label {
    color: #1d4ed8;
    font-weight: 700;
}
.os-subreason-badge {
    line-height: 1.2;
}
.os-subreason-badge .badge {
    transition: all 0.15s ease-in-out;
}
.os-subreason-badge .badge:hover {
    background-color: #2563eb !important;
    color: #ffffff !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(37,99,235,0.2);
}
</style>

<script>
window.CRM_OS_LOCATIONS = <?php echo json_encode(array_values($os_dynamic_locations), JSON_UNESCAPED_UNICODE); ?>;

(function($) {
    var activeLeadId = null;
    var activeSelectEl = null;
    var previousStatus = '';
    var activeCurrentVal = '';
    var defaultSites = ['Kanpur Road', 'Raebareli Road', 'Sultanpur Road', 'Faizabad Road', 'Sitapur Road', 'Other Site'];

    // Helper: Escape HTML
    function escapeHtml(str) {
        return $('<div>').text(str || '').html();
    }

    // Refresh OS locations from server
    function reloadOsLocationsList(callback) {
        $.get('api/manage_os_locations.php', { action: 'list' }, function(res) {
            if (res && res.status === 'success' && Array.isArray(res.locations)) {
                window.CRM_OS_LOCATIONS = res.locations;
                renderOsSitesList();
                if (typeof callback === 'function') callback(res.locations);
            }
        });
    }

    // Render list of sites in modal
    function renderOsSitesList() {
        var container = $('#osOptionsList');
        container.empty();

        var sites = window.CRM_OS_LOCATIONS || defaultSites;
        $('#osTotalCountBadge').text(sites.length);

        if (sites.length === 0) {
            container.html('<div class="text-center py-3 text-muted small">No OS sites found. Add one above!</div>');
            return;
        }

        var html = '';
        sites.forEach(function(site) {
            var isSelected = (activeCurrentVal && activeCurrentVal.toLowerCase() === site.toLowerCase());
            var isDefault = defaultSites.some(function(d) { return d.toLowerCase() === site.toLowerCase(); });

            html += '<div class="os-site-btn ' + (isSelected ? 'is-selected' : '') + '" data-value="' + escapeHtml(site) + '">';
            html += '  <div class="d-flex align-items-center text-truncate me-2">';
            html += '    <i class="fa-solid fa-location-dot text-primary me-2.5 fs-6"></i>';
            html += '    <span class="os-site-label">' + escapeHtml(site) + '</span>';
            html += '  </div>';
            html += '  <div class="d-flex align-items-center gap-1.5">';
            if (isSelected) {
                html += '    <span class="badge bg-primary text-white px-2 py-1"><i class="fa-solid fa-check me-1"></i>Selected</span>';
            } else {
                html += '    <span class="badge bg-light text-muted border px-2 py-0.5 small select-hint">Select ↵</span>';
            }
            html += '    <button type="button" class="btn btn-xs btn-outline-danger p-1 btn-delete-os-location ms-1" data-location="' + escapeHtml(site) + '" title="Delete this OS site" style="line-height:1; border-radius:6px;"><i class="fa-solid fa-trash-can"></i></button>';
            html += '  </div>';
            html += '</div>';
        });

        container.html(html);
    }

    // Open OS Modal
    window.openOsDirectoryModal = function(leadId, selectEl, currentVal, prevSt, leadInfo) {
        activeLeadId = leadId;
        activeSelectEl = selectEl;
        previousStatus = prevSt || (selectEl ? (selectEl.attr('data-current-status') || selectEl.data('current-status') || '') : '');
        activeCurrentVal = currentVal || '';

        // Update modal info
        if (leadInfo) {
            $('#osModalLeadInfo').html(leadInfo);
        } else if (selectEl) {
            var row = selectEl.closest('tr');
            var name = row.find('td:nth-child(4)').text().trim() || row.find('.lead-name-cell').text().trim() || ('Lead #' + leadId);
            var phone = row.find('td:nth-child(3)').text().trim() || '';
            $('#osModalLeadInfo').html('<strong>Lead #' + leadId + '</strong> - ' + escapeHtml(name) + (phone ? ' (' + escapeHtml(phone) + ')' : ''));
        }

        // Current selection banner
        if (activeCurrentVal) {
            $('#osCurrentSelectionText').text(activeCurrentVal);
            $('#osCurrentSelectionBanner').show();
        } else {
            $('#osCurrentSelectionBanner').hide();
        }

        $('#newOsLocationInlineInput').val('');
        $('#osAddFeedback').addClass('d-none').text('');

        renderOsSitesList();

        var modalEl = document.getElementById('osDirectoryModal');
        var bsModal = bootstrap.Modal.getInstance(modalEl);
        if (!bsModal) {
            bsModal = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: true });
        }
        bsModal.show();

        setTimeout(function() {
            $('#newOsLocationInlineInput').focus();
        }, 350);
    };

    // Save Selected Option
    function selectAndSaveOsOption(chosenVal) {
        if (!activeLeadId || !chosenVal) return;

        var leadId = activeLeadId;
        var selectEl = activeSelectEl;

        if (selectEl) {
            selectEl.prop('disabled', true);
        }

        // Close modal
        var modalEl = document.getElementById('osDirectoryModal');
        var bsModal = bootstrap.Modal.getInstance(modalEl);
        if (bsModal) {
            bsModal.hide();
        }

        // AJAX Post to save status = 'OS' with os_sub_reason and site_location
        $.post('api/update_lead_status.php', {
            lead_id: leadId,
            status: 'OS',
            os_sub_reason: chosenVal,
            site_location: chosenVal
        }, function(res) {
            if (selectEl) {
                selectEl.prop('disabled', false);
            }

            if (res && res.status === 'success') {
                if (selectEl) {
                    selectEl.val('OS');
                    selectEl.attr('data-current-status', 'OS');
                    selectEl.data('current-status', 'OS');
                    if (typeof updateDropdownColor === 'function') {
                        updateDropdownColor(selectEl, 'OS');
                    } else {
                        selectEl.css({
                            'background-color': '#2563eb',
                            'color': '#ffffff',
                            'border-color': '#2563eb'
                        });
                    }

                    // Update or insert subreason badge in the table row
                    var parentTd = selectEl.closest('td');
                    var badgeContainer = parentTd.find('.os-subreason-badge');
                    var badgeHtml = '<div class="os-subreason-badge mt-1 text-center" data-lead-id="' + leadId + '">' +
                                    '  <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-0.5 os-select-trigger cursor-pointer" data-lead-id="' + leadId + '" style="font-size: 10.5px; font-weight: 600; cursor: pointer;" title="Click to change OS Site Location">' +
                                    '    <i class="fa-solid fa-map-pin me-1"></i><span>' + escapeHtml(chosenVal) + '</span> <i class="fa-solid fa-chevron-down ms-1" style="font-size: 8px;"></i>' +
                                    '  </span>' +
                                    '</div>';

                    if (badgeContainer.length) {
                        badgeContainer.replaceWith(badgeHtml);
                    } else {
                        parentTd.append(badgeHtml);
                    }

                    // Update SITE LOCATION cell in the same row if present
                    var row = selectEl.closest('tr');
                    var siteLocSelect = row.find('select[data-field="site_location"]');
                    if (siteLocSelect.length) {
                        var optExists = siteLocSelect.find('option').filter(function() { return $(this).val() === chosenVal; }).length > 0;
                        if (!optExists) {
                            siteLocSelect.append('<option value="' + escapeHtml(chosenVal) + '">' + escapeHtml(chosenVal) + '</option>');
                        }
                        siteLocSelect.val(chosenVal);
                    }
                }

                // Also sync dashboard input elements if present
                var tcInput = $('#tc_os_sub_reason_' + leadId);
                if (tcInput.length) {
                    tcInput.val(chosenVal);
                    $('#tc-os-reason-' + leadId).show();
                    $('.status-selector[data-lead-id="' + leadId + '"]').val('OS');
                }

                if (typeof showCrmToast === 'function') {
                    showCrmToast('Status saved as OS: [' + chosenVal + ']', 'success');
                }
            } else {
                if (selectEl && previousStatus) {
                    selectEl.val(previousStatus);
                    if (typeof updateDropdownColor === 'function') {
                        updateDropdownColor(selectEl, previousStatus);
                    }
                }
                if (typeof showCrmToast === 'function') {
                    showCrmToast((res && res.message) ? res.message : 'Error saving OS reason', 'error');
                }
            }
        }).fail(function(xhr) {
            if (selectEl) {
                selectEl.prop('disabled', false);
                if (previousStatus) {
                    selectEl.val(previousStatus);
                    if (typeof updateDropdownColor === 'function') {
                        updateDropdownColor(selectEl, previousStatus);
                    }
                }
            }
            if (typeof showCrmToast === 'function') {
                showCrmToast('Failed to save OS status. Please try again.', 'error');
            }
        });
    }

    // Click on site button
    $(document).on('click', '.os-site-btn', function(e) {
        if ($(e.target).closest('.btn-delete-os-location').length) {
            return; // let delete handler take care of delete button
        }
        var val = $(this).attr('data-value');
        selectAndSaveOsOption(val);
    });

    // Add New Location Action (Admin / Dynamic)
    function submitNewOsLocation() {
        var input = $('#newOsLocationInlineInput');
        var val = $.trim(input.val());
        var feedback = $('#osAddFeedback');

        if (!val) {
            feedback.removeClass('d-none text-success').addClass('text-danger').text('Please enter a site name.');
            input.focus();
            return;
        }

        var btn = $('#btnSaveNewOsLocation');
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Adding...');

        $.post('api/manage_os_locations.php', { action: 'add', name: val }, function(res) {
            btn.prop('disabled', false).html('<i class="fa-solid fa-plus"></i> Add & Select');
            if (res && res.status === 'success') {
                window.CRM_OS_LOCATIONS = res.locations || window.CRM_OS_LOCATIONS;
                input.val('');
                feedback.removeClass('d-none text-danger').addClass('text-success').text('Added successfully!');
                renderOsSitesList();
                // Immediately select this new site for the lead!
                selectAndSaveOsOption(res.location || val);
            } else {
                feedback.removeClass('d-none text-success').addClass('text-danger').text((res && res.message) ? res.message : 'Error adding location.');
            }
        }).fail(function() {
            btn.prop('disabled', false).html('<i class="fa-solid fa-plus"></i> Add & Select');
            feedback.removeClass('d-none text-success').addClass('text-danger').text('Network error adding location.');
        });
    }

    $(document).on('click', '#btnSaveNewOsLocation', function() {
        submitNewOsLocation();
    });

    $(document).on('keypress', '#newOsLocationInlineInput', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            submitNewOsLocation();
        }
    });

    // Delete custom location
    $(document).on('click', '.btn-delete-os-location', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var locName = $(this).data('location');
        if (!locName) return;

        if (!confirm('Are you sure you want to remove OS site "' + locName + '" from master list?')) {
            return;
        }

        $.post('api/manage_os_locations.php', { action: 'delete', name: locName }, function(res) {
            if (res && res.status === 'success') {
                window.CRM_OS_LOCATIONS = res.locations || window.CRM_OS_LOCATIONS;
                renderOsSitesList();
                if (typeof showCrmToast === 'function') {
                    showCrmToast('OS site removed successfully', 'success');
                }
            } else {
                alert((res && res.message) ? res.message : 'Error removing location.');
            }
        });
    });

    // Revert status if modal dismissed without selection
    $('#osDirectoryModal').on('hidden.bs.modal', function() {
        if (activeSelectEl && activeSelectEl.val() === 'OS' && previousStatus && previousStatus !== 'OS') {
            var row = activeSelectEl.closest('tr');
            var existingSub = row.find('.os-subreason-badge span span').text().trim();
            if (!existingSub) {
                activeSelectEl.val(previousStatus);
                if (typeof updateDropdownColor === 'function') {
                    updateDropdownColor(activeSelectEl, previousStatus);
                }
            }
        }
        activeLeadId = null;
        activeSelectEl = null;
        previousStatus = '';
        activeCurrentVal = '';
    });

    // Global Click on OS Subreason Badge in Table
    $(document).on('click', '.os-select-trigger', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var leadId = $(this).data('lead-id') || $(this).closest('tr').find('.table-inline-status').data('lead-id');
        var selectEl = $('.table-inline-status[data-lead-id="' + leadId + '"]');
        var currentVal = $(this).find('span').text().trim();
        var prevSt = selectEl.attr('data-current-status') || 'OS';
        window.openOsDirectoryModal(leadId, selectEl, currentVal, prevSt);
    });

    // Direct Header button to view/manage OS Sites
    $(document).on('click', '.btn-open-os-modal-direct', function(e) {
        e.preventDefault();
        window.openOsDirectoryModal(null, null, '', '', '<strong>OS Sites Directory</strong> - Manage Dynamic Locations');
    });

})(jQuery);
</script>
