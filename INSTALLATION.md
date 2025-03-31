# PhoenixPanel Installation Guide (Ubuntu 22.04)

This guide will walk you through installing the PhoenixPanel web panel software on Ubuntu 22.04.

**Note:** This guide uses PHP 8.3. Please verify this is the correct PHP version required by your specific PhoenixPanel version and adjust commands if necessary.

## 1. Prerequisites

*   **Update System:**
    ```bash
    sudo apt update && sudo apt upgrade -y
    ```
*   **Install Web Server (Nginx Recommended) & Utilities:**
    ```bash
    sudo apt install -y nginx curl tar unzip git
    sudo systemctl enable --now nginx
    ```
*   **Install Database Server (MariaDB Recommended):**
    ```bash
    sudo apt install -y mariadb-server
    sudo systemctl enable --now mariadb
    # Follow prompts to secure DB & set root password
    sudo mysql_secure_installation
    ```
*   **Install Redis:**
    ```bash
    sudo apt install -y redis-server
    sudo systemctl enable --now redis-server
    ```
*   **Install PHP 8.3:**
    ```bash
    sudo apt install -y software-properties-common # Needed for add-apt-repository
    sudo add-apt-repository ppa:ondrej/php -y # Recommended PPA for latest PHP versions
    sudo apt update
    sudo apt install -y php8.3 php8.3-cli php8.3-gd php8.3-mysql php8.3-pdo php8.3-mbstring php8.3-tokenizer php8.3-bcmath php8.3-xml php8.3-fpm php8.3-curl php8.3-zip
    ```
*   **Install Composer v2:**
    ```bash
    curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
    ```
*   **Install Node.js (e.g., v18) & Yarn:**
    ```bash
    # Using NodeSource repository for Node.js v18
    curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
    sudo apt install -y nodejs
    # Install Yarn globally using npm
    sudo npm install -g yarn
    ```
*   **Firewall (UFW - Optional but Recommended):**
    ```bash
    # Allow SSH, HTTP, HTTPS
    sudo ufw allow ssh
    sudo ufw allow http
    sudo ufw allow https
    # Enable the firewall
    sudo ufw enable
    ```

## 2. Download Panel Files

*   Create the installation directory and set permissions.
    ```bash
    sudo mkdir -p /var/www/phoenixpanel
    # Set initial ownership to your user to clone without sudo
    # Replace 'your_user' with your actual non-root username
    sudo chown $USER:$USER /var/www/phoenixpanel
    cd /var/www/phoenixpanel
    ```
*   Download and extract the panel files.
    ```bash
    # Download the latest release archive
    curl -L https://github.com/SHBenno/phoenixpanel-dev/archive/refs/tags/latest.tar.gz | sudo tar -xz --strip-components=1 -C /var/www/phoenixpanel
    ```

## 3. Install Dependencies

*   Copy the example environment file and generate the application key.
    ```bash
    cp .env.example .env
    php artisan key:generate --force
    ```
*   Install PHP dependencies using Composer (run as your user).
    ```bash
    composer install --no-dev --optimize-autoloader
    ```
*   Install Node.js dependencies and build assets (run as your user).
    ```bash
    yarn install --production
    yarn build:production
    ```

## 4. Configure Environment

