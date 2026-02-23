# 📊 ANÁLISIS COMPLETO DEL FRONTEND - IRONGYM

## 🎯 RESUMEN EJECUTIVO

El frontend tiene **15 servicios** que manejan todas las funcionalidades del sistema.
Actualmente TODOS usan `localStorage` para persistencia de datos.
Necesitan ser migrados a API REST del backend.

---

## 📋 SERVICIOS Y FUNCIONALIDADES

### 1. AUTH SERVICE ✅ (IMPLEMENTADO)
**Archivo:** `auth.service.ts`
**Estado Backend:** ✅ LISTO

**Funcionalidades:**
- `login(email, password)` → AuthState
- `logout()` → void
- `getCurrentUser()` → AuthState | null
- `isAuthenticated()` → boolean

**Datos:**
- Usuario demo: admin@demo.com / Admin123!
- Token mock generado

**Backend implementado:**
- POST /api/login
- POST /api/register
- POST /api/logout
- GET /api/user

---

### 2. CLIENTS SERVICE ✅ (IMPLEMENTADO)
**Archivo:** `clients.service.ts`
**Estado Backend:** ✅ LISTO

**Funcionalidades:**
- `getAll()` → Client[]
- `getById(id)` → Client | null
- `create(client)` → Client
- `update(id, updates)` → Client | null
- `delete(id)` → boolean
- `search(query)` → Client[]
- `getByQR(qrCode)` → Client | null

**Datos manejados:**
```typescript
Client {
  id: string
  name: string (frontend combina first_name + last_name)
  phone: string
  email?: string
  dpi?: string
  photo?: string
  notes?: string
  status: 'ACTIVE' | 'INACTIVE' | 'SUSPENDED'
  membershipEnd?: string
  profilePhoto?: string
  fingerprintId?: string
  fingerprintRegisteredAt?: string
  createdAt: string
}
```

**Backend implementado:**
- GET /api/clients
- POST /api/clients
- GET /api/clients/:id
- PUT /api/clients/:id
- DELETE /api/clients/:id

---

### 3. MEMBERSHIPS SERVICE ⚠️ (PARCIAL)
**Archivo:** `memberships.service.ts`
**Estado Backend:** ⚠️ FALTA IMPLEMENTAR

**Funcionalidades:**
- `getPlans()` → MembershipPlan[]
- `getPlanById(id)` → MembershipPlan | null
- `getPlanBySlug(slug)` → MembershipPlan | null
- `getPublishedPlans()` → MembershipPlan[]
- `createPlan(plan)` → MembershipPlan
- `updatePlan(id, updates)` → MembershipPlan | null
- `deletePlan(id)` → boolean
- `togglePublished(id)` → MembershipPlan | null
- **`assignMembership(clientId, planId, paymentMethod, amount, reference)`** → Payment | null

**Datos:**
```typescript
MembershipPlan {
  id: string
  name: string
  price: number
  durationDays: number
  description: string
  features: string[]
  published: boolean
  slug: string
  createdAt: string
  updatedAt: string
}
```

**Backend:**
- ✅ MembershipPlanController creado
- ❌ FALTA: método assignMembership en MembershipController

---

### 4. PAYMENTS SERVICE ❌ (NO IMPLEMENTADO)
**Archivo:** `payments.service.ts`
**Estado Backend:** ❌ FALTA TODO

**Funcionalidades:**
- `getAllPayments()` → Payment[]
- `getPaymentById(id)` → Payment | null
- `createManualPayment(payment)` → Payment
- `getPaymentsByClient(clientId)` → Payment[]
- `getPaymentsByStatus(status)` → Payment[]
- `getTotalRevenue()` → number
- `updatePaymentStatus(id, status)` → Payment | null

**Datos:**
```typescript
Payment {
  id: string
  clientId?: string
  leadId?: string
  planId: string
  membershipId?: string
  amount: number
  method: 'CASH' | 'TRANSFER' | 'STRIPE' | 'CARD'
  createdAt: string
  reference?: string
  status: 'PAID' | 'PENDING' | 'FAILED'
}
```

**Necesita:**
- Implementar PaymentController completo
- Métodos de estadísticas (getTotalRevenue)
- Filtros por cliente y status

---

