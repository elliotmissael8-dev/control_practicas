FROM php:8.2-apache

# Habilitar extensiones necesarias
RUN docker-php-ext-install pdo pdo_mysql fileinfo

# Habilitar mod_rewrite
RUN a2enmod rewrite

# Configurar Apache para usar la variable $PORT que inyecta Railway
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/sites-available/000-default.conf

# Zona horaria y límites
RUN echo "date.timezone = America/Mexico_City" > /usr/local/etc/php/conf.d/timezone.ini
RUN echo "upload_max_filesize = 12M\npost_max_size = 12M" > /usr/local/etc/php/conf.d/custom.ini

# Copiar el código
COPY . /var/www/html/

# Carpetas de subidas
RUN mkdir -p /var/www/html/uploads/documentos /var/www/html/uploads/archivo \
    && chown -R www-data:www-data /var/www/html/uploads

# Script de arranque limpio que evita el error 'More than one MPM loaded'
CMD ["sh", "-c", "rm -f /var/run/apache2/apache2.pid && exec apache2 -DFOREGROUND"]
