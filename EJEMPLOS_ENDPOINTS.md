# EJEMPLOS DE USO - RECIBOS Y HUELLA DIGITAL

## 🔐 Autenticación

Todos los endpoints requieren autenticación Bearer Token:

```bash
Authorization: Bearer your_access_token
```

Obtener token:
```bash
curl -X POST http://localhost:3000/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@irongym.local",
    "password": "password"
  }'
```

---

## 📋 RECIBOS - EJEMPLOS DE USO

### 1. Listar todos los recibos

```bash
curl -X GET "http://localhost:3000/api/receipts?status=pending&per_page=15" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Respuesta:**
```json
{
  "data": [
    {
      "id": 1,
      "client_id": 1,
      "payment_id": null,
      "receipt_number": "REC-2026-000001",
      "type": "receipt",
      "payment_type": "subscription",
      "subtotal": "50.00",
      "tax": "0.00",
      "discount": "0.00",
      "total": "50.00",
      "status": "pending",
      "is_invoiced": false,
      "email_sent": false,
      "created_at": "2026-02-15T10:30:00Z"
    }
  ],
  "pagination": { ... }
}
```

### 2. Crear un nuevo recibo

```bash
curl -X POST http://localhost:3000/api/receipts \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "client_id": 1,
    "type": "receipt",
    "payment_type": "subscription",
    "subtotal": 50.00,
    "tax": 0,
    "discount": 0,
    "total": 50.00,
    "status": "pending",
    "description": "Membresía mensual - Enero 2026",
    "details": {
      "plan": "Premium",
      "duration": "1 mes",
      "start_date": "2026-02-15"
    }
  }'
```

**Respuesta:**
```json
{
  "id": 5,
  "client_id": 1,
  "receipt_number": "REC-2026-000005",
  "type": "receipt",
  "payment_type": "subscription",
  "total": "50.00",
  "status": "pending",
  "created_at": "2026-02-15T10:35:00Z"
}
```

### 3. Obtener detalles de un recibo

```bash
curl -X GET http://localhost:3000/api/receipts/5 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### 4. Crear factura desde recibo

```bash
curl -X POST http://localhost:3000/api/receipts/5/invoice \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "invoice_notes": "Factura por pago de membresía"
  }'
```

**Respuesta:**
```json
{
  "message": "Invoice created successfully",
  "receipt": {
    "id": 5,
    "is_invoiced": true,
    "invoiced_at": "2026-02-15T10:40:00Z",
    "invoice_number": "INV-2026-000001"
  }
}
```

### 5. Marcar recibo como pagado

```bash
curl -X POST http://localhost:3000/api/receipts/5/mark-paid \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Respuesta:**
```json
{
  "message": "Receipt marked as paid",
  "receipt": {
    "id": 5,
    "status": "paid",
    "paid_at": "2026-02-15T10:45:00Z"
  }
}
```

### 6. Enviar recibo por email

```bash
curl -X POST http://localhost:3000/api/receipts/5/send-email \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "email": "cliente@example.com",
    "message": "Adjunto encontrará su recibo de pago"
  }'
```

**Respuesta:**
```json
{
  "message": "Email sent successfully",
  "receipt": {
    "id": 5,
    "email_sent": true,
    "email_sent_at": "2026-02-15T10:50:00Z",
    "sent_to_email": "cliente@example.com"
  }
}
```

### 7. Listar recibos de un cliente

```bash
curl -X GET http://localhost:3000/api/receipts/client/1 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### 8. Crear recibo desde pago existente

```bash
curl -X POST http://localhost:3000/api/receipts/from-payment \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "payment_id": 123,
    "type": "invoice",
    "payment_type": "subscription"
  }'
```

### 9. Ver estadísticas de recibos

```bash
curl -X GET http://localhost:3000/api/receipts/statistics/all \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Respuesta:**
```json
{
  "total_receipts": 15,
  "total_invoiced": 10,
  "total_paid": "750.50",
  "total_pending": "250.00",
  "email_sent": 8,
  "by_payment_type": [
    {
      "payment_type": "subscription",
      "count": 10,
      "total": "500.00"
    },
    {
      "payment_type": "product",
      "count": 5,
      "total": "250.50"
    }
  ],
  "by_status": [
    {
      "status": "paid",
      "count": 12,
      "total": "750.50"
    },
    {
      "status": "pending",
      "count": 3,
      "total": "250.00"
    }
  ]
}
```

---

## 👆 HUELLA DIGITAL - EJEMPLOS DE USO

### 1. Obtener estado del dispositivo

```bash
curl -X GET http://localhost:3000/api/fingerprint-status/device-status \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "connected": true,
    "device": "DigitalPersona",
    "quality": "95%",
    "fps": 500
  }
}
```

### 2. Probar conexión con servidor Java

```bash
curl -X GET http://localhost:3000/api/fingerprint-status/test-connection \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Respuesta exitosa:**
```json
{
  "status": "connected",
  "message": "Connected to fingerprint server",
  "version": "1.0.0"
}
```

**Respuesta de error:**
```json
{
  "status": "disconnected",
  "error": "Cannot connect to fingerprint server",
  "url": "http://localhost:8089/api",
  "details": "Connection refused"
}
```

### 3. Capturar huella digital del dispositivo

```bash
curl -X POST http://localhost:3000/api/fingerprint-status/capture \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Respuesta:**
```json
{
  "success": true,
  "fingerprint_template": "base64_encoded_fingerprint_data",
  "quality": 95,
  "message": "Fingerprint captured successfully"
}
```

### 4. Registrar huella digital para un cliente

```bash
curl -X POST http://localhost:3000/api/clients/1/fingerprint \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "fingerprint_template": "base64_encoded_template_from_device",
    "device_id": "default",
    "quality": 95
  }'
