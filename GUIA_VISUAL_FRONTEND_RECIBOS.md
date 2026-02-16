# 🎨 GUÍA VISUAL - CÓMO USAR RECIBOS DESDE EL FRONTEND

**Documento:** Tutorial paso a paso con screenshots conceptuales  
**Versión:** 1.0.0  
**Fecha:** 15 de Febrero, 2026

---

## 📍 DÓNDE ENCONTRAR LOS RECIBOS

### Opción 1: Página Principal de Recibos

```
En el navegador de arriba del admin:
1. Haz clic en el menú lateral izquierdo
2. Baja hasta encontrar: 📄 Recibos y Facturas
3. Haz clic

URL: http://localhost:5173/admin/receipts
```

### Opción 2: Desde el Perfil de un Cliente

```
1. Ve a: 👥 Clientes (en menú lateral)
2. Selecciona un cliente
3. Baja hasta la sección: "Recibos y Facturas"
4. Verás todos sus recibos ahí
```

---

## 🚀 CÓMO DESCARGAR UN RECIBO

### Paso 1: Ir a Recibos
```
http://localhost:5173/admin/receipts
```

### Paso 2: Buscar el Recibo
```
┌──────────────────────────────────┐
│ Buscar: [REC-2026-001__________] │  ← Escribe el número
└──────────────────────────────────┘

O simplemente desplázate por la tabla
```

### Paso 3: Hacer Clic en el Menú
```
Busca la fila del recibo → Al final verás: ⋯ (tres puntos)
┌─────────────────────────────────────┐
│ ⋯ (Click AQUÍ)                      │
│  ├─ 👁 Ver Recibo                   │
│  ├─ ⬇ Descargar Recibo PDF  ◄─ AQUÍ│
│  ├─ ✉ Enviar por Email             │
│  └─ ...                             │
└─────────────────────────────────────┘
```

### Paso 4: Se Descargará
```
Tu navegador mostrará:
┌─────────────────────────────────────┐
│ Descargar: REC-2026-001.pdf        │
│ [Descargar] [Cancelar]              │
└─────────────────────────────────────┘

Se guardará en tu carpeta de Descargas
```

### Resultado
```
Tu computadora → Carpeta Descargas → REC-2026-001.pdf ✅
```

---

## 🖨️ CÓMO IMPRIMIR UN RECIBO

### Método Visual

```
1. Ve a Recibos
2. Haz clic en el menú (⋯) de un recibo
3. Selecciona: 👁 Ver Recibo
4. Se abre un modal con el recibo
5. Haz clic en: 🖨️ Imprimir
6. Se abre el diálogo de impresoras
7. Selecciona tu impresora y haz clic en "Imprimir"
```

### Visualización

```
┌─────────────────────────────────────────┐
│ REC-2026-001 - Juan Medina         X   │
├─────────────────────────────────────────┤
│ [🖨️ Imprimir] [Cerrar]                 │
├─────────────────────────────────────────┤
│                                         │
│  ┌─────────────────────────────────┐   │
│  │                                 │   │
│  │      RECIBO DE PAGO             │   │
│  │                                 │   │
│  │  Recibo #: REC-2026-001         │   │
│  │  Fecha:    15 de Febrero, 2026 │   │
│  │  Cliente:  Juan Medina Pérez    │   │
│  │                                 │   │
│  │  Concepto: Membresía Mensual    │   │
│  │  Monto:    Q 500.00             │   │
│  │  Impuesto: Q  80.00             │   │
│  │  ─────────────────────────────  │   │
│  │  TOTAL:    Q 580.00             │   │
│  │                                 │   │
│  └─────────────────────────────────┘   │
│                                         │
└─────────────────────────────────────────┘
```

---

## ✉️ CÓMO ENVIAR RECIBO POR EMAIL

### Paso a Paso

```
1. En la tabla de recibos, haz clic en ⋯ menu
2. Selecciona: ✉️ Enviar por Email
3. Se abre un modal:

┌─────────────────────────────────┐
│ Enviar Recibo por Email    X    │
│ REC-2026-001 - Juan Medina      │
├─────────────────────────────────┤
│ Correo Electrónico:             │
│ [Cliente@example.com__________] │  ← Se llena automático
│                                 │
│ Mensaje (Opcional):             │
│ [Adjunto recibo mensual...... ] │
│ [                      ......] │
│ [                      ......] │
│                                 │
│ [Cancelar] [✉️ Enviar]          │ ← Haz clic
└─────────────────────────────────┘

4. Se enviará El PDF por email
5. Verás un mensaje: ✓ Recibo enviado por correo
```

