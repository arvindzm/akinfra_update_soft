<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$curr_user = getUser();
$user_role = $curr_user['role'];
$user_id   = $curr_user['id'];

if (!in_array($user_role, ['admin', 'sales'])) {
    redirect('index.php');
}

// Handle Settings Update (Admin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_erp_settings' && $user_role === 'admin') {
    $webhook_url = trim($_POST['net_erp_webhook_url'] ?? '');
    $api_key     = trim($_POST['net_erp_api_key'] ?? '');
    $auto_sync   = isset($_POST['net_erp_auto_sync']) ? '1' : '0';
    $inbound_key = trim($_POST['crm_api_inbound_key'] ?? '');

    setAppSetting($pdo, 'net_erp_webhook_url', $webhook_url);
    setAppSetting($pdo, 'net_erp_api_key', $api_key);
    setAppSetting($pdo, 'net_erp_auto_sync', $auto_sync);
    if (!empty($inbound_key)) {
        setAppSetting($pdo, 'crm_api_inbound_key', $inbound_key);
    }

    $success = "Real Estate .NET ERP Integration settings updated successfully!";
}

// Handle 1-Click Sync Trigger from Page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_sync_lead') {
    $sync_lead_id = intval($_POST['lead_id'] ?? 0);
    if ($sync_lead_id > 0) {
        $res = syncLeadToNetErp($pdo, $sync_lead_id, $user_id);
        if ($res['success']) {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    }
}

// Current Settings
$net_erp_webhook_url = getAppSetting($pdo, 'net_erp_webhook_url', 'https://akinfrarealestate.auctechitsolutions.in/api/CrmIntegration/receive_lead');
$net_erp_api_key     = getAppSetting($pdo, 'net_erp_api_key', 'AK_INFRA_NET_ERP_SECRET_TOKEN_2026');
$net_erp_auto_sync   = getAppSetting($pdo, 'net_erp_auto_sync', '1');
$crm_api_inbound_key = getAppSetting($pdo, 'crm_api_inbound_key', 'AK_CRM_INBOUND_API_KEY_SECURE_999');

