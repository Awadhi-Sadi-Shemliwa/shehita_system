FROM php:8.2-apache

# The app uses mysqli; install and enable it
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# Allow larger uploads (logos / files) for convenience during local testing
RUN { \
      echo "upload_max_filesize = 20M"; \
      echo "post_max_size = 25M"; \
    } > /usr/local/etc/php/conf.d/uploads.ini
