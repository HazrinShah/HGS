<?php
// TEMPORARY DEBUG - Remove after fixing
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();  
session_start();
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$fp = hash('sha256', $ua . '|' . $ip);
if (!isset($_SESSION['fp'])) {
    $_SESSION['fp'] = $fp;
    if (isset($_SESSION['hikerID'])) { $_SESSION['hikerLock'] = (int)$_SESSION['hikerID']; }
} else {
    if ($_SESSION['fp'] !== $fp || (isset($_SESSION['hikerLock']) && (int)($_SESSION['hikerID'] ?? 0) !== (int)$_SESSION['hikerLock'])) {
        $_SESSION = [];
        session_destroy();
        echo "Session validation failed. Please log in again.";
        exit;
    }
}
include '../shared/db_connection.php';

// create table bookinghikerdetails kalau tak ada (letak kat atas supaya create awal-awal)
$conn->query("CREATE TABLE IF NOT EXISTS bookinghikerdetails (
    hikerDetailID INT AUTO_INCREMENT PRIMARY KEY,
    bookingID INT NOT NULL,
    hikerName VARCHAR(255) NOT NULL,
    identityCard VARCHAR(50) NOT NULL,
    address TEXT NOT NULL,
    phoneNumber VARCHAR(20) NOT NULL,
    emergencyContactName VARCHAR(255) NOT NULL,
    emergencyContactNumber VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bookingID) REFERENCES booking(bookingID) ON DELETE CASCADE,
    INDEX idx_bookingID (bookingID)
) ENGINE=InnoDB");

// session debug: log entry dengan current user dan URL
try {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $fullUrl = $scheme . '://' . $host . $uri;
    error_log("HBooking1.php ENTRY: hikerID=" . ($_SESSION['hikerID'] ?? 'N/A') . " URL=" . $fullUrl);
} catch (Throwable $e) {
    // ignore je
}

if (!isset($_SESSION['hikerID'])) {
    // debug: log session information
    error_log("HBooking1.php - Session hikerID not set. Session data: " . print_r($_SESSION, true));
    echo "Session hikerID not set! Please log in again.";
    exit;
}
$hikerID = $_SESSION['hikerID'];

// debug: log successful session
error_log("HBooking1.php - User logged in with hikerID: $hikerID");
$guiderID = $_GET['guiderID'] ?? $_POST['guiderID'] ?? null;
$startDate = $_POST['startDate'] ?? $_GET['start'] ?? null;
$endDate = $_POST['endDate'] ?? $_GET['end'] ?? null;
$preSelectedMountainID = $_GET['mountainID'] ?? $_POST['mountainID'] ?? null;

// Helper function to build redirect URL with preserved parameters
function buildRedirectUrl($guiderID, $startDate, $endDate, $mountainID = null, $additionalParams = []) {
    $params = [];
    if ($guiderID) $params['guiderID'] = $guiderID;
    if ($startDate) $params['start'] = $startDate;
    if ($endDate) $params['end'] = $endDate;
    if ($mountainID) $params['mountainID'] = $mountainID;
    $params = array_merge($params, $additionalParams);
    return "HBooking1.php?" . http_build_query($params);
}


// ambil guider info untuk pricing
$guider = $conn->prepare("SELECT price FROM guider WHERE guiderID = ?");
$guider->bind_param("i", $guiderID);
$guider->execute();
$guiderResult = $guider->get_result();
$guiderData = $guiderResult->fetch_assoc();
$price = $guiderData['price'] ?? 0;