```

**Respuesta:**
```json
{
  "message": "Huella digital registrada exitosamente",
  "fingerprint_id": "FP-1-1708090800-abc12345",
  "registered_at": "2026-02-15T10:30:00Z",
  "quality": 95,
  "device": {
    "fingerprint_id": "FP-1-1708090800-abc12345",
    "quality": 95,
    "device_id": "default"
  },
  "client": {
    "id": 1,
    "first_name": "Juan",
    "fingerprint_id": "FP-1-1708090800-abc12345",
    "fingerprint_registered_at": "2026-02-15T10:30:00Z"
  }
}
```

### 5. Ver estado de huella digital de un cliente

```bash
curl -X GET http://localhost:3000/api/clients/1/fingerprint \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Respuesta:**
```json
{
  "has_fingerprint": true,
  "fingerprint_id": "FP-1-1708090800-abc12345",
  "device_id": "default",
  "quality": 95,
  "registered_at": "2026-02-15T10:30:00Z"
}
```

### 6. Verificar acceso por huella digital

```bash
curl -X POST http://localhost:3000/api/access/verify-fingerprint \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "fingerprint_id": "FP-1-1708090800-abc12345"
  }'
```

**Respuesta (Acceso permitido):**
```json
{
  "allowed": true,
  "client": {
    "id": 1,
    "first_name": "Juan",
    "last_name": "Pérez",
    "status": "active"
  },
  "message": "¡Acceso permitido! Bienvenido/a Juan",
  "log": {
    "id": 1,
    "client_id": 1,
    "verification_method": "fingerprint",
    "status": "allowed",
    "access_time": "2026-02-15T10:35:00Z"
  }
}
```

**Respuesta (Acceso denegado):**
```json
{
  "allowed": false,
  "client": {
    "id": 1,
    "first_name": "Juan"
  },
  "message": "Acceso denegado - Membresía vencida",
  "log": {
    "id": 2,
    "status": "denied",
    "reason": "Membresía vencida"
  }
}
```

### 7. Eliminar huella digital de un cliente

```bash
curl -X DELETE http://localhost:3000/api/clients/1/fingerprint \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Respuesta:**
```json
{
  "message": "Huella digital eliminada exitosamente",
  "client": {
    "id": 1,
    "first_name": "Juan",
    "fingerprint_id": null
  }
}
```

### 8. Listar todas las huellas registradas en dispositivo

```bash
curl -X GET http://localhost:3000/api/fingerprint-status/list \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Respuesta:**
```json
{
  "success": true,
  "fingerprints": [
    {
      "fingerprint_id": "FP-1-1708090800-abc12345",
      "client_id": 1,
      "quality": 95,
      "registered_at": "2026-02-15T10:30:00Z"
    },
    {
      "fingerprint_id": "FP-2-1708090900-def67890",
      "client_id": 2,
      "quality": 92,
      "registered_at": "2026-02-16T09:15:00Z"
    }
  ]
}
```

### 9. Sincronizar todas las huellas con dispositivo

```bash
curl -X POST http://localhost:3000/api/fingerprint-status/sync \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Respuesta:**
```json
{
  "success": true,
  "synced": 23,
  "failed": 0,
  "errors": []
}
```

---

## 📊 FLUJOS COMBINADOS

### Flujo Completo: Crear Cliente con Huella y Recibo

**1. Crear cliente:**
```bash
curl -X POST http://localhost:3000/api/clients \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "first_name": "Carlos",
    "last_name": "López",
    "email": "carlos@example.com",
    "phone": "555-1234",
    "dni": "123456789"
  }'
```

**2. Registrar huella:**
```bash
curl -X POST http://localhost:3000/api/clients/NUEVO_ID/fingerprint \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "fingerprint_template": "base64_template",
    "quality": 95
  }'
```

**3. Crear pago:**
```bash
curl -X POST http://localhost:3000/api/payments \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "client_id": NUEVO_ID,
    "amount": 50.00,
    "payment_method": "cash",
    "status": "completed"
  }'
```

**4. Crear recibo:**
```bash
curl -X POST http://localhost:3000/api/receipts/from-payment \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "payment_id": PAYMENT_ID,
    "type": "invoice",
    "payment_type": "subscription"
  }'
```

**5. Enviar por email:**
```bash
curl -X POST http://localhost:3000/api/receipts/RECEIPT_ID/send-email \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "email": "carlos@example.com"
  }'
```

---

## 🧪 TESTING CON POSTMAN

### 1. Importar colección
- Descargar [IronGym_Receipts.postman_collection.json](./postman/IronGym_Receipts.postman_collection.json)
- Importar en Postman
- Configurar variables de entorno

### 2. Variables de Entorno
```json
{
  "base_url": "http://localhost:3000/api",
  "token": "tu_access_token",
  "client_id": 1,
  "receipt_id": 5
}
```

### 3. Tests Automáticos
- Las colecciones incluyen scripts de testing
- Verificar status codes
- Validar estructura de respuestas

---

## ⚠️ ERRORES COMUNES

### Error 401 - Unauthorized
```json
{
  "message": "Unauthenticated."
}
```
**Solución:** Verificar token de autenticación válido

### Error 422 - Validation Failed
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "client_id": ["The client_id field is required."]
  }
}
```
**Solución:** Resolver errores de validación indicados

### Error 500 - Fingerprint Device
```json
{
  "error": "Failed to register fingerprint with device",
  "message": "Cannot connect to fingerprint server"
}
```
**Solución:** Verificar servidor Java está corriendo

---

**Última actualización:** Febrero 15, 2026
