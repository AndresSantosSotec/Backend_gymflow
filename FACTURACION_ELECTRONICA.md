# IMPLEMENTACIÓN DE FACTURACIÓN ELECTRÓNICA

**Documento:** Guía de Integración con Proveedores de Facturación  
**Versión:** 1.0.0  
**Fecha:** 15 de Febrero, 2026

---

## 📋 INTRODUCCIÓN

Este documento describe cómo integrar IronGym con sistemas de facturación electrónica para generar facturas válidas fiscalmente.

---

## ✅ ARQUITECTURA IMPLEMENTADA

### Service Locator Pattern

El sistema usa un patrón de servicio que permite cambiar proveedores sin modificar controladores:

```
ElectronicBillingService
    ├── sendToFacturama()      // Integración Facturama
    ├── sendToSAT()            // Integración SAT (México)
    └── storeLocalInvoice()    // Almacenamiento local
```

---

## 🔑 CONFIGURACIÓN POR PROVEEDOR

### Opción 1: Local (Sin API Externa)

**Descripción:** Almacena facturas en la base de datos local.

**Ventajas:**
- No requiere credenciales
- Funciona sin conexión a internet
- Ideal para desarrollo

**Configuración en `.env`:**
```env
BILLING_PROVIDER=local
```

**Almacenamiento:**
- Los datos se guardan en `receipts.details` como JSON
- Los PDFs en `storage/app/public/facturas/`

---

### Opción 2: Facturama (Recomendado para México)

**Descripción:** Integración con API de Facturama para CFDI.

**Ventajas:**
- Generación automática de CFDI
- Envío a SAT integrado
- Consultas de validez
- Cancelación de facturas

**Obtener Credenciales:**

1. Ir a https://www.facturama.mx/
2. Crear cuenta de pruebas
3. En Dashboard → Configuración → API
4. Copiar:
   - **API Key:** (Usuario/Email)
   - **API Secret:** (Contraseña)

**Configuración en `.env`:**
```env
BILLING_PROVIDER=facturama
BILLING_API_URL=https://apisandbox.facturama.mx/v3
BILLING_API_KEY=user@example.com
BILLING_API_SECRET=password123
```

**Endpoints en ElectronicBillingService:**

```php
private function sendToFacturama(array $invoiceData)
{
    // Endpoint: POST /v3/cfdi
    // Envía datos estructurados a Facturama
    // Retorna folio, UUID y PDF
}
```

**Datos Requeridos:**

```php
[
    'company' => [
        'taxId' => 'RFC12345678ABC',
        'name' => 'IronGym',
        'address' => [...],
    ],
    'client' => [
        'taxId' => 'RFC87654321XYZ',
        'name' => 'Cliente Nombre',
        'address' => [...],
    ],
    'items' => [
        [
            'description' => 'Membresía Mensual',
            'quantity' => 1,
            'unitPrice' => 500.00,
            'taxAmount' => 80.00,
        ]
    ],
]
```

---

### Opción 3: SAT Directo (Para Contribuyentes Registrados)

**Descripción:** Integración directa con el Servicio de Administración Tributaria de México.

**Requisitos:**
- RFC registrado en SAT
- Certificados digitales (.cer y .key)
- Homologación con SAT

**Configuración en `.env`:**
```env
BILLING_PROVIDER=sat
SAT_RFC=RFC12345678ABC
SAT_CERT_PATH=/path/to/certificate.cer
SAT_KEY_PATH=/path/to/private_key.key
SAT_KEY_PASSWORD=password
```

**Consideraciones:**
- Requiere desarrollo más complejo
- Validaciones en tiempo real
- SOC 2 compliance

---

## 📊 ESTRUCTURA DE DATOS

### Receipt Model

Campo `details` contiene metadatos de facturación:

```php
$receipt = Receipt::find(1);

// Acceder a datos de facturación
$billing = $receipt->details['electronic_billing'] ?? [];

echo $billing['provider'];      // facturama
echo $billing['folio'];          // INV-2026-000001
echo $billing['stamp_number'];   // UUID de CFDI
echo $billing['status'];         // emitted, cancelled, etc.
```

---

## 🚀 GENERAR FACTURA ELECTRÓNICA

### Desde Controlador

