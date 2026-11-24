<?php

namespace App\Services\Returns;

use App\Exceptions\BusinessRuleException;
use App\Models\Orders\Order;
use App\Models\Returns\Dispute;
use App\Models\Returns\DisputeEvent;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Notifications\DisputeUpdateNotification;
use App\Services\Accounting\DocumentSequencer;
use App\Services\Accounting\JournalDraft;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\Posting\CommissionPostingService;
use App\Services\Admin\AuditLogger;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * النزاعات ووساطة المنصة.
 *
 * المنصة ليست طرفاً — هي وسيط. وقرارها يُترجَم إلى أثر مالي في
 * دفتر المستورد (إشعار دائن) لا في دفترها، باستثناء حصّتها من العمولة.
 */
class DisputeService
{
    /** مهلة ردّ الطرف الآخر بالأيام */
    private const RESPONSE_WINDOW_DAYS = 5;

    public function __construct(
        private readonly DocumentSequencer $sequencer,
        private readonly JournalPoster $poster,
        private readonly CommissionPostingService $commissions,
        private readonly AuditLogger $audit,
    ) {}

    public function open(Order $order, User $actor, array $data): Dispute
    {
        $tenantId = $actor->tenant_id;

        if (! in_array($tenantId, [$order->buyer_tenant_id, $order->supplier_tenant_id], true)) {
            throw new BusinessRuleException('النزاع يفتحه طرف الصفقة فقط.');
        }

        $existing = Dispute::where('order_id', $order->id)
            ->whereIn('status', ['open', 'under_review', 'awaiting_party'])
            ->first();

        if ($existing) {
            throw new BusinessRuleException("يوجد نزاع مفتوح على هذا الطلب ({$existing->reference_no}).");
        }

        return DB::transaction(function () use ($order, $actor, $data, $tenantId) {
            $dispute = Dispute::create([
                'order_id' => $order->id,
                'return_request_id' => $data['return_request_id'] ?? null,
                'buyer_tenant_id' => $order->buyer_tenant_id,
                'supplier_tenant_id' => $order->supplier_tenant_id,
                'opened_by_tenant_id' => $tenantId,
                'reference_no' => $this->sequencer->next(null, 'dispute', 'DSP'),
                'reason' => $data['reason'],
                'description' => $data['description'],
                'claimed_amount' => $data['claimed_amount'] ?? 0,
                'currency' => $order->currency,
                'status' => 'open',
                'due_at' => now()->addDays(self::RESPONSE_WINDOW_DAYS),
                'created_by' => $actor->id,
            ]);

            $this->append($dispute, $actor, 'status_change', 'فُتح النزاع', meta: ['status' => 'open']);

            $this->notifyParties($dispute, 'opened', except: $tenantId);

            return $dispute;
        });
    }

    public function addMessage(Dispute $dispute, ?User $actor, string $body, ?string $filePath = null, bool $internal = false): DisputeEvent
    {
        if (! $dispute->isOpen()) {
            throw new BusinessRuleException('النزاع مُقفَل — لا تُضاف إليه رسائل.');
        }

        $event = $this->append(
            $dispute,
            $actor,
            $filePath ? 'evidence' : 'message',
            $body,
            $filePath,
            $internal,
        );

        // ردّ أحد الطرفين يعيد الكرة إلى ملعب الوسيط
        if (! $internal && $dispute->status === 'awaiting_party') {
            $dispute->update(['status' => 'under_review', 'due_at' => null]);
        }

        if (! $internal) {
            $this->notifyParties($dispute, 'message', except: $actor?->tenant_id);
        }

        return $event;
    }

    /** إسناد النزاع إلى وسيط من المنصة. */
    public function assign(Dispute $dispute, User $mediator): Dispute
    {
        if (! $mediator->isPlatformUser()) {
            throw new BusinessRuleException('الوساطة من مستخدمي المنصة فقط.');
        }

        $dispute->update([
            'assigned_to' => $mediator->id,
            'status' => $dispute->status === 'open' ? 'under_review' : $dispute->status,
        ]);

        $this->append($dispute, $mediator, 'status_change', 'بدأت مراجعة المنصة', meta: ['status' => 'under_review']);
        $this->audit->record('dispute.assigned', $dispute, [], ['mediator' => $mediator->id]);

        return $dispute->fresh();
    }

    /** طلب إيضاح من طرف — يوقف العدّاد على المنصة ويضع مهلة على الطرف. */
    public function requestResponse(Dispute $dispute, User $mediator, string $message): Dispute
    {
        if (! $dispute->canMoveTo('awaiting_party')) {
            throw new BusinessRuleException('لا يمكن طلب إيضاح في هذه الحالة.');
        }

        $dispute->update([
            'status' => 'awaiting_party',
            'due_at' => now()->addDays(self::RESPONSE_WINDOW_DAYS),
        ]);

        $this->append($dispute, $mediator, 'message', $message);
        $this->notifyParties($dispute, 'awaiting_party');

        return $dispute->fresh();
    }

