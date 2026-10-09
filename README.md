# Gather

Gather is a lightweight group-chat web app built with PHP, MySQL, HTML, CSS, and vanilla JavaScript. It uses a server-client model: PHP handles room membership and chat APIs, MySQL stores rooms and messages, and browser clients poll for new messages.

## Run locally

Requirements: PHP 8.1+ with `pdo_mysql` and `mbstring`, plus MySQL.

1. Ensure MySQL is running. The local-only `config.local.php` is already configured for the provided local MySQL root account. It is git-ignored and is outside the web document root.
2. From this folder, create the database and tables:

   ```powershell
   php setup.php
   ```

   The MySQL account must be allowed to create databases.
3. Start PHP's development server:

   ```powershell
   php -S 127.0.0.1:8000 -t public
   ```

4. Open <http://127.0.0.1:8000>.

To use a different local account or database, edit `config.local.php`; do not move it into `public`.

## How it works

- Create a room with a name and display name. The creator becomes the room admin and receives a 4-character hexadecimal invite code.
- Join with an invite code and display name. Room codes are case-insensitive.
- Messages are stored in MySQL and refreshed every 2.5 seconds. The latest 100 messages are loaded when opening a room.
- The creator can close a room for everyone. Leaving a room does not close it.
- Room membership is held in a PHP session; requests that change data require a session CSRF token.

This is intended for local development. For an internet-facing deployment, use HTTPS, a least-privilege MySQL account, and a production PHP server configuration.
