# INTEGRACIÓN FRONTEND - RECIBOS Y FACTURAS

**Documento:** Guía de Implementación Frontend para PDFs y Recibos  
**Versión:** 1.0.0  
**Fecha:** 15 de Febrero, 2026  
**Estado:** COMPLETADO

---

## 📱 COMPONENTES CREADOS EN REACT

### 1. Service: `receiptsService`
**Ubicación:** `src/services/receipts.service.ts`

Servicio TypeScript que interactúa con los endpoints del backend.

**Métodos principales:**
```typescript
// Obtener
await receiptsService.getAll(filters?)               // Listar todos
await receiptsService.byClient(clientId)             // Por cliente
await receiptsService.getById(id)                    // Obtener uno

// Crear
await receiptsService.create(data)                   // Crear recibo
await receiptsService.createFromPayment(paymentId)   // Desde pago

// Descargar PDFs
const blob = await receiptsService.downloadReceiptPdf(id)    // Recibo PDF
const blob = await receiptsService.downloadInvoicePdf(id)    // Factura PDF
const blob = await receiptsService.generateAndDownloadInvoice(id, notes?)  // Generar

// Previsualizar
const html = await receiptsService.previewReceipt(id)        // Recibo HTML
const html = await receiptsService.previewInvoice(id)        // Factura HTML

// Email
await receiptsService.emailReceipt(id, email, message?)      // Enviar

// Otros
await receiptsService.bulkDownload(ids)             // Descargar múltiples
await receiptsService.markAsPaid(id)                // Marcar pagado
await receiptsService.statistics()                  // Estadísticas
```

### 2. Componente: `ReceiptList`
**Ubicación:** `src/components/receipts/ReceiptList.tsx`

Tabla completa con todas las operaciones de recibos.

**Props:**
```typescript
interface ReceiptListProps {
  clientId?: number;           // Filtrar por cliente
  onReceiptSelect?: (receipt) => void;  // Callback de selección
}
```

**Características:**
- ✅ Tabla paginada de recibos
- ✅ Descargar recibos en PDF
- ✅ Descargar facturas en PDF
- ✅ Ver preview HTML
- ✅ Imprimir directamente
- ✅ Enviar por email
- ✅ Filtros por estado, tipo, facturación

**Ejemplo de uso:**
```tsx
import { ReceiptList } from '@/components/receipts';

export function MyPage() {
  return (
    <ReceiptList 
      clientId={5}
      onReceiptSelect={(receipt) => console.log(receipt)}
    />
  );
}
```

### 3. Componente: `ReceiptCard`
**Ubicación:** `src/components/receipts/ReceiptCard.tsx`

Tarjeta compacta para mostrar un recibo individual.

**Props:**
```typescript
interface ReceiptCardProps {
  receipt: Receipt;
  onDownload?: (receipt) => void;
  onPreview?: (receipt) => void;
  compact?: boolean;  // Versión compacta
}
```

**Ejemplo de uso:**
```tsx
import { ReceiptCard } from '@/components/receipts';

export function ClientProfile({ client }) {
  return (
    <div className="space-y-4">
      {client.receipts?.map(receipt => (
        <ReceiptCard 
          key={receipt.id}
          receipt={receipt}
          compact={true}
          onDownload={(r) => toast.success(`Descargado: ${r.receipt_number}`)}
        />
      ))}
    </div>
  );
}
```

### 4. Componente: `ClientReceipts`
**Ubicación:** `src/components/receipts/ClientReceipts.tsx`

Componente completo para mostrar todos los recibos de un cliente.

**Props:**
```typescript
interface ClientReceiptsProps {
  clientId: number;
}
```

**Características:**
- ✅ Estadísticas del cliente (total, pagados, pendientes)
- ✅ Lista completa de recibos
- ✅ Integración automática

**Ejemplo:**
```tsx
import { ClientReceipts } from '@/components/receipts';

export function ClientDetail({ clientId }) {
  return (
    <div>
      <h1>Detalles del Cliente</h1>
      {/* ... otras secciones ... */}
      <ClientReceipts clientId={clientId} />
    </div>
  );
}
```

### 5. Página: `ReceiptsPage`
**Ubicación:** `src/pages/admin/Receipts.tsx`

Página completa de administración de recibos y facturas.

