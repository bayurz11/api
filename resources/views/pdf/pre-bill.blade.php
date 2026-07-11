<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Pre-Bill {{ $bill->bill_no }}</title>
    <style>
        @page { margin: 8px 10px 12px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; margin: 0; }
        .page { width: 100%; }
        .center { text-align: center; }
        .logo { max-width: 56px; max-height: 56px; margin-bottom: 8px; }
        .title { font-size: 14px; font-weight: bold; margin-bottom: 2px; }
        .subtitle { font-size: 11px; font-weight: bold; margin-bottom: 6px; }
        .muted { color: #6b7280; }
        .section { margin-top: 10px; }
        .row { width: 100%; margin-bottom: 4px; }
        .label { width: 56%; display: inline-block; }
        .value { width: 42%; display: inline-block; text-align: right; }
        .group-title { font-size: 11px; font-weight: bold; margin: 10px 0 6px; padding-bottom: 4px; border-bottom: 1px dashed #9ca3af; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 0; vertical-align: top; }
        th { text-align: left; font-size: 9px; color: #6b7280; border-bottom: 1px dashed #d1d5db; }
        td.right, th.right { text-align: right; }
        .note { font-size: 9px; color: #4b5563; padding-top: 0; }
        .separator { border-top: 1px dashed #9ca3af; margin: 10px 0; }
        .footer { margin-top: 16px; text-align: center; font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="page">
        <div class="center">
            @if(!empty($profile['restaurant_logo_path']) && file_exists($profile['restaurant_logo_path']))
                <img src="{{ $profile['restaurant_logo_path'] }}" class="logo" alt="Logo Restoran">
            @endif
            <div class="title">{{ $profile['restaurant_name'] }}</div>
            <div class="subtitle">PRE-BILL / STRUK ORDER</div>
            @if(!empty($profile['restaurant_address']))
                <div class="muted">{{ $profile['restaurant_address'] }}</div>
            @endif
        </div>

        <div class="section">
            <div class="row"><span class="label">No. Bill</span><span class="value">{{ $bill->bill_no }}</span></div>
            <div class="row"><span class="label">Tipe</span><span class="value">{{ $bill->bill_type }}</span></div>
            <div class="row"><span class="label">Pelanggan</span><span class="value">{{ $customerName ?: '-' }}</span></div>
            <div class="row"><span class="label">Meja</span><span class="value">{{ $bill->table?->name ?: '-' }}</span></div>
            <div class="row"><span class="label">Jumlah Tamu</span><span class="value">{{ $bill->guest_count }}</span></div>
            <div class="row"><span class="label">Waktu Cetak</span><span class="value">{{ now()->format('d/m/Y H:i') }}</span></div>
        </div>

        <div class="separator"></div>

        @foreach($sections as $section)
            <div class="group-title">{{ $section['section_name'] }}</div>
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="right">Qty</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($section['items'] as $item)
                        <tr>
                            <td>{{ $item['menu_name'] }}</td>
                            <td class="right">{{ $item['qty'] }}</td>
                        </tr>
                        @if(!empty($item['notes']))
                            <tr>
                                <td colspan="2" class="note">Catatan: {{ $item['notes'] }}</td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
            <div class="row" style="margin-top: 6px;">
                <span class="label muted">Subtotal {{ $section['section_name'] }}</span>
                <span class="value">Rp {{ number_format((float) $section['subtotal'], 0, ',', '.') }}</span>
            </div>
        @endforeach

        <div class="separator"></div>

        <div class="section">
            <div class="row"><span class="label">Subtotal Bill</span><span class="value">Rp {{ number_format((float) $bill->subtotal, 0, ',', '.') }}</span></div>
            <div class="row"><span class="label">Status</span><span class="value">{{ $bill->status }}</span></div>
        </div>

        <div class="footer">
            Struk ini adalah ringkasan order sementara sebelum pembayaran final.
        </div>
    </div>
</body>
</html>
