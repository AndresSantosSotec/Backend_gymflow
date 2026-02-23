# ANÁLISIS Y PLAN DE INTEGRACIÓN FRONTEND-BACKEND IRONGYM

## 📊 ANÁLISIS DE TIPOS Y MODELOS

### ✅ MODELOS COINCIDENTES (Ya creados)
1. **Client** ✓
   - Frontend usa: `id`, `name`, `phone`, `email`, `status`, `createdAt`
   - Backend tiene: `first_name`, `last_name`, `email`, `phone`, `qr_code`, `status`
   - **ACCIÓN**: Concatenar `first_name + last_name` en respuestas API

2. **Payment** ✓
   - Frontend: `id`, `clientId`, `planId`, `amount`, `method`, `status`
   - Backend: `client_id`, `membership_id`, `amount`, `payment_method`, `status`
   - **ACCIÓN**: Transformar nombres en API Resources

3. **AccessLog** ✓
   - Frontend: `id`, `clientId`, `method`, `result`
   - Backend: `client_id`, `access_type`, `status`
   - **ACCIÓN**: OK, solo adaptar nombres

### ❌ MODELOS FALTANTES EN BACKEND

4. **MembershipPlan**
   - Campos frontend: `id`, `name`, `price`, `durationDays`, `description`, `features`, `published`, `slug`
   - **ACCIÓN**: Crear tabla `membership_plans` + modelo + controlador

5. **Lead** ✓ (Ya existe)
   - Frontend: `name`, `phone`, `email`, `planSlug`, `preferredPaymentMethod`, `status`
   - Backend: `first_name`, `last_name`, `phone`, `email`, `status`
   - **ACCIÓN**: Agregar campos `plan_slug`, `preferred_payment_method`

6. **EconomicProfileItem**
   - Campos: `clientId`, `type`, `category`, `source`, `monthlyAmount`, `active`
   - **ACCIÓN**: Crear tabla + modelo + controlador

7. **User** (Parcial)
   - Frontend: `id`, `name`, `username`, `email`, `roleId`, `active`
   - Backend: solo `name`, `email`, `password`
   - **ACCIÓN**: Agregar `username`, `role_id`, `active` a tabla users

8. **SiteConfig**
   - Campos: `gymName`, `slogan`, `aboutText`, `phone`, `whatsapp`, `instagram`, `primaryColor`, `heroImageUrl`
   - **ACCIÓN**: Crear tabla settings/configuraciones

9. **CashMovement**
   - Frontend: `type`, `amount`, `category`, `description`, `reference`
   - Backend (CashTransaction): `type`, `amount`, `concept`, `description`, `payment_method`
   - **ACCIÓN**: Adaptar modelo existente

10. **InventoryProduct**
    - Frontend: `name`, `sku`, `unitPrice`, `stock`, `active`
    - Backend (InventoryItem): Similar pero falta unificar
    - **ACCIÓN**: Agregar campos faltantes

11. **InventoryMovement**
    - Frontend: `productId`, `type`, `quantity`, `reference`, `notes`
    - **ACCIÓN**: Crear tabla + modelo

---

## 🎯 PLAN DE IMPLEMENTACIÓN

### FASE 1: ACTUALIZAR MIGRACIONES Y MODELOS EXISTENTES

**1.1 Actualizar tabla `users`**
```sql
- Agregar: username (unique)
- Agregar: role_id (foreign key)
- Agregar: active (boolean)
```

**1.2 Actualizar tabla `leads`**
```sql
- Separar name en: first_name, last_name
- Agregar: plan_slug
- Agregar: preferred_payment_method
```

**1.3 Actualizar tabla `memberships`**
```sql
- Renombrar a `client_memberships`
- Agregar relación con `membership_plans`
```

**1.4 Actualizar tabla `clients`**
```sql
- ✓ Ya tiene los campos necesarios
- Asegurar que qr_code se genera automáticamente
```

### FASE 2: CREAR NUEVAS TABLAS

**2.1 Tabla `membership_plans`**
```php
- id
- name
- slug (unique)
- price (decimal)
- duration_days (integer)
- description (text)
- features (json)
- published (boolean)
- timestamps
```

**2.2 Tabla `economic_profile_items`**
```php
- id
- client_id (foreign)
- type (enum: income/expense)
- category (string)
- source (string nullable)
- monthly_amount (decimal)
- active (boolean)
- timestamps
```

**2.3 Tabla `site_settings`**
```php
- id
- key (unique)
- value (text)
- timestamps
```

**2.4 Tabla `inventory_movements`**
```php
- id
- inventory_item_id (foreign)
- type (enum: in/out)
- quantity (integer)
- reference (string nullable)
- notes (text nullable)
- timestamps
```

### FASE 3: CREAR CONTROLADORES FALTANTES

```bash
php artisan make:controller Api/MembershipPlanController --api
php artisan make:controller Api/EconomicProfileController --api
php artisan make:controller Api/SiteSettingsController
php artisan make:controller Api/UserController --api
php artisan make:controller Api/InventoryMovementController --api
```

### FASE 4: CREAR API RESOURCES

