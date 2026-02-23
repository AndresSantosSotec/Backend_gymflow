# MÓDULO DE GENERACIÓN DE PDFs Y FACTURACIÓN ELECTRÓNICA

**Documento:** Guía de Implementación de PDFs y Facturas Electrónicas  
**Versión:** 1.0.0  
**Fecha:** 15 de Febrero, 2026  
**Estado:** COMPLETADO

---

## 📋 RESUMEN

Se implementó un sistema completo para:
- **Generación de PDFs** de recibos y facturas
- **Facturación electrónica** con integración a proveedores externos
- **Plantillas HTML** personalizables para recibos e invoices
- **Envío automático** de PDFs por email
- **Descarga** de múltiples recibos en lote

---

## 🎯 CARACTERÍSTICAS IMPLEMENTADAS

### ✅ Generación de PDFs

1. **Recibos (Receipts)**
   - Diseño profesional con logo y datos de empresa
   - Información completa del cliente
   - Detalles de pago
   - Estados visuales (Pagado, Pendiente, Borrador)

2. **Facturas Electrónicas (Invoices)**
   - Formato oficial para facturación
   - Datos fiscales y RFC
   - Información de cliente y empresa
   - Detalles de IVA e impuestos
   - Marcas de agua y estampas

### ✅ Servicios de Facturación

1. **Almacenamiento local**
   - Guardar PDFs en servidor
   - Generar en tiempo real

2. **Integraciones externas**
   - Facturama
   - SAT (México)
   - Extensible a otros proveedores

### ✅ Endpoints de Descarga

- Descargar recibos individuales
- Descargar facturas individuales
- Descargar lotes de PDFs
- Previsualizar HTML
- Enviar por email

---

## 🔧 COMPONENTES CREADOS

### 1. Servicio: ReceiptPdfService
**Ubicación:** `app/Services/ReceiptPdfService.php`

**Métodos principales:**
```php
$service->generateReceiptPdf($receipt)      // Generar PDF recibo
$service->generateInvoicePdf($receipt)      // Generar PDF factura
$service->downloadReceiptPdf($receipt)      // Descargar recibo
$service->downloadInvoicePdf($receipt)      // Descargar factura
$service->emailReceiptPdf($receipt, $email) // Enviar por email
$service->generateBulkPdfs($ids)            // Generar múltiples
```

### 2. Servicio: ElectronicBillingService
**Ubicación:** `app/Services/ElectronicBillingService.php`

**Métodos principales:**
```php
$service->generateElectronicInvoice($receipt)    // Generar factura
$service->validateInvoiceStatus($receipt)        // Validar estado
$service->cancelInvoice($receipt)                // Cancelar factura
$service->generateCFDI($receipt)                 // Generar XML
```

### 3. Plantillas de Vista

#### `resources/views/pdfs/receipt.blade.php`
Template HTML para recibos con:
- Encabezado con datos de empresa
- Información del cliente
- Detalles del pago
- Totales calculados
- Información de estado
- Footer personalizable

#### `resources/views/pdfs/invoice.blade.php`
Template HTML para facturas con:
- Datos fiscales completos
- Información RFC/TAX
- Líneas detalladas
- Tabla de conceptos
- Totales con impuestos
- Datos de CFDI

### 4. Configuración

#### `config/billing.php`
Configuración centralizada:
```php
'provider' => 'local'  // Tipo de facturación
'pdf.paper_size' => 'a4'  // Tamaño de papel
'company' => [...]     // Datos de empresa
'storage' => [...]     // Rutas de almacenamiento
```

---

## 📚 NUEVOS ENDPOINTS

### Descargar Recibos

```
GET /api/receipts/{id}/download/receipt
```

Descarga el recibo en formato PDF.

**Respuesta:**
```
File: REC-2026-000001.pdf (Binary PDF)
```

**Errores:**
- 404 Not Found - Recibo no existe
- 500 Server Error - Error en generación

---

### Descargar Factura

```
GET /api/receipts/{id}/download/invoice
```

Descarga la factura electrónica en PDF.

**Requisitos:**
- Recibo debe estar facturado (`is_invoiced = true`)

**Respuesta:**
```
File: INV-2026-000001.pdf (Binary PDF)
```

**Error si no está facturado:**
```json
{
  "error": "Receipt is not invoiced",
  "message": "Este recibo no ha sido facturado aún"
}
```

