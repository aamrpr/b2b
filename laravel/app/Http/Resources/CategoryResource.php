<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\TranslatesFields;
use App\Support\Media\ImageThumbnail;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    use TranslatesFields;

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            ...$this->translated('name'),
            ...$this->translated('description'),
            'slug' => $this->slug,
            'icon' => $this->icon,
            'image_url' => $this->image_path ? asset('storage/'.$this->image_path) : null,
            'image_thumb_url' => ImageThumbnail::url($this->image_path),
            'is_featured' => $this->is_featured,
            'suppliers_count' => $this->suppliers_count,
            'products_count' => $this->products_count,
            'children' => CategoryResource::collection($this->whenLoaded('children')),
        ];
    }
}