// check kalau ni open group booking (mountain dah pre-selected)
$isOpenGroupBooking = false;
$openGroupMountain = null;
if ($preSelectedMountainID && $startDate && $endDate) {
    $openGroupStmt = $conn->prepare("
        SELECT 
            m.mountainID, 
            m.name, 
            m.location, 
            m.picture,
            COALESCE(SUM(b.totalHiker), 0) as existingHikers
        FROM mountain m 
        LEFT JOIN booking b ON m.mountainID = b.mountainID 
            AND b.guiderID = ? 
            AND b.groupType = 'open' 
            AND b.startDate <= ? 
            AND b.endDate >= ? 
            AND b.status IN ('pending', 'accepted', 'paid')
        WHERE m.mountainID = ?
        GROUP BY m.mountainID, m.name, m.location, m.picture
    ");
    $openGroupStmt->bind_param("isss", $guiderID, $endDate, $startDate, $preSelectedMountainID);
    $openGroupStmt->execute();
    $openGroupResult = $openGroupStmt->get_result();
    $openGroupMountain = $openGroupResult->fetch_assoc();
    
    // Only set isOpenGroupBooking = true if there are EXISTING hikers in an open group
    // This prevents close group bookings from being treated as open group on error redirect
    if ($openGroupMountain && (int)($openGroupMountain['existingHikers'] ?? 0) > 0) {
        $isOpenGroupBooking = true;
    }
}

if (isset($_POST['book'])) {
    // persist open-group intent across POST (bukan via GET je)
    if ((isset($_POST['groupType']) && $_POST['groupType'] === 'open')) {
        $isOpenGroupBooking = true;
        if (!empty($_POST['mountainID'])) {
            $preSelectedMountainID = $_POST['mountainID'];
        }
    }
    // kalau POST datang dari open-group join flow yang groupType mungkin takde,
    // infer 'open' kalau ada existing open group untuk guider/mountain/date yang sama
    $postMountainId = $_POST['mountainID'] ?? null;
    $postGroupType = $_POST['groupType'] ?? null;
    if (!$isOpenGroupBooking && $postMountainId && (!isset($_POST['groupType']) || $_POST['groupType'] !== 'close')) {
        $probe = $conn->prepare("SELECT 1 FROM booking WHERE guiderID = ? AND mountainID = ? AND groupType = 'open' AND startDate <= ? AND endDate >= ? AND status IN ('pending','accepted','paid') LIMIT 1");
        $probe->bind_param('iiss', $guiderID, $postMountainId, $endDate, $startDate);
        $probe->execute();
        $probeRes = $probe->get_result()->fetch_assoc();
        $probe->close();
        if ($probeRes) {
            $isOpenGroupBooking = true;
            $preSelectedMountainID = $postMountainId;
            error_log("HBooking1.php - Inferred open-group intent from POST for mountainID={$postMountainId}");
        }
    }
    // debug: log semua POST data
    error_log("HBooking1.php - POST data: " . print_r($_POST, true));
    error_log("HBooking1.php - GET data: " . print_r($_GET, true));
    
    // untuk open group bookings, guna pre-selected mountain (fallback ke POST)
    $mountainID = $isOpenGroupBooking ? ($preSelectedMountainID ?: ($_POST['mountainID'] ?? null)) : ($_POST['mountainID'] ?? null);
    
    // dapat hiker details dari POST
    $hikerDetails = [];
    if (isset($_POST['hikerDetails']) && is_array($_POST['hikerDetails'])) {
        foreach ($_POST['hikerDetails'] as $detail) {
            if (!empty($detail['name']) && !empty($detail['identityCard']) && !empty($detail['address']) && 
                !empty($detail['phoneNumber']) && !empty($detail['emergencyContactName']) && !empty($detail['emergencyContactNumber'])) {
                $hikerDetails[] = $detail;
            }
        }
    }
    $totalHiker = count($hikerDetails);
    
    // Validate identity card format (12 digits, numbers only)
    $invalidFormatICs = [];
    foreach ($hikerDetails as $index => $detail) {
        $ic = trim($detail['identityCard']);
        if (empty($ic)) continue;
        
        // Check if IC is exactly 12 digits and contains only numbers
        if (!preg_match('/^\d{12}$/', $ic)) {
            $invalidFormatICs[] = $ic;
        }
    }
    
    // If invalid format found, show error
    if (!empty($invalidFormatICs)) {
        error_log("HBooking1.php - ERROR: Invalid identity card format found: " . implode(', ', $invalidFormatICs));
        $errorMessage = "Invalid Identity Card Format";
        $_SESSION['booking_error'] = $errorMessage;
        $redirectUrl = buildRedirectUrl($guiderID, $_POST['startDate'] ?? null, $_POST['endDate'] ?? null, $preSelectedMountainID ?? null, ['error' => '1']);
        header("Location: " . $redirectUrl);
        exit();
    }
    
    // Validate for duplicate identity cards within submitted hiker details
    $identityCards = [];
    $duplicateICs = [];
    foreach ($hikerDetails as $index => $detail) {
        $ic = trim($detail['identityCard']);
        if (empty($ic)) continue;
        
        // Check for duplicates within the form
        if (in_array($ic, $identityCards)) {
            if (!in_array($ic, $duplicateICs)) {
                $duplicateICs[] = $ic;
            }
        } else {
            $identityCards[] = $ic;
        }
    }
    
    // If duplicates found within form, show error
    if (!empty($duplicateICs)) {
        error_log("HBooking1.php - ERROR: Duplicate identity cards found in form: " . implode(', ', $duplicateICs));
        $errorMessage = "Duplicate Identity Card";
        $_SESSION['booking_error'] = $errorMessage;
        $redirectUrl = buildRedirectUrl($guiderID, $_POST['startDate'] ?? null, $_POST['endDate'] ?? null, $preSelectedMountainID ?? null, ['error' => '1']);
        header("Location: " . $redirectUrl);
        exit();
    }
    
    // NOTE: Duplicate IC check is ONLY within the same booking (above code)
    // Same IC can be used in different bookings (e.g., same person booking multiple trips)
    
    // kalau user select open atau inferred open, force 'open' dan jangan fall back ke 'close'
    $groupType = $isOpenGroupBooking ? 'open' : ($_POST['groupType'] ?? 'close');
    $startDate = $_POST['startDate'] ?? date('Y-m-d');
    $endDate = $_POST['endDate'] ?? date('Y-m-d');
    $status = "pending";

    // debug: log nilai mountainID
    error_log("HBooking1.php - MountainID: " . ($mountainID ?? 'NULL') . ", isOpenGroupBooking: " . ($isOpenGroupBooking ? 'true' : 'false') . ", preSelectedMountainID: " . ($preSelectedMountainID ?? 'NULL') . ", POST mountainID: " . ($_POST['mountainID'] ?? 'NULL'));

    // Validate required fields
    if (empty($mountainID)) {
        error_log("HBooking1.php - ERROR: MountainID is empty or NULL!");
        echo "<div style='background: #fee; border: 1px solid #fcc; padding: 20px; margin: 20px; border-radius: 5px;'>";
        echo "<h3 style='color: #c33;'>Booking Error</h3>";
        echo "<p>Please select a mountain for your booking. Go back and choose a hiking location.</p>";
        echo "<a href='javascript:history.back()' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go Back</a>";
        echo "</div>";
        exit();
    }
    
    if (empty($totalHiker) || $totalHiker < 1 || $totalHiker > 7) {
        error_log("HBooking1.php - ERROR: Invalid totalHiker value: " . ($totalHiker ?? 'NULL'));
        echo "<div style='background: #fee; border: 1px solid #fcc; padding: 20px; margin: 20px; border-radius: 5px;'>";
        echo "<h3 style='color: #c33;'>Booking Error</h3>";
        echo "<p>Please add at least one hiker with complete details (1-7 hikers maximum).</p>";
        echo "<a href='javascript:history.back()' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go Back</a>";
        echo "</div>";
        exit();
    }

    $mountainInfo = $conn->prepare("SELECT name, location FROM mountain WHERE mountainID = ?");
    $mountainInfo->bind_param("i", $mountainID);
    $mountainInfo->execute();
    $mountainResult = $mountainInfo->get_result()->fetch_assoc();
    
    if (!$mountainResult) {
        error_log("HBooking1.php - ERROR: Mountain not found with ID: " . $mountainID);
        echo "Error: Selected mountain not found.";
        exit();
    }
    
    if ($groupType === 'open') {
        // If an open group already exists for these dates with this guider, force join that group (no new parallel group)
        // IMPORTANT: restrict to the same mountain to avoid cross-mountain joins
        $existingOpen = $conn->prepare("
            SELECT bookingID, mountainID AS openMountainID, startDate AS openStart, endDate AS openEnd, created_at AS groupStart
            FROM booking
            WHERE guiderID = ?
              AND mountainID = ?
              AND groupType = 'open'
              AND startDate <= ?
              AND endDate >= ?
              AND status IN ('pending','accepted','paid')
            ORDER BY created_at ASC
            LIMIT 1
        ");
        $existingOpen->bind_param('iiss', $guiderID, $mountainID, $endDate, $startDate);
        $existingOpen->execute();
        $openRow = $existingOpen->get_result()->fetch_assoc();
        $existingOpen->close();

        if ($openRow) {
            // check forming window dan capacity
            $groupStartTs = $openRow['groupStart'] ? strtotime($openRow['groupStart']) : time();
            $recruitDeadline = $groupStartTs + (3 * 60);
            $capStmt = $conn->prepare("
                SELECT COALESCE(b.totalHiker,0) AS totalHikers
                FROM booking b
                WHERE b.bookingID = ?
            ");
            $capStmt->bind_param('i', $openRow['bookingID']);
            $capStmt->execute();
            $cap = $capStmt->get_result()->fetch_assoc();
            $capStmt->close();

            $currentSize = (int)($cap['totalHikers'] ?? 0);
            $formingOpen = (time() < $recruitDeadline) && ($currentSize < 7);

            if ($formingOpen) {
                // join ke existing open booking via bookingParticipant, update booking.totalHiker
                // pastikan participants table ada
                $conn->query("CREATE TABLE IF NOT EXISTS bookingparticipant (
                    bookingID INT NOT NULL,
                    hikerID INT NOT NULL,
                    qty INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (bookingID, hikerID)
                ) ENGINE=InnoDB");

                $joinQty = (int)$totalHiker;
                $bookingIDAnchor = (int)$openRow['bookingID'];

                // capacity check terhadap anchor booking totalHiker
                if (($currentSize + $joinQty) > 7) {
                    $seatsLeft = max(0, 7 - $currentSize);
                    echo "<div style='background:#fee;border:1px solid #fcc;padding:20px;margin:20px;border-radius:5px;'>";
                    echo "<h3 style='color:#c33;'>Open Group Capacity Reached</h3>";
                    echo "<p>Only {$seatsLeft} seat(s) left for this open group on the selected dates. Please adjust the number of hikers or choose another date.</p>";
                    echo "<a href='javascript:history.back()' style='background:#007bff;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;'>Go Back</a>";
                    echo "</div>";
                    exit();
                }

                // Upsert participant row
                $upsert = $conn->prepare("INSERT INTO bookingparticipant (bookingID, hikerID, qty)
                                          VALUES (?, ?, ?)
                                          ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)");
                $upsert->bind_param('iii', $bookingIDAnchor, $hikerID, $joinQty);
                $upsert->execute();
                $upsert->close();
                
                // Validate identity card format (12 digits, numbers only) for joining open group
                $joinInvalidFormatICs = [];
                foreach ($hikerDetails as $detail) {
                    $ic = trim($detail['identityCard']);
                    if (empty($ic)) continue;
                    
                    // Check if IC is exactly 12 digits and contains only numbers
                    if (!preg_match('/^\d{12}$/', $ic)) {
                        $joinInvalidFormatICs[] = $ic;
                    }
                }
                
                // If invalid format found, show error
                if (!empty($joinInvalidFormatICs)) {
                    error_log("HBooking1.php - ERROR: Invalid identity card format found when joining open group: " . implode(', ', $joinInvalidFormatICs));
                    $errorMessage = "Invalid Identity Card Format";
                    $_SESSION['booking_error'] = $errorMessage;
                    $redirectUrl = buildRedirectUrl($guiderID, $_POST['startDate'] ?? null, $_POST['endDate'] ?? null, $preSelectedMountainID ?? null, ['error' => '1']);
                    header("Location: " . $redirectUrl);
                    exit();
                }
                
                // Validate identity cards before inserting for joining open group
                $joinIdentityCards = array_column($hikerDetails, 'identityCard');
                if (!empty($joinIdentityCards)) {
                    $joinPlaceholders = str_repeat('?,', count($joinIdentityCards) - 1) . '?';
                    $checkJoinDuplicateStmt = $conn->prepare("SELECT identityCard FROM bookinghikerdetails WHERE identityCard IN ($joinPlaceholders)");
                    $checkJoinDuplicateStmt->bind_param(str_repeat('s', count($joinIdentityCards)), ...$joinIdentityCards);
                    $checkJoinDuplicateStmt->execute();
                    $existingJoinICs = $checkJoinDuplicateStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $checkJoinDuplicateStmt->close();
                    
                    if (!empty($existingJoinICs)) {
                        $existingJoinICList = array_column($existingJoinICs, 'identityCard');
                        error_log("HBooking1.php - ERROR: Identity cards already exist when joining open group: " . implode(', ', $existingJoinICList));
                        $errorMessage = "Duplicate Identity Card";
                        $_SESSION['booking_error'] = $errorMessage;
                        $redirectUrl = buildRedirectUrl($guiderID, $_POST['startDate'] ?? null, $_POST['endDate'] ?? null, $preSelectedMountainID ?? null, ['error' => '1']);
                        header("Location: " . $redirectUrl);
                        exit();
                    }
                }
                
                // Insert hiker details for joining open group
                $hikerDetailStmt = $conn->prepare("INSERT INTO bookinghikerdetails (bookingID, hikerName, identityCard, address, phoneNumber, emergencyContactName, emergencyContactNumber) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $joinInsertError = false;
                $joinDuplicateIC = '';
                foreach ($hikerDetails as $detail) {
                    $hikerDetailStmt->bind_param('issssss', $bookingIDAnchor, $detail['name'], $detail['identityCard'], $detail['address'], $detail['phoneNumber'], $detail['emergencyContactName'], $detail['emergencyContactNumber']);
                    if (!$hikerDetailStmt->execute()) {
                        // Check if error is due to duplicate identity card
                        if ($conn->errno == 1062) { // MySQL duplicate entry error
                            $joinInsertError = true;
                            $joinDuplicateIC = $detail['identityCard'];
                            break;
                        } else {
                            error_log("HBooking1.php - Error inserting hiker detail for open group: " . $conn->error);
                            $joinInsertError = true;
                            break;
                        }
                    }
                }
                $hikerDetailStmt->close();
                
                // If insert failed, rollback participant update
                if ($joinInsertError) {
                    // Remove participant if it was added
                    $removeParticipant = $conn->prepare("DELETE FROM bookingparticipant WHERE bookingID = ? AND hikerID = ?");
                    $removeParticipant->bind_param('ii', $bookingIDAnchor, $hikerID);
                    $removeParticipant->execute();
                    $removeParticipant->close();
                    
                    if (!empty($joinDuplicateIC)) {
                        $_SESSION['booking_error'] = "Duplicate Identity Card";
                        $redirectUrl = buildRedirectUrl($guiderID, $_POST['startDate'] ?? null, $_POST['endDate'] ?? null, $preSelectedMountainID ?? null, ['error' => '1']);
                        header("Location: " . $redirectUrl);
                        exit();
                    } else {
                        $_SESSION['booking_error'] = "An error occurred while joining the open group. Please try again.";
                        $redirectUrl = buildRedirectUrl($guiderID, $_POST['startDate'] ?? null, $_POST['endDate'] ?? null, $preSelectedMountainID ?? null, ['error' => '1']);
                        header("Location: " . $redirectUrl);
                        exit();
                    }
                }

                // update booking totalHiker atomically
                $updTot = $conn->prepare("UPDATE booking SET totalHiker = totalHiker + ? WHERE bookingID = ?");
                $updTot->bind_param('ii', $joinQty, $bookingIDAnchor);
                $updTot->execute();
                $updTot->close();

                // redirect terus ke payment; jangan create booking baru
                error_log("HBooking1.php - Joined existing open bookingID={$bookingIDAnchor} by hikerID={$hikerID} qty={$joinQty}");
                header("Location: HPayment.php");
                exit();
            } else {
                echo "<div style='background:#fee;border:1px solid #fcc;padding:20px;margin:20px;border-radius:5px;'>";
                echo "<h3 style='color:#c33;'>Open Group Unavailable</h3>";
                echo "<p>There is an existing open group for the selected guider and dates, but it is either closed or full. Please choose another date.</p>";
                echo "<a href='javascript:history.back()' style='background:#007bff;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;'>Go Back</a>";
                echo "</div>";
                exit();
            }
        }
    }

    if ($groupType === 'close') {
        // Close group: flat price for the whole group regardless of size
        $totalPrice = $price;
    } else {
        // Open group: enforce 24h window or max 7, and dynamic per-person pricing
        // Count existing participants including pending (to reserve seats), accepted, and paid
        $existingStmt = $conn->prepare("
            SELECT
              COALESCE(SUM(b.totalHiker), 0) as existingHikers,
              MIN(b.created_at) as groupStart
            FROM booking b
            WHERE b.mountainID = ?
              AND b.guiderID = ?
              AND b.groupType = 'open'
              AND b.startDate <= ?
              AND b.endDate >= ?
              AND b.status IN ('pending', 'accepted', 'paid')
        ");
        $existingStmt->bind_param("iiss", $mountainID, $guiderID, $endDate, $startDate);
        $existingStmt->execute();
        $existingRes = $existingStmt->get_result()->fetch_assoc();
        $existingHikers = (int)($existingRes['existingHikers'] ?? 0);
        $groupStart = $existingRes['groupStart'] ? strtotime($existingRes['groupStart']) : time();

        // Determine if the group is already closed (either reached 7 or exceeded 3 minutes since first booking)
        $nowTs = time();
        $deadlineTs = $groupStart + (3 * 60); // 3 minutes to form group
        $isClosed = ($existingHikers >= 7) || ($nowTs >= $deadlineTs);

        if ($isClosed) {
            echo "<div style='background: #fee; border: 1px solid #fcc; padding: 20px; margin: 20px; border-radius: 5px;'>";
            echo "<h3 style='color: #c33;'>Open Group Closed</h3>";
            echo "<p>This open group is closed (either 3 minutes passed or full with 7 hikers). Please choose Close Group or different dates.</p>";
            echo "<a href='javascript:history.back()' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go Back</a>";
            echo "</div>";
            exit();
        }

        $groupSizeAfter = $existingHikers + (int)$totalHiker;
        if ($groupSizeAfter > 7) {
            // Prevent overbooking in open group
            $seatsLeft = max(0, 7 - $existingHikers);
            echo "<div style='background: #fee; border: 1px solid #fcc; padding: 20px; margin: 20px; border-radius: 5px;'>";
            echo "<h3 style='color: #c33;'>Open Group Capacity Reached</h3>";
            echo "<p>Only {$seatsLeft} seat(s) left for this open group on the selected dates. Please adjust the number of hikers or choose Close Group.</p>";
            echo "<a href='javascript:history.back()' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go Back</a>";
            echo "</div>";
            exit();
        }

        // Provisional total for summary; final amount will be determined at payment after the group closes
        $perPersonPrice = $price / max($groupSizeAfter, 1);
        $totalPrice = $perPersonPrice * (int)$totalHiker;
    }

    $location = $mountainResult['name'] . ", " . $mountainResult['location'];

    // Prevent duplicate pending open-group bookings for same hiker/guider/date
    if ($groupType === 'open') {
        $dup = $conn->prepare("SELECT bookingID FROM booking WHERE hikerID = ? AND guiderID = ? AND mountainID = ? AND groupType = 'open' AND startDate <= ? AND endDate >= ? AND status = 'pending' LIMIT 1");
        $dup->bind_param("iiiss", $hikerID, $guiderID, $mountainID, $endDate, $startDate);
        $dup->execute();
        $dupRes = $dup->get_result()->fetch_assoc();
        $dup->close();
        if ($dupRes) {
            error_log("HBooking1.php - Duplicate pending open booking exists. Redirecting to HPayment. hikerID=$hikerID guiderID=$guiderID mountainID=$mountainID");
            header("Location: HPayment.php");
            exit();
        }
    }

    $insert = $conn->prepare("INSERT INTO booking (startDate, endDate, totalHiker, groupType, location, price, status, hikerID, guiderID, mountainID)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insert->bind_param("ssissdsiii", $startDate, $endDate, $totalHiker, $groupType, $location, $totalPrice, $status, $hikerID, $guiderID, $mountainID);

    if ($insert->execute()) {
        $bookingID = $conn->insert_id;
        
        error_log("HBooking1.php - Booking created successfully. BookingID: $bookingID, HikerID: $hikerID, GuiderID: $guiderID, MountainID: $mountainID, Status: pending");
        
        // Insert hiker details
        $hikerDetailStmt = $conn->prepare("INSERT INTO bookinghikerdetails (bookingID, hikerName, identityCard, address, phoneNumber, emergencyContactName, emergencyContactNumber) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insertError = false;
        $duplicateIC = '';
        foreach ($hikerDetails as $detail) {
            $hikerDetailStmt->bind_param('issssss', $bookingID, $detail['name'], $detail['identityCard'], $detail['address'], $detail['phoneNumber'], $detail['emergencyContactName'], $detail['emergencyContactNumber']);
            if (!$hikerDetailStmt->execute()) {
                // Check if error is due to duplicate identity card
                if ($conn->errno == 1062) { // MySQL duplicate entry error
                    $insertError = true;
                    $duplicateIC = $detail['identityCard'];
                    break;
                } else {
                    error_log("HBooking1.php - Error inserting hiker detail: " . $conn->error);
                    $insertError = true;
                    break;
                }
            }
        }
        $hikerDetailStmt->close();
        
        // If insert failed due to duplicate, rollback and show error
        if ($insertError && !empty($duplicateIC)) {
            // Delete the booking that was created
            $rollbackStmt = $conn->prepare("DELETE FROM booking WHERE bookingID = ?");
            $rollbackStmt->bind_param('i', $bookingID);
            $rollbackStmt->execute();
            $rollbackStmt->close();
            
            $_SESSION['booking_error'] = "Duplicate Identity Card";
            $redirectUrl = buildRedirectUrl($guiderID, $_POST['startDate'] ?? null, $_POST['endDate'] ?? null, $preSelectedMountainID ?? null, ['error' => '1']);
            header("Location: " . $redirectUrl);
            exit();
        } else if ($insertError) {
            $_SESSION['booking_error'] = "An error occurred while saving hiker details. Please try again.";
            $redirectUrl = buildRedirectUrl($guiderID, $_POST['startDate'] ?? null, $_POST['endDate'] ?? null, $preSelectedMountainID ?? null, ['error' => '1']);
            header("Location: " . $redirectUrl);
            exit();
        }
        
        // untuk new open-group booking, create participant row untuk creator jugak
        if ($groupType === 'open') {
            $conn->query("CREATE TABLE IF NOT EXISTS bookingparticipant (
                bookingID INT NOT NULL,
                hikerID INT NOT NULL,
                qty INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (bookingID, hikerID)
            ) ENGINE=InnoDB");
            $insP = $conn->prepare("INSERT INTO bookingparticipant (bookingID, hikerID, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty)");
            $insP->bind_param('iii', $bookingID, $hikerID, $totalHiker);
            $insP->execute();
            $insP->close();
        }

        $_SESSION['booking_success'] = true;
        $_SESSION['booking_summary'] = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'location' => $location,
            'totalHiker' => $totalHiker,
            'groupType' => $groupType,
            'totalPrice' => $totalPrice
        ];
        // Log before redirect to help detect session flips
        error_log("HBooking1.php REDIRECT: hikerID=" . ($_SESSION['hikerID'] ?? 'N/A') . " to HPayment.php");
        
        // Clear output buffer and redirect
        ob_end_clean();
        header("Location: HPayment.php");
        exit();
    } else {
        error_log("HBooking1.php - Booking insertion failed: " . $insert->error);
        echo "Booking failed: " . $insert->error;
        exit();
    }

}

// ambil semua mountains
$mountainQuery = "SELECT * FROM mountain";
$mountainResult = $conn->query($mountainQuery);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hiker Booking</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css" />
    <link rel="stylesheet" href="../css/style.css" />

    <style>
    body {
      font-family: "Montserrat", sans-serif;
      background-color: #f8fafc;
    }
    
    /* Guider Blue Color Scheme - Matching HBooking */
    :root {
      --guider-blue: #1e40af;
      --guider-blue-light: #3b82f6;
      --guider-blue-dark: #1e3a8a;
      --guider-blue-accent: #60a5fa;
      --guider-blue-soft: #dbeafe;
    }

    .navbar {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue)) !important;
      box-shadow: 0 4px 20px rgba(30, 64, 175, 0.3);
    }

    .navbar-toggler-icon {
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='white' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
    }

    .logo {
      width: 45px;
      border-radius: 50%;
      transition: transform 0.3s ease;
    }

    .logo:hover {
      transform: scale(1.05);
    }

    /* Main Content Container - Matching HBooking style */
    .main-content {
      padding: 2rem 0;
      min-height: 100vh;
    }

    /* Section Header - Matching HBooking style */
    .section-header {
      background: white;
      border-radius: 20px;
      padding: 2rem;
      box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
      border: 1px solid rgba(30, 64, 175, 0.1);
      margin-bottom: 2rem;
      text-align: center;
    }

    .section-title {
      color: var(--guider-blue-dark);
      font-weight: 700;
      margin-bottom: 1rem;
      font-size: 2rem;
    }

    .section-subtitle {
      color: #64748b;
      font-size: 1.1rem;
      margin-bottom: 0;
    }

    /* Checkout Container - Matching HBooking card style */
    .checkout-container {
      background: white;
      border-radius: 20px;
      padding: 2rem;
      box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
      border: 1px solid rgba(30, 64, 175, 0.1);
      margin-bottom: 2rem;
    }

    .checkout-title {
      color: var(--guider-blue-dark);
      font-weight: 700;
      margin-bottom: 1.5rem;
      font-size: 1.5rem;
    }

    .scroll-mountain-container {
      max-height: 400px;
      overflow-y: auto;
      padding-right: 10px;
    }

    /* Mountain Cards - Matching HBooking card style */
    .mountain-card {
      background: white;
      border: 2px solid var(--guider-blue-soft);
      border-radius: 15px;
      padding: 1.5rem;
      transition: all 0.3s ease;
      cursor: pointer;
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .mountain-card:hover {
      border-color: var(--guider-blue);
      background: var(--guider-blue-soft);
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(30, 64, 175, 0.2);
    }

    .mountain-card.selected {
      border-color: var(--guider-blue);
      background: var(--guider-blue-soft);
    }

    .mountain-img {
      width: 80px;
      height: 80px;
      object-fit: cover;
      border-radius: 12px;
      border: 3px solid var(--guider-blue-soft);
      transition: all 0.3s ease;
    }

    .mountain-card:hover .mountain-img {
      border-color: var(--guider-blue);
      transform: scale(1.05);
    }

    .mountain-info h6 {
      color: var(--guider-blue-dark);
      font-weight: 600;
      margin-bottom: 0.25rem;
      font-size: 1.1rem;
    }

    .mountain-info small {
      color: #64748b;
      font-size: 0.9rem;
    }

    /* Form Styling - Matching HBooking */
    .form-label {
      color: var(--guider-blue-dark);
      font-weight: 600;
      margin-bottom: 0.75rem;
    }

    .form-control {
      border: 2px solid var(--guider-blue-soft);
      border-radius: 12px;
      padding: 12px 16px;
      transition: all 0.3s ease;
    }

    .form-control:focus {
      border-color: var(--guider-blue);
      box-shadow: 0 0 0 0.2rem rgba(30, 64, 175, 0.25);
    }

    /* Radio Button Styling */
    input[type="radio"] {
      transform: scale(1.5);
      accent-color: var(--guider-blue);
    }

    /* Book Button - Matching HBooking button style */
    .book-btn {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      border: none;
      border-radius: 12px;
      padding: 12px 30px;
      font-weight: 600;
      color: white;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(30, 64, 175, 0.3);
    }

    .book-btn:hover:not(:disabled) {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
      color: white;
    }

    .book-btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }

        /* Responsive Design */
        @media (max-width: 768px) {
          .section-title {
            font-size: 1.75rem;
          }
          
          .checkout-container {
            padding: 1.5rem;
          }
          
          .mountain-card {
            flex-direction: column;
            text-align: center;
            gap: 0.75rem;
          }
          
          .mountain-img {
            width: 60px;
            height: 60px;
          }
        }

        /* Custom Notification System */
        .notification-container {
          position: fixed;
          bottom: 20px;
          right: 20px;
          z-index: 9999;
          max-width: 400px;
        }

        .notification {
          background: white;
          border-radius: 12px;
          box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
          margin-bottom: 12px;
          padding: 16px 20px;
          display: flex;
          align-items: center;
          gap: 12px;
          border-left: 4px solid;
          transform: translateX(100%);
          opacity: 0;
          transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
          position: relative;
          overflow: hidden;
        }

        .notification.show {
          transform: translateX(0);
          opacity: 1;
        }

        .notification.hide {
          transform: translateX(100%);
          opacity: 0;
        }

        .notification.success {
          border-left-color: #10b981;
          background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
        }

        .notification.error {
          border-left-color: #ef4444;
          background: linear-gradient(135deg, #fef2f2, #fee2e2);
        }

        .notification.warning {
          border-left-color: #f59e0b;
          background: linear-gradient(135deg, #fffbeb, #fef3c7);
        }

        .notification.info {
          border-left-color: var(--guider-blue);
          background: linear-gradient(135deg, var(--guider-blue-soft), #e0f2fe);
        }

        .notification-icon {
          width: 24px;
          height: 24px;
          display: flex;
          align-items: center;
          justify-content: center;
          border-radius: 50%;
          flex-shrink: 0;
        }

        .notification.success .notification-icon {
          background: #10b981;
          color: white;
        }

        .notification.error .notification-icon {
          background: #ef4444;
          color: white;
        }

        .notification.warning .notification-icon {
          background: #f59e0b;
          color: white;
        }

        .notification.info .notification-icon {
          background: var(--guider-blue);
          color: white;
        }

        .notification-content {
          flex: 1;
          min-width: 0;
        }

        .notification-title {
          font-weight: 600;
          font-size: 14px;
          margin: 0 0 4px 0;
          color: #1f2937;
        }

        .notification-message {
          font-size: 13px;
          margin: 0;
          color: #6b7280;
          line-height: 1.4;
        }

        .notification-close {
          background: none;
          border: none;
          color: #9ca3af;
          cursor: pointer;
          padding: 4px;
          border-radius: 4px;
          transition: all 0.2s ease;
          flex-shrink: 0;
        }

        .notification-close:hover {
          background: rgba(0, 0, 0, 0.1);
          color: #374151;
        }

        .notification-progress {
          position: absolute;
          bottom: 0;
          left: 0;
          height: 3px;
          background: rgba(0, 0, 0, 0.1);
          border-radius: 0 0 12px 12px;
          transition: width linear;
        }

        .notification.success .notification-progress {
          background: #10b981;
        }

        .notification.error .notification-progress {
          background: #ef4444;
        }

        .notification.warning .notification-progress {
          background: #f59e0b;
        }

        .notification.info .notification-progress {
          background: var(--guider-blue);
        }

        /* Mobile Responsive for Notifications */
        @media (max-width: 768px) {
          .notification-container {
            bottom: 10px;
            right: 10px;
            left: 10px;
            max-width: none;
          }
          
          .notification {
            margin-bottom: 8px;
            padding: 12px 16px;
          }
        }

        /* Date Summary Styles */
        .date-summary-container {
          background: white;
          border-radius: 15px;
          padding: 1.5rem;
          margin-bottom: 2rem;
          box-shadow: 0 5px 15px rgba(30, 64, 175, 0.1);
          border: 1px solid var(--guider-blue-soft);
        }

        .date-summary-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 1rem;
          padding-bottom: 0.75rem;
          border-bottom: 2px solid var(--guider-blue-soft);
        }

        .date-summary-title {
          color: var(--guider-blue-dark);
          font-weight: 700;
          font-size: 1.25rem;
          margin: 0;
        }


        .date-summary-content {
          display: grid;
          grid-template-columns: 1fr 1fr;
          gap: 1rem;
        }

        .date-item {
          background: var(--guider-blue-soft);
          padding: 1rem;
          border-radius: 10px;
          border-left: 4px solid var(--guider-blue);
        }

        .date-label {
          font-size: 0.875rem;
          color: var(--guider-blue-dark);
          font-weight: 600;
          margin-bottom: 0.5rem;
          display: flex;
          align-items: center;
          gap: 0.5rem;
        }

        .date-value {
          font-size: 1.125rem;
          color: #1f2937;
          font-weight: 700;
        }

        /* Visual Calendar Styles */
        .calendar-container {
          background: white;
          border-radius: 15px;
          padding: 1.5rem;
          box-shadow: 0 5px 15px rgba(30, 64, 175, 0.1);
          border: 1px solid var(--guider-blue-soft);
        }

        .calendar-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 1rem;
          padding-bottom: 0.75rem;
          border-bottom: 2px solid var(--guider-blue-soft);
        }

        .calendar-title {
          color: var(--guider-blue-dark);
          font-weight: 700;
          font-size: 1.25rem;
          margin: 0;
        }

        .calendar-nav {
          display: flex;
          gap: 0.5rem;
        }

        .calendar-nav-btn {
          background: var(--guider-blue);
          border: none;
          color: white;
          width: 35px;
          height: 35px;
          border-radius: 8px;
          display: flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
          transition: all 0.3s ease;
        }

        .calendar-nav-btn:hover {
          background: var(--guider-blue-dark);
          transform: scale(1.05);
        }

        .calendar-nav-btn:disabled {
          background: #e5e7eb;
          color: #9ca3af;
          cursor: not-allowed;
          transform: none;
        }

        .calendar-grid {
          display: grid;
          grid-template-columns: repeat(7, 1fr);
          gap: 2px;
          margin-bottom: 1rem;
        }

        .calendar-day-header {
          background: var(--guider-blue-soft);
          color: var(--guider-blue-dark);
          font-weight: 600;
          font-size: 0.875rem;
          padding: 0.75rem 0.5rem;
          text-align: center;
          border-radius: 8px;
        }

        .calendar-day {
          background: #f8fafc;
          border: 2px solid transparent;
          color: #374151;
          font-weight: 500;
          padding: 0.75rem 0.5rem;
          text-align: center;
          cursor: pointer;
          transition: all 0.3s ease;
          border-radius: 8px;
          position: relative;
          min-height: 45px;
          display: flex;
          align-items: center;
          justify-content: center;
        }

        .calendar-day:hover {
          background: var(--guider-blue-soft);
          border-color: var(--guider-blue);
          transform: scale(1.05);
        }

        .calendar-day.available {
          background: #dcfce7;
          color: #166534;
          border-color: #22c55e;
        }

        .calendar-day.available:hover {
          background: #bbf7d0;
          border-color: #16a34a;
        }

        .calendar-day.unavailable {
          background: #fef2f2;
          color: #dc2626;
          border-color: #ef4444;
          cursor: not-allowed;
        }

        .calendar-day.unavailable:hover {
          background: #fee2e2;
          transform: none;
        }

        .calendar-day.other-month {
          background: #f3f4f6;
          color: #9ca3af;
          cursor: not-allowed;
        }

        .calendar-day.other-month:hover {
          background: #f3f4f6;
          transform: none;
          border-color: transparent;
        }

        .calendar-day.selected {
          background: var(--guider-blue);
          color: white;
          border-color: var(--guider-blue-dark);
          font-weight: 700;
        }

        .calendar-day.selected:hover {
          background: var(--guider-blue-dark);
        }

        .calendar-day.today {
          background: #fef3c7;
          color: #92400e;
          border-color: #f59e0b;
          font-weight: 700;
        }

        .calendar-day.today.available {
          background: #dcfce7;
          color: #166534;
          border-color: #22c55e;
        }

        .calendar-day.today.unavailable {
          background: #fef2f2;
          color: #dc2626;
          border-color: #ef4444;
        }

        .calendar-legend {
          display: flex;
          justify-content: center;
          gap: 1.5rem;
          margin-top: 1rem;
          padding-top: 1rem;
          border-top: 2px solid var(--guider-blue-soft);
        }

        .legend-item {
          display: flex;
          align-items: center;
          gap: 0.5rem;
          font-size: 0.875rem;
          font-weight: 500;
        }

        .legend-color {
          width: 16px;
          height: 16px;
          border-radius: 4px;
          border: 2px solid;
        }

        .legend-available {
          background: #dcfce7;
          border-color: #22c55e;
        }

        .legend-unavailable {
          background: #fef2f2;
          border-color: #ef4444;
        }

        .legend-selected {
          background: var(--guider-blue);
          border-color: var(--guider-blue-dark);
        }

        .calendar-loading {
          display: flex;
          justify-content: center;
          align-items: center;
          padding: 2rem;
          color: var(--guider-blue);
        }

        .calendar-loading i {
          animation: spin 1s linear infinite;
          font-size: 1.5rem;
        }

        /* Mobile Responsive Calendar */
        @media (max-width: 768px) {
          .date-summary-container {
            padding: 1rem;
          }
          
          .date-summary-content {
            grid-template-columns: 1fr;
            gap: 0.75rem;
          }
          
          .calendar-container {
            padding: 1rem;
          }
          
          .calendar-day {
            padding: 0.5rem 0.25rem;
            min-height: 40px;
            font-size: 0.875rem;
          }
          
          .calendar-legend {
            flex-direction: column;
            gap: 0.75rem;
            align-items: center;
          }
        }

        /* Booking Success Notification */
        .booking-success-notification {
          position: fixed;
          top: 20px;
          right: 20px;
          background: white;
          border-radius: 15px;
          box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
          padding: 1.5rem;
          max-width: 400px;
          z-index: 9999;
          border-left: 5px solid #10b981;
          transform: translateX(100%);
          opacity: 0;
          transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }

        .booking-success-notification.show {
          transform: translateX(0);
          opacity: 1;
        }

        .booking-success-header {
          display: flex;
          align-items: center;
          margin-bottom: 1rem;
        }

        .booking-success-icon {
          width: 40px;
          height: 40px;
          background: linear-gradient(135deg, #10b981, #059669);
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          margin-right: 12px;
          box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .booking-success-icon i {
          color: white;
          font-size: 1.2rem;
        }

        .booking-success-title {
          font-weight: 700;
          color: #059669;
          font-size: 1.1rem;
          margin: 0;
        }

        .booking-summary {
          background: #f0fdf4;
          border-radius: 10px;
          padding: 1rem;
          margin-bottom: 1rem;
        }

        .booking-summary h6 {
          color: var(--guider-blue-dark);
          font-weight: 600;
          margin-bottom: 0.5rem;
          font-size: 0.9rem;
        }

        .booking-details {
          display: grid;
          grid-template-columns: 1fr 1fr;
          gap: 0.5rem;
          font-size: 0.85rem;
        }

        .booking-detail {
          color: #374151;
        }

        .booking-detail strong {
          color: var(--guider-blue-dark);
        }

        .booking-actions {
          display: flex;
          gap: 0.75rem;
        }

        .booking-btn {
          flex: 1;
          padding: 0.75rem 1rem;
          border-radius: 10px;
          font-weight: 600;
          font-size: 0.9rem;
          text-decoration: none;
          text-align: center;
          transition: all 0.3s ease;
          border: none;
          cursor: pointer;
        }

        .booking-btn-primary {
          background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
          color: white;
          box-shadow: 0 4px 15px rgba(30, 64, 175, 0.3);
        }

        .booking-btn-primary:hover {
          background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
          transform: translateY(-2px);
          box-shadow: 0 6px 20px rgba(30, 64, 175, 0.4);
          color: white;
        }

        .booking-btn-secondary {
          background: linear-gradient(135deg, #f8fafc, #e2e8f0);
          color: var(--guider-blue-dark);
          border: 2px solid var(--guider-blue-soft);
        }

        .booking-btn-secondary:hover {
          background: linear-gradient(135deg, var(--guider-blue-soft), #dbeafe);
          transform: translateY(-2px);
          color: var(--guider-blue-dark);
        }

        .booking-close {
          position: absolute;
          top: 10px;
          right: 10px;
          background: none;
          border: none;
          color: #9ca3af;
          cursor: pointer;
          font-size: 1.2rem;
          padding: 0;
          width: 24px;
          height: 24px;
          display: flex;
          align-items: center;
          justify-content: center;
          border-radius: 50%;
          transition: all 0.2s ease;
        }

        .booking-close:hover {
          background: rgba(0, 0, 0, 0.1);
          color: #374151;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
          .booking-success-notification {
            top: 10px;
            right: 10px;
            left: 10px;
            max-width: none;
          }
          
          .booking-details {
            grid-template-columns: 1fr;
          }
          
          .booking-actions {
            flex-direction: column;
          }
        }

        /* Success Page Styles */
        .success-icon-large {
          animation: successPulse 2s ease-in-out infinite;
        }

        @keyframes successPulse {
          0% { transform: scale(1); }
          50% { transform: scale(1.05); }
          100% { transform: scale(1); }
        }

        .btn-primary:hover {
          background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue)) !important;
          transform: translateY(-3px) !important;
          box-shadow: 0 12px 35px rgba(30, 64, 175, 0.4) !important;
        }

        .btn-outline-primary:hover {
          background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light)) !important;
          color: white !important;
          border-color: var(--guider-blue) !important;
          transform: translateY(-3px) !important;
          box-shadow: 0 12px 35px rgba(30, 64, 175, 0.4) !important;
        }

        .booking-detail-item {
          padding: 0.5rem 0;
        }

        .booking-detail-item strong {
          display: block;
          margin-bottom: 0.25rem;
        }

        /* Group Type Selection Styles */
        .group-type-container {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .group-type-option {
            flex: 1;
        }

        .group-type-option input[type="radio"] {
            display: none;
        }

        .group-type-label {
            cursor: pointer;
            display: block;
        }

        .group-type-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
            height: 100%;
        }

        .group-type-card i {
            font-size: 2rem;
            color: #64748b;
            margin-bottom: 0.75rem;
            display: block;
        }

        .group-type-card h6 {
            color: var(--guider-blue-dark);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .group-type-card p {
            color: #64748b;
            font-size: 0.9rem;
            margin: 0;
            line-height: 1.4;
        }

        .group-type-option input[type="radio"]:checked + .group-type-label .group-type-card {
            border-color: var(--guider-blue);
            background: var(--guider-blue-soft);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.2);
        }

        .group-type-option input[type="radio"]:checked + .group-type-label .group-type-card i {
            color: var(--guider-blue);
        }

        .group-type-option input[type="radio"]:checked + .group-type-label .group-type-card h6 {
            color: var(--guider-blue-dark);
        }

        .group-type-card:hover {
            border-color: var(--guider-blue-accent);
            transform: translateY(-1px);
        }

        /* Open Group Booking Styles */
        .open-group-info {
            margin-bottom: 2rem;
        }

        .selected-mountain-card {
            background: white;
            border: 2px solid var(--guider-blue);
            border-radius: 15px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            position: relative;
            margin-top: 1rem;
        }

        .mountain-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .mountain-badge i {
            font-size: 0.7rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .group-type-container {
                flex-direction: column;
                gap: 0.75rem;
            }
            
            .group-type-card {
                padding: 1rem;
            }
            
            .group-type-card i {
                font-size: 1.5rem;
            }

            .selected-mountain-card {
                flex-direction: column;
                text-align: center;
                gap: 0.75rem;
            }
        }
        
        /* Hiker Detail Form Styles */
        .hiker-detail-form {
            background: var(--guider-blue-soft);
            border: 2px solid var(--guider-blue);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            position: relative;
        }
        
        .hiker-detail-form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--guider-blue);
        }
        
        .hiker-detail-form-title {
            color: var(--guider-blue-dark);
            font-weight: 700;
            font-size: 1.1rem;
            margin: 0;
        }
        
        .remove-hiker-btn {
            background: #ef4444;
            border: none;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        
        .remove-hiker-btn:hover {
            background: #dc2626;
            transform: scale(1.1);
        }
        
        .hiker-detail-form .row {
            margin-bottom: 1rem;
        }
        
        .hiker-detail-form .form-label {
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .hiker-detail-form .form-control {
            font-size: 0.9rem;
        }
        
        #addHikerBtn {
            transition: all 0.3s ease;
            width: 100%;
            max-width: 300px;
            padding: 12px 20px;
            font-weight: 600;
        }
        
        #addHikerBtn:hover {
            background: var(--guider-blue);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
        }
        
        #addHikerBtn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
<!-- Custom Notification Container -->
<div class="notification-container" id="notificationContainer"></div>


<!-- Header -->
<header>
  <nav class="navbar">
    <div class="container d-flex align-items-center justify-content-between">
      <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasNavbar">
        <span class="navbar-toggler-icon"></span>
      </button>
      <h1 class="navbar-title text-white mx-auto">HIKING GUIDANCE SYSTEM</h1>
      <a class="navbar-brand" href="../index.php">
        <img src="../img/logo.png" class="img-fluid logo" alt="HGS Logo">
      </a>
    </div>

    <div class="offcanvas offcanvas-start" id="offcanvasNavbar">
      <div class="offcanvas-header">
        <h5 class="offcanvas-title">Menu</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
      </div>
      <div class="offcanvas-body">
        <ul class="navbar-nav">
          <li class="nav-item"><a class="nav-link" href="HHomePage.php">Home</a></li>
          <li class="nav-item"><a class="nav-link" href="HProfile.php">Profile</a></li>
          <li class="nav-item"><a class="nav-link active" href="HBooking.php">Book Guider</a></li>
          <li class="nav-item"><a class="nav-link" href="HPayment.php">Payment</a></li>
          <li class="nav-item"><a class="nav-link" href="HYourGuider.php">Your Guider</a></li>
          <li class="nav-item"><a class="nav-link" href="HRateReview.php">Rate and Review</a></li>
          <li class="nav-item"><a class="nav-link" href="HBookingHistory.html">Booking History</a></li>
        </ul>
        <form action="../shared/logout.php" method="POST" class="d-flex justify-content-center mt-5">
          <button type="submit" class="btn btn-danger">Logout</button>
        </form>
      </div>
    </div>
  </nav>
</header>
<?php include_once '../shared/suspension_banner.php'; ?>

<!-- Main Content -->
<main class="main-content">
  <div class="container">
    <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
      <!-- Success Page Content -->
      <div class="section-header">
        <h1 class="section-title">
          <i class="fas fa-check-circle me-3 text-success"></i>Booking Confirmed!
        </h1>
        <p class="section-subtitle">Your hiking booking has been successfully created</p>
      </div>
      
      <!-- Success Content Card -->
      <div class="checkout-container">
        <div class="text-center">
          <div class="mb-4">
            <div class="success-icon-large mx-auto mb-3" style="width: 100px; height: 100px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 15px 40px rgba(16, 185, 129, 0.3);">
              <i class="fas fa-check text-white" style="font-size: 3rem;"></i>
            </div>
          </div>
          
          <h3 class="text-success mb-4 fw-bold">Booking Successfully Created!</h3>
          <p class="text-muted mb-4 fs-5">Your hiking booking has been confirmed. You can now proceed with payment or make another booking.</p>
          
          <!-- Booking Summary -->
          <div class="booking-summary-card" style="background: #f0fdf4; border-radius: 15px; padding: 1.5rem; margin: 2rem 0; border: 2px solid #10b981;">
            <h5 class="text-success mb-3 fw-bold">
              <i class="fas fa-info-circle me-2"></i>Booking Summary
            </h5>
            <div class="row g-3">
              <div class="col-md-6">
                <div class="booking-detail-item">
                  <strong class="text-success">Location:</strong>
                  <p class="mb-0" id="summaryLocation"><?php echo isset($_SESSION['booking_summary']['location']) ? htmlspecialchars($_SESSION['booking_summary']['location']) : ''; ?></p>
                </div>
              </div>
              <div class="col-md-6">
                <div class="booking-detail-item">
                  <strong class="text-success">Duration:</strong>
                  <p class="mb-0" id="summaryDuration">
                    <?php 
                    if (isset($_SESSION['booking_summary']['startDate']) && isset($_SESSION['booking_summary']['endDate'])) {
                      $startDate = new DateTime($_SESSION['booking_summary']['startDate']);
                      $endDate = new DateTime($_SESSION['booking_summary']['endDate']);
                      echo $startDate->format('M j, Y') . ' - ' . $endDate->format('M j, Y');
                    }
                    ?>
                  </p>
                </div>
              </div>
              <div class="col-md-6">
                <div class="booking-detail-item">
                  <strong class="text-success">Number of Hikers:</strong>
                  <p class="mb-0" id="summaryHikers"><?php echo isset($_SESSION['booking_summary']['totalHiker']) ? $_SESSION['booking_summary']['totalHiker'] . ' person(s)' : ''; ?></p>
                </div>
              </div>
              <div class="col-md-6">
                <div class="booking-detail-item">
                  <strong class="text-success">Group Type:</strong>
                  <p class="mb-0" id="summaryGroupType">
                    <?php 
                    if (isset($_SESSION['booking_summary']['groupType'])) {
                        $groupType = $_SESSION['booking_summary']['groupType'];
                        $icon = $groupType === 'close' ? 'fas fa-lock' : 'fas fa-unlock';
                        $text = $groupType === 'close' ? 'Close Group' : 'Open Group';
                        echo '<i class="' . $icon . ' me-1"></i>' . $text;
                    }
                    ?>
                  </p>
                </div>
              </div>
              <div class="col-md-6">
                <div class="booking-detail-item">
                  <strong class="text-success">Total Price:</strong>
                  <p class="mb-0" id="summaryPrice">RM <?php echo isset($_SESSION['booking_summary']['totalPrice']) ? number_format($_SESSION['booking_summary']['totalPrice'], 2) : ''; ?></p>
                </div>
              </div>
            </div>
          </div>
          
          <div class="d-flex gap-3 justify-content-center">
            <a href="HPayment.php" class="btn btn-primary btn-lg px-4 py-3" style="background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light)); border: none; border-radius: 12px; font-weight: 600; box-shadow: 0 8px 25px rgba(30, 64, 175, 0.3); transition: all 0.3s ease;">
              <i class="fas fa-credit-card me-2"></i>Make Payment
            </a>
            <a href="HBooking.php" class="btn btn-outline-primary btn-lg px-4 py-3" style="border: 2px solid var(--guider-blue); color: var(--guider-blue); border-radius: 12px; font-weight: 600; transition: all 0.3s ease; background: linear-gradient(135deg, rgba(30, 64, 175, 0.05), rgba(59, 130, 246, 0.05));">
              <i class="fas fa-plus-circle me-2"></i>Book Another
            </a>
          </div>
        </div>
      </div>
      
    <?php else: ?>
      <!-- Normal Booking Page Content -->
      <div class="section-header">
        <h1 class="section-title">SELECT HIKING LOCATION</h1>
        <p class="section-subtitle">Choose your preferred mountain destination for the hiking</p>
      </div>

    <!-- Date Summary -->
    <div class="date-summary-container">
      <div class="date-summary-header">
        <h5 class="date-summary-title">
          <i class="fas fa-calendar-alt me-2"></i>Selected Hiking Dates
        </h5>
      </div>
      <div class="date-summary-content">
        <div class="date-item">
          <div class="date-label">
            <i class="fas fa-play-circle"></i>
            Start Date
          </div>
          <div class="date-value" id="startDateDisplay">
            <?php 
            if ($startDate) {
              echo date('l, F j, Y', strtotime($startDate));
            } else {
              echo 'Not selected';
            }
            ?>
          </div>
        </div>
        <div class="date-item">
          <div class="date-label">
            <i class="fas fa-stop-circle"></i>
            End Date
          </div>
          <div class="date-value" id="endDateDisplay">
            <?php 
            if ($endDate) {
              echo date('l, F j, Y', strtotime($endDate));
            } else {
              echo 'Not selected';
            }
            ?>
          </div>
        </div>
      </div>
    </div>

<form method="POST" action="HBooking1.php?guiderID=<?= htmlspecialchars($guiderID ?? '') ?><?= ($startDate ? '&start=' . urlencode($startDate) : '') ?><?= ($endDate ? '&end=' . urlencode($endDate) : '') ?>">
    <input type="hidden" name="startDate" value="<?= htmlspecialchars($startDate ?? '') ?>">
    <input type="hidden" name="endDate" value="<?= htmlspecialchars($endDate ?? '') ?>">
    <input type="hidden" name="guiderID" id="guiderID" value="<?= htmlspecialchars($guiderID ?? '') ?>">
    <?php if ($isOpenGroupBooking): ?>
    <input type="hidden" id="existingHikers" value="<?= htmlspecialchars($openGroupMountain['existingHikers']) ?>">
    <input type="hidden" name="mountainID" value="<?= htmlspecialchars($preSelectedMountainID) ?>">
    <?php endif; ?>
      
      <!-- Checkout Container -->
<div class="checkout-container">
        <?php if ($isOpenGroupBooking): ?>
        <!-- Open Group Booking - Pre-selected Mountain -->
        <h4 class="checkout-title">
          <i class="fas fa-users me-2"></i>Joining Open Group
        </h4>
        
        <div class="open-group-info">
          <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>You're joining an existing open group!</strong> The mountain has already been chosen by the first group.
          </div>
          
          <div class="selected-mountain-card">
            <?php 
              $rawPic = $openGroupMountain['picture'] ?? '';
              $rawPic = str_replace('\\', '/', $rawPic);
              if ($rawPic === '' || $rawPic === null) {
                $selPicture = 'https://via.placeholder.com/100';
              } elseif (strpos($rawPic, 'http') === 0) {
                $selPicture = $rawPic;
              } elseif (strpos($rawPic, '../') === 0) {
                $selPicture = $rawPic;
              } elseif (strpos($rawPic, '/') === 0) {
                $selPicture = '..' . $rawPic;
              } else {
                $selPicture = '../' . $rawPic;
              }
            ?>
            <img src="<?= htmlspecialchars($selPicture) ?>" class="mountain-img" alt="Mountain Image">
            <div class="mountain-info">
              <h6><?= htmlspecialchars($openGroupMountain['name']) ?></h6>
              <small><?= htmlspecialchars($openGroupMountain['location']) ?></small>
            </div>
            <div class="mountain-badge">
              <i class="fas fa-lock-open"></i>
              <span>Pre-selected</span>
            </div>
          </div>
        </div>
        
        <?php else: ?>
        <!-- Regular Booking - Choose Mountain -->
        <h4 class="checkout-title">
          <i class="fas fa-mountain me-2"></i>Choose Your Hiking Location
        </h4>

        <!-- Scrollable Mountain Container -->
        <div class="scroll-mountain-container">
          <div class="row g-3">
            <?php while ($row = $mountainResult->fetch_assoc()): 
              $mountainID = $row['mountainID'];
              $name = htmlspecialchars($row['name']);
              $location = htmlspecialchars($row['location']);
              $raw = $row['picture'] ?? '';
              $raw = str_replace('\\', '/', $raw);
              if ($raw === '' || $raw === null) {
                $picture = 'https://via.placeholder.com/100';
              } elseif (strpos($raw, 'http') === 0) {
                $picture = $raw;
              } elseif (strpos($raw, '../') === 0) {
                $picture = $raw;
              } elseif (strpos($raw, '/') === 0) {
                $picture = '..' . $raw;
              } else {
                $picture = '../' . $raw;
              }
            ?>
            <label class="col-12 mountain-card">
              <input type="radio" name="mountainID" value="<?= $mountainID ?>" required>
              <img src="<?= htmlspecialchars($picture) ?>" class="mountain-img" alt="Mountain Image">
              <div class="mountain-info">
                <h6><?= $name ?></h6>
                <small><?= $location ?></small>
              </div>
            </label>
            <?php endwhile; ?>
          </div>
        </div>
        <?php endif; ?>

    <!-- Hiker Details Section -->
    <div class="mt-4">
          <label class="form-label">
            <i class="fas fa-users me-2"></i>Hiker Details (Maximum 7 hikers)
            <?php if ($isOpenGroupBooking): ?>
            <?php $seatsLeft = max(0, 7 - (int)($openGroupMountain['existingHikers'] ?? 0)); ?>
            <small class="text-muted d-block">Existing group has <?= (int)($openGroupMountain['existingHikers'] ?? 0) ?> hikers • Seats left: <?= $seatsLeft ?></small>
            <?php endif; ?>
          </label>
          
          <div id="hikerDetailsContainer">
              <!-- Hiker forms will be added here dynamically -->
          </div>
          
          <button type="button" class="btn btn-outline-primary mt-3" id="addHikerBtn" style="border-color: var(--guider-blue); color: var(--guider-blue); width: 100%; max-width: 300px;">
              <i class="fas fa-plus-circle me-2"></i>Add Hiker
          </button>
          
          <input type="hidden" name="totalHiker" id="totalHikerInput" value="0">
          
          <?php if ($isOpenGroupBooking): ?>
          <div id="hikerValidation" class="text-danger mt-2" style="display: none;">
            <i class="fas fa-exclamation-triangle me-1"></i>
            <span id="validationMessage"></span>
          </div>
          <?php endif; ?>
    </div>

    <!-- Group Type Selection -->
    <?php if (!$isOpenGroupBooking): ?>
    <div class="mt-4">
        <label class="form-label">
            <i class="fas fa-users-cog me-2"></i>Group Type
        </label>
        <div class="group-type-container">
            <div class="group-type-option">
                <input type="radio" name="groupType" value="close" id="closeGroup" <?php echo ($isOpenGroupBooking || ($_GET['joinOpen'] ?? '') === '1') ? 'disabled' : 'checked'; ?>>
                <label for="closeGroup" class="group-type-label">
                    <div class="group-type-card">
                        <i class="fas fa-lock"></i>
                        <div>
                            <h6>Close Group</h6>
                            <p>Flat price for your group</p>
                        </div>
                    </div>
                </label>
            </div>
            <div class="group-type-option">
                <input type="radio" name="groupType" value="open" id="openGroup" <?php echo ($isOpenGroupBooking || ($_GET['joinOpen'] ?? '') === '1') ? 'checked' : ''; ?>>
                <label for="openGroup" class="group-type-label">
                    <div class="group-type-card">
                        <i class="fas fa-unlock"></i>
                        <div>
                            <h6>Open Group</h6>
                            <p>Split price by final headcount</p>
                        </div>
                    </div>
                </label>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- Open Group Info -->
    <div class="mt-4">
        <div class="alert alert-success">
            <i class="fas fa-users me-2"></i>
            <strong>Open Group Booking</strong> - You're joining an existing group. The guider will remain available for other bookings.
        </div>
    </div>
    <?php endif; ?>

    <!-- Submit Button -->
    <div class="text-end mt-4">
          <button type="submit" name="book" class="book-btn">
            <i class="fas fa-calendar-check me-2"></i>CONFIRM BOOKING
          </button>
    </div>
  </div>
</form>
  </div>
    <?php endif; ?>
  </div>
</main>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet" />

<!-- Custom Notification System -->
<script>
class NotificationSystem {
    constructor() {
        this.container = document.getElementById('notificationContainer');
        this.notifications = new Map();
        this.notificationId = 0;
    }

    show(type, title, message, duration = 5000) {
        const id = ++this.notificationId;
        const notification = this.createNotification(id, type, title, message, duration);
        
        this.container.appendChild(notification);
        this.notifications.set(id, notification);

        // Trigger animation
        setTimeout(() => {
            notification.classList.add('show');
        }, 10);

        // Auto remove
        if (duration > 0) {
            setTimeout(() => {
                this.remove(id);
            }, duration);
        }

        return id;
    }

    createNotification(id, type, title, message, duration) {
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.dataset.id = id;

        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-triangle',
            warning: 'fa-exclamation-circle',
            info: 'fa-info-circle'
        };

        notification.innerHTML = `
            <div class="notification-icon">
                <i class="fa-solid ${icons[type]}"></i>
            </div>
            <div class="notification-content">
                <div class="notification-title">${title}</div>
                <div class="notification-message">${message}</div>
            </div>
            <button class="notification-close" onclick="notificationSystem.remove(${id})">
                <i class="fa-solid fa-times"></i>
            </button>
            <div class="notification-progress" style="width: 100%; transition-duration: ${duration}ms;"></div>
        `;

        // Start progress bar
        setTimeout(() => {
            const progressBar = notification.querySelector('.notification-progress');
            if (progressBar) {
                progressBar.style.width = '0%';
            }
        }, 10);

        return notification;
    }

    remove(id) {
        const notification = this.notifications.get(id);
        if (notification) {
            notification.classList.add('hide');
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
                this.notifications.delete(id);
            }, 400);
        }
    }

    success(title, message, duration) {
        return this.show('success', title, message, duration);
    }

    error(title, message, duration) {
        return this.show('error', title, message, duration);
    }

    warning(title, message, duration) {
        return this.show('warning', title, message, duration);
    }

    info(title, message, duration) {
        return this.show('info', title, message, duration);
    }
}

