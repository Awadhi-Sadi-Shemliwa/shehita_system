FROM php:8.2-apache

# The app uses mysqli; install and enable it
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# GD powers the brand-colour extraction in lib/brand.php: a new company uploads
# a logo at signup and the system reads their brand colour out of the image.
# jpeg/freetype/webp so we can read whatever format they upload.
#
# unzip is required by Composer: without it `composer install` cannot unpack
# packages and fails with "The zip extension and unzip/7z commands are both
# missing". That failure is easy to miss locally, because the bind mount in
# development supplies a vendor/ directory built on the host — but in production
# it would leave vendor/ empty and the AI assistant silently unavailable.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev \
        unzip git \
 && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
 && docker-php-ext-install gd \
 && rm -rf /var/lib/apt/lists/*

# Path-based tenant routing (/<company>/login.php) is done by .htaccess, which
# needs mod_rewrite AND AllowOverride — the base image ships with overrides off,
# so without this the rewrite rules are silently ignored and every company URL
# 404s.
RUN a2enmod rewrite headers
RUN printf '<Directory /var/www/html>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' > /etc/apache2/conf-available/shehita-overrides.conf \
 && a2enconf shehita-overrides

# Allow larger uploads (logos / files)
RUN { \
      echo "upload_max_filesize = 20M"; \
      echo "post_max_size = 25M"; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# Composer, for the Anthropic PHP SDK used by the AI assistant.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies are installed at build time so the image is self-contained.
# NOTE: docker-compose.prod.yml bind-mounts the repo over /var/www/html, which
# would hide this vendor/ directory — that compose file declares an anonymous
# volume on /var/www/html/vendor specifically to keep this copy visible.
COPY composer.json composer.lock* ./

# Fail the build if dependencies cannot be installed.
#
# This used to end in `|| echo "skipped"`, which turned a genuine failure into a
# passing build that produced an image with no vendor/ directory. The only thing
# tolerated here is the absence of composer.lock; anything else is a real error
# and should stop the build rather than surface later as a mysteriously missing
# AI assistant.
RUN if [ -f composer.lock ]; then \
        composer install --no-dev --no-interaction --no-progress --optimize-autoloader; \
    else \
        echo "No composer.lock present - skipping dependency install."; \
    fi
