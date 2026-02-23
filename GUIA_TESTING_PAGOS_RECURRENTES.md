# 🧪 Guía Completa - Testing de Pagos Recurrentes (Recurrente API)

## 📋 Tabla de Contenidos
1. [Configuración de API Keys](#configuración-de-api-keys)
2. [Validación de Credenciales](#validación-de-credenciales)
3. [Ambientes (Sandbox vs Producción)](#ambientes-sandbox-vs-producción)
4. [Payloads Validados para Testing](#payloads-validados-para-testing)
5. [Endpoints Disponibles](#endpoints-disponibles)
6. [Pruebas Paso a Paso](#pruebas-paso-a-paso)
7. [Verificación de Webhooks](#verificación-de-webhooks)

---

## Configuración de API Keys

### 1️⃣ Ubicar las Llaves en Recurrente

1. Ve a **https://app.recurrente.com**
2. Inicia sesión en tu cuenta
3. Navega a **Configuración → Llaves API**
4. Encontrarás:
   - **Llave Pública** (`X-PUBLIC-KEY`)
   - **Llave Privada/Secreta** (`X-SECRET-KEY`)
   - Llaves para TEST y PRODUCCIÓN

### 2️⃣ Configurar en tu .env

```bash
# Ambiente (test o production)
RECURRENTE_ENV=test

# URLs de la API
RECURRENTE_BASE_URL=https://app.recurrente.com/api

# Llaves del Ambiente TEST (para desarrollo)
RECURRENTE_PUBLIC_KEY=pk_test_xxxxxxxxxxxxxxxxxxxxx
RECURRENTE_SECRET_KEY=sk_test_xxxxxxxxxxxxxxxxxxxxx

# (Opcional) Llaves de Producción (NO usar en desarrollo)
# RECURRENTE_PUBLIC_KEY_PROD=pk_live_xxxxxxxxxxxxxxxxxxxxx
# RECURRENTE_SECRET_KEY_PROD=sk_live_xxxxxxxxxxxxxxxxxxxxx
```

### 3️⃣ Recargar Configuración

```bash
php artisan config:cache
```

---

## Validación de Credenciales

### ✅ Test Automático

Ejecuta el comando que verifica todas las llaves:

```bash
php artisan smoke:test
```

Este comando verifica:
- ✓ Llaves configuradas en .env
- ✓ Conexión a API de Recurrente
- ✓ Autenticación correcta (401 si las llaves son inválidas)
- ✓ Formato de respuesta

### 🔍 Verificación Manual

**GET** a cualquier endpoint con llaves inválidas debe retornar:

```json
{
  "error": "Unauthorized",
  "message": "Invalid API credentials"
}
```

**HTTP Status: 401**

---

## Ambientes (Sandbox vs Producción)

### 🧪 AMBIENTE TEST (Sandbox)

**Características:**
- No genera actividad real
- No afecta el balance
- No dispara webhooks
- Indicador "PRUEBA" en el checkout

**Tarjeta de Prueba:**
- Número: `4242 4242 4242 4242`
- Expiración: Cualquier fecha futura
- CVV: Cualquier 3 dígitos

**Configuración:**
```env
RECURRENTE_ENV=test
RECURRENTE_PUBLIC_KEY=pk_test_xxxxx
RECURRENTE_SECRET_KEY=sk_test_xxxxx
```

### 🏢 AMBIENTE LIVE (Producción)

**Características:**
- Operaciones reales
- Afecta el balance
- Dispara webhooks
- Pagos sin indicador "PRUEBA"

**Recomendación:**
- Reembolsar pagos de prueba el mismo día (100% del monto)
- Usar SIEMPRE TEST para desarrollo

---

## Payloads Validados para Testing

### 1. Crear Usuario (Cliente)

**Endpoint:** `POST /users`

```json
{
  "email": "cliente@irongym.com",
  "name": "Juan Pérez"
}
```

**Response Exitoso (201):**
```json
{
  "id": "us_abc123def456",
  "email": "cliente@irongym.com",
  "name": "Juan Pérez"
}
```

---

### 2. Crear Producto (Plan de Membresía)

**Endpoint:** `POST /products`

```json
{
  "name": "Membresía Premium - 1 Mes",
  "description": "Acceso completo al gimnasio por 1 mes",
  "price_in_cents": 15000,
  "currency": "GTQ"
}
```

**Response Exitoso (201):**
```json
{
  "id": "prod_xyz789abc",
  "name": "Membresía Premium - 1 Mes",
  "price_in_cents": 15000,
  "currency": "GTQ"
}
```

**Nota:** El precio debe estar SIEMPRE en centavos:
- Q150.00 = 15000 centavos
- Q50.50 = 5050 centavos

---

### 3. Crear Checkout Hosteado (Pago con Nuevo Cliente)

**Endpoint:** `POST /checkouts`

```json
{
  "user_id": "us_abc123def456",
  "items": [
    {
      "product_id": "prod_xyz789abc",
      "quantity": 1
    }
  ],
  "success_url": "https://app.irongym.com/memberships/success",
  "cancel_url": "https://app.irongym.com/memberships/cancelled",
  "metadata": {
    "membership_id": 1,
    "client_id": 1,
    "plan_type": "monthly"
  }
}
```

**Response Exitoso (201):**
```json
{
  "id": "ch_checkout123456",
  "status": "pending",
  "user_id": "us_abc123def456",
  "checkout_url": "https://app.recurrente.com/checkout/ch_checkout123456",
  "live_mode": false
}
```

**Pasos siguientes:**
1. Enviar `checkout_url` al frontend
2. Cliente hace clic y es redirigido a Recurrente
3. Completa formulario de tarjeta
4. Se dispara webhook `checkout.succeeded`
5. Backend activa membresía automáticamente

---

### 4. Crear Pago Único (Tarjeta Guardada)

**Prerequisito:** El cliente ya tiene una tarjeta guardada (`payment_method_id`)

**Endpoint:** `POST /one_time_payments`

```json
{
  "payment_method_id": "pay_m_abc123",
  "items": [
    {
      "name": "Cuota de Membresía - Mes 1",
      "currency": "GTQ",
      "amount_in_cents": 15000,
      "quantity": 1
    }
  ],
  "user_id": "us_abc123def456",
  "metadata": {
    "installment_number": 1,
    "membership_id": 1
  }
}
```

**Response Exitoso (201):**
```json
{
  "id": "on_payment123",
  "status": "paid",
  "user_id": "us_abc123def456",
  "items": [...],
  "amount_in_cents": 15000
}
```

---

### 5. Crear Suscripción (Cobros Periódicos)

**Endpoint:** `POST /subscriptions`

```json
{
  "user_id": "us_abc123def456",
  "product_id": "prod_xyz789abc",
  "billing_cycle": "monthly",
  "payment_method_id": "pay_m_abc123",
  "metadata": {
    "membership_id": 1,
    "plan_type": "membresía_recurrente"
  }
}
```

**Response Exitoso (201):**
```json
{
  "id": "sub_subscription123",
  "status": "active",
  "user_id": "us_abc123def456",
  "product_id": "prod_xyz789abc",
  "billing_cycle": "monthly",
  "next_billing_date": "2026-03-21"
}
```

---

## Endpoints Disponibles

### 🔐 Headers Requeridos (TODOS los requests)

```http
X-PUBLIC-KEY: pk_test_xxxxxxxxxxxxxxxxxxxxx
X-SECRET-KEY: sk_test_xxxxxxxxxxxxxxxxxxxxx
Content-Type: application/json
```

### Usuario / Cliente

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST | `/users` | Crear usuario |
| GET | `/users/{id}` | Obtener usuario |
| GET | `/users/{id}/payment_methods` | Listar tarjetas guardadas |

### Productos

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST | `/products` | Crear producto |
| GET | `/products/{id}` | Obtener producto |

### Checkouts (Pago Hosteado)

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST | `/checkouts` | Crear checkout |
| GET | `/checkout/{id}` | Obtener detalles del checkout |

### Pagos Únicos

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST | `/one_time_payments` | Cobrar con tarjeta guardada |
| GET | `/one_time_payments/{id}` | Obtener detalles de pago |

### Suscripciones

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST | `/subscriptions` | Crear suscripción |
| GET | `/subscriptions/{id}` | Obtener suscripción |
| DELETE | `/subscriptions/{id}` | Cancelar suscripción |

---

## Pruebas Paso a Paso

### 📝 Scenario 1: Cliente Nuevo Paga Primer Mes

```bash
# 1. Crear usuario en Recurrente
POST /users
{
  "email": "juan@example.com",
  "name": "Juan"
}
# Guardar: user_id = "us_..."

# 2. Crear producto (si no existe)
POST /products
{
  "name": "Membresía Premium",
  "price_in_cents": 15000,
  "currency": "GTQ"
}
# Guardar: product_id = "prod_..."

# 3. Crear checkout
POST /checkouts
{
  "user_id": "us_...",
  "items": [{"product_id": "prod_...", "quantity": 1}],
  "success_url": "https://app.irongym.com/success",
  "cancel_url": "https://app.irongym.com/cancel"
}
# Guardar: checkout_url → Enviar al frontend

# 4. Cliente abre el link checkout_url en navegador
# → Rellena tarjeta: 4242 4242 4242 4242
# → Hace clic en "Pagar"

# 5. Recurrente envía webhook a tu backend:
POST /webhooks/recurrente
{
  "event": "checkout.succeeded",
  "id": "wh_...",
  "data": {
    "id": "ch_checkout123",
    "user_id": "us_abc123",
    "payment_method_id": "pay_m_abc123",
    "payment_id": "pay_123"
  }
}

# 6. Tu backend procesa webhook:
# ✓ Guarda payment_method_id
# ✓ Crea Membership como "active"
# ✓ Crea Receipt automáticamente
# ✓ Retorna HTTP 200
```

---

### 📝 Scenario 2: Cobrar Cuota Mensual Siguiente

```bash
# Cuota 2 (dentro de 30 días): Cobrar con tarjeta guardada

POST /one_time_payments
{
  "payment_method_id": "pay_m_abc123",
  "items": [
    {
      "name": "Cuota Membresía - Mes 2",
      "currency": "GTQ",
      "amount_in_cents": 15000,
      "quantity": 1
    }
  ],
  "user_id": "us_abc123def456"
}

# Response: { "id": "on_payment456", "status": "paid" }

# Tu backend recibe webhook:
POST /webhooks/recurrente
{
  "event": "one_time_payment.succeeded",
  "id": "wh_...",
  "data": {
    "id": "on_payment456",
    "payment_id": "pay_456",
    "user_id": "us_abc123"
  }
}

# Resultado: PaymentInstallment actualizado a "paid"
```

---

## Verificación de Webhooks

### 1️⃣ Configurar URL de Webhooks en Recurrente

1. Ve a **https://app.recurrente.com → Configuración → Webhooks**
2. Añade nueva webhook:
   - **URL:** `https://tu-dominio.com/webhooks/recurrente`
   - **Eventos:** Selecciona todos los relevantes:
     - ✓ `checkout.succeeded`
     - ✓ `subscription.paid`
     - ✓ `one_time_payment.succeeded`
     - ✓ `subscription.cancelled`
     - ✓ `payment.failed`

### 2️⃣ Verificar Recepción de Webhooks (Desarrollo Local)

Usa **ngrok** para exponer tu servidor local:

```bash
# Terminal 1: Inicia tu servidor Laravel
php artisan serve

# Terminal 2: Expone en internet
ngrok http 8000

# URL pública: https://xxxx-xx-xxx-xxx.ngrok.io
# Configurar en Recurrente: https://xxxx-xx-xxx-xxx.ngrok.io/webhooks/recurrente
```

### 3️⃣ Ver Logs de Webhooks Recibidos

```bash
# Ver últimas líneas de logs en tiempo real
tail -f storage/logs/laravel.log

# Buscar eventos específicos de Recurrente
grep "Webhook:Recurrente" storage/logs/laravel.log -A 5

# Ver solo errores
grep "❌ Error" storage/logs/laravel.log
```

---

## 🧪 Testing con Postman

### Colección JSON (Import esto en Postman)

```json
{
  "info": {
    "name": "Pagos Recurrentes - IronGym",
    "description": "Testing de Recurrente API"
  },
  "item": [
    {
      "name": "1. Crear Usuario",
      "request": {
        "method": "POST",
        "header": [
          {"key": "X-PUBLIC-KEY", "value": "{{RECURRENTE_PUBLIC_KEY}}"},
          {"key": "X-SECRET-KEY", "value": "{{RECURRENTE_SECRET_KEY}}"}
        ],
        "url": {"raw": "{{RECURRENTE_BASE_URL}}/users", "path": ["users"]},
        "body": {
          "mode": "raw",
          "raw": "{\"email\": \"test@irongym.com\", \"name\": \"Test User\"}"
        }
      }
    }
  ]
}
```

---

## ⚠️ Errores Comunes y Soluciones

| Error | Causa | Solución |
|-------|-------|----------|
| **401 Unauthorized** | Llaves de API inválidas | Verificar en Recurrente → Configuración → Llaves API |
| **400 Bad Request** | Payload malformado | Validar JSON y formato de centavos |
| **422 Unprocessable** | Usuario/Producto no encontrado | Verificar IDs antes de usar |
| **429 Too Many Requests** | Límite de requests excedido | Esperar 1 minuto y reintentar |
| **Webhook no recibido** | URL incorrecta o firewall | Usar ngrok para desarrollo local |

---

## 📊 Validación de Datos en Base de Datos

### Después de cada pago, verificar:

```sql
-- Ver el RecurrentePayment creado
SELECT * FROM recurrente_payments 
WHERE client_id = 1 
ORDER BY created_at DESC 
LIMIT 1;

-- Ver PaymentInstallment actualizado
SELECT * FROM payment_installments 
WHERE membership_id = 1 
ORDER BY due_date;

-- Ver Membership activada
SELECT * FROM memberships WHERE id = 1;

-- Ver Receipt generado
SELECT * FROM receipts 
WHERE concept = 'subscription' 
ORDER BY created_at DESC 
LIMIT 1;
```

---

## 🔗 Referencias

- [Documentación Recurrente API](https://docs.recurrente.com)
- [Dashboard Recurrente](https://app.recurrente.com)
- [Status Page](https://status.recurrente.com)
- [Discord Community](https://discord.gg/recurrente)

---

## ✅ Checklist Pre-Deploy a Producción

- [ ] Llaves de API configuradas correctamente
- [ ] `php artisan smoke:test` pasa sin errores
- [ ] Webhooks configurados en Recurrente dashboard
- [ ] URL de webhooks es HTTPS con certificado válido
- [ ] Verificar que el webhook se recibe en producción
- [ ] Payment method se guarda correctamente
- [ ] Membresía se activa automáticamente
- [ ] Receipt se genera automáticamente
- [ ] Email de confirmación se envía
- [ ] Conversión de centavos siempre correcta

---

**Última actualización:** 21 de Febrero de 2026  
**Versión:** 1.0.0
