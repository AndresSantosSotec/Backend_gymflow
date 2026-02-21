<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket {{ $receipt->receipt_number }}</title>
    <style>
        @page {
            width: 80mm;
            margin: 2mm;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', 'DejaVu Sans Mono', monospace;
            font-size: 10px;
            width: 76mm;
            color: #000;
            line-height: 1.3;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .separator {
            text-align: center;
            letter-spacing: 2px;
            margin: 4px 0;
            font-size: 10px;
        }
        .double-sep {
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            padding: 2px 0;
            margin: 4px 0;
        }
        .company-name {
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            margin-bottom: 2px;
        }
        .line-item {
            width: 100%;
        }
        .line-item td {
            padding: 1px 0;
            font-size: 10px;
            vertical-align: top;
        }
        .line-item .desc { text-align: left; }
        .line-item .amt { text-align: right; white-space: nowrap; }
        .total-line td {
            padding: 3px 0;
            font-size: 13px;
            font-weight: bold;
        }
        .footer-msg {
            text-align: center;
            font-size: 9px;
            margin-top: 6px;
            padding-top: 4px;
        }
        .small { font-size: 8px; }
    </style>
</head>
<body>
    <div class="company-name">{{ $companyName }}</div>
    <div class="center small">{{ $companyAddress }}</div>
    <div class="center small">Tel: {{ $companyPhone }}</div>

    <div class="separator">================================</div>

    <div class="center bold">RECIBO #: {{ $receipt->receipt_number }}</div>
    <div class="center small">
        Fecha: {{ $receipt->created_at ? $receipt->created_at->format('d/m/Y H:i') : now()->format('d/m/Y H:i') }}
    </div>

    <div class="separator">--------------------------------</div>

    <div>
        <span class="bold">Cliente:</span>
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
            <td class="desc bold" colspan="2">Concepto</td>
            <td class="amt bold">Monto</td>
        </tr>
        <tr>
            <td class="desc" colspan="2">
                {{ ucfirst(str_replace('_', ' ', $receipt->payment_type ?? 'Pago')) }}
                @if($planName) - {{ $planName }} @endif
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

    <div>
        <span class="bold">Metodo pago:</span> {{ ucfirst(optional($receipt->payment)->payment_method ?? 'N/A') }}
    </div>
    <div>
        <span class="bold">Estado:</span>
        @if($receipt->status === 'paid')
            PAGADO
        @elseif($receipt->status === 'pending')
            PENDIENTE
        @else
            {{ strtoupper($receipt->status ?? 'N/A') }}
        @endif
    </div>

    @if($receipt->description)
    <div class="small" style="margin-top: 3px;">
        Nota: {{ $receipt->description }}
    </div>
    @endif

    <div class="separator">================================</div>

    <div class="footer-msg">
        Gracias por su preferencia!<br>
        {{ $companyName }}<br>
        <span class="small">{{ $companyEmail }}</span>
    </div>

    <div class="center small" style="margin-top: 4px;">
        ID: {{ $receipt->id }} | {{ $receipt->receipt_number }}
    </div>
</body>
</html>
