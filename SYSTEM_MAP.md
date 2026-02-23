# 🗺️ SYSTEM MAP — IronGym
> Generado por análisis de código fuente  
> Fecha: 2026-02-21  
> Versión Backend: Laravel 12.50.0 | Versión Frontend: React 19 / Vite 7

---

## 1. STACK TECNOLÓGICO

### Backend
| Item | Valor |
|------|-------|
| Framework | **Laravel 12.50.0** |
| PHP | ≥ 8.2 (requerido por Laravel 12) |
| Base de datos | **MySQL** — DB: `gym_flow`, Host: `127.0.0.1:3306` |
| Queue driver | **`database`** (cola en BD — no Redis) |
| Cache store | `database` |
| Session driver | `database` |
| Auth | Laravel Sanctum (tokens por API) |
| Mail | `log` en local (⚠️ NO configurado para producción) |
| Storage | `public` (local, sin S3) |
| PDF | `barryvdh/laravel-dompdf` |
| Biometría | Integración nativa de hardware (fingerprint_template en BD) |

**Dependencias clave de composer:**
- `laravel/sanctum ^4.0` — Autenticación API
- `barryvdh/laravel-dompdf ^3.1` — Generación de PDFs
- `spatie/laravel-permission` — {VERIFICAR: no detectado en composer pero hay tabla roles/permissions propia}

### Frontend
| Item | Valor |
|------|-------|
| Framework | **Vite 7.2.6** + React 19 |
| Lenguaje | TypeScript 5.7 |
| Routing | React Router DOM 7.13 |
| UI Library | **Shadcn UI** + Radix UI (completo) |
| Animaciones | Framer Motion 12 |
| Iconos | Phosphor Icons 2.1 + Lucide React 0.484 |
| Forms | React Hook Form 7.54 + Zod 3.25 |
| Server state | **@tanstack/react-query 5.83** |
| Notificaciones | **Sonner 2.0** |
| Gráficos | Recharts 2.15 + D3 7.9 |
| Estilos | Tailwind CSS 4.1 |
| Fechas | date-fns 3.6 |
| QR | qrcode.react 4.2 |
| Theme | next-themes 0.4 |

### Servicios externos
| Servicio | Uso | Configuración |
|----------|-----|---------------|
| **Recurrente API** | Pagos, suscripciones, checkout hosteado | `RECURRENTE_BASE_URL=https://app.recurrente.com/api` — **ENV: PRODUCCIÓN** |
| **Stripe** | Demo/legacy (ruta `/p/pago-demo`) | Solo frontend — {VERIFICAR si tiene backend} |
| **Email** | Notificaciones de pago adelantado | `MAIL_MAILER=log` ⚠️ Solo loguea, no envía |
| **Storage** | Imágenes de productos, fotos de staff | `public` disk — local |
| **Fingerprint HW** | Registro y validación biométrica | Hardware externo, template binario en BD |

---

## 2. ESTRUCTURA DE BASE DE DATOS

### Tablas detectadas (46 migraciones)

#### `users`
| Campo | Tipo | Nullable | Descripción |
|-------|------|----------|-------------|
| id | bigint | No | PK |
| name | string | No | Nombre completo |
| username | string | Sí | Username único |
| email | string | No | Unique |
| password | string | No | Hash bcrypt |
| role_id | FK→roles | Sí | Rol del usuario |
| active | boolean | No | Default: true |
| fingerprint_data | text | Sí | Template biométrico staff |
| photo_url | string | Sí | Avatar |
| position | string | Sí | Cargo en el gym |
| department | string | Sí | Departamento |
| timestamps, softDeletes | — | — | — |

Relaciones:
- belongsTo: `roles`
- hasMany: `user_photos`, `user_documents`

---

#### `clients`
| Campo | Tipo | Nullable | Descripción |
|-------|------|----------|-------------|
| id | bigint | No | PK |
| first_name | string | No | — |
| last_name | string | No | — |
| email | string | No | Unique |
| phone | string | Sí | Principal |
| phone_secondary | string | Sí | WhatsApp |
| dni | string | Sí | Unique |
| birth_date | date | Sí | — |
| gender | enum M/F/other | Sí | — |
| address | text | Sí | — |
| photo_url | string | Sí | — |
| qr_code | string | No | Unique — para acceso |
| status | enum active/inactive/suspended | No | Default: active |
| weight_kg | decimal(5,2) | Sí | — |
| height_cm | decimal(5,2) | Sí | — |
| medical_conditions | text | Sí | — |
| referral_source | string | Sí | Cómo conoció el gym |
| fingerprint_template | binary | Sí | Template biométrico |
| fingerprint_device_id | string | Sí | Modelo del sensor |
| fingerprint_quality | tinyint | Sí | Calidad 0-100 |
| fingerprint_registered_at | timestamp | Sí | — |
| fiscal_address | string | Sí | Para facturación |
| fiscal_name | string | Sí | Razón social |
| nit | string | Sí | NIT fiscal |
| **recurrente_user_id** | string | Sí | ID en Recurrente |
| **recurrente_payment_method_id** | string | Sí | Token de tarjeta guardada |
| notes | text | Sí | — |
| timestamps, softDeletes | — | — | — |

Relaciones:
- hasMany: `memberships`, `payments`, `access_logs`, `payment_installments`, `recurrente_payments`, `recurrente_subscriptions`

---

#### `memberships`
| Campo | Tipo | Nullable | Descripción |
|-------|------|----------|-------------|
| id | bigint | No | PK |
| client_id | FK→clients | No | — |
| plan_id | FK→membership_plans | Sí | — |
| name | string | No | Nombre del plan al momento |
| price | decimal(10,2) | No | Precio base |
| duration_days | int | No | — |
| start_date | date | No | — |
| end_date | date | No | — |
| status | enum active/expired/cancelled | No | Default: active |
| auto_renew | boolean | No | Default: false |
| total_amount | decimal(10,2) | Sí | Monto total si cuotas |
| payment_type | enum single/installments | No | Default: single |
| num_installments | smallint | No | Default: 1 |
| amount_paid | decimal(10,2) | No | Default: 0 |
| payment_status | enum paid/partial/pending/overdue | No | Default: pending |
| **recurrente_status** | string | Sí | active\|scheduled\|cancelled |
| **recurrente_rescheduled_at** | timestamp | Sí | Cuándo se reprogramó |
| **payment_method_log** | json | Sí | Historial de métodos de pago |
| **previous_plan_id** | bigint | Sí | Plan anterior en upgrade |
| **credito_restante** | decimal(10,2) | No | Crédito de upgrade |
| **upgraded_at** | timestamp | Sí | Fecha de cambio de plan |
| timestamps, softDeletes | — | — | — |

