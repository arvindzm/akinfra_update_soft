<?php
// includes/budget_modal.php
// Reusable modal for adding and managing Budget Ranges across CRM
?>
<div class="modal fade" id="manageBudgetsModal" tabindex="-1" aria-labelledby="manageBudgetsModalLabel" aria-hidden="true" style="backdrop-filter: blur(4px);">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header border-bottom bg-light px-4 py-3">
                <div>
                    <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2 mb-0" id="manageBudgetsModalLabel">
                        <i class="fa-solid fa-wallet text-success"></i> Manage Budget Ranges
                    </h5>
                    <small class="text-muted" style="font-size: 12px;">Add new dynamic budget slabs for quick telecaller selection</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-4">
                <!-- Add New Budget Form -->
                <label class="form-label fw-bold text-dark small mb-1.5">Add New Budget Range</label>
                <div class="input-group mb-3 shadow-sm" style="border-radius: 8px; overflow: hidden;">
                    <span class="input-group-text bg-white border-end-0 text-muted">
                        <i class="fa-solid fa-indian-rupee-sign text-success"></i>
                    </span>
                    <input type="text" id="newBudgetInput" class="form-control border-start-0 ps-1" placeholder="e.g. 0 - 10 Lakhs, 10 - 50 Lakhs, 1 Cr+" maxlength="80" autocomplete="off">
                    <button class="btn btn-success px-3 fw-bold d-flex align-items-center gap-1.5" type="button" id="btnAddNewBudget">
                        <i class="fa-solid fa-plus"></i>
                        <span>Add</span>
                    </button>
                </div>
                <div id="budgetFeedback" class="small mb-3 d-none"></div>

                <!-- Existing Budgets Container -->
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <label class="form-label fw-bold text-secondary text-uppercase mb-0" style="font-size: 11px; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-list-check me-1"></i> Current Budget Options
                    </label>
                    <span class="badge bg-secondary-subtle text-secondary" id="budgetsCountBadge" style="font-size: 11px;">0 Options</span>
                </div>

                <div class="p-3 bg-light rounded-3 border" style="max-height: 220px; overflow-y: auto;">
                    <div class="d-flex flex-wrap gap-2 align-items-center" id="budgetsListContainer">
                        <div class="text-center w-100 py-3 text-muted small">
                            <i class="fa-solid fa-spinner fa-spin me-1"></i> Loading budget ranges...
                        </div>
                    </div>
                </div>

                <div class="mt-3 p-2.5 rounded bg-success-subtle border border-success-subtle small text-dark d-flex align-items-start gap-2" style="font-size: 12px;">
                    <i class="fa-solid fa-circle-info text-success mt-0.5"></i>
                    <div>
                        <strong>Instant Live Sync:</strong> Newly added budget options will instantly be available in the dropdown of all lead rows for telecallers without reloading.
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light px-4 py-2.5 border-top d-flex justify-content-end">
                <button type="button" class="btn btn-secondary px-4 fw-semibold" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
