<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PurchasingApiTest extends PurchasingTestCase
{
    use RefreshDatabase;

    public function test_the_whole_cycle_runs_through_the_api(): void
    {
        $user = $this->user;
        $vendor = $this->vendor;
        $product = $this->product;

        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', [
                'vendor_id' => $vendor->id,
                'items' => [[
                    'product_id' => $product->id,
                    'description' => 'فلتر',
                    'quantity' => 50,
                    'unit_cost' => 30_000,
                    'tax_amount' => 225_000,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('order.status', 'draft');

        $orderId = $created->json('order.id');
        $itemId = $created->json('order.items.0.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$orderId}/send")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$orderId}/receive", [
                'items' => [['purchase_order_item_id' => $itemId, 'quantity' => 50]],
                'vendor_note_no' => 'TR-9981',
            ])
            ->assertCreated()
            ->assertJsonPath('order.status', 'received')
            ->assertJsonPath('order.items.0.pending_invoice', 50);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$orderId}/bill", ['vendor_invoice_no' => 'INV-TR-55'])
            ->assertCreated()
            ->assertJsonPath('invoice.subtotal', 50 * 30_000)
            ->assertJsonPath('invoice.tax_amount', 225_000);
    }

    public function test_billing_before_receiving_returns_422_not_500(): void
    {
        $user = $this->user;
        $vendor = $this->vendor;

        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', [
                'vendor_id' => $vendor->id,
                'items' => [['description' => 'خدمة', 'quantity' => 1, 'unit_cost' => 100_000]],
            ])
            ->assertCreated();

        $orderId = $created->json('order.id');

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/purchase-orders/{$orderId}/send")->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$orderId}/bill")
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_an_outsider_cannot_reach_another_tenants_order(): void
    {
        $user = $this->user;
        $vendor = $this->vendor;

        $orderId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', [
                'vendor_id' => $vendor->id,
                'items' => [['description' => 'صنف', 'quantity' => 1, 'unit_cost' => 1_000]],
            ])
            ->json('order.id');

        $other = $this->makeSupplier('Outsider');

        $stranger = User::create([
            'tenant_id' => $other->id,
            'name' => 'غريب',
            'email' => 'x'.$other->id.'@test.local',
            'password' => 'password',
        ]);

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/purchase-orders/{$orderId}")
            ->assertForbidden();

        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/v1/purchase-orders')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 0);
    }
}
