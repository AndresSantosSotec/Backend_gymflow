<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recibo {{ $receipt->receipt_number }}</title>
    <style>
        @page { margin: 20mm 15mm 15mm 15mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #222; font-size: 10px; line-height: 1.4; }
        .header { width: 100%; border-bottom: 2px solid #333; margin-bottom: 12px; padding-bottom: 8px; }
        .header td { vertical-align: top; }
        .company-name { font-size: 18px; font-weight: bold; margin-bottom: 2px; }
        .company-detail { font-size: 9px; color: #555; margin: 1px 0; }
        .receipt-info { text-align: right; }
        .receipt-info h2 { font-size: 16px; font-weight: bold; margin-bottom: 2px; }
        .receipt-info p { font-size: 9px; color: #555; }
        .client-box { width: 100%; margin-bottom: 10px; border: 1px solid #ccc; }
        .client-box td { padding: 8px 10px; vertical-align: top; width: 50%; font-size: 9px; }
        .client-box .lbl { font-size: 9px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
        .client-box p { margin: 2px 0; }
        .items { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .items th { background-color: #333; color: #fff; padding: 6px 8px; text-align: left; font-size: 9px; font-weight: bold; }
        .items td { padding: 6px 8px; border-bottom: 1px solid #ddd; font-size: 9px; }
        .items .r { text-align: right; }
        .totals-wrap { width: 100%; margin-bottom: 10px; }
        .totals { width: 220px; margin-left: auto; border-collapse: collapse; }
        .totals td { padding: 4px 8px; font-size: 10px; border-bottom: 1px solid #ddd; }
        .totals .lbl { font-weight: bold; }
        .totals .val { text-align: right; }
        .totals tr.grand td { border-top: 2px solid #333; border-bottom: 2px solid #333; font-size: 13px; font-weight: bold; padding: 6px 8px; }
        .status-line { padding: 6px 10px; margin-bottom: 8px; font-size: 9px; border-left: 3px solid #333; background: #f5f5f5; }
        .footer { border-top: 1px solid #ccc; padding-top: 8px; text-align: center; font-size: 8px; color: #888; }
    </style>
</head>
<body>
    @include('pdfs.partials.logo')
    <table class="header" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width:60%;">
                <div class="company-name">{{ $companyName }}</div>
                <p class="company-detail">{{ $companyAddress }}</p>
                <p class="company-detail">Tel: {{ $companyPhone }} | {{ $companyEmail }}</p>
            </td>
            <td class="receipt-info" style="width:40%;">
                <h2>RECIBO</h2>
                <p><strong>#{{ $receipt->receipt_number }}</strong></p>
                <p>Fecha: {{ $receipt->created_at ? $receipt->created_at->format('d/m/Y') : now()->format('d/m/Y') }}</p>
            </td>
        </tr>
    </table>

    <table class="client-box" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <div class="lbl">Cliente</div>
                <p><strong>{{ optional($receipt->client)->company_name ?? optional($receipt->client)->full_name ?? (optional($receipt->client)->first_name ? optional($receipt->client)->first_name . ' ' . optional($receipt->client)->last_name : 'Consumidor Final') }}</strong></p>
                <p>Tel: {{ optional($receipt->client)->phone ?? '-' }}</p>
                <p>Email: {{ optional($receipt->client)->email ?? '-' }}</p>
                @if(optional($receipt->client)->nit)
                <p>NIT: {{ optional($receipt->client)->nit }}</p>
                @else
                <p>DPI: {{ optional($receipt->client)->dni ?? '-' }}</p>
                @endif
                @if(optional($receipt->client)->fiscal_address)
                <p>Direcci&oacute;n Fiscal: {{ optional($receipt->client)->fiscal_address }}</p>
                @endif
            </td>
            <td>
                <div class="lbl">Detalle</div>
                <p>Tipo: <strong>{{ ucfirst(str_replace('_', ' ', $receipt->payment_type ?? 'Pago')) }}</strong></p>
                <p>Metodo: <strong>{{ ucfirst(optional($receipt->payment)->payment_method ?? 'N/A') }}</strong></p>
                @php
                    $planName = optional(optional($receipt->membership)->plan)->name;
                @endphp
                @if($planName)
                    <p>Plan: <strong>{{ $planName }}</strong></p>
                @endif
                <p>Estado: <strong>{{ $receipt->status === 'paid' ? 'Pagado' : ($receipt->status === 'pending' ? 'Pendiente' : ucfirst($receipt->status ?? '-')) }}</strong></p>
            </td>
        </tr>
    </table>

    @if($receipt->description)
    <div style="font-size:9px; padding:6px 10px; background:#f5f5f5; margin-bottom:8px; border-left:2px solid #333;">
        <strong>Concepto:</strong> {{ $receipt->description }}
    </div>
    @endif

    <table class="items">
        <thead>
            <tr>
                <th style="width:70%;">Concepto</th>
                <th class="r" style="width:30%;">Monto</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    {{ ucfirst(str_replace('_', ' ', $receipt->payment_type ?? 'Pago')) }}
                    @if($planName)
                        - {{ $planName }}
                    @endif
                    @if($receipt->description)
                        <br><small style="color:#666;">{{ $receipt->description }}</small>
                    @endif
                </td>
                <td class="r">Q{{ number_format($receipt->subtotal ?? 0, 2) }}</td>
            </tr>
            @if(($receipt->discount ?? 0) > 0)
            <tr>
                <td>Descuento</td>
                <td class="r">-Q{{ number_format($receipt->discount, 2) }}</td>
            </tr>
            @endif
            @if(($receipt->tax ?? 0) > 0)
            <tr>
                <td>IVA / Impuesto</td>
                <td class="r">Q{{ number_format($receipt->tax, 2) }}</td>
            </tr>
            @endif
        </tbody>
    </table>

    <div class="totals-wrap">
        <table class="totals">
            <tr>
                <td class="lbl">Subtotal:</td>
                <td class="val">Q{{ number_format($receipt->subtotal ?? 0, 2) }}</td>
            </tr>
            @if(($receipt->discount ?? 0) > 0)
            <tr>
                <td class="lbl">Descuento:</td>
                <td class="val">-Q{{ number_format($receipt->discount, 2) }}</td>
            </tr>
            @endif
            @if(($receipt->tax ?? 0) > 0)
            <tr>
                <td class="lbl">Impuesto:</td>
                <td class="val">Q{{ number_format($receipt->tax, 2) }}</td>
            </tr>
            @endif
            <tr class="grand">
                <td class="lbl">TOTAL:</td>
                <td class="val">Q{{ number_format($receipt->total ?? 0, 2) }}</td>
            </tr>
        </table>
    </div>

    <div class="status-line">
        @if($receipt->status === 'paid')
            <strong>PAGADO</strong>
            @if($receipt->paid_at)
                - {{ \Carbon\Carbon::parse($receipt->paid_at)->format('d/m/Y H:i') }}
            @endif
        @elseif($receipt->status === 'pending')
            <strong>PENDIENTE</strong> - Aguardando pago
        @else
            <strong>{{ strtoupper($receipt->status ?? 'N/A') }}</strong>
        @endif
    </div>

    <div class="footer">
        Gracias por su confianza | {{ $companyName }} | {{ $companyEmail }}<br>
        <small>Generado: {{ now()->format('d/m/Y H:i') }} | ID: {{ $receipt->id }} | {{ $receipt->receipt_number }}</small>
    </div>
</body>
</html>