Relaciones:
- belongsTo: `clients`, `membership_plans`
- hasMany: `payment_installments`, `recurrente_payments`, `receipts`

---

#### `payment_installments`
| Campo | Tipo | Nullable | Descripción |
|-------|------|----------|-------------|
| id | bigint | No | PK |
| membership_id | FK→memberships | No | — |
| client_id | FK→clients | No | — |
| installment_number | smallint | No | Número de cuota |
| amount | decimal(10,2) | No | Monto esperado |
| amount_paid | decimal(10,2) | No | Default: 0 |
| due_date | date | No | Fecha de vencimiento |
| status | enum pending/partial/paid/overdue | No | — |
| payment_id | FK→payments | Sí | — |
| paid_at | timestamp | Sí | — |
| notes | text | Sí | — |
| **payment_method** | string | Sí | efectivo\|transferencia\|tarjeta\|recurrente\|combinado |
| **transfer_reference** | string | Sí | Número de referencia |
| **recurrente_payment_id** | string | Sí | ID en Recurrente si fue cobrada automáticamente |
| **is_advance_payment** | boolean | No | Default: false |
| **registered_by** | FK→users | Sí | Admin que registró |
| **precio_pagado** | decimal(10,2) | Sí | Snapshot del precio al pagar (FIX 3.2) |
| **descuento_aplicado** | decimal(10,2) | No | Default: 0 |
| **descuento_motivo** | string | Sí | — |
| **descuento_autorizado_por** | FK→users | Sí | — |
| timestamps, softDeletes | — | — | — |

Índices: `[membership_id, installment_number]`, `[client_id, status]`, `due_date`

---

#### `recurrente_payments`
| Campo | Tipo | Nullable | Descripción |
|-------|------|----------|-------------|
| id | bigint | No | PK |
| client_id | FK→clients | Sí | — |
| membership_id | FK→memberships | Sí | — |
| membership_plan_id | FK→membership_plans | Sí | — |
| recurrente_payment_id | string | Sí | ID del pago en Recurrente |
| recurrente_subscription_id | string | Sí | ID de suscripción |
| recurrente_checkout_id | string | Sí | ID del checkout |
| type | string | No | one_time\|subscription\|checkout |
| amount_in_cents | int unsigned | No | Monto en centavos |
| currency | string(3) | No | Default: GTQ |
| status | string | No | pending\|succeeded\|failed\|refunded |
| concept | string | Sí | Descripción del cobro |
| metadata | json | Sí | Payload completo del webhook |
| paid_at | timestamp | Sí | — |
| timestamps | — | — | — |

---

#### `recurrente_subscriptions`
| Campo | Tipo | Nullable | Descripción |
|-------|------|----------|-------------|
| id | bigint | No | PK |
| client_id | FK→clients | No | — |
| membership_plan_id | FK→membership_plans | Sí | — |
| recurrente_subscription_id | string | No | Unique |
| **idempotency_key** | string | Sí | Unique — UUID para evitar duplicados en timeout |
| **creation_status** | string | No | created\|pending_confirmation\|failed |
| recurrente_product_id | string | Sí | — |
| status | string | No | active\|cancelled\|past_due\|paused |
| current_period_start | timestamp | Sí | — |
| current_period_end | timestamp | Sí | — |
| metadata | json | Sí | — |
| timestamps | — | — | — |

---

#### `membership_plans`
| Campo | Tipo | Nullable | Descripción |
|-------|------|----------|-------------|
| id | bigint | No | PK |
| **recurrente_product_id** | string | Sí | ID del producto en Recurrente |
| name | string | No | — |
| description | text | Sí | — |
| price | decimal(10,2) | No | — |
| duration_days | int | No | — |
| features | json | Sí | Lista de características |
| is_active | boolean | No | Default: true |
| timestamps | — | — | — |

---

#### `payments` (pagos manuales legacy)
| Campo | Tipo | Nullable | Descripción |
|-------|------|----------|-------------|
| id | bigint | No | PK |
| client_id | FK→clients | No | — |
| membership_id | FK→memberships | Sí | — |
| amount | decimal(10,2) | No | — |
| payment_method | enum cash/card/transfer/stripe | No | ⚠️ No incluye 'recurrente' |
| status | enum pending/completed/failed/refunded | No | Default: pending |
| transaction_id | string | Sí | — |
| notes | text | Sí | — |
| paid_at | timestamp | Sí | — |
| timestamps, softDeletes | — | — | — |

---

#### `receipts`
| Campo | Tipo | Nullable | Descripción |
|-------|------|----------|-------------|
| id | bigint | No | PK |
| client_id | FK→clients | Sí | — |
| payment_id | FK→payments | Sí | — |
| venta_id | FK→ventas | Sí | — |
| membership_id | FK→memberships | Sí | — |
| receipt_number | string | No | Unique |
| type | enum receipt/invoice/proforma | No | Default: receipt |
| payment_type | enum subscription/individual_payment/course/product | No | — |
| subtotal, tax, discount, total | decimal(15,2) | No | — |
| is_invoiced | boolean | No | Default: false |
| invoice_number | string | Sí | Unique |
| email_sent | boolean | No | Default: false |
| status | enum draft/pending/paid/cancelled | No | Default: draft |
| timestamps, softDeletes | — | — | — |

---

#### `access_logs`
| Campo | Tipo | Nullable | Descripción |
|-------|------|----------|-------------|
| id | bigint | No | PK |
| client_id | FK→clients | No | — |
| qr_code | string | Sí | Código QR escaneado |
| fingerprint_id | string | Sí | — |
| access_type | enum entry/exit | No | — |
| verification_method | enum qr/fingerprint/manual | No | Default: qr |
| timestamps | — | — | — |

---

#### Módulo Comercial

**`productos`**: id, marca_id, presentacion_id, codigo, nombre, descripcion, precio_compra, precio_venta, stock, stock_minimo, image_url, activo, timestamps