// Fetch Leads Ready for / Synced with .NET ERP
if ($user_role === 'sales') {
    $stmt = $pdo->prepare("
        SELECT l.*, t.name as telecaller_name, s.name as sales_name 
        FROM leads l
        LEFT JOIN users t ON l.telecaller_id = t.id
        LEFT JOIN users s ON l.salesperson_id = s.id
        WHERE l.salesperson_id = ? AND (l.status IN ('Booked / Token Paid', 'Booked', 'Converted') OR (l.booking_amount > 0) OR (l.deal_value > 0) OR l.erp_synced = 1)
        ORDER BY l.updated_at DESC
    ");
    $stmt->execute([$user_id]);
    $sync_leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_book_leads = count($sync_leads);
    $synced_leads_count = count(array_filter($sync_leads, fn($l) => !empty($l['erp_synced']) && $l['erp_synced'] == 1));
    $pending_sync_count = max(0, $total_book_leads - $synced_leads_count);
} else {
    // Admin: View all leads ready for ERP sync
    $total_book_leads = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE (status IN ('Booked / Token Paid', 'Booked', 'Converted') OR (booking_amount > 0) OR (deal_value > 0))")->fetchColumn();
    $synced_leads_count = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE erp_synced = 1")->fetchColumn();
    $pending_sync_count = max(0, $total_book_leads - $synced_leads_count);

    $stmt = $pdo->query("
        SELECT l.*, t.name as telecaller_name, s.name as sales_name 
        FROM leads l
        LEFT JOIN users t ON l.telecaller_id = t.id
        LEFT JOIN users s ON l.salesperson_id = s.id
        WHERE (l.status IN ('Booked / Token Paid', 'Booked', 'Converted') OR (l.booking_amount > 0) OR (l.deal_value > 0) OR l.erp_synced = 1)
        ORDER BY l.updated_at DESC
    ");
    $sync_leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$server_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$inbound_endpoint_url = "http://{$server_host}/ak-infra-crm-leads/api/erp_api.php";

$page_title = ".NET Real Estate ERP Integration Hub - AK Infra CRM";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .code-block {
            background: #0f172a;
            color: #f8fafc;
            border-radius: 10px;
            padding: 16px;
            font-family: monospace;
            font-size: 12px;
            overflow-x: auto;
            position: relative;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div id="content">
        <!-- Top Navbar -->
        <div class="top-navbar d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <button type="button" id="sidebarCollapse" class="btn btn-light shadow-sm">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h4 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2" style="font-size: 1.15rem;">
                    <i class="fa-solid fa-network-wired text-primary"></i>
                    .NET ERP Handover
                </h4>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="dropdown">
                    <button class="nav-action-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fa-solid fa-circle-user text-primary"></i><?php echo htmlspecialchars($curr_user['name']); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                        <li><a class="dropdown-item" href="profile.php"><i class="fa-solid fa-user me-2"></i>My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="content-body">
            
            <?php if(isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 mb-3" role="alert" style="border-radius: 12px;">
                    <i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if(isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 mb-3" role="alert" style="border-radius: 12px;">
                    <i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Architecture Header Banner -->
            <div class="banner-clean mb-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle p-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px; background: #ecfdf5; color: #059669;">
                            <i class="fa-solid fa-handshake"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <h6 class="mb-0 fw-bold text-dark">.NET Real Estate ERP Handover Bridge</h6>
                                <span class="badge-soft-emerald px-2 py-0.5 rounded text-xs fw-bold">Live</span>
                            </div>
                            <p class="text-muted small mb-0" style="font-size: 12px;">
                                Handover booked leads and token amounts directly to .NET Real Estate ERP for Plot Allotment.
                            </p>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge-soft-slate px-2.5 py-1 rounded text-xs font-monospace">
                            <i class="fa-solid fa-server me-1"></i>akinfrarealestate.auctechitsolutions.in
                        </span>
                    </div>
                </div>
            </div>

            <!-- KPI Metric Cards -->
            <div class="row g-3 mb-4">
                <div class="col-xl-4 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Booked Deals Ready</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark"><?php echo $total_book_leads; ?></div>
                    </div>
                </div>

                <div class="col-xl-4 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Synced to .NET ERP</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-cloud-arrow-up"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark"><?php echo $synced_leads_count; ?></div>
                    </div>
                </div>

                <div class="col-xl-4 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Pending Sync</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-hourglass-half"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark"><?php echo $pending_sync_count; ?></div>
                    </div>
                </div>
            </div>

            <!-- Configuration & Two-Way Endpoints Card (Admin Only) -->
            <?php if ($user_role === 'admin'): ?>
            <div class="row g-4 mb-4">
                <div class="col-lg-6">
                    <div class="table-card-clean h-100">
                        <div class="card-header-clean">
                            <h6 class="mb-0 fw-bold text-dark">
                                <i class="fa-solid fa-gear text-primary me-2"></i>Outbound Webhook Settings (.NET ERP)
                            </h6>
                        </div>
                        <div class="p-4">
                            <form method="POST" action="erp_integration.php">
                                <input type="hidden" name="action" value="save_erp_settings">
                                
                                <div class="mb-3">
                                    <label class="form-label text-dark fw-bold small">.NET ERP Target Webhook URL</label>
                                    <input type="url" name="net_erp_webhook_url" class="form-control form-control-sm font-monospace" value="<?php echo htmlspecialchars($net_erp_webhook_url); ?>" required placeholder="https://akinfrarealestate.auctechitsolutions.in/api/CrmIntegration/receive_lead">
                                    <div class="text-muted small mt-1" style="font-size: 11px;">Where token booking payloads will be sent automatically.</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label text-dark fw-bold small">.NET ERP Authorization API Secret Key</label>
                                    <input type="text" name="net_erp_api_key" class="form-control form-control-sm font-monospace" value="<?php echo htmlspecialchars($net_erp_api_key); ?>" required>
                                </div>

                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="net_erp_auto_sync" id="autoSyncCheck" <?php echo ($net_erp_auto_sync === '1') ? 'checked' : ''; ?>>
                                    <label class="form-check-label text-dark small fw-semibold" for="autoSyncCheck">
                                        Auto-sync to .NET ERP when Sales records Token Booking or Converts Deal
                                    </label>
                                </div>

                                <div class="pt-2 border-top">
                                    <button type="submit" class="btn btn-sm btn-primary px-3 fw-semibold">
                                        <i class="fa-solid fa-check me-1"></i>Save ERP Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="table-card-clean h-100">
                        <div class="card-header-clean">
                            <h6 class="mb-0 fw-bold text-dark">
                                <i class="fa-solid fa-key text-emerald me-2" style="color: #059669;"></i>Inbound CRM API Credentials (For .NET Software)
                            </h6>
                        </div>
                        <div class="p-4">
                            <div class="mb-3">
                                <label class="form-label text-muted small fw-semibold">Inbound API Endpoint URL for .NET ERP</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control font-monospace bg-light" readonly value="<?php echo htmlspecialchars($inbound_endpoint_url); ?>" id="inboundUrlInput">
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('inboundUrlInput').value); alert('Copied!');">
                                        <i class="fa-solid fa-copy"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label text-muted small fw-semibold">Inbound API Secret Key</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control font-monospace bg-light" readonly value="<?php echo htmlspecialchars($crm_api_inbound_key); ?>" id="inboundKeyInput">
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('inboundKeyInput').value); alert('Copied!');">
                                        <i class="fa-solid fa-copy"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="badge-soft-blue p-3 rounded small">
                                <i class="fa-solid fa-circle-info me-1"></i>
                                <strong>Supported Actions for .NET software:</strong>
                                <ul class="mb-0 mt-1 ps-3" style="font-size: 11.5px;">
                                    <li><code>?action=get_ready_leads</code>: Fetch booked leads to allot plots in .NET</li>
                                    <li><code>?action=update_allotment</code>: Post back .NET Plot No. & Booking ID</li>
                                    <li><code>?action=get_lead_audit</code>: Fetch full telecalling & sales history</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Leads Handover Table -->
            <div class="table-card-clean mb-4">
                <div class="card-header-clean">
                    <div class="d-flex align-items-center gap-2">
                        <h6 class="mb-0 fw-bold text-dark">
                            <i class="fa-solid fa-list-check text-primary me-2"></i>Leads Ready for .NET ERP Handover & Sync
                        </h6>
                        <span class="badge-soft-emerald px-2.5 py-0.5 rounded-pill text-xs fw-bold"><?php echo count($sync_leads); ?> Leads</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table-clean">
                        <thead>
                            <tr>
                                <th>CUSTOMER & CONTACT</th>
                                <th>PROJECT & UNIT #</th>
                                <th>DEAL VALUE (₹)</th>
                                <th>TOKEN PAID (₹)</th>
                                <th>SALES CLOSER</th>
                                <th>.NET ERP STATUS</th>
                                <th class="text-end">ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($sync_leads)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-network-wired fa-3x mb-2 opacity-40"></i>
                                    <h6 class="fw-bold text-dark mb-1">No Booked Leads Yet</h6>
                                    <p class="small text-muted mb-0">Leads with token deposits or converted deals will appear here for ERP sync.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach($sync_leads as $sl): 
                                $is_synced = !empty($sl['erp_synced']) && $sl['erp_synced'] == 1;
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($sl['name']); ?></div>
                                    <div class="text-muted small font-monospace">#<?php echo htmlspecialchars($sl['lead_code'] ?: $sl['id']); ?> • <?php echo htmlspecialchars($sl['phone']); ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($sl['project'] ?: 'General'); ?></div>
                                    <div class="small text-primary fw-semibold"><i class="fa-solid fa-house me-1"></i>Unit: <?php echo htmlspecialchars($sl['unit_number'] ?: '-'); ?></div>
                                </td>
                                <td>
                                    <?php if(!empty($sl['deal_value']) && $sl['deal_value'] > 0): ?>
                                        <span class="badge-soft-emerald px-2 py-0.5 rounded text-xs font-monospace fw-bold">₹<?php echo number_format($sl['deal_value']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($sl['booking_amount']) && $sl['booking_amount'] > 0): ?>
                                        <span class="badge-soft-blue px-2 py-0.5 rounded text-xs font-monospace fw-bold">₹<?php echo number_format($sl['booking_amount']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="small text-slate-700 fw-semibold"><?php echo htmlspecialchars($sl['sales_name'] ?: 'Direct'); ?></span>
                                </td>
                                <td>
                                    <?php if($is_synced): ?>
                                        <span class="badge-soft-emerald px-2.5 py-1 rounded text-xs fw-bold">
                                            <i class="fa-solid fa-circle-check me-1"></i>Synced to .NET ERP
                                        </span>
                                        <?php if(!empty($sl['erp_customer_id'])): ?>
                                            <div class="text-muted small font-monospace mt-0.5" style="font-size: 10.5px;"><?php echo htmlspecialchars($sl['erp_customer_id']); ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge-soft-amber px-2.5 py-1 rounded text-xs fw-bold">
                                            <i class="fa-solid fa-clock me-1"></i>Pending Sync
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <button type="button" class="btn btn-sm btn-outline-primary px-2.5 fw-semibold view-journey-btn" data-lead-id="<?php echo $sl['id']; ?>">
                                            <i class="fa-solid fa-timeline me-1"></i>Journey
                                        </button>

                                        <form method="POST" action="erp_integration.php" class="d-inline">
                                            <input type="hidden" name="action" value="manual_sync_lead">
                                            <input type="hidden" name="lead_id" value="<?php echo $sl['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-primary px-2.5 fw-semibold shadow-sm" title="Push to .NET Real Estate ERP">
                                                <i class="fa-solid fa-cloud-arrow-up me-1"></i>Push to ERP
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Ready-to-use C# (.NET) Webhook Receiver Code Snippet -->
            <div class="table-card-clean mb-4">
                <div class="card-header-clean">
                    <h6 class="mb-0 fw-bold text-dark">
                        <i class="fa-solid fa-code text-primary me-2"></i>.NET / C# Webhook Controller Code Snippet (For your .NET Developer)
                    </h6>
                </div>
                <div class="p-4">
                    <p class="text-muted small mb-2">
                        Aapke .NET Real Estate software (<code>akinfrarealestate.auctechitsolutions.in</code>) me is Controller code ko paste karke CRM se 1-click me customer and plot booking transfer receive ho jayega:
                    </p>
                    <div class="code-block">
<pre class="mb-0" style="color: #93c5fd;">
// ASP.NET Core / .NET Web API Controller for AK Infra Real Estate ERP
[ApiController]
[Route("api/[controller]")]
public class CrmLeadReceiveController : ControllerBase
{
    [HttpPost("receive")]
    public async Task&lt;IActionResult&gt; ReceiveCrmLead([FromBody] CrmLeadPayload lead, [FromHeader(Name = "X-API-KEY")] string apiKey)
    {
        if (apiKey != "AK_INFRA_NET_ERP_SECRET_TOKEN_2026") 
            return Unauthorized(new { status = "Invalid API Key" });

        // 1. Map Lead to .NET Customer & Plot Booking tables
        // var customerId = await _customerService.CreateOrUpdateCustomerAsync(lead.CustomerName, lead.Phone, lead.Email);
        // var bookingId  = await _bookingService.CreateBookingDraftAsync(customerId, lead.ProjectName, lead.UnitNumber, lead.TokenAmount, lead.DealValue);

        return Ok(new { 
            status = "Success", 
            message = "Lead received in .NET Real Estate ERP", 
            erp_customer_id = "CUST-" + lead.CrmLeadId 
        });
    }
}

public class CrmLeadPayload
{
    public int CrmLeadId { get; set; }
    public string LeadCode { get; set; }
    public string CustomerName { get; set; }
    public string Phone { get; set; }
    public string Email { get; set; }
    public string ProjectName { get; set; }
    public string UnitNumber { get; set; }
    public decimal TokenAmount { get; set; }
    public decimal DealValue { get; set; }
    public string SalespersonName { get; set; }
    public string TelecallerName { get; set; }
}
</pre>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Modal: Comprehensive Lead Journey & Lifecycle Audit Timeline -->
<?php include 'includes/lead_journey_modal.php'; ?>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>
