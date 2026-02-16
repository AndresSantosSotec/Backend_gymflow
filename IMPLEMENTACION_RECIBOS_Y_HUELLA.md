# IMPLEMENTACIÓN DEL SISTEMA DE RECIBOS, FACTURAS Y HUELLA DIGITAL

## 📋 Resumen de Cambios

Se ha implementado un sistema completo de:
- **Recibos y Facturas** para diferentes tipos de pagos
- **Integración con servidor Java** para lectura de huella digital
- **Gestión de permisos** para usuarios
- **Envío automático de facturas** por email

---

## 🔧 Pasos de Instalación

### 1. Configuración de Variables de Entorno

Copiar el archivo de ejemplo y completar los datos:

```bash
cp .env.fingerprint.example .env
```

Actualizar las siguientes variables en `.env`:

```env
# Servidor Java para huella digital
FINGERPRINT_SERVER_URL=http://localhost:8089/api
FINGERPRINT_DEVICE_ID=default
FINGERPRINT_ENABLED=true
FINGERPRINT_TIMEOUT=30

# Configuración de Email
MAIL_FROM_ADDRESS=noreply@gymflow.local
MAIL_FROM_NAME="GymFlow System"
```

### 2. Ejecutar Migración Fresh

```bash
php artisan migrate:fresh
```

Esto creará las siguientes tablas nuevas:
- `receipts` - Recibos y facturas
- Todas las tablas existentes se resetearán

⚠️ **ADVERTENCIA**: Este comando elimina todos los datos. Hacer backup antes de ejecutar.

### 3. Poblamiento de Datos (Seeders)

Después de la migración, ejecutar seeders para datos de prueba:

```bash
php artisan db:seed
```

---

## 📦 Nuevas Estructuras Creadas

### Modelos

#### `App\Models\Receipt`
- Gestión de recibos y facturas
- Relaciones con `Client`, `Payment`, `Venta`, `Membership`
- Métodos útiles:
  - `generateReceiptNumber()` - Genera número único de recibo
  - `generateInvoiceNumber()` - Genera número único de factura
  - `markAsInvoiced()` - Marcar como facturado
  - `markAsPaid()` - Marcar como pagado
  - `markEmailSent()` - Registrar envío de email

### Servicios

#### `App\Services\FingerprintService`
- Integración con servidor Java
- Métodos principales:
  - `registerFingerprintWithDevice()` - Registrar huella digital
  - `verifyFingerprintWithDevice()` - Verificar huella
  - `deleteFingerprintFromDevice()` - Eliminar huella
  - `syncAllFingerprints()` - Sincronizar todas las huellas
  - `testConnection()` - Probar conexión con servidor

### Controladores

#### `App\Http\Controllers\Api\ReceiptController`
Endpoints para gestionar recibos:
- CRUD completo de recibos
- Crear invoice desde recibo
- Enviar por email
- Marcar como pagado
- Estadísticas

#### `App\Http\Controllers\Api\FingerprintStatusController`
Endpoints para gestionar estado de huella:
- Estado del dispositivo
- Capturar huella digital
- Listar huellas registradas
- Sincronizar con dispositivo
- Probar conexión

---

## 🔌 Rutas API

### Recibos (Receipts)
```
GET    /api/receipts                    - Listar recibos
POST   /api/receipts                    - Crear recibo
GET    /api/receipts/{id}               - Obtener recibo
PUT    /api/receipts/{id}               - Actualizar recibo
DELETE /api/receipts/{id}               - Eliminar recibo

GET    /api/receipts/client/{clientId}  - Recibos de un cliente
GET    /api/receipts/statistics/all     - Estadísticas
POST   /api/receipts/{id}/invoice       - Crear factura
POST   /api/receipts/{id}/send-email    - Enviar por email
POST   /api/receipts/{id}/mark-paid     - Marcar como pagado
POST   /api/receipts/from-payment       - Crear desde pago
POST   /api/receipts/bulk-export        - Exportar múltiples
```

### Huella Digital (Fingerprint)
```
GET    /api/fingerprint-status/device-status     - Estado del dispositivo
POST   /api/fingerprint-status/capture           - Capturar huella
GET    /api/fingerprint-status/list              - Listar huellas
POST   /api/fingerprint-status/sync              - Sincronizar
GET    /api/fingerprint-status/test-connection  - Probar conexión

POST   /api/clients/{id}/fingerprint      - Registrar huella
DELETE /api/clients/{id}/fingerprint      - Eliminar huella
GET    /api/clients/{id}/fingerprint      - Estado de huella
```

---

## 🗄️ Estructura de la Base de Datos

### Tabla `receipts`
```sql
- id (PK)
- client_id (FK) - Cliente que recibe el recibo
- payment_id (FK) - Pago asociado
- venta_id (FK) - Venta asociada
- membership_id (FK) - Membresía asociada
- receipt_number - Número único de recibo (REC-2026-000001)
- type - Tipo: receipt, invoice, proforma
- payment_type - Tipo de pago: subscription, individual_payment, course, product
- subtotal, tax, discount, total - Montos
- is_invoiced - Si tiene factura
- invoice_number - Número de factura (INV-2026-000001)
- email_sent - Si se envió por email
- sent_to_email - Email donde se envió
- status - Estado: draft, pending, paid, cancelled
- timestamps - created_at, updated_at, deleted_at
```

---

## 💳 Flujos de Uso

