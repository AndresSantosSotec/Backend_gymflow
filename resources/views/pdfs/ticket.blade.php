<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=320">
    <title>Ticket {{ $receipt->receipt_number }}</title>
    <style>
        @page {
            size: 80mm auto;
            margin: 4mm 3mm;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', 'Arial', sans-serif;
            font-size: 14px;
            width: 74mm;
            min-width: 74mm;
            max-width: 74mm;
            color: #000;
            line-height: 1.5;
        }
        @media print {
            body { font-size: 14px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .company-name { font-size: 20px !important; }
            .total-line td { font-size: 18px !important; }
            .small { font-size: 12px !important; }
            .line-item td { font-size: 14px !important; }
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .separator {
            text-align: center;
            letter-spacing: 1px;
            margin: 6px 0;
            font-size: 13px;
            color: #333;
        }
        .company-name {
            font-size: 20px;
            font-weight: 900;
            text-align: center;
            margin-bottom: 3px;
            letter-spacing: 0.5px;
        }
        .receipt-title {
            font-size: 17px;
            font-weight: 900;
            text-align: center;
            letter-spacing: 1px;
            margin: 4px 0 2px;
        }
        .receipt-num {
            font-size: 13px;
            font-weight: bold;
            text-align: center;
        }
        .line-item {
            width: 100%;
        }
        .line-item td {
            padding: 3px 1px;
            font-size: 14px;
            vertical-align: top;
        }
        .line-item .desc { text-align: left; }
        .line-item .amt { text-align: right; white-space: nowrap; }
        .total-line td {
            padding: 5px 1px;
            font-size: 18px;
            font-weight: 900;
        }
        .footer-msg {
            text-align: center;
            font-size: 12px;
            margin-top: 8px;
            padding-top: 6px;
            line-height: 1.7;
        }
        .small { font-size: 12px; }
        .label { font-weight: bold; }
        .status-paid { font-weight: bold; }
        .status-pending { font-weight: bold; }
    </style>
</head>
<body>
    @if(!empty($logoBase64))
    <div class="center" style="margin-bottom:5px;">
        <img src="{{ $logoBase64 }}" alt="Logo" style="height:52px; max-width:74mm; object-fit:contain;" />
    </div>
    @else
    <div class="company-name">{{ $companyName }}</div>
    @endif

    <div class="center small">{{ $companyAddress }}</div>
    <div class="center small">Tel: {{ $companyPhone }}</div>

    <div class="separator">================================</div>

    <div class="receipt-title">RECIBO</div>
    <div class="receipt-num">#{{ $receipt->receipt_number }}</div>
    <div class="center small" style="margin-top:2px;">
        {{ $receipt->created_at ? $receipt->created_at->format('d/m/Y H:i') : now()->format('d/m/Y H:i') }}
    </div>

    <div class="separator">--------------------------------</div>

    <div>
        <span class="label">Cliente:</span>
        {{ $receipt->client->full_name ?? ($receipt->client->first_name . ' ' . $receipt->client->last_name) }}
    </div>
    @if($receipt->client->nit)
    <div class="small">NIT: {{ $receipt->client->nit }}</div>
    @endif
    @if($receipt->client->dni)
    <div class="small">DPI: {{ $receipt->client->dni }}</div>
    @endif

    <div class="separator">================================</div>

    @php
        $planName = optional(optional($receipt->membership)->plan)->name;
    @endphp

    <table class="line-item" cellpadding="0" cellspacing="0">
        <tr>
            <td class="desc label" colspan="2">Concepto</td>
            <td class="amt label">Monto</td>
        </tr>
        <tr>
            <td class="desc" colspan="2">
                {{ ucfirst(str_replace('_', ' ', $receipt->payment_type ?? 'Pago')) }}
                @if($planName)<br><span class="small">{{ $planName }}</span>@endif
            </td>
            <td class="amt">Q{{ number_format($receipt->subtotal ?? 0, 2) }}</td>
        </tr>
        @if(($receipt->discount ?? 0) > 0)
        <tr>
            <td class="desc" colspan="2">Descuento</td>
            <td class="amt">-Q{{ number_format($receipt->discount, 2) }}</td>
        </tr>
        @endif
        @if(($receipt->tax ?? 0) > 0)
        <tr>
            <td class="desc" colspan="2">IVA</td>
            <td class="amt">Q{{ number_format($receipt->tax, 2) }}</td>
        </tr>
        @endif
    </table>

    <div class="separator">--------------------------------</div>

    <table class="line-item" cellpadding="0" cellspacing="0">
        <tr class="total-line">
            <td class="desc">TOTAL:</td>
            <td class="amt">Q{{ number_format($receipt->total ?? 0, 2) }}</td>
        </tr>
    </table>

    <div class="separator">--------------------------------</div>

    <div><span class="label">Metodo:</span> {{ ucfirst(optional($receipt->payment)->payment_method ?? 'N/A') }}</div>
    <div>
        <span class="label">Estado:</span>
        @if($receipt->status === 'paid')
            <span class="status-paid">&#10003; PAGADO</span>
        @elseif($receipt->status === 'pending')
            <span class="status-pending">&#9679; PENDIENTE</span>
        @else
            {{ strtoupper($receipt->status ?? 'N/A') }}
        @endif
    </div>

    @if($receipt->description)
    <div class="small" style="margin-top:4px;">Nota: {{ $receipt->description }}</div>
    @endif

    <div class="separator">================================</div>

    <div class="footer-msg">
        <strong>Gracias por su preferencia!</strong><br>
        {{ $companyName }}<br>
        {{ $companyEmail }}
    </div>

    <div class="center small" style="margin-top:5px; color:#555;">
        ID: {{ $receipt->id }} | {{ $receipt->receipt_number }}
    </div>
</body>
</html>
