# EJEMPLOS PRÁCTICOS DE PRUEBA - PDFs y Facturación

**Documento:** Colección de comandos y requests para probar el sistema  
**Versión:** 1.0.0  
**Fecha:** 15 de Febrero, 2026

---

## 🧪 AMBIENTE DE PRUEBA

### Requerimientos Previos

```bash
# 1. Ejecutar migraciones (si no lo hizo)
php artisan migrate:fresh --seed

# 2. Iniciar servidor Laravel
php artisan serve
# Output: Server running at: http://localhost:8000

# 3. En otra terminal, iniciar servidor del fronten (si usa Vite)
cd gym-access-qr-manage
npm run dev
```

### Obtener Token de Autenticación

Primero, crear/obtener un token válido:

```bash
# Opción 1: Crear usuario en tinker
php artisan tinker
>>> $user = User::create([
...   'name' => 'Test User',
...   'email' => 'test@example.com',
...   'password' => bcrypt('password123')
... ]);
>>> $token = $user->createToken('test-token');
>>> echo $token->plainTextToken;

# Copiar el token y usarlo en los ejemplos
```

O si ya tiene usuario:

```bash
php artisan tinker
>>> $user = User::first();
>>> $token = $user->createToken('test-token');
>>> echo $token->plainTextToken;
```

---

## 📥 BASH / cURL - Terminal Linux/Mac

### 1. Descargar Recibo en PDF

```bash
TOKEN="your_token_here"

curl -X GET "http://localhost:8000/api/receipts/1/download/receipt" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/pdf" \
  --output recibo_download.pdf

echo "Recibo descargado: recibo_download.pdf"
```

### 2. Descargar Factura en PDF

```bash
TOKEN="your_token_here"

curl -X GET "http://localhost:8000/api/receipts/1/download/invoice" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/pdf" \
  --output factura_download.pdf

echo "Factura descargada: factura_download.pdf"
```

### 3. Generar Factura y Descargar

```bash
TOKEN="your_token_here"

curl -X POST "http://localhost:8000/api/receipts/1/generate-invoice-pdf" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "invoice_notes": "Factura complementaria - Membresía válida por 30 días"
  }' \
  --output factura_generada.pdf

echo "Factura generada: factura_generada.pdf"
```

### 4. Previsualizar Recibo (HTML)

```bash
TOKEN="your_token_here"

curl -X GET "http://localhost:8000/api/receipts/1/preview/receipt" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: text/html" \
  --output preview_recibo.html

# Abrir en navegador
open preview_recibo.html  # Mac
# o
xdg-open preview_recibo.html  # Linux
```

### 5. Previsualizar Factura (HTML)

```bash
TOKEN="your_token_here"

curl -X GET "http://localhost:8000/api/receipts/1/preview/invoice" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: text/html" \
  --output preview_factura.html

open preview_factura.html
```

### 6. Descargar Múltiples Recibos

```bash
TOKEN="your_token_here"

curl -X POST "http://localhost:8000/api/receipts/bulk-download" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ids": [1, 2, 3, 4, 5]
  }' \
  --output bulk_recibos.json

# Ver resultado
cat bulk_recibos.json | jq '.'
```

### 7. Enviar Recibo por Email

```bash
TOKEN="your_token_here"

curl -X POST "http://localhost:8000/api/receipts/1/email-pdf" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "cliente@example.com",
    "message": "Adjunto encontrará su recibo de pago de membresía"
  }'

# Ver respuesta
```

---

## 🪟 PowerShell - Windows

### 1. Descargar Recibo

```powershell
$TOKEN = "your_token_here"
$headers = @{
    Authorization = "Bearer $TOKEN"
    Accept = "application/pdf"
}

Invoke-WebRequest `
  -Uri "http://localhost:8000/api/receipts/1/download/receipt" `
  -Headers $headers `
  -OutFile "recibo_download.pdf"

Write-Host "Recibo descargado: recibo_download.pdf"
```

### 2. Descargar Factura

```powershell
$TOKEN = "your_token_here"
$headers = @{
    Authorization = "Bearer $TOKEN"
    Accept = "application/pdf"
}