---

## 🔍 CÓMO FILTRAR RECIBOS

### Por Estado (Pagado, Pendiente, etc.)

```
En la sección de Filtros:

┌─────────────────────────────────┐
│ Estado [Todos ▼]  ← Haz clic   │
├─────────────────────────────────┤
│ ✓ Todos                         │
│ • Borrador                      │
│ • Pendiente                     │
│ • Pagado                        │
│ • Cancelado                     │
└─────────────────────────────────┘

Selecciona "Pagado" → Tabla se actualiza
Solo muestra recibos pagados ✓
```

### Por Tipo de Pago

```
Tipo de Pago [Todos ▼]  ← Haz clic

Se verán opciones:
- Todos
- Membresía
- Pago Individual
- Curso
- Producto
```

### Por Facturación

```
Facturación [Todos ▼]

- Todos (muestra todo)
- Facturado (solo INV-XXX)
- No Facturado (solo REC-XXX)
```

### Por Búsqueda

```
Buscar [___________________]

Escribe:
- Número de recibo: REC-2026-001
- Número de factura: INV-2026-001
- Nombre de cliente: Juan Medina
```

---

## 📊 CÓMO VER ESTADÍSTICAS

En la página principal de recibos verás:

```
┌──────────────────────────────────────────────────────┐
│ ESTADÍSTICAS                                         │
├──────────────────────────────────────────────────────┤
│
│ ┌──────────────┐  ┌──────────────┐  ┌──────────────┐ │
│ │ Total        │  │ Pagados      │  │ Pendientes   │ │
│ │ 150 recibos  │  │ 120 recibos  │  │ 30 recibos   │ │
│ └──────────────┘  └──────────────┘  └──────────────┘ │
│
│ ┌──────────────┐                                      │
│ │ Facturados   │                                      │
│ │ 45 recibos   │                                      │
│ └──────────────┘                                      │
│                                                       │
└──────────────────────────────────────────────────────┘
```

---

## 👥 CÓMO VER RECIBOS DE UN CLIENTE

### Desde Cliente Individual

```
1. Ve a 👥 Clientes (menú lateral)
2. Haz clic en un cliente
3. Baja en la página
4. Encontrarás sección: "Recibos y Facturas"
5. Verá todos los recibos del cliente con:

┌───────────────────────────────────────────┐
│ RECIBOS DEL CLIENTE                        │
│                                            │
│ [Total: 5] [Pagados: 4] [Pendientes: 1] │
│                                            │
│ ┌ REC-2026-001 │ Q500.00 │ ✓ Pagado │   │
│ ├ REC-2026-002 │ Q750.00 │ ⏳ Pendiente │
│ ├ REC-2026-003 │ Q500.00 │ ✓ Pagado │   │
│ ├ INV-2026-001 │ Q1000.00│ ✓ Pagado │   │
│ └ REC-2026-004 │ Q500.00 │ ✓ Pagado │   │
│                                            │
│ [⬇ Descargar] [👁 Ver] [✉ Email] ...    │
└───────────────────────────────────────────┘
```

---

## 🎫 CÓMO GENERAR UNA FACTURA

```
Diferencia importante:
- RECIBO (REC-XXX) = Comprobante de pago
- FACTURA (INV-XXX) = Documento fiscal oficial
```

### Proceso

```
1. Busca un recibo que NO esté facturado
2. Haz clic en el menú (⋯)
3. Selecciona: 📋 Generar Factura y Descargar
4. Se abre un modal:

┌──────────────────────────────────────────┐
│ Generar Factura                     X    │
├──────────────────────────────────────────┤
│ Se marcará como facturado:                │
│ REC-2026-001 → INV-2026-001              │
│                                          │
│ Notas Adicionales (Opcional):            │
│ [Factura complementaria .............]   │
│ [......................................] │
│                                          │
│ [Cancelar] [📋 Generar y Descargar]     │
└──────────────────────────────────────────┘

5. Se genera el PDF de factura
6. Se descarga automáticamente
7. La tabla se actualiza → Ahora marca como FACTURADO
```

### Antes vs Después

