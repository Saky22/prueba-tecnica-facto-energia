FROM php:8.3.36-cli-bookworm
# Drivers de SQL Server para PHP (ODBC Driver 18 + sqlsrv / pdo_sqlsrv) y Composer.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl gnupg git unzip libicu-dev unixodbc-dev \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list -o /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && pecl install sqlsrv-5.12.0 pdo_sqlsrv-5.12.0 \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && docker-php-ext-install intl \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP dependencies (versiones fijadas por composer.lock)
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts

# Copy application source
COPY . .

# Expose port and start PHP built-in server bound to all interfaces
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "web", "web/index.php"]
