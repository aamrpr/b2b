<?php

namespace Tests\Feature\Returns;

use App\Models\Returns\ReturnRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** حدود الأدوار على مسارات المرتجع — من يفتح، ومن يبتّ، ومن لا يرى شيئاً. */
class ReturnApiTest extends ReturnsTestCase
{
    use RefreshDatabase;

    private function payload(int $quantity = 10): array
    {
        return [
            'reason' => 'damaged',
            'items' => [['order_item_id' => $this->line->id, 'quantity' => $quantity]],
        ];
    }

    private function openViaApi(int $quantity = 10): ReturnRequest
    {
        $this->actingAs($this->buyerUser, 'sanctum')
            ->postJson("/api/v1/orders/{$this->order->id}/returns", $this->payload($quantity))
            ->assertCreated();

        return ReturnRequest::latest('id')->firstOrFail();
    }

    public function test_the_buyer_opens_the_return_not_the_supplier(): void
    {
        $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson("/api/v1/orders/{$this->order->id}/returns", $this->payload())
            ->assertForbidden();

        $this->actingAs($this->buyerUser, 'sanctum')
            ->postJson("/api/v1/orders/{$this->order->id}/returns", $this->payload())
            ->assertCreated()
            ->assertJsonPath('return.status', 'requested');
    }

    public function test_the_supplier_decides_not_the_buyer(): void
    {
        $request = $this->openViaApi();

        $this->actingAs($this->buyerUser, 'sanctum')
            ->postJson("/api/v1/returns/{$request->id}/approve")
            ->assertForbidden();

        $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson("/api/v1/returns/{$request->id}/approve", ['note' => 'موافق'])
            ->assertOk()
            ->assertJsonPath('return.status', 'approved');
    }

    public function test_an_outsider_sees_nothing(): void
    {
        $request = $this->openViaApi();

        $outsider = $this->makeSupplier('Outsider');

        $stranger = User::create([
            'tenant_id' => $outsider->id,
            'name' => 'طرف ثالث',
            'email' => 'x'.$outsider->id.'@test.local',
            'password' => 'password',
        ]);

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/returns/{$request->id}")
            ->assertForbidden();

        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/v1/returns')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 0);
    }

    public function test_the_returnable_endpoint_reflects_earlier_returns(): void
    {
        $this->actingAs($this->buyerUser, 'sanctum')
            ->getJson("/api/v1/orders/{$this->order->id}/returnable")
            ->assertOk()
            ->assertJsonPath('items.0.returnable', 100);

        $this->openViaApi(25);

        $this->actingAs($this->buyerUser, 'sanctum')
            ->getJson("/api/v1/orders/{$this->order->id}/returnable")
            ->assertOk()
            ->assertJsonPath('items.0.returnable', 75);
    }

    public function test_the_full_cycle_runs_through_the_api_and_issues_a_credit_note(): void
    {
        $request = $this->openViaApi(20);
        $itemId = $request->items->first()->id;

        $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson("/api/v1/returns/{$request->id}/approve", ['quantities' => [$itemId => 20]])
            ->assertOk();

        $this->actingAs($this->buyerUser, 'sanctum')
            ->postJson("/api/v1/returns/{$request->id}/in-transit")
            ->assertOk();

        $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson("/api/v1/returns/{$request->id}/receive", ['quantities' => [$itemId => 18]])
            ->assertOk()
            ->assertJsonPath('return.status', 'received');

        $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson("/api/v1/returns/{$request->id}/complete")
            ->assertOk()
            ->assertJsonPath('credit_note.subtotal', 18 * $this->unitPrice);

        $this->assertSame('completed', $request->fresh()->status);
        $this->assertLedgersBalanced();
    }

    public function test_an_invalid_reason_is_rejected(): void
    {
        $this->actingAs($this->buyerUser, 'sanctum')
            ->postJson("/api/v1/orders/{$this->order->id}/returns", [
                'reason' => 'لأني غيّرت رأيي',
                'items' => [['order_item_id' => $this->line->id, 'quantity' => 5]],
            ])
            ->assertStatus(422);
    }
}