Invoke-WebRequest `
  -Uri "http://localhost:8000/api/receipts/1/download/invoice" `
  -Headers $headers `
  -OutFile "factura_download.pdf"

Write-Host "Factura descargada: factura_download.pdf"
```

### 3. Generar Factura

```powershell
$TOKEN = "your_token_here"
$headers = @{
    Authorization = "Bearer $TOKEN"
    "Content-Type" = "application/json"
}

$body = @{
    invoice_notes = "Factura complementaria - Membresía"
} | ConvertTo-Json

Invoke-WebRequest `
  -Uri "http://localhost:8000/api/receipts/1/generate-invoice-pdf" `
  -Method POST `
  -Headers $headers `
  -Body $body `
  -OutFile "factura_generada.pdf"

Write-Host "Factura generada: factura_generada.pdf"
```

### 4. Enviar por Email

```powershell
$TOKEN = "your_token_here"
$headers = @{
    Authorization = "Bearer $TOKEN"
    "Content-Type" = "application/json"
}

$body = @{
    email = "cliente@example.com"
    message = "Adjunto su recibo de pago"
} | ConvertTo-Json

$response = Invoke-WebRequest `
  -Uri "http://localhost:8000/api/receipts/1/email-pdf" `
  -Method POST `
  -Headers $headers `
  -Body $body

$response.Content | ConvertFrom-Json | ConvertTo-Json -Depth 10
```

---

## 📮 Postman - GUI

### Configuración Inicial

1. Abrir Postman
2. Crear nueva Collection: "IronGym - PDFs y Facturas"
3. Crear Environment variable: `token` con su JWT
4. Crear variable: `base_url` = `http://localhost:8000`

### Request 1: Descargar Recibo

```
Method: GET
URL: {{base_url}}/api/receipts/1/download/receipt

Headers:
- Authorization: Bearer {{token}}
- Accept: application/pdf

Send → Save response as → recibo.pdf
```

### Request 2: Descargar Factura

```
Method: GET
URL: {{base_url}}/api/receipts/1/download/invoice

Headers:
- Authorization: Bearer {{token}}
- Accept: application/pdf

Send → Save response as → factura.pdf
```

### Request 3: Generar Factura

```
Method: POST
URL: {{base_url}}/api/receipts/1/generate-invoice-pdf

Headers:
- Authorization: Bearer {{token}}
- Content-Type: application/json

Body (raw - JSON):
{
  "invoice_notes": "Factura generada 15 de Febrero de 2026"
}

Send
```

### Request 4: Preview Recibo

```
Method: GET
URL: {{base_url}}/api/receipts/1/preview/receipt

Headers:
- Authorization: Bearer {{token}}

Send → Open response → Visualizar en navegador
```

### Request 5: Email Recibo

```
Method: POST
URL: {{base_url}}/api/receipts/1/email-pdf

Headers:
- Authorization: Bearer {{token}}
- Content-Type: application/json

Body (raw - JSON):
{
  "email": "cliente@example.com",
  "message": "Adjunto su recibo mensual"
}

Send
```

### Request 6: Descarga en Lote

```
Method: POST
URL: {{base_url}}/api/receipts/bulk-download

Headers:
- Authorization: Bearer {{token}}
- Content-Type: application/json

Body (raw - JSON):
{
  "ids": [1, 2, 3, 4, 5]
}

Send → Ver respuesta JSON
```

---

## 🧪 PHP Tinker - Pruebas de Servicio

```bash
php artisan tinker
```

### Test 1: Generar PDF de Recibo

```php
use App\Models\Receipt;
use App\Services\ReceiptPdfService;

$receipt = Receipt::find(1);
$service = new ReceiptPdfService();

// Generar PDF
$pdf = $service->generateReceiptPdf($receipt);

// Ver si se generó
echo "PDF generado: " . ($pdf ? "SÍ ✓" : "NO ✗");
```

### Test 2: Verificar Almacenamiento

