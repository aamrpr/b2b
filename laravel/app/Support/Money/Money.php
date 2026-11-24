<?php

namespace App\Support\Money;

use InvalidArgumentException;

/**
 * قيمة نقدية ثابتة. تُخزَّن دائماً كعدد صحيح بالوحدة الصغرى (قرش/سنت)
 * لتفادي أخطاء الفاصلة العائمة في كل العمليات المالية.
 */
final readonly class Money
{
    public function __construct(
        public int $amount,       // بالوحدة الصغرى
        public string $currency,
    ) {
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException("رمز عملة غير صالح: {$currency}");
        }
    }

    public static function of(int $amount, string $currency): self
    {
        return new self($amount, strtoupper($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, strtoupper($currency));
    }

    /** من قيمة بالوحدة الكبرى (مثال: 125.50 ⟵ 12550) */
    public static function fromDecimal(float|string $value, string $currency, int $decimals = 2): self
    {
        return new self((int) round(((float) $value) * (10 ** $decimals)), strtoupper($currency));
    }

    public function toDecimal(int $decimals = 2): string
    {
        return number_format($this->amount / (10 ** $decimals), $decimals, '.', '');
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(float $factor): self
    {
        return new self((int) round($this->amount * $factor), $this->currency);
    }

    /** نسبة مئوية — تُستخدم للضريبة والعمولة */
    public function percentage(float $rate): self
    {
        return new self((int) round($this->amount * $rate / 100), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "لا يمكن الجمع بين عملتين مختلفتين: {$this->currency} و {$other->currency}"
            );
        }
    }
}
