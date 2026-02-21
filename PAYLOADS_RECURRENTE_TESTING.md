# 📦 Payloads Validados - Recurrente API

## 🔑 Variables a Reemplazar en Todos los Requests

```
{{BASE_URL}} = https://app.recurrente.com/api
{{RECURRENTE_PUBLIC_KEY}} = Tu llave pública (pk_test_... o pk_live_...)
{{RECURRENTE_SECRET_KEY}} = Tu llave secreta (sk_test_... o sk_live_...)
```

---

## ✅ PAYLOAD 1: Crear Usuario (Cliente)

**Endpoint:** `POST {{BASE_URL}}/users`

**Headers:**
```http
X-PUBLIC-KEY: {{RECURRENTE_PUBLIC_KEY}}
X-SECRET-KEY: {{RECURRENTE_SECRET_KEY}}
Content-Type: application/json
Accept: application/json
```

**Body (JSON):**
```json
{
  "email": "cliente1@gymflow.com",
  "name": "Juan Pérez García"
}
```

**cURL:**
```bash
curl -X POST "https://app.recurrente.com/api/users" \
  -H "X-PUBLIC-KEY: pk_test_xxxxxxxxxxxxx" \
  -H "X-SECRET-KEY: sk_test_xxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "cliente1@gymflow.com",
    "name": "Juan Pérez García"
  }'
```

**Response (201):**
```json
{
  "id": "us_dsvxz8nkq0s3h0m1",
  "email": "cliente1@gymflow.com",
  "name": "Juan Pérez García",
  "created_at": "2026-02-21T10:30:00Z"
}
```

**Guardar:** `user_id = "us_dsvxz8nkq0s3h0m1"`

---

## ✅ PAYLOAD 2: Crear Producto (Plan Mensual Q150)

**Endpoint:** `POST {{BASE_URL}}/products`

**Body (JSON):**
```json
{
  "name": "Membresía Premium - 1 Mes",
  "description": "Acceso completo al gimnasio + Clases + Estacionamiento",
  "price_in_cents": 15000,
  "currency": "GTQ"
}
```

**cURL:**
```bash
curl -X POST "https://app.recurrente.com/api/products" \
  -H "X-PUBLIC-KEY: pk_test_xxxxxxxxxxxxx" \
  -H "X-SECRET-KEY: sk_test_xxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Membresía Premium - 1 Mes",
    "description": "Acceso completo al gimnasio + Clases + Estacionamiento",
    "price_in_cents": 15000,
    "currency": "GTQ"
  }'
```

**Response (201):**
```json
{
  "id": "prod_abc123xyz456",
  "name": "Membresía Premium - 1 Mes",
  "description": "Acceso completo al gimnasio + Clases + Estacionamiento",
  "price_in_cents": 15000,
  "currency": "GTQ"
}
```

**Guardar:** `product_id = "prod_abc123xyz456"`

**Variantes de Planes:**

### Membresía 3 Meses (Q400)
```json
{
  "name": "Membresía Premium - 3 Meses",
  "price_in_cents": 40000,
  "currency": "GTQ"
}
```

### Membresía Anual (Q1400)
```json
{
  "name": "Membresía Premium - 1 Año",
  "price_in_cents": 140000,
  "currency": "GTQ"
}
```

---

## ✅ PAYLOAD 3: Crear Checkout (Pago Inicial)

**Endpoint:** `POST {{BASE_URL}}/checkouts`

```json
{
  "user_id": "us_dsvxz8nkq0s3h0m1",
  "items": [
    {
      "product_id": "prod_abc123xyz456",
      "quantity": 1
    }
  ],
  "success_url": "https://app.gymflow.com/memberships/success?checkout_id={checkout_id}",
  "cancel_url": "https://app.gymflow.com/memberships/cancelled",
  "metadata": {
    "membership_id": 1,
    "client_id": 1,
    "plan_type": "monthly",
    "reference": "MEM-001-202602"
  }
}
```

**cURL completo:**
```bash
curl -X POST "https://app.recurrente.com/api/checkouts" \
  -H "X-PUBLIC-KEY: pk_test_xxxxxxxxxxxxx" \
  -H "X-SECRET-KEY: sk_test_xxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": "us_dsvxz8nkq0s3h0m1",
    "items": [
      {
        "product_id": "prod_abc123xyz456",
        "quantity": 1
      }
    ],
    "success_url": "https://app.gymflow.com/memberships/success?checkout_id={checkout_id}",
    "cancel_url": "https://app.gymflow.com/memberships/cancelled",
    "metadata": {
      "membership_id": 1,
      "client_id": 1
    }
  }'
```

**Response (201):**
```json
{
  "id": "ch_xyz789abc123",
  "status": "pending",
  "user_id": "us_dsvxz8nkq0s3h0m1",
  "checkout_url": "https://app.recurrente.com/checkout/ch_xyz789abc123?preview=false",
  "live_mode": false,
  "created_at": "2026-02-21T10:35:00Z"
}
```

