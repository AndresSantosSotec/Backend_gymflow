<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo {{ $receipt->receipt_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            border-bottom: 3px solid #1e40af;
            padding-bottom: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .company-info h1 {
            color: #1e40af;
            font-size: 28px;
            margin-bottom: 5px;
        }

        .company-info p {
            font-size: 12px;
            color: #666;
            margin: 2px 0;
        }

        .receipt-number {
            text-align: right;
        }

        .receipt-number h2 {
            color: #1e40af;
            font-size: 24px;
            margin-bottom: 5px;
        }

        .receipt-number p {
            font-size: 12px;
            color: #666;
        }

        .client-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }

        .client-section h3 {
            font-size: 13px;
            color: #1e40af;
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .client-section p {
            font-size: 12px;
            margin: 3px 0;
        }

        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .details-table thead {
            background-color: #1e40af;
            color: white;
        }

        .details-table th {
            padding: 12px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
        }

        .details-table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            font-size: 12px;
        }

        .details-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .text-right {
            text-align: right;
        }

        .totals-section {
            margin-left: auto;
            width: 300px;
            margin-bottom: 30px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            font-size: 13px;
            border-bottom: 1px solid #ddd;
        }

        .total-row.subtotal {
            font-weight: 600;
        }

        .total-row.tax {
            font-weight: 600;
        }

        .total-row.discount {
            color: #28a745;
            font-weight: 600;
        }

        .total-row.final {
            border-bottom: 2px solid #1e40af;
            border-top: 2px solid #1e40af;
            font-size: 16px;
            font-weight: bold;
            color: #1e40af;
            padding: 12px 0;
        }

        .payment-info {
            background-color: #e7f3ff;
            border-left: 4px solid #1e40af;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 3px;
            font-size: 12px;
        }

        .payment-info h4 {
            color: #1e40af;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .footer {
            border-top: 2px solid #ddd;
            padding-top: 20px;
            margin-top: 30px;
            text-align: center;
            font-size: 11px;
            color: #666;
        }

        .stamp {
            opacity: 0.1;
            font-size: 60px;
            text-align: center;
            margin: 20px 0;
            transform: rotate(-15deg);
            color: #ccc;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .status-badge.paid {
            background-color: #d4edda;
            color: #155724;
        }

        .status-badge.pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-badge.draft {
            background-color: #e2e3e5;
            color: #383d41;
        }

        .description {
            font-size: 12px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 3px solid #1e40af;
        }

        .description h4 {
            color: #1e40af;
            margin-bottom: 8px;
            font-size: 12px;
        }

        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            .container {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                <h1>{{ $companyName }}</h1>
                <p><strong>Dirección:</strong> {{ $companyAddress }}</p>
                <p><strong>Teléfono:</strong> {{ $companyPhone }}</p>
                <p><strong>Email:</strong> {{ $companyEmail }}</p>
            </div>
            <div class="receipt-number">
                <h2>RECIBO</h2>
                <p><strong>#{{ $receipt->receipt_number }}</strong></p>
                <p>Fecha: {{ $receipt->created_at->format('d/m/Y H:i') }}</p>
                <div class="status-badge {{ strtolower($receipt->status) }}">
                    {{ ucfirst($receipt->status) }}
                </div>
            </div>
        </div>

        <!-- Cliente -->
        <div class="client-section">
            <div>
                <h3>Facturado a:</h3>
                <p><strong>{{ $receipt->client->full_name ?? 'No especificado' }}</strong></p>
                <p>Email: {{ $receipt->client->email ?? 'No disponible' }}</p>
                <p>Teléfono: {{ $receipt->client->phone ?? 'No disponible' }}</p>
                <p>DNI/Cédula: {{ $receipt->client->dni ?? 'No disponible' }}</p>
            </div>
            <div>
                <h3>Información del Recibo:</h3>
                <p>Tipo: <strong>{{ ucfirst(str_replace('_', ' ', $receipt->payment_type)) }}</strong></p>
                <p>Método de Pago: <strong>{{ ucfirst($receipt->payment->payment_method ?? 'N/A') }}</strong></p>
                @if($receipt->membership)
                    <p>Plan: <strong>{{ $receipt->membership->name ?? 'N/A' }}</strong></p>
                @endif
                @if($receipt->paid_at)
                    <p>Pagado: <strong>{{ $receipt->paid_at->format('d/m/Y') }}</strong></p>
                @endif
            </div>
        </div>

        <!-- Descripción si existe -->
        @if($receipt->description)
        <div class="description">
            <h4>Concepto:</h4>
            <p>{{ $receipt->description }}</p>
        </div>
        @endif

        <!-- Detalles -->
        <table class="details-table">
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th class="text-right">Monto</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Subtotal</td>
                    <td class="text-right">${{ number_format($receipt->subtotal, 2) }}</td>
                </tr>
                @if($receipt->discount > 0)
                <tr>
                    <td>Descuento</td>
                    <td class="text-right">-${{ number_format($receipt->discount, 2) }}</td>
                </tr>
                @endif
                @if($receipt->tax > 0)
                <tr>
                    <td>Impuesto/IVA</td>
                    <td class="text-right">${{ number_format($receipt->tax, 2) }}</td>
                </tr>
                @endif
            </tbody>
        </table>

        <!-- Totales -->
        <div class="totals-section">
            <div class="total-row subtotal">
                <span>Subtotal:</span>
                <span>${{ number_format($receipt->subtotal, 2) }}</span>
            </div>
            @if($receipt->discount > 0)
            <div class="total-row discount">
                <span>Descuento:</span>
                <span>-${{ number_format($receipt->discount, 2) }}</span>
            </div>
            @endif
            @if($receipt->tax > 0)
            <div class="total-row tax">
                <span>Impuesto:</span>
                <span>${{ number_format($receipt->tax, 2) }}</span>
            </div>
            @endif
            <div class="total-row final">
                <span>TOTAL:</span>
                <span>${{ number_format($receipt->total, 2) }}</span>
            </div>
        </div>

        <!-- Información de Pago -->
        <div class="payment-info">
            <h4>Estado del Pago:</h4>
            <p>
                @switch($receipt->status)
                    @case('paid')
                        <strong style="color: #28a745;">✓ PAGADO</strong> - {{ $receipt->paid_at->format('d/m/Y H:i') }}
                        @break
                    @case('pending')
                        <strong style="color: #ffc107;">⧗ PENDIENTE</strong> - Aguardando pago
                        @break
                    @case('draft')
                        <strong style="color: #6c757d;">◐ BORRADOR</strong> - No emitido
                        @break
                    @default
                        <strong style="color: #dc3545;">✗ CANCELADO</strong>
                @endswitch
            </p>
        </div>

        <!-- Notas y Footer -->
        <div class="footer">
            <p style="margin-bottom: 20px;">
                <strong>Gracias por su confianza</strong>
            </p>
            <p>
                Este recibo es comprobante de la transacción realizada.<br>
                Para consultas, contáctenos a {{ $companyEmail }}
            </p>
            <p style="margin-top: 15px; font-size: 10px; color: #999;">
                Generado automáticamente el {{ now()->format('d/m/Y H:i:s') }}<br>
                ID Sistema: {{ $receipt->id }} | Recibo: {{ $receipt->receipt_number }}
            </p>
        </div>
    </div>
</body>
</html>