```php
use App\Services\ElectronicBillingService;

class ReceiptController {
    public function generateElectronicInvoice(Request $request, string $id)
    {
        $receipt = Receipt::findOrFail($id);
        
        $billingService = new ElectronicBillingService();
        
        $result = $billingService->generateElectronicInvoice($receipt);
        
        if ($result['success']) {
            return response()->json([
                'message' => 'Invoice generated successfully',
                'invoice' => $result['data']
            ]);
        } else {
            return response()->json([
                'error' => 'Failed to generate invoice',
                'message' => $result['message']
            ], 400);
        }
    }
}
```

### Desde PHP (Tinker)

```bash
php artisan tinker

$receipt = Receipt::find(1);
$service = new \App\Services\ElectronicBillingService();
$result = $service->generateElectronicInvoice($receipt);

# Ver resultado
dd($result);
```

---

## ✓ VALIDAR FACTURA

```php
$billingService = new ElectronicBillingService();

$status = $billingService->validateInvoiceStatus($receipt);

echo $status['status']; // emitted, pending, error
```

---

## ❌ CANCELAR FACTURA

```php
$billingService = new ElectronicBillingService();

$result = $billingService->cancelInvoice($receipt);

if ($result['success']) {
    echo "Factura cancelada: " . $result['data']['folio'];
}
```

---

## 📋 FORMATO CFDI

### Plantilla CFDI (XML)

El servicio genera CFDI en formato XML:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Comprobante
    xmlns="http://www.sat.gob.mx/cfd/3"
    xmlns:cfdi="http://www.sat.gob.mx/cfd/3"
    Version="3.3"
    Folio="INV-2026-000001"
    Fecha="2026-02-15T10:30:00"
    Serie="INV"
    SubTotal="500.00"
    IVA="80.00"
    Total="580.00"
>
    <!-- Emisor (Company) -->
    <cfdi:Emisor
        Rfc="RFC12345678ABC"
        Nombre="IronGym"
    >
        <cfdi:DomicilioFiscal
            Calle="Calle Principal"
            Numero="123"
            Municipio="Ciudad"
            Estado="Estado"
            Pais="Mexico"
            CodigoPostal="00000"
        />
    </cfdi:Emisor>

    <!-- Receptor (Client) -->
    <cfdi:Receptor
        Rfc="RFC87654321XYZ"
        Nombre="Cliente Nombre"
        UsoCFDI="G101"
    >
```

---

## 🔄 FLUJO DE FACTURACIÓN

```
┌─────────────────┐
│   Crear Recibo  │
└────────┬────────┘
         │
         ▼
┌──────────────────────────┐
│ POST /generate-invoice   │
│ (marcar como facturado)  │
└────────┬─────────────────┘
         │
         ▼
┌──────────────────────────┐
│ ElectronicBillingService │
│ .generateElectronicInvoice│
└────────┬─────────────────┘
         │
         ├─────────────────┬──────────────┬──────────────┐
         │                 │              │              │
         ▼                 ▼              ▼              ▼
    ┌─────────┐    ┌──────────────┐ ┌─────────┐  ┌──────────┐
    │  LOCAL  │    │  FACTURAMA   │ │  SAT    │  │  (Queue) │
    │ Storage │    │  API Request │ │ Request │  │ Email    │
    └────┬────┘    └──────┬───────┘ └────┬────┘  └────┬─────┘
         │                │            │            │
         └────────┬───────┴────────────┴────────────┘
                  │
                  ▼
         ┌─────────────────┐
         │ Actualizar DB   │
         │ (is_invoiced=1) │
         └─────────────────┘
                  │
                  ▼
         ┌─────────────────┐
         │ Generar PDF     │
         │ (invoice.pdf)   │
         └─────────────────┘
                  │
                  ▼
         ┌─────────────────┐
         │ Responder al    │
         │ cliente (JSON)  │
         └─────────────────┘
```

---

## 📝 EJEMPLOS PRÁCTICOS

### Ejemplo 1: Generar Factura Local

```json
POST /api/receipts/1/generate-invoice-pdf

Body:
{
  "invoice_notes": "Membresía válida por 30 días"
}