### 5. ACCESS SERVICE ❌ (NO IMPLEMENTADO)
**Archivo:** `access.service.ts`
**Estado Backend:** ❌ FALTA TODO

**Funcionalidades:**
- `verifyAccessByQR(qrCode)` → { allowed, client, message }
- `verifyAccessByFingerprint(fingerprintId)` → { allowed, client, message }
- `verifyAccess(qrCode)` → { allowed, client, message }
- `getRecentLogs(limit)` → AccessLog[]
- `getLogsByClient(clientId)` → AccessLog[]
- `getAllLogs()` → AccessLog[]

**Datos:**
```typescript
AccessLog {
  id: string
  clientId: string
  createdAt: string
  method: 'QR' | 'FINGERPRINT'
  result: 'ALLOWED' | 'DENIED'
  clientName?: string
}
```

**Lógica de verificación:**
1. Buscar cliente por QR o fingerprint
2. Validar que esté activo
3. Validar que membresía no esté vencida
4. Crear log de acceso
5. Retornar resultado

**Necesita:**
- Implementar AccessLogController completo
- POST /api/access/verify-qr
- POST /api/access/verify-fingerprint
- GET /api/access/logs
- GET /api/access/logs/recent
- GET /api/access/logs/client/:id

---

### 6. LEADS SERVICE ❌ (NO IMPLEMENTADO)
**Archivo:** `leads.service.ts`
**Estado Backend:** ❌ FALTA TODO

**Funcionalidades:**
- `getAllLeads()` → Lead[]
- `getLeadById(id)` → Lead | null
- `getLeadsByStatus(status)` → Lead[]
- `createLead(lead)` → Lead
- `updateLead(id, updates)` → Lead | null
- **`convertToClient(leadId)`** → string | null (Crea cliente y marca lead como CONVERTED)
- `deleteLead(id)` → boolean

**Datos:**
```typescript
Lead {
  id: string
  name: string
  phone: string
  email?: string
  planSlug: string
  preferredPaymentMethod: string
  status: 'NEW' | 'CONTACTED' | 'INTERESTED' | 'CONVERTED'
  createdAt: string
}
```

**Necesita:**
- Implementar LeadController completo
- Método especial para convertToClient
- POST /api/leads/:id/convert

---

### 7. BLOG SERVICE ❌ (NO IMPLEMENTADO)
**Archivo:** `blog.service.ts`
**Estado Backend:** ❌ FALTA TODO

**Funcionalidades:**
- `getAllPosts()` → BlogPost[]
- `getPublishedPosts()` → BlogPost[]
- `getPostById(id)` → BlogPost | null
- `getPostBySlug(slug)` → BlogPost | null
- `createPost(post)` → BlogPost
- `updatePost(id, updates)` → BlogPost | null
- `deletePost(id)` → boolean
- `togglePublished(id)` → BlogPost | null

**Datos:**
```typescript
BlogPost {
  id: string
  title: string
  slug: string
  excerpt: string
  content: string
  coverImageUrl?: string
  published: boolean
  createdAt: string
  updatedAt: string
}
```

**Necesita:**
- Implementar BlogPostController completo
- GET /api/blog-posts (filtro published)
- GET /api/blog-posts/slug/:slug
- POST /api/blog-posts/:id/toggle-published

---

### 8. CASH SERVICE ❌ (NO IMPLEMENTADO)
**Archivo:** `cash.service.ts`
**Estado Backend:** ❌ FALTA TODO

**Funcionalidades:**
- `getAllMovements()` → CashMovement[]
- `getMovementById(id)` → CashMovement | undefined
- `createMovement(data)` → CashMovement
- **`getBalance()`** → number (Calcula IN - OUT)
- `getMovementsByDateRange(start, end)` → CashMovement[]

**Datos:**
```typescript
CashMovement {
  id: string
  type: 'IN' | 'OUT'
  amount: number
  category: string
  description: string
  reference?: string
  createdAt: string
}
```

**Necesita:**
- Implementar CashTransactionController completo
- GET /api/cash-transactions/balance
- GET /api/cash-transactions?from=X&to=Y
- Lógica de cálculo de balance

---

### 9. INVENTORY SERVICE ❌ (NO IMPLEMENTADO)
**Archivo:** `inventory.service.ts`
**Estado Backend:** ⚠️ TABLA EXISTE, FALTA IMPLEMENTAR

