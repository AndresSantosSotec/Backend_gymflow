<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura {{ $receipt->invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            color: #222;
            line-height: 1.5;
            background-color: #fff;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
            background-color: white;
        }

        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 120px;
            opacity: 0.05;
            z-index: -1;
            color: #1e40af;
        }

        .header-invoice {
            display: grid;
            grid-template-columns: 60% 40%;
            gap: 30px;
            border-bottom: 3px solid #1e40af;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }

        .company-logo-section h1 {
            color: #1e40af;
            font-size: 32px;
            margin-bottom: 5px;
            font-weight: 900;
        }

        .company-logo-section .subtitle {
            color: #666;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .company-details {
            font-size: 11px;
            color: #555;
            margin-top: 10px;
            line-height: 1.6;
        }

        .invoice-details {
            text-align: right;
        }

        .invoice-type {
            background-color: #1e40af;
            color: white;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 10px;
        }

        .invoice-type h2 {
            font-size: 28px;
            margin: 0 0 5px 0;
        }

        .invoice-meta {
            font-size: 11px;
            color: #666;
            line-height: 1.8;
        }

        .invoice-meta strong {
            display: inline-block;
            width: 80px;
        }

        .parties-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
            font-size: 11px;
        }

        .party-box {
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
            background-color: #f9f9f9;
        }

        .party-box h3 {
            color: #1e40af;
            font-size: 12px;
            margin-bottom: 10px;
            text-transform: uppercase;
            border-bottom: 2px solid #1e40af;
            padding-bottom: 8px;
        }

        .party-box p {
            margin: 5px 0;
            line-height: 1.5;
        }

        .party-box strong {
            color: #222;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            font-size: 11px;
        }

        .items-table thead {
            background-color: #1e40af;
            color: white;
        }

        .items-table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border: 1px solid #1e40af;
        }

        .items-table td {
            padding: 12px;
            border: 1px solid #ddd;
            vertical-align: middle;
        }

        .items-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .totals-box {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 30px;
        }

        .totals-table {
            width: 350px;
            font-size: 11px;
        }

        .totals-table .row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #ddd;
        }

        .totals-table .row.total {
            border-top: 2px solid #1e40af;
            border-bottom: 2px solid #1e40af;
            font-weight: 900;
            font-size: 14px;
            color: #1e40af;
            padding: 12px 0;
            background-color: #f0f5ff;
        }

        .totals-table .row.subtotal,
        .totals-table .row.tax,
        .totals-table .row.discount {
            font-weight: 600;
        }

        .payment-terms {
            background-color: #e7f3ff;
            border-left: 4px solid #1e40af;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 3px;
            font-size: 11px;
        }

        .payment-terms h4 {
            color: #1e40af;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 600;
        }

        .payment-terms p {
            margin: 5px 0;
        }

        .notes-section {
            background-color: #f9f9f9;
            border-left: 4px solid #666;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 3px;
            font-size: 11px;
        }

        .notes-section h4 {
            color: #333;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 600;
        }

        .footer-invoice {
            border-top: 2px solid #ddd;
            padding-top: 20px;
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
            line-height: 1.6;
        }

        .qr-section {
            text-align: center;
            margin: 20px 0;
            font-size: 10px;
        }

        .document-info {
            background-color: #f0f5ff;
            border: 1px solid #1e40af;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 3px;
            font-size: 10px;
            color: #1e40af;
        }

        .document-info strong {
            color: #1e40af;
        }

        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            .container {
                max-width: 100%;
                padding: 0;
            }
            .watermark {
                display: block;
            }
        }
    </style>