    /**
     * قرار الوسيط.
     *
     * لصالح المشتري بمبلغ ⟵ إشعار دائن في دفتر المستورد وعكس حصّة العمولة.
     * لصالح المستورد ⟵ لا أثر مالي، النزاع يُقفَل فقط.
     */
    public function resolve(Dispute $dispute, User $mediator, array $data): Dispute
    {
        if (! $mediator->isPlatformUser()) {
            throw new BusinessRuleException('القرار من وسيط المنصة فقط.');
        }

        if (! $dispute->canMoveTo('resolved')) {
            throw new BusinessRuleException('لا يمكن إصدار قرار في هذه الحالة.');
        }

        $outcome = $data['outcome'];
        $amount = (int) ($data['resolution_amount'] ?? 0);

        if (in_array($outcome, ['buyer_favored', 'partial'], true) && $amount <= 0) {
            throw new BusinessRuleException('القرار لصالح المشتري يحتاج مبلغ تعويض.');
        }

        if ($amount > $dispute->claimed_amount && $dispute->claimed_amount > 0) {
            throw new BusinessRuleException('مبلغ التعويض يتجاوز المبلغ المُطالَب به.');
        }

        return DB::transaction(function () use ($dispute, $mediator, $data, $outcome, $amount) {
            $dispute->update([
                'status' => 'resolved',
                'outcome' => $outcome,
                'resolution_amount' => $amount,
                'resolution_note' => $data['resolution_note'] ?? null,
                'resolved_by' => $mediator->id,
                'resolved_at' => now(),
                'due_at' => null,
            ]);

            $this->append($dispute, $mediator, 'decision', $data['resolution_note'] ?? 'صدر القرار', meta: [
                'outcome' => $outcome,
                'amount' => $amount,
            ]);

            if ($amount > 0) {
                $this->postCompensation($dispute, Money::of($amount, $dispute->currency));
            }

            $this->audit->record('dispute.resolved', $dispute, [], [
                'outcome' => $outcome,
                'amount' => $amount,
            ]);

            $this->notifyParties($dispute, 'resolved');

            return $dispute->fresh();
        });
    }

    public function close(Dispute $dispute, User $actor, ?string $note = null): Dispute
    {
        if (! $dispute->canMoveTo('closed')) {
            throw new BusinessRuleException('لا يمكن إقفال النزاع في هذه الحالة.');
        }

        $dispute->update(['status' => 'closed', 'due_at' => null]);
        $this->append($dispute, $actor, 'status_change', $note ?? 'أُقفل النزاع', meta: ['status' => 'closed']);

        return $dispute->fresh();
    }

    /**
     * قيد التعويض: خصم مسموح به في دفتر المستورد مقابل ذمة المشتري،
     * ثم عكس حصّة المنصة من العمولة على المبلغ ذاته.
     */
    private function postCompensation(Dispute $dispute, Money $amount): void
    {
        $order = $dispute->order;
        $supplier = $order->supplier;

        $this->poster->post(
            JournalDraft::for($supplier, $amount->currency)
                ->describe("تعويض نزاع {$dispute->reference_no}")
                ->from($dispute)
                ->idempotent("dispute_compensation:dispute:{$dispute->id}")
                ->debit('discount_allowed', $amount)
                ->credit('accounts_receivable', $amount, null, $order->buyer)
        );

        $this->commissions->reversePortion($order, $amount, "dispute:{$dispute->id}");
    }

    private function append(
        Dispute $dispute,
        ?User $actor,
        string $type,
        ?string $body,
        ?string $filePath = null,
        bool $internal = false,
        array $meta = [],
    ): DisputeEvent {
        return DisputeEvent::create([
            'dispute_id' => $dispute->id,
            'tenant_id' => $actor?->isPlatformUser() ? null : $actor?->tenant_id,
            'user_id' => $actor?->id,
            'type' => $type,
            'body' => $body,
            'file_path' => $filePath,
            'meta' => $meta ?: null,
            'is_internal' => $internal,
        ]);
    }

    private function notifyParties(Dispute $dispute, string $event, ?int $except = null): void
    {
        $tenantIds = collect([$dispute->buyer_tenant_id, $dispute->supplier_tenant_id])
            ->reject(fn ($id) => $id === $except);

        User::whereIn('tenant_id', $tenantIds)
            ->where('status', 'active')
            ->get()
            ->each(fn (User $user) => $user->notify(new DisputeUpdateNotification($dispute, $event)));
    }

    /** النزاعات التي تجاوزت مهلة الردّ — تُعرض للوسيط أولاً. */
    public function overdue(): Collection
    {
        return Dispute::query()
            ->whereIn('status', ['open', 'awaiting_party'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->with(['order', 'buyer', 'supplier'])
            ->orderBy('due_at')
            ->get();
    }

    /** الطرف الذي ليس من المنصة في سياق مستأجر. */
    public function counterpart(Dispute $dispute, int $tenantId): ?Tenant
    {
        return $tenantId === $dispute->buyer_tenant_id ? $dispute->supplier : $dispute->buyer;
    }
}
