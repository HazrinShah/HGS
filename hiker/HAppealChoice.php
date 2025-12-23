<?php
session_start();

// Check if hiker is logged in
if (!isset($_SESSION['hikerID'])) {
    header("Location: HLogin.html");
    exit();
}

$hikerID = $_SESSION['hikerID'];
include '../shared/db_connection.php';

// Get appeals that require hiker choice (admin approved cancellation)
$query = "SELECT a.*, b.startDate, b.endDate, b.price, b.bookingID, g.username as guiderName, m.name as mountainName
          FROM appeal a 
          JOIN booking b ON a.bookingID = b.bookingID 
          JOIN guider g ON b.guiderID = g.guiderID 
          JOIN mountain m ON b.mountainID = m.mountainID 
          WHERE a.hikerID = ? AND a.status = 'approved' AND a.appealType = 'cancellation'
          ORDER BY a.createdAt DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$appeals = $result->fetch_all(MYSQLI_ASSOC);

// Also get pending refund appeals to show status
$pendingRefundQuery = "SELECT a.*, b.startDate, b.endDate, b.price, g.username as guiderName, m.name as mountainName
          FROM appeal a 
          JOIN booking b ON a.bookingID = b.bookingID 
          JOIN guider g ON b.guiderID = g.guiderID 
          JOIN mountain m ON b.mountainID = m.mountainID 
          WHERE a.hikerID = ? AND a.status = 'pending_refund'
          ORDER BY a.createdAt DESC";

$pendingStmt = $conn->prepare($pendingRefundQuery);
$pendingStmt->bind_param("i", $hikerID);
$pendingStmt->execute();
$pendingRefunds = $pendingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appeal Choice - HGS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .choice-card {
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px solid transparent;
        }
        .choice-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .choice-card.selected {
            border-color: #0d6efd;
            background-color: #f8f9fa;
        }
        .appeal-card {
            border-left: 4px solid #0d6efd;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <h2 class="mb-4">
                    <i class="bi bi-chat-dots me-2"></i>Appeal Choices
                </h2>
                
                <!-- Show pending refund appeals -->
                <?php if (!empty($pendingRefunds)): ?>
                    <div class="alert alert-warning mb-4">
                        <h5><i class="bi bi-hourglass-split me-2"></i>Pending Refund Requests</h5>
                        <p class="mb-0">Your refund request is being reviewed by admin. You will be notified once it's approved or rejected.</p>
                    </div>
                    <?php foreach ($pendingRefunds as $pending): ?>
                        <div class="card mb-3" style="border-left: 4px solid #ffc107;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1"><?= htmlspecialchars($pending['mountainName']) ?></h6>
                                        <small class="text-muted">
                                            Guider: <?= htmlspecialchars($pending['guiderName']) ?> | 
                                            <?= date('d/m/Y', strtotime($pending['startDate'])) ?> - <?= date('d/m/Y', strtotime($pending['endDate'])) ?>
                                        </small>
                                    </div>
                                    <span class="badge bg-warning text-dark">
                                        <i class="bi bi-clock me-1"></i>Pending Admin Approval
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <hr>
                <?php endif; ?>
                
                <?php if (empty($appeals)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        No appeals awaiting your choice.
                    </div>
                <?php else: ?>
                    <?php foreach ($appeals as $appeal): ?>
                        <div class="card appeal-card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-calendar-event me-2"></i>
                                    Appeal #<?= $appeal['appealID'] ?> - <?= htmlspecialchars($appeal['mountainName']) ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="fw-bold">Booking Details</h6>
                                        <p><strong>Guider:</strong> <?= htmlspecialchars($appeal['guiderName']) ?></p>
                                        <p><strong>Start Date:</strong> <?= date('d/m/Y', strtotime($appeal['startDate'])) ?></p>
                                        <p><strong>End Date:</strong> <?= date('d/m/Y', strtotime($appeal['endDate'])) ?></p>
                                        <p><strong>Price:</strong> RM <?= $appeal['price'] ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="fw-bold">Appeal Reason</h6>
                                        <p class="text-muted"><?= htmlspecialchars($appeal['reason']) ?></p>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <h6 class="fw-bold mb-3">Choose Your Option:</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="card choice-card" onclick="selectChoice(<?= $appeal['appealID'] ?>, 'refund', <?= $appeal['bookingID'] ?>)">
                                            <div class="card-body text-center">
                                                <i class="bi bi-currency-dollar text-warning" style="font-size: 2rem;"></i>
                                                <h5 class="mt-2">Request Refund</h5>
                                                <p class="text-muted">Cancel the booking and get your money back</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="card choice-card" onclick="selectChoice(<?= $appeal['appealID'] ?>, 'change', <?= $appeal['bookingID'] ?>)">
                                            <div class="card-body text-center">
                                                <i class="bi bi-person-plus text-info" style="font-size: 2rem;"></i>
                                                <h5 class="mt-2">Change Guider</h5>
                                                <p class="text-muted">Keep the booking but with a different guider</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-3 text-center">
                                    <button class="btn btn-primary" id="submitBtn_<?= $appeal['appealID'] ?>" onclick="submitChoice(<?= $appeal['appealID'] ?>, <?= $appeal['bookingID'] ?>)" disabled>
                                        <i class="bi bi-check-circle me-1"></i>Submit Choice
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let selectedChoices = {};
        let bookingIds = {};

        function selectChoice(appealId, choice, bookingId) {
            // Remove previous selection for this appeal
            document.querySelectorAll(`[onclick*="selectChoice(${appealId}"]`).forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selection to clicked card
            event.currentTarget.classList.add('selected');
            
            // Store the choice and bookingId
            selectedChoices[appealId] = choice;
            bookingIds[appealId] = bookingId;
            
            // Enable submit button
            document.getElementById(`submitBtn_${appealId}`).disabled = false;
        }

        function submitChoice(appealId, bookingId) {
            const choice = selectedChoices[appealId];
            if (!choice) {
                alert('Please select an option first.');
                return;
            }

            const confirmMsg = choice === 'refund' 
                ? 'Are you sure you want to request a refund? Your request will be sent to admin for approval.'
                : 'Are you sure you want to change guider?';

            if (confirm(confirmMsg)) {
                fetch('../admin/process_appeal.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: choice === 'refund' ? 'hiker_chose_refund' : 'hiker_chose_change',
                        appealId: appealId,
                        bookingId: bookingId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (choice === 'refund') {
                            alert('Your refund request has been submitted. Please wait for admin approval.');
                        } else {
                            alert('Your choice has been submitted successfully!');
                        }
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while submitting your choice.');
                });
            }
        }
    </script>
</body>
</html>

