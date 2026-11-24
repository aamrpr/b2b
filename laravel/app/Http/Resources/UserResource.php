<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * المستخدم كما تراه واجهته: هو وشركته معاً.
 *
 * الواجهة تقرأ من هذا الشكل في كل صفحة — الدور، العملة، حالة التوثيق —
 * فجُمع في مورد واحد بدل أن يُكرَّر بناؤه في كل مُصدِر للرمز.
 */
class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        $this->resource->loadMissing('tenant.supplierProfile');
        $tenant = $this->tenant;
        $profile = $tenant?->supplierProfile;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'avatar_url' => $this->avatar_path ? asset('storage/'.$this->avatar_path) : null,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'type' => $tenant->type,
                'name' => $profile?->t('display_name') ?? $tenant->name,
                'name_translations' => $profile?->display_name ?? new \stdClass,
                'description' => $profile?->t('description'),
                'description_translations' => $profile?->description ?? new \stdClass,
                'slug' => $tenant->slug,
                'city' => $tenant->city,
                'phone' => $profile?->phone,
                'whatsapp' => $profile?->whatsapp,
                'logo_url' => $profile?->logo_path ? asset('storage/'.$profile->logo_path) : null,
                'base_currency' => $tenant->base_currency,
                'status' => $tenant->status,
                'accounting_enabled' => $tenant->accounting_enabled,
                'verification_status' => $profile?->verification_status,
            ] : null,
        ];
    }
}
