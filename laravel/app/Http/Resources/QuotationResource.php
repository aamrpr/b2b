<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class QuotationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'quotation_no' => $this->quotation_no,
            'version' => $this->version,
            'rfq_id' => $this->rfq_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'subtotal' => MoneyResource::make($this->subtotal, $this->currency),
            'discount' => MoneyResource::make($this->discount_amount, $this->currency),
            'tax' => MoneyResource::make($this->tax_amount, $this->currency),
            'shipping' => MoneyResource::make($this->shipping_amount, $this->currency),
            'total' => MoneyResource::make($this->total, $this->currency),
            'currency' => $this->currency,
            'incoterm' => $this->incoterm,
            'lead_time_days' => $this->lead_time_days,
            'payment_terms_days' => $this->payment_terms_days,
            'valid_until' => $this->valid_until?->toIso8601String(),
            'is_actionable' => $this->isActionable(),
            'notes' => $this->notes,
            'status' => $this->status,
            'buyer' => new SupplierResource($this->whenLoaded('buyer')),
            'rfq_title' => $this->whenLoaded('rfq', fn () => $this->rfq?->title),
            // التوقيع: الصورة خطّ يد، والاسم والوقت هما ما يُحتجّ به
            'signatures' => [
                'supplier' => $this->supplier_signed_at ? [
                    'url' => asset('storage/'.$this->supplier_signature_path),
                    'by' => $this->supplier_signed_by,
                    'at' => $this->supplier_signed_at->toIso8601String(),
                ] : null,
                'buyer' => $this->buyer_signed_at ? [
                    'url' => asset('storage/'.$this->buyer_signature_path),
                    'by' => $this->buyer_signed_by,
                    'at' => $this->buyer_signed_at->toIso8601String(),
                ] : null,
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit' => $i->unit,
                'unit_price' => MoneyResource::make($i->unit_price, $this->currency),
                'line_total' => MoneyResource::make($i->line_total, $this->currency),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
