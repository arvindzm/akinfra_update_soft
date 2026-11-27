<?php
// includes/site_location_modal.php
// Reusable modal for adding and managing Site Locations across CRM
?>
<div class="modal fade" id="manageSiteLocationsModal" tabindex="-1" aria-labelledby="manageSiteLocationsModalLabel" aria-hidden="true" style="backdrop-filter: blur(4px);">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header border-bottom bg-light px-4 py-3">
                <div>
                    <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2 mb-0" id="manageSiteLocationsModalLabel">
                        <i class="fa-solid fa-map-location-dot text-primary"></i> Site Locations
                    </h5>
                    <small class="text-muted" style="font-size: 12px;">Add new project site locations for quick selection in leads</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-4">
                <!-- Add New Location Form -->
                <label class="form-label fw-bold text-dark small mb-1.5">Add New Site Location</label>
                <div class="input-group mb-3 shadow-sm" style="border-radius: 8px; overflow: hidden;">
                    <span class="input-group-text bg-white border-end-0 text-muted">
                        <i class="fa-solid fa-location-pin text-primary"></i>
                    </span>
                    <input type="text" id="newSiteLocationInput" class="form-control border-start-0 ps-1" placeholder="e.g. DGC-V, Green Valley, Gomti Greens" maxlength="80" autocomplete="off">
                    <button class="btn btn-primary px-3 fw-bold d-flex align-items-center gap-1.5" type="button" id="btnAddNewSiteLocation">
                        <i class="fa-solid fa-plus"></i>
                        <span>Add</span>
                    </button>
                </div>
                <div id="siteLocationFeedback" class="small mb-3 d-none"></div>

                <!-- Existing Locations Container -->
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <label class="form-label fw-bold text-secondary text-uppercase mb-0" style="font-size: 11px; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-list-check me-1"></i> Current Locations List
                    </label>
                    <span class="badge bg-secondary-subtle text-secondary" id="siteLocationsCountBadge" style="font-size: 11px;">0 Locations</span>
                </div>

                <div class="p-3 bg-light rounded-3 border" style="max-height: 220px; overflow-y: auto;">
                    <div class="d-flex flex-wrap gap-2 align-items-center" id="siteLocationsListContainer">
                        <div class="text-center w-100 py-3 text-muted small">
                            <i class="fa-solid fa-spinner fa-spin me-1"></i> Loading site locations...
                        </div>
                    </div>
                </div>

                <div class="mt-3 p-2.5 rounded bg-info-subtle border border-info-subtle small text-dark d-flex align-items-start gap-2" style="font-size: 12px;">
                    <i class="fa-solid fa-circle-info text-info mt-0.5"></i>
                    <div>
                        <strong>Instant Live Sync:</strong> Newly added site locations will instantly be available in the dropdown of all lead rows without reloading.
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light px-4 py-2.5 border-top d-flex justify-content-end">
                <button type="button" class="btn btn-secondary px-4 fw-semibold" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
