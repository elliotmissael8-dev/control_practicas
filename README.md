# Control de Prácticas Profesionales

Aplicación PHP para registrar y dar seguimiento a estudiantes de prácticas profesionales y servicio social.

## Requisitos

- Docker y Docker Compose, o
- PHP 8.2+ con las extensiones PDO MySQL y fileinfo, y MySQL/MariaDB

## Inicio rápido con Docker

1. Copie la plantilla de variables:

   ```bash
   cp .env.example .env
   ```

2. Cambie todas las contraseñas de `.env`.

3. Inicie los servicios:

   ```bash
   docker compose up --build
   ```

4. Abra `http://localhost:8080/login.php`.

La base de datos se almacena en el volumen `db_data` y los archivos subidos en `app_uploads`. El puerto local de MySQL es `3307` para evitar conflictos con una instalación local.

## Importar el esquema

Con Docker el esquema se importa solo la primera vez. Para una base nueva sin Docker, cree la base `control_practicas` e importe `database.sql` (phpMyAdmin → Importar, o con MySQL):

```bash
mysql -h 127.0.0.1 -P 3307 -u root -p control_practicas < database.sql
```

`database.sql` es el único esquema y la fuente de verdad. Si ya tenía una base creada con el esquema anterior, la aplicación agrega sola las columnas que falten al abrir el sistema.

## Ejecución local sin Docker

1. Configure una base MySQL/MariaDB.
2. Exporte las variables `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_DATABASE`, `MYSQL_USER` y `MYSQL_PASSWORD`.
3. Cree las carpetas `uploads/documentos` y `uploads/archivo`.
4. Inicie PHP:

```bash
php -S 0.0.0.0:8080 -t . router.php
```

## Variables de correo

Configure una contraseña de aplicación de Gmail y añada estas variables en Railway:

- `SMTP_HOST=smtp.gmail.com`
- `SMTP_PORT=587`
- `SMTP_USER=cuenta@gmail.com`
- `SMTP_PASS=contraseña-de-aplicación`
- `SMTP_FROM=cuenta@gmail.com`
- `SMTP_NAME=Control de Prácticas`
- `APP_URL=https://tu-dominio.up.railway.app`

Revoca y sustituye cualquier credencial SMTP publicada anteriormente.

## Variables de base de datos

- `MYSQL_URL` o `DATABASE_URL`: URL completa de MySQL. Tiene prioridad sobre las variables individuales.
- `MYSQLHOST`/`MYSQL_HOST`/`DB_HOST`: servidor.
- `MYSQLPORT`/`MYSQL_PORT`/`DB_PORT`: puerto.
- `MYSQLDATABASE`/`MYSQL_DATABASE`/`DB_NAME`: base de datos.
- `MYSQLUSER`/`MYSQL_USER`/`DB_USER`: usuario.
- `MYSQLPASSWORD`/`MYSQL_PASSWORD`/`DB_PASS`: contraseña.

## Seguridad

- Con `php -S` use siempre `router.php` (bloquea `config/`, `uploads/` y archivos `.sql`/`.key`); con Apache/XAMPP se usa el `.htaccess` incluido.
- Defina `QR_SECRET` (y opcionalmente `CLAVE_SECRET`) con valores aleatorios de al menos 32 caracteres.
- No haga commits de `.env`, claves privadas ni archivos de `uploads/`.
- Cambie las contraseñas predeterminadas antes de desplegar.
- Si una contraseña o clave ya fue publicada, rótela inmediatamente.
- Mantenga copias de seguridad cifradas y pruebe periódicamente su restauración.
