<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\JournalEntry;
use App\Services\Accounting\JournalDraft;
use App\Services\Accounting\JournalPoster;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class JournalPosterTest extends TestCase
{
    use RefreshDatabase;

    private function poster(): JournalPoster
    {
        return app(JournalPoster::class);
    }

    public function test_it_posts_a_balanced_entry(): void
    {
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $amount = Money::of(100_000, 'SYP');

        $entry = $this->poster()->post(
            JournalDraft::for($supplier, 'SYP')
                ->describe('بيع نقدي')
                ->debit('cash', $amount)
                ->credit('sales_revenue', $amount)
        );

        $this->assertSame('posted', $entry->status);
        $this->assertTrue($entry->isBalanced());
        $this->assertCount(2, $entry->lines);
        $this->assertSame(100_000, $entry->total_debit);
    }

    public function test_it_refuses_an_unbalanced_entry(): void
    {
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $this->expectException(RuntimeException::class);

        $this->poster()->post(
            JournalDraft::for($supplier, 'SYP')
                ->describe('قيد مختل')
                ->debit('cash', Money::of(100_000, 'SYP'))
                ->credit('sales_revenue', Money::of(90_000, 'SYP'))
        );
    }

    public function test_the_same_event_never_produces_two_entries(): void
    {
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $amount = Money::of(50_000, 'SYP');

        $make = fn () => JournalDraft::for($supplier, 'SYP')
            ->describe('حدث متكرر')
            ->idempotent('test:event:1')
            ->debit('cash', $amount)
            ->credit('sales_revenue', $amount);

        $first = $this->poster()->post($make());
        $second = $this->poster()->post($make());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalEntry::withoutGlobalScope('tenant')
            ->where('tenant_id', $supplier->id)->count());
    }

    public function test_a_posted_entry_cannot_be_modified_or_deleted(): void
    {
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $amount = Money::of(10_000, 'SYP');

        $entry = $this->poster()->post(
            JournalDraft::for($supplier, 'SYP')
                ->describe('قيد مُرحَّل')
                ->debit('cash', $amount)
                ->credit('sales_revenue', $amount)
        );

        try {
            $entry->update(['description' => 'محاولة تعديل']);
            $this->fail('تعديل قيد مُرحَّل كان يجب أن يفشل.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        try {
            $entry->delete();
            $this->fail('حذف قيد مُرحَّل كان يجب أن يفشل.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
    }

    public function test_reversal_mirrors_the_original_entry(): void
    {
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $amount = Money::of(75_000, 'SYP');

        $entry = $this->poster()->post(
            JournalDraft::for($supplier, 'SYP')
                ->describe('قيد للعكس')
                ->debit('cash', $amount)
                ->credit('sales_revenue', $amount)
        );

        $reversal = $this->poster()->reverse($entry, 'تصحيح');

        $this->assertSame('reversal', $reversal->type);
        $this->assertSame('reversed', $entry->fresh()->status);
        $this->assertSame($reversal->id, $entry->fresh()->reversed_by_entry_id);

        // الحساب الذي كان مديناً أصبح دائناً
        $cashLine = $reversal->lines->first(fn ($l) => $l->account->system_key === 'cash');
        $this->assertSame(75_000, $cashLine->credit);
        $this->assertSame(0, $cashLine->debit);
    }

    public function test_each_tenant_keeps_its_own_ledger(): void
    {
        $first = $this->makeSupplier('First');
        $second = $this->makeSupplier('Second');

        $amount = Money::of(20_000, 'SYP');

        $this->actingAsTenant($first);
        $this->poster()->post(
            JournalDraft::for($first, 'SYP')
                ->describe('قيد المستورد الأول')
                ->debit('cash', $amount)
                ->credit('sales_revenue', $amount)
        );

        $this->actingAsTenant($second);
        $this->assertSame(0, JournalEntry::count(), 'دفتر المستورد الثاني يجب أن يكون فارغاً.');

        $this->actingAsTenant($first);
        $this->assertSame(1, JournalEntry::count());
    }
}