**Siguientes pasos:**
1. Enviar `checkout_url` al cliente (en email o QR)
2. Cliente abre link en navegador
3. Ingresa tarjeta: **4242 4242 4242 4242**
4. Completa formulario
5. Se resuelta a `success_url`
6. Backend recibe webhook `checkout.succeeded`

---

## ✅ PAYLOAD 4: Cobrar Cuota Recurrente (Tarjeta Guardada)

**⚠️ IMPORTANTE:** Este endpoint solo funciona DESPUÉS de que el cliente haya pagado un checkout exitosamente y tengas su `payment_method_id`.

**Endpoint:** `POST {{BASE_URL}}/one_time_payments`

```json
{
  "payment_method_id": "pay_m_abc123def456",
  "items": [
    {
      "name": "Cuota Membresía Premium - Mes 2",
      "currency": "GTQ",
      "amount_in_cents": 15000,
      "quantity": 1
    }
  ],
  "user_id": "us_dsvxz8nkq0s3h0m1",
  "metadata": {
    "membership_id": 1,
    "installment_number": 2,
    "due_date": "2026-03-21"
  }
}
```

**cURL:**
```bash
curl -X POST "https://app.recurrente.com/api/one_time_payments" \
  -H "X-PUBLIC-KEY: pk_test_xxxxxxxxxxxxx" \
  -H "X-SECRET-KEY: sk_test_xxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "payment_method_id": "pay_m_abc123def456",
    "items": [
      {
        "name": "Cuota Membresía Premium - Mes 2",
        "currency": "GTQ",
        "amount_in_cents": 15000,
        "quantity": 1
      }
    ],
    "user_id": "us_dsvxz8nkq0s3h0m1",
    "metadata": {
      "membership_id": 1,
      "installment_number": 2
    }
  }'
```

**Response (201):**
```json
{
  "id": "on_payment456789",
  "status": "paid",
  "user_id": "us_dsvxz8nkq0s3h0m1",
  "items": [
    {
      "name": "Cuota Membresía Premium - Mes 2",
      "currency": "GTQ",
      "amount_in_cents": 15000,
      "quantity": 1
    }
  ],
  "paid_at": "2026-02-21T10:40:00Z"
}
```

---

## ✅ PAYLOAD 5: Crear Suscripción Automática

**Endpoint:** `POST {{BASE_URL}}/subscriptions`

```json
{
  "user_id": "us_dsvxz8nkq0s3h0m1",
  "product_id": "prod_abc123xyz456",
  "billing_cycle": "monthly",
  "payment_method_id": "pay_m_abc123def456",
  "metadata": {
    "membership_id": 1,
    "client_id": 1,
    "auto_renew": true
  }
}
```

**cURL:**
```bash
curl -X POST "https://app.recurrente.com/api/subscriptions" \
  -H "X-PUBLIC-KEY: pk_test_xxxxxxxxxxxxx" \
  -H "X-SECRET-KEY: sk_test_xxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": "us_dsvxz8nkq0s3h0m1",
    "product_id": "prod_abc123xyz456",
    "billing_cycle": "monthly",
    "payment_method_id": "pay_m_abc123def456",
    "metadata": {
      "membership_id": 1,
      "client_id": 1
    }
  }'
```

**Response (201):**
```json
{
  "id": "sub_sub123456",
  "status": "active",
  "user_id": "us_dsvxz8nkq0s3h0m1",
  "product_id": "prod_abc123xyz456",
  "billing_cycle": "monthly",
  "next_billing_date": "2026-03-21",
  "created_at": "2026-02-21T10:45:00Z"
}
```

---

## ✅ PAYLOAD 6: Validar Tarjeta Guardada (GET)

**Endpoint:** `GET {{BASE_URL}}/users/us_dsvxz8nkq0s3h0m1/payment_methods`

**Headers:**
```http
X-PUBLIC-KEY: {{RECURRENTE_PUBLIC_KEY}}
X-SECRET-KEY: {{RECURRENTE_SECRET_KEY}}
Accept: application/json
```

**cURL:**
```bash
curl -X GET "https://app.recurrente.com/api/users/us_dsvxz8nkq0s3h0m1/payment_methods" \
  -H "X-PUBLIC-KEY: pk_test_xxxxxxxxxxxxx" \
  -H "X-SECRET-KEY: sk_test_xxxxxxxxxxxxx"
```

**Response (200):**
```json
{
  "data": [
    {
      "id": "pay_m_abc123def456",
      "type": "credit_card",
      "card": {
        "brand": "visa",
        "last_4": "4242",
        "exp_month": 12,
        "exp_year": 2028
      },
      "is_default": true,
      "created_at": "2026-02-21T10:30:00Z"
    }
  ]
}
```

---

## ✅ PAYLOAD 7: Obtener Detalles de Pago

**Endpoint:** `GET {{BASE_URL}}/one_time_payments/on_payment456789`

