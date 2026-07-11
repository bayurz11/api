<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Support\AuditLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function receiptPdf(Bill $bill)
    {
        $bill->load(['table', 'customer', 'items', 'payments']);
        abort_if((float) $bill->paid_total <= 0, 422, 'Bill belum memiliki pembayaran untuk dicetak.');

        $profile = RestaurantProfileController::profilePayload();
        $customerName = $bill->customer?->name ?: $bill->customer_name;

        $pdf = Pdf::loadView('pdf.receipt', [
            'bill' => $bill,
            'profile' => $profile,
            'customerName' => $customerName,
        ])->setPaper(self::THERMAL_PAPER_80MM, 'portrait');

        return $pdf->download("receipt-{$bill->bill_no}.pdf");
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
}