---

### Generar Factura y Descargar

```
POST /api/receipts/{id}/generate-invoice-pdf
```

Crea factura y descarga PDF en una sola solicitud.

**Body:**
```json
{
  "invoice_notes": "Notas adicionales opcionales"
}
```

**Respuesta:**
```
File: INV-2026-000001.pdf (Binary PDF)
```

---

### Enviar Recibo por Email

```
POST /api/receipts/{id}/email-pdf
```

Envía el recibo PDF por correo electrónico.

**Body:**
```json
{
  "email": "cliente@example.com",
  "message": "Mensaje personalizado opcional"
}
```

**Respuesta:**
```json
{
  "message": "PDF sent successfully",
  "email": "cliente@example.com",
  "receipt": { ... }
}
```

---

### Descargar Múltiples Recibos

```
POST /api/receipts/bulk-download
```

Genera PDFs de múltiples recibos en lote.

**Body:**
```json
{
  "ids": [1, 2, 3, 4, 5]
}
```

**Respuesta:**
```json
{
  "message": "Bulk PDF generation completed",
  "results": {
    "success": [
      {
        "id": 1,
        "receipt_number": "REC-2026-000001",
        "file": "recibos/REC-2026-000001_2026-02-15.pdf"
      }
    ],
    "failed": []
  }
}
```

---

### Preview (Previsualizar) Recibo

```
GET /api/receipts/{id}/preview/receipt
```

Previsualiza el recibo en HTML (no PDF).

**Respuesta:** HTML renderizado

---

### Preview (Previsualizar) Factura

```
GET /api/receipts/{id}/preview/invoice
```

Previsualiza la factura en HTML (no PDF).

**Respuesta:** HTML renderizado

---

## ⚙️ CONFIGURACIÓN REQUERIDA

### Variables de Entorno

Agregar a `.env`:

```env
# Facturación Electrónica
BILLING_PROVIDER=local
# BILLING_PROVIDER=facturama  # Para Facturama
# BILLING_PROVIDER=sat        # Para SAT

BILLING_API_URL=https://api.facturama.mx/v3
BILLING_API_KEY=your_api_key
BILLING_API_SECRET=your_api_secret

# Datos de Empresa
COMPANY_NAME=IronGym
COMPANY_TAX_ID=RFC12345678ABC
COMPANY_ADDRESS=Calle Principal 123, Ciudad, País
COMPANY_PHONE=+1 (555) 123-4567
COMPANY_EMAIL=info@irongym.local

# PDFs
RECEIPT_PAPER_SIZE=a4
RECEIPT_ORIENTATION=portrait
RECEIPT_LOGO_URL=https://yourdomain.com/logo.png
RECEIPT_FOOTER_TEXT=Gracias por su confianza
```

### Publicar Assets

```bash
# Crear enlace simbólico para storage
php artisan storage:link
```

---

## 📝 EJEMPLOS DE USO

### Ejemplo 1: Descargar Recibo

```bash
curl -X GET "http://localhost:3000/api/receipts/5/download/receipt" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  --output recibo.pdf
```

### Ejemplo 2: Crear Factura y Descargar

```bash
curl -X POST "http://localhost:3000/api/receipts/5/generate-invoice-pdf" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "invoice_notes": "Factura por servicios de membresía mensual"
  }' \
  --output factura.pdf
```

### Ejemplo 3: Enviar Recibo por Email

```bash
curl -X POST "http://localhost:3000/api/receipts/5/email-pdf" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "cliente@example.com",
    "message": "Adjunto encontrará su recibo de pago"
  }'
```

### Ejemplo 4: Descargar Lote de Recibos

```bash
curl -X POST "http://localhost:3000/api/receipts/bulk-download" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ids": [1, 2, 3, 4, 5]
  }'
```

---

## 🔌 FACTURACIÓN ELECTRÓNICA

### Proveedores Soportados

#### 1. Local (Predeterminado)
- No requiere API externa
- Almacena facturas localmente
- Genera PDFs en servidor

**Configuración:**
```env
BILLING_PROVIDER=local
```

#### 2. Facturama (México)
- API REST completa
- Generación de CFDI
- Envío a SAT automático

**Configuración:**
```env
BILLING_PROVIDER=facturama
BILLING_API_URL=https://api.facturama.mx/v3
BILLING_API_KEY=your_facturama_key
```

