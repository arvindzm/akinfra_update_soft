<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$curr_user = getUser();
$user_role = $curr_user['role'];
$user_id   = $curr_user['id'];

// Project Catalog Data
$catalog = [
    [
        'id' => 1,
        'name' => 'The Grand Palm Residences',
        'location' => 'DGC-I (Near Expressway)',
        'type' => 'Residential Plots & Luxury Villas',
        'sizes' => '100, 150, 200, 250 Sq Yd',
        'price_range' => '₹35 Lakhs - ₹75 Lakhs',
        'rate' => '₹35,000 / Sq Yd',
        'status' => 'Fast Selling / Ready for Registry',
        'amenities' => ['Gated Community', 'Clubhouse & Swimming Pool', '24x7 Security', 'Wide 40ft Roads', 'Park Facing Plots'],
        'bank_loan' => 'SBI, HDFC, ICICI Approved',
        'badge_color' => 'primary'
    ],
    [
        'id' => 2,
        'name' => 'Skyline Heights Luxury Towers',
        'location' => 'DGC-II (Sector 12)',
        'type' => '2BHK, 3BHK & 4BHK Premium Apartments',
        'sizes' => '1150 Sq Ft - 2250 Sq Ft',
        'price_range' => '₹55 Lakhs - ₹1.25 Cr',
        'rate' => '₹4,800 / Sq Ft',
        'status' => 'Under Construction (Possession in 18 Mos)',
        'amenities' => ['High Speed Lifts', 'Podium Garden', 'Gymnasium', 'Covered Car Parking', 'Power Backup'],
        'bank_loan' => 'All Major Banks Approved',
        'badge_color' => 'purple'
    ],
    [
        'id' => 3,
        'name' => 'Green Valley Eco Villas',
        'location' => 'SGC (Southern Green Corridor)',
        'type' => 'Independent Duplex Eco Villas',
        'sizes' => '150 & 200 Sq Yd Duplex (3BHK / 4BHK)',
        'price_range' => '₹65 Lakhs - ₹95 Lakhs',
        'rate' => '₹42,000 / Sq Yd',
        'status' => 'Sample Villa Ready for Visit',
        'amenities' => ['Private Lawn', 'Solar Power Integration', 'Lush Green Surroundings', 'Kids Play Zone'],
        'bank_loan' => 'Loan Available up to 80%',
        'badge_color' => 'success'
    ],
    [
        'id' => 4,
        'name' => 'Royal Imperial Bayfront',
        'location' => 'NGC (Northern Gateway Complex)',
        'type' => 'Commercial High Street Shops & SCO Plots',
        'sizes' => '300 Sq Ft - 1000 Sq Ft',
        'price_range' => '₹45 Lakhs - ₹1.50 Cr',
        'rate' => '₹12,000 / Sq Ft',
        'status' => 'Prime Commercial Investment',
        'amenities' => ['100ft Main Road Frontage', 'High Footfall Zone', 'Escalators & Elevators', 'Double Height Shops'],
        'bank_loan' => 'Commercial Loan Available',
        'badge_color' => 'warning'
    ],
    [
        'id' => 5,
        'name' => 'Emerald Park Signature Greens',
        'location' => 'DGC-III & DGC-IV',
        'type' => 'Affordable & Premium Residential Plots',
        'sizes' => '80, 100, 120 Sq Yd',
        'price_range' => '₹24 Lakhs - ₹40 Lakhs',
        'rate' => '₹28,000 / Sq Yd',
        'status' => 'Immediate Registry & Mutation',
        'amenities' => ['Water & Sewerage Line Ready', 'Street Lights', 'Boundary Wall', 'Immediate Possession'],
        'bank_loan' => 'Registry Loan Available',
        'badge_color' => 'info'
    ]
];

// Fetch active lead counts for each project in DB
$lead_counts_by_proj = [];
try {
    $counts_stmt = $pdo->query("SELECT project, COUNT(*) as c FROM leads GROUP BY project");
    while ($row = $counts_stmt->fetch(PDO::FETCH_ASSOC)) {
        $lead_counts_by_proj[$row['project']] = (int)$row['c'];
    }
} catch (PDOException $e) {}

$search = trim($_GET['search'] ?? '');
if (!empty($search)) {
    $catalog = array_filter($catalog, function($p) use ($search) {
        return (stripos($p['name'], $search) !== false || stripos($p['location'], $search) !== false || stripos($p['type'], $search) !== false);
    });
}

$page_title = "Projects, Sites & Inventory - CRM Leads";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
</head>
<body>

