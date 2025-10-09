FROM composer:latest AS composer

FROM php:8.2-fpm-alpine AS builder

WORKDIR /app

# Install system dependencies and build tools
RUN apk add --no-cache --virtual .build-deps \
    bash \
    icu-dev \
    postgresql-dev \
    libxml2-dev \
    oniguruma-dev \
    libzip-dev \
    git \
    unzip \
    && docker-php-ext-install intl pdo pdo_pgsql opcache zip

# Copy composer from official image
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Copy composer files first for better layer caching
COPY composer.json composer.lock symfony.lock* ./

# Install PHP dependencies
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --optimize-autoloader

# Copy application code
COPY . .

# Generate optimized autoloader
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# Verify Kernel exists
RUN test -f src/Kernel.php || (echo "❌ src/Kernel.php not found!" && exit 1)

# Generate JWT keys if needed
RUN mkdir -p config/jwt \
    && php bin/console lexik:jwt:generate-keypair --skip-if-exists 2>/dev/null || true

# Warm up Symfony cache
RUN APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear --no-warmup \
    && APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup

# Clean up build dependencies
RUN apk del .build-deps

# =============================================================================
# Stage 2: Runtime - Production image with Nginx + PHP-FPM + Supervisor
# =============================================================================
FROM php:8.2-fpm-alpine

# Install runtime dependencies
RUN apk add --no-cache \
    bash \
    nginx \
    supervisor \
    icu-libs \
    postgresql-libs \
    libxml2 \
    oniguruma \
    libzip \
    fcgi

# Copy PHP extensions from builder
COPY --from=builder /usr/local/etc/php/conf.d/docker-php-ext-*.ini /usr/local/etc/php/conf.d/
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions

# Configure PHP for production
RUN { \
        echo '[PHP]'; \
        echo 'expose_php = Off'; \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 60'; \
        echo 'upload_max_filesize = 10M'; \
        echo 'post_max_size = 10M'; \
        echo 'date.timezone = Europe/Paris'; \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'error_log = /proc/self/fd/2'; \
    } > /usr/local/etc/php/conf.d/php-prod.ini

# Configure OPcache for production
RUN { \
        echo '[opcache]'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.memory_consumption = 256'; \
        echo 'opcache.interned_strings_buffer = 16'; \
        echo 'opcache.max_accelerated_files = 20000'; \
        echo 'opcache.validate_timestamps = 0'; \
        echo 'opcache.save_comments = 1'; \
        echo 'opcache.enable_cli = 0'; \
    } > /usr/local/etc/php/conf.d/opcache-prod.ini

# Configure PHP-FPM to use Unix socket
RUN { \
        echo '[www]'; \
        echo 'listen = /var/run/php-fpm.sock'; \
        echo 'listen.owner = nginx'; \
        echo 'listen.group = nginx'; \
        echo 'listen.mode = 0660'; \
        echo 'pm = dynamic'; \
        echo 'pm.max_children = 20'; \
        echo 'pm.start_servers = 2'; \
        echo 'pm.min_spare_servers = 1'; \
        echo 'pm.max_spare_servers = 3'; \
        echo 'pm.max_requests = 500'; \
        echo 'pm.status_path = /status'; \
        echo 'ping.path = /ping'; \
        echo 'ping.response = pong'; \
        echo 'catch_workers_output = yes'; \
        echo 'decorate_workers_output = no'; \
    } > /usr/local/etc/php-fpm.d/zz-docker.conf

# Configure Nginx
RUN { \
        echo 'server {'; \
        echo '    listen 3040 default_server;'; \
        echo '    server_name _;'; \
        echo '    root /var/www/html/public;'; \
        echo '    index index.php;'; \
        echo ''; \
        echo '    client_max_body_size 10M;'; \
        echo '    fastcgi_read_timeout 300;'; \
        echo ''; \
        echo '    location / {'; \
        echo '        try_files $uri /index.php$is_args$args;'; \
        echo '    }'; \
        echo ''; \
        echo '    location ~ ^/index\.php(/|$) {'; \
        echo '        fastcgi_pass unix:/var/run/php-fpm.sock;'; \
        echo '        fastcgi_split_path_info ^(.+\.php)(/.*)$;'; \
        echo '        include fastcgi_params;'; \
        echo '        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;'; \
        echo '        fastcgi_param DOCUMENT_ROOT $realpath_root;'; \
        echo '        fastcgi_buffer_size 128k;'; \
        echo '        fastcgi_buffers 4 256k;'; \
        echo '        fastcgi_busy_buffers_size 256k;'; \
        echo '        internal;'; \
        echo '    }'; \
        echo ''; \
        echo '    location ~ \.php$ {'; \
        echo '        return 404;'; \
        echo '    }'; \
        echo ''; \
        echo '    location ~ /\. {'; \
        echo '        deny all;'; \
        echo '    }'; \
        echo '}'; \
    } > /etc/nginx/http.d/default.conf

# Configure Supervisor to manage Nginx and PHP-FPM
RUN { \
        echo '[supervisord]'; \
        echo 'nodaemon=true'; \
        echo 'user=root'; \
        echo 'logfile=/dev/stdout'; \
        echo 'logfile_maxbytes=0'; \
        echo 'pidfile=/var/run/supervisord.pid'; \
        echo ''; \
        echo '[program:php-fpm]'; \
        echo 'command=php-fpm -F'; \
        echo 'stdout_logfile=/dev/stdout'; \
        echo 'stdout_logfile_maxbytes=0'; \
        echo 'stderr_logfile=/dev/stderr'; \
        echo 'stderr_logfile_maxbytes=0'; \
        echo 'autorestart=true'; \
        echo 'priority=10'; \
        echo ''; \
        echo '[program:nginx]'; \
        echo 'command=nginx -g "daemon off;"'; \
        echo 'stdout_logfile=/dev/stdout'; \
        echo 'stdout_logfile_maxbytes=0'; \
        echo 'stderr_logfile=/dev/stderr'; \
        echo 'stderr_logfile_maxbytes=0'; \
        echo 'autorestart=true'; \
        echo 'priority=20'; \
    } > /etc/supervisord.conf

# Copy application from builder
WORKDIR /var/www/html
COPY --from=builder --chown=nginx:nginx /app ./

# Create required directories with correct permissions
RUN mkdir -p var/cache var/log public/uploads \
    && chown -R nginx:nginx var public/uploads \
    && chmod -R 775 var public/uploads

# Expose port for CapRover
EXPOSE 3040

# Simple HTTP healthcheck
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget --quiet --tries=1 --spider http://localhost:3040/ping || exit 1

# Start Supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
