<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Setting;
use App\Support\AuditLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PrintController extends Controller
{
    private const THERMAL_PAPER_80MM = [0, 0, 226.77, 900];

    public function printers(): JsonResponse
    {
        $printers = Printer::query()
            ->with([
                'printJobs' => fn ($query) => $query
                    ->latest('id')
                    ->limit(1),
            ])
            ->orderByRaw('CASE WHEN station_type IS NULL THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->get()
            ->map(function (Printer $printer): array {
                $latestJob = $printer->printJobs->first();

                return [
                    'id' => $printer->id,
                    'name' => $printer->name,
                    'printer_type' => $printer->printer_type,
                    'connection_type' => $printer->connection_type,
                    'address' => $printer->address,
                    'station_type' => $printer->station_type,
                    'is_active' => (bool) $printer->is_active,
                    'status_label' => $printer->is_active ? 'Aktif' : 'Nonaktif',
                    'latest_job' => $latestJob ? [
                        'id' => $latestJob->id,
                        'job_type' => $latestJob->job_type,
                        'status' => $latestJob->status,
                        'created_at' => optional($latestJob->created_at)->toISOString(),
                    ] : null,
                ];
            })
            ->values();

        return response()->json([
            'data' => $printers,
        ]);
    }

    public function kitchenTicket(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'printer_id' => ['nullable', 'integer', 'exists:printers,id'],
        ]);

        $order = Order::query()
            ->with(['bill.table', 'items.menu'])
            ->findOrFail($validated['order_id']);

        $items = $order->items->where('station_type', 'KITCHEN')->values();
        abort_if($items->isEmpty(), 422, 'Order tidak memiliki item kitchen.');

        $job = $this->createPrintJob(
            request: $request,
            jobType: 'KITCHEN_TICKET',
            referenceType: 'order',
            referenceId: $order->id,
            printerId: $validated['printer_id'] ?? $this->resolvePrinterId('KITCHEN'),
            payload: [
                'order_no' => $order->order_no,
                'bill_no' => $order->bill->bill_no,
                'table' => $order->bill->table?->name,
                'items' => $items->map(fn ($item) => [
                    'menu_name' => $item->menu?->name,
                    'qty' => $item->qty,
                    'notes' => $item->notes,
                ])->values(),
            ],
        );

        return response()->json([
            'message' => 'Print job kitchen berhasil dibuat.',
            'data' => $job,
        ], 201);
    }

    public function barTicket(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'printer_id' => ['nullable', 'integer', 'exists:printers,id'],
        ]);

        $order = Order::query()
            ->with(['bill.table', 'items.menu'])
            ->findOrFail($validated['order_id']);

        $items = $order->items->where('station_type', 'BAR')->values();
        abort_if($items->isEmpty(), 422, 'Order tidak memiliki item bar.');

        $job = $this->createPrintJob(
            request: $request,
            jobType: 'BAR_TICKET',
            referenceType: 'order',
            referenceId: $order->id,
            printerId: $validated['printer_id'] ?? $this->resolvePrinterId('BAR'),
            payload: [
                'order_no' => $order->order_no,
                'bill_no' => $order->bill->bill_no,
                'table' => $order->bill->table?->name,
                'items' => $items->map(fn ($item) => [
                    'menu_name' => $item->menu?->name,
                    'qty' => $item->qty,
                    'notes' => $item->notes,
                ])->values(),
            ],
        );

        return response()->json([
            'message' => 'Print job bar berhasil dibuat.',
            'data' => $job,
        ], 201);
    }

    public function receipt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bill_id' => ['required', 'integer', 'exists:bills,id'],
            'printer_id' => ['nullable', 'integer', 'exists:printers,id'],
        ]);

        $bill = Bill::query()->with(['table', 'payments'])->findOrFail($validated['bill_id']);
        abort_if((float) $bill->paid_total <= 0, 422, 'Bill belum memiliki pembayaran untuk dicetak.');

        $job = $this->createPrintJob(
            request: $request,
            jobType: 'RECEIPT',
            referenceType: 'bill',
            referenceId: $bill->id,
            printerId: $validated['printer_id'] ?? $this->resolvePrinterId(null),
            payload: [
                'bill_no' => $bill->bill_no,
                'table' => $bill->table?->name,
                'grand_total' => $bill->grand_total,
                'paid_total' => $bill->paid_total,
                'payments' => $bill->payments->map(fn ($payment) => [
                    'payment_no' => $payment->payment_no,
                    'payment_method' => $payment->payment_method,
                    'amount' => $payment->amount,
                    'status' => $payment->status,
                ])->values(),
            ],
        );

        return response()->json([
            'message' => 'Print job receipt berhasil dibuat.',
            'data' => $job,
        ], 201);
    }

    public function preBill(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bill_id' => ['required', 'integer', 'exists:bills,id'],
            'printer_id' => ['nullable', 'integer', 'exists:printers,id'],
        ]);

        $bill = Bill::query()->with(['table', 'customer'])->findOrFail($validated['bill_id']);
        $sections = $this->buildPreBillSections($bill);
        abort_if($sections->isEmpty(), 422, 'Bill belum memiliki item untuk dicetak.');

        $job = $this->createPrintJob(
            request: $request,
            jobType: 'PRE_BILL',
            referenceType: 'bill',
            referenceId: $bill->id,
            printerId: $validated['printer_id'] ?? $this->resolvePrinterId(null),
            payload: [
                'bill_no' => $bill->bill_no,
                'bill_type' => $bill->bill_type,
                'table' => $bill->table?->name,
                'guest_count' => $bill->guest_count,
                'customer_name' => $bill->customer?->name ?: $bill->customer_name,
                'sections' => $sections->values()->all(),
                'subtotal' => (float) $bill->subtotal,
                'printed_at' => now()->toDateTimeString(),
            ],
        );

        return response()->json([
            'message' => 'Print job pre-bill berhasil dibuat.',
            'data' => $job,
        ], 201);
    }

    public function receiptPdf(Bill $bill)
    {
        $bill->load(['table', 'customer', 'items', 'payments']);
        abort_if((float) $bill->paid_total <= 0, 422, 'Bill belum memiliki pembayaran untuk dicetak.');

        $profile = RestaurantProfileController::profilePayload();
        $logoPath = Setting::getValue('restaurant_logo_path');
        $profile['restaurant_logo_path'] = is_string($logoPath)
            && $logoPath !== ''
            && Storage::disk('public')->exists($logoPath)
            ? Storage::disk('public')->path($logoPath)
            : null;
        $customerName = $bill->customer?->name ?: $bill->customer_name;

        $pdf = Pdf::loadView('pdf.receipt', [
            'bill' => $bill,
            'profile' => $profile,
            'customerName' => $customerName,
        ])->setPaper(self::THERMAL_PAPER_80MM, 'portrait');

        return $pdf->download("receipt-{$bill->bill_no}.pdf");
    }

    public function preBillPdf(Bill $bill)
    {
        $bill->load(['table', 'customer']);
        $sections = $this->buildPreBillSections($bill);
        abort_if($sections->isEmpty(), 422, 'Bill belum memiliki item untuk dicetak.');

        $profile = RestaurantProfileController::profilePayload();
        $logoPath = Setting::getValue('restaurant_logo_path');
        $profile['restaurant_logo_path'] = is_string($logoPath)
            && $logoPath !== ''
            && Storage::disk('public')->exists($logoPath)
            ? Storage::disk('public')->path($logoPath)
            : null;

        $pdf = Pdf::loadView('pdf.pre-bill', [
            'bill' => $bill,
            'profile' => $profile,
            'customerName' => $bill->customer?->name ?: $bill->customer_name,
            'sections' => $sections,
        ])->setPaper(self::THERMAL_PAPER_80MM, 'portrait');

        return $pdf->download("pre-bill-{$bill->bill_no}.pdf");
    }

    public function jobs(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        $jobs = PrintJob::query()
            ->with('printer:id,name,station_type')
            ->when($request->filled('job_type'), fn ($query) => $query->where('job_type', $request->string('job_type')))
            ->latest('id')
            ->paginate($perPage);

        return response()->json($jobs);
    }

    public function testPrinter(Request $request, Printer $printer): JsonResponse
    {
        abort_if(! $printer->is_active, 422, 'Printer tidak aktif.');

        $job = $this->createPrintJob(
            request: $request,
            jobType: 'TEST_RECEIPT',
            referenceType: 'printer',
            referenceId: $printer->id,
            printerId: $printer->id,
            payload: [
                'title' => 'Tes Printer',
                'printer_name' => $printer->name,
                'station_type' => $printer->station_type,
                'connection_type' => $printer->connection_type,
                'address' => $printer->address,
                'printed_at' => now()->toDateTimeString(),
                'message' => 'Jika struk tes ini keluar, koneksi printer siap dipakai.',
            ],
        );

        return response()->json([
            'message' => 'Tes printer berhasil dikirim ke antrean cetak.',
            'data' => $job,
        ], 201);
    }

    private function createPrintJob(
        Request $request,
        string $jobType,
        string $referenceType,
        int $referenceId,
        ?int $printerId,
        array $payload,
    ): PrintJob {
        $job = PrintJob::query()->create([
            'printer_id' => $printerId,
            'job_type' => $jobType,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status' => 'PENDING',
            'attempt_count' => 0,
        ]);

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'print_job.created',
            entityType: 'print_job',
            entityId: $job->id,
            after: $job->toArray(),
        );

        return $job;
    }

    private function resolvePrinterId(?string $stationType): ?int
    {
        return Printer::query()
            ->when($stationType, fn ($query) => $query->where('station_type', $stationType))
            ->where('is_active', true)
            ->value('id');
    }

    private function buildPreBillSections(Bill $bill): Collection
    {
        $items = BillItem::query()
            ->leftJoin('menus', 'menus.id', '=', 'bill_items.menu_id')
            ->leftJoin('menu_categories', 'menu_categories.id', '=', 'menus.category_id')
            ->where('bill_items.bill_id', $bill->id)
            ->select([
                'bill_items.id',
                'bill_items.menu_name',
                'bill_items.qty',
                'bill_items.unit_price',
                'bill_items.line_total',
                'bill_items.notes',
                'menus.station_type',
                'menu_categories.name as category_name',
                'menu_categories.sort_order as category_sort_order',
            ])
            ->orderByRaw('COALESCE(menu_categories.sort_order, 9999)')
            ->orderBy('menu_categories.name')
            ->orderBy('bill_items.menu_name')
            ->get();

        return $items
            ->map(function ($item): array {
                $sectionName = $item->category_name
                    ?: match ($item->station_type) {
                        'KITCHEN' => 'Makanan',
                        'BAR' => 'Minuman',
                        default => 'Lainnya',
                    };

                return [
                    'section_name' => $sectionName,
                    'section_sort_order' => (int) ($item->category_sort_order ?? 9999),
                    'item' => [
                        'id' => (int) $item->id,
                        'menu_name' => $item->menu_name,
                        'qty' => (int) $item->qty,
                        'unit_price' => (float) $item->unit_price,
                        'line_total' => (float) $item->line_total,
                        'notes' => $item->notes,
                    ],
                ];
            })
            ->groupBy('section_name')
            ->map(function (Collection $sectionRows, string $sectionName): array {
                $items = $sectionRows
                    ->pluck('item')
                    ->values();

                return [
                    'section_name' => $sectionName,
                    'items_count' => $items->count(),
                    'total_qty' => $items->sum('qty'),
                    'subtotal' => (float) $items->sum('line_total'),
                    'items' => $items->all(),
                    'sort_order' => (int) $sectionRows->min('section_sort_order'),
                ];
            })
            ->sortBy([
                ['sort_order', 'asc'],
                ['section_name', 'asc'],
            ])
            ->values();
    }
}
