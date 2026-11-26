FROM php:8.2-cli-alpine

RUN apk add --no-cache tzdata openssl \
    && docker-php-ext-install pdo pdo_mysql fileinfo

ENV TZ="America/Mexico_City" \
    PORT=80
RUN echo "date.timezone = America/Mexico_City" > /usr/local/etc/php/conf.d/timezone.ini \
    && printf 'upload_max_filesize = 12M\npost_max_size = 14M\nmax_execution_time = 120\n' > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /app
COPY --chown=www-data:www-data . /app

RUN mkdir -p /app/uploads/documentos /app/uploads/archivo \
    && chown -R www-data:www-data /app/uploads \
    && find /app/uploads -type d -exec chmod 0750 {} \; \
    && find /app/uploads -type f -exec chmod 0640 {} \;

USER www-data
EXPOSE 80

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /app /app/router.php"]
