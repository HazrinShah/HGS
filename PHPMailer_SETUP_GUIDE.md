# PHPMailer Setup Guide for HGS

This guide will help you set up PHPMailer to send emails from your Hiking Guidance System.

## 📋 Prerequisites

1. **Composer** installed on your system
   - Download from: https://getcomposer.org/download/
   - Or install via XAMPP (if not already installed)

## 🚀 Installation Steps

### Step 1: Install Composer Dependencies

Open a terminal/command prompt in your project root directory (`C:\xampp\htdocs\HGS`) and run:

```bash
composer install
```

This will:
- Create a `vendor/` directory
- Download PHPMailer and its dependencies

### Step 2: Configure Email Settings

Edit the file: `shared/email_config.php`

Choose **ONE** of the following options:

#### Option 1: Gmail SMTP (Recommended for Development)

1. Go to your Google Account settings: https://myaccount.google.com/
2. Enable **2-Step Verification**
3. Go to **App Passwords**: https://myaccount.google.com/apppasswords
4. Create a new app password for "Mail"
5. Copy the 16-character password

Update `shared/email_config.php`:

```php
$mail->Host       = 'smtp.gmail.com';
$mail->SMTPAuth   = true;
$mail->Username   = 'your-email@gmail.com';  // Your Gmail address
$mail->Password   = 'xxxx xxxx xxxx xxxx';   // Your 16-character app password
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port       = 587;
```

#### Option 2: Mailtrap (For Testing - No Real Emails)

1. Sign up at: https://mailtrap.io/ (Free account available)
2. Go to your inbox settings
3. Copy the SMTP credentials

Update `shared/email_config.php` - uncomment Option 3 and add your credentials:

```php
$mail->Host       = 'smtp.mailtrap.io';
$mail->SMTPAuth   = true;
$mail->Username   = 'your-mailtrap-username';
$mail->Password   = 'your-mailtrap-password';
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port       = 2525;
```

#### Option 3: Outlook/Hotmail SMTP

Update `shared/email_config.php` - uncomment Option 2:

```php
$mail->Host       = 'smtp-mail.outlook.com';
$mail->SMTPAuth   = true;
$mail->Username   = 'your-email@outlook.com';
$mail->Password   = 'your-password';
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port       = 587;
```

#### Option 4: Custom SMTP Server

If you have your own SMTP server, uncomment Option 4 and configure:

```php
$mail->Host       = 'smtp.yourdomain.com';
$mail->SMTPAuth   = true;
$mail->Username   = 'noreply@yourdomain.com';
$mail->Password   = 'your-password';
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // or ENCRYPTION_SMTPS
$mail->Port       = 587; // or 465 for SSL
```

### Step 3: Test Email Sending

You can test if emails are working by accessing the payment success page after a successful payment. Check your error logs or email inbox.

## 🔧 Troubleshooting

### Issue: "Composer command not found"

**Solution:**
- Make sure Composer is installed and in your system PATH
- Or use the full path: `C:\ProgramData\ComposerSetup\bin\composer.bat install`
- Or download Composer PHAR and run: `php composer.phar install`

### Issue: "Class 'PHPMailer\PHPMailer\PHPMailer' not found"

**Solution:**
- Run `composer install` again in the project root
- Check that `vendor/autoload.php` exists
- Make sure `shared/email_config.php` is included before using PHPMailer

### Issue: "SMTP connect() failed"

**Solution:**
- Verify your SMTP credentials (username/password)
- Check if your firewall/antivirus is blocking the connection
- For Gmail: Make sure you're using an App Password, not your regular password
- Try enabling SMTP debug mode temporarily in `email_config.php`:
  ```php
  $mail->SMTPDebug = SMTP::DEBUG_SERVER;
  ```

### Issue: "Could not authenticate"

**Solution:**
- Double-check your username and password
- For Gmail: Ensure 2-Step Verification is enabled and use App Password
- For Outlook: Make sure your account allows "less secure apps" or use Modern Authentication

### Issue: Emails going to Spam

**Solution:**
- Check your SPF and DKIM records for your domain
- Use a proper "From" address (not a generic one)
- Add your domain's SPF record if using custom SMTP

## 📝 Usage in Code

After setup, you can use PHPMailer in any PHP file:

```php
// Include the email configuration
require_once __DIR__ . '/shared/email_config.php';

// Send an email
$success = sendEmail(
    'recipient@example.com',           // To email
    'Email Subject',                    // Subject
    '<h1>Hello!</h1><p>Email body</p>', // HTML body
    'Recipient Name'                    // Optional: recipient name
);

if ($success) {
    echo "Email sent!";
} else {
    echo "Email failed to send. Check error logs.";
}
```

## 🔒 Security Notes

1. **Never commit credentials** - Keep `email_config.php` out of version control
2. **Use environment variables** - Consider using `.env` file for sensitive data
3. **Use App Passwords** - For Gmail, always use App Passwords, never regular passwords
4. **Enable SSL/TLS** - Always use encrypted connections (STARTTLS or SMTPS)

## 📚 Additional Resources

- PHPMailer Documentation: https://github.com/PHPMailer/PHPMailer
- Gmail App Passwords: https://support.google.com/accounts/answer/185833
- Mailtrap Documentation: https://mailtrap.io/docs/

## ✅ Verification Checklist

- [ ] Composer installed
- [ ] `composer install` completed successfully
- [ ] `vendor/` directory exists
- [ ] Email configuration updated in `shared/email_config.php`
- [ ] SMTP credentials are correct
- [ ] Test email sent successfully
- [ ] Error logs checked (no errors)

---

**Need Help?** Check the error logs in `C:\xampp\php\logs\php_error_log` or your server's error log file.

