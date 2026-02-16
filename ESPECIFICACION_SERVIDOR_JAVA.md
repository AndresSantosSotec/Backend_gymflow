# ESPECIFICACIÓN DEL SERVIDOR JAVA - INTEGRACIÓN HUELLA DIGITAL

**Documento:** Especificación Técnica del Servidor Java  
**Versión:** 1.0.0  
**Fecha:** 15 de Febrero, 2026  
**Estado:** REQUERIDO PARA IMPLEMENTACIÓN

---

## 📋 RESUMEN

El servidor Java debe exponer una API REST que actúe como intermediario entre el backend Laravel y el dispositivo lector de huella digital (ej: DigitalPersona, SecuGen, Nitgen).

El servidor:
- Lee/captura huellas del dispositivo
- Almacena templates de huella en memoria o BD local
- Realiza verificación/matching de huellas
- Sincroniza con el backend Laravel

---

## 🏗️ ARQUITECTURA

```
┌─────────────────────────────┐
│   Laravel Backend           │
│   (GymFlow API)             │
│   Port: 3000                │
└────────────┬────────────────┘
             │ HTTP REST
             │ (JSON)
             ▼
┌─────────────────────────────┐
│   Java Server               │
│   (Fingerprint Bridge)      │
│   Port: 8089                │
└────────────┬────────────────┘
             │ Native USB
             │ (Device Driver)
             ▼
┌─────────────────────────────┐
│   Biometric Device          │
│   (DigitalPersona, etc.)    │
│   USB Port                  │
└─────────────────────────────┘
```

---

## 🔌 ENDPOINTS REQUERIDOS

### 1. Health Check

**Endpoint:** `GET /api/health`

**Respuesta exitosa:**
```json
{
  "status": "ok",
  "version": "1.0.0",
  "timestamp": "2026-02-15T10:30:00Z",
  "uptime": 3600
}
```

**Código HTTP:** 200 OK

---

### 2. Estado del Dispositivo

**Endpoint:** `GET /api/device/status`

**Descripción:** Obtiene el estado actual del dispositivo biométrico

**Respuesta exitosa:**
```json
{
  "connected": true,
  "device_name": "DigitalPersona",
  "device_model": "U.are.U 5000",
  "firmware_version": "2.1.0",
  "quality_threshold": 80,
  "dpi": 500,
  "fps": 500,
  "fingerprints_in_device": 150,
  "last_capture_at": "2026-02-15T10:28:30Z",
  "status": "ready"
}
```

**Códigos HTTP:**
- 200 OK - Dispositivo conectado
- 503 Service Unavailable - Dispositivo desconectado

**Respuesta de error:**
```json
{
  "connected": false,
  "error": "Device not connected",
  "message": "No se detectó el dispositivo USB"
}
```

---

### 3. Capturar Huella Digital

**Endpoint:** `POST /api/fingerprint/capture`

**Descripción:** Captura una huella del dispositivo

**Body:** (vacío o vacío JSON `{}`)

**Respuesta exitosa:**
```json
{
  "success": true,
  "template": "binary_base64_encoded_template_here...",
  "quality": 95,
  "nfiq": 100,
  "capture_time_ms": 250,
  "message": "Huella capturada exitosamente"
}
```

**Códigos HTTP:**
- 200 OK - Huella capturada
- 400 Bad Request - Captura fallida
- 503 Service Unavailable - Dispositivo desconectado

**Respuesta de error:**
```json
{
  "success": false,
  "error": "Capture failed",
  "message": "Calidad de huella insuficiente (< 80)",
  "quality": 65
}
```

---

### 4. Registrar Huella Digital

**Endpoint:** `POST /api/fingerprint/register`

**Descripción:** Registra una huella digital en el servidor

**Body:**
```json
{
  "client_id": 1,
  "client_name": "Juan Perez",
  "fingerprint_template": "base64_encoded_template",
  "device_id": "default",
  "metadata": {
    "dpi": 500,
    "nfiq": 100
  }
}
```

**Respuesta exitosa:**
```json
{
  "success": true,
  "fingerprint_id": "FP-1-1708090800-abc12345",
  "client_id": 1,
  "stored_at": "2026-02-15T10:30:00Z",
  "quality": 95,
  "message": "Fingerprint registered successfully"
}
```

**Códigos HTTP:**
- 201 Created - Huella registrada
- 400 Bad Request - Datos inválidos
- 409 Conflict - Huella ya existe para este cliente

**Respuesta de error:**
```json
{
  "success": false,
  "error": "Invalid template",
  "message": "El template proporcionado no es válido"
}
```

---

### 5. Verificar Huella Digital

**Endpoint:** `POST /api/fingerprint/verify`

