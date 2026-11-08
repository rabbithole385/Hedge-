# Nexora - production image for Railway, Render or any Docker host.
# Has its own built-in MariaDB: if no database is configured it starts one automatically.
FROM php:8.3-apache-bookworm

# PHP extensions
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libicu-dev mariadb-server mariadb-client \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd zip intl opcache \
 && (a2dismod -f mpm_event mpm_worker >/dev/null 2>&1 || true) \
 && a2enmod mpm_prefork rewrite headers remoteip \
 && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-hedgeinv.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
RUN echo '' > /etc/apache2/ports.conf
COPY docker/bin/ /usr/local/lib/hedgeinv/

WORKDIR /var/www/html
COPY . /var/www/html

# The cPanel-only PHP handler in .htaccess would break PHP under stock Apache.
RUN sed -i '/# php -- BEGIN cPanel-generated handler/,/# php -- END cPanel-generated handler/d' .htaccess \
 && rm -rf docker render Dockerfile render.yaml .dockerignore \
 && mkdir -p assets/uploads/kyc assets/uploads/deposit_proofs assets/uploads/experts \
 && cp -a assets/uploads /opt/uploads-seed \
 && chown -R www-data:www-data /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/hedgeinv-entrypoint
# Strip any CR (Windows line endings) so the shebang resolves; then make it executable.
RUN sed -i 's/\r$//' /usr/local/bin/hedgeinv-entrypoint \
 && chmod +x /usr/local/bin/hedgeinv-entrypoint

ENV PORT=10000
EXPOSE 10000
ENTRYPOINT ["hedgeinv-entrypoint"]
CMD ["apache2-foreground"]
