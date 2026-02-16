# 🧪 Colección de Pruebas API - Gymflow

## Variables Globales
```
BASE_URL = http://127.0.0.1:8000/api
TOKEN = (se obtiene después del login)
```

---

## 1️⃣ Autenticación

### Login
```http
POST {{BASE_URL}}/login
Content-Type: application/json

{
  "email": "admin@gymflow.com",
  "password": "password123"
}
```

### Register
```http
POST {{BASE_URL}}/register
Content-Type: application/json

{
  "name": "Nuevo Usuario",
  "username": "usuario123",
  "email": "nuevo@gymflow.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

### Logout
```http
POST {{BASE_URL}}/logout
Authorization: Bearer {{TOKEN}}
```

### Get User Info
```http
GET {{BASE_URL}}/user
Authorization: Bearer {{TOKEN}}
```

---

## 2️⃣ Clientes

### Listar Clientes
```http
GET {{BASE_URL}}/clients
Authorization: Bearer {{TOKEN}}
```

### Buscar Clientes
```http
GET {{BASE_URL}}/clients?search=juan&status=active
Authorization: Bearer {{TOKEN}}
```

### Buscar por QR
```http
GET {{BASE_URL}}/clients/qr/QRNLBPQ8WK
Authorization: Bearer {{TOKEN}}
```

### Crear Cliente
```http
POST {{BASE_URL}}/clients
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "first_name": "Roberto",
  "last_name": "Díaz",
  "email": "roberto.diaz@example.com",
  "phone": "5551112222",
  "birth_date": "1985-03-10",
  "address": "Calle 5 #123",
  "dni": "12345678A",
  "notes": "Cliente nuevo desde promoción"
}
```

### Ver Cliente
```http
GET {{BASE_URL}}/clients/1
Authorization: Bearer {{TOKEN}}
```

### Actualizar Cliente
```http
PUT {{BASE_URL}}/clients/1
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "phone": "5559998888",
  "address": "Nueva Calle 10 #456",
  "status": "active"
}
```

### Eliminar Cliente
```http
DELETE {{BASE_URL}}/clients/1
Authorization: Bearer {{TOKEN}}
```

---

## 3️⃣ Planes de Membresía

### Listar Planes
```http
GET {{BASE_URL}}/membership-plans
Authorization: Bearer {{TOKEN}}
```

### Planes Publicados
```http
GET {{BASE_URL}}/membership-plans?published=1
Authorization: Bearer {{TOKEN}}
```

### Crear Plan
```http
POST {{BASE_URL}}/membership-plans
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "name": "Plan Estudiante",
  "slug": "estudiante",
  "description": "Plan especial para estudiantes",
  "price": 199.00,
  "duration_days": 30,
  "features": [
    "Acceso horario reducido",
    "Casillero",
    "Descuento en proteínas"
  ],
  "published": true
}
```

### Ver Plan
```http
GET {{BASE_URL}}/membership-plans/1
Authorization: Bearer {{TOKEN}}
```

### Actualizar Plan
```http
PUT {{BASE_URL}}/membership-plans/1
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "price": 349.00,
  "features": [
    "Acceso al área de pesas",
    "Acceso a cardio",
    "Casillero personal",
    "Clase grupal gratis"
  ]
}
```

### Eliminar Plan
```http
DELETE {{BASE_URL}}/membership-plans/5
Authorization: Bearer {{TOKEN}}
```

---

## 4️⃣ Membresías

### Listar Membresías
```http
GET {{BASE_URL}}/memberships
Authorization: Bearer {{TOKEN}}
```

### Membresías Activas
```http
GET {{BASE_URL}}/memberships?status=active
Authorization: Bearer {{TOKEN}}
```

### Membresías por Cliente
```http
GET {{BASE_URL}}/memberships?client_id=1
Authorization: Bearer {{TOKEN}}
```

### ⭐ Asignar Membresía (Recomendado)
```http
POST {{BASE_URL}}/memberships/assign
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "client_id": 5,
  "plan_id": 2,
  "payment_method": "card",
  "auto_renew": true,
  "notes": "Pago con tarjeta terminada en 1234"
}
```

### Crear Membresía Manual
```http
POST {{BASE_URL}}/memberships
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "client_id": 3,
  "plan_id": 1,
  "start_date": "2026-02-01",
  "end_date": "2026-03-03",
  "status": "active",
  "auto_renew": false
}
```

### Ver Membresía
```http
GET {{BASE_URL}}/memberships/1
Authorization: Bearer {{TOKEN}}
```

### Actualizar Membresía
```http
PUT {{BASE_URL}}/memberships/1
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "status": "expired",
  "auto_renew": false
}
```

---

## 5️⃣ Pagos

### Listar Pagos
```http
GET {{BASE_URL}}/payments
Authorization: Bearer {{TOKEN}}
```

### Filtrar Pagos
```http
GET {{BASE_URL}}/payments?status=completed&payment_method=card
Authorization: Bearer {{TOKEN}}
```

### Pagos por Cliente
```http
GET {{BASE_URL}}/payments/client/1
Authorization: Bearer {{TOKEN}}
```

### ⭐ Estadísticas de Ingresos
```http
GET {{BASE_URL}}/payments/revenue/stats
Authorization: Bearer {{TOKEN}}
```

### Crear Pago
```http
POST {{BASE_URL}}/payments
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "client_id": 2,
  "membership_id": 2,
  "amount": 499.00,
  "payment_method": "cash",
  "status": "completed",
  "transaction_id": "TXN123ABC",
  "notes": "Pago en efectivo"
}
```

### Ver Pago
```http
GET {{BASE_URL}}/payments/1
Authorization: Bearer {{TOKEN}}
```

### Actualizar Estado de Pago
```http
PATCH {{BASE_URL}}/payments/1/status
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "status": "completed"
}
```

---

## 6️⃣ Control de Acceso (CRÍTICO)

### ⭐ Verificar Acceso por QR
```http
POST {{BASE_URL}}/access/verify-qr
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "qr_code": "QRNLBPQ8WK"
}
```

**Respuesta Acceso Permitido:**
```json
{
  "allowed": true,
  "client": {
    "id": 1,
    "first_name": "Juan",
    "last_name": "Pérez",
    "email": "juan.perez@example.com",
    "status": "active"
  },
  "message": "¡Acceso permitido! Bienvenido/a Juan",
  "log": { ... }
}
```

**Respuesta Acceso Denegado:**
```json
{
  "allowed": false,
  "client": {
    "id": 5,
    "first_name": "Luis",
    "status": "inactive"
  },
  "message": "Acceso denegado - Membresía vencida"
}
```

### Verificar por Huella Digital
```http
POST {{BASE_URL}}/access/verify-fingerprint
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "fingerprint_id": "FP12345"
}
```

### Logs Recientes
```http
GET {{BASE_URL}}/access/recent?limit=20
Authorization: Bearer {{TOKEN}}
```

### Logs por Cliente
```http
GET {{BASE_URL}}/access/by-client/1
Authorization: Bearer {{TOKEN}}
```

### Listar Todos los Logs
```http
GET {{BASE_URL}}/access-logs?status=allowed
Authorization: Bearer {{TOKEN}}
```

### Crear Log Manual
```http
POST {{BASE_URL}}/access-logs
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "client_id": 1,
  "access_type": "entry",
  "qr_code": "QRNLBPQ8WK",
  "status": "allowed",
  "notes": "Acceso manual desde recepción"
}
```

---

## 7️⃣ Leads

### Listar Leads
```http
GET {{BASE_URL}}/leads
Authorization: Bearer {{TOKEN}}
```

### Buscar Leads
```http
GET {{BASE_URL}}/leads?status=new&search=pedro
Authorization: Bearer {{TOKEN}}
```

### Crear Lead
```http
POST {{BASE_URL}}/leads
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "first_name": "Sofia",
  "last_name": "Torres",
  "email": "sofia.torres@example.com",
  "phone": "5557778888",
  "status": "new",
  "source": "Facebook Ads",
  "plan_slug": "premium-mensual",
  "preferred_payment_method": "card",
  "notes": "Interesada en yoga y pilates"
}
```

### Ver Lead
```http
GET {{BASE_URL}}/leads/1
Authorization: Bearer {{TOKEN}}
```

### Actualizar Lead
```http
PUT {{BASE_URL}}/leads/1
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "status": "contacted",
  "notes": "Llamada realizada - Agendada visita para mañana"
}
```

### ⭐ Convertir Lead a Cliente
```http
POST {{BASE_URL}}/leads/1/convert
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "birth_date": "1992-08-20",
  "address": "Av. Reforma 789",
  "emergency_contact_name": "Carlos Torres",
  "emergency_contact_phone": "5556665555",
  "notes": "Referido por Juan Pérez"
}
```

### Estadísticas de Leads
```http
GET {{BASE_URL}}/leads/statistics/all
Authorization: Bearer {{TOKEN}}
```

---

## 🧪 Casos de Prueba

### Test 1: Flujo Completo - Lead a Cliente Activo

1. **Crear Lead**
```http
POST {{BASE_URL}}/leads
{
  "first_name": "Test",
  "last_name": "Usuario",
  "email": "test.flow@example.com",
  "phone": "5551234567",
  "plan_slug": "basico-mensual"
}
```

2. **Convertir a Cliente**
```http
POST {{BASE_URL}}/leads/{id}/convert
{
  "birth_date": "1990-01-01",
  "address": "Test Street 123"
}
```

3. **Asignar Membresía**
```http
POST {{BASE_URL}}/memberships/assign
{
  "client_id": {nuevo_id},
  "plan_id": 1,
  "payment_method": "cash"
}
```

4. **Verificar Acceso**
```http
POST {{BASE_URL}}/access/verify-qr
{
  "qr_code": "{qr_code_generado}"
}
```

### Test 2: Acceso Denegado - Cliente sin Membresía

```http
POST {{BASE_URL}}/access/verify-qr
{
  "qr_code": "QR_DE_LUIS_HERNANDEZ"
}
```

Debe retornar `"allowed": false`

### Test 3: Reportes de Ingresos

```http
GET {{BASE_URL}}/payments/revenue/stats
```

Debe mostrar total de ingresos, promedio, y distribución por método de pago.

---

## 📊 Ejemplos de Respuestas

### Revenue Stats
```json
{
  "total_revenue": 1796.00,
  "total_payments": 4,
  "average_payment": 449.00,
  "completed": 4,
  "pending": 0,
  "refunded": 0,
  "by_method": {
    "cash": 1,
    "card": 2,
    "transfer": 1
  }
}
```

### Lead Statistics
```json
{
  "total": 3,
  "by_status": {
    "new": 1,
    "contacted": 1,
    "interested": 1
  },
  "conversion_rate": 0
}
```

### Access Verification
```json
{
  "allowed": true,
  "client": {
    "id": 1,
    "first_name": "Juan",
    "last_name": "Pérez",
    "email": "juan.perez@example.com",
    "phone": "1234567890",
    "qr_code": "QRNLBPQ8WK",
    "status": "active"
  },
  "message": "¡Acceso permitido! Bienvenido/a Juan",
  "log": {
    "id": 21,
    "client_id": 1,
    "access_type": "entry",
    "status": "allowed",
    "access_time": "2026-02-05T21:30:00.000000Z"
  }
}
```

---

## 🔥 Tips para Testing

1. **Siempre obtén el token primero** con `/api/login`
2. **Guarda el token** en una variable de entorno
3. **Usa los datos de prueba** creados por el seeder
4. **QR Codes válidos:**
   - Juan Pérez (activo con membresía)
   - María García (activo con membresía)
   - Carlos Rodríguez (activo con membresía)
   - Ana Martínez (activo con membresía)
   - Luis Hernández (inactivo SIN membresía) ← Usar para probar acceso denegado

5. **IDs de Planes:**
   - 1 = Básico Mensual ($299)
   - 2 = Premium Mensual ($499)
   - 3 = VIP Mensual ($899)
   - 4 = Trimestral ($799)
   - 5 = Anual ($2999)

---

¡Listo para probar! 🚀