**Características:**
- ✅ Dashboard con estadísticas
- ✅ Tabla de recibos con todos los datos
- ✅ Filtros avanzados (estado, tipo, facturación)
- ✅ Búsqueda por número o cliente
- ✅ Acciones rápidas (descargar, enviar, previsualizar)
- ✅ Dialog para previsualizar con opción de imprimir
- ✅ Dialog para enviar por email

**Accedida en:**
```
http://localhost:5173/admin/receipts
```

---

## 🔗 INTEGRACIÓN EN RUTAS

**Ubicación:** `src/App.tsx`

```tsx
// Los recibos están bajo el mismo permiso que Pagos
<Route element={<PermissionGuard permission="PAYMENTS_VIEW" />}>
  <Route path="payments" element={<Payments />} />
  <Route path="receipts" element={<ReceiptsPage />} />
</Route>
```

---

## 🧭 MENÚ LATERAL

**Ubicación:** `src/components/Sidebar.tsx`

Se agregó el enlace "Recibos y Facturas" en el menú lateral.

```tsx
{ to: '/admin/receipts', icon: Receipt, label: 'Recibos y Facturas', permission: 'PAYMENTS_VIEW' }
```

---

## 📊 CASOS DE USO

### Caso 1: Ver Recibos de un Cliente

```tsx
import { ClientReceipts } from '@/components/receipts';

export function ClientDetailPage({ id }) {
  return (
    <div>
      <h1>Perfil del Cliente</h1>
      <ClientReceipts clientId={parseInt(id)} />
    </div>
  );
}
```

### Caso 2: Descargar un Recibo

```tsx
import { receiptsService } from '@/services/receipts.service';

async function handleDownloadReceipt(receiptId: number) {
  try {
    const blob = await receiptsService.downloadReceiptPdf(receiptId);
    
    // Crear link de descarga
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `recibo-${receiptId}.pdf`;
    document.body.appendChild(a);
    a.click();
    window.URL.revokeObjectURL(url);
    document.body.removeChild(a);
    
    toast.success('Recibo descargado');
  } catch (error) {
    toast.error('Error descargando recibo');
  }
}
```

### Caso 3: Imprimir un Recibo

```tsx
import { receiptsService } from '@/services/receipts.service';

async function handlePrintReceipt(receiptId: number) {
  try {
    const html = await receiptsService.previewReceipt(receiptId);
    
    const printWindow = window.open('', '', 'width=800,height=600');
    if (printWindow) {
      printWindow.document.write(html);
      printWindow.document.close();
      setTimeout(() => {
        printWindow.print();
      }, 250);
    }
  } catch (error) {
    toast.error('Error preparando impresión');
  }
}
```

### Caso 4: Enviar Recibo por Email

```tsx
import { receiptsService } from '@/services/receipts.service';

async function handleSendEmail(receiptId: number, email: string, message: string) {
  try {
    await receiptsService.emailReceipt(receiptId, email, message);
    toast.success('Recibo enviado por email');
  } catch (error) {
    toast.error('Error enviando recibo');
  }
}
```

### Caso 5: Generar Factura desde Recibo

```tsx
import { receiptsService } from '@/services/receipts.service';

async function handleGenerateInvoice(receiptId: number, invoiceNotes?: string) {
  try {
    const blob = await receiptsService.generateAndDownloadInvoice(
      receiptId,
      invoiceNotes
    );
    
    // Descargar PDF
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `factura-${receiptId}.pdf`;
    document.body.appendChild(a);
    a.click();
    window.URL.revokeObjectURL(url);
    document.body.removeChild(a);
    
    toast.success('Factura generada y descargada');
  } catch (error) {
    toast.error('Error generando factura');
  }
}
```

---

## 🎨 PERSONALIZACIONES

### Cambiar Estilos de Estados

En `ReceiptCard.tsx`:

```tsx
const getStatusColor = (status: string) => {
  switch (status) {
    case 'paid':
      return 'bg-green-100 text-green-800';  // Cambiar color
    case 'pending':
      return 'bg-yellow-100 text-yellow-800';
    // ... más
  }
};
```

### Agregar Nuevas Acciones

En `ReceiptList.tsx`, agregar a `DropdownMenuContent`:

```tsx
<DropdownMenuItem onClick={() => handleNewAction(receipt)}>
  <Icon className="w-4 h-4 mr-2" />
  Nueva Acción
</DropdownMenuItem>
```

### Cambiar Columnas de la Tabla

En `ReceiptsPage.tsx`:

```tsx
<TableHeader>
  <TableRow>
    <TableHead>Número</TableHead>
    {/* Agregar más aquí */}
  </TableRow>
</TableHeader>
```

