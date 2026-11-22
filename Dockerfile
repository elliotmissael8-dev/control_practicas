FROM php:8.2-apache

# Extensiones de PHP
RUN docker-php-ext-install pdo pdo_mysql fileinfo

# Mod rewrite
RUN a2enmod rewrite

# Ajustar Apache para que escuche en el puerto dinámico de Railway (o 80 por defecto)
RUN sed -i 's/Listen 80/Listen ${PORT}/g' /etc/apache2/ports.conf
RUN sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/g' /etc/apache2/sites-available/000-default.conf
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/sites-available/000-default.conf

# Zona horaria
RUN echo "date.timezone = America/Mexico_City" > /usr/local/etc/php/conf.d/timezone.ini
RUN echo "upload_max_filesize = 12M\npost_max_size = 12M" > /usr/local/etc/php/conf.d/custom.ini

# Copiar archivos
COPY . /var/www/html/

# Permisos de carpetas
RUN mkdir -p /var/www/html/uploads/documentos /var/www/html/uploads/archivo \
    && chown -R www-data:www-data /var/www/html/uploads

# Puerto por defecto si no lo pasa Railway
ENV PORT=80

EXPOSE 80

# Arranque oficial
CMD ["apache2-foreground"]