**Funcionalidades PRODUCTOS:**
- `getAllProducts()` → InventoryProduct[]
- `getProductById(id)` → InventoryProduct | undefined
- `createProduct(data)` → InventoryProduct
- `updateProduct(id, data)` → InventoryProduct | null
- `deleteProduct(id)` → boolean

**Funcionalidades MOVIMIENTOS:**
- `getAllMovements()` → InventoryMovement[]
- `getMovementsByProduct(productId)` → InventoryMovement[]
- **`createMovement(data)`** → InventoryMovement | null
  - Valida stock disponible para salidas
  - Actualiza automáticamente stock del producto

**Datos:**
```typescript
InventoryProduct {
  id: string
  name: string
  sku?: string
  unitPrice: number
  stock: number
  active: boolean
  createdAt: string
}

InventoryMovement {
  id: string
  productId: string
  type: 'IN' | 'OUT'
  quantity: number
  reference?: string
  notes?: string
  createdAt: string
}
```

**Necesita:**
- ❌ Crear tabla `inventory_movements`
- Implementar InventoryItemController completo
- Crear InventoryMovementController
- Lógica de actualización automática de stock

---

### 10. ROLES SERVICE ❌ (NO IMPLEMENTADO)
**Archivo:** `roles.service.ts`
**Estado Backend:** ⚠️ TABLA EXISTE, FALTA IMPLEMENTAR

**Funcionalidades:**
- `getAllRoles()` → Role[]
- `getRoleById(id)` → Role | undefined
- `createRole(data)` → Role
- `updateRole(id, data)` → Role | null
- `deleteRole(id)` → boolean
- `hasPermission(roleId, permission)` → boolean

**Datos:**
```typescript
Role {
  id: string
  name: string
  description?: string
  permissions: PermissionKey[]
  createdAt: string
}

// Permisos disponibles:
PermissionKey = 
  | 'DASHBOARD_VIEW'
  | 'CLIENTS_VIEW' | 'CLIENTS_CREATE' | 'CLIENTS_EDIT' | 'CLIENTS_DELETE'
  | 'PLANS_VIEW' | 'PLANS_MANAGE'
  | 'MEMBERSHIPS_VIEW' | 'MEMBERSHIPS_MANAGE'
  | 'PAYMENTS_VIEW' | 'PAYMENTS_MANAGE'
  | 'CASH_VIEW' | 'CASH_MANAGE'
  | 'INVENTORY_VIEW' | 'INVENTORY_MANAGE'
  | 'ACCESS_VIEW' | 'ACCESS_MANAGE'
  | 'SETTINGS_VIEW' | 'SETTINGS_MANAGE'
  | 'ROLES_VIEW' | 'ROLES_MANAGE'
  | 'USERS_VIEW' | 'USERS_MANAGE'
```

**Necesita:**
- Crear RoleController
- Implementar sistema de permisos
- Middleware de verificación de permisos

---

### 11. USERS SERVICE ❌ (NO IMPLEMENTADO)
**Archivo:** `users.service.ts`
**Estado Backend:** ⚠️ TABLA EXISTE, FALTA IMPLEMENTAR

**Funcionalidades:**
- `getAllUsers()` → User[]
- `getUserById(id)` → User | undefined
- `getUserByUsername(username)` → User | undefined
- `createUser(data)` → User
- `updateUser(id, data)` → User | null
- `deleteUser(id)` → boolean

**Datos:**
```typescript
User {
  id: string
  name: string
  username: string
  email: string
  roleId: string
  active: boolean
  createdAt: string
}
```

**Necesita:**
- Crear UserController
- GET /api/users
- POST /api/users
- PUT /api/users/:id
- DELETE /api/users/:id

---

### 12. STRIPE SERVICE ⚠️ (INTEGRACIÓN EXTERNA)
**Archivo:** `stripe.service.ts`
**Estado Backend:** ❌ NO IMPLEMENTADO

**Funcionalidades:**
- `createCheckoutSession(planId, clientId?, leadId?)` → StripeSession
- `getSessionById(sessionId)` → StripeSession | null
- `simulateWebhookSuccess(sessionId)` → Payment | null
- `createCheckoutSessionReal(data)` → Promise<{checkoutUrl, sessionId}>
- `handleReturnFromStripe(sessionId)` → StripeSession | null
- `getAllSessions()` → StripeSession[]

