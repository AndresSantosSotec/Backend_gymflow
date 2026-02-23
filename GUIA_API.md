# 📚 Guía Completa de la API - IronGym Backend

## 🚀 Estado del Backend

### ✅ Implementado y Funcional

#### 1. **Autenticación (Laravel Sanctum)**
- ✅ POST `/api/register` - Registro de usuarios
- ✅ POST `/api/login` - Inicio de sesión
- ✅ POST `/api/logout` - Cerrar sesión
- ✅ GET `/api/user` - Obtener usuario autenticado

#### 2. **Clientes (CRUD Completo)**
- ✅ GET `/api/clients` - Listar todos los clientes (con búsqueda y paginación)
- ✅ POST `/api/clients` - Crear cliente
- ✅ GET `/api/clients/{id}` - Ver cliente específico
- ✅ PUT/PATCH `/api/clients/{id}` - Actualizar cliente
- ✅ DELETE `/api/clients/{id}` - Eliminar cliente (soft delete)
- ✅ GET `/api/clients/qr/{qrCode}` - Buscar cliente por código QR

#### 3. **Planes de Membresía (CRUD Completo)**
- ✅ GET `/api/membership-plans` - Listar planes
- ✅ POST `/api/membership-plans` - Crear plan
- ✅ GET `/api/membership-plans/{id}` - Ver plan
- ✅ PUT/PATCH `/api/membership-plans/{id}` - Actualizar plan
- ✅ DELETE `/api/membership-plans/{id}` - Eliminar plan

#### 4. **Membresías**
- ✅ GET `/api/memberships` - Listar membresías (con filtros)
- ✅ POST `/api/memberships` - Crear membresía
- ✅ GET `/api/memberships/{id}` - Ver membresía
- ✅ PUT/PATCH `/api/memberships/{id}` - Actualizar membresía
- ✅ DELETE `/api/memberships/{id}` - Eliminar membresía
- ✅ POST `/api/memberships/assign` - **Asignar membresía a cliente** (Crea pago automáticamente)

#### 5. **Pagos (CRUD Completo + Reportes)**
- ✅ GET `/api/payments` - Listar pagos (con filtros por status, método, cliente)
- ✅ POST `/api/payments` - Crear pago
- ✅ GET `/api/payments/{id}` - Ver pago
- ✅ PUT/PATCH `/api/payments/{id}` - Actualizar pago
- ✅ DELETE `/api/payments/{id}` - Eliminar pago
- ✅ GET `/api/payments/client/{clientId}` - Pagos por cliente
- ✅ GET `/api/payments/revenue/stats` - **Estadísticas de ingresos**
- ✅ PATCH `/api/payments/{id}/status` - Actualizar estado del pago

#### 6. **Control de Acceso (CRÍTICO - Core Business)**
- ✅ POST `/api/access/verify-qr` - **Verificar acceso por código QR**
- ✅ POST `/api/access/verify-fingerprint` - **Verificar acceso por huella digital**
- ✅ GET `/api/access/recent` - Logs de acceso recientes
- ✅ GET `/api/access/by-client/{clientId}` - Logs por cliente
- ✅ GET `/api/access-logs` - Todos los logs de acceso
- ✅ POST `/api/access-logs` - Crear log manual
- ✅ GET `/api/access-logs/{id}` - Ver log específico
- ✅ PUT/PATCH `/api/access-logs/{id}` - Actualizar log
- ✅ DELETE `/api/access-logs/{id}` - Eliminar log

#### 7. **Leads (Prospección)**
- ✅ GET `/api/leads` - Listar leads (con búsqueda y filtros)
- ✅ POST `/api/leads` - Crear lead
- ✅ GET `/api/leads/{id}` - Ver lead
- ✅ PUT/PATCH `/api/leads/{id}` - Actualizar lead
- ✅ DELETE `/api/leads/{id}` - Eliminar lead
- ✅ POST `/api/leads/{id}/convert` - **Convertir lead a cliente**
- ✅ GET `/api/leads/statistics/all` - Estadísticas de leads

#### 8. **Base de Datos**
- ✅ 23 migraciones ejecutadas correctamente
- ✅ 21 tablas creadas
- ✅ Datos de prueba (seeder) creados:
  - 5 planes de membresía
  - 5 clientes (4 activos, 1 inactivo)
  - 4 membresías activas
  - 4 pagos completados
  - 20 logs de acceso
  - 3 leads

---

## 🔧 Cómo Probar la API

### 1. Iniciar el Servidor

```bash
cd Backend-IronGym
php artisan serve
```

El servidor estará disponible en: `http://127.0.0.1:8000`

### 2. Autenticación

**Login (obtener token):**
```bash
POST http://127.0.0.1:8000/api/login
Content-Type: application/json

{
  "email": "admin@irongym.com",
  "password": "password123"
}
```

**Respuesta:**
```json
{
  "user": { ... },
  "token": "1|abcd1234..."
}
```

**Usar el token en las peticiones:**
```bash
Authorization: Bearer 1|abcd1234...
```

### 3. Ejemplos de Uso

#### ✅ Verificar Acceso por QR (Core Business)

```bash
POST http://127.0.0.1:8000/api/access/verify-qr
Authorization: Bearer {token}
Content-Type: application/json

{
  "qr_code": "QRNLBPQ8WK"
}
```

**Respuesta (Acceso Permitido):**
```json
{
  "allowed": true,
  "client": {
    "id": 1,
    "first_name": "Juan",
    "last_name": "Pérez",
    "email": "juan.perez@example.com",
    "status": "active",
    ...
  },
  "message": "¡Acceso permitido! Bienvenido/a Juan",
  "log": { ... }
}
```

