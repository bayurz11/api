<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Struk {{ $bill->bill_no }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; }
        .page { padding: 20px; }
        .center { text-align: center; }
        .logo { max-width: 84px; max-height: 84px; margin-bottom: 10px; }
        .title { font-size: 18px; font-weight: bold; margin-bottom: 4px; }
        .muted { color: #6b7280; }
        .section { margin-top: 18px; }
        .row { width: 100%; margin-bottom: 6px; }
        .label { width: 58%; display: inline-block; }
        .value { width: 40%; display: inline-block; text-align: right; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 6px 0; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { text-align: left; font-size: 11px; color: #6b7280; }
        td.right, th.right { text-align: right; }
        .total { font-weight: bold; }
        .footer { margin-top: 24px; text-align: center; font-size: 11px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="page">
        <div class="center">
            @if(!empty($profile['restaurant_logo_path']) && file_exists($profile['restaurant_logo_path']))
                <img src="{{ $profile['restaurant_logo_path'] }}" class="logo" alt="Logo Restoran">
            @endif
            <div class="title">{{ $profile['restaurant_name'] }}</div>
            @if(!empty($profile['restaurant_address']))
                <div class="muted">{{ $profile['restaurant_address'] }}</div>
            @endif
        </div>

        <div class="section">
            <div class="row"><span class="label">No. Struk</span><span class="value">{{ $bill->bill_no }}</span></div>
            <div class="row"><span class="label">Tipe</span><span class="value">{{ $bill->bill_type }}</span></div>
            <div class="row"><span class="label">Pelanggan</span><span class="value">{{ $customerName ?: '-' }}</span></div>
            <div class="row"><span class="label">Meja</span><span class="value">{{ $bill->table?->name ?: '-' }}</span></div>
            <div class="row"><span class="label">Tamu</span><span class="value">{{ $bill->guest_count }}</span></div>
            <div class="row"><span class="label">Status</span><span class="value">{{ $bill->status }}</span></div>
        </div>

        <div class="section">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="right">Qty</th>
                        <th class="right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($bill->items as $item)
                        <tr>
                            <td>{{ $item->menu_name }}</td>
                            <td class="right">{{ $item->qty }}</td>
                            <td class="right">Rp {{ number_format((float) $item->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="section">
            <div class="row"><span class="label">Subtotal</span><span class="value">Rp {{ number_format((float) $bill->subtotal, 0, ',', '.') }}</span></div>
            <div class="row"><span class="label">Diskon</span><span class="value">Rp {{ number_format((float) $bill->discount_total, 0, ',', '.') }}</span></div>
            <div class="row"><span class="label">Pajak</span><span class="value">Rp {{ number_format((float) $bill->tax_total, 0, ',', '.') }}</span></div>
            <div class="row"><span class="label">Layanan</span><span class="value">Rp {{ number_format((float) $bill->service_total, 0, ',', '.') }}</span></div>
            <div class="row total"><span class="label">Total Akhir</span><span class="value">Rp {{ number_format((float) $bill->grand_total, 0, ',', '.') }}</span></div>
            <div class="row total"><span class="label">Sudah Dibayar</span><span class="value">Rp {{ number_format((float) $bill->paid_total, 0, ',', '.') }}</span></div>
        </div>

        <div class="section">
            <table>
                <thead>
                    <tr>
                        <th>Pembayaran</th>
                        <th class="right">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($bill->payments as $payment)
                        <tr>
                            <td>{{ $payment->payment_method }} @if($payment->payment_no) ({{ $payment->payment_no }}) @endif</td>
                            <td class="right">Rp {{ number_format((float) $payment->amount, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="footer">
            Terima kasih. Struk ini merupakan bukti pembayaran yang sah.
        </div>
    </div>
</body>
</html>
