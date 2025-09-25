<?php
// Test script to verify email validation is working
require_once 'shared/email_validation.php';

echo "<h2>Email Validation Test</h2>";
echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; }
.test-result { margin: 10px 0; padding: 10px; border-radius: 5px; }
.valid { background-color: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
.invalid { background-color: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
.test-email { font-weight: bold; margin-bottom: 5px; }
</style>";

// Test emails
$testEmails = [
    // Valid emails from major providers (should pass)
    'test@gmail.com',
    'user@yahoo.com',
    'admin@hotmail.com',
    'test@outlook.com',
    'user@live.com',
    'admin@icloud.com',
    
    // Invalid emails (should fail)
    'nonexistent@fakedomain12345.com',
    'fake@notrealdomain999.com',
    'test@example.com',
    'user@test.com',
    'admin@invalid.org',
    
    // Disposable emails (should fail)
    'test@10minutemail.com',
    'user@tempmail.org',
    'admin@guerrillamail.com'
];

foreach ($testEmails as $email) {
    echo "<div class='test-result'>";
    echo "<div class='test-email'>Testing: $email</div>";
    
    $result = validateEmailForRegistration($email);
    
    if ($result['success']) {
        echo "<div class='valid'>✅ VALID: " . $result['message'] . "</div>";
    } else {
        echo "<div class='invalid'>❌ INVALID: " . $result['error'] . "</div>";
    }
    
    echo "</div>";
}

echo "<h3>Test Summary</h3>";
echo "<p>If the validation is working correctly:</p>";
echo "<ul>";
echo "<li>✅ Major email providers (gmail.com, yahoo.com, hotmail.com, outlook.com, live.com, icloud.com) should be VALID</li>";
echo "<li>❌ Fake domains (fakedomain12345.com, notrealdomain999.com) should be INVALID</li>";
echo "<li>❌ Disposable emails (10minutemail.com, tempmail.org) should be INVALID</li>";
echo "<li>❌ Test domains (example.com, test.com) should be INVALID</li>";
echo "</ul>";
echo "<p><strong>Note:</strong> Major email providers are trusted without SMTP verification to avoid false rejections.</p>";

echo "<h3>Registration Test</h3>";
echo "<p>Try registering with these test emails to see if the validation works in the registration form:</p>";
echo "<ul>";
echo "<li><strong>Should work:</strong> your-real-email@gmail.com</li>";
echo "<li><strong>Should fail:</strong> fake@notrealdomain999.com</li>";
echo "<li><strong>Should fail:</strong> test@10minutemail.com</li>";
echo "</ul>";
?>
