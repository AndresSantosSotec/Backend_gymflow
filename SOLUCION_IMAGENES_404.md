# 🔧 Solución: Errores 404 en Imágenes de Clientes (Producción)

## 📋 Diagnóstico del Problema

Los errores 404 indican que las imágenes de clientes no se están cargando desde:
```
https://irongymgt.com/storage/clients/photos/[filename].jpg
```

### Posibles Causas

1. ❌ **Symlink de Laravel Storage no creado**
2. ❌ **Imágenes no existen en el servidor de producción**
3. ❌ **Configuración incorrecta del servidor web**
4. ❌ **Permisos incorrectos en carpetas**

---

## ✅ Solución 1: Crear Symlink de Laravel Storage

El symlink conecta `public/storage` → `storage/app/public`.

```bash
# Conectar al servidor de producción vía SSH
ssh usuario@irongymgt.com

# Navegar al directorio del backend
cd /ruta/a/Backend-Gymflow

# Ejecutar el comando de Laravel
php artisan storage:link
```

**Salida Esperada:**
```
The [public/storage] link has been connected to [storage/app/public].
The links have been created.
```

### Si ya existe el symlink

Si obtienes error "symlink already exists":
```bash
# Eliminar el symlink existente
rm public/storage

# Volver a crear
php artisan storage:link
```

---

## ✅ Solución 2: Verificar que Existen las Imágenes

```bash
# Verificar que la carpeta existe
ls -la storage/app/public/clients/photos/

# Si no existe, crearla con permisos correctos
mkdir -p storage/app/public/clients/photos
chmod -R 775 storage/app/public/clients
chown -R www-data:www-data storage/app/public/clients
```

### Si las imágenes no existen en producción

Tienes **dos opciones**:

#### Opción A: Subir las imágenes desde tu máquina local

```bash
# Desde tu máquina local (Windows)
# Comprimir las fotos
cd d:\Gymflow\Backend-Gymflow
tar -czf client_photos.tar.gz storage/app/public/clients/photos

# Subir al servidor (usando SCP o SFTP)
scp client_photos.tar.gz usuario@irongymgt.com:/tmp/

# En el servidor
cd /ruta/a/Backend-Gymflow
tar -xzf /tmp/client_photos.tar.gz
chmod -R 775 storage/app/public/clients
chown -R www-data:www-data storage/app/public/clients
```

#### Opción B: Re-subir las fotos desde el frontend

Los usuarios tendrán que volver a subir las fotos de clientes desde el panel de administración.

---

## ✅ Solución 3: Verificar Configuración del Servidor Web

### Para Nginx

Editar `/etc/nginx/sites-available/irongymgt.com`:

```nginx
server {
    listen 80;
    server_name irongymgt.com www.irongymgt.com;
    root /var/www/Backend-Gymflow/public;
    
    index index.php index.html;

    # Importante: Servir archivos estáticos (imágenes, CSS, JS)
    location /storage {
        alias /var/www/Backend-Gymflow/storage/app/public;
        access_log off;
        expires max;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

**Recargar Nginx:**
```bash
sudo nginx -t
sudo systemctl reload nginx
```

### Para Apache

Editar `/etc/apache2/sites-available/irongymgt.com.conf`:

```apache
<VirtualHost *:80>
    ServerName irongymgt.com
    ServerAlias www.irongymgt.com
    DocumentRoot /var/www/Backend-Gymflow/public

    <Directory /var/www/Backend-Gymflow/public>
        AllowOverride All
        Require all granted
    </Directory>

    # Importante: Permitir acceso a storage
    Alias /storage /var/www/Backend-Gymflow/storage/app/public
    <Directory /var/www/Backend-Gymflow/storage/app/public>
        Options -Indexes
        AllowOverride None
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/irongymgt-error.log
    CustomLog ${APACHE_LOG_DIR}/irongymgt-access.log combined
