<?php
/**
 * Email Validation Functions
 * Validates if an email address actually exists and is deliverable
 */

function verifyEmailExists($email) {
    // First, validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'valid' => false,
            'message' => 'Invalid email format'
        ];
    }
    
    // Extract domain from email
    $domain = substr(strrchr($email, "@"), 1);
    
    // List of common disposable email domains to block
    $disposableDomains = [
        '10minutemail.com', 'tempmail.org', 'guerrillamail.com', 'mailinator.com',
        'yopmail.com', 'temp-mail.org', 'throwaway.email', 'getnada.com',
        'maildrop.cc', 'sharklasers.com', 'guerrillamailblock.com', 'pokemail.net',
        'spam4.me', 'bccto.me', 'chacuo.net', 'dispostable.com', 'mailnesia.com',
        'mailcatch.com', 'inboxalias.com', 'mailmetrash.com', 'trashmail.net',
        'mytrashmail.com', 'mailnull.com', 'spamgourmet.com', 'spam.la',
        'binkmail.com', 'bobmail.info', 'chammy.info', 'devnullmail.com',
        'letthemeatspam.com', 'mailin8r.com', 'mailinator2.com', 'notmailinator.com',
        'reallymymail.com', 'reconmail.com', 'safetymail.info', 'sogetthis.com',
        'spamhereplease.com', 'superrito.com', 'thisisnotmyrealemail.com',
        'tradermail.info', 'veryrealemail.com', 'wegwerfmail.de', 'wegwerfmail.net',
        'wegwerfmail.org', 'wegwerpmailadres.nl', 'wetrainbayarea.com',
        'wetrainbayarea.org', 'wh4f.org', 'whyspam.me', 'willselfdestruct.com',
        'wuzup.net', 'wuzupmail.net', 'yeah.net', 'yopmail.net', 'yopmail.org',
        'ypmail.webarnak.fr.eu.org', 'cool.fr.nf', 'jetable.fr.nf', 'nospam.ze.tc',
        'nomail.xl.cx', 'mega.zik.dj', 'speed.1s.fr', 'courriel.fr.nf', 'moncourrier.fr.nf',
        'monemail.fr.nf', 'monmail.fr.nf', 'test.com', 'example.com', 'example.org',
        'example.net', 'invalid.com', 'fake.com', 'dummy.com', 'test.org'
    ];
    
    // Check if domain is in disposable email list
    if (in_array(strtolower($domain), $disposableDomains)) {
        return [
            'valid' => false,
            'message' => 'Disposable email addresses are not allowed'
        ];
    }
    
    // Check if domain has valid MX record
    if (!checkdnsrr($domain, 'MX')) {
        return [
            'valid' => false,
            'message' => 'Email domain does not exist'
        ];
    }
    
    // Now verify if the specific email address exists
    return verifyEmailWithSMTP($email, $domain);
}

function verifyEmailWithSMTP($email, $domain) {
    // Get MX records for the domain
    $mxRecords = [];
    $mxWeights = [];
    
    if (getmxrr($domain, $mxRecords, $mxWeights)) {
        // Sort by weight (priority)
        array_multisort($mxWeights, $mxRecords);
    } else {
        // If no MX record, try the domain itself
        $mxRecords = [$domain];
    }
    
    // Try to connect to the mail server
    foreach ($mxRecords as $mxHost) {
        $result = checkEmailWithSMTP($email, $mxHost);
        if ($result['valid']) {
            return $result;
        }
    }
    
    return [
        'valid' => false,
        'message' => 'Email address does not exist or is not deliverable'
    ];
}

