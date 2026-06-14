<?php
/**
 * Configuration file
 * --------------------------------------------------
 * Change ADMIN_USERNAME and ADMIN_PASSWORD_HASH to set
 * your own admin login.
 *
 * Default login is:
 *   username: admin
 *   password: admin123
 *
 * To generate a new password hash, run this on your server
 * (e.g. via a temporary PHP file or the command line):
 *
 *   <?php echo password_hash('your-new-password', PASSWORD_DEFAULT);
 *
 * Then paste the result below.
 */

session_start();

define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH', '$2y$10$SK7tZX6VPaweb2Tzaj.rHeIxNrjR4qkHTkCHJsq1aKD5vWSFu8zhu');

// Site name shown in the admin panel
define('SITE_TITLE', 'Katy Pest Pros');

// File paths
define('BASE_DIR', __DIR__);
define('DATA_FILE', BASE_DIR . '/data/site.json');
define('UPLOAD_DIR', BASE_DIR . '/uploads/');
define('UPLOAD_URL', 'uploads/');