```
ANTES:
┌─────────────────────────────────┐
│ REC-2026-001 │ Q500 │ Pendiente │
│ Facturado: - │      │           │
└─────────────────────────────────┘

DESPUÉS:
┌─────────────────────────────────┐
│ INV-2026-001 │ Q500 │ Pagado    │
│ Facturado: INV-2026-001          │
└─────────────────────────────────┘
```

---

## 💡 CONSEJOS Y TRUCOS

### Truco 1: Descargar Múltiples Recibos
```
Aunque no está en la UI principal, desde el API:
POST /api/receipts/bulk-download
Body: { "ids": [1, 2, 3, 4, 5] }

Te descargará un JSON con todos los archivos
```

### Truco 2: Previsualizar sin Abrir PDF
```
Algunos navegadores lentosComprueba hacer click en 👁 Ver
Se abre HTML en modal sin generar PDF
Más rápido para múltiples recibos
```

### Truco 3: Imprimir Múltiples
```
1. Abre en pestañas diferentes los recibos
2. En cada pestaña: 👁 Ver → 🖨️ Imprimir
3. Imprime todos a la vez
```

### Truco 4: Compartir Recibos
```
Descarga el PDF → Comparte por WhatsApp, email, etc.
Usa el botón ✉️ Enviar por Email para automatizar
```

---

## 🔐 PERMISOS Y QUIÉN VE QUÉ

### Quien Puede Acceder

```
Necesitas permiso: PAYMENTS_VIEW

Roles con este permiso pueden:
✅ Ver lista completa de recibos
✅ Descargar PDFs
✅ Ver previsualizaciones
✅ Enviar por email
✅ Generar facturas
```

### Como Cliente (Frontend Público)

```
Los clientes NO ven recibos directamente en el frontend público.
Solo desde el admin (requiere login).

Futuro: Se puede agregar portal de cliente para esto.
```

---

## 🆘 PROBLEMAS COMUNES

### "No veo el botón de Recibos"
```
❌ Solución 1: Actualiza el navegador (Ctrl+F5)
❌ Solución 2: Verifica que tengas permiso PAYMENTS_VIEW
❌ Solución 3: Recarga la aplicación completamente
```

### "No se descarga el PDF"
```
✅ Asegúrate que el backend está corriendo
✅ Verifica tu conexión a internet
✅ Prueba con otro navegador
✅ Desactiva bloqueadores de anuncios
```

### "Error al enviar email"
```
⚠️ El backend aún no tiene email configurado
📝 Esto se puede hacer después (próximo paso)
⚡ Por ahora solo funciona visual desde el admin
```

### "Los datos no se actualizan"
```
🔄 Intenta recargar la página (F5)
🔄 Borra caché del navegador (Ctrl+Shift+Del)
🔄 Cierra y reabre la pestaña
```

---

## 📱 ACCESO RÁPIDO

### URLs Útiles
```
Página de Recibos:
http://localhost:5173/admin/receipts

Perfil de Cliente (con recibos):
http://localhost:5173/admin/clients/{id}

Backend API:
http://localhost:8000/api/receipts
```

### Atajos de Teclado
```
F5 → Recargar página
Ctrl+P → Imprimir página (desde modal)
Ctrl+S → Guardar/Descargar
Ctrl+Shift+Del → Limpiar caché
```

---

## ✅ CHECKLIST DE FUNCIONALIDAD

Verifica que todo funciona:

- [ ] Puedo ver la página de recibos en /admin/receipts
- [ ] Veo los recibos en una tabla
- [ ] Puedo descargar recibos en PDF
- [ ] Puedo ver preview HTML
- [ ] Puedo imprimir desde el preview
- [ ] Puedo filtrar por estado
- [ ] Puedo buscar por número
- [ ] Puedo ver recibos de un cliente específico
- [ ] Generas factura genera el PDF
- [ ] Veo estadísticas en la parte superior
- [ ] El menú lateral muestra "Recibos y Facturas"

---

## 🎓 PRÓXIMO PASO

Una vez que todo funcione visualmente, el próximo paso es:

```
1. ✅ COMPLETADO - Visualizar recibos en frontend
2. ⏳ SIGUIENTE - Configurar email real (Mailtrap, SendGrid)
3. ⏳ SIGUIENTE - Integrar Facturama para facturas electrónicas
4. ⏳ SIGUIENTE - Portal de cliente para ver sus recibos
```

---

**Versión:** 1.0.0  
**Última actualización:** 15 de Febrero, 2026