**Datos:**
```typescript
StripeSession {
  sessionId: string
  checkoutUrl: string
  planId: string
  clientId?: string
  leadId?: string
  amount: number
  status: 'pending' | 'completed' | 'expired'
  createdAt: string
}
```

**Lógica:**
1. Crear sesión de pago en Stripe
2. Retornar URL de checkout
3. Webhook recibe confirmación de pago
4. Crea Payment y asigna membresía al cliente

**Necesita:**
- Instalar Stripe SDK en backend
- Crear StripeController
- POST /api/stripe/create-checkout
- POST /api/webhooks/stripe (webhook handler)
- Configurar Stripe keys en .env

---

### 13. SITE SERVICE ❌ (NO IMPLEMENTADO)
**Archivo:** `site.service.ts`
**Estado Backend:** ❌ FALTA TODO

**Funcionalidades:**
- `getConfig()` → SiteConfig
- `getDefaultConfig()` → SiteConfig
- `updateConfig(updates)` → SiteConfig

**Datos:**
```typescript
SiteConfig {
  gymName: string
  slogan: string
  aboutText: string
  phone: string
  whatsapp: string
  instagram: string
  primaryColor: string
  heroImageUrl?: string
  updatedAt: string
}
```

**Implementación sugerida:**
- Tabla `settings` con key-value
- O tabla `site_config` con single row
- GET /api/settings
- PUT /api/settings

---

### 14. ECONOMIC PROFILE SERVICE ❌ (NO IMPLEMENTADO)
**Archivo:** `economic-profile.service.ts`
**Estado Backend:** ❌ FALTA TODO

**Funcionalidades:**
- `getByClient(clientId)` → EconomicProfileItem[]
- `create(item)` → EconomicProfileItem
- `update(id, updates)` → EconomicProfileItem | null
- `delete(id)` → boolean
- `getAll()` → EconomicProfileItem[]
- **`calculateCapacity(clientId)`** → { totalIncome, totalExpense, capacity }

**Datos:**
```typescript
EconomicProfileItem {
  id: string
  clientId: string
  type: 'INCOME' | 'EXPENSE'
  category: string
  source?: string
  monthlyAmount: number
  active: boolean
  createdAt: string
}
```

**Lógica de cálculo:**
- capacity = totalIncome - totalExpense
- Solo items activos

**Necesita:**
- ❌ Crear tabla `economic_profile_items`
- Crear EconomicProfileController
- GET /api/economic-profiles?clientId=X
- GET /api/economic-profiles/:clientId/capacity

---

### 15. PERMISSIONS (HELPER)
**Archivo:** `permissions.ts`
**Estado:** Helper functions

**Funcionalidades:**
- `can(permission)` → boolean
- `canAny(permissions[])` → boolean
- `canAll(permissions[])` → boolean

Usa el role del usuario actual y verifica si tiene el permiso.

**Backend:**
Middleware para rutas protegidas por permisos.

---

## 🗄️ TABLAS FALTANTES EN BACKEND

### 1. ❌ `inventory_movements`
```sql
CREATE TABLE inventory_movements (
  id BIGINT PRIMARY KEY,
  inventory_item_id BIGINT,
  type ENUM('IN', 'OUT'),
  quantity INT,
  reference VARCHAR(255),
  notes TEXT,
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
);
```

### 2. ❌ `economic_profile_items`
```sql
CREATE TABLE economic_profile_items (
  id BIGINT PRIMARY KEY,
  client_id BIGINT,
  type ENUM('INCOME', 'EXPENSE'),
  category VARCHAR(255),
  source VARCHAR(255),
  monthly_amount DECIMAL(10,2),
  active BOOLEAN DEFAULT 1,
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id)
);
```