</head>
<body>
    <div class="watermark">FACTURA</div>

    <div class="container">
        <!-- Header -->
        <div class="header-invoice">
            <div class="company-logo-section">
                <h1>{{ $companyName }}</h1>
                <p class="subtitle">Factura Electrónica</p>
                <div class="company-details">
                    <p><strong>RFC/Impuesto:</strong> {{ $companyTax }}</p>
                    <p><strong>Dirección:</strong> {{ $companyAddress }}</p>
                    <p><strong>Teléfono:</strong> {{ $companyPhone }}</p>
                    <p><strong>Email:</strong> {{ $companyEmail }}</p>
                </div>
            </div>

            <div class="invoice-details">
                <div class="invoice-type">
                    <h2>FACTURA</h2>
                    <p style="margin: 0;">Electrónica</p>
                </div>

                <div class="invoice-meta">
                    <p><strong>No. Factura:</strong> {{ $receipt->invoice_number }}</p>
                    <p><strong>Fecha Emisión:</strong> {{ $receipt->invoiced_at->format('d/m/Y') }}</p>
                    <p><strong>Fecha Vencimiento:</strong> {{ $receipt->invoiced_at->addDays(30)->format('d/m/Y') }}</p>
                    <p><strong>Estado:</strong> <span style="color: #28a745; font-weight: bold;">✓ {{ strtoupper($receipt->status) }}</span></p>
                </div>
            </div>
        </div>

        <!-- Partes (Empresa y Cliente) -->
        <div class="parties-section">
            <div class="party-box">
                <h3>Empresa Proveedora</h3>
                <p><strong>Razón Social:</strong> {{ $companyName }}</p>
                <p><strong>RFC:</strong> {{ $companyTax }}</p>
                <p><strong>Domicilio:</strong> {{ $companyAddress }}</p>
                <p><strong>Teléfono:</strong> {{ $companyPhone }}</p>
                <p><strong>Email:</strong> {{ $companyEmail }}</p>
            </div>

            <div class="party-box">
                <h3>Cliente / Receptor</h3>
                <p><strong>Nombre:</strong> {{ $receipt->client->full_name ?? 'No especificado' }}</p>
                <p><strong>DNI/RFC:</strong> {{ $receipt->client->dni ?? 'No disponible' }}</p>
                <p><strong>Email:</strong> {{ $receipt->client->email ?? 'No disponible' }}</p>
                <p><strong>Teléfono:</strong> {{ $receipt->client->phone ?? 'No disponible' }}</p>
                <p><strong>Dirección:</strong> {{ $receipt->client->address ?? 'No disponible' }}</p>
            </div>
        </div>

        <!-- Items de la Factura -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 50%;">Concepto</th>
                    <th style="width: 15%;" class="text-center">Cantidad</th>
                    <th style="width: 17%;" class="text-right">Valor Unitario</th>
                    <th style="width: 18%;" class="text-right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>{{ ucfirst(str_replace('_', ' ', $receipt->payment_type)) }}</strong><br>
                        <small>{{ $receipt->description ?? 'Pago de servicios' }}</small>
                    </td>
                    <td class="text-center">1</td>
                    <td class="text-right">${{ number_format($receipt->subtotal, 2) }}</td>
                    <td class="text-right">${{ number_format($receipt->subtotal, 2) }}</td>
                </tr>
            </tbody>
        </table>

        <!-- Totales -->
        <div class="totals-box">
            <div class="totals-table">
                <div class="row subtotal">
                    <span>Subtotal:</span>
                    <span>${{ number_format($receipt->subtotal, 2) }}</span>
                </div>
                @if($receipt->discount > 0)
                <div class="row">
                    <span style="color: #28a745;">Descuento (-):</span>
                    <span style="color: #28a745;">-${{ number_format($receipt->discount, 2) }}</span>
                </div>
                @endif
                @if($receipt->tax > 0)
                <div class="row tax">
                    <span>IVA / Impuesto (+):</span>
                    <span>${{ number_format($receipt->tax, 2) }}</span>
                </div>
                @endif
                <div class="row total">
                    <span>TOTAL A PAGAR:</span>
                    <span>${{ number_format($receipt->total, 2) }}</span>
                </div>
            </div>
        </div>

        <!-- Información de Pago -->
        <div class="payment-terms">
            <h4>Condiciones de Pago</h4>
            <p><strong>Método de Pago:</strong> {{ ucfirst($receipt->payment->payment_method ?? 'No especificado') }}</p>
            <p><strong>Estado de Pago:</strong>
                @if($receipt->status === 'paid')
                    <span style="color: #28a745; font-weight: bold;">✓ PAGADO el {{ $receipt->paid_at->format('d/m/Y') }}</span>
                @elseif($receipt->status === 'pending')
                    <span style="color: #ffc107; font-weight: bold;">⧗ PENDIENTE - Fecha vencimiento: {{ $receipt->invoiced_at->addDays(30)->format('d/m/Y') }}</span>
                @endif
            </p>
            @if($receipt->membership)
            <p><strong>Membresía/Plan:</strong> {{ $receipt->membership->name ?? 'N/A' }}</p>
            @endif
        </div>

        <!-- Notas Fiscales -->
        @if($receipt->invoice_notes)
        <div class="notes-section">
            <h4>Notas:</h4>
            <p>{{ $receipt->invoice_notes }}</p>
        </div>
        @endif

        <!-- Información del Documento -->
        <div class="document-info">
            <strong>📄 Información del Documento</strong><br>
            Factura Electrónica Generada Automáticamente<br>
            Sistema: GymFlow | ID Factura: {{ $receipt->id }}<br>
            Generada: {{ now()->format('d/m/Y H:i:s') }}
        </div>

        <!-- Footer -->
        <div class="footer-invoice">
            <p style="margin-bottom: 15px; font-weight: 600;">
                {{ $companyName }}<br>
                RFC: {{ $companyTax }}
            </p>
            <p>
                Esta factura es comprobante fiscal electrónico válido.<br>
                Para verificar su autenticidad, visite: www.gymflow.local/verify
            </p>
            <p style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                Gracias por su confianza y preferencia<br>
                Para consultas: {{ $companyEmail }}
            </p>
        </div>
    </div>
</body>
</html>
