# Facility Reservation System

A modern, comprehensive web-based facility reservation system built with PHP, MySQL, and Tailwind CSS. This system provides a complete solution for managing facility bookings, user registrations, and administrative oversight.

## 🚀 Features

### For Users
- **User Authentication**: Secure registration and login system
- **Facility Discovery**: Browse and search available facilities with detailed information
- **Smart Filtering**: Filter facilities by category, price range, and capacity
- **Real-time Booking**: Interactive calendar for date and time selection
- **Payment Integration**: Upload payment receipts for reservation confirmation
- **Email Notifications**: Automated confirmations and reminders
- **Responsive Design**: Mobile-friendly interface that works on all devices
- **Reservation Management**: View and manage your booking history

### For Administrators
- **Comprehensive Dashboard**: Real-time statistics and system overview
- **Facility Management**: Add, edit, and manage facilities with image uploads
- **Category Management**: Organize facilities with custom categories
- **Reservation Oversight**: Approve, reject, and manage all bookings
- **User Management**: Monitor and manage user accounts
- **Usage Tracking**: Verify facility usage and completion
- **No-Show Management**: Track and report no-show incidents
- **Revenue Analytics**: Monitor booking statistics and revenue
- **Email System**: Automated notifications and reminders

## 🛠️ System Requirements

- **Web Server**: Apache/Nginx with PHP support
- **PHP**: Version 7.4 or higher
- **MySQL**: Version 5.7 or higher
- **Browser**: Modern web browser with JavaScript enabled
- **Extensions**: PDO, PDO_MySQL, JSON, GD (for image processing)

## 📦 Installation

### 1. Database Setup

1. Create a MySQL database:
```sql
CREATE DATABASE facility_reservation;
USE facility_reservation;
```

2. Import the database schema:
```bash
mysql -u your_username -p facility_reservation < database.sql
```

### 2. Configuration

1. Update database settings in `config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'facility_reservation');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
define('SITE_URL', 'http://your-domain.com/Facility_reservation');
```

2. Configure email settings in `config/email.php`:
```php
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password');
```

### 3. File Permissions

Ensure write permissions for upload directories:
```bash
chmod 755 uploads/
chmod 755 uploads/facilities/
chmod 755 uploads/payment_slips/
```

### 4. Web Server Setup

1. Place all files in your web server's document root
2. Ensure PHP extensions are enabled:
   - PDO and PDO_MySQL
   - JSON
   - GD (for image processing)
   - OpenSSL (for email)

## 👤 Default Accounts

### Admin Account
- **Username**: admin
- **Password**: admin123
- **Email**: admin@facility.com

### Demo User Account
- **Username**: user1
- **Password**: user123
- **Email**: user1@example.com

**⚠️ Important**: Change these default passwords immediately after installation!

## 📁 Project Structure

```
Facility_reservation/
├── admin/                    # Administrative interface
│   ├── dashboard.php        # Admin dashboard
│   ├── facilities.php       # Facility management
│   ├── categories.php       # Category management
│   ├── users.php            # User management
│   ├── reservations.php     # Reservation management
│   ├── usage_management.php # Usage tracking
│   └── no_show_reports.php  # No-show reporting
├── auth/                    # Authentication system
│   ├── auth.php            # Auth class
│   ├── login.php           # Login page
│   ├── register.php        # Registration page
│   └── logout.php          # Logout script
├── classes/                 # Core classes
│   ├── PaymentManager.php  # Payment handling
│   ├── EmailMailer.php     # Email notifications
│   └── UsageManager.php    # Usage tracking
├── config/                  # Configuration files
│   ├── database.php        # Database settings
│   └── email.php           # Email configuration
├── cron/                    # Automated tasks
│   ├── check_expired_payments.php
│   └── auto_complete_usage.php
├── emails/                  # Email templates
├── uploads/                 # File uploads
│   ├── facilities/         # Facility images
│   └── payment_slips/      # Payment receipts
├── assets/                  # Static assets
│   ├── css/               # Stylesheets
│   └── js/                # JavaScript files
├── vendor/                  # Composer dependencies
├── index.php               # Main homepage
├── reservation.php         # Booking page
├── facility_details.php    # Facility details
├── my_reservations.php     # User reservations
├── upload_payment.php      # Payment upload
└── README.md              # This file
```