<div class="wrapper">
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
                <div>
                    <h4 class="mb-0 fw-bold text-dark">
                        <i class="fa-solid fa-building text-primary me-2"></i>Projects, Sites & Inventory Hub
                    </h4>
                    <p class="text-muted small mb-0">Instant property configurations, rate sheets, amenities, and 1-click WhatsApp share for sales closers</p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="sales_pipeline.php" class="btn btn-outline-primary btn-sm px-3 shadow-sm">
                    <i class="fa-solid fa-diagram-project me-1"></i>Sales Pipeline
                </a>
                <a href="site_visits.php" class="btn btn-outline-purple btn-sm px-3 shadow-sm" style="color: #9333ea; border-color: #d8b4fe; background: #faf5ff;">
                    <i class="fa-solid fa-car-side me-1"></i>Site Visits
                </a>
                <div class="dropdown">
                    <button class="btn btn-light btn-sm shadow-sm dropdown-toggle fw-semibold" type="button" data-bs-toggle="dropdown">
                        <i class="fa-solid fa-user-circle text-primary me-1"></i><?php echo htmlspecialchars($curr_user['name']); ?>
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
            
            <!-- Search & Filter Bar -->
            <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
                <div class="card-body p-3">
                    <form method="GET" action="project_inventory.php" class="row g-2 align-items-center">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                                <input type="text" name="search" class="form-control border-start-0" placeholder="Search project name, site location (DGC, SGC, NGC), or property type..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        <div class="col-md-auto d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-3 fw-semibold"><i class="fa-solid fa-search me-1"></i>Search Projects</button>
                            <a href="project_inventory.php" class="btn btn-outline-secondary px-3" title="Reset"><i class="fa-solid fa-rotate-right"></i></a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Project Cards Grid -->
            <div class="row g-4">
                <?php foreach($catalog as $proj): 
                    $lead_c = $lead_counts_by_proj[$proj['name']] ?? 0;
                    $wa_text = urlencode("Hello! Here are the details for *" . $proj['name'] . "*:\n📍 *Location:* " . $proj['location'] . "\n🏡 *Configuration:* " . $proj['type'] . "\n📐 *Sizes:* " . $proj['sizes'] . "\n💰 *Starting Price:* " . $proj['price_range'] . " (" . $proj['rate'] . ")\n✅ *Status:* " . $proj['status'] . "\n\nPlease let me know if you would like to schedule a site visit!");
                ?>
                <div class="col-xl-6">
                    <div class="card border-0 shadow-sm h-100" style="border-radius: 14px; border-top: 4px solid var(--bs-primary) !important;">
                        <div class="card-body p-4 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <span class="badge bg-light text-primary border font-monospace px-2 py-1 mb-1">
                                            <i class="fa-solid fa-map-pin me-1"></i><?php echo htmlspecialchars($proj['location']); ?>
                                        </span>
                                        <h5 class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($proj['name']); ?></h5>
                                    </div>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 small">
                                        <i class="fa-solid fa-users me-1"></i><?php echo $lead_c; ?> Leads
                                    </span>
                                </div>

                                <p class="text-secondary small fw-semibold mb-3">
                                    <i class="fa-solid fa-layer-group me-1"></i><?php echo htmlspecialchars($proj['type']); ?>
                                </p>

                                <div class="row g-2 p-3 bg-light rounded-3 mb-3 small">
                                    <div class="col-sm-6">
                                        <span class="text-muted">Plot / Unit Sizes:</span>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($proj['sizes']); ?></div>
                                    </div>
                                    <div class="col-sm-6">
                                        <span class="text-muted">Price Range:</span>
                                        <div class="fw-bold text-success"><?php echo htmlspecialchars($proj['price_range']); ?></div>
                                    </div>
                                    <div class="col-sm-6 mt-2">
                                        <span class="text-muted">Base Rate:</span>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($proj['rate']); ?></div>
                                    </div>
                                    <div class="col-sm-6 mt-2">
                                        <span class="text-muted">Project Status:</span>
                                        <div class="fw-bold text-primary"><?php echo htmlspecialchars($proj['status']); ?></div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <span class="text-muted small fw-semibold d-block mb-1">Key Amenities & Highlights:</span>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach($proj['amenities'] as $am): ?>
                                            <span class="badge bg-white text-dark border px-2 py-1 small fw-normal">
                                                <i class="fa-solid fa-check text-success me-1"></i><?php echo htmlspecialchars($am); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="small text-muted mb-3">
                                    <i class="fa-solid fa-landmark text-secondary me-1"></i>Bank Loans: <strong class="text-dark"><?php echo htmlspecialchars($proj['bank_loan']); ?></strong>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2 pt-3 border-top">
                                <a href="https://api.whatsapp.com/send?text=<?php echo $wa_text; ?>" target="_blank" class="btn btn-success btn-sm fw-bold px-3 shadow-sm">
                                    <i class="fa-brands fa-whatsapp me-1"></i>Share on WhatsApp
                                </a>
                                <a href="leads.php?project=<?php echo urlencode($proj['name']); ?>" class="btn btn-outline-primary btn-sm fw-semibold px-3">
                                    <i class="fa-solid fa-list me-1"></i>View Project Leads (<?php echo $lead_c; ?>)
                                </a>
                                <a href="sales_pipeline.php?project=<?php echo urlencode($proj['name']); ?>" class="btn btn-outline-secondary btn-sm fw-semibold px-3">
                                    <i class="fa-solid fa-diagram-project me-1"></i>Pipeline
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>
