<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/email.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
class EmailMailer {
    private $mailer;
    public function __construct() {
        $this->mailer = new PHPMailer(true);
        $this->setupSMTP();
    }
    private function setupSMTP() {
        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = SMTP_HOST;
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = SMTP_USERNAME;
            $this->mailer->Password = SMTP_PASSWORD;
            $this->mailer->SMTPSecure = SMTP_SECURE;
            $this->mailer->Port = SMTP_PORT;
            // Default settings
            $this->mailer->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $this->mailer->isHTML(true);
            $this->mailer->CharSet = 'UTF-8';
        } catch (Exception $e) {
            error_log("Email setup error: " . $e->getMessage());
        }
    }
    /**
     * Send email using template
     */
    public function sendTemplateEmail($to_email, $to_name, $template_type, $data = []) {
        try {
            if (!isset(EMAIL_TEMPLATES[$template_type])) {
                throw new Exception("Email template '$template_type' not found");
            }
            $template = EMAIL_TEMPLATES[$template_type];
            $subject = $this->replacePlaceholders($template['subject'], $data);
            $body = $this->loadTemplate($template['template'], $data);
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to_email, $to_name);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $body;
            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("Email sending error: " . $e->getMessage());
            return false;
        }
    }
    /**
     * Send custom email
     */
    public function sendEmail($to_email, $to_name, $subject, $body, $is_html = true) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to_email, $to_name);
            $this->mailer->Subject = $subject;
            $this->mailer->isHTML($is_html);
            $this->mailer->Body = $body;
            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("Email sending error: " . $e->getMessage());
            return false;
        }
    }
    /**
     * Load email template and replace placeholders
     */
    private function loadTemplate($template_path, $data) {
        $full_path = __DIR__ . '/../' . $template_path;
        if (!file_exists($full_path)) {
            // Return default template if file doesn't exist
            return $this->getDefaultTemplate($data);
        }
        $template = file_get_contents($full_path);
        return $this->replacePlaceholders($template, $data);
    }
    /**
     * Replace placeholders in text
     */
    private function replacePlaceholders($text, $data) {
        foreach ($data as $key => $value) {
            $text = str_replace('{' . $key . '}', $value, $text);
            $text = str_replace('#' . $key . '#', $value, $text);
        }
        return $text;
    }
    /**
     * Get default email template
     */
    private function getDefaultTemplate($data) {
        $template = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Facility Reservation System</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #3B82F6; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .footer { background: #333; color: white; padding: 15px; text-align: center; font-size: 12px; }
                .button { display: inline-block; padding: 10px 20px; background: #3B82F6; color: white; text-decoration: none; border-radius: 5px; }
                .info-box { background: white; padding: 15px; margin: 10px 0; border-left: 4px solid #3B82F6; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>Facility Reservation System</h1>
                </div>
                <div class="content">
                    <h2>{subject}</h2>
                    <div class="info-box">
                        {message}
                    </div>
                    {details}
                    <p>Thank you for using our facility reservation system!</p>
                </div>
                <div class="footer">
                    <p>&copy; 2024 Facility Reservation System. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>';
        return $this->replacePlaceholders($template, $data);
    }
    /**
     * Send reservation confirmation email
     */
    public function sendReservationConfirmation($user_email, $user_name, $reservation_data) {
        $data = [
            'user_name' => $user_name,
            'facility_name' => $reservation_data['facility_name'],
            'reservation_id' => $reservation_data['id'],
            'start_time' => $reservation_data['start_time'],
            'end_time' => $reservation_data['end_time'],
            'total_amount' => '₱' . number_format($reservation_data['total_amount'], 2),
            'payment_due' => $reservation_data['payment_due_at'],
            'subject' => 'Reservation Confirmation',
            'message' => "Your reservation has been confirmed successfully!",
            'details' => "
                <h3>Reservation Details:</h3>
                <p><strong>Facility:</strong> {$reservation_data['facility_name']}</p>
                <p><strong>Date & Time:</strong> " . date('M j, Y g:i A', strtotime($reservation_data['start_time'])) . " - " . date('g:i A', strtotime($reservation_data['end_time'])) . "</p>
                <p><strong>Total Amount:</strong> ₱" . number_format($reservation_data['total_amount'], 2) . "</p>
                <p><strong>Payment Due:</strong> " . date('M j, Y g:i A', strtotime($reservation_data['payment_due_at'])) . "</p>
                <p><em>Please settle your payment within 24 hours to avoid automatic cancellation.</em></p>
            "
        ];
        return $this->sendTemplateEmail($user_email, $user_name, 'reservation_confirmation', $data);
    }
    /**
     * Send payment reminder email
     */
    public function sendPaymentReminder($user_email, $user_name, $reservation_data) {
        $data = [
            'user_name' => $user_name,
            'reservation_id' => $reservation_data['id'],
            'facility_name' => $reservation_data['facility_name'],
            'total_amount' => '₱' . number_format($reservation_data['total_amount'], 2),
            'payment_due' => $reservation_data['payment_due_at'],
            'subject' => 'Payment Reminder',
            'message' => "This is a reminder that your payment is due soon!",
            'details' => "
                <h3>Payment Details:</h3>
                <p><strong>Reservation ID:</strong> #{$reservation_data['id']}</p>
                <p><strong>Facility:</strong> {$reservation_data['facility_name']}</p>
                <p><strong>Amount Due:</strong> ₱" . number_format($reservation_data['total_amount'], 2) . "</p>
                <p><strong>Payment Due:</strong> " . date('M j, Y g:i A', strtotime($reservation_data['payment_due_at'])) . "</p>
                <p><strong style='color: red;'>Please settle your payment immediately to avoid automatic cancellation.</strong></p>
            "
        ];
        return $this->sendTemplateEmail($user_email, $user_name, 'payment_reminder', $data);
    }
    /**
     * Send reservation cancellation email
     */
    public function sendReservationCancelled($user_email, $user_name, $reservation_data) {
        $data = [
            'user_name' => $user_name,
            'reservation_id' => $reservation_data['id'],
            'facility_name' => $reservation_data['facility_name'],
            'subject' => 'Reservation Cancelled',
            'message' => "Your reservation has been cancelled due to non-payment.",
            'details' => "
                <h3>Cancelled Reservation:</h3>
                <p><strong>Reservation ID:</strong> #{$reservation_data['id']}</p>
                <p><strong>Facility:</strong> {$reservation_data['facility_name']}</p>
                <p><strong>Date & Time:</strong> " . date('M j, Y g:i A', strtotime($reservation_data['start_time'])) . " - " . date('g:i A', strtotime($reservation_data['end_time'])) . "</p>
                <p>You can make a new reservation at any time.</p>
            "
        ];
        return $this->sendTemplateEmail($user_email, $user_name, 'reservation_cancelled', $data);
    }
    /**
     * Send admin notification for new reservation
     */
    public function sendAdminNotification($reservation_data) {
        $data = [
            'reservation_id' => $reservation_data['id'],
            'user_name' => $reservation_data['user_name'],
            'user_email' => $reservation_data['user_email'],
            'facility_name' => $reservation_data['facility_name'],
            'total_amount' => '₱' . number_format($reservation_data['total_amount'], 2),
            'subject' => 'New Reservation Notification',
            'message' => "A new reservation has been made.",
            'details' => "
                <h3>New Reservation Details:</h3>
                <p><strong>Reservation ID:</strong> #{$reservation_data['id']}</p>
                <p><strong>User:</strong> {$reservation_data['user_name']} ({$reservation_data['user_email']})</p>
                <p><strong>Facility:</strong> {$reservation_data['facility_name']}</p>
                <p><strong>Date & Time:</strong> " . date('M j, Y g:i A', strtotime($reservation_data['start_time'])) . " - " . date('g:i A', strtotime($reservation_data['end_time'])) . "</p>
                <p><strong>Amount:</strong> ₱" . number_format($reservation_data['total_amount'], 2) . "</p>
            "
        ];
        return $this->sendTemplateEmail(ADMIN_EMAIL, ADMIN_NAME, 'admin_notification', $data);
    }
    /**
     * Send payment confirmation email
     */
    public function sendPaymentConfirmed($user_email, $user_name, $reservation_data) {
        $data = [
            'user_name' => $user_name,
            'reservation_id' => $reservation_data['id'],
            'facility_name' => $reservation_data['facility_name'],
            'subject' => 'Payment Confirmed',
            'message' => "Your payment has been confirmed successfully!",
            'details' => "
                <h3>Payment Confirmed:</h3>
                <p><strong>Reservation ID:</strong> #{$reservation_data['id']}</p>
                <p><strong>Facility:</strong> {$reservation_data['facility_name']}</p>
                <p><strong>Date & Time:</strong> " . date('M j, Y g:i A', strtotime($reservation_data['start_time'])) . " - " . date('g:i A', strtotime($reservation_data['end_time'])) . "</p>
                <p><strong style='color: green;'>Your reservation is now confirmed and active!</strong></p>
            "
        ];
        return $this->sendTemplateEmail($user_email, $user_name, 'payment_confirmed', $data);
    }
    /**
     * Send welcome email to new user
     */
    public function sendWelcomeEmail($user_email, $user_name) {
        $data = [
            'user_name' => $user_name,
            'subject' => 'Welcome to Facility Reservation System',
            'message' => "Welcome to our facility reservation system!",
            'details' => "
                <h3>Welcome {$user_name}!</h3>
                <p>Thank you for registering with our facility reservation system. You can now:</p>
                <ul>
                    <li>Browse available facilities</li>
                    <li>Make reservations</li>
                    <li>View your booking history</li>
                    <li>Receive email notifications</li>
                </ul>
                <p>If you have any questions, please don't hesitate to contact us.</p>
            "
        ];
        return $this->sendTemplateEmail($user_email, $user_name, 'welcome_user', $data);
    }
    /**
     * Send no-show notification email
     */
    public function sendNoShowNotification($user_email, $user_name, $reservation_data) {
        $data = [
            'user_name' => $user_name,
            'reservation_id' => $reservation_data['id'],
            'facility_name' => $reservation_data['facility_name'],
            'subject' => 'No-Show Notification',
            'message' => "Your reservation has been marked as no-show.",
            'details' => "
                <h3>No-Show Notification:</h3>
                <p><strong>Reservation ID:</strong> #{$reservation_data['id']}</p>
                <p><strong>Facility:</strong> {$reservation_data['facility_name']}</p>
                <p><strong>Date & Time:</strong> " . date('M j, Y g:i A', strtotime($reservation_data['start_time'])) . " - " . date('g:i A', strtotime($reservation_data['end_time'])) . "</p>
                <p><strong style='color: red;'>Your reservation has been marked as no-show because you did not arrive for your scheduled booking.</strong></p>
                <p><strong>Important:</strong></p>
                <ul>
                    <li>Payment for this reservation is non-refundable</li>
                    <li>Repeated no-shows may affect your ability to make future reservations</li>
                    <li>Please contact us if you have any questions about this policy</li>
                </ul>
                <p>You can make new reservations at any time.</p>
            "
        ];
        return $this->sendTemplateEmail($user_email, $user_name, 'no_show_notification', $data);
    }
    /**
     * Send cancellation confirmation email
     */
    public function sendCancellationConfirmation($user_email, $user_name, $reservation_data, $refund_data) {
        $refundMessage = $refund_data['percentage'] > 0 ? 
            "You will receive a {$refund_data['percentage']}% refund of ₱" . number_format($refund_data['amount'], 2) . "." :
            "Unfortunately, no refund is available due to the cancellation timing.";
        $data = [
            'user_name' => $user_name,
            'reservation_id' => $reservation_data['id'],
            'facility_name' => $reservation_data['facility_name'],
            'subject' => 'Reservation Cancelled',
            'message' => "Your reservation has been successfully cancelled.",
            'details' => "
                <h3>Cancelled Reservation Details:</h3>
                <p><strong>Reservation ID:</strong> #{$reservation_data['id']}</p>
                <p><strong>Facility:</strong> {$reservation_data['facility_name']}</p>
                <p><strong>Date & Time:</strong> " . date('M j, Y g:i A', strtotime($reservation_data['start_time'])) . " - " . date('g:i A', strtotime($reservation_data['end_time'])) . "</p>
                <p><strong>Original Amount:</strong> ₱" . number_format($reservation_data['total_amount'], 2) . "</p>
                <h3>Refund Information:</h3>
                <p><strong>{$refundMessage}</strong></p>
                <p>If you have any questions about the cancellation or refund, please contact our support team.</p>
            "
        ];
        return $this->sendTemplateEmail($user_email, $user_name, 'cancellation_confirmation', $data);
    }
    /**
     * Send reschedule confirmation email
     */
    public function sendRescheduleConfirmation($user_email, $user_name, $reservation_data, $cost_difference) {
        $costMessage = '';
        if ($cost_difference > 0) {
            $costMessage = "<p><strong style='color: orange;'>Additional Payment Required:</strong> ₱" . number_format($cost_difference, 2) . "</p>";
        } elseif ($cost_difference < 0) {
            $costMessage = "<p><strong style='color: green;'>Refund Due:</strong> ₱" . number_format(abs($cost_difference), 2) . "</p>";
        } else {
            $costMessage = "<p><strong style='color: green;'>No additional payment required.</strong></p>";
        }
        $data = [
            'user_name' => $user_name,
            'reservation_id' => $reservation_data['id'],
            'facility_name' => $reservation_data['facility_name'],
            'subject' => 'Reservation Rescheduled',
            'message' => "Your reservation has been successfully rescheduled.",
            'details' => "
                <h3>New Reservation Details:</h3>
                <p><strong>Reservation ID:</strong> #{$reservation_data['id']}</p>
                <p><strong>Facility:</strong> {$reservation_data['facility_name']}</p>
                <p><strong>New Date & Time:</strong> " . date('M j, Y g:i A', strtotime($reservation_data['start_time'])) . " - " . date('g:i A', strtotime($reservation_data['end_time'])) . "</p>
                <p><strong>Total Amount:</strong> ₱" . number_format($reservation_data['total_amount'], 2) . "</p>
                {$costMessage}
                <p>Please arrive on time for your rescheduled reservation. Thank you!</p>
            "
        ];
        return $this->sendTemplateEmail($user_email, $user_name, 'reschedule_confirmation', $data);
    }
    /**
     * Send extension confirmation email
     */
    public function sendExtensionConfirmation($user_email, $user_name, $reservation_data, $additional_cost) {
        $costMessage = $additional_cost > 0 ? 
            "<p><strong style='color: orange;'>Additional Payment Required:</strong> ₱" . number_format($additional_cost, 2) . "</p>" :
            "<p><strong style='color: green;'>No additional payment required.</strong></p>";
        $data = [
            'user_name' => $user_name,
            'reservation_id' => $reservation_data['id'],
            'facility_name' => $reservation_data['facility_name'],
            'subject' => 'Reservation Extended',
            'message' => "Your reservation has been successfully extended.",
            'details' => "
                <h3>Updated Reservation Details:</h3>
                <p><strong>Reservation ID:</strong> #{$reservation_data['id']}</p>
                <p><strong>Facility:</strong> {$reservation_data['facility_name']}</p>
                <p><strong>Extended Time:</strong> " . date('g:i A', strtotime($reservation_data['start_time'])) . " - " . date('g:i A', strtotime($reservation_data['end_time'])) . "</p>
                <p><strong>Total Amount:</strong> ₱" . number_format($reservation_data['total_amount'], 2) . "</p>
                {$costMessage}
                <p>Enjoy your extended reservation time!</p>
            "
        ];
        return $this->sendTemplateEmail($user_email, $user_name, 'extension_confirmation', $data);
    }
    
    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail($user_email, $user_name, $reset_token, $reset_url) {
        $data = [
            'user_name' => $user_name,
            'reset_token' => $reset_token,
            'reset_url' => $reset_url,
            'subject' => 'Password Reset Request',
            'message' => "You have requested to reset your password.",
            'details' => "
                <h3>Password Reset Instructions:</h3>
                <p>Hello {$user_name},</p>
                <p>You have requested to reset your password for your Facility Reservation System account.</p>
                <p>To reset your password, please click the button below:</p>
                <div style='text-align: center; margin: 20px 0;'>
                    <a href='{$reset_url}' style='display: inline-block; padding: 12px 24px; background: #3B82F6; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Reset Password</a>
                </div>
                <p>Or copy and paste this link into your browser:</p>
                <p style='word-break: break-all; background: #f5f5f5; padding: 10px; border-radius: 3px;'>{$reset_url}</p>
                <p><strong>Important:</strong></p>
                <ul>
                    <li>This link will expire in 1 hour for security reasons</li>
                    <li>If you didn't request this password reset, please ignore this email</li>
                    <li>For security, this link can only be used once</li>
                </ul>
                <p>If you have any questions, please contact our support team.</p>
            "
        ];
        return $this->sendTemplateEmail($user_email, $user_name, 'password_reset', $data);
    }
}