#### ✅ Asignar Membresía a Cliente

```bash
POST http://127.0.0.1:8000/api/memberships/assign
Authorization: Bearer {token}
Content-Type: application/json

{
  "client_id": 5,
  "plan_id": 1,
  "payment_method": "card",
  "auto_renew": true
}
```

**Esto hace:**
1. Crea el pago automáticamente
2. Calcula las fechas de inicio y fin
3. Crea la membresía
4. Activa al cliente

#### ✅ Obtener Estadísticas de Ingresos

```bash
GET http://127.0.0.1:8000/api/payments/revenue/stats
Authorization: Bearer {token}
```

**Respuesta:**
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

#### ✅ Convertir Lead a Cliente

```bash
POST http://127.0.0.1:8000/api/leads/1/convert
Authorization: Bearer {token}
Content-Type: application/json

{
  "birth_date": "1995-06-20",
  "address": "Calle Principal 123",
  "emergency_contact_name": "María González",
  "emergency_contact_phone": "5559876543"
}
```

#### ✅ Buscar Cliente por QR

```bash
GET http://127.0.0.1:8000/api/clients/qr/QRNLBPQ8WK
Authorization: Bearer {token}
```

#### ✅ Listar Clientes con Búsqueda

```bash
GET http://127.0.0.1:8000/api/clients?search=juan&status=active
Authorization: Bearer {token}
```

---

## 📦 Datos de Prueba

### Usuarios
- **Admin:** admin@irongym.com / password123
- **Test:** test@irongym.com / password123

### Planes de Membresía
1. Básico Mensual - $299 (30 días)
2. Premium Mensual - $499 (30 días)
3. VIP Mensual - $899 (30 días)
4. Trimestral - $799 (90 días)
5. Anual - $2999 (365 días)

### Clientes con Membresías Activas
1. Juan Pérez - Básico Mensual
2. María García - Premium Mensual
3. Carlos Rodríguez - VIP Mensual
4. Ana Martínez - Básico Mensual
5. Luis Hernández - **SIN MEMBRESÍA** (para probar acceso denegado)

---

## 🔍 Flujo de Trabajo Típico

### Nuevo Cliente desde Lead

1. **Crear Lead**
   ```bash
   POST /api/leads
   {
     "first_name": "Pedro",
     "last_name": "González",
     "email": "pedro@example.com",
     "phone": "5551234567",
     "status": "new",
     "plan_slug": "premium-mensual"
   }
   ```

2. **Convertir a Cliente**
   ```bash
   POST /api/leads/{id}/convert
   {
     "birth_date": "1990-01-15",
     "address": "Av. Principal 456"
   }
   ```

3. **Asignar Membresía**
   ```bash
   POST /api/memberships/assign
   {
     "client_id": {nuevo_cliente_id},
     "plan_id": 2,
     "payment_method": "card"
   }
   ```

4. **Cliente activo con QR generado - Listo para usar el gimnasio**

### Verificación de Acceso

1. **Cliente llega al gimnasio con QR**
   ```bash
   POST /api/access/verify-qr
   {
     "qr_code": "QR12345678"
   }
   ```

2. **Sistema verifica:**
   - ✅ Cliente existe
   - ✅ Cliente está activo
   - ✅ Membresía vigente (no vencida)
   - ✅ Crea log de acceso

3. **Respuesta instantánea:** permitido/denegado

---

## 📊 Endpoints Pendientes de Implementar

### Media Prioridad
- ❌ BlogPostController (CRUD completo)
- ❌ CashTransactionController (CRUD + reportes)
- ❌ InventoryItemController (CRUD + stock)

### Baja Prioridad
- ❌ RoleController (gestión de roles)
- ❌ UserController (gestión de usuarios admin)
- ❌ SiteSettingsController (configuración del sitio)

---

## 🎯 Next Steps para el Frontend

1. **Crear servicio API (`api.service.ts`)**
   - Configurar Axios con baseURL
   - Interceptor para agregar token automáticamente
   - Manejo de errores global

2. **Migrar servicios de localStorage a API**
   - Empezar con `auth.service.ts`
   - Luego `clients.service.ts`
   - Seguir con `memberships.service.ts` y `payments.service.ts`

3. **Implementar panel de acceso en tiempo real**
   - Usar `/api/access/verify-qr` para lectura de QR
   - Mostrar logs recientes con `/api/access/recent`

4. **Dashboard con estadísticas**
   - Usar `/api/payments/revenue/stats`
   - Crear gráficos de ingresos
   - Panel de leads con `/api/leads/statistics/all`

---

## 🔐 Seguridad

- ✅ Todas las rutas protegidas con `auth:sanctum` middleware
- ✅ CORS configurado para localhost:5173, :3000, :5174
- ✅ Tokens de API con Laravel Sanctum
- ✅ Soft deletes en tablas críticas
- ✅ Validación de datos en todos los controllers

---

## 📝 Notas Importantes

1. **QR Codes:** Se generan automáticamente al crear clientes con formato `QR{8 caracteres aleatorios}`
2. **Membresías:** Al asignar, el cliente pasa automáticamente a estado `active`
3. **Pagos:** Se crean automáticamente al asignar membresía
4. **Access Logs:** Registran TODOS los intentos (permitidos y denegados)
5. **Leads:** Estado cambia a `converted` al transformarse en cliente, no se pueden reconvertir

---

¡Backend listo para integrarse con el frontend! 🚀
