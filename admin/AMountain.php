<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['email'])) {
    header("Location: ALogin.html");
    exit(); // Make sure to exit to prevent further execution
}

include '../shared/db_connection.php';

// Verify the email belongs to an admin (optional but recommended)
$email = $_SESSION['email'];
$stmt = $conn->prepare("SELECT * FROM admin WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Not an admin - destroy session and redirect
    session_destroy();
    header("Location: ALogin.html");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_mountain'])) {
    $name = $_POST['mountain_name'];
    $location = $_POST['mountain_location'];
    $description = $_POST['mountain_description'];

    // Upload image
    $targetDir = "../upload/";
    $imagePath = "../img/mountain-default.jpg"; // Set default image path
    $uploadError = null; // Temporary variable for upload errors

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    if (isset($_FILES['mountain_image']) && $_FILES['mountain_image']['error'] === 0) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $fileType = mime_content_type($_FILES['mountain_image']['tmp_name']);

        if (in_array($fileType, $allowedTypes)) {
            $imageName = basename($_FILES['mountain_image']['name']);
            $targetFile = $targetDir . time() . "_" . $imageName;

            if (move_uploaded_file($_FILES['mountain_image']['tmp_name'], $targetFile)) {
                $imagePath = $targetFile; // Use uploaded image if successful
            } else {
                 $uploadError = "Failed to upload image.";
            }
        } else {
            $uploadError = "Only JPG, PNG, and GIF files are allowed.";
        }
          } elseif (isset($_FILES['mountain_image']) && $_FILES['mountain_image']['error'] !== UPLOAD_ERR_NO_FILE && $_FILES['mountain_image']['error'] !== 0) {
        // Handle other upload errors
        $uploadError = "An error occurred during file upload. Error code: " . $_FILES['mountain_image']['error'];
    }

    // If there was an upload error, redirect with the error message
    if ($uploadError) {
        header("Location: AMountain.php?error=" . urlencode($uploadError));
        exit();
    }

    // Insert into database
    $stmt = $conn->prepare("INSERT INTO mountain (name, location, description, picture) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $name, $location, $description, $imagePath);

    if ($stmt->execute()) {
        // PRG: Redirect after successful POST
        header("Location: AMountain.php?success=" . urlencode("Mountain added successfully!"));
        exit();
    } else {
        // PRG: Redirect with error message if insert fails
        $dbError = "Failed to add mountain: " . $stmt->error;
        header("Location: AMountain.php?error=" . urlencode($dbError));
        exit();
    }

}

  // Update Mountain
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_mountain'])) {
    $mountainID = $_POST['mountain_id'];
    $name = $_POST['mountain_name'];
    $location = $_POST['mountain_location'];
    $description = $_POST['mountain_description'];

    // Get current image path
    $stmt = $conn->prepare("SELECT picture FROM mountain WHERE mountainID = ?");
    $stmt->bind_param("i", $mountainID);
    $stmt->execute();
    $result = $stmt->get_result();
    $currentImage = $result->fetch_assoc()['picture'];
    $stmt->close();

    $imagePath = $currentImage; // Default to current image

    // Handle new image upload
    if (isset($_FILES['mountain_image']) && $_FILES['mountain_image']['error'] === 0) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $fileType = mime_content_type($_FILES['mountain_image']['tmp_name']);

        if (in_array($fileType, $allowedTypes)) {
            $targetDir = "../upload/";
            $imageName = basename($_FILES['mountain_image']['name']);
            $targetFile = $targetDir . time() . "_" . $imageName;

            if (move_uploaded_file($_FILES['mountain_image']['tmp_name'], $targetFile)) {
                $imagePath = $targetFile;
                // Delete old image if not default
                if ($currentImage !== "../img/mountain-default.jpg" && file_exists($currentImage)) {
                    unlink($currentImage);
                }
            }
        }
    }

    // Update database
    $stmt = $conn->prepare("UPDATE mountain SET name=?, location=?, description=?, picture=? WHERE mountainID=?");
    $stmt->bind_param("ssssi", $name, $location, $description, $imagePath, $mountainID);

    if ($stmt->execute()) {
        header("Location: AMountain.php?success=" . urlencode("Mountain updated successfully!"));
        exit();
    } else {
        header("Location: AMountain.php?error=" . urlencode("Failed to update mountain: " . $stmt->error));
        exit();
    }
}
?>

