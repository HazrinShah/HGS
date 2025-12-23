<?php
session_start();
if (!isset($_SESSION['guiderID'])) {
    header("Location: GLogin.html");
    exit();
}
$guiderID = $_SESSION['guiderID'];
?>

<?php
include '../shared/db_connection.php';

// Fetch cancelled and completed bookings for history
$cancelledBookings = [];
$completedBookings = [];

if ($guiderID) {
    // Query for ALL cancelled and completed bookings (for history section)
    $historyStmt = $conn->prepare("
        SELECT 
            b.bookingID,
            b.totalHiker,
            b.price,
            b.status,
            b.startDate,
            b.endDate,
            h.username AS hikerName,
            m.name AS location
        FROM booking b
        JOIN hiker h ON b.hikerID = h.hikerID
        JOIN mountain m ON b.mountainID = m.mountainID
        WHERE b.guiderID = ?
        AND b.status IN ('cancelled', 'completed')
        ORDER BY b.startDate DESC
    ");
    $historyStmt->bind_param("i", $guiderID);
    $historyStmt->execute();
    $historyResult = $historyStmt->get_result();
    $allHistoryBookings = $historyResult->fetch_all(MYSQLI_ASSOC);
    
    // Split history bookings
    foreach ($allHistoryBookings as $row) {
        if (strtoupper($row['status']) === 'CANCELLED') {
            $cancelledBookings[] = $row;
        } elseif (strtoupper($row['status']) === 'COMPLETED') {
            $completedBookings[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking History - Hiking Guidance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --guider-blue: #1e40af;
            --guider-blue-light: #3b82f6;
            --guider-blue-dark: #1e3a8a;
            --guider-blue-accent: #60a5fa;
            --guider-blue-soft: #dbeafe;
            --primary: var(--guider-blue);
            --accent: var(--guider-blue-light);
            --soft-bg: #f8fafc;
            --card-white: #ffffff;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --dark-color: #343a40;
            --light-color: #f8f9fa;
            --primary-color: var(--guider-blue);
        }

        body {
            font-family: "Montserrat", sans-serif;
            background-color: var(--soft-bg);
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

    /* Header */
    .navbar {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue)) !important;
      padding: 12px 0;
      box-shadow: 0 4px 20px rgba(30, 64, 175, 0.3);
    }
    .navbar-toggler {
      border: 1px solid rgba(255, 255, 255, 0.3);
    }
    .navbar-toggler-icon {
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 1%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
    }

    .navbar-title {
      font-size: 22px;
      font-weight: bold;
      color: white;
      margin: 0 auto;
      text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
    }

    .logo {
      width: 60px;
      height: 60px;
      object-fit: contain;
    }

    /* Offcanvas Menu */
    .offcanvas {
      background-color: var(--light-color);
    }

    .offcanvas-title {
      color: var(--primary-color);
      font-weight: 600;
    }

    .nav-link {
      color: var(--dark-color);
      font-weight: 500;
      padding: 10px 15px;
      border-radius: 8px;
      margin: 2px 0;
    }

    .nav-link:hover, .nav-link.active {
      background-color: var(--guider-blue-soft);
      color: var(--guider-blue-dark);
      border-color: var(--guider-blue);
    }


        /* Main Container */
        .main-container {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--guider-blue-dark);
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: #64748b;
            font-size: 1.1rem;
        }

        /* History Section */
        .history-section {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            padding: 2rem;
            border: 1px solid #e2e8f0;
        }

        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e2e8f0;
        }

        .history-title {
            margin: 0;
            color: var(--guider-blue-dark);
            font-weight: 600;
            font-size: 1.5rem;
        }

        .history-filter {
            padding: 0.75rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            color: #64748b;
            font-size: 0.9rem;
            min-width: 150px;
        }

        .history-filter:focus {
            outline: none;
            border-color: var(--guider-blue);
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }

        /* History Grid */
        .history-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 1.5rem;
        }

        .history-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .history-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .history-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem;
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-bottom: 1px solid #e2e8f0;
        }

        .booking-id {
            font-weight: 700;
            color: var(--guider-blue-dark);
            font-size: 1.1rem;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-cancelled {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .status-completed {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .history-card-body {
            padding: 1.5rem;
        }

        .history-info {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .info-row {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: #374151;
            font-size: 0.95rem;
        }

        .info-row i {
            width: 20px;
            color: var(--guider-blue);
            font-size: 1rem;
        }

        .info-row span {
            font-weight: 500;
        }

        .history-card-footer {
            padding: 1.25rem;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .btn-details {
            width: 100%;
            padding: 0.875rem;
            background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-details:hover {
            background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(30, 64, 175, 0.3);
            color: white;
        }

        /* Empty State */
        .history-empty {
            text-align: center;
            padding: 4rem 2rem;
            color: #64748b;
        }

        .empty-icon {
            margin-bottom: 1.5rem;
        }

        .empty-icon i {
            font-size: 4rem;
            color: var(--guider-blue);
            opacity: 0.6;
        }

        .history-empty h4 {
            color: var(--guider-blue-dark);
            margin-bottom: 1rem;
            font-weight: 600;
            font-size: 1.5rem;
        }

        .history-empty p {
            margin: 0;
            font-size: 1.1rem;
            line-height: 1.6;
        }


        /* Responsive */
        @media (max-width: 768px) {
            .main-container {
                padding: 1rem;
            }
            
            .history-grid {
                grid-template-columns: 1fr;
            }
            
            .history-header {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }
            
            .history-filter {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<!-- Header -->
<header>
  <nav class="navbar">
    <div class="container d-flex align-items-center justify-content-between">
      <!-- hamburger button (left) -->
      <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasNavbar" aria-controls="offcanvasNavbar">
        <span class="navbar-toggler-icon"></span>
      </button>

      <!-- title (center) -->
      <h1 class="navbar-title mx-auto">HIKING GUIDANCE SYSTEM</h1>

      <!-- logo (right) -->
      <a class="navbar-brand" href="../index.php">
        <img src="../img/logo.png" class="img-fluid logo" alt="HGS Logo">
      </a>
    </div>

    <!-- Offcanvas menu -->
    <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasNavbar" aria-labelledby="offcanvasNavbarLabel">
      <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="offcanvasNavbarLabel">Menu</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
      </div>
      <div class="offcanvas-body">
        <ul class="navbar-nav justify-content-end flex-grow-1 pe-3">
          <li class="nav-item"><a class="nav-link" href="GBooking.php">Booking</a></li>
          <li class="nav-item"><a class="nav-link active" href="GHistory.php">History</a></li>
          <li class="nav-item"><a class="nav-link" href="GProfile.php">Profile</a></li>
          <li class="nav-item"><a class="nav-link" href="GEarning.php">Earn & Receive</a></li>
          <li class="nav-item"><a class="nav-link" href="GPerformance.php">Performance Review</a></li>
          <form action="../shared/logout.php" method="POST" class="d-flex justify-content-center mt-5" >
            <button type="submit" class="btn btn-outline-danger">Logout</button>
          </form>
        </ul>
      </div>
    </div>
  </nav>
</header>
<!-- End Header -->

<?php include_once '../shared/suspension_banner.php'; ?>

    <div class="main-container">

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Booking History</h1>
            <p class="page-subtitle">View your completed and cancelled bookings</p>
        </div>

        <!-- History Section -->
        <div class="history-section">
            <!-- History Header -->
            <div class="history-header">
                <h5 class="history-title">All Bookings</h5>
                <select id="filterHistoryStatus" class="history-filter">
                    <option value="all">All Status</option>
                    <option value="CANCELLED">Cancelled</option>
                    <option value="COMPLETED">Completed</option>
                </select>
            </div>
            
            <?php 
            $allHistoryBookings = array_merge($cancelledBookings, $completedBookings);
            if (!empty($allHistoryBookings)): ?>
                <div class="history-grid">
                    <?php foreach ($allHistoryBookings as $row): ?>
                        <div class="history-card" data-status="<?= strtoupper(htmlspecialchars($row['status'])) ?>">
                            <div class="history-card-header">
                                <div class="booking-id">#<?= htmlspecialchars($row['bookingID']) ?></div>
                                <span class="status-badge 
                                    <?= strtoupper($row['status']) == 'CANCELLED' ? 'status-cancelled' : 
                                        (strtoupper($row['status']) == 'COMPLETED' ? 'status-completed' : '') ?>">
                                    <?= strtoupper($row['status']) ?>
                                </span>
                            </div>
                            <div class="history-card-body">
                                <div class="history-info">
                                    <div class="info-row">
                                        <i class="fas fa-user"></i>
                                        <span><?= htmlspecialchars($row['hikerName']) ?></span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span><?= htmlspecialchars($row['location']) ?></span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-calendar"></i>
                                        <span><?= date("d M Y", strtotime($row['startDate'])) ?> - <?= date("d M Y", strtotime($row['endDate'])) ?></span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-dollar-sign"></i>
                                        <span>RM <?= number_format($row['price'], 2) ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="history-card-footer">
                                <button class="btn btn-details" data-bs-toggle="modal" data-bs-target="#detailsModalHistory<?= $row['bookingID'] ?>">
                                    <i class="fas fa-eye"></i> View Details
                                </button>
                            </div>
                        </div>

                        <!-- Booking Details Modal -->
                        <div class="modal fade" id="detailsModalHistory<?= $row['bookingID'] ?>" tabindex="-1" aria-labelledby="detailsLabelHistory<?= $row['bookingID'] ?>" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <div class="modal-content border-0 shadow">
                                    <div class="modal-header" style="background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue)); color: white; border-radius: 20px 20px 0 0; border: none; padding: 1.5rem;">
                                        <h5 class="modal-title" id="detailsLabelHistory<?= $row['bookingID'] ?>">Booking Details - #<?= $row['bookingID'] ?></h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <ul class="list-group list-group-flush">
                                            <li class="list-group-item"><strong>Hiker Name:</strong> <?= htmlspecialchars($row['hikerName']) ?></li>
                                            <li class="list-group-item"><strong>Location:</strong> <?= htmlspecialchars($row['location']) ?></li>
                                            <li class="list-group-item"><strong>Start Date:</strong> <?= date("d M Y", strtotime($row['startDate'])) ?></li>
                                            <li class="list-group-item"><strong>End Date:</strong> <?= date("d M Y", strtotime($row['endDate'])) ?></li>
                                            <li class="list-group-item"><strong>Total Hikers:</strong> <?= htmlspecialchars($row['totalHiker']) ?></li>
                                            <li class="list-group-item"><strong>Price:</strong> RM <?= number_format($row['price'], 2) ?></li>
                                            <li class="list-group-item"><strong>Status:</strong> 
                                                <span class="status-badge 
                                                    <?= strtoupper($row['status']) == 'CANCELLED' ? 'status-cancelled' : 
                                                        (strtoupper($row['status']) == 'COMPLETED' ? 'status-completed' : '') ?>">
                                                    <?= strtoupper($row['status']) ?>
                                                </span>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="modal-footer">
                                        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="history-empty">
                    <div class="empty-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <h4>No History Yet</h4>
                    <p>Your completed and cancelled bookings will appear here once you have some activity.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // History Filter Script
        const historyFilterSelect = document.getElementById('filterHistoryStatus');
        if (historyFilterSelect) {
            historyFilterSelect.addEventListener('change', () => {
                const selected = historyFilterSelect.value.toUpperCase();
                const historyCards = document.querySelectorAll('.history-card');
                
                historyCards.forEach(card => {
                    const status = card.getAttribute('data-status');
                    if (selected === 'ALL' || status === selected) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        }
    </script>
</body>
</html>