// Initialize notification system
const notificationSystem = new NotificationSystem();

// Show booking success notification
function showBookingSuccessNotification() {
    const notification = document.getElementById('bookingSuccessNotification');
    const bookingDetails = document.getElementById('bookingDetails');
    
    // Get booking summary from session (passed via PHP)
    const bookingSummary = <?php echo isset($_SESSION['booking_summary']) ? json_encode($_SESSION['booking_summary']) : 'null'; ?>;
    
    if (bookingSummary) {
        // Format dates
        const startDate = new Date(bookingSummary.startDate).toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric'
        });
        
        const endDate = new Date(bookingSummary.endDate).toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric'
        });
        
        // Populate booking details
        bookingDetails.innerHTML = `
            <div class="booking-detail">
                <strong>Location:</strong><br>
                ${bookingSummary.location}
            </div>
            <div class="booking-detail">
                <strong>Duration:</strong><br>
                ${startDate} - ${endDate}
            </div>
            <div class="booking-detail">
                <strong>Hikers:</strong><br>
                ${bookingSummary.totalHiker} person(s)
            </div>
            <div class="booking-detail">
                <strong>Group Type:</strong><br>
                <i class="${bookingSummary.groupType === 'close' ? 'fas fa-lock' : 'fas fa-unlock'}"></i>
                ${bookingSummary.groupType === 'close' ? 'Close Group' : 'Open Group'}
            </div>
            <div class="booking-detail">
                <strong>Total Price:</strong><br>
                RM ${parseFloat(bookingSummary.totalPrice).toFixed(2)}
            </div>
        `;
        
        // Show notification
        notification.style.display = 'block';
        setTimeout(() => {
            notification.classList.add('show');
        }, 100);
        
        // Auto hide after 10 seconds
        setTimeout(() => {
            closeBookingNotification();
        }, 10000);
    }
}

