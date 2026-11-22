FROM php:8.2-apache

# Habilitar extensiones necesarias
RUN docker-php-ext-install pdo pdo_mysql fileinfo

# Habilitar mod_rewrite de Apache
RUN a2enmod rewrite

# Configurar zona horaria
RUN echo "date.timezone = America/Mexico_City" > /usr/local/etc/php/conf.d/timezone.ini

# Configurar upload y sesiones
RUN echo "upload_max_filesize = 12M\npost_max_size = 12M\nsession.cookie_httponly = 1\nsession.use_strict_mode = 1\nsession.cookie_samesite = Lax" > /usr/local/etc/php/conf.d/custom.ini

# Copiar todo el proyecto al directorio web de Apache
COPY . /var/www/html/

# Crear directorios de uploads con permisos
RUN mkdir -p /var/www/html/uploads/documentos /var/www/html/uploads/archivo \
    && chown -R www-data:www-data /var/www/html/uploads

# Configurar Apache para permitir .htaccess
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/sites-available/000-default.conf

EXPOSE 80