*   Edit the `.env` file using a text editor (e.g., `nano .env`):
    *   `APP_NAME="PhoenixPanel"`
    *   `APP_ENV=production`
    *   `APP_KEY=` (Leave blank for now)
    *   `APP_DEBUG=false`
    *   `APP_URL=http://your.domain.com` (Replace with your panel's URL - use `https` later if setting up SSL)
    *   `APP_TIMEZONE='UTC'` (Adjust to your server's timezone, e.g., `Europe/London`)
    *   `APP_LOCALE=en`
    *   `DB_CONNECTION=mysql`
    *   `DB_HOST=127.0.0.1`
    *   `DB_PORT=3306`
    *   `DB_DATABASE=panel` (Or your chosen name from Step 5)
    *   `DB_USERNAME=phoenix` (Or your chosen username from Step 5)
    *   `DB_PASSWORD=yourPassword` (Your chosen password from Step 5)
    *   `REDIS_HOST=127.0.0.1`
    *   `REDIS_PASSWORD=null` (Unless you configured one)
    *   `REDIS_PORT=6379`
    *   `MAIL_*`: Configure your mail driver settings (e.g., SMTP). Using a transactional email service like Mailgun or SendGrid is recommended.

## 5. Database Setup

*   Log in to MariaDB/MySQL as root.
    ```bash
    sudo mysql -u root -p
    ```
*   Create the database and user (replace `panel`, `phoenix`, and `yourPassword` if you used different values in `.env`).
    ```sql
    CREATE DATABASE panel;
    CREATE USER 'phoenix'@'127.0.0.1' IDENTIFIED BY 'yourPassword';
    GRANT ALL PRIVILEGES ON panel.* TO 'phoenix'@'127.0.0.1' WITH GRANT OPTION;
    FLUSH PRIVILEGES;
    EXIT;
    ```
*   Run database migrations and seeders. This creates the necessary tables and populates some default data.
    ```bash
    php artisan migrate --seed --force
    ```

## 6. Create Admin User

*   Create the initial administrative user account.
    ```bash
    # --- CONFIRM this is the correct Artisan command for PhoenixPanel ---
    php artisan p:user:make
    ```
    *Follow the prompts to set up your admin username, email, and password.*

## 7. Set File Permissions

*   Set correct ownership for the webserver user (`www-data` for Nginx/Apache on Ubuntu) to allow writing to storage and cache.
    ```bash
    sudo chown -R www-data:www-data /var/www/phoenixpanel/storage/* /var/www/phoenixpanel/bootstrap/cache/
    ```

## 8. Configure Queue Worker (Supervisor)

*   Install Supervisor process manager.
    ```bash
    sudo apt install -y supervisor
    ```
*   Create a Supervisor configuration file for the PhoenixPanel worker.
    ```bash
    sudo nano /etc/supervisor/conf.d/phoenixpanel.conf
    ```
*   Paste the following configuration. This ensures background tasks (like server installs/backups) are processed.
    ```ini
    [program:phoenixpanel-worker]
    process_name=%(program_name)s_%(process_num)02d
    command=php /var/www/phoenixpanel/artisan queue:work --sleep=3 --tries=3 --max-time=3600
    autostart=true
    autorestart=true
    stopasgroup=true
    killasgroup=true
    user=www-data ; The user the worker runs as (must match webserver user)
    numprocs=1 ; Start one worker process
    redirect_stderr=true
    stdout_logfile=/var/www/phoenixpanel/storage/logs/worker.log
    stopwaitsecs=60
    ```
*   Inform Supervisor about the new configuration and start the worker.
    ```bash
    sudo supervisorctl reread
    sudo supervisorctl update
    sudo supervisorctl start phoenixpanel-worker:*
    ```
*   Check the worker status:
    ```bash
    sudo supervisorctl status phoenixpanel-worker:*
    ```

## 9. Configure Cron Job

*   Add the Laravel scheduler task to the webserver user's crontab to handle scheduled tasks (like backups).
    ```bash
    # Open crontab for the www-data user
    sudo crontab -u www-data -e
    ```
*   Add the following line at the bottom of the file:
    ```cron
    * * * * * php /var/www/phoenixpanel/artisan schedule:run >> /dev/null 2>&1
    ```
    *Save and close the editor.*

## 10. Configure Web Server (Nginx)

*   Create an Nginx server block configuration file.
    ```bash
    sudo nano /etc/nginx/sites-available/phoenixpanel.conf
    ```
*   Paste the following configuration (replace `your.domain.com` and verify the `fastcgi_pass` path matches your PHP-FPM version, e.g., `/run/php/php8.3-fpm.sock`).
    ```nginx
    server {
        listen 80;
        server_name your.domain.com; # Replace with your panel's domain

        root /var/www/phoenixpanel/public;
        index index.php;

        # Improve security with headers
        add_header X-Frame-Options "SAMEORIGIN" always;
        add_header X-XSS-Protection "1; mode=block" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        # Content-Security-Policy might need adjustments based on panel features/plugins
        add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self';" always;

        # Handle requests, try files directly, then directories, then fallback to index.php
        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        # Pass PHP scripts to PHP-FPM
        location ~ \.php$ {
            fastcgi_split_path_info ^(.+\.php)(/.+)$;
            # Verify this path matches your PHP-FPM socket
            fastcgi_pass unix:/run/php/php8.3-fpm.sock;
            fastcgi_index index.php;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_param PATH_INFO $fastcgi_path_info;
            # Adjust upload limits if needed
            fastcgi_param PHP_VALUE "upload_max_filesize = 100M \n post_max_size=100M";
        }

        # Block access to sensitive files/directories
        location ~ /\.ht { deny all; }
        location ~ /.well-known { allow all; } # Needed for Certbot HTTP validation
        location = /storage { deny all; }
        location = /vendor { deny all; }
        location = /.env { deny all; }
    }
    ```
*   Enable the site configuration by creating a symbolic link.
    ```bash
    sudo ln -s /etc/nginx/sites-available/phoenixpanel.conf /etc/nginx/sites-enabled/
    ```
*   Test the Nginx configuration for syntax errors.
    ```bash
    sudo nginx -t
    ```
*   Restart Nginx to apply the changes if the test is successful.
    ```bash
    sudo systemctl restart nginx
    ```

## 11. Configure SSL (Recommended - Using Certbot)

*   Install Certbot and the Nginx plugin.
    ```bash
    sudo apt install -y certbot python3-certbot-nginx
    ```
*   Obtain and install an SSL certificate (replace `your.domain.com`).
    ```bash
    # Ensure your domain's DNS points to this server's IP address
    sudo certbot --nginx -d your.domain.com
    ```
    *Follow the prompts.* Certbot will automatically modify your Nginx config for HTTPS (port 443) and set up a cron job for automatic certificate renewal. Remember to update `APP_URL` in your `.env` file to use `https://`.

## 12. Next Steps

*   **Install Wings:** Set up the PhoenixPanel Wings daemon on the machine(s) that will host the game servers. Refer to the official Wings documentation.
*   **Access Panel:** Open your web browser and navigate to your panel's URL (e.g., `https://your.domain.com`). Log in with the admin credentials created in Step 6.
*   **Configure Panel:** Go through the administrative settings in the panel UI to configure locations, nodes, nests, eggs, etc.
