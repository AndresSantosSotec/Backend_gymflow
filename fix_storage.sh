#!/bin/bash

###############################################################################
# Script de Corrección Automática: Laravel Storage en Producción
# Este script soluciona problemas comunes con imágenes 404 en Laravel
###############################################################################

set -e

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}"
echo "╔═══════════════════════════════════════════════════════╗"
echo "║   Laravel Storage Fix - IronGym Production            ║"
echo "╚═══════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Verificar que estamos en el directorio correcto
if [ ! -f "artisan" ]; then
    echo -e "${RED}❌ Error: No se encuentra el archivo 'artisan'${NC}"
    echo "Ejecuta este script desde el directorio raíz de Laravel (Backend-Gymflow)"
    exit 1
fi

echo -e "${YELLOW}📋 Paso 1: Verificando estructura de directorios...${NC}"

# Crear directorios si no existen
mkdir -p storage/app/public/clients/photos
mkdir -p storage/app/public/products
mkdir -p storage/app/public/blog
mkdir -p storage/app/public/documents
mkdir -p public/storage

echo -e "${GREEN}✓ Directorios verificados/creados${NC}"

echo -e "${YELLOW}📋 Paso 2: Eliminando symlink antiguo (si existe)...${NC}"

# Eliminar symlink antiguo
if [ -L "public/storage" ]; then
    rm public/storage
    echo -e "${GREEN}✓ Symlink antiguo eliminado${NC}"
elif [ -d "public/storage" ]; then
    echo -e "${RED}⚠ public/storage es un directorio, no un symlink. Eliminando...${NC}"
    rm -rf public/storage
fi

echo -e "${YELLOW}📋 Paso 3: Creando nuevo symlink de Laravel...${NC}"

# Crear symlink
php artisan storage:link

echo -e "${GREEN}✓ Symlink creado correctamente${NC}"

echo -e "${YELLOW}📋 Paso 4: Configurando permisos...${NC}"

# Detectar usuario del servidor web
if command -v nginx &> /dev/null; then
    WEB_USER="www-data"
elif command -v apache2 &> /dev/null || command -v httpd &> /dev/null; then
    if [ -f /etc/redhat-release ]; then
        WEB_USER="apache"
    else
        WEB_USER="www-data"
    fi
else
    echo -e "${YELLOW}⚠ No se pudo detectar el servidor web. Usando www-data por defecto.${NC}"
    WEB_USER="www-data"
fi

echo "Usuario del servidor web detectado: $WEB_USER"

# Configurar permisos
chmod -R 775 storage
chmod -R 775 bootstrap/cache
chmod -R 755 public

# Cambiar ownership (requiere sudo)
if [ "$EUID" -eq 0 ]; then
    chown -R $WEB_USER:$WEB_USER storage
    chown -R $WEB_USER:$WEB_USER bootstrap/cache
    chown -R $WEB_USER:$WEB_USER public/storage
    echo -e "${GREEN}✓ Ownership configurado para $WEB_USER${NC}"
else
    echo -e "${YELLOW}⚠ No estás ejecutando como root. Intenta ejecutar con sudo para cambiar ownership:${NC}"
    echo -e "${YELLOW}  sudo chown -R $WEB_USER:$WEB_USER storage${NC}"
    echo -e "${YELLOW}  sudo chown -R $WEB_USER:$WEB_USER bootstrap/cache${NC}"
    echo -e "${YELLOW}  sudo chown -R $WEB_USER:$WEB_USER public/storage${NC}"
fi

echo -e "${GREEN}✓ Permisos configurados${NC}"

echo -e "${YELLOW}📋 Paso 5: Limpiando cachés de Laravel...${NC}"

php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear

echo -e "${GREEN}✓ Cachés limpiados${NC}"

echo -e "${YELLOW}📋 Paso 6: Recacheando configuración...${NC}"

php artisan config:cache
php artisan route:cache

echo -e "${GREEN}✓ Configuración cacheada${NC}"

echo -e "${YELLOW}📋 Paso 7: Verificando symlink...${NC}"

if [ -L "public/storage" ]; then
    SYMLINK_TARGET=$(readlink public/storage)
    echo -e "${GREEN}✓ Symlink verificado: public/storage -> $SYMLINK_TARGET${NC}"
else
    echo -e "${RED}❌ Error: El symlink no se creó correctamente${NC}"
    exit 1
fi

echo ""
echo -e "${GREEN}"
echo "╔═══════════════════════════════════════════════════════╗"
echo "║              ✓ CORRECCIÓN COMPLETADA                  ║"
echo "╚═══════════════════════════════════════════════════════╝"
echo -e "${NC}"

echo -e "${YELLOW}📝 Pasos de verificación:${NC}"
echo ""
echo "1. Verifica que las imágenes existen:"
echo "   ls -la storage/app/public/clients/photos/"
echo ""
echo "2. Prueba una imagen directamente en el navegador:"
echo "   https://irongymgt.com/storage/clients/photos/[nombre-archivo].jpg"
echo ""
echo "3. Si aún tienes problemas, verifica la configuración de Nginx/Apache"
echo "   Ver: SOLUCION_IMAGENES_404.md (Solución 3)"
echo ""

exit 0
