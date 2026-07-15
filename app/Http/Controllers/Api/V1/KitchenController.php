<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KitchenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = OrderItem::query()
            ->with([
                'order:id,order_no,bill_id,created_by,sent_at,status',
                'order.bill:id,bill_no,table_id,status',
                'order.bill.table:id,code,name',
                'menu:id,name',
                'billItem:id,menu_name',
            ])
            ->where('station_type', 'KITCHEN')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $items,
        ]);
    }
}