#### 3. SAT (México - Directo)
- Conexión directa con SAT
- Requiere certificados
- Validación en tiempo real

**Configuración:**
```env
BILLING_PROVIDER=sat
BILLING_API_URL=https://www.sat.gob.mx/...
```

---

## 📊 ESTRUCTURA DE DATOS

### Almacenamiento de Facturas

Los datos de facturación se guardan en el campo `details` del modelo Receipt:

```php
$receipt->details = [
    'electronic_billing' => [
        'success' => true,
        'provider' => 'local',
        'invoice_id' => 'LOCAL-INV-2026-000001-1708090800',
        'folio' => 'INV-2026-000001',
        'stamp_number' => 'A1B2C3D4E5F6G7H8...',
        'status' => 'emitted',
        'emission_date' => '2026-02-15 10:30:00',
        'xml_url' => 'http://...',
        'pdf_url' => 'http://...',
    ]
];
```

---

## 🎨 PERSONALIZACIÓN DE TEMPLATES

### Modificar Template de Recibo

**Archivo:** `resources/views/pdfs/receipt.blade.php`

Cambiar:
- Colores: Buscar `#1e40af` y reemplazar
- Logo: Agregar imagen en header
- Estructura: Modificar HTML según necesidades
- Estilos: Actualizar CSS en `<style>`

### Modificar Template de Factura

**Archivo:** `resources/views/pdfs/invoice.blade.php`

Cambiar:
- Datos fiscales
- Formato de CFDI
- Información requerida
- Campos adicionales

---

## ⚠️ CONSIDERACIONES IMPORTANTES

### Performance

1. **Generación de PDFs es lenta**
   - Usar queues para generación en background
   - Cachear PDFs generados
   - Generar bajo demanda

2. **Almacenamiento**
   - Limpiar PDFs antiguos periódicamente
   - Usar S3 para PDFs en producción
   - Limitar tamaño de archivos

### Seguridad

1. **Acceso a PDFs**
   - Validar permisos antes de descargar
   - Usar URLs temporales (signed URLs)
   - Loguear descargas

2. **Datos sensibles**
   - Encriptar RFC/TAX en tránsito
   - Usar HTTPS en producción
   - No guardar templates en git

### Facturación

1. **Validación**
   - Validar datos antes de enviar
   - Reintentos en caso de error
   - Auditoría de cambios

2. **Conformidad**
   - Cumplir regulaciones locales
   - Validar con proveedores
   - Mantener registros

---

## 🔧 PRÓXIMOS PASOS

### Corto plazo

- [ ] Implementar Queue para envío de emails
- [ ] Agregar firma digital en PDFs
- [ ] Crear endpoint para descargar XML
- [ ] Validar plantillas con datos reales

### Mediano plazo

- [ ] Integrar con Facturama API
- [ ] Implementar SAT directo
- [ ] Generar reportes mensuales
- [ ] Dashboard de facturación

### Largo plazo

- [ ] Integración con contabilidad
- [ ] Reportes de impuestos
- [ ] Auditoría completa
- [ ] Certificación digital

---

## 🆘 TROUBLESHOOTING

### "Error generating PDF"

**Causa:** DomPDF no puede generar PDF

**Solución:**
```bash
# Verificar instalación
composer show barryvdh/laravel-dompdf

# Reinstalar si es necesario
composer require barryvdh/laravel-dompdf:^3.1
```

### "Storage permission denied"

**Causa:** Permisos insuficientes en carpeta storage

**Solución:**
```bash
chmod -R 755 storage/
chmod -R 755 public/
php artisan storage:link
```

### "Invalid configuration"

**Causa:** Falta config/billing.php

**Solución:**
```bash
# Archivo ya existe en Backend-IronGym/config/billing.php
# Verificar variables de entorno:
echo $BILLING_PROVIDER
```

### "Email not sending"

**Causa:** Configuración de mail incorrecta

**Solución:**
```bash
# Verificar config/mail.php
# Probar con Mailtrap o similar
php artisan tinker
# Mail::raw('test', function($m) { $m->to('test@test.com'); });
```

---

## 📞 SOPORTE

- Errores de PDF: Ver logs en `storage/logs/laravel.log`
- Errores de facturación: Validar con proveedor
- Errores de email: Revisar configuración SMTP

---

**Versión Final:** 1.0.0  
**Última actualización:** 15 de Febrero, 2026