// Close booking notification
function closeBookingNotification() {
    const notification = document.getElementById('bookingSuccessNotification');
    notification.classList.remove('show');
    setTimeout(() => {
        notification.style.display = 'none';
    }, 400);
}


// Edit Calendar System removed - keeping only date summary

</script>

<script>
// Global functions for hiker form management
let hikerDetailsContainer, addHikerBtn, totalHikerInput, existingHikersInput, validationDiv, validationMessage, submitButton;

function getMaxHikers() {
  if (existingHikersInput) {
    const existingHikers = parseInt(existingHikersInput.value) || 0;
    return 7 - existingHikers;
  }
  return 7;
}

function updateHikerCount() {
  if (!hikerDetailsContainer || !totalHikerInput) return;
  
  const currentCount = hikerDetailsContainer.querySelectorAll('.hiker-detail-form').length;
  totalHikerInput.value = currentCount;
  
  // Update add button state
  if (addHikerBtn) {
    const maxAllowed = getMaxHikers();
    if (currentCount >= maxAllowed) {
      addHikerBtn.disabled = true;
      addHikerBtn.innerHTML = '<i class="fas fa-ban me-2"></i>Maximum hikers reached';
    } else {
      addHikerBtn.disabled = false;
      addHikerBtn.innerHTML = '<i class="fas fa-plus-circle me-2"></i>Add Hiker';
    }
  }
  
  // Validate for open group bookings
  if (existingHikersInput && validationDiv && validationMessage) {
    const existingHikers = parseInt(existingHikersInput.value) || 0;
    const totalHikers = existingHikers + currentCount;
    
    if (currentCount > 0 && totalHikers > 7) {
      validationDiv.style.display = 'block';
      validationMessage.textContent = `Total hikers would be ${totalHikers} (${existingHikers} existing + ${currentCount} new). Maximum allowed is 7. You can add maximum ${getMaxHikers()} hikers.`;
      if (submitButton) submitButton.disabled = true;
    } else {
      validationDiv.style.display = 'none';
      if (submitButton) submitButton.disabled = false;
    }
  }
}

