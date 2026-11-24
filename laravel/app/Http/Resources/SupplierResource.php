<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray($request): array
    {
        $profile = $this->supplierProfile;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $profile?->t('display_name') ?? $this->name,
            'name_translations' => $profile?->display_name ?? new \stdClass,
            'description' => $profile?->t('description'),
            'logo_url' => $profile?->logo_path ? asset('storage/'.$profile->logo_path) : null,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'phone' => $profile?->phone,
            'whatsapp' => $profile?->whatsapp,
            'is_verified' => $profile?->verification_status === 'verified',
            'rating' => [
                'average' => (float) ($profile?->rating_avg ?? 0),
                'count' => (int) ($profile?->rating_count ?? 0),
            ],
            'completed_orders' => (int) ($profile?->completed_orders_count ?? 0),
            'min_order' => $profile
                ? MoneyResource::make($profile->min_order_value, $profile->min_order_currency)
                : null,
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($link) => [
                'id' => $link->category_id,
                'name' => $link->category?->t('name'),
                'is_primary' => $link->is_primary,
                'brands' => $link->brands ?? [],
            ])),
            'products_count' => $this->when(isset($this->products_count), fn () => $this->products_count),
        ];
    }
}
