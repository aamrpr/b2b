<?php

namespace App\Services\Accounting;

use App\Models\Tenancy\Tenant;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * مسوّدة قيد تُبنى بأسلوب سلس ثم تُرحَّل عبر JournalPoster.
 * الحسابات تُحدَّد بمفتاحها النظامي (system_key) لا بكودها الرقمي.
 */
class JournalDraft
{
    /** @var array<int, array{account_key: string, debit: Money|null, credit: Money|null, description: string|null, contact: Model|null}> */
    private array $lines = [];

    private ?string $description = null;

    private ?Model $source = null;

    private ?string $idempotencyKey = null;

    private string $type = 'auto';

    private Carbon $date;

    private function __construct(
        public readonly Tenant $tenant,
        public readonly string $currency,
    ) {
        $this->date = Carbon::today();
    }

    public static function for(Tenant $tenant, string $currency): self
    {
        return new self($tenant, strtoupper($currency));
    }

    public function on(Carbon|string $date): self
    {
        $this->date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $this;
    }

    public function debit(string $accountKey, Money $amount, ?string $description = null, ?Model $contact = null): self
    {
        return $this->line($accountKey, $amount, null, $description, $contact);
    }

    public function credit(string $accountKey, Money $amount, ?string $description = null, ?Model $contact = null): self
    {
        return $this->line($accountKey, null, $amount, $description, $contact);
    }

    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function from(Model $source): self
    {
        $this->source = $source;

        return $this;
    }

    /** مفتاح يمنع إنشاء القيد مرتين لنفس الحدث. */
    public function idempotent(string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    public function ofType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function totalDebit(): int
    {
        return array_sum(array_map(fn ($l) => $l['debit']?->amount ?? 0, $this->lines));
    }

    public function totalCredit(): int
    {
        return array_sum(array_map(fn ($l) => $l['credit']?->amount ?? 0, $this->lines));
    }

    public function isBalanced(): bool
    {
        return $this->totalDebit() === $this->totalCredit();
    }

    public function lines(): array
    {
        return $this->lines;
    }

    public function date(): Carbon
    {
        return $this->date;
    }

    public function descriptionText(): ?string
    {
        return $this->description;
    }

    public function source(): ?Model
    {
        return $this->source;
    }

    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function type(): string
    {
        return $this->type;
    }

    private function line(string $accountKey, ?Money $debit, ?Money $credit, ?string $description, ?Model $contact): self
    {
        $money = $debit ?? $credit;

        if ($money === null || $money->isNegative()) {
            throw new InvalidArgumentException('مبلغ سطر القيد يجب أن يكون موجباً.');
        }

        if ($money->currency !== $this->currency) {
            throw new InvalidArgumentException(
                "عملة السطر ({$money->currency}) لا تطابق عملة القيد ({$this->currency})."
            );
        }

        if ($money->isZero()) {
            return $this; // أسطر بصفر لا تُضاف
        }

        $this->lines[] = compact('accountKey', 'debit', 'credit', 'description', 'contact');

        return $this;
    }
}