**`ventas`**: id, cliente_venta_id, user_id, subtotal, descuento, impuesto, total, estado (pendiente/completada/cancelada), metodo_pago_id, timestamps

**`venta_detalles`**: id, venta_id, producto_id, cantidad, precio_unitario, descuento, subtotal, timestamps

**`pago_ventas`**: id, venta_id, metodo_pago_id, monto, referencia, timestamps

**`movimiento_inventarios`**: id, producto_id, tipo (entrada/salida/ajuste), cantidad, motivo, user_id, timestamps

**`marcas`**, **`presentaciones`**, **`metodo_pagos`**: tablas de catálogo

**`cliente_ventas`**: cliente para ventas (puede ser diferente al cliente de membresía)

---

#### Tablas adicionales

**`leads`**: Prospectos con plan_interested, source, status (new/contacted/converted/lost), notes

**`blog_posts`**: title, slug, content, status (draft/published), featured_image, timestamps

**`site_settings`**: JSON key-value para configuración del sitio público (hero, colores, secciones, etc.)

**`cash_transactions`**: Caja diaria — type (income/expense), amount, concept, category, user_id

**`inventory_items`** (legacy): items de inventario simple antes del módulo comercial

---

#### Tablas de Auditoría (nuevas)

**`advance_payment_logs`**: Log completo de cada pago adelantado registrado

**`subscription_audit_log`**: Quién canceló/reprogramó/reactivó cada suscripción

**`recurrente_conciliation_alerts`**: Alertas de cobros duplicados o discrepancias detectadas por el job diario

**`notification_log`**: Registro de envíos de email (éxito/fallo) — {VERIFICAR: tabla no migrada aún}

---

### Diagrama de relaciones principales

```
users 1──N payments (registered_by)
users 1──1 roles N──N permissions

clients N──1 membership_plans (a través de memberships)
clients 1──N memberships
clients 1──N payment_installments
clients 1──N payments
clients 1──N access_logs
clients 1──N recurrente_payments
clients 1──N recurrente_subscriptions

memberships 1──N payment_installments
memberships N──1 membership_plans
membership_plans 1──1 recurrente_product_id (en Recurrente)

ventas 1──N venta_detalles
ventas 1──N pago_ventas
venta_detalles N──1 productos
productos N──1 marcas
productos N──1 presentaciones
```

---

## 3. MÓDULOS DEL SISTEMA

### Módulo: Dashboard
- **Ruta frontend:** `/admin/dashboard`
- **Archivo frontend:** `src/pages/admin/Dashboard.tsx`
- **Controller backend:** Sin controller específico (usa múltiples)
- **Rutas API:** Varias (memberships, clients, payments summary)
- **Permiso requerido:** `DASHBOARD_VIEW`
- **Estado:** ⚠️ Parcial — Los gráficos de Recharts están pero dependen de endpoints de resumen no documentados

---

### Módulo: Clientes
- **Ruta frontend:** `/admin/clients`, `/admin/clients/:id`
- **Archivos frontend:** `ClientsList.tsx`, `ClientDetail.tsx`, `ClientCreateWizardModal.tsx`, `ClientEditModal.tsx`
- **Controller backend:** `ClientController`
- **Servicio frontend:** `clients.service.ts`
- **Rutas API:**
  - `GET /api/clients`
  - `POST /api/clients`
  - `GET /api/clients/{id}`
  - `PUT /api/clients/{id}`
  - `DELETE /api/clients/{id}`
  - `GET /api/clients/{id}/memberships`
  - `GET /api/clients/{id}/access-logs`
- **Permiso requerido:** `CLIENTS_VIEW`, `CLIENTS_MANAGE`
- **Estado:** ✅ Completo

---

### Módulo: Membresías
- **Ruta frontend:** `/admin/memberships`, `/admin/clients/:id` (tab membresías)
- **Archivos frontend:** `Memberships.tsx`, sección en `ClientDetail.tsx`
- **Controller backend:** `MembershipController`
- **Servicio frontend:** `memberships.service.ts`
- **Rutas API:**
  - `GET /api/memberships`
  - `POST /api/memberships`
  - `PUT /api/memberships/{id}`
  - `DELETE /api/memberships/{id}`
  - `GET /api/membership-plans`
  - `POST /api/membership-plans`
- **Estado:** ✅ Completo — Incluye cuotas (installments)

---

### Módulo: Pagos Manuales
- **Ruta frontend:** `/admin/payments`
- **Archivos frontend:** `Payments.tsx`
- **Controller backend:** `PaymentController`, `PaymentInstallmentController`
- **Servicio frontend:** `payments.service.ts`
- **Rutas API:**
  - `GET /api/payments`
  - `POST /api/payments`
  - `GET /api/payment-installments`
- **Permiso requerido:** `PAYMENTS_VIEW`
- **Estado:** ⚠️ Parcial — Tabla `payments` tiene enum `payment_method` que no incluye `recurrente`

---

### Módulo: Pagos Adelantados (NUEVO)
- **Ruta frontend:** Sin ruta dedicada — se usa desde `ClientDetail.tsx`
- **Archivos frontend:** `BotonPagoRecurrente.tsx` (componente reutilizable)
- **Controller backend:** `PagoAdelantoController`
- **Servicio backend:** `PagoAdelantoService`, `PagoAdelantoReversionService`
- **Rutas API:**
  - `POST /api/pagos/adelanto` — Registrar pago adelantado
  - `GET /api/pagos/adelanto/client/{id}` — Ver estado de cuotas
  - `POST /api/pagos/adelanto/reactivar` — Reactivar suscripción
  - `POST /api/pagos/adelanto/{logId}/revertir` — Anular pago [Fix 4.1]
  - `GET /api/pagos/adelanto/{logId}/reembolso` — Calcular reembolso [Fix 4.2]
  - `POST /api/pagos/adelanto/upgrade-plan` — Cambio de plan con crédito [Fix 4.3]
  - `GET /api/pagos/alertas` — Alertas de conciliación [Fix 5.2]
  - `PATCH /api/pagos/alertas/{id}` — Resolver alerta
- **Estado:** ✅ Backend completo | ⚠️ Frontend pendiente de integrar en ClientDetail

---