### 3. ❌ `site_settings`
```sql
CREATE TABLE site_settings (
  id BIGINT PRIMARY KEY,
  key VARCHAR(255) UNIQUE,
  value TEXT,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

### 4. ❌ Actualizar `leads` table
```sql
ALTER TABLE leads 
ADD COLUMN plan_slug VARCHAR(255),
ADD COLUMN preferred_payment_method VARCHAR(50);
```

---

## 🎯 PRIORIDADES DE IMPLEMENTACIÓN

### 🔴 CRÍTICO (Funcionalidad Core)
1. ✅ Auth (HECHO)
2. ✅ Clients (HECHO)
3. ✅ MembershipPlans (HECHO)
4. ❌ **Memberships.assignMembership** (CRÍTICO para workflow)
5. ❌ **Access verification** (Core del negocio)
6. ❌ **Payments** (Finanzas)

### 🟡 IMPORTANTE (Gestión)
7. ❌ Leads
8. ❌ Dashboard stats endpoints
9. ❌ Users & Roles management

### 🟢 SECUNDARIO (Features adicionales)
10. ❌ Blog
11. ❌ Inventory & Movements
12. ❌ Cash transactions
13. ❌ Economic profiles
14. ❌ Site settings
15. ❌ Stripe integration

---

## 📝 ENDPOINTS NECESARIOS

```
AUTH ✅
POST   /api/register
POST   /api/login
POST   /api/logout
GET    /api/user

CLIENTS ✅
GET    /api/clients
POST   /api/clients
GET    /api/clients/:id
PUT    /api/clients/:id
DELETE /api/clients/:id
GET    /api/clients/qr/:qrCode

MEMBERSHIP PLANS ✅
GET    /api/membership-plans
POST   /api/membership-plans
GET    /api/membership-plans/:id
PUT    /api/membership-plans/:id
DELETE /api/membership-plans/:id
GET    /api/membership-plans/published

MEMBERSHIPS ❌
POST   /api/memberships/assign
GET    /api/memberships/client/:clientId

PAYMENTS ❌
GET    /api/payments
POST   /api/payments
GET    /api/payments/:id
GET    /api/payments/client/:clientId
GET    /api/payments/stats/revenue
PUT    /api/payments/:id/status

ACCESS LOGS ❌
POST   /api/access/verify-qr
POST   /api/access/verify-fingerprint
GET    /api/access/logs
GET    /api/access/logs/recent?limit=10
GET    /api/access/logs/client/:clientId

LEADS ❌
GET    /api/leads
POST   /api/leads
GET    /api/leads/:id
PUT    /api/leads/:id
DELETE /api/leads/:id
POST   /api/leads/:id/convert
GET    /api/leads/status/:status

BLOG POSTS ❌
GET    /api/blog-posts
POST   /api/blog-posts
GET    /api/blog-posts/:id
PUT    /api/blog-posts/:id
DELETE /api/blog-posts/:id
GET    /api/blog-posts/slug/:slug
POST   /api/blog-posts/:id/toggle-published

CASH TRANSACTIONS ❌
GET    /api/cash-transactions
POST   /api/cash-transactions
GET    /api/cash-transactions/:id
GET    /api/cash-transactions/balance
GET    /api/cash-transactions?from=X&to=Y

INVENTORY ITEMS ❌
GET    /api/inventory-items
POST   /api/inventory-items
GET    /api/inventory-items/:id
PUT    /api/inventory-items/:id
DELETE /api/inventory-items/:id

INVENTORY MOVEMENTS ❌
GET    /api/inventory-movements
POST   /api/inventory-movements
GET    /api/inventory-movements/product/:productId

ROLES ❌
GET    /api/roles
POST   /api/roles
GET    /api/roles/:id
PUT    /api/roles/:id
DELETE /api/roles/:id
GET    /api/roles/:id/permissions

USERS ❌
GET    /api/users
POST   /api/users
GET    /api/users/:id
PUT    /api/users/:id
DELETE /api/users/:id

ECONOMIC PROFILES ❌
GET    /api/economic-profiles?clientId=X
POST   /api/economic-profiles
PUT    /api/economic-profiles/:id
DELETE /api/economic-profiles/:id
GET    /api/economic-profiles/:clientId/capacity

SITE SETTINGS ❌
GET    /api/settings
PUT    /api/settings

STRIPE ❌
POST   /api/stripe/create-checkout
POST   /api/webhooks/stripe
GET    /api/stripe/sessions
GET    /api/stripe/sessions/:id
```

---

## 🚀 SIGUIENTE PASO

Implementar en orden de prioridad CRÍTICA:
1. Memberships.assignMembership
2. Access verification (QR y fingerprint)
3. Payments completo
4. Leads con convertToClient