**Descripción:** Verifica una huella contra las registradas

**Body:**
```json
{
  "fingerprint_id": "FP-1-1708090800-abc12345",
  "fingerprint_template": "base64_encoded_live_capture",
  "device_id": "default"
}
```

**Respuesta exitosa (Match):**
```json
{
  "success": true,
  "match": true,
  "fingerprint_id": "FP-1-1708090800-abc12345",
  "client_id": 1,
  "similarity": 98,
  "threshold": 80,
  "verification_time_ms": 50,
  "message": "Fingerprint matched successfully"
}
```

**Respuesta exitosa (No match):**
```json
{
  "success": true,
  "match": false,
  "similarity": 45,
  "threshold": 80,
  "message": "Fingerprint does not match"
}
```

**Códigos HTTP:**
- 200 OK - Verificación completada
- 404 Not Found - Huella no encontrada
- 503 Service Unavailable - Error del dispositivo

**Respuesta de error:**
```json
{
  "success": false,
  "error": "Device error",
  "message": "Error al leer del dispositivo"
}
```

---

### 6. Listar Huellas Registradas

**Endpoint:** `GET /api/fingerprint/list`

**Descripción:** Lista todas las huellas registradas en el servidor

**Query Parameters:**
- `page` (opcional): Número de página
- `per_page` (opcional): Huellas por página (default: 50)

**Respuesta exitosa:**
```json
{
  "success": true,
  "fingerprints": [
    {
      "fingerprint_id": "FP-1-1708090800-abc12345",
      "client_id": 1,
      "client_name": "Juan Perez",
      "registered_at": "2026-02-15T10:30:00Z",
      "quality": 95,
      "last_verified_at": "2026-02-16T09:15:00Z"
    },
    {
      "fingerprint_id": "FP-2-1708090900-def67890",
      "client_id": 2,
      "client_name": "María García",
      "registered_at": "2026-02-16T08:45:00Z",
      "quality": 92,
      "last_verified_at": "2026-02-16T10:20:00Z"
    }
  ],
  "pagination": {
    "total": 150,
    "page": 1,
    "per_page": 50,
    "last_page": 3
  }
}
```

**Códigos HTTP:**
- 200 OK - Lista obtenida

---

### 7. Eliminar Huella Digital

**Endpoint:** `DELETE /api/fingerprint/{fingerprintId}`

**Descripción:** Elimina una huella registrada

**Respuesta exitosa:**
```json
{
  "success": true,
  "fingerprint_id": "FP-1-1708090800-abc12345",
  "message": "Fingerprint deleted successfully"
}
```

**Códigos HTTP:**
- 200 OK - Huella eliminada
- 404 Not Found - Huella no existe

**Respuesta de error:**
```json
{
  "success": false,
  "error": "Not found",
  "message": "La huella especificada no existe"
}
```

---

### 8. Sincronizar Huellas (Bulk)

**Endpoint:** `POST /api/fingerprint/sync`

**Descripción:** Sincroniza múltiples huellas de una sola vez

**Body:**
```json
{
  "client_id": 1,
  "fingerprint_id": "FP-1-1708090800-abc12345",
  "fingerprint_template": "base64_template",
  "device_id": "default",
  "operation": "upsert"
}
```

**Respuesta exitosa:**
```json
{
  "success": true,
  "fingerprint_id": "FP-1-1708090800-abc12345",
  "operation": "upsert",
  "message": "Synchronized successfully"
}
```

**Códigos HTTP:**
- 200 OK - Sincronizacion completada
- 400 Bad Request - Datos inválidos

---

## 🔐 AUTENTICACIÓN

### Headers Requeridos

El servidor Java DEBE validar los siguientes headers en TODAS las solicitudes:

```
Authorization: Bearer {JWT_TOKEN}
Content-Type: application/json
User-Agent: GymflowLaravelAPI/1.0
X-Request-ID: {UUID}
```

### Generar JWT Token

El backend enviará un JWT como authorization header.

**Token estructura:**
```
Header: {
  "alg": "HS256",
  "typ": "JWT"
}

Payload: {
  "client_id": "gymflow-backend",
  "iat": 1708090800,
  "exp": 1708177200,
  "iss": "gymflow"
}
```

---

## 🔄 MANEJO DE ERRORES

### Estructura de Errores

Todos los errores deben seguir este formato:

```json
{
  "success": false,
  "error": "error_code",
  "message": "Descripción del error en español",
  "timestamp": "2026-02-15T10:30:00Z",
  "request_id": "abc-123-def-456"
}
```

### Códigos de Error