### Módulo: Integración Recurrente
- **Ruta frontend:** Sin ruta dedicada — componente `BotonPagoRecurrente.tsx`
- **Hook:** `useRecurrentePago.ts`
- **Servicio frontend:** `recurrente.service.ts`
- **Controller backend:** `RecurrenteController`
- **Webhook:** `RecurrenteWebhookController` — `POST /webhooks/recurrente`
- **Rutas API:**
  - `POST /api/pagos/checkout` — Crear checkout hosteado
  - `POST /api/pagos/cobrar` — Cobrar con tarjeta guardada
  - `GET /api/pagos/historial/{clientId}` — Historial de pagos
  - `GET /api/pagos/estado/{clientId}` — Estado del cliente en Recurrente
  - `POST /api/suscripciones/crear` — Crear suscripción
  - `DELETE /api/suscripciones/{id}` — Cancelar suscripción
- **Estado:** ✅ Completo con QA fixes

---

### Módulo: Control de Acceso (QR)
- **Ruta frontend:** `/admin/access`, `/qr/:clientId`
- **Archivos frontend:** `AccessControl.tsx`, `AccessControlDual.tsx`, `QrPass.tsx`
- **Controller backend:** `AccessLogController`
- **Servicio frontend:** `access.service.ts`
- **Rutas API:**
  - `POST /api/access-logs` — Registrar acceso
  - `GET /api/access-logs` — Listar accesos
- **Permiso requerido:** `ACCESS_VIEW`
- **Estado:** ✅ Completo — QR + huella digital

---

### Módulo: Biometría / Huellas
- **Ruta frontend:** `/admin/fingerprints`, `/admin/identifier`
- **Archivos frontend:** `Fingerprints.tsx`, `Identifier.tsx`, `FingerprintCaptureModal.tsx`, `FingerprintRegistration.tsx`
- **Controller backend:** `FingerprintStatusController`
- **Rutas API:** `GET /api/fingerprint-status`
- **Permiso requerido:** `ACCESS_VIEW`
- **Estado:** ⚠️ Parcial — Template se guarda pero validación remota {VERIFICAR endpoint de validación}

---

### Módulo: Recibos
- **Ruta frontend:** `/admin/receipts`
- **Archivos frontend:** `Receipts.tsx`, `ClientReceipts.tsx`, `ReceiptCard.tsx`
- **Controller backend:** `ReceiptController`
- **Servicio frontend:** `receipts.service.ts`
- **Rutas API:**
  - `GET /api/receipts`
  - `POST /api/receipts`
  - `GET /api/receipts/{id}/pdf`
- **Permiso requerido:** `PAYMENTS_VIEW`
- **Estado:** ⚠️ Parcial — PDF generado con DomPDF pero sin template HTML validado en producción

---

### Módulo Comercial
- **Rutas frontend:** `/admin/productos`, `/admin/inventario`, `/admin/ventas`, `/admin/ventas/pos`, `/admin/catalogos`
- **Archivos frontend:** `Products.tsx`, `Inventory.tsx`, `Sales.tsx`, `SalesHistory.tsx`, `Catalogs.tsx`, `ProductForm.tsx`
- **Controllers backend:** `ProductController`, `InventoryController`, `SaleController`, `MarcaController`, `PresentacionController`, `CommercialLookupController`
- **Servicio frontend:** `commercial.service.ts`
- **Permisos:** `PRODUCTS_VIEW`, `INVENTORY_VIEW`, `SALES_VIEW`
- **Estado:** ✅ Completo — POS con multi-método de pago

---

### Módulo: Reportes
- **Ruta frontend:** `/admin/reports`
- **Archivos frontend:** `Reports.tsx`
- **Controllers backend:** `ReportController`, `ReportExportController`
- **Rutas API principales:**
  - `GET /api/reportes/inventario-disponible/pdf`
  - `GET /api/reportes/movimientos-inventario/pdf`
  - `GET /api/reportes/catalogo-productos/pdf`
  - `GET /api/reportes/valoracion-inventario/pdf`
  - `GET /api/reportes/rotacion-inventario/pdf`
  - `GET /api/reportes/reporte-semestral/pdf`
- **Estado:** ⚠️ Parcial — Solo reportes de inventario. Sin reportes de membresías/pagos.

---

### Módulo: Sitio Público
- **Rutas frontend:** `/p`, `/p/planes`, `/p/planes/:slug`, `/p/blog`, `/p/suscripcion`, `/p/contacto`
- **Archivos frontend:** `PublicHome.tsx`, `PublicPlans.tsx`, `PublicPlanDetail.tsx`, `PublicBlog.tsx`, `PublicSubscribe.tsx`
- **Controllers backend:** `SiteSettingController`, `BlogPostController`, `MembershipPlanController`
- **Estado:** ✅ Completo — Página pública con planes, blog y formulario de contacto

---

### Módulo: Staff
- **Ruta frontend:** `/admin/staff`, `/admin/staff/new`, `/admin/staff/edit/:id`
- **Archivos frontend:** `Staff.tsx`, `StaffForm.tsx`, `StaffFormModal.tsx`
- **Controller backend:** `UserController`
- **Permiso:** `USERS_VIEW`
- **Estado:** ✅ Completo — Incluye upload de documentos y fotos

---

### Módulo: Roles y Permisos
- **Ruta frontend:** `/admin/roles`
- **Archivos frontend:** `Roles.tsx`
- **Controllers backend:** `RoleController`
- **Permiso:** `ROLES_MANAGE`
- **Estado:** ⚠️ Parcial — Sistema de roles propio (no usa Spatie), solo 5 permisos definidos en seeder

---

## 4. FLUJOS DE NEGOCIO DETECTADOS