**Para transformar respuestas a formato frontend:**
```bash
php artisan make:resource ClientResource
php artisan make:resource MembershipResource
php artisan make:resource PaymentResource
php artisan make:resource AccessLogResource
php artisan make:resource LeadResource
php artisan make:resource MembershipPlanResource
```

### FASE 5: ACTUALIZAR RUTAS API

**Agregar rutas faltantes en `routes/api.php`:**
```php
Route::apiResource('membership-plans', MembershipPlanController::class);
Route::apiResource('economic-profiles', EconomicProfileController::class);
Route::apiResource('users', UserController::class);
Route::apiResource('inventory-movements', InventoryMovementController::class);

Route::get('settings', [SiteSettingsController::class, 'index']);
Route::put('settings', [SiteSettingsController::class, 'update']);

// Rutas especiales
Route::post('access/verify-qr', [AccessLogController::class, 'verifyQR']);
Route::post('access/verify-fingerprint', [AccessLogController::class, 'verifyFingerprint']);
```

### FASE 6: CONFIGURACIÓN FRONTEND

**6.1 Crear archivo de configuración API**
```typescript
// src/config/api.ts
export const API_CONFIG = {
  baseURL: 'http://localhost:8000/api',
  timeout: 10000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  }
}
```

**6.2 Actualizar servicios para usar API REST**
- Reemplazar `storage.get/set` con `fetch/axios`
- Implementar manejo de tokens Bearer
- Agregar interceptores para autenticación
- Manejo de errores centralizado

---

## 📋 MAPEO DE CAMPOS FRONTEND → BACKEND

### Cliente
```
Frontend          →  Backend
---------------------------------
id                →  id
name              →  first_name + ' ' + last_name
phone             →  phone
email             →  email
status            →  status
membershipEnd     →  (calcular desde memberships)
profilePhoto      →  photo_url
createdAt         →  created_at
```

### Payment
```
Frontend          →  Backend
---------------------------------
id                →  id
clientId          →  client_id
planId            →  membership_id
amount            →  amount
method            →  payment_method
status            →  status
createdAt         →  created_at
```

### AccessLog
```
Frontend          →  Backend
---------------------------------
id                →  id
clientId          →  client_id
method            →  (QR/FINGERPRINT)
result            →  status (ALLOWED/DENIED)
createdAt         →  access_time
```

---

## 🔄 TRANSFORMACIONES NECESARIAS

### 1. En API Resources (Backend)
```php
// ClientResource.php
public function toArray($request)
{
    return [
        'id' => $this->id,
        'name' => $this->first_name . ' ' . $this->last_name,
        'phone' => $this->phone,
        'email' => $this->email,
        'status' => $this->status,
        'profilePhoto' => $this->photo_url,
        'membershipEnd' => $this->activeMembership?->end_date,
        'createdAt' => $this->created_at->toISOString(),
    ];
}
```

### 2. En Request Validation (Backend)
```php
// Al crear cliente desde frontend
$validated = $request->validate([
    'name' => 'required|string',
]);

// Separar nombre
$nameParts = explode(' ', $validated['name'], 2);
$data['first_name'] = $nameParts[0];
$data['last_name'] = $nameParts[1] ?? '';
```

### 3. En Servicios Frontend
```typescript
// Antes (localStorage)
getAll: (): Client[] => {
    return storage.get<Client[]>(STORAGE_KEYS.CLIENTS) || [];
}

// Después (API)
getAll: async (): Promise<Client[]> => {
    const response = await fetch(`${API_CONFIG.baseURL}/clients`, {
        headers: {
            ...API_CONFIG.headers,
            'Authorization': `Bearer ${getToken()}`
        }
    });
    return response.json();
}
```

---

## ⚠️ CONSIDERACIONES IMPORTANTES

1. **Autenticación**: El frontend espera un token en `AuthState.token`
2. **Paginación**: Backend usa Laravel pagination, frontend espera array
3. **Fechas**: Frontend usa ISO strings, Laravel usa Carbon
4. **IDs**: Frontend usa strings como `CLT-001`, backend usa integers
5. **Status/Enum**: Mantener consistencia en valores (ACTIVE vs active)

---

## 📝 ORDEN DE EJECUCIÓN

1. ✅ Actualizar migraciones existentes
2. ✅ Crear nuevas migraciones
3. ✅ Ejecutar `php artisan migrate:fresh --seed`
4. ✅ Crear/actualizar modelos
5. ✅ Crear controladores faltantes
6. ✅ Crear API Resources
7. ✅ Actualizar rutas
8. ✅ Crear seeder con datos de prueba
9. ✅ Probar endpoints con Postman
10. ✅ Actualizar servicios frontend
11. ✅ Probar integración completa

---

## 🚀 PRIORIDAD DE IMPLEMENTACIÓN

### CRÍTICO (Implementar YA)
- ✅ Auth (login/register/logout)
- ✅ Clients CRUD
- ⬜ MembershipPlans CRUD
- ⬜ Payments
- ⬜ Access verification

### IMPORTANTE (Siguiente)
- ⬜ Leads
- ⬜ Users & Roles
- ⬜ Dashboard stats

### SECUNDARIO
- ⬜ Blog
- ⬜ Inventory
- ⬜ Cash movements
- ⬜ Economic profiles
- ⬜ Site settings