**cURL:**
```bash
curl -X GET "https://app.recurrente.com/api/one_time_payments/on_payment456789" \
  -H "X-PUBLIC-KEY: pk_test_xxxxxxxxxxxxx" \
  -H "X-SECRET-KEY: sk_test_xxxxxxxxxxxxx"
```

**Response (200):**
```json
{
  "id": "on_payment456789",
  "status": "paid",
  "user_id": "us_dsvxz8nkq0s3h0m1",
  "amount_in_cents": 15000,
  "currency": "GTQ",
  "paid_at": "2026-02-21T10:40:00Z"
}
```

---

## ✅ PAYLOAD 8: Cancelar Suscripción

**Endpoint:** `DELETE {{BASE_URL}}/subscriptions/sub_sub123456`

**cURL:**
```bash
curl -X DELETE "https://app.recurrente.com/api/subscriptions/sub_sub123456" \
  -H "X-PUBLIC-KEY: pk_test_xxxxxxxxxxxxx" \
  -H "X-SECRET-KEY: sk_test_xxxxxxxxxxxxx"
```

**Response (200):**
```json
{
  "id": "sub_sub123456",
  "status": "cancelled",
  "cancelled_at": "2026-02-21T11:00:00Z"
}
```

---

## 🧪 FLUJO COMPLETO DE TESTING

### Paso 1: Crear Usuario
```bash
curl -X POST "https://app.recurrente.com/api/users" \
  -H "X-PUBLIC-KEY: pk_test_xxxxx" \
  -H "X-SECRET-KEY: sk_test_xxxxx" \
  -H "Content-Type: application/json" \
  -d '{"email": "test@gymflow.com", "name": "Test User"}'
# Guardar: user_id
```

### Paso 2: Crear Producto
```bash
curl -X POST "https://app.recurrente.com/api/products" \
  -H "X-PUBLIC-KEY: pk_test_xxxxx" \
  -H "X-SECRET-KEY: sk_test_xxxxx" \
  -H "Content-Type: application/json" \
  -d '{"name": "Membresía", "price_in_cents": 15000, "currency": "GTQ"}'
# Guardar: product_id
```

### Paso 3: Crear Checkout
```bash
curl -X POST "https://app.recurrente.com/api/checkouts" \
  -H "X-PUBLIC-KEY: pk_test_xxxxx" \
  -H "X-SECRET-KEY: sk_test_xxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": "us_xxxxx",
    "items": [{"product_id": "prod_xxxxx", "quantity": 1}],
    "success_url": "https://app.gymflow.com/success",
    "cancel_url": "https://app.gymflow.com/cancel"
  }'
# Guardar: checkout_url → Abrir en navegador
```

### Paso 4: Pagar en Recurrente (Manual)
- Abrir: `checkout_url`
- Ingresar tarjeta: **4242 4242 4242 4242**
- Expiración: Cualquier fecha futura (ej: 12/28)
- CVV: Cualquier 3 dígitos (ej: 123)
- Hacer clic en **"Pagar"**

### Paso 5: Verificar Webhook en Backend
```bash
tail -f storage/logs/laravel.log | grep "checkout.succeeded"
# Debe mostrar el evento recibido y procesado
```

### Paso 6: Verificar Base de Datos
```sql
SELECT * FROM recurrente_payments ORDER BY created_at DESC LIMIT 1;
SELECT * FROM memberships WHERE id = 1;
```

---

## 🔍 Validaciones Importantes

### ✓ Centavos Correctos

| Quetzales | Centavos | Validación |
|-----------|----------|-----------|
| Q150.00 | 15000 | ✅ CORRECTO |
| Q150 | 15000 | ✅ CORRECTO |
| Q0.50 | 50 | ✅ CORRECTO |
| Q1,500.00 | 150000 | ✅ CORRECTO |
| Q150 | 150 | ❌ ERROR (Q1.50) |

### ✓ User ID es Obligatorio en Checkouts

```json
{
  "user_id": "us_xxxx",        // ← OBLIGATORIO
  "items": [...],
  "success_url": "...",
  "cancel_url": "..."
}
```

### ✓ Payment Method ID es Obligatorio en One-Time Payments

```json
{
  "payment_method_id": "pay_m_xxxx",  // ← OBLIGATORIO
  "items": [...]
}
```

---

## 🚨 Casos de Error Comunes

### 401 Unauthorized
```json
{
  "error": "Unauthorized",
  "message": "Invalid X-PUBLIC-KEY or X-SECRET-KEY"
}
```
**Solución:** Verificar llaves en Recurrente → Configuración → Llaves API

### 422 Unprocessable Entity
```json
{
  "error": "Validation error",
  "message": "User not found"
}
```
**Solución:** Verificar que user_id existe (crear usuario primero)

### 400 Bad Request
```json
{
  "error": "Invalid request",
  "message": "amount_in_cents must be in cents, not quetzales"
}
```
**Solución:** Verificar conversión a centavos

---

**Última actualización:** 21 de Febrero de 2026