Response:
{
  "message": "Invoice generated successfully",
  "receipt": {
    "id": 1,
    "invoice_number": "INV-2026-000001",
    "is_invoiced": true,
    "details": {
      "electronic_billing": {
        "success": true,
        "provider": "local",
        "invoice_id": "LOCAL-INV-2026-000001-1708090800",
        "status": "emitted",
        "emission_date": "2026-02-15 10:30:00"
      }
    }
  }
}
```

### Ejemplo 2: Generar Factura Facturama

```php
// Configurar en .env
BILLING_PROVIDER=facturama
BILLING_API_KEY=user@facturama.com
BILLING_API_SECRET=abc123xyz

// En controlador
$service = new ElectronicBillingService();
$result = $service->generateElectronicInvoice($receipt);

// Resultado esperado
$result = [
    'success' => true,
    'data' => [
        'provider' => 'facturama',
        'folio' => 'INV-2026-000001',
        'stamp_number' => 'A1B2C3D4-E5F6-G7H8-I9J0-K1L2M3N4O5P6',
        'download_url' => 'https://pdf.facturama.mx/...',
        'validation_status' => 'valid_at_sat'
    ]
];
```

### Ejemplo 3: Validar Estado

```php
$service = new ElectronicBillingService();
$status = $service->validateInvoiceStatus($receipt);

// Respuesta
[
    'folio' => 'INV-2026-000001',
    'status' => 'emitted',
    'at_sat' => true,
    'valid' => true,
    'last_check' => '2026-02-15 10:35:00'
]
```

---

## 🛡️ CONSIDERACIONES DE SEGURIDAD

### Credenciales

❌ **NO hacer:**
```env
# En .env public
BILLING_API_KEY=user@example.com
BILLING_API_SECRET=password
```

✅ **Hacer:**
```bash
# Almacenar en servidor seguro
# Usar variables de entorno del servidor
# Nunca commitear en Git
# Usar .env.local (gitignored)
```

### Validación de Datos

```php
// Validar antes de enviar a proveedor
$validated = [
    'company_tax_id' => ['required', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/'],
    'client_tax_id' => ['required', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/'],
    'total' => ['required', 'numeric', 'min:0.01'],
];
```

### Registros de Auditoría

```php
// Registrar todas las operaciones
\Log::info('Factura generada', [
    'receipt_id' => $receipt->id,
    'provider' => 'facturama',
    'folio' => 'INV-2026-000001',
    'user_id' => auth()->id(),
    'timestamp' => now(),
]);
```

---

## 🔍 DEBUGGING

### Ver Solicitud a API

```php
// En ElectronicBillingService::sendToFacturama()

\Log::debug('Facturama Request', [
    'url' => $url,
    'method' => 'POST',
    'data' => $payload,  // No loguear credenciales
]);

$response = Http::withBasicAuth(...)
    ->post($url, $payload);

\Log::debug('Facturama Response', [
    'status' => $response->status(),
    'body' => $response->json(),
]);
```

### Probar en Local

```bash
# Usar Postman/Insomnia
POST http://localhost:8000/api/receipts/1/generate-invoice-pdf
Authorization: Bearer {token}
Content-Type: application/json

{
  "invoice_notes": "test"
}

# Ver respuesta y error si aplica
```

---

## 📞 SOPORTE DE PROVEEDORES

### Facturama
- **Documentación:** https://www.facturama.mx/API/
- **Endpoint Sandbox:** https://apisandbox.facturama.mx
- **Soporte:** https://www.facturama.mx/Soporte/

### SAT
- **Portal:** https://www.sat.gob.mx/
- **Documentación:** https://www.sat.gob.mx/cfdi
- **Requiere certificado digital**

### Local
- **Sin soporte externo**
- **Desarrollado internamente**

---

## ✅ CHECKLIST DE IMPLEMENTACIÓN

- [ ] Instalar y configurar DomPDF
- [ ] Crear modelos y migraciones
- [ ] Implementar ReceiptPdfService
- [ ] Crear plantillas HTML
- [ ] Implementar ElectronicBillingService
- [ ] Configurar config/billing.php
- [ ] Agregar variables de entorno
- [ ] Crear endpoints de API
- [ ] Probar localmente
- [ ] Integrar con Facturama (opcional)
- [ ] Integrar con SAT (opcional)
- [ ] Implementar envío de emails
- [ ] Crear UI frontend
- [ ] Probar en producción

---

**Versión Final:** 1.0.0  
**Última actualización:** 15 de Febrero, 2026
