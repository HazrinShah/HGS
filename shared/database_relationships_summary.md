# Database Relationships and Cascading Deletes

## Overview
The database relationships have been properly configured to ensure data integrity and automatic cleanup when records are deleted.

## Foreign Key Constraints with CASCADE DELETE

### Review Table
- `bookingID` → `booking(bookingID)` ON DELETE CASCADE
- `hikerID` → `hiker(hikerID)` ON DELETE CASCADE  
- `guiderID` → `guider(guiderID)` ON DELETE CASCADE

## Database Triggers

### 1. update_guider_rating_on_delete
- **Triggered**: AFTER DELETE ON review
- **Purpose**: Automatically recalculates guider's average rating and total review count when a review is deleted
- **Action**: Updates guider table with new average rating and total reviews count

### 2. update_guider_rating_on_insert  
- **Triggered**: AFTER INSERT ON review
- **Purpose**: Automatically updates guider's average rating and total review count when a new review is added
- **Action**: Updates guider table with new average rating and total reviews count

### 3. update_guider_rating_on_update
- **Triggered**: AFTER UPDATE ON review  
- **Purpose**: Automatically recalculates guider's average rating when an existing review is modified
- **Action**: Updates guider table with new average rating and total reviews count

## Behavior

### When a booking is deleted:
1. All related reviews are automatically deleted (CASCADE DELETE)
2. Guider ratings are automatically recalculated (via triggers)

### When a review is deleted:
1. Guider's average rating and total review count are automatically recalculated
2. No orphaned data remains

### When a review is added/updated:
1. Guider's average rating and total review count are automatically updated
2. Data consistency is maintained

## Benefits
- **Data Integrity**: No orphaned records
- **Automatic Maintenance**: Ratings stay accurate without manual intervention
- **Performance**: Database-level operations are faster than application-level calculations
- **Reliability**: Triggers ensure consistency even if application logic fails