```php
use Illuminate\Support\Facades\Storage;

// Ver archivos en recibos
$files = Storage::disk('public')->files('recibos');
dd($files);

// Ver archivos en facturas
$files = Storage::disk('public')->files('facturas');
dd($files);
```

### Test 3: Acceder a Receipt

```php
use App\Models\Receipt;

$receipt = Receipt::find(1);

echo "Recibo #: " . $receipt->receipt_number . "\n";
echo "Cliente: " . $receipt->client->name . "\n";
echo "Total: " . $receipt->total . "\n";
echo "Estado: " . $receipt->status . "\n";
echo "Facturado: " . ($receipt->is_invoiced ? "SÍ" : "NO") . "\n";
echo "Número de factura: " . $receipt->invoice_number . "\n";

dd($receipt);
```

### Test 4: Servicios de Facturación

```php
use App\Services\ElectronicBillingService;
use App\Models\Receipt;

$receipt = Receipt::find(1);
$service = new ElectronicBillingService();

// Preparar datos
$data = $service->prepareInvoiceData($receipt);

echo "Datos preparados:";
dd($data);
```

### Test 5: Marcar como Facturado

```php
use App\Models\Receipt;

$receipt = Receipt::find(1);

// Marcar como facturado
$receipt->markAsInvoiced("Factura de prueba");

echo "Recibo actualizado:";
echo "is_invoiced: " . ($receipt->is_invoiced ? "true" : "false") . "\n";
echo "invoice_number: " . $receipt->invoice_number . "\n";
echo "invoiced_at: " . $receipt->invoiced_at . "\n";

exit; // Salir de tinker
```

---

## 🎯 Flujo Completo de Prueba

### Escenario 1: Usuario descarga su recibo

```bash
# 1. Obtener token
TOKEN=$(php artisan tinker -c "echo User::first()->createToken('test')->plainTextToken;")

# 2. Descargar recibo
curl -X GET "http://localhost:8000/api/receipts/1/download/receipt" \
  -H "Authorization: Bearer $TOKEN" \
  --output /tmp/recibo.pdf

# 3. Verificar que se descargó
ls -lh /tmp/recibo.pdf

# 4. Abrir en navegador (Mac)
open /tmp/recibo.pdf
```

### Escenario 2: Admin genera factura desde recibo

```bash
TOKEN="your_token"

# 1. Generar factura
curl -X POST "http://localhost:8000/api/receipts/1/generate-invoice-pdf" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"invoice_notes": "Factura mensual de membresía"}' \
  --output /tmp/factura.pdf

# 2. Ver en Tinker que se marcó como facturado
php artisan tinker -c "echo Receipt::find(1)->invoice_number;"

# 3. Verificar datos en DB
php artisan tinker -c "dd(Receipt::find(1)->details);"
```

### Escenario 3: Envío masivo de PDFs

```bash
TOKEN="your_token"

# 1. Descargar 5 recibos
curl -X POST "http://localhost:8000/api/receipts/bulk-download" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"ids":[1,2,3,4,5]}' \
  --output /tmp/bulk_result.json

# 2. Ver resultado
cat /tmp/bulk_result.json | jq '.results.success[] | {id, file}'

# 3. Verificar archivos creados
ls -lh storage/app/public/recibos/
```

---

## ❌ Manejo de Errores

### Error: "Unauthorized"

```json
{
  "message": "Unauthenticated."
}
```

**Solución:**
- Verificar token está correcto
- Token no está expirado
- Usar header: `Authorization: Bearer {token}`

### Error: "Receipt not found"

```json
{
  "message": "No query results found for model [App\Models\Receipt]",
  "exception": "ModelNotFoundException"
}
```

**Solución:**
- Verificar ID existe: `Receipt::find(1)` en tinker
- Crear recibo de prueba si no existe

### Error: "Receipt is not invoiced"

```json
{
  "error": "Receipt is not invoiced",
  "message": "Este recibo no ha sido facturado aún"
}
```