<?php

// Delete Mountain Functionality
if (isset($_POST['delete_mountain'])) {
    $mountainID = $_POST['mountain_id'];
    
    // First, get the image path to delete the file
    $stmt = $conn->prepare("SELECT picture FROM mountain WHERE mountainID = ?");
    $stmt->bind_param("i", $mountainID);
    $stmt->execute();
    $result = $stmt->get_result();
    $mountain = $result->fetch_assoc();
    
    // Delete the record from database
    $deleteQuery = "DELETE FROM mountain WHERE mountainID = ?";
    $stmt = $conn->prepare($deleteQuery);
    $stmt->bind_param("i", $mountainID);

    if ($stmt->execute()) {
        // Delete the associated image file if it's not the default
        if ($mountain['picture'] && $mountain['picture'] !== "../img/mountain-default.jpg") {
            if (file_exists($mountain['picture'])) {
                unlink($mountain['picture']);
            }
        }
        $_SESSION['success'] = "Mountain deleted successfully!";
    } else {
        $_SESSION['error'] = "Failed to delete mountain: " . $stmt->error;
    }
    
    // Redirect to prevent form resubmission
    header("Location: AMountain.php");
    exit();
}

// Fetch all mountains for display
$mountains = [];
$query = $conn->prepare("SELECT * FROM mountain ORDER BY name ASC");
$query->execute();
$result = $query->get_result();
$mountains = $result->fetch_all(MYSQLI_ASSOC);

?>

