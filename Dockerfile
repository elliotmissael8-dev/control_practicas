FROM php:8.2-cli-alpine

# Instalar extensiones necesarias de MySQL y dependencias
RUN apk add --no-cache tzdata \
    && docker-php-ext-install pdo pdo_mysql fileinfo

# Zona horaria y límites
ENV TZ="America/Mexico_City"
RUN echo "date.timezone = America/Mexico_City" > /usr/local/etc/php/conf.d/timezone.ini \
    && echo "upload_max_filesize = 12M\npost_max_size = 12M" > /usr/local/etc/php/conf.d/custom.ini

# Copiar archivos al directorio de trabajo
WORKDIR /app
COPY . /app

# Crear carpetas de uploads
RUN mkdir -p /app/uploads/documentos /app/uploads/archivo

# Puerto por defecto
ENV PORT=80
EXPOSE 80

# Iniciar servidor PHP directo (sin Apache, 0 errores de MPM)
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /app"]
