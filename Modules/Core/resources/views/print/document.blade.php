<!doctype html>
<html lang="en" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $doc->type }} {{ $doc->number }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 13px/1.45 -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #1c1c1c; background: #f3f3f3; }
        .sheet { max-width: 210mm; margin: 12px auto; padding: 14mm; background: #fff; box-shadow: 0 1px 6px rgba(0,0,0,.12); }
        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid #1c1c1c; padding-bottom: 10px; }
        h1 { margin: 0; font-size: 22px; letter-spacing: .02em; }
        .company { font-size: 15px; font-weight: 600; }
        .number { margin-top: 2px; font-size: 14px; word-break: break-all; }
        .badge { display: inline-block; margin-top: 6px; padding: 1px 8px; border: 1px solid #1c1c1c; border-radius: 10px; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; }
        dl.meta { margin: 0; display: grid; grid-template-columns: auto auto; gap: 2px 14px; font-size: 12px; }
        dl.meta dt { color: #666; } dl.meta dd { margin: 0; font-weight: 600; text-align: end; }
        .parties { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin: 16px 0; }
        .party h3 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #666; }
        .party p { margin: 0; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        caption { text-align: start; font-weight: 600; padding: 4px 0; }
        th { background: #efefef; text-align: start; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
        .right { text-align: end; } .center { text-align: center; }
        .totals { margin-inline-start: auto; width: 55%; }
        .totals td { border-bottom: 0; padding: 3px 8px; } .totals td:last-child { text-align: end; }
        .totals .strong td { border-top: 2px solid #1c1c1c; font-weight: 700; font-size: 14px; }
        .notes { margin-top: 12px; padding: 8px 10px; background: #fafafa; border: 1px solid #e5e5e5; white-space: pre-line; }
        .signatures { display: flex; gap: 24px; margin-top: 46px; }
        .signatures div { flex: 1; padding-top: 6px; border-top: 1px solid #1c1c1c; text-align: center; font-size: 12px; color: #444; }
        .actions { text-align: center; margin: 12px; } .actions button { padding: 8px 18px; font-size: 14px; cursor: pointer; }
        @media print { body { background: #fff; } .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; } .actions { display: none; } tr { page-break-inside: avoid; } }
    </style>
</head>
<body>
<div class="actions"><button type="button" onclick="window.print()">Print</button></div>
<div class="sheet">
    <header>
        <div>
            <div class="company">{{ $doc->company }}</div>
            <h1>{{ $doc->type }}</h1>
            <div class="number">{{ $doc->number }}</div>
            @if ($doc->status)<span class="badge">{{ $doc->status }}</span>@endif
        </div>
        @if ($doc->meta)
            <dl class="meta">
                @foreach ($doc->meta as $label => $value)
                    @if ($value !== null && $value !== '')<dt>{{ $label }}</dt><dd>{{ $value }}</dd>@endif
                @endforeach
            </dl>
        @endif
    </header>

    @if ($doc->parties)
        <section class="parties">
            @foreach ($doc->parties as $party)
                <div class="party">
                    <h3>{{ $party['title'] }}</h3>
                    @foreach (array_filter($party['lines'], fn ($line) => $line !== null && $line !== '') as $line)<p>{{ $line }}</p>@endforeach
                </div>
            @endforeach
        </section>
    @endif

    @foreach ($doc->tables as $table)
        <table>
            @if ($table['title'])<caption>{{ $table['title'] }}</caption>@endif
            <thead><tr>@foreach ($table['columns'] as $column)<th class="{{ $column['align'] ?? '' }}">{{ $column['label'] }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse ($table['rows'] as $row)
                <tr>@foreach ($table['columns'] as $column)<td class="{{ $column['align'] ?? '' }}">{{ $row[$column['key']] ?? '' }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($table['columns']) }}" class="center">—</td></tr>
            @endforelse
            </tbody>
        </table>
    @endforeach

    @if ($doc->totals)
        <table class="totals">
            @foreach ($doc->totals as $total)
                <tr class="{{ ! empty($total['strong']) ? 'strong' : '' }}"><td>{{ $total['label'] }}</td><td>{{ $total['value'] }}</td></tr>
            @endforeach
        </table>
    @endif

    @if ($doc->notes)<div class="notes">{{ $doc->notes }}</div>@endif

    @if ($doc->signatures)
        <div class="signatures">@foreach ($doc->signatures as $name)<div>{{ $name }}</div>@endforeach</div>
    @endif
</div>
</body>
</html>