function addHikerForm() {
  if (!hikerDetailsContainer) return;
  
  const maxAllowed = getMaxHikers();
  const currentCount = hikerDetailsContainer.querySelectorAll('.hiker-detail-form').length;
  
  if (currentCount >= maxAllowed) {
    if (typeof notificationSystem !== 'undefined') {
      notificationSystem.warning('Maximum Reached', `You can only add up to ${maxAllowed} hikers.`);
    } else {
      alert(`You can only add up to ${maxAllowed} hikers.`);
    }
    return;
  }
  
  const hikerIndex = currentCount + 1;
  const hikerForm = document.createElement('div');
  hikerForm.className = 'hiker-detail-form';
  hikerForm.dataset.index = hikerIndex;
  
  hikerForm.innerHTML = `
    <div class="hiker-detail-form-header">
      <h6 class="hiker-detail-form-title">
        <i class="fas fa-user me-2"></i>Hiker ${hikerIndex}
      </h6>
      <button type="button" class="remove-hiker-btn" onclick="removeHikerForm(this)">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="row">
      <div class="col-md-6">
        <label class="form-label">Full Name <span class="text-danger">*</span></label>
        <input type="text" name="hikerDetails[${hikerIndex}][name]" class="form-control" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Identity Card / Passport <span class="text-danger">*</span></label>
        <input type="text" name="hikerDetails[${hikerIndex}][identityCard]" class="form-control identity-card-input" required maxlength="12" pattern="[0-9]{12}" inputmode="numeric">
        <div class="invalid-feedback identity-card-feedback" style="display: none;"></div>
        <small class="form-text text-muted">Must be exactly 12 digits</small>
      </div>
    </div>
    <div class="row">
      <div class="col-12">
        <label class="form-label">Address <span class="text-danger">*</span></label>
        <textarea name="hikerDetails[${hikerIndex}][address]" class="form-control" rows="2" required></textarea>
      </div>
    </div>
    <div class="row">
      <div class="col-md-6">
        <label class="form-label">Phone Number <span class="text-danger">*</span></label>
        <input type="tel" name="hikerDetails[${hikerIndex}][phoneNumber]" class="form-control" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Emergency Contact Name <span class="text-danger">*</span></label>
        <input type="text" name="hikerDetails[${hikerIndex}][emergencyContactName]" class="form-control" required>
      </div>
    </div>
    <div class="row">
      <div class="col-md-6">
        <label class="form-label">Emergency Contact Number <span class="text-danger">*</span></label>
        <input type="tel" name="hikerDetails[${hikerIndex}][emergencyContactNumber]" class="form-control" required>
      </div>
    </div>
  `;
  
  hikerDetailsContainer.appendChild(hikerForm);
  updateHikerCount();
  // Check for duplicates after adding a hiker
  if (typeof checkDuplicateIdentityCards === 'function') {
    checkDuplicateIdentityCards();
  }
  
  // Scroll to the new form
  hikerForm.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function removeHikerForm(button) {
  if (!hikerDetailsContainer) return;
  
  const hikerForm = button.closest('.hiker-detail-form');
  if (hikerForm) {
    const currentCount = hikerDetailsContainer.querySelectorAll('.hiker-detail-form').length;
    if (currentCount <= 1) {
      if (typeof notificationSystem !== 'undefined') {
        notificationSystem.warning('Cannot Remove', 'You must have at least one hiker.');
      } else {
        alert('You must have at least one hiker.');
      }
      return;
    }
    
    hikerForm.remove();
    updateHikerCount();
    renumberHikerForms();
    // Check for duplicates after removing a hiker
    if (typeof checkDuplicateIdentityCards === 'function') {
      checkDuplicateIdentityCards();
    }
  }
}

function renumberHikerForms() {
  if (!hikerDetailsContainer) return;
  
  const forms = hikerDetailsContainer.querySelectorAll('.hiker-detail-form');
  forms.forEach((form, index) => {
    const newIndex = index + 1;
    form.dataset.index = newIndex;
    const title = form.querySelector('.hiker-detail-form-title');
    if (title) {
      title.innerHTML = `<i class="fas fa-user me-2"></i>Hiker ${newIndex}`;
    }
    
    // Update all input names
    const inputs = form.querySelectorAll('input, textarea');
    inputs.forEach(input => {
      const name = input.getAttribute('name');
      if (name) {
        const match = name.match(/hikerDetails\[\d+\]\[(\w+)\]/);
        if (match) {
          input.setAttribute('name', `hikerDetails[${newIndex}][${match[1]}]`);
        }
      }
    });
  });
}

document.addEventListener('DOMContentLoaded', function() {
  // Add visual feedback for mountain card selection
  const mountainCards = document.querySelectorAll('.mountain-card');
  const radioButtons = document.querySelectorAll('input[name="mountainID"]');
  
  radioButtons.forEach(radio => {
    radio.addEventListener('change', function() {
      // Remove selected class from all cards
      mountainCards.forEach(card => card.classList.remove('selected'));
      
      // Add selected class to the clicked card
      if (this.checked) {
        this.closest('.mountain-card').classList.add('selected');
      }
    });
  });
  
  // Add click handler to mountain cards
  mountainCards.forEach(card => {
    card.addEventListener('click', function(e) {
      if (e.target.type !== 'radio') {
        const radio = this.querySelector('input[type="radio"]');
        radio.checked = true;
        radio.dispatchEvent(new Event('change'));
      }
    });
  });

  // Hiker Details Management - Initialize global variables
  hikerDetailsContainer = document.getElementById('hikerDetailsContainer');
  addHikerBtn = document.getElementById('addHikerBtn');
  totalHikerInput = document.getElementById('totalHikerInput');
  existingHikersInput = document.getElementById('existingHikers');
  validationDiv = document.getElementById('hikerValidation');
  validationMessage = document.getElementById('validationMessage');
  submitButton = document.querySelector('button[name="book"]');
  
  // Add first hiker form by default
  if (addHikerBtn) {
    addHikerBtn.addEventListener('click', addHikerForm);
    // Add first hiker form automatically
    addHikerForm();
  }
  
  // Form validation before submit
  const bookingForm = document.querySelector('form[method="POST"]');
  if (bookingForm) {
    bookingForm.addEventListener('submit', function(e) {
      // Debug: Log form data
      console.log('Form submit triggered');
      
      // Check if dates are present
      const startDateInput = bookingForm.querySelector('input[name="startDate"]');
      const endDateInput = bookingForm.querySelector('input[name="endDate"]');
      const guiderIDInput = bookingForm.querySelector('input[name="guiderID"]');
      
      console.log('Start Date:', startDateInput?.value);
      console.log('End Date:', endDateInput?.value);
      console.log('Guider ID:', guiderIDInput?.value);
      
      if (!startDateInput || !startDateInput.value || !startDateInput.value.trim()) {
        e.preventDefault();
        console.log('BLOCKED: Start date missing');
        if (typeof notificationSystem !== 'undefined') {
          notificationSystem.error('Validation Error', 'Start date is required. Please go back and select your hiking dates.');
        } else {
          alert('Start date is required. Please go back and select your hiking dates.');
        }
        return false;
      }
      
      if (!endDateInput || !endDateInput.value || !endDateInput.value.trim()) {
        e.preventDefault();
        console.log('BLOCKED: End date missing');
        if (typeof notificationSystem !== 'undefined') {
          notificationSystem.error('Validation Error', 'End date is required. Please go back and select your hiking dates.');
        } else {
          alert('End date is required. Please go back and select your hiking dates.');
        }
        return false;
      }
      
      if (!guiderIDInput || !guiderIDInput.value || !guiderIDInput.value.trim()) {
        e.preventDefault();
        console.log('BLOCKED: Guider ID missing');
        if (typeof notificationSystem !== 'undefined') {
          notificationSystem.error('Validation Error', 'Guider information is missing. Please go back and try again.');
        } else {
          alert('Guider information is missing. Please go back and try again.');
        }
        return false;
      }
      
      if (!hikerDetailsContainer) return;
      
      const hikerForms = hikerDetailsContainer.querySelectorAll('.hiker-detail-form');
      if (hikerForms.length === 0) {
        e.preventDefault();
        if (typeof notificationSystem !== 'undefined') {
          notificationSystem.error('Validation Error', 'Please add at least one hiker with complete details.');
        } else {
          alert('Please add at least one hiker with complete details.');
        }
        return false;
      }
      
      // FIRST: Check for identity card format validation (12 digits, numbers only)
      let hasInvalidFormat = false;
      hikerForms.forEach((form, index) => {
        const icInput = form.querySelector('input[name*="[identityCard]"]');
        if (icInput && icInput.value.trim()) {
          const ic = icInput.value.trim();
          if (!/^\d{12}$/.test(ic)) {
            hasInvalidFormat = true;
            icInput.classList.add('is-invalid');
            const feedbackDiv = form.querySelector('.identity-card-feedback');
            if (feedbackDiv) {
              feedbackDiv.textContent = 'Identity Card must be exactly 12 digits (numbers only).';
              feedbackDiv.style.display = 'block';
            }
          } else {
            icInput.classList.remove('is-invalid');
          }
        }
      });
      
      if (hasInvalidFormat) {
        e.preventDefault();
        e.stopPropagation();
        return false;
      }
      
      // SECOND: Check for duplicate identity cards within the form
      const identityCards = [];
      const duplicateICs = [];
      hikerForms.forEach((form, index) => {
        const icInput = form.querySelector('input[name*="[identityCard]"]');
        if (icInput && icInput.value.trim()) {
          const ic = icInput.value.trim();
          if (identityCards.includes(ic)) {
            if (!duplicateICs.includes(ic)) {
              duplicateICs.push(ic);
            }
            icInput.classList.add('is-invalid');
            const feedbackDiv = form.querySelector('.identity-card-feedback');
            if (feedbackDiv) {
              feedbackDiv.textContent = 'This Identity Card is used by another hiker in your list.';
              feedbackDiv.style.display = 'block';
            }
          } else {
            identityCards.push(ic);
          }
        }
      });
      
      // If duplicates found in form, block submission (button should already be disabled)
      if (duplicateICs.length > 0) {
        e.preventDefault();
        e.stopPropagation();
        return false;
      }
      
      // Validate each form
      let isValid = true;
      hikerForms.forEach((form, index) => {
        const inputs = form.querySelectorAll('input[required], textarea[required]');
        inputs.forEach(input => {
          if (!input.value.trim()) {
            isValid = false;
            input.classList.add('is-invalid');
          } else {
            input.classList.remove('is-invalid');
          }
        });
      });
      
      if (!isValid) {
        e.preventDefault();
        if (typeof notificationSystem !== 'undefined') {
          notificationSystem.error('Validation Error', 'Please fill in all required fields for all hikers.');
        } else {
          alert('Please fill in all required fields for all hikers.');
        }
        return false;
      }
      
      // If we reach here, validation passed - allow form submission
      // Server-side will check database duplicates and redirect back if needed
    });
  }
  
  // Hiker validation for open group bookings
  if (existingHikersInput && validationDiv) {
    updateHikerCount();
  }
  
  // Validate identity card format (12 digits, numbers only)
  function isValidIdentityCardFormat(ic) {
    if (!ic || ic.trim() === '') return false;
    const trimmedIC = ic.trim();
    // Check if it's exactly 12 digits and contains only numbers
    return /^\d{12}$/.test(trimmedIC);
  }

  // Real-time duplicate identity card validation
  function checkDuplicateIdentityCards() {
    if (!hikerDetailsContainer) return;
    
    const hikerForms = hikerDetailsContainer.querySelectorAll('.hiker-detail-form');
    const identityCards = new Map(); // Map of IC -> [array of inputs with that IC]
    let hasDuplicates = false;
    let hasInvalidFormat = false;
    
    // Clear previous validation
    hikerForms.forEach((form) => {
      const icInput = form.querySelector('input[name*="[identityCard]"]');
      const feedbackDiv = form.querySelector('.identity-card-feedback');
      if (icInput) {
        icInput.classList.remove('is-invalid');
        if (feedbackDiv) {
          feedbackDiv.style.display = 'none';
          feedbackDiv.textContent = '';
        }
      }
    });
    
    // First pass: Validate format and collect valid identity cards
    hikerForms.forEach((form) => {
      const icInput = form.querySelector('input[name*="[identityCard]"]');
      if (icInput) {
        const ic = icInput.value.trim();
        const feedbackDiv = form.querySelector('.identity-card-feedback');
        
        // Check format validation
        if (ic && !isValidIdentityCardFormat(ic)) {
          hasInvalidFormat = true;
          icInput.classList.add('is-invalid');
          if (feedbackDiv) {
            feedbackDiv.textContent = 'Identity Card must be exactly 12 digits (numbers only).';
            feedbackDiv.style.display = 'block';
          }
        } else if (ic) {
          // Only add to map if format is valid
          if (!identityCards.has(ic)) {
            identityCards.set(ic, []);
          }
          identityCards.get(ic).push(icInput);
        }
      }
    });
    
    // Second pass: Check for duplicates (only among valid formatted ICs)
    identityCards.forEach((inputs, ic) => {
      if (inputs.length > 1) {
        hasDuplicates = true;
        inputs.forEach(input => {
          input.classList.add('is-invalid');
          const feedbackDiv = input.closest('.hiker-detail-form')?.querySelector('.identity-card-feedback');
          if (feedbackDiv) {
            feedbackDiv.textContent = 'This Identity Card is used by another hiker in your list.';
            feedbackDiv.style.display = 'block';
          }
        });
      }
    });
    
    // Enable/disable submit button based on duplicates or invalid format
    if (submitButton) {
      submitButton.disabled = hasDuplicates || hasInvalidFormat;
    }
  }
  
  // Add event listeners for real-time validation
  if (hikerDetailsContainer) {
    // Restrict input to numbers only for identity card fields
    hikerDetailsContainer.addEventListener('input', function(e) {
      if (e.target && e.target.matches('input[name*="[identityCard]"]')) {
        // Remove any non-numeric characters
        e.target.value = e.target.value.replace(/[^0-9]/g, '');
        // Limit to 12 digits
        if (e.target.value.length > 12) {
          e.target.value = e.target.value.substring(0, 12);
        }
        checkDuplicateIdentityCards();
      }
    });
    
    // Also handle paste events
    hikerDetailsContainer.addEventListener('paste', function(e) {
      if (e.target && e.target.matches('input[name*="[identityCard]"]')) {
        setTimeout(() => {
          e.target.value = e.target.value.replace(/[^0-9]/g, '').substring(0, 12);
          checkDuplicateIdentityCards();
        }, 0);
      }
    });
    
    // Check duplicates on initial load and whenever IC changes
    checkDuplicateIdentityCards();
  }
});
</script>

<?php if (isset($_SESSION['booking_error']) || (isset($_GET['error']) && $_GET['error'] == '1' && isset($_SESSION['booking_error']))): ?>
<script>
// On page load, check for duplicates and disable button if needed
// This handles the case when page is redirected back due to server-side duplicate detection
document.addEventListener('DOMContentLoaded', function() {
  // Check duplicates on page load - this will disable the button if duplicates exist
  if (typeof checkDuplicateIdentityCards === 'function') {
    checkDuplicateIdentityCards();
  }
});
</script>
<?php endif; ?>



</body>
</html>

<?php
// Clear booking success session data after displaying
if (isset($_SESSION['booking_success'])) {
    unset($_SESSION['booking_success']);
}
if (isset($_SESSION['booking_summary'])) {
    unset($_SESSION['booking_summary']);
}
if (isset($_SESSION['form_data'])) {
    unset($_SESSION['form_data']);
}
// Clear booking error session data
// Keep it for one page load to ensure JavaScript can display it
// It will be cleared on the next page load (after user sees it)
if (isset($_SESSION['booking_error_clear'])) {
    unset($_SESSION['booking_error']);
    unset($_SESSION['booking_error_clear']);
} else if (isset($_SESSION['booking_error'])) {
    // Mark for clearing on next page load
    $_SESSION['booking_error_clear'] = true;
}
?>
