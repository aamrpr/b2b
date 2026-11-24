<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\TranslatesFields;
use App\Support\Media\ImageThumbnail;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    use TranslatesFields;

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            ...$this->translated('name'),
            ...$this->translated('description'),
            'slug' => $this->slug,
            'sku' => $this->sku,
            'brand' => $this->brand,
            'hs_code' => $this->hs_code,
            // الخصائص مرّتين كالحقول المترجمة: بلغة الطلب للعرض، وكاملةً للتحرير
            'specifications' => $this->localizedSpecifications(),
            'specifications_translations' => $this->specifications ?? [],
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'supplier' => new SupplierResource($this->whenLoaded('tenant')),
            'unit_of_measure' => $this->unit_of_measure,
            'min_order_quantity' => $this->min_order_quantity,
            'lead_time_days' => $this->lead_time_days,
            'country_of_origin' => $this->country_of_origin,
            'price_visible' => $this->price_visible,
            'price' => $this->price_visible ? MoneyResource::make($this->base_price, $this->currency) : null,
            'price_tiers' => $this->whenLoaded('priceTiers', fn () => $this->priceTiers->map(fn ($t) => [
                'min_qty' => $t->min_qty,
                'max_qty' => $t->max_qty,
                'unit_price' => MoneyResource::make($t->unit_price, $t->currency),
            ])),
            // المعرّف يرافق الرابط ليتمكن المالك من حذف صورة بعينها
            'images' => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => [
                'id' => $m->id,
                'url' => asset('storage/'.$m->path),
                'thumb_url' => ImageThumbnail::url($m->path),
            ])),
            'cover_url' => $this->whenLoaded('media', fn () => $this->media->first()
                ? asset('storage/'.$this->media->first()->path)
                : null),
            'cover_thumb_url' => $this->whenLoaded('media', fn () => ImageThumbnail::url($this->media->first()?->path)),
            'status' => $this->status,
        ];
    }
}
