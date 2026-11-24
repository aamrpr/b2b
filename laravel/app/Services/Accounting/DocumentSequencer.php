<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

/**
 * ترقيم متسلسل بلا فجوات ولا تكرار تحت التزامن.
 * يُستخدم قفل متشائم على سطر التسلسل داخل معاملة الاستدعاء.
 */
class DocumentSequencer
{
    public function next(?int $tenantId, string $documentType, string $defaultPrefix = ''): string
    {
        return DB::transaction(function () use ($tenantId, $documentType, $defaultPrefix) {
            $row = DB::table('document_sequences')
                ->where('tenant_id', $tenantId)
                ->where('document_type', $documentType)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                DB::table('document_sequences')->insert([
                    'tenant_id' => $tenantId,
                    'document_type' => $documentType,
                    'prefix' => $defaultPrefix,
                    'next_number' => 1,
                    'padding' => 5,
                    'reset_frequency' => 'yearly',
                    'period_key' => now()->format('Y'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('document_sequences')
                    ->where('tenant_id', $tenantId)
                    ->where('document_type', $documentType)
                    ->lockForUpdate()
                    ->first();
            }

            $periodKey = match ($row->reset_frequency) {
                'yearly' => now()->format('Y'),
                'monthly' => now()->format('Y-m'),
                default => null,
            };

            $number = ($periodKey !== null && $row->period_key !== $periodKey) ? 1 : (int) $row->next_number;

            DB::table('document_sequences')
                ->where('id', $row->id)
                ->update([
                    'next_number' => $number + 1,
                    'period_key' => $periodKey,
                    'updated_at' => now(),
                ]);

            $parts = array_filter([
                $row->prefix ?: null,
                $periodKey,
                str_pad((string) $number, $row->padding, '0', STR_PAD_LEFT),
            ]);

            return implode('-', $parts);
        });
    }
}
