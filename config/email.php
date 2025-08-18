<?php
// Email Configuration
define('SMTP_HOST', 'smtp.gmail.com'); // Change to your SMTP host
define('SMTP_PORT', 587); // SMTP port (587 for TLS, 465 for SSL)
define('SMTP_USERNAME', 'your-email@gmail.com'); // Your email address
define('SMTP_PASSWORD', 'your-app-password'); // Your email password or app password
define('SMTP_SECURE', 'tls'); // 'tls' or 'ssl'
define('SMTP_FROM_EMAIL', 'noreply@facilityreservation.com'); // From email address
define('SMTP_FROM_NAME', 'Facility Reservation System'); // From name

// Email Templates
define('EMAIL_TEMPLATES', [
    'reservation_confirmation' => [
        'subject' => 'Reservation Confirmation - {facility_name}',
        'template' => 'emails/reservation_confirmation.html'
    ],
    'payment_reminder' => [
        'subject' => 'Payment Reminder - Reservation #{reservation_id}',
        'template' => 'emails/payment_reminder.html'
    ],
    'reservation_cancelled' => [
        'subject' => 'Reservation Cancelled - #{reservation_id}',
        'template' => 'emails/reservation_cancelled.html'
    ],
    'admin_notification' => [
        'subject' => 'New Reservation - #{reservation_id}',
        'template' => 'emails/admin_notification.html'
    ],
    'payment_confirmed' => [
        'subject' => 'Payment Confirmed - Reservation #{reservation_id}',
        'template' => 'emails/payment_confirmed.html'
    ],
    'welcome_user' => [
        'subject' => 'Welcome to Facility Reservation System',
        'template' => 'emails/welcome_user.html'
    ]
]);

// Admin email for notifications
define('ADMIN_EMAIL', 'admin@facilityreservation.com');
define('ADMIN_NAME', 'System Administrator');
