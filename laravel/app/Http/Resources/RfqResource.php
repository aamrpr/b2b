<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RfqResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'reference_no' => $this->reference_no,
            'title' => $this->title,
            'description' => $this->description,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'buyer' => $this->hide_buyer_identity
                ? ['name' => __('مشترٍ مجهول الهوية')]
                : ['id' => $this->buyer_tenant_id, 'name' => $this->whenLoaded('buyer', fn () => $this->buyer->name)],
            'target_price' => $this->target_price
                ? MoneyResource::make($this->target_price, $this->currency)
                : null,
            'currency' => $this->currency,
            'delivery_city' => $this->delivery_city,
            'delivery_deadline' => $this->delivery_deadline?->toDateString(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'hours_remaining' => $this->expires_at ? max(0, (int) now()->diffInHours($this->expires_at, false)) : null,
            'visibility' => $this->visibility,
            'status' => $this->status,
            'reach' => [
                'notified' => $this->notified_count,
                'viewed' => $this->viewed_count,
                'quoted' => $this->quotations_count,
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit' => $i->unit,
                'specifications' => $i->specifications ?? new \stdClass,
                'product' => $i->relationLoaded('product') && $i->product
                    ? [
                        'id' => $i->product->id,
                        'name' => $i->product->t('name'),
                        'slug' => $i->product->slug,
                    ]
                    : null,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
