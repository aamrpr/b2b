<?php

namespace App\Notifications;

use App\Models\Rfq\Quotation;
use App\Models\User;

/** يصل لتاجر الجملة عندما يرسل مستورد عرض سعر على طلبه. */
class QuotationReceivedNotification extends PlatformNotification
{
    public function __construct(private readonly Quotation $quotation) {}

    public function event(): string
    {
        return 'quotation.received';
    }

    public function icon(): string
    {
        return 'receipt';
    }

    public function title(): array
    {
        $supplier = $this->quotation->supplier?->name ?? '';

        return [
            'ar' => "عرض سعر جديد من {$supplier}",
            'en' => "New quotation from {$supplier}",
        ];
    }

    public function body(): array
    {
        $amount = number_format($this->quotation->total / 100).' '.$this->quotation->currency;

        return [
            'ar' => "الإجمالي {$amount} · التسليم خلال {$this->quotation->lead_time_days} يوم",
            'en' => "Total {$amount} · delivery in {$this->quotation->lead_time_days} days",
        ];
    }

    public function actionUrl(): ?string
    {
        return $this->quotation->rfq_id ? "/rfqs/{$this->quotation->rfq_id}/quotations" : null;
    }

    public function payload(): array
    {
        return [
            'quotation_id' => $this->quotation->id,
            'rfq_id' => $this->quotation->rfq_id,
            'total' => $this->quotation->total,
            'currency' => $this->quotation->currency,
        ];
    }

    public function mailKey(): ?string
    {
        return 'quotation';
    }

    public function mailVariables(User $user): array
    {
        return [
            'supplier' => $this->quotation->supplier?->name ?? '',
            'reference' => $this->quotation->quotation_no,
            'amount' => number_format($this->quotation->total / 100, 2).' '.$this->quotation->currency,
            'days' => (string) ($this->quotation->lead_time_days ?? ''),
        ];
    }
}
