<?php
// Setup script for the rating system
// This script will create the review table and add rating columns to guider table

include 'db_connection.php';

echo "Setting up rating system...\n";

// Create review table
$reviewTableSQL = "
CREATE TABLE IF NOT EXISTS review (
    reviewID INT AUTO_INCREMENT PRIMARY KEY,
    bookingID INT NOT NULL,
    hikerID INT NOT NULL,
    guiderID INT NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    comment TEXT,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (bookingID) REFERENCES booking(bookingID) ON DELETE CASCADE,
    FOREIGN KEY (hikerID) REFERENCES hiker(hikerID) ON DELETE CASCADE,
    FOREIGN KEY (guiderID) REFERENCES guider(guiderID) ON DELETE CASCADE,
    UNIQUE KEY unique_booking_review (bookingID, hikerID)
)";

if ($conn->query($reviewTableSQL)) {
    echo "✓ Review table created successfully\n";
} else {
    echo "✗ Error creating review table: " . $conn->error . "\n";
}

// Add rating columns to guider table
$addRatingColumnsSQL = "
ALTER TABLE guider 
ADD COLUMN IF NOT EXISTS average_rating DECIMAL(3,2) DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS total_reviews INT DEFAULT 0";

if ($conn->query($addRatingColumnsSQL)) {
    echo "✓ Rating columns added to guider table successfully\n";
} else {
    echo "✗ Error adding rating columns: " . $conn->error . "\n";
}

// Add indexes
$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_review_booking ON review(bookingID)",
    "CREATE INDEX IF NOT EXISTS idx_review_hiker ON review(hikerID)",
    "CREATE INDEX IF NOT EXISTS idx_review_guider ON review(guiderID)",
    "CREATE INDEX IF NOT EXISTS idx_review_rating ON review(rating)",
    "CREATE INDEX IF NOT EXISTS idx_review_created ON review(createdAt)",
    "CREATE INDEX IF NOT EXISTS idx_guider_rating ON guider(average_rating)",
    "CREATE INDEX IF NOT EXISTS idx_guider_reviews ON guider(total_reviews)"
];

foreach ($indexes as $indexSQL) {
    if ($conn->query($indexSQL)) {
        echo "✓ Index created successfully\n";
    } else {
        echo "✗ Error creating index: " . $conn->error . "\n";
    }
}

echo "\nRating system setup completed!\n";
echo "You can now use the rating and review functionality.\n";

$conn->close();
?>