### Flujo A: Alta de nuevo cliente con pago inicial
```
PASO 1: Admin abre wizard de creación
  → Frontend: ClientCreateWizardModal.tsx
  → Formulario multi-paso: datos personales → plan → pago

PASO 2: Seleccionar plan y configurar cuotas
  → API: GET /api/membership-plans
  → Frontend: Muestra precio, permite elegir single/installments

PASO 3: Crear cliente en BD
  → API: POST /api/clients
  → Backend: ClientController@store
  → BD: INSERT clients, genera qr_code único

PASO 4: Asignar membresía
  → API: POST /api/memberships
  → Backend: MembershipController@store
  → BD: INSERT memberships + payment_installments (si cuotas)

PASO 5: Si pago con tarjeta → Recurrente Checkout
  → Frontend: BotonPagoRecurrente.tsx
  → API: POST /api/pagos/checkout
  → Backend: RecurrenteController@createCheckout → RecurrenteService::createCheckout
  → Redirige a Recurrente hosted page

PASO 6: Webhook confirma pago
  → Recurrente: POST /webhooks/recurrente {event: "checkout.succeeded"}
  → Backend: RecurrenteWebhookController@handleCheckoutSucceeded
  → BD: clients.recurrente_user_id + recurrente_payment_method_id actualizados
  → BD: membership.status = active
  → BD: INSERT receipts

Estado actual: ✅ Funciona
Casos edge cubiertos: Idempotencia, doble webhook, webhook antes de redirect
Casos edge NO cubiertos: Cliente con email ya existente en Recurrente pero nuevo en IronGym
```

---

### Flujo B: Cobro automático mensual (suscripción)
```
PASO 1: Recurrente cobra automáticamente según el schedule del plan
  → Recurrente llama: POST /webhooks/recurrente {event: "subscription.paid"}
  → Backend: RecurrenteWebhookController@handleSubscriptionPaid
  → Verifica idempotencia (lockForUpdate en RecurrentePayment)

PASO 2: Actualizar cuota correspondiente
  → BD: payment_installments.status = paid, recurrente_payment_id = ID
  → BD: recurrente_payments INSERT
  → BD: receipts INSERT (deduplicado)

PASO 3: Renovar membresía si expiró
  → BD: memberships.end_date extendido, status = active

Estado actual: ✅ Funciona con idempotencia
Casos edge cubiertos: Double webhook (lockForUpdate), recibo deduplicado
Casos edge NO cubiertos: Cuota ya pagada en efectivo + Recurrente intenta cobrar
  → Fix 5.2 conciliation job detecta esto, pero no previene en tiempo real
```

---

### Flujo C: Pago adelantado en efectivo (flujo central del sprint actual)
```
PASO 1: Admin entra a ClientDetail, ve cuotas pendientes
  → Frontend: {VERIFICAR — UI de selección de cuotas no confirma implementada}
  → API GET /api/pagos/adelanto/client/{id}

PASO 2: Selecciona cuotas y registra monto + método
  → Validaciones: cuotas ≠ ya pagadas, cuotas ≠ cobradas por Recurrente, descuento con auth

PASO 3: Backend procesa en DB::transaction
  → PagoAdelantoService@procesarPagoAdelantado
  → lockForUpdate en cuotas (previene concurrencia)
  → price snapshot en precio_pagado

PASO 4: Evalúa suscripción activa en Recurrente
  → FIX 2.1: GET a Recurrente para verificar estado real
  → CASO A (todas pagadas): DELETE /subscriptions/{id}
  → CASO B (parcial): DELETE vieja + POST nueva con billing_start_date
  → idempotency_key guardado ANTES del request (FIX 2.3)

PASO 5: Notificación y auditoría
  → NotificarPagoAdelantado job (cola database, 3 reintentos)
  → subscription_audit_log INSERT
  → advance_payment_logs INSERT

Estado actual: ✅ Backend completo | ⚠️ Frontend pendiente
Casos edge cubiertos: 1.1, 1.2, 2.1, 2.2, 2.3, 2.4, 3.1, 3.2, 3.3, 3.4
```

---

### Flujo D: Venta en POS
```
PASO 1: Cajero abre POS (Sales.tsx)
  → Busca cliente o crea cliente_venta anónimo
  → Escáner de código de barras o búsqueda de producto

PASO 2: Agregar items al carrito
  → lockForUpdate en Producto.stock durante la venta (SaleController)

PASO 3: Seleccionar método(s) de pago
  → Soporta multi-pago: efectivo + tarjeta + transferencia
  → BD: pago_ventas INSERT por cada método

PASO 4: Confirmar venta
  → BD: ventas INSERT + venta_detalles + movimiento_inventarios (salida)
  → BD: productos.stock decrement
  → Genera recibo automáticamente

Estado actual: ✅ Completo
Casos edge cubiertos: Stock lock, multi-pago, inventario actualizado
```

---

## 5. INTEGRACIÓN CON RECURRENTE

### Endpoints de Recurrente utilizados

| Endpoint | Método | Dónde se usa | Estado |
|----------|--------|-------------|--------|
| `/users` | POST | `RecurrenteService::createUser()` | ✅ |
| `/users/{id}` | GET | `RecurrenteService::getUser()` — Fix 2.4 | ✅ |
| `/users/{id}/payment_methods` | GET | `RecurrenteService::getPaymentMethods()` | ✅ |
| `/products` | POST | `RecurrenteService::createProduct()` | ✅ |
| `/products/{id}` | GET | `RecurrenteService::getProduct()` | ✅ |
| `/checkouts` | POST | `RecurrenteService::createCheckout()` | ✅ |
| `/one_time_payments` | POST | `RecurrenteService::createOneTimePayment()` | ✅ |
| `/one_time_payments` | POST | `RecurrenteService::createOneTimePaymentByProduct()` | ✅ |
| `/subscriptions` | POST | `RecurrenteService::createSubscription()` | ✅ |
| `/subscriptions/{id}` | DELETE | `RecurrenteService::cancelSubscription()` | ✅ |
| `/subscriptions/{id}` | GET | `RecurrenteService::getSubscription()` — Fix 2.1 | ✅ |

**ENV:** `RECURRENTE_ENV=production` ⚠️ — Las llaves en `.env` son llaves de producción (`pk_live_`, `sk_live_`)

---

### Flujo de webhooks — `POST /webhooks/recurrente`

| Evento | Handler | Acción | Idempotente |
|--------|---------|--------|-------------|
| `checkout.succeeded` | `handleCheckoutSucceeded` | Activa membresía + guarda payment_method | ✅ lockForUpdate |
| `one_time_payment.succeeded` | `handleOneTimePaymentSucceeded` | Activa membresía + recibo | ✅ |
| `subscription.paid` | `handleSubscriptionPaid` | Renueva membresía + cuota + recibo | ✅ lockForUpdate |
| `subscription.cancelled` | `handleSubscriptionCancelled` | Desactiva membresía local | ✅ |
| `payment.failed` | `handlePaymentFailed` | Período de gracia 5 días | ✅ |
| `*` (default) | `Log::info` | Solo loguea | — |

