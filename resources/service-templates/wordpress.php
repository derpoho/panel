<?php

return [
    'name' => 'WordPress',
    'category' => 'Self-hosted',
    'description' => 'WordPress with its own MariaDB, installed and ready to log in to.',
    'icon' => 'wordpress',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/wp-includes/images/blank.gif',
    'notes' => 'Log in at /wp-admin with the admin user and password below. No install wizard to click through.',
    'env' => [
        ['key' => 'WP_URL', 'label' => 'Site URL', 'default' => '{url}', 'hint' => 'Set from the domain. Redeploy with the new domain if it changes.'],
        ['key' => 'WP_TITLE', 'label' => 'Site title', 'default' => 'My WordPress site'],
        ['key' => 'WP_ADMIN_USER', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'WP_ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:20}', 'secret' => true],
        ['key' => 'WP_ADMIN_EMAIL', 'label' => 'Admin email', 'default' => 'admin@example.com', 'hint' => 'Where WordPress sends password resets and updates.'],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  wordpress:
    image: wordpress:6-php8.3-apache
    restart: unless-stopped
    depends_on: [db]
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: ${DB_PASSWORD}
      WORDPRESS_DB_NAME: wordpress
      WORDPRESS_CONFIG_EXTRA: |
        define('WP_HOME', getenv('WP_URL'));
        define('WP_SITEURL', getenv('WP_URL'));
      WP_URL: ${WP_URL}
      WP_TITLE: ${WP_TITLE}
      WP_ADMIN_USER: ${WP_ADMIN_USER}
      WP_ADMIN_PASSWORD: ${WP_ADMIN_PASSWORD}
      WP_ADMIN_EMAIL: ${WP_ADMIN_EMAIL}
    # Copy WordPress in and write wp-config.php, install it once with the
    # settings above (so there is no setup wizard), then start Apache.
    entrypoint:
      - bash
      - -c
      - |
        set -e
        docker-ensure-installed.sh true
        cat > /tmp/serveros-install.php <<'PHP'
        <?php
        $$url = rtrim(getenv('WP_URL'), '/');
        for ($$try = 0; $$try < 60; $$try++) {
            try {
                new mysqli(getenv('WORDPRESS_DB_HOST'), getenv('WORDPRESS_DB_USER'), getenv('WORDPRESS_DB_PASSWORD'), getenv('WORDPRESS_DB_NAME'));
                break;
            } catch (Throwable $$e) {
                sleep(2);
            }
        }
        $$_SERVER['HTTP_HOST'] = parse_url($$url, PHP_URL_HOST) ?: 'localhost';
        define('WP_INSTALLING', true);
        require '/var/www/html/wp-load.php';
        if (is_blog_installed()) {
            exit(0);
        }
        function wp_new_blog_notification() {}
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        wp_install(getenv('WP_TITLE'), getenv('WP_ADMIN_USER'), getenv('WP_ADMIN_EMAIL'), true, '', wp_slash(getenv('WP_ADMIN_PASSWORD')));
        update_option('permalink_structure', '/%postname%/');
        fwrite(STDERR, "ServerOS: installed WordPress at $$url\n");
        PHP
        setpriv --reuid=www-data --regid=www-data --init-groups php /tmp/serveros-install.php
        exec docker-entrypoint.sh apache2-foreground
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - site:/var/www/html
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: wordpress
      MARIADB_USER: wordpress
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - db:/var/lib/mysql
volumes:
  site:
  db:
YAML,
    ],
];