---

## 🔒 PERMISOS Y SEGURIDAD

Todos los componentes verifican el permiso `PAYMENTS_VIEW`.

```tsx
<Route element={<PermissionGuard permission="PAYMENTS_VIEW" />}>
  <Route path="receipts" element={<ReceiptsPage />} />
</Route>
```

Para cambiar el permiso, modificar en:
- `src/App.tsx` - Ruta
- `src/components/Sidebar.tsx` - Menú

---

## 📱 CARACTERÍSTICAS POR COMPONENTE

### ReceiptList

| Feature | Status |
|---------|--------|
| Ver recibos | ✅ |
| Descargar PDF | ✅ |
| Previsualizar HTML | ✅ |
| Imprimir | ✅ |
| Enviar email | ✅ |
| Filtros | ✅ |
| Búsqueda | ✅ |

### ReceiptCard

| Feature | Status |
|---------|--------|
| Mostrar datos | ✅ |
| Descargar PDF | ✅ |
| Ver detalles | ✅ |
| Modo compacto | ✅ |

### ReceiptsPage

| Feature | Status |
|---------|--------|
| Dashboard stats | ✅ |
| Tabla completa | ✅ |
| Filtros avanzados | ✅ |
| Búsqueda | ✅ |
| Acciones rápidas | ✅ |
| Preview modal | ✅ |
| Email modal | ✅ |

---

## ⚠️ CONSIDERACIONES

### Performance

- Las listas usan paginación (15 items por página por defecto)
- Los PDFs se generan bajo demanda
- Las previsulizaciones se cachean mientras esté abierto el modal

### Seguridad

- Solo usuarios autenticados pueden acceder
- Se valida el permiso `PAYMENTS_VIEW`
- Los PDFs se descargan del servidor validado

### Navegadores

- ✅ Chrome/Edge
- ✅ Firefox
- ✅ Safari
- ✅ Opera

---

## 🆘 TROUBLESHOOTING

### "Error cargando recibos"

**Causa:** Endpoint del backend no responde

**Solución:**
```bash
# Verificar que el backend está corriendo
curl http://localhost:8000/api/receipts -H "Authorization: Bearer {token}"
```

### "No hay recibos disponibles"

**Causa:** No hay datos en la base de datos

**Solución:**
```bash
# En el backend, ejecutar seeders
php artisan migrate:fresh --seed
```

### "Error descargando recibo"

**Causa:** El PDF no se puede generar

**Solución:**
- Verificar que DomPDF está instalado en backend
- Revisar logs en `storage/logs/laravel.log`

### "Error enviando email"

**Causa:** Configuración de email no está lista

**Solución:**
- Configurar variables de entorno en backend (.env)
- Revisar `config/mail.php` en backend

---

## 📦 DEPENDENCIAS

Frontend:
- React 18+
- TypeScript
- Tailwind CSS
- @phosphor-icons/react
- sonner (toasts)
- axios (api calls)

Backend (ya incluidas):
- Laravel 11
- barryvdh/laravel-dompdf
- Sanctum (auth)

---

## 🔄 FLUJO COMPLETO

```
Usuario hace clic en "Descargar Recibo"
  ↓
ReceiptList.handleDownloadReceipt() se ejecuta
  ↓
receiptsService.downloadReceiptPdf(id) llama API
  ↓
GET /api/receipts/{id}/download/receipt (backend)
  ↓
ReceiptController.downloadReceiptPdf() genera PDF
  ↓
Renderiza resources/views/pdfs/receipt.blade.php
  ↓
DomPDF convierte HTML a PDF binario
  ↓
Retorna blob PDF al frontend
  ↓
Frontend crea link de descarga
  ↓
Usuario descarga: recibo-123.pdf
```

---

## ✅ CHECKLIST DE PRUEBA

- [ ] Navegar a /admin/receipts
- [ ] Ver tabla de recibos
- [ ] Filtrar por estado (Pagado, Pendiente, etc.)
- [ ] Buscar por número de recibo
- [ ] Descargar recibo PDF
- [ ] Ver preview HTML
- [ ] Imprimir recibo
- [ ] Abrir email dialog
- [ ] Ver recibos de un cliente específico
- [ ] Generar factura desde recibo
- [ ] Descargar múltiples en lote

---

**Versión:** 1.0.0  
**Última actualización:** 15 de Febrero, 2026