**Eventos NO manejados** (Recurrente puede emitir estos):
- `subscription.created` — No hay handler
- `subscription.updated` — No hay handler (precio, frecuencia cambiada)
- `subscription.paused` — No hay handler
- `checkout.expired` — No hay handler
- `payment.refunded` — No hay handler ⚠️ Si se hace un reembolso en Recurrente, la BD no se actualiza

---

### Seguridad del Webhook
| Check | Estado | Notas |
|-------|--------|-------|
| Validación de firma | ❌ **NO IMPLEMENTADO** | **CRÍTICO** — Cualquiera puede enviar eventos falsos |
| Whitelist de IPs | ❌ No | Depende de infraestructura |
| HTTPS forzado | {VERIFICAR} | Depende de servidor |
| Rate limiting | No específico | Solo el global de Laravel |

---

### Manejo de errores en RecurrenteService
| Escenario | Manejo actual |
|-----------|--------------|
| Timeout (>30s) | ✅ Exception clara, retry x3 |
| 401 Unauthorized | ✅ Log crítico con instrucciones |
| 429 Too Many Requests | ✅ Exponential backoff 500ms→1s→2s |
| 404 Not Found | ✅ Detectado en PagoAdelantoService (Fix 2.1, 2.4) |
| 5xx Server Error | ✅ Retry automático |
| Timeout al crear suscripción | ✅ idempotency_key (Fix 2.3) |

---

## 6. SISTEMA DE ROLES Y PERMISOS

### Roles detectados

| Rol | Permisos definidos en seeder | Notas |
|-----|----------------------------|-------|
| `admin` | Todos los permisos | Creado en seed |
| `staff` | DASHBOARD_VIEW, CLIENTS_VIEW | Creado en seed |

### Permisos definidos (5 en seeder)

| Slug | Nombre |
|------|--------|
| `DASHBOARD_VIEW` | Ver Dashboard |
| `CLIENTS_MANAGE` | Gestionar Clientes |
| `USERS_MANAGE` | Gestionar Staff |
| `ROLES_MANAGE` | Gestionar Roles |
| `INVENTORY_VIEW` | Ver Inventario |

**⚠️ Discrepancia crítica:** El `App.tsx` referencia permisos que NO existen en el seeder:

| Permiso en App.tsx | ¿En seeder? |
|-------------------|-------------|
| `CLIENTS_VIEW` | ❌ No — solo `CLIENTS_MANAGE` |
| `MEMBERSHIPS_VIEW` | ❌ No |
| `PAYMENTS_VIEW` | ❌ No |
| `ACCESS_VIEW` | ❌ No |
| `SETTINGS_VIEW` | ❌ No |
| `USERS_VIEW` | ❌ No — solo `USERS_MANAGE` |
| `PRODUCTS_VIEW` | ❌ No |
| `INVENTORY_VIEW` | ✅ Sí |
| `SALES_VIEW` | ❌ No |

**Consecuencia:** Las rutas admin protegidas por `PermissionGuard` con esos permisos están **inaccesibles para rol `staff`** porque los permisos no existen en BD.

---

### Filtros de visibilidad por módulo

| Módulo | Filtro por usuario | Notas |
|--------|------------------|-------|
| Clientes | ❌ Sin filtro | Todos ven todos |
| Membresías | ❌ Sin filtro | Todos ven todas |
| Pagos | ❌ Sin filtro | — |
| Accesos | ❌ Sin filtro | — |
| Ventas | ❌ Sin filtro | — |
| Staff | ❌ Sin filtro | Todos ven todos los empleados |

**Sin filtro de visibilidad en ningún módulo** — Apropiado para un sistema de gestión de gimnasio donde el staff necesita ver todo.

---

## 7. MÉTODOS DE PAGO

### Flujos por método

| Método | Flujo cubierto | Integración | Estado |
|--------|---------------|-------------|--------|
| Efectivo | ✅ | Manual (cajero registra) | ✅ |
| Transferencia | ✅ | Manual + referencia | ✅ |
| Tarjeta con Recurrente (checkout) | ✅ | `POST /checkouts` | ✅ |
| Tarjeta tokenizada (charge) | ✅ | `POST /one_time_payments` | ✅ |
| Suscripción automática | ✅ | Webhook `subscription.paid` | ✅ |
| Combinado (efectivo + tarjeta) | ✅ | `PagoAdelantoService` | ✅ Backend / ⚠️ Frontend |
| Stripe | ⚠️ Solo demo | `PublicStripeDemoCheckout.tsx` | Sin backend real |

### Pago adelantado — resumen de casos

| Caso | Descripción | Recurrente | Estado |
|------|-------------|-----------|--------|
| A | Paga TODAS las cuotas | Cancela suscripción | ✅ |
| B | Paga ALGUNAS cuotas | Cancela + crea nueva desde fecha futura | ✅ |
| C | Pago combinado | Igual que B | ✅ |
| Reversión | Admin anula pago adelantado | Cancela nueva + recrea desde hoy | ✅ |
| Upgrade de plan | Cambia plan con crédito restante | Cancela + crea con nuevo producto | ✅ |
| Reembolso | Calcula monto a devolver | No toca Recurrente | ✅ Cálculo |

---

## 8. JOBS Y QUEUES

### Jobs implementados

| Job / Command | Trigger | Frecuencia | Propósito | Estado |
|--------------|---------|-----------|-----------|--------|
| `recurrente:sync-subscriptions` | Schedule | Diario 03:00 | Reconcilia suscripciones contra API de Recurrente. Detecta cancelaciones sin webhook. | ✅ |
| `recurrente:conciliar` | Schedule | Diario 06:00 | Detecta cobros duplicados, suscripciones en `pending_confirmation`, cuotas sin webhook | ✅ |
| `NotificarPagoAdelantado` | Dispatch en `PagoAdelantoController` | On-demand | Email al cliente con resumen del pago adelantado. 3 reintentos × 60s | ✅ Código / ⚠️ MAIL_MAILER=log |
| `RecurrenteSyncCustomers` | {VERIFICAR trigger} | {VERIFICAR} | Sincroniza clientes con Recurrente | ✅ Existe |
| `RecurrenteSyncProducts` | {VERIFICAR trigger} | {VERIFICAR} | Sincroniza productos/planes con Recurrente | ✅ Existe |