## 🎯 Usage Guide

### For Users

1. **Registration**: Create an account with your details
2. **Browse Facilities**: View available facilities on the homepage
3. **Filter & Search**: Use filters to find specific facilities
4. **Book Facility**: Click "Book Now" and select your preferred time
5. **Submit Reservation**: Fill in details and submit your booking
6. **Upload Payment**: Upload payment receipt for confirmation
7. **Wait for Approval**: Admins will review and approve your reservation

### For Administrators

1. **Login**: Access admin panel with admin credentials
2. **Dashboard**: Monitor system statistics and recent activities
3. **Manage Reservations**: Review and approve pending bookings
4. **Facility Management**: Add, edit, or remove facilities
5. **User Management**: Monitor user accounts and activities
6. **Reports**: Generate detailed reports and analytics

## 🔧 Customization

### Styling
The system uses Tailwind CSS for styling. Customize by:
- Modifying Tailwind configuration in pages
- Adding custom CSS classes
- Updating color schemes in configuration

### Features
To add new features:
1. Create new PHP files for functionality
2. Update database schema if needed
3. Add navigation links
4. Update admin dashboard

## 🔒 Security Features

- **Password Hashing**: Secure password storage with PHP's password_hash()
- **SQL Injection Prevention**: Prepared statements throughout
- **XSS Protection**: Proper output escaping
- **Session Management**: Secure session handling
- **Input Validation**: Comprehensive form validation
- **File Upload Security**: Secure file upload handling

## 📊 No-Show Management

The system includes comprehensive no-show handling:

- **Definition**: No-show occurs when users don't arrive within 15 minutes
- **Payment Policy**: All no-show payments are non-refundable
- **Account Impact**: Repeated no-shows may result in restrictions
- **Waitlist Processing**: Automatic waitlist processing for no-shows
- **Admin Tools**: Mark no-shows and generate detailed reports

## 🚨 Troubleshooting

### Common Issues

1. **Database Connection Error**
   - Verify database credentials in `config/database.php`
   - Ensure MySQL service is running
   - Check database exists and is accessible

2. **Permission Errors**
   - Set proper permissions for upload directories
   - Ensure web server has read/write access

3. **Email Not Working**
   - Check SMTP settings in `config/email.php`
   - Verify email credentials and server settings
   - Test with a simple email first

4. **Page Not Found**
   - Check file paths and URLs
   - Verify .htaccess configuration (Apache)
   - Ensure all files are in correct locations

## 📈 Performance Optimization

- **Database Indexing**: Ensure proper indexes on frequently queried columns
- **Image Optimization**: Compress facility images before upload
- **Caching**: Consider implementing caching for frequently accessed data
- **CDN**: Use CDN for static assets in production

## 🔄 Maintenance

### Regular Tasks
- Monitor error logs
- Backup database regularly
- Update system dependencies
- Review and clean up old files
- Monitor disk space usage

### Automated Tasks
The system includes cron jobs for:
- Checking expired payments
- Auto-completing usage records
- Sending reminder emails

## 📞 Support

For technical support:
1. Check the troubleshooting section
2. Review code comments for implementation details
3. Ensure all system requirements are met
4. Check error logs for specific issues

## 📄 License

This project is open source and available under the MIT License.

## 🤝 Contributing

Contributions are welcome! Please:
- Fork the repository
- Create a feature branch
- Make your changes
- Submit a pull request

---

**Note**: This is a production-ready system. For deployment, consider implementing additional security measures, backup systems, and performance optimizations based on your specific requirements.
