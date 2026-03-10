<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura {{ $receipt->invoice_number }}</title>
    <style>
        @page { margin: 18mm 15mm 15mm 15mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #222; font-size: 9px; line-height: 1.4; }
        .header { width: 100%; border-bottom: 2px solid #333; margin-bottom: 10px; padding-bottom: 6px; }
        .header td { vertical-align: top; }
        .company-name { font-size: 18px; font-weight: bold; margin-bottom: 2px; }
        .company-sub { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #555; }
        .company-detail { font-size: 8px; color: #555; margin: 1px 0; }
        .inv-box { background-color: #333; color: #fff; padding: 8px; text-align: center; margin-bottom: 6px; }
        .inv-box h2 { font-size: 18px; margin: 0; }
        .inv-meta { font-size: 8px; color: #555; text-align: right; }
        .inv-meta p { margin: 2px 0; }
        .parties { width: 100%; margin-bottom: 10px; }
        .parties td { width: 50%; vertical-align: top; padding: 0 3px; }
        .party { border: 1px solid #ccc; padding: 8px; font-size: 8px; }
        .party h3 { font-size: 9px; font-weight: bold; text-transform: uppercase; border-bottom: 1px solid #ccc; padding-bottom: 3px; margin-bottom: 4px; }
        .party p { margin: 2px 0; }
        .items { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .items th { background-color: #333; color: #fff; padding: 5px 6px; text-align: left; font-size: 8px; }
        .items td { padding: 5px 6px; border-bottom: 1px solid #ddd; font-size: 8px; }
        .items .r { text-align: right; }
        .items .c { text-align: center; }
        .totals-wrap { width: 100%; margin-bottom: 10px; }
        .totals { width: 240px; margin-left: auto; border-collapse: collapse; }
        .totals td { padding: 4px 6px; font-size: 9px; border-bottom: 1px solid #ddd; }
        .totals .lbl { font-weight: bold; }
        .totals .val { text-align: right; }
        .totals tr.grand td { border-top: 2px solid #333; border-bottom: 2px solid #333; font-size: 12px; font-weight: bold; padding: 5px 6px; }
        .info-box { padding: 6px 8px; margin-bottom: 6px; font-size: 8px; border-left: 3px solid #333; background: #f5f5f5; }
        .info-box h4 { font-size: 9px; font-weight: bold; margin-bottom: 3px; }
        .footer { border-top: 1px solid #ccc; padding-top: 6px; text-align: center; font-size: 7px; color: #888; }
    </style>
</head>
<body>
    @include('pdfs.partials.logo')
    <table class="header" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width:58%;">
                <div class="company-name">{{ $companyName }}</div>
                <p class="company-sub">Factura Electronica</p>
                <p class="company-detail"><strong>NIT:</strong> {{ $companyTax }}</p>
                <p class="company-detail">{{ $companyAddress }}</p>
                <p class="company-detail">Tel: {{ $companyPhone }} | {{ $companyEmail }}</p>
            </td>
            <td style="width:42%;">
                <div class="inv-box">
                    <h2>FACTURA</h2>
                </div>
                <div class="inv-meta">
                    <p><strong>No.:</strong> {{ $receipt->invoice_number }}</p>
                    <p><strong>Emision:</strong> {{ $receipt->invoiced_at ? \Carbon\Carbon::parse($receipt->invoiced_at)->format('d/m/Y') : now()->format('d/m/Y') }}</p>
                    <p><strong>Vence:</strong> {{ $receipt->invoiced_at ? \Carbon\Carbon::parse($receipt->invoiced_at)->addDays(30)->format('d/m/Y') : 'N/A' }}</p>
                    <p><strong>Estado:</strong> {{ strtoupper($receipt->status) }}</p>
                </div>
            </td>
        </tr>
    </table>

    <table class="parties" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <div class="party">
                    <h3>Empresa</h3>
                    <p><strong>{{ $companyName }}</strong></p>
                    <p>NIT: {{ $companyTax }}</p>
                    <p>{{ $companyAddress }}</p>
                    <p>{{ $companyPhone }}</p>
                </div>
            </td>
            <td>
                <div class="party">
                    <h3>Cliente</h3>
                    <p><strong>{{ $receipt->client->full_name ?? ($receipt->client->first_name . ' ' . $receipt->client->last_name) }}</strong></p>
                    @if($receipt->client->nit)
                    <p>NIT: {{ $receipt->client->nit }}</p>
                    @endif
                    <p>DPI: {{ $receipt->client->dni ?? '-' }}</p>
                    <p>Email: {{ $receipt->client->email ?? '-' }}</p>
                    <p>Tel: {{ $receipt->client->phone ?? '-' }}</p>
                    @if($receipt->client->fiscal_address)
                    <p>Dir. Fiscal: {{ $receipt->client->fiscal_address }}</p>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    @php
        $planName = optional(optional($receipt->membership)->plan)->name;
    @endphp

    <table class="items">
        <thead>
            <tr>
                <th style="width:50%;">Concepto</th>
                <th class="c" style="width:12%;">Cant.</th>
                <th class="r" style="width:19%;">Unitario</th>
                <th class="r" style="width:19%;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <strong>{{ ucfirst(str_replace('_', ' ', $receipt->payment_type ?? 'Pago')) }}</strong>
                    @if($planName) - {{ $planName }} @endif
                    <br><small style="color:#666;">{{ $receipt->description ?? 'Pago de servicios' }}</small>
                </td>
                <td class="c">1</td>
                <td class="r">Q{{ number_format($receipt->subtotal ?? 0, 2) }}</td>
                <td class="r">Q{{ number_format($receipt->subtotal ?? 0, 2) }}</td>
            </tr>
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
                <td class="lbl">IVA:</td>
                <td class="val">Q{{ number_format($receipt->tax, 2) }}</td>
            </tr>
            @endif
            <tr class="grand">
                <td class="lbl">TOTAL:</td>
                <td class="val">Q{{ number_format($receipt->total ?? 0, 2) }}</td>
            </tr>
        </table>
    </div>

    <div class="info-box">
        <h4>Pago</h4>
        <p>Metodo: {{ ucfirst(optional($receipt->payment)->payment_method ?? 'N/A') }}</p>
        <p>Estado:
            @if($receipt->status === 'paid')
                <strong>PAGADO</strong> {{ $receipt->paid_at ? '- ' . \Carbon\Carbon::parse($receipt->paid_at)->format('d/m/Y') : '' }}
            @else
                <strong>PENDIENTE</strong>
            @endif
        </p>
        @if($planName)
            <p>Plan: {{ $planName }}</p>
        @endif
    </div>

    @if($receipt->invoice_notes)
    <div class="info-box">
        <h4>Notas</h4>
        <p>{{ $receipt->invoice_notes }}</p>
    </div>
    @endif

    <div class="footer">
        {{ $companyName }} | NIT: {{ $companyTax }} | Factura electronica generada automaticamente<br>
        Generada: {{ now()->format('d/m/Y H:i') }} | ID: {{ $receipt->id }}<br>
        Gracias por su confianza | {{ $companyEmail }}
    </div>
</body>
</html>
