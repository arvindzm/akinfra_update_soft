<?php
require_once 'includes/config.php';

echo "<h1>Database Seeder</h1>";

try {
    // 1. Create Dummy Users if they don't exist
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'telecaller'");
    $telecallers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($telecallers) == 0) {
        $password = password_hash('password', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (name, email, password, role) VALUES ('Priya Telecaller', 'priya@crmleads.com', '$password', 'telecaller')");
        $pdo->exec("INSERT INTO users (name, email, password, role) VALUES ('Rahul Telecaller', 'rahul@crmleads.com', '$password', 'telecaller')");
        $stmt = $pdo->query("SELECT id FROM users WHERE role = 'telecaller'");
        $telecallers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "<p>Created dummy Telecallers.</p>";
    }

    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'sales'");
    $salespersons = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($salespersons) == 0) {
        $password = password_hash('password', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (name, email, password, role) VALUES ('Vikram Sales', 'vikram@crmleads.com', '$password', 'sales')");
        $pdo->exec("INSERT INTO users (name, email, password, role) VALUES ('Neha Sales', 'neha@crmleads.com', '$password', 'sales')");
        $stmt = $pdo->query("SELECT id FROM users WHERE role = 'sales'");
        $salespersons = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "<p>Created dummy Salespersons.</p>";
    }

    // 2. Create Dummy Leads
    $names = ['Amit Sharma', 'Rohan Gupta', 'Pooja Singh', 'Suresh Verma', 'Anita Desai', 'Karan Patel', 'Megha Kapoor', 'Sunil Yadav', 'Divya Jain', 'Ravi Kumar', 'Sneha Reddy', 'Aditya Joshi', 'Kavita Mishra', 'Rajesh Tiwari', 'Priya Bhatia', 'Gaurav Agarwal', 'Shruti Iyer', 'Nitin Chawla', 'Ritu Saxena', 'Varun Rao'];
    
    $sources = ['Meta Ads', 'Google Ads', 'Website', 'Manual'];
    $statuses = ['New', 'In Progress', 'Assigned to Sales', 'Converted', 'Dead'];
    
    // Clear existing leads for a clean slate
    $pdo->exec("DELETE FROM lead_notes");
    $pdo->exec("DELETE FROM leads");
    echo "<p>Cleared old leads.</p>";

    $insert_stmt = $pdo->prepare("INSERT INTO leads (source, name, email, phone, status, telecaller_id, salesperson_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    $lead_count = 0;
    foreach($names as $index => $name) {
        $source = $sources[array_rand($sources)];
        $email = strtolower(explode(' ', $name)[0]) . rand(10, 99) . "@gmail.com";
        $phone = "9" . rand(100000000, 999999999);
        $status = $statuses[array_rand($statuses)];
        
        $telecaller_id = $telecallers[array_rand($telecallers)];
        $salesperson_id = null;
        
        // If status requires salesperson
        if (in_array($status, ['Assigned to Sales', 'Converted', 'Dead'])) {
            $salesperson_id = $salespersons[array_rand($salespersons)];
        }
        
        // Random date in the last 7 days
        $days_ago = rand(0, 6);
        $created_at = date('Y-m-d H:i:s', strtotime("-$days_ago days"));
        
        $insert_stmt->execute([$source, $name, $email, $phone, $status, $telecaller_id, $salesperson_id, $created_at]);
        $lead_count++;
    }
    
    echo "<p>Successfully inserted $lead_count dummy leads with random dates for the graph!</p>";
    echo "<p><a href='index.php'>Go back to Dashboard</a></p>";

} catch (PDOException $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
?>
