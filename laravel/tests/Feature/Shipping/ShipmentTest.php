<?php

namespace Tests\Feature\Shipping;

use App\Models\Accounting\Account;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Orders\Order;
use App\Models\Orders\OrderItem;
use App\Models\Shipping\Carrier;
use App\Models\Shipping\Shipment;
use App\Models\Shipping\ShipmentEvent;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Notifications\ShipmentUpdateNotification;
use App\Services\Shipping\ShipmentService;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ShipmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $supplier;

    private User $supplierUser;

    private Order $order;

    private OrderItem $line;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform();
        $this->supplier = $this->makeSupplier();
        $this->actingAsTenant($this->supplier);

        $this->supplierUser = User::create([
            'tenant_id' => $this->supplier->id,
            'name' => 'مستورد',
            'email' => 's'.$this->supplier->id.'@test.local',
            'password' => 'password',
        ]);

        $category = Category::firstOrCreate(
            ['slug' => 'auto-parts'],
            ['name' => ['ar' => 'قطع سيارات', 'en' => 'Auto Parts']]
        );

        $product = new Product([
            'category_id' => $category->id,
            'name' => ['ar' => 'فلتر', 'en' => 'Filter'],
            'slug' => 'filter-'.Str::lower(Str::random(6)),
            'base_price' => 50_000,
            'currency' => 'SYP',
            'status' => 'active',
        ]);
        $product->tenant_id = $this->supplier->id;
        $product->save();

        $buyer = app(TenantProvisioner::class)->createBuyer(['name' => 'تاجر جملة']);

        $buyerUser = User::create([
            'tenant_id' => $buyer->id,
            'name' => 'مشترٍ',
            'email' => 'b'.$buyer->id.'@test.local',
            'password' => 'password',
        ]);

        $this->order = Order::create([
            'order_no' => 'PO-'.Str::upper(Str::random(6)),
            'buyer_tenant_id' => $buyer->id,
            'supplier_tenant_id' => $this->supplier->id,
            'created_by' => $buyerUser->id,
            'subtotal' => 100 * 50_000,
            'total' => 100 * 50_000,
            'base_total' => 100 * 50_000,
            'currency' => 'SYP',
            'status' => 'confirmed',
        ]);

        $this->line = OrderItem::create([
            'order_id' => $this->order->id,
            'product_id' => $product->id,
            'description' => 'فلتر',
            'quantity' => 100,
            'unit_price' => 50_000,
            'unit_cost' => 30_000,
            'line_total' => 100 * 50_000,
        ]);

        $this->order->load('items');
    }

    private function service(): ShipmentService
    {
        return app(ShipmentService::class);
    }

    private function ship(int $quantity): Shipment
    {
        return $this->service()->create($this->order->fresh()->load('items'), $this->supplierUser, [
            'items' => [['order_item_id' => $this->line->id, 'quantity' => $quantity]],
        ]);
    }

    private function deliver(Shipment $shipment): Shipment
    {
        foreach (['ready', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered'] as $status) {
            $shipment = $this->service()->transition($shipment->fresh(), $status, $this->supplierUser);
        }

        return $shipment;
    }

    public function test_creating_a_shipment_marks_the_order_shipped_on_pickup(): void
    {
        $shipment = $this->ship(100);

        $this->assertSame('confirmed', $this->order->fresh()->status, 'الإنشاء وحده لا يشحن الطلب');

        $this->service()->transition($shipment, 'ready', $this->supplierUser);
        $this->service()->transition($shipment->fresh(), 'picked_up', $this->supplierUser);

        $this->assertSame('shipped', $this->order->fresh()->status);
    }

    public function test_the_order_is_delivered_only_when_every_quantity_arrives(): void
    {
        $first = $this->ship(60);
        $this->deliver($first);

        $this->assertSame('shipped', $this->order->fresh()->status, 'الشحن الجزئي لا يُسلّم الطلب');

        $second = $this->ship(40);
        $this->deliver($second);

        $this->assertSame('delivered', $this->order->fresh()->status);
    }

    public function test_full_delivery_posts_the_invoice_cost_and_commission(): void
    {
        $this->deliver($this->ship(100));

        $order = $this->order->fresh()->load('commission', 'invoices');

        $this->assertNotNull($order->invoices->first(), 'الفاتورة لم تُصدَر');
        $this->assertNotNull($order->commission, 'العمولة لم تُستحق');

        $cogs = Account::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->supplier->id)->where('system_key', 'cogs')->first();

        $this->assertSame(100 * 30_000, $cogs->balance());
    }

    public function test_shipping_more_than_remaining_is_refused(): void
    {
        $this->ship(60);

        $this->expectException(RuntimeException::class);
        $this->ship(50);
    }

    public function test_pending_quantities_shrink_with_each_shipment(): void
    {
        $this->assertSame([$this->line->id => 100], $this->service()->pendingQuantities($this->order));

        $this->ship(30);
        $this->assertSame([$this->line->id => 70], $this->service()->pendingQuantities($this->order->fresh()->load('items')));

        $this->ship(70);
        $this->assertSame([$this->line->id => 0], $this->service()->pendingQuantities($this->order->fresh()->load('items')));
    }

    public function test_status_transitions_follow_the_allowed_flow(): void
    {
        $shipment = $this->ship(100);

        // قفزة من draft إلى delivered مرفوضة
        $this->assertFalse($shipment->canMoveTo('delivered'));

        $this->expectException(RuntimeException::class);
        $this->service()->transition($shipment, 'delivered', $this->supplierUser);
    }

    public function test_a_delivered_shipment_cannot_go_backwards(): void
    {
        $shipment = $this->deliver($this->ship(100));

        $this->assertSame([], $shipment->allowedTransitions());
    }

    public function test_every_transition_appends_an_immutable_event(): void
    {
        $shipment = $this->ship(100);
        $this->service()->transition($shipment, 'ready', $this->supplierUser, 'دمشق');

        $events = ShipmentEvent::where('shipment_id', $shipment->id)->get();

        $this->assertCount(2, $events, 'حدث الإنشاء + حدث الجاهزية');

        $this->expectException(RuntimeException::class);
        $events->first()->update(['status' => 'tampered']);
    }

    public function test_shipping_cost_posts_to_the_expense_account_once(): void
    {
        $shipment = $this->service()->create($this->order, $this->supplierUser, [
            'shipping_cost' => 14_250,
            'items' => [['order_item_id' => $this->line->id, 'quantity' => 100]],
        ]);

        $this->service()->postShippingCost($shipment);
        $this->service()->postShippingCost($shipment->fresh());

        $expense = Account::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->supplier->id)->where('system_key', 'shipping_expense')->first();

        $this->assertSame(14_250, $expense->balance());
    }

    public function test_the_public_token_is_unguessable_and_scoped_to_one_shipment(): void
    {
        $first = $this->ship(50);
        $second = $this->ship(50);

        $this->assertSame(32, strlen($first->tracking_token));
        $this->assertNotSame($first->tracking_token, $second->tracking_token);

        $this->getJson("/api/v1/track/{$first->tracking_token}")
            ->assertOk()
            ->assertJsonPath('shipment.shipment_no', $first->shipment_no);

        $this->getJson('/api/v1/track/'.Str::random(32))->assertNotFound();
    }

    public function test_the_buyer_is_notified_of_each_update(): void
    {
        Notification::fake();

        $shipment = $this->ship(100);
        $this->service()->transition($shipment, 'ready', $this->supplierUser);

        Notification::assertSentTo(
            User::where('tenant_id', $this->order->buyer_tenant_id)->get(),
            ShipmentUpdateNotification::class
        );
    }

    public function test_a_carrier_quote_picks_the_matching_rate(): void
    {
        $carrier = Carrier::create([
            'name' => ['ar' => 'ناقل', 'en' => 'Carrier'],
            'code' => 'TEST',
            'is_active' => true,
        ]);

        $carrier->rates()->create([
            'from_city' => 'دمشق',
            'to_city' => 'حلب',
            'min_weight_kg' => 0,
            'max_weight_kg' => 50,
            'base_price' => 750_000,
            'price_per_kg' => 15_000,
            'transit_days' => 2,
        ]);

        $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson('/api/v1/carriers/quote', [
                'from_city' => 'دمشق',
                'to_city' => 'حلب',
                'weight_kg' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('quotes.0.amount', 750_000 + 20 * 15_000);
    }
}