### Queue configuration
- **Driver:** `database` (tabla `jobs`)
- **Worker:** {VERIFICAR — no hay supervisor/horizon en configuración detectada}
- **⚠️ CRÍTICO:** Si `php artisan queue:work` no está corriendo como daemon, los jobs de notificación no se procesan.

### Jobs que deberían existir y no están

| Job sugerido | Razón |
|-------------|-------|
| `ExpireMembershipsJob` | No hay job que marque membresías como 'expired' cuando pasa end_date |
| `SendPaymentReminder` | No hay recordatorio antes del vencimiento de cuotas |
| `PruneOldLogs` | advance_payment_logs y subscription_audit_log crecerán indefinidamente |

---

## 9. GENERACIÓN DE DOCUMENTOS

| Documento | Librería | Formato | Módulos | Estado |
|-----------|----------|---------|---------|--------|
| Recibo de pago | DomPDF | PDF | Payments, Recurrente | ⚠️ Template sin verificar en producción |
| Recibo de venta | DomPDF | PDF | POS Comercial | ⚠️ |
| Reporte inventario disponible | DomPDF | PDF A4 | Inventory | ✅ |
| Reporte movimientos | DomPDF | PDF A4 | Inventory | ✅ |
| Catálogo productos | DomPDF | PDF A4 | Products | ✅ |
| Valoración inventario | DomPDF | PDF A4 | Inventory | ✅ |
| Rotación inventario | DomPDF | PDF A4 | Inventory | ✅ |
| Reporte semestral | DomPDF | PDF A4 | Multiple | ✅ |
| Email pago adelantado | HTML inline | Email | PagoAdelanto | ⚠️ Solo loguea en dev |

---

## 10. ISSUES Y GAPS DETECTADOS

### 🔴 CRÍTICOS (pérdida de dinero o seguridad)

**CRÍTICO 1 — Webhook sin validación de firma**
- Archivo: `app/Http/Controllers/RecurrenteWebhookController.php`
- Línea: 44 (método `handle`)
- Descripción: Cualquier request a `POST /webhooks/recurrente` es procesado sin verificar que viene de Recurrente
- Fix: Implementar validación de `X-Signature` o whitelist de IPs de Recurrente

**CRÍTICO 2 — MAIL_MAILER=log en producción**
- Archivo: `.env` línea 50
- Descripción: Los emails de notificación de pagos adelantados se loguean pero NO se envían. Los jobs de `NotificarPagoAdelantado` se ejecutan pero el correo nunca llega al cliente.
- Fix: Configurar SMTP real (ej: Mailgun, SES, Postfix)

**CRÍTICO 3 — Llave de producción de Recurrente en .env del entorno local**
- Archivo: `.env` líneas 70-71
- Descripción: `pk_live_` y `sk_live_` son llaves de producción. Si se usa en ambiente de desarrollo, los tests harán cargos reales.
- Fix: Usar llaves de sandbox en dev, producción solo en servidor

**CRÍTICO 4 — tabla `notification_log` no migrada**
- Archivo: `app/Jobs/NotificarPagoAdelantado.php` línea ~68
- Descripción: El job intenta `DB::table('notification_log')->insert()` pero esa tabla no existe en ninguna migración
- Fix: Crear migración para `notification_log`

**CRÍTICO 5 — `payments.payment_method` no incluye 'recurrente'**
- Archivo: `database/migrations/2026_02_05_193426_create_payments_table.php` línea 19
- Descripción: Enum solo tiene `cash|card|transfer|stripe`. Los pagos de Recurrente no pueden registrarse aquí.
- Fix: Agregar `recurrente` al enum o usar `recurrente_payments` exclusivamente como fuente de verdad de pagos Recurrente

---

### 🟡 ALTOS (datos inconsistentes)

**ALTO 1 — Permisos en App.tsx no coinciden con seeder**
- Archivo: `src/App.tsx` líneas 77-131
- Descripción: 8 de los 9 permisos usados en `PermissionGuard` no existen en BD
- Fix: Actualizar `RolesAndPermissionsSeeder` con todos los permisos referenciados en frontend

**ALTO 2 — Queue worker no garantizado**
- Descripción: Si `php artisan queue:work` no corre como servicio, las notificaciones de pago no se procesan jamás
- Fix: Configurar Supervisor en producción. Documentar en README.

**ALTO 3 — Sin job para expirar membresías automáticamente**
- Descripción: Membresías pasado `end_date` siguen con `status=active` hasta que alguien las actualice manualmente o el cliente intente acceder
- Fix: Crear `ExpireMembershipsJob` que corra diariamente

**ALTO 4 — `webhook: payment.refunded` sin handler**
- Descripción: Si admin hace reembolso desde panel de Recurrente, la BD local no refleja el cambio
- Fix: Agregar handler `handlePaymentRefunded` en WebhookController

**ALTO 5 — `recurrente_subscriptions` tabla sin relación explícita a `receipts`**
- Descripción: Los recibos generados por webhook de suscripción tienen `membership_id` pero no `recurrente_subscription_id`
- Fix: Agregar columna en `receipts` o en `recurrente_payments`

---

### 🟢 MEDIOS (UX degradada)

**MEDIO 1 — Sin UI para pago adelantado en ClientDetail**
- Descripción: El backend de pago adelantado está completo pero no hay componente frontend en `ClientDetail.tsx`
- Fix: Implementar modal de "Registrar Pago Adelantado" con selector de cuotas

**MEDIO 2 — Sin UI de alertas de conciliación**
- Descripción: La tabla `recurrente_conciliation_alerts` se llena con alertas pero no hay página en admin para verlas
- Fix: Crear `AlertasPago.tsx` o sección en Dashboard

**MEDIO 3 — Dashboard sin datos de Recurrente**
- Descripción: Los gráficos de Dashboard.tsx probablemente no muestran ingresos de Recurrente
- Fix: Incluir `recurrente_payments` en las queries de resumen

**MEDIO 4 — Stripe solo en demo**
- Descripción: `PublicStripeDemoCheckout.tsx` existe pero no tiene backend real
- Fix: Definir si Stripe se mantiene o se elimina completamente en favor de Recurrente