### Flujo 1: Crear Recibo desde Pago
```
1. Cliente realiza pago
2. Sistema crea Receipt automáticamente
3. Opción: Convertir a factura
4. Opción: Enviar por email
```

**Endpoint:**
```bash
POST /api/receipts/from-payment
{
  "payment_id": 123,
  "type": "receipt",
  "payment_type": "subscription"
}
```

### Flujo 2: Registrar Huella Digital
```
1. Cliente va a recepción
2. Se captura huella del dispositivo
3. Sistema envía a servidor Java
4. Se guarda template en BD
5. Se registra fingerprint_id en cliente
```

**Endpoint:**
```bash
POST /api/clients/{id}/fingerprint
{
  "fingerprint_template": "base64_encoded_template",
  "device_id": "default",
  "quality": 95
}
```

### Flujo 3: Verificar Acceso por Huella
```
1. Cliente intenta acceso al gym
2. Lee huella en dispositivo entrada
3. Sistema verifica contra servidor Java
4. Se registra AccessLog
5. Se permite o deniega acceso
```

**Endpoint:**
```bash
POST /api/access/verify-fingerprint
{
  "fingerprint_id": "FP-123..."
}
```

---

## 🔐 Permisos Requeridos

Se recomienda agregar los siguientes permisos a los roles:

### Admin
- ✅ Todos los permisos de recibos
- ✅ Todos los permisos de huella digital
- ✅ Ver estadísticas

### Receptionist
- ✅ Ver recibos
- ✅ Crear recibos
- ✅ Registrar huella digital
- ✅ Enviar emails

### Client
- ✅ Ver propios recibos
- ✅ Descargar propias facturas

---

## 🧪 Testing

### Test de Conexión al Servidor Java
```bash
curl http://localhost:3000/api/fingerprint-status/test-connection
```

### Crear Recibo de Prueba
```bash
curl -X POST http://localhost:3000/api/receipts \
  -H "Content-Type: application/json" \
  -d '{
    "client_id": 1,
    "type": "receipt",
    "payment_type": "subscription",
    "subtotal": 50.00,
    "tax": 0,
    "total": 50.00,
    "status": "pending"
  }'
```

### Registrar Huella Digital
```bash
curl -X POST http://localhost:3000/api/clients/1/fingerprint \
  -H "Content-Type: application/json" \
  -d '{
    "fingerprint_template": "template_base64_aqui",
    "device_id": "default",
    "quality": 95
  }'
```

---

## 📧 Funcionalidad de Email (TODO)

Se necesita implementar:

1. **Crear Mailable**
   ```php
   php artisan make:mail ReceiptMail
   ```

2. **Actualizar ReceiptController**
   - Implementar envío real en `sendEmail()`
   - Incluir PDF del recibo
   - Usar queue para no bloquear

3. **Crear PDF**
   - Usar librería `barryvdh/laravel-dompdf`
   - Template de recibo personalizable

---

## 🔄 Integración con Servidor Java

### Configuración del Servidor Java

El servidor Java debe exponer estos endpoints:

```
GET    /api/health
       Respuesta: { "status": "ok", "version": "1.0.0" }

GET    /api/device/status
       Respuesta: { "connected": true, "device": "DigitalPersona", "quality": "95%" }

POST   /api/fingerprint/capture
       Respuesta: { "template": "binary_data", "quality": 95 }

POST   /api/fingerprint/register
       Payload: { client_id, client_name, fingerprint_template, device_id }
       Respuesta: { "fingerprint_id": "FP-123", "quality": 95 }

POST   /api/fingerprint/verify
       Payload: { fingerprint_id, device_id }
       Respuesta: { "match": true, "similarity": 98 }

DELETE /api/fingerprint/{fingerprintId}
       Respuesta: { "success": true }
```

---

## ⚠️ Consideraciones Importantes

1. **Seguridad**
   - Los templates de huella digital deben estar encriptados
   - Usar HTTPS en producción
   - Validar permisos en todos los endpoints

2. **Performance**
   - Usar indexing en `receipt_number`, `invoice_number`, `client_id`
   - Implementar caché para estadísticas
   - Usar queues para envio de emails

3. **Respaldo**
   - Hacer backup antes de `migrate:fresh`
   - Sincronizar huellas regularmente
   - Auditar cambios en facturass

4. **Logeo**
   - Log de todos los registros de huella
   - Log de cambios en facturas
   - Log de envíos de email

---

## 📚 Próximos Pasos

- [ ] Implementar envío de emails con PDF
- [ ] Crear migraciones para permisos/roles
- [ ] Integración real con lector biométrico
- [ ] Dashboard de estadísticas
- [ ] Reportes de facturación
- [ ] Integración con contabilidad

---

## 🆘 Troubleshooting

### Error: Conexión al servidor Java rechazada
```
Verificar:
1. Servidor Java está corriendo en puerto 8089
2. FIREWALL permite conexiones
3. Variable FINGERPRINT_SERVER_URL es correcta
```

### Error: Template de huella inválido
```
Solución:
1. Validar que sea base64 válido
2. Verificar calidad > 80
3. Probar captura nuevamente
```

### Migración falla
```
Verificar:
1. Base de datos puede ser accedida
2. Permisos en carpeta storage/
3. Ejecutar: php artisan migrate:reset
```

---

**Fecha de creación:** Febrero 15, 2026
**Versión:** 1.0.0
