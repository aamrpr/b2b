<?php

namespace App\Notifications;

use App\Models\User;

/** يصل للمستورد عند البتّ في توثيق حسابه. */
class SupplierVerificationNotification extends PlatformNotification
{
    public function __construct(
        private readonly bool $approved,
        private readonly ?string $reason = null,
    ) {}

    public function event(): string
    {
        return 'supplier.verification';
    }

    public function icon(): string
    {
        return $this->approved ? 'check-circle' : 'bell';
    }

    public function title(): array
    {
        return $this->approved
            ? ['ar' => 'تم اعتماد حسابك', 'en' => 'Your account is verified']
            : ['ar' => 'لم يُعتمد حسابك', 'en' => 'Your account was not approved'];
    }

    public function body(): array
    {
        if ($this->approved) {
            return [
                'ar' => 'أصبح حسابك ظاهراً في دليل المستوردين وستصلك طلبات عروض الأسعار في أصنافك.',
                'en' => 'You now appear in the importer directory and will receive RFQs in your categories.',
            ];
        }

        return ['ar' => (string) $this->reason, 'en' => (string) $this->reason];
    }

    public function actionUrl(): ?string
    {
        return '/';
    }

    public function payload(): array
    {
        return ['approved' => $this->approved];
    }

    public function mailKey(): ?string
    {
        return 'verification';
    }

    public function mailVariables(User $user): array
    {
        $en = $user->locale === 'en';

        return [
            'result' => $this->approved
                ? ($en ? 'Approved' : 'تم الاعتماد')
                : ($en ? 'Not approved' : 'لم يُعتمد'),
            'reason' => $this->reason ?? '',
        ];
    }
}