<!DOCTYPE html>
<html lang="en" style="overflow-x: hidden;">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet" />
  <style>
    :root {
      --primary-color: #3fd847;
      --secondary-color: #85fb5a;
      --success-color: #28a745;
      --warning-color: #ffc107;
      --danger-color: #dc3545;
      --dark-color: #343a40;
      --light-color: #f8f9fa;
    }
    
    body {
      background-color: #f5f5f5;
      font-family: 'Montserrat', sans-serif;
      margin: 0;
      padding: 0;
    }

     /* Header */
    .navbar {
      background-color: #571785 !important;
      padding: 12px 0;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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

    .sidebar {
      background: linear-gradient(135deg, #571785 0%, #4a0f6b 100%) !important;
      width: 300px;
      height: 500px;
      margin-top: 45px;
      margin-left: 20px;
      padding: 20px 10px;
      border-radius: 25px;
      box-shadow: 0 8px 32px rgba(87, 23, 133, 0.4);
      border: 2px solid rgba(255, 255, 255, 0.1);
      position: relative;
      flex-shrink: 0;
    }

    /* Mobile Menu Button */
    .mobile-menu-btn {
      display: none;
      position: fixed;
      top: 15px;
      left: 15px;
      z-index: 1001;
      background: linear-gradient(135deg, #571785 0%, #4a0f6b 100%);
      color: white;
      border: none;
      border-radius: 12px;
      padding: 12px;
      font-size: 1.2rem;
      box-shadow: 0 4px 12px rgba(87, 23, 133, 0.3);
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .mobile-menu-btn:hover {
      background: linear-gradient(135deg, #4a0f6b 0%, #3d0a5c 100%);
      transform: scale(1.05);
    }

    /* Mobile Overlay */
    .mobile-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 999;
      transition: opacity 0.3s ease;
    }

    .mobile-overlay.show {
      display: block;
    }

    .sidebar .menu a {
      display: flex;
      align-items: center;
      padding: 15px 20px;
      color: #ffffff;
      font-weight: 600;
      text-decoration: none;
      margin-bottom: 8px;
      border-radius: 12px;
      transition: all 0.3s ease;
      border: 1px solid transparent;
      position: relative;
      overflow: hidden;
    }

    .sidebar .menu a:hover {
      background: rgba(255, 255, 255, 0.15);
      border-color: rgba(255, 255, 255, 0.3);
      transform: translateX(5px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }

    .sidebar .menu i {
      margin-right: 15px;
      font-size: 18px;
      width: 20px;
      text-align: center;
    }

    .navbar.fixed-top {
      position: fixed;
      top: 0;
      right: 0;
      left: 0;
      z-index: 1030;
    }

    @media (max-width: 768px) {
      .mobile-menu-btn { display: block; }
      .sidebar {
        position: fixed;
        top: 0;
        left: -320px;
        width: 280px;
        height: 100vh;
        margin: 0;
        border-radius: 0;
        z-index: 1000;
        transition: left 0.3s ease;
        overflow-y: auto;
      }
      .sidebar.mobile-open { left: 0; }
      .main-content { margin-left: 0; padding: 80px 15px 20px 15px; width: 100%; }
      .wrapper { flex-direction: column; }
    }

    .main-content {
        flex-grow: 1;
        padding: 30px;
    }

    .content-title {
      font-size: 16px;
      font-weight: bold;
      text-align: left;
      margin-bottom: 10px;
    }

    .content-card {
    background-color: white;
    border-radius: 15px;
    padding: 20px;
    height: 100%;
    min-height: 500px;
    width: 1225px; 
    justify-content: flex-start; /* Corrected value */
    align-items: flex-start;   /* Changed to align content to the top */
    align-items: center;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    margin-top: 15px;
        }

    .logout-btn {
      margin-top: 30px;
    }

    .modal-btn {
    background-color:rgb(91, 147, 244);       
    color: #000;                     /* Text color */
    padding: 10px 20px;              /* Spacing */
    border: none;                    /* Remove default border */
    border-radius: 8px;              /* Rounded corners */
    font-weight: 600;                /* Bold text */
    font-family: 'Montserrat', sans-serif; /* Consistent font */
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);  /* Subtle shadow */
    transition: all 0.3s ease;       /* Smooth hover */
    }

    .action-btn {
    background-color:rgb(91, 147, 244);       
    color: #000;                     /* Text color */
    padding: 10px 20px;              /* Spacing */
    border: none;                    /* Remove default border */
    border-radius: 8px;              /* Rounded corners */
    font-weight: 600;                /* Bold text */
    font-family: 'Montserrat', sans-serif; /* Consistent font */
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);  /* Subtle shadow */
    transition: all 0.3s ease; 
    }

    .modal-btn-red {
    background-color:rgb(244, 91, 91);       
    color: #000;                     /* Text color */
    padding: 10px 20px;              /* Spacing */
    border: none;                    /* Remove default border */
    border-radius: 8px;              /* Rounded corners */
    font-weight: 600;                /* Bold text */
    font-family: 'Montserrat', sans-serif; /* Consistent font */
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);  /* Subtle shadow */
    transition: all 0.3s ease;       /* Smooth hover */
    }

    .modal-btn:hover {
    background-color:rgb(72, 118, 198);       /* Slightly darker on hover */
    color: #000;
    box-shadow: 0 6px 10px rgba(0,0,0,0.15);
    transform: scale(1.03);         /* Slight zoom on hover */
    }

    .action-btn:hover {
    background-color:rgb(72, 118, 198);       /* Slightly darker on hover */
    color: #000;
    box-shadow: 0 6px 10px rgba(0,0,0,0.15);
    transform: scale(1.03);         /* Slight zoom on hover */
    }

    .modal-btn-red:hover {
    background-color:rgb(203, 72, 72);       /* Slightly darker on hover */
    color: #000;
    box-shadow: 0 6px 10px rgba(0,0,0,0.15);
    transform: scale(1.03);         /* Slight zoom on hover */
    }

    .table-responsive {
    width: 100%;
    }
    .table {
        width: 1185px;
        max-width: 100%;
        margin-bottom: 1rem;
    }

    .table th, .table td {
        padding: 0.75rem;
        vertical-align: top;
        border-top: 1px solid #dee2e6;
    }

    .table th{
        background-color: #571785;
    }

    .wrapper {
    display: flex;
    min-height: 100vh;
    }



  </style>
</head>
<body>

  <!-- Mobile Menu Button -->
  <button class="mobile-menu-btn" onclick="toggleMobileMenu()"><i class="bi bi-list"></i></button>

  <!-- Mobile Overlay -->
  <div class="mobile-overlay" onclick="closeMobileMenu()"></div>

  <!-- Header -->
    <header>
    <nav class="navbar">
        <div class="container d-flex align-items-center">
        <!-- Logo and Admin text (left side) -->
        <a class="navbar-brand d-flex align-items-center" href="../index.html">
            <img src="../img/logo.png" class="img-fluid logo me-2" alt="HGS Logo" style="width: 50px; height: 50px;">
            <span class="fs-6 fw-bold text-white">Admin</span>
        </a>

        <!-- Title centered -->
        <h1 class="navbar-title ms-auto me-auto">HIKING GUIDANCE SYSTEM</h1>
        </div>
    </nav>
    </header>
    <!-- End Header -->

<div class="wrapper">
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo-admin">
        <strong class="ms-2 text-white">Menu</strong>
        </div>
        <div class="menu mt-4">
        <a href="ADashboard.html" ><i class="bi bi-grid-fill"></i> Dashboard</a>
        <a href="AUser.html"><i class="bi bi-people-fill"></i> User</a>
        <a href="AMountain.php"><i class="bi bi-triangle-fill"></i> Mountain</a>
        <a href="AReport.html"><i class="bi bi-file-earmark-text-fill"></i> Reports</a>
        <div class="text-center mt-4">
            <form action="../shared/logout.php" method="POST" class="d-flex justify-content-center">
                <button class="btn btn-danger logout-btn w-50" type="submit">
                <i class="bi bi-box-arrow-right"></i> Log Out
                </button>
            </form>
        </div>
        </div>
    </div>

    <div class="main-content">
    <div class="container">
        <div class="row g-4">
        <div class="col-md-6">
            <div class="content-card">
            <div>
                <div class="content-title">

                <h3 class="text-start">Mountain</h3>

                    <button type="button" class="btn modal-btn mt-3" data-bs-toggle="modal" data-bs-target="#addMountainModal">
                    Add Mountain
                    </button>

                    <!-- Add Mountain Modal -->
                    <div class="modal fade" id="addMountainModal" tabindex="-1" aria-labelledby="addMountainModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <form method="POST" action="" enctype="multipart/form-data">
                        <div class="modal-content">
                            <div class="modal-header">
                            <h5 class="modal-title" id="addMountainModalLabel">Add New Mountain</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>

                            <div class="modal-body">
                            <!-- Image Upload with Preview -->
                            <div class="text-center mb-4">
                                <label for="mountainImageInput" class="position-relative d-inline-block" style="cursor: pointer;">
                                <img id="mountainImagePreview" 
                                    src="../img/mountain-default.jpg" 
                                    class="border shadow"
                                    style="width: 100%; max-width: 300px; height: 180px; object-fit: cover; border-radius: 12px;" 
                                    alt="Mountain">
                                <i class="bi bi-camera-fill position-absolute bottom-0 end-0 bg-light text-dark p-2 rounded-circle shadow" 
                                    style="transform: translate(25%, 25%); font-size: 1rem;"></i>
                                </label>
                                <input type="file" class="form-control" name="mountain_image" id="mountainImageInput" accept="image/*" hidden>
                                <div class="form-text mt-2">Insert mountain picture</div>
                            </div>

                            <!-- Name -->
                            <div class="mb-3">
                                <label class="form-label">Mountain Name</label>
                                <input type="text" class="form-control" name="mountain_name" required>
                            </div>

                            <!-- Location -->
                            <div class="mb-3">
                                <label class="form-label">Location</label>
                                <input type="text" class="form-control" name="mountain_location" required>
                            </div>

                            <!-- Description -->
                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="mountain_description" rows="3" required></textarea>
                            </div>
                            </div>

                            <div class="modal-footer">
                            <button type="button" class="btn modal-btn-red" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="add_mountain" class="btn modal-btn">Add Mountain</button>
                            </div>
                        </div>
                        </form>
                    </div>
                    </div>

                    <?php if (!empty($displaySuccessMessage)): ?>
                    <div class="alert alert-success text-center mt-3">
                        <?= $displaySuccessMessage ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($displayErrorMessage)): ?>
                    <div class="alert alert-danger text-center">
                        <?= $displayErrorMessage ?>
                    </div>
                    <?php endif; ?>


                    <!-- EDIT MOUNTAIN MODAL -->

                    <!-- Edit Mountain Modal -->
                    <div class="modal fade" id="editMountainModal" tabindex="-1" aria-labelledby="editMountainModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <form method="POST" action="AMountain.php" enctype="multipart/form-data">
                        <div class="modal-content">
                            <div class="modal-header">
                            <h5 class="modal-title" id="editMountainModalLabel">Edit Mountain</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>

                            <div class="modal-body">
                            <!-- Hidden ID -->
                            <input type="hidden" name="mountain_id" id="editMountainId">

                            <!-- Image Upload with Preview -->
                            <div class="text-center mb-4">
                                <label for="editMountainImageInput" class="position-relative d-inline-block" style="cursor: pointer;">
                                <img id="editMountainImagePreview" 
                                    src="../img/mountain-default.jpg" 
                                    class="border shadow"
                                    style="width: 100%; max-width: 300px; height: 180px; object-fit: cover; border-radius: 12px;" 
                                    alt="Mountain">
                                <i class="bi bi-camera-fill position-absolute bottom-0 end-0 bg-light text-dark p-2 rounded-circle shadow" 
                                    style="transform: translate(25%, 25%); font-size: 1rem;"></i>
                                </label>
                                <input type="file" class="form-control" name="mountain_image" id="editMountainImageInput" accept="image/*" hidden>
                                <div class="form-text mt-2">Change mountain picture</div>
                            </div>

                            <!-- Name -->
                            <div class="mb-3">
                                <label class="form-label">Mountain Name</label>
                                <input type="text" class="form-control" name="mountain_name" id="editMountainName" required>
                            </div>

                            <!-- Location -->
                            <div class="mb-3">
                                <label class="form-label">Location</label>
                                <input type="text" class="form-control" name="mountain_location" id="editMountainLocation" required>
                            </div>

                            <!-- Description -->
                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="mountain_description" id="editMountainDescription" rows="3" required></textarea>
                            </div>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn modal-btn-red" data-bs-dismiss="modal">
                                    Cancel
                                </button>
                                <button type="submit" name="update_mountain" class="btn modal-btn" id="updateMountainBtn">
                                    <span class="submit-text">
                                        Update Mountain
                                    </span>
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                </button>
                            </div>

                        <script>
                        // Add loading state on form submission
                        document.querySelector('form').addEventListener('submit', function() {
                            const btn = document.getElementById('updateMountainBtn');
                            btn.querySelector('.submit-text').classList.add('d-none');
                            btn.querySelector('.spinner-border').classList.remove('d-none');
                            btn.disabled = true;
                        });
                        </script>
                        </div>
                        </form>
                    </div>
                    </div>






                    <!-- DISPLAY AVAILABLE MOUNTAIN DATA -->
                        
                    <?php
                    // Fetch mountain data
                    $result = $conn->query("SELECT * FROM mountain");
                    ?>

                    <!-- Mountain Data Table -->
                    <div class="table-responsive mt-4">
                    <table class="table table-bordered table-hover align-middle">
                        <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Picture</th>
                            <th>Name</th>
                            <th>Location</th>
                            <th>Description</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <?php static $count = 1; ?>
                            <td><?= $count++ ?></td>
                            <td>
                                <img src="<?= $row['picture'] ?>" alt="Mountain" style="width: 100px; height: 60px; object-fit: cover; border-radius: 8px;">
                            </td>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td><?= htmlspecialchars($row['location']) ?></td>
                            <td><?= htmlspecialchars($row['description']) ?></td>
                            <td class="text-center">
                                <!-- Edit button with data attributes -->
                                <button type="button" class="btn btn-primary mb-2" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#editMountainModal"
                                    data-id="<?= $row['mountainID'] ?>"
                                    data-name="<?= htmlspecialchars($row['name']) ?>"
                                    data-location="<?= htmlspecialchars($row['location']) ?>"
                                    data-description="<?= htmlspecialchars($row['description']) ?>"
                                    data-image="<?= $row['picture'] ?>">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                
                                <!-- Delete button -->
                                 <form method="POST" action="AMountain.php" style="display: inline;">
                                    <input type="hidden" name="mountain_id" value="<?= $row['mountainID'] ?>">
                                    <button type="submit" name="delete_mountain" class="btn btn-danger" 
                                            onclick="return confirm('Are you sure you want to delete this mountain?')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                 </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                                                </tbody>
                                            </table>
                                            </div>



                </div>
            </div>
            </div>
        </div>
    </div>
    </div>
    </div>
    </div>




    <script>
    // Mobile menu toggle function
    function toggleMobileMenu() {
      const sidebar = document.querySelector('.sidebar');
      const mobileBtn = document.querySelector('.mobile-menu-btn');
      const overlay = document.querySelector('.mobile-overlay');
      
      sidebar.classList.toggle('mobile-open');
      overlay.classList.toggle('show');
      
      // Change icon based on state
      const icon = mobileBtn.querySelector('i');
      if (sidebar.classList.contains('mobile-open')) {
        icon.className = 'bi bi-x';
      } else {
        icon.className = 'bi bi-list';
      }
    }

    // Close mobile menu function
    function closeMobileMenu() {
      const sidebar = document.querySelector('.sidebar');
      const mobileBtn = document.querySelector('.mobile-menu-btn');
      const overlay = document.querySelector('.mobile-overlay');
      
      sidebar.classList.remove('mobile-open');
      overlay.classList.remove('show');
      
      const icon = mobileBtn.querySelector('i');
      icon.className = 'bi bi-list';
    }

    // Close mobile menu when clicking outside
    document.addEventListener('click', function(event) {
      const sidebar = document.querySelector('.sidebar');
      const mobileBtn = document.querySelector('.mobile-menu-btn');
      
      if (window.innerWidth <= 768) {
        if (!sidebar.contains(event.target) && !mobileBtn.contains(event.target)) {
          closeMobileMenu();
        }
      }
    });
    </script>

    <script>
    document.getElementById('mountainImageInput').addEventListener('change', function(event) {
    const image = event.target.files[0];
    const preview = document.getElementById('mountainImagePreview');

    if (image) {
        const reader = new FileReader();
        reader.onload = function(e) {
        preview.src = e.target.result;
        preview.style.display = 'block';
        }
        reader.readAsDataURL(image);
    } else {
        preview.src = '#';
        preview.style.display = 'none';
    }
    });

    </script>

<script>
// Image preview for add modal
document.getElementById('mountainImageInput').addEventListener('change', function(event) {
    const image = event.target.files[0];
    const preview = document.getElementById('mountainImagePreview');

    if (image) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
        }
        reader.readAsDataURL(image);
    }
});

// Image preview for edit modal
document.getElementById('editMountainImageInput').addEventListener('change', function(event) {
    const image = event.target.files[0];
    const preview = document.getElementById('editMountainImagePreview');

    if (image) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
        }
        reader.readAsDataURL(image);
    }
});

// Handle edit modal data population
document.getElementById('editMountainModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget; // Button that triggered the modal
    const id = button.getAttribute('data-id');
    const name = button.getAttribute('data-name');
    const location = button.getAttribute('data-location');
    const description = button.getAttribute('data-description');
    const image = button.getAttribute('data-image');
    
    // Update modal content
    const modal = this;
    modal.querySelector('#editMountainId').value = id;
    modal.querySelector('#editMountainName').value = name;
    modal.querySelector('#editMountainLocation').value = location;
    modal.querySelector('#editMountainDescription').value = description;
    modal.querySelector('#editMountainImagePreview').src = image;
});

// Handle delete modal data population
document.getElementById('deleteMountainModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const id = button.getAttribute('data-id');
    this.querySelector('#deleteMountainId').value = id;
});
</script>





  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
