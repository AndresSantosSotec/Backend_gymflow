<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Recibos</title>
    <style>
        @page {
            size: letter landscape;
            margin: 12mm 10mm;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #222;
            font-size: 9px;
            line-height: 1.4;
        }
        .header {
            width: 100%;
            border-bottom: 2px solid #333;
            margin-bottom: 10px;
            padding-bottom: 8px;
        }
        .header td { vertical-align: top; }
        .company-name { font-size: 16px; font-weight: bold; margin-bottom: 2px; }
        .report-title {
            font-size: 14px;
            font-weight: bold;
            text-align: right;
            text-transform: uppercase;
        }
        .report-meta {
            font-size: 8px;
            color: #555;
            text-align: right;
        }
        .filters-box {
            background: #f5f5f5;
            border: 1px solid #ddd;
            padding: 6px 10px;
            margin-bottom: 10px;
            font-size: 8px;
        }
        .filters-box span { margin-right: 15px; }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .data-table th {
            background-color: #333;
            color: #fff;
            padding: 5px 6px;
            text-align: left;
            font-size: 8px;
            font-weight: bold;
            white-space: nowrap;
        }
        .data-table td {
            padding: 4px 6px;
            border-bottom: 1px solid #ddd;
            font-size: 8px;
            vertical-align: top;
        }
        .data-table tr:nth-child(even) td {
            background-color: #fafafa;
        }
        .data-table .r { text-align: right; }
        .data-table .c { text-align: center; }
        .data-table .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 7px; }
        .summary-wrap {
            width: 100%;
            margin-top: 8px;
        }
        .summary {
            width: 280px;
            margin-left: auto;
            border-collapse: collapse;
        }
        .summary td {
            padding: 4px 8px;
            font-size: 10px;
            border-bottom: 1px solid #ddd;
        }
        .summary .lbl { font-weight: bold; }
        .summary .val { text-align: right; }
        .summary tr.grand td {
            border-top: 2px solid #333;
            border-bottom: 2px solid #333;
            font-size: 13px;
            font-weight: bold;
            padding: 6px 8px;
        }
        .footer {
            border-top: 1px solid #ccc;
            padding-top: 6px;
            text-align: center;
            font-size: 7px;
            color: #888;
            margin-top: 8px;
        }
        .badge-paid {
            display: inline-block;
            background: #16a34a;
            color: #fff;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: bold;
        }
        .badge-pending {
            display: inline-block;
            background: #ca8a04;
            color: #fff;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: bold;
        }
        .badge-cancelled {
            display: inline-block;
            background: #dc2626;
            color: #fff;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: bold;
        }
        .badge-draft {
            display: inline-block;
            background: #6b7280;
            color: #fff;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    @include('pdfs.partials.logo')
    <table class="header" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width:55%;">
                <div class="company-name">{{ $companyName }}</div>
                <div style="font-size:8px; color:#555;">{{ $companyAddress }}</div>
                <div style="font-size:8px; color:#555;">Tel: {{ $companyPhone }} | {{ $companyEmail }}</div>
            </td>
            <td style="width:45%;">
                <div class="report-title">Reporte de Recibos</div>
                <div class="report-meta">
                    <p>Periodo: {{ $dateFrom ?? 'Inicio' }} - {{ $dateTo ?? 'Actual' }}</p>
                    <p>Generado: {{ now()->format('d/m/Y H:i') }}</p>
                    <p>Total registros: {{ count($receipts) }}</p>
                </div>
            </td>
        </tr>
    </table>

    @if(!empty($appliedFilters))
    <div class="filters-box">
        <strong>Filtros aplicados:</strong>
        @foreach($appliedFilters as $key => $value)
            <span><strong>{{ $key }}:</strong> {{ $value }}</span>
        @endforeach
    </div>
    @endif

    <table class="data-table">
        <thead>
            <tr>
                <th style="width:5%;">#</th>
                <th style="width:13%;">No. Recibo</th>
                <th style="width:17%;">Cliente</th>
                <th style="width:14%;">Concepto / Plan</th>
                <th style="width:8%;">Metodo</th>
                <th style="width:7%;">Estado</th>
                <th class="r" style="width:9%;">Subtotal</th>
                <th class="r" style="width:7%;">Desc.</th>
                <th class="r" style="width:7%;">IVA</th>
                <th class="r" style="width:9%;">Total</th>
                <th style="width:9%;">Fecha</th>
            </tr>
        </thead>
        <tbody>
            @forelse($receipts as $idx => $r)
            @php
                $rPlanName = optional(optional($r->membership)->plan)->name;
            @endphp
            <tr>
                <td class="c">{{ $idx + 1 }}</td>
                <td class="mono">{{ $r->receipt_number }}</td>
                <td>{{ $r->client->full_name ?? ($r->client->first_name . ' ' . ($r->client->last_name ?? '')) }}</td>
                <td>
                    {{ ucfirst(str_replace('_', ' ', $r->payment_type ?? '-')) }}
                    @if($rPlanName) <br><small>{{ $rPlanName }}</small> @endif
                </td>
                <td>{{ ucfirst(optional($r->payment)->payment_method ?? 'N/A') }}</td>
                <td class="c">
                    @if($r->status === 'paid')
                        <span class="badge-paid">PAGADO</span>
                    @elseif($r->status === 'pending')
                        <span class="badge-pending">PEND.</span>
                    @elseif($r->status === 'cancelled')
                        <span class="badge-cancelled">CANC.</span>
                    @else
                        <span class="badge-draft">{{ strtoupper($r->status) }}</span>
                    @endif
                </td>
                <td class="r">Q{{ number_format($r->subtotal ?? 0, 2) }}</td>
                <td class="r">{{ ($r->discount ?? 0) > 0 ? '-Q' . number_format($r->discount, 2) : '-' }}</td>
                <td class="r">{{ ($r->tax ?? 0) > 0 ? 'Q' . number_format($r->tax, 2) : '-' }}</td>
                <td class="r" style="font-weight:bold;">Q{{ number_format($r->total ?? 0, 2) }}</td>
                <td>{{ $r->created_at ? $r->created_at->format('d/m/Y') : '-' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="11" style="text-align:center; padding:20px; color:#999;">
                    No se encontraron recibos para el periodo seleccionado.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>

    @if(count($receipts) > 0)
    <div class="summary-wrap">
        <table class="summary">
            <tr>
                <td class="lbl">Total registros:</td>
                <td class="val">{{ count($receipts) }}</td>
            </tr>
            <tr>
                <td class="lbl">Pagados:</td>
                <td class="val">{{ $receipts->where('status', 'paid')->count() }}</td>
            </tr>
            <tr>
                <td class="lbl">Pendientes:</td>
                <td class="val">{{ $receipts->where('status', 'pending')->count() }}</td>
            </tr>
            <tr>
                <td class="lbl">Subtotal acumulado:</td>
                <td class="val">Q{{ number_format($receipts->sum('subtotal'), 2) }}</td>
            </tr>
            @if($receipts->sum('discount') > 0)
            <tr>
                <td class="lbl">Descuentos:</td>
                <td class="val">-Q{{ number_format($receipts->sum('discount'), 2) }}</td>
            </tr>
            @endif
            @if($receipts->sum('tax') > 0)
            <tr>
                <td class="lbl">IVA total:</td>
                <td class="val">Q{{ number_format($receipts->sum('tax'), 2) }}</td>
            </tr>
            @endif
            <tr class="grand">
                <td class="lbl">TOTAL RECAUDADO:</td>
                <td class="val">Q{{ number_format($receipts->sum('total'), 2) }}</td>
            </tr>
        </table>
    </div>
    @endif

    <div class="footer">
        {{ $companyName }} | Reporte generado automaticamente el {{ now()->format('d/m/Y H:i') }} | {{ $companyEmail }}
    </div>
</body>
</html>