| Código | Status | Descripción |
|--------|--------|-------------|
| DEVICE_NOT_CONNECTED | 503 | Dispositivo no conectado |
| INVALID_TEMPLATE | 400 | Template de huella inválido |
| QUALITY_LOW | 400 | Calidad de huella insuficiente |
| CAPTURE_TIMEOUT | 408 | Tiempo de captura agotado |
| FINGERPRINT_NOT_FOUND | 404 | Huella no encontrada |
| UNAUTHORIZED | 401 | Autenticación fallida |
| INVALID_REQUEST | 400 | Solicitud inválida |
| INTERNAL_ERROR | 500 | Error interno del servidor |

---

## ⏱️ TIMEOUTS Y LIMITES

```
Capture timeout: 30 segundos
Verify timeout: 5 segundos
Register timeout: 10 segundos
Request timeout: 30 segundos

Max fingerprints en memoria: 5000
Quality threshold: 80 (NFIQ)
Similarity threshold (matching): 80%
```

---

## 💾 ALMACENAMIENTO

El servidor Java debe almacenar:

1. **En memoria:** Templates de huellas (para verificación rápida)
2. **En BD local:** Log de capturas, verificaciones, registros
3. **En BD remota:** Sincronizar con backend Laravel

**Estructura de tabla local:**

```sql
CREATE TABLE fingerprints (
  id INT PRIMARY KEY AUTO_INCREMENT,
  fingerprint_id VARCHAR(50) UNIQUE NOT NULL,
  client_id INT NOT NULL,
  client_name VARCHAR(255),
  template LONGBLOB NOT NULL,
  quality INT,
  nfiq INT,
  registered_at TIMESTAMP,
  last_verified_at TIMESTAMP,
  verification_count INT DEFAULT 0
);

CREATE TABLE capture_logs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  fingerprint_id VARCHAR(50),
  client_id INT,
  quality INT,
  captured_at TIMESTAMP,
  success BOOLEAN,
  error_message TEXT
);
```

---

## 🧪 TESTING

### Test de Conexión

```bash
curl -X GET http://localhost:8089/api/health \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

### Test de Dispositivo

```bash
curl -X GET http://localhost:8089/api/device/status \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

### Test Completo

```bash
# 1. Capturar
curl -X POST http://localhost:8089/api/fingerprint/capture \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json"

# 2. Registrar
curl -X POST http://localhost:8089/api/fingerprint/register \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"client_id": 1, "fingerprint_template": "..."}'

# 3. Verificar
curl -X POST http://localhost:8089/api/fingerprint/verify \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"fingerprint_id": "FP-1-...", "fingerprint_template": "..."}'
```

---

## 📊 LOGGING

El servidor debe loguear:

```
[timestamp] [level] [action] [client_id] [fingerprint_id] [result] [details]

Ejemplo:
2026-02-15 10:30:45 INFO CAPTURE 1 FP-1-... SUCCESS quality=95
2026-02-15 10:30:50 INFO VERIFY 1 FP-1-... SUCCESS match=true similarity=98
2026-02-15 10:31:00 ERROR VERIFY 2 FP-2-... FAILED error=device_timeout
```

---

## 🚀 DEPLOYMENT

### Requisitos del Servidor

- Java 11+
- 2GB RAM mínimo
- Puerto 8089 disponible
- USB 2.0+ para dispositivo biométrico
- SO: Windows, Linux o macOS

### Instalación y Configuración

```bash
# 1. Descargar JAR
wget https://github.com/gymflow/fingerprint-server/releases/download/v1.0.0/fingerprint-server.jar

# 2. Configurar (config.properties)
FINGERPRINT_SERVER_PORT=8089
FINGERPRINT_SERVER_HOST=0.0.0.0
FINGERPRINT_JWT_SECRET=your_secret_here
FINGERPRINT_DB_URL=jdbc:mysql://localhost:3306/fingerprint
FINGERPRINT_DB_USER=root
FINGERPRINT_DB_PASSWORD=password

# 3. Ejecutar
java -jar fingerprint-server.jar
```

---

## 📝 NOTAS IMPORTANTES

1. **Seguridad:**
   - Usar HTTPS en producción (TLS 1.2+)
   - Validar JWT en cada solicitud
   - Encriptar templates en BD
   - Logs con acceso restringido

2. **Performance:**
   - Cache de huellas en memoria para búsqueda rápida
   - Connection pooling para BD
   - Asincronía para operaciones lentas
   - Monitoreo de recursos

3. **Confiabilidad:**
   - Health checks automáticos
   - Reintentos para fallos transitorios
   - Sincronización periódica con backend
   - Backups automáticos

---

**Versión:** 1.0.0  
**Última actualización:** 15 de Febrero, 2026