**MEDIO 5 — Sin recordatorio automático de vencimiento de cuotas**
- Descripción: No hay job que notifique a clientes antes de que venza una cuota
- Fix: `SendPaymentReminderJob` que corra 3 días antes del `due_date`

---

### 📋 CASOS DE USO NO CUBIERTOS DETECTADOS

1. **Pausa de membresía** — No hay estado `paused` en membresías ni flujo para pausar/reanudar (vacaciones, lesiones)
2. **Membresía familiar** — No hay relación entre clientes para planes grupales
3. **Clases y horarios** — No hay módulo de scheduling de clases
4. **Control de capacidad** — No hay límite de aforo por clase o por día
5. **Programa de referidos** — `referral_source` existe en BD pero no hay lógica de recompensas
6. **Portal del cliente** — No hay login propio para que clientes vean su historial. Solo `QrPass.tsx` (público).
7. **Reporte de pagos Recurrente** — No hay reporte que consolide `recurrente_payments` con `payment_installments`
8. **Devolución de acceso (QR temporal)** — No hay QR con expiración para visitantes o día de prueba
9. **Notificación push (WebSocket)** — El webhook activa cambios en BD pero el frontend debe hacer polling para verlos

---

## 11. CHECKLIST DE ROBUSTEZ

### Seguridad
- [x] API keys en variables de entorno (no hardcodeadas)
- [ ] **Webhooks validados por firma/origen** — ❌ PENDIENTE CRÍTICO
- [x] Montos calculados en backend (Fix 5.3 — no confiados del frontend cuando hay plan_id)
- [x] Filtros de visibilidad aplicados en queries (no aplicable — sistema interno)
- [x] CSRF configurado (Sanctum + excepciones para `/webhooks/recurrente`)
- [ ] Rate limiting específico para endpoints de pago — ❌ Solo global de Laravel

### Pagos
- [x] `DB::transaction()` en operaciones mixtas BD + API
- [x] Idempotencia en webhooks (lockForUpdate + check de IDs)
- [x] Manejo de timeout en calls a Recurrente (30s + retry)
- [x] Log de cada transacción (advance_payment_logs, subscription_audit_log)
- [x] Conciliación automática implementada (recurrente:conciliar @ 06:00)
- [x] idempotency_key para evitar suscripciones duplicadas en timeout
- [ ] Reembolso automático al cancelar — ❌ Solo cálculo, sin ejecución

### Calidad de código
- [x] Try/catch en todos los calls externos (RecurrenteService)
- [x] Validación de requests en todos los endpoints (Form Requests implícitos)
- [x] Tests implementados para flujos críticos (`PagoAdelantoTest.php`)
- [ ] Sin datos huérfanos en BD — ⚠️ `notification_log` tabla referenciada pero no migrada

---

## 12. PRÓXIMOS PASOS SUGERIDOS

### 🔴 Inmediatos (antes de ir a producción con el nuevo flujo)

1. **Crear migración para `notification_log`** — El job `NotificarPagoAdelantado` fallará en producción sin esta tabla
2. **Implementar validación de firma en webhook** — ANY request puede activar membresías falsas ahora mismo
3. **Configurar SMTP real** — Los emails de pago adelantado no llegan actualmente
4. **Corregir el seeder de permisos** — Agregar los 8 permisos faltantes o el rol `staff` no puede hacer nada

### 🟡 Esta semana

5. **Implementar UI en `ClientDetail.tsx`** para pago adelantado — El backend está listo, falta el frontend
6. **Crear página de alertas de conciliación** en admin — Las alertas del job diario no tienen interfaz
7. **Configurar Supervisor** para queue worker en producción
8. **Agregar handler `payment.refunded`** en WebhookController

### 🟢 Próximas 2 semanas

9. **Crear `ExpireMembershipsJob`** — Membresías no expiran automáticamente
10. **Implementar recordatorio de cuotas** — Notificación 3 días antes del vencimiento
11. **Reporte consolidado de pagos** — Que incluya tanto `payments` como `recurrente_payments`
12. **Usar llaves sandbox de Recurrente** en entorno de desarrollo
13. **Agregar `recurrente` al enum** de `payments.payment_method` o documentar que la fuente de verdad es `recurrente_payments`
14. **Definir futuro de Stripe** — Eliminar o implementar completamente `PublicStripeDemoCheckout`

---

## APÉNDICE — Árbol de archivos clave

```
Backend-IronGym/
├── app/
│   ├── Console/Commands/
│   │   ├── RecurrenteConciliacion.php       ← FIX 5.2 (nuevo)
│   │   ├── RecurrenteSyncSubscriptions.php  ← FIX 3.1/3.2
│   │   ├── RecurrenteSyncCustomers.php
│   │   └── RecurrenteSyncProducts.php
│   ├── Http/Controllers/
│   │   ├── RecurrenteWebhookController.php  ← Todos los webhooks
│   │   └── Api/
│   │       ├── RecurrenteController.php     ← Checkout, cobro, suscripción
│   │       ├── PagoAdelantoController.php   ← Pago adelantado (nuevo)
│   │       ├── ClientController.php
│   │       ├── MembershipController.php
│   │       ├── PaymentController.php
│   │       └── ... (27 controllers total)
│   ├── Jobs/
│   │   └── NotificarPagoAdelantado.php      ← FIX 5.1 (nuevo)
│   └── Services/
│       ├── RecurrenteService.php            ← HTTP client + helpers
│       ├── PagoAdelantoService.php          ← Lógica de pagos adelantados
│       └── PagoAdelantoReversionService.php ← Reversion, reembolso, upgrade
├── database/migrations/ (46 archivos)
├── routes/
│   ├── api.php    ← 193 rutas
│   ├── web.php
│   └── console.php ← Schedules
└── tests/Feature/
    └── PagoAdelantoTest.php ← 6 tests críticos

gym-access-qr-manage/
├── src/
│   ├── App.tsx               ← Router principal (39 rutas)
│   ├── pages/
│   │   ├── admin/            ← 22 páginas admin
│   │   └── public/           ← 8 páginas públicas
│   ├── components/
│   │   ├── BotonPagoRecurrente.tsx   ← Smart payment button
│   │   └── ui/               ← 45+ componentes Shadcn
│   ├── hooks/
│   │   ├── useRecurrentePago.ts
│   │   └── useThemeColors.ts
│   └── services/             ← 19 servicios API
```
