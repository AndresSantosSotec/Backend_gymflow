<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Corte de Caja</title>
    <style>
        @page { size: letter; margin: 14mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #222;
            font-size: 10px;
            line-height: 1.35;
        }
        .header {
            width: 100%;
            border-bottom: 2px solid #333;
            margin-bottom: 12px;
            padding-bottom: 8px;
        }
        .header td { vertical-align: top; }
        .company-name { font-size: 16px; font-weight: bold; margin-bottom: 2px; }
        .report-title { font-size: 14px; font-weight: bold; text-align: right; text-transform: uppercase; }
        .report-meta { font-size: 9px; color: #555; text-align: right; }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .data-table th {
            background-color: #333;
            color: #fff;
            padding: 6px 8px;
            text-align: left;
            font-size: 9px;
            font-weight: bold;
        }
        .data-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #ddd;
            font-size: 9px;
        }
        .data-table tr:nth-child(even) td { background-color: #f9f9f9; }
        .data-table .r { text-align: right; }
        .data-table .c { text-align: center; }
        .summary-wrap { margin-top: 12px; }
        .summary {
            width: 320px;
            margin-left: auto;
            border-collapse: collapse;
        }
        .summary td { padding: 5px 10px; font-size: 10px; border-bottom: 1px solid #ddd; }
        .summary .lbl { font-weight: bold; }
        .summary .val { text-align: right; }
        .summary tr.grand td {
            border-top: 2px solid #333;
            font-size: 12px;
            font-weight: bold;
            padding: 8px 10px;
        }
        .footer {
            border-top: 1px solid #ccc;
            padding-top: 8px;
            text-align: center;
            font-size: 8px;
            color: #888;
            margin-top: 12px;
        }
    </style>
</head>
<body>
    <table class="header" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width:55%;">
                @if(!empty($logoBase64))
                <img src="{{ $logoBase64 }}" alt="Logo" style="height:45px; margin-bottom:3px; display:block;" />
                @else
                <div class="company-name">{{ $companyName }}</div>
                @endif
                <div style="font-size:9px; color:#555;">{{ $companyAddress }}</div>
                <div style="font-size:9px; color:#555;">Tel: {{ $companyPhone }} | {{ $companyEmail }}</div>
            </td>
            <td style="width:45%;">
                <div class="report-title">Corte de Caja</div>
                <div class="report-meta">
                    <p>Del {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}</p>
                    <p>Generado: {{ now()->format('d/m/Y H:i') }}</p>
                    <p>{{ $payments->count() }} pago(s)</p>
                </div>
            </td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width:5%;">#</th>
                <th style="width:12%;">Fecha</th>
                <th style="width:28%;">Cliente</th>
                <th style="width:18%;">Método</th>
                <th class="r" style="width:15%;">Monto</th>
                <th style="width:22%;">Referencia / Notas</th>
            </tr>
        </thead>
        <tbody>
            @php
                $methodLabels = [
                    'cash' => 'Efectivo',
                    'card' => 'Tarjeta',
                    'transfer' => 'Transferencia',
                    'stripe' => 'Stripe',
                    'recurrente' => 'Recurrente',
                ];
            @endphp
            @forelse($payments as $idx => $p)
            <tr>
                <td class="c">{{ $idx + 1 }}</td>
                <td>{{ $p->paid_at ? $p->paid_at->format('d/m/Y H:i') : ($p->created_at ? $p->created_at->format('d/m/Y H:i') : '-') }}</td>
                <td>{{ $p->client ? trim(($p->client->first_name ?? '') . ' ' . ($p->client->last_name ?? '')) : '-' }}</td>
                <td>{{ $methodLabels[$p->payment_method] ?? $p->payment_method }}</td>
                <td class="r" style="font-weight:bold;">Q {{ number_format((float) $p->amount, 2) }}</td>
                <td style="font-size:8px;">{{ $p->transaction_id ?: '-' }}{!! $p->notes ? '<br>' . e($p->notes) : '' !!}</td>
            </tr>
            @empty
            <tr>
                <td colspan="6" style="text-align:center; padding:24px; color:#999;">No hay pagos en este periodo.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    @if($payments->count() > 0)
    <div class="summary-wrap">
        <table class="summary">
            @foreach($by_method ?? [] as $method => $total)
            <tr>
                <td class="lbl">{{ $methodLabels[$method] ?? $method }}:</td>
                <td class="val">Q {{ number_format($total, 2) }}</td>
            </tr>
            @endforeach
            <tr class="grand">
                <td class="lbl">TOTAL INGRESOS</td>
                <td class="val">Q {{ number_format($total_revenue ?? 0, 2) }}</td>
            </tr>
        </table>
    </div>
    @endif

    <div class="footer">
        {{ $companyName }} | Corte de caja generado el {{ now()->format('d/m/Y H:i') }} | {{ $companyEmail }}
    </div>
</body>
</html>
