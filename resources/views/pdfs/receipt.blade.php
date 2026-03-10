<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recibo {{ $receipt->receipt_number }}</title>
    <style>
        /* ─── Página ─────────────────────────────────────────────────────── */
        @page {
            size: letter;
            margin: 18mm 16mm 14mm 16mm;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }

        /* ─── Tipografía base — tamaño aumentado para impresión legible ── */
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            color: #111;
            font-size: 12px;        /* base 10 → 12 */
            line-height: 1.55;
        }

        /* ─── Cabecera ───────────────────────────────────────────────────── */
        .header {
            width: 100%;
            border-bottom: 2.5px solid #111;
            margin-bottom: 14px;
            padding-bottom: 10px;
        }
        .header td { vertical-align: middle; }
        .company-name {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 2px;
            letter-spacing: -0.3px;
        }
        .company-detail { font-size: 11px; color: #444; margin: 2px 0; }

        /* ─── Caja de número de recibo ───────────────────────────────────── */
        .receipt-badge {
            text-align: right;
        }
        .receipt-badge .receipt-title {
            font-size: 26px;
            font-weight: 900;
            letter-spacing: 1px;
            color: #111;
            line-height: 1;
        }
        .receipt-badge .receipt-num {
            font-size: 14px;
            font-weight: bold;
            color: #333;
            margin-top: 3px;
        }
        .receipt-badge .receipt-date {
            font-size: 11px;
            color: #555;
            margin-top: 2px;
        }

        /* ─── Bloque cliente / detalle ───────────────────────────────────── */
        .client-box {
            width: 100%;
            margin-bottom: 14px;
            border: 1.5px solid #bbb;
            border-radius: 3px;
        }
        .client-box td {
            padding: 10px 12px;
            vertical-align: top;
            width: 50%;
            font-size: 11px;
        }
        .client-box td + td {
            border-left: 1px solid #ddd;
        }
        .client-box .lbl {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 4px;
            color: #444;
        }
        .client-box p { margin: 3px 0; }
        .client-box strong { color: #111; }

        /* ─── Tabla de ítems ─────────────────────────────────────────────── */
        .items { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .items th {
            background-color: #222;
            color: #fff;
            padding: 8px 10px;
            text-align: left;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .items td {
            padding: 8px 10px;
            border-bottom: 1px solid #ddd;
            font-size: 11px;
            vertical-align: top;
        }
        .items tbody tr:last-child td { border-bottom: none; }
        .items .r { text-align: right; white-space: nowrap; }
        .items tbody tr:nth-child(even) td { background: #fafafa; }

        /* ─── Totales ────────────────────────────────────────────────────── */
        .totals-wrap { width: 100%; margin-bottom: 14px; }
        .totals {
            width: 260px;
            margin-left: auto;
            border-collapse: collapse;
            border: 1px solid #ddd;
        }
        .totals td { padding: 6px 10px; font-size: 12px; border-bottom: 1px solid #eee; }
        .totals .lbl { font-weight: bold; color: #333; }
        .totals .val { text-align: right; }
        .totals tr:last-child td { border-bottom: none; }
        .totals tr.grand td {
            border-top: 2.5px solid #111;
            font-size: 16px;
            font-weight: 900;
            padding: 8px 10px;
            color: #111;
            background: #f5f5f5;
        }

        /* ─── Estado de pago ─────────────────────────────────────────────── */
        .status-line {
            padding: 8px 12px;
            margin-bottom: 12px;
            font-size: 12px;
            font-weight: bold;
            border-left: 4px solid #111;
            background: #f0f0f0;
            letter-spacing: 0.3px;
        }
        .status-paid   { border-color: #16a34a; background: #f0fdf4; color: #15803d; }
        .status-pending{ border-color: #ca8a04; background: #fefce8; color: #a16207; }

        /* ─── Pie ────────────────────────────────────────────────────────── */
        .footer {
            border-top: 1px solid #ccc;
            padding-top: 8px;
            text-align: center;
            font-size: 10px;
            color: #777;
            line-height: 1.6;
        }
    </style>
</head>
<body>

    {{-- ════ CABECERA ════ --}}
    <table class="header" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width:58%;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}"
                         alt="{{ $companyName }}"
                         style="height:64px; max-width:220px; object-fit:contain; display:block; margin-bottom:5px;" />
                @else
                    <div class="company-name">{{ $companyName }}</div>
                @endif
                <p class="company-detail">{{ $companyAddress }}</p>
                <p class="company-detail">Tel: {{ $companyPhone }}</p>
                <p class="company-detail">{{ $companyEmail }}</p>
            </td>
            <td class="receipt-badge" style="width:42%;">
                <div class="receipt-title">RECIBO</div>
                <div class="receipt-num"># {{ $receipt->receipt_number }}</div>
                <div class="receipt-date">
                    Fecha: {{ $receipt->created_at
                        ? $receipt->created_at->format('d/m/Y')
                        : now()->format('d/m/Y') }}
                </div>
                @if($receipt->paid_at && $receipt->status === 'paid')
                <div class="receipt-date">
                    Pago: {{ \Carbon\Carbon::parse($receipt->paid_at)->format('d/m/Y H:i') }}
                </div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ════ CLIENTE / DETALLE ════ --}}
    @php
        $planName = optional(optional($receipt->membership)->plan)->name;
        $clientName = optional($receipt->client)->company_name
            ?? optional($receipt->client)->full_name
            ?? (optional($receipt->client)->first_name
                ? optional($receipt->client)->first_name . ' ' . optional($receipt->client)->last_name
                : 'Consumidor Final');
    @endphp
    <table class="client-box" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <div class="lbl">Cliente</div>
                <p><strong>{{ $clientName }}</strong></p>
                @if(optional($receipt->client)->phone)
                    <p>Tel: {{ $receipt->client->phone }}</p>
                @endif
                @if(optional($receipt->client)->email)
                    <p>Email: {{ $receipt->client->email }}</p>
                @endif
                @if(optional($receipt->client)->nit)
                    <p><strong>NIT:</strong> {{ $receipt->client->nit }}</p>
                @elseif(optional($receipt->client)->dni)
                    <p><strong>DPI:</strong> {{ $receipt->client->dni }}</p>
                @endif
                @if(optional($receipt->client)->fiscal_address)
                    <p style="margin-top:3px; font-size:10px; color:#555;">
                        Dir: {{ $receipt->client->fiscal_address }}
                    </p>
                @endif
            </td>
            <td>
                <div class="lbl">Detalle del pago</div>
                <p>Tipo: <strong>{{ ucfirst(str_replace('_', ' ', $receipt->payment_type ?? 'Pago')) }}</strong></p>
                <p>M&eacute;todo: <strong>{{ ucfirst(optional($receipt->payment)->payment_method ?? 'N/A') }}</strong></p>
                @if($planName)
                    <p>Plan: <strong>{{ $planName }}</strong></p>
                @endif
                <p style="margin-top:4px;">
                    Estado:&nbsp;
                    @if($receipt->status === 'paid')
                        <strong style="color:#15803d;">&#10003; PAGADO</strong>
                    @elseif($receipt->status === 'pending')
                        <strong style="color:#a16207;">&#9679; PENDIENTE</strong>
                    @else
                        <strong>{{ strtoupper($receipt->status ?? '-') }}</strong>
                    @endif
                </p>
            </td>
        </tr>
    </table>

    {{-- ════ CONCEPTO ════ --}}
    @if($receipt->description)
    <div style="font-size:11px; padding:8px 12px; background:#f5f5f5; margin-bottom:12px;
                border-left:3px solid #555; color:#333;">
        <strong>Concepto:</strong> {{ $receipt->description }}
    </div>
    @endif

    {{-- ════ ÍTEMS ════ --}}
    <table class="items">
        <thead>
            <tr>
                <th style="width:70%;">Descripci&oacute;n</th>
                <th class="r" style="width:30%;">Monto</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <strong>{{ ucfirst(str_replace('_', ' ', $receipt->payment_type ?? 'Pago')) }}</strong>
                    @if($planName)
                        &mdash; {{ $planName }}
                    @endif
                    @if($receipt->description)
                        <br><span style="color:#666; font-size:10px;">{{ $receipt->description }}</span>
                    @endif
                </td>
                <td class="r"><strong>Q{{ number_format($receipt->subtotal ?? $receipt->total ?? 0, 2) }}</strong></td>
            </tr>
            @if(($receipt->discount ?? 0) > 0)
            <tr>
                <td>Descuento aplicado</td>
                <td class="r" style="color:#b91c1c;">-Q{{ number_format($receipt->discount, 2) }}</td>
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

    {{-- ════ TOTALES ════ --}}
    <div class="totals-wrap">
        <table class="totals">
            @if(($receipt->subtotal ?? 0) != ($receipt->total ?? 0))
            <tr>
                <td class="lbl">Subtotal</td>
                <td class="val">Q{{ number_format($receipt->subtotal ?? 0, 2) }}</td>
            </tr>
            @endif
            @if(($receipt->discount ?? 0) > 0)
            <tr>
                <td class="lbl">Descuento</td>
                <td class="val" style="color:#b91c1c;">-Q{{ number_format($receipt->discount, 2) }}</td>
            </tr>
            @endif
            @if(($receipt->tax ?? 0) > 0)
            <tr>
                <td class="lbl">Impuesto</td>
                <td class="val">Q{{ number_format($receipt->tax, 2) }}</td>
            </tr>
            @endif
            <tr class="grand">
                <td class="lbl">TOTAL</td>
                <td class="val">Q{{ number_format($receipt->total ?? 0, 2) }}</td>
            </tr>
        </table>
    </div>

    {{-- ════ ESTADO ════ --}}
    @php
        $statusClass = $receipt->status === 'paid' ? 'status-paid'
                     : ($receipt->status === 'pending' ? 'status-pending' : '');
    @endphp
    <div class="status-line {{ $statusClass }}">
        @if($receipt->status === 'paid')
            &#10003;&nbsp; PAGO CONFIRMADO
            @if($receipt->paid_at)
                &nbsp;&mdash;&nbsp;{{ \Carbon\Carbon::parse($receipt->paid_at)->format('d/m/Y H:i') }}
            @endif
        @elseif($receipt->status === 'pending')
            &#9679;&nbsp; PENDIENTE DE PAGO
        @else
            {{ strtoupper($receipt->status ?? 'N/A') }}
        @endif
    </div>

    {{-- ════ PIE ════ --}}
    <div class="footer">
        Gracias por su preferencia &mdash; {{ $companyName }}<br>
        {{ $companyAddress }} &nbsp;|&nbsp; {{ $companyPhone }} &nbsp;|&nbsp; {{ $companyEmail }}<br>
        <span style="font-size:9px; color:#aaa;">
            Generado: {{ now()->format('d/m/Y H:i') }}
            &nbsp;|&nbsp; Recibo #{{ $receipt->receipt_number }}
            &nbsp;|&nbsp; ID {{ $receipt->id }}
        </span>
    </div>

</body>
</html>