**Solución:**
- Primero generar factura: `POST /receipts/{id}/generate-invoice-pdf`
- O marcar como facturado en tinker: `Receipt::find(1)->markAsInvoiced()`

### Error: "DomPDF Error"

```
ErrorException: Undefined property: $stdout
```

**Solución:**
- Reinstalar DomPDF: `composer require barryvdh/laravel-dompdf:^3.1`
- Verificar PHP >= 8.0
- Revisar `storage/logs/laravel.log`

---

## 📊 Datos de Prueba Disponibles

### Recibos Creados por Seeder

```php
// De ReceiptSeeder - 15 recibos generados

Clients:
- ID 1: John Doe
- ID 2: Jane Smith  
- ID 3: Carlos López
- ID 4: María García
- ID 5: Juan Martínez

Receipts (5 per client):
1. REC-2026-000001 - Client 1 - Pagado
2. REC-2026-000002 - Client 1 - Pendiente
3. REC-2026-000003 - Client 1 - Facturado
... hasta 15 recibos
```

Verificar en Tinker:

```php
php artisan tinker

# Ver todos los recibos
\App\Models\Receipt::all()->each(function($r) {
  echo $r->receipt_number . " - " . $r->client->name . "\n";
});

# Ver recibos por estado
\App\Models\Receipt::where('status', 'paid')->count();
\App\Models\Receipt::where('status', 'pending')->count();
\App\Models\Receipt::where('is_invoiced', true)->count();
```

---

## 🎬 Script de Prueba Automática

### bash/zsh

Crear archivo `test_pdfs.sh`:

```bash
#!/bin/bash

TOKEN="your_token_here"
BASE_URL="http://localhost:8000"

echo "🧪 Iniciando pruebas de PDFs y Facturas..."

# Test 1
echo "✓ Test 1: Descargar recibo"
curl -s -X GET "$BASE_URL/api/receipts/1/download/receipt" \
  -H "Authorization: Bearer $TOKEN" \
  --output /tmp/test_recibo.pdf && echo "  OK" || echo "  FAIL"

# Test 2
echo "✓ Test 2: Previsualizar recibo"
curl -s -X GET "$BASE_URL/api/receipts/1/preview/receipt" \
  -H "Authorization: Bearer $TOKEN" \
  -o /tmp/test_preview.html && echo "  OK" || echo "  FAIL"

# Test 3
echo "✓ Test 3: Descargar en lote"
curl -s -X POST "$BASE_URL/api/receipts/bulk-download" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"ids":[1,2,3]}' \
  -o /tmp/test_bulk.json && echo "  OK" || echo "  FAIL"

# Test 4
echo "✓ Test 4: Generar factura"
curl -s -X POST "$BASE_URL/api/receipts/1/generate-invoice-pdf" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"invoice_notes":"Test"}' \
  -o /tmp/test_invoice.pdf && echo "  OK" || echo "  FAIL"

echo "✅ Pruebas completadas!"
ls -lh /tmp/test_*
```

Ejecutar:

```bash
chmod +x test_pdfs.sh
./test_pdfs.sh
```

---

## 🔗 URLs de Referencia

### Endpoints Disponibles

```
GET    /api/receipts                                    # Listar recibos
POST   /api/receipts                                    # Crear recibo
GET    /api/receipts/{id}                               # Ver recibo
PUT    /api/receipts/{id}                               # Actualizar
DELETE /api/receipts/{id}                               # Eliminar

GET    /api/receipts/{id}/download/receipt              # ✨ NEW: Descargar
GET    /api/receipts/{id}/download/invoice              # ✨ NEW: Factura
POST   /api/receipts/{id}/generate-invoice-pdf          # ✨ NEW: Generar
POST   /api/receipts/{id}/email-pdf                     # ✨ NEW: Email
POST   /api/receipts/bulk-download                      # ✨ NEW: Lote
GET    /api/receipts/{id}/preview/receipt               # ✨ NEW: Preview
GET    /api/receipts/{id}/preview/invoice               # ✨ NEW: Preview
```

---

**Versión:** 1.0.0  
**Última actualización:** 15 de Febrero, 2026