</VirtualHost>
```

**Recargar Apache:**
```bash
sudo a2enmod rewrite
sudo apachectl configtest
sudo systemctl reload apache2
```

---

## ✅ Solución 4: Verificar Permisos

```bash
# Asignar permisos correctos a las carpetas de Laravel
cd /ruta/a/Backend-Gymflow

# Storage completo
chmod -R 775 storage
chown -R www-data:www-data storage

# Bootstrap cache
chmod -R 775 bootstrap/cache
chown -R www-data:www-data bootstrap/cache

# Public (donde está el symlink)
chmod -R 755 public
chown -R www-data:www-data public
```

**Nota:** Cambia `www-data` por el usuario de tu servidor web:
- Nginx/Apache: `www-data` (Ubuntu/Debian)
- Apache: `apache` (CentOS/RHEL)

---

## ✅ Solución 5: Verificar Variable de Entorno APP_URL

En el servidor de producción, editar `.env`:

```bash
nano /ruta/a/Backend-Gymflow/.env
```

Asegúrate que tenga:
```env
APP_URL=https://irongymgt.com
APP_ENV=production
FILESYSTEM_DISK=public
```

Luego:
```bash
php artisan config:cache
php artisan config:clear
```

---

## 🧪 Probar la Solución

### Test 1: Verificar symlink

```bash
ls -la public/storage
# Debería mostrar: storage -> ../storage/app/public
```

### Test 2: Verificar acceso directo a una imagen

```bash
# En el navegador, probar esta URL directamente:
https://irongymgt.com/storage/clients/photos/UdJjAdlCyYE2Drl5VXMdvsQ8KiKetqnUPk9uoJB6.jpg
```

Si carga la imagen, el problema está resuelto.

### Test 3: Verificar desde curl

```bash
curl -I https://irongymgt.com/storage/clients/photos/UdJjAdlCyYE2Drl5VXMdvsQ8KiKetqnUPk9uoJB6.jpg
```

**Respuesta esperada:**
```
HTTP/2 200
content-type: image/jpeg
```

**Si devuelve 404:**
- El archivo no existe
- El symlink no está bien configurado
- El servidor web no está configurando la ruta `/storage` correctamente

---

## 📝 Checklist de Verificación

- [ ] Ejecutado `php artisan storage:link` en producción
- [ ] Verificado que existe `storage/app/public/clients/photos/`
- [ ] Permisos correctos: `chmod -R 775 storage`
- [ ] Owner correcto: `chown -R www-data:www-data storage`
- [ ] Configuración de Nginx/Apache actualizada
- [ ] Variable `APP_URL` correcta en `.env`
- [ ] Ejecutado `php artisan config:cache`
- [ ] Test de imagen directa en navegador funciona

---

## 🚨 Problema Persistente

Si después de todos los pasos las imágenes siguen sin cargar:

### Opción 1: Usar base64 (no recomendado para producción)

Las imágenes se pueden guardar como base64 en la base de datos, pero esto aumenta significativamente el tamaño de la BD.

### Opción 2: Usar S3/Cloud Storage

Configurar AWS S3 o un servicio similar para almacenar las imágenes:

```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=tu_key
AWS_SECRET_ACCESS_KEY=tu_secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=irongym-photos
```

### Opción 3: Verificar logs del servidor

```bash
# Nginx
tail -f /var/log/nginx/error.log

# Apache
tail -f /var/log/apache2/error.log

# Laravel
tail -f storage/logs/laravel.log
```

---

## 📞 Soporte Adicional

Si el problema persiste, revisa:

1. **Logs del servidor web** para ver si hay errores de permisos
2. **Logs de Laravel** en `storage/logs/laravel.log`
3. **SELinux** (si está habilitado): `setenforce 0` temporalmente para probar
4. **Firewall** que no esté bloqueando acceso a `/storage`

---

**Última actualización:** 26 de febrero de 2026
