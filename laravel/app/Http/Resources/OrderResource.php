<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'buyer' => $this->whenLoaded('buyer', fn () => ['id' => $this->buyer->id, 'name' => $this->buyer->name]),
            'subtotal' => MoneyResource::make($this->subtotal, $this->currency),
            'tax' => MoneyResource::make($this->tax_amount, $this->currency),
            'shipping' => MoneyResource::make($this->shipping_amount, $this->currency),
            'total' => MoneyResource::make($this->total, $this->currency),
            'commission' => MoneyResource::make($this->commission_amount, $this->currency),
            'currency' => $this->currency,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit_price' => MoneyResource::make($i->unit_price, $this->currency),
                'line_total' => MoneyResource::make($i->line_total, $this->currency),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
