AIHubpedia Contact + PHPMailer Patch

1) Upload these files into your project root:
   - contact.php
   - contact_send.php

2) Install PHPMailer with Composer:
   cd /var/www/html/AI
   composer require phpmailer/phpmailer

3) Add mail settings in data/site.json (example):
   "mail": {
     "from_email": "no-reply@yourdomain.com",
     "from_name": "AIHubpedia",
     "to_email": "you@yourdomain.com",
     "smtp_host": "smtp.gmail.com",
     "smtp_port": 587,
     "smtp_secure": "tls",
     "smtp_user": "you@yourdomain.com",
     "smtp_pass": "YOUR_APP_PASSWORD"
   }

4) Permissions (if needed):
   chown -R www-data:www-data /var/www/html/AI/data
   chmod -R 775 /var/www/html/AI/data

Notes:
- If using Gmail, you MUST use an App Password.
- contact_send.php uses CSRF + honeypot spam protection.