function checkEmailWithSMTP($email, $mxHost) {
    $timeout = 10; // 10 second timeout
    $port = 25;
    
    // Try to connect to the mail server
    $connection = @fsockopen($mxHost, $port, $errno, $errstr, $timeout);
    
    if (!$connection) {
        return [
            'valid' => false,
            'message' => 'Cannot connect to email server'
        ];
    }
    
    // Set timeout
    stream_set_timeout($connection, $timeout);
    
    // Read initial response
    $response = fgets($connection, 1024);
    if (substr($response, 0, 3) != '220') {
        fclose($connection);
        return [
            'valid' => false,
            'message' => 'Email server not responding properly'
        ];
    }
    
    // Send HELO command
    fputs($connection, "HELO localhost\r\n");
    $response = fgets($connection, 1024);
    if (substr($response, 0, 3) != '250') {
        fclose($connection);
        return [
            'valid' => false,
            'message' => 'HELO command failed'
        ];
    }
    
    // Send MAIL FROM command
    fputs($connection, "MAIL FROM: <test@example.com>\r\n");
    $response = fgets($connection, 1024);
    if (substr($response, 0, 3) != '250') {
        fclose($connection);
        return [
            'valid' => false,
            'message' => 'MAIL FROM command failed'
        ];
    }
    
    // Send RCPT TO command to check if email exists
    fputs($connection, "RCPT TO: <$email>\r\n");
    $response = fgets($connection, 1024);
    
    // Send QUIT command
    fputs($connection, "QUIT\r\n");
    fclose($connection);
    
    // Check response
    if (substr($response, 0, 3) == '250') {
        return [
            'valid' => true,
            'message' => 'Email address exists and is deliverable'
        ];
    } elseif (substr($response, 0, 3) == '550') {
        return [
            'valid' => false,
            'message' => 'Email address does not exist'
        ];
    } else {
        return [
            'valid' => false,
            'message' => 'Unable to verify email address'
        ];
    }
}

function validateEmailForRegistration($email) {
    // First, validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'error' => 'Invalid email format'
        ];
    }
    
    // Extract domain from email
    $domain = substr(strrchr($email, "@"), 1);
    
    // List of common disposable email domains to block
    $disposableDomains = [
        '10minutemail.com', 'tempmail.org', 'guerrillamail.com', 'mailinator.com',
        'yopmail.com', 'temp-mail.org', 'throwaway.email', 'getnada.com',
        'maildrop.cc', 'sharklasers.com', 'guerrillamailblock.com', 'pokemail.net',
        'spam4.me', 'bccto.me', 'chacuo.net', 'dispostable.com', 'mailnesia.com',
        'mailcatch.com', 'inboxalias.com', 'mailmetrash.com', 'trashmail.net',
        'mytrashmail.com', 'mailnull.com', 'spamgourmet.com', 'spam.la',
        'binkmail.com', 'bobmail.info', 'chammy.info', 'devnullmail.com',
        'letthemeatspam.com', 'mailin8r.com', 'mailinator2.com', 'notmailinator.com',
        'reallymymail.com', 'reconmail.com', 'safetymail.info', 'sogetthis.com',
        'spamhereplease.com', 'superrito.com', 'thisisnotmyrealemail.com',
        'tradermail.info', 'veryrealemail.com', 'wegwerfmail.de', 'wegwerfmail.net',
        'wegwerfmail.org', 'wegwerpmailadres.nl', 'wetrainbayarea.com',
        'wetrainbayarea.org', 'wh4f.org', 'whyspam.me', 'willselfdestruct.com',
        'wuzup.net', 'wuzupmail.net', 'yeah.net', 'yopmail.net', 'yopmail.org',
        'ypmail.webarnak.fr.eu.org', 'cool.fr.nf', 'jetable.fr.nf', 'nospam.ze.tc',
        'nomail.xl.cx', 'mega.zik.dj', 'speed.1s.fr', 'courriel.fr.nf', 'moncourrier.fr.nf',
        'monemail.fr.nf', 'monmail.fr.nf', 'test.com', 'example.com', 'example.org',
        'example.net', 'invalid.com', 'fake.com', 'dummy.com', 'test.org'
    ];
    
    // Check if domain is in disposable email list
    if (in_array(strtolower($domain), $disposableDomains)) {
        return [
            'success' => false,
            'error' => 'Disposable email addresses are not allowed'
        ];
    }
    
    // Check if domain has valid MX record
    if (!checkdnsrr($domain, 'MX')) {
        return [
            'success' => false,
            'error' => 'Email domain does not exist'
        ];
    }
    
    // For major email providers, skip SMTP verification as they often block it
    $majorProviders = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'msn.com', 'aol.com', 'icloud.com', 'me.com', 'mac.com'];
    
    if (in_array(strtolower($domain), $majorProviders)) {
        // Trust major providers - they have good email validation
        return [
            'success' => true,
            'message' => 'Email is valid (major provider)'
        ];
    }
    
    // For other domains, perform SMTP verification
    $smtpResult = verifyEmailWithSMTP($email, $domain);
    if (!$smtpResult['valid']) {
        return [
            'success' => false,
            'error' => $smtpResult['message']
        ];
    }
    
    return [
        'success' => true,
        'message' => 'Email is valid and exists'
    ];
}
?>
