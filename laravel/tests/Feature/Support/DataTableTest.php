<?php

namespace Tests\Feature\Support;

use App\Models\Accounting\Currency;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * بروتوكول DataTables في الخادم.
 *
 * وأهمّ ما يُحرَس فيه ليس الفرز بل **القائمة البيضاء**: اسم العمود يأتي
 * من المتصفّح، ولو مرّ إلى orderBy أو where كما هو لصار باب حقنٍ في
 * الاستعلام. فما لم يعلنه المتحكّم يُهمَل صامتاً، وهذا ما تثبته هذه
 * الاختبارات قبل أيّ شيء آخر.
 */
class DataTableTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tenant $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $platform = $this->platform();
        $this->supplier = $this->makeSupplier('مستورد');

        $this->admin = User::create([
            'tenant_id' => $platform->id,
            'name' => 'مدير المنصة',
            'email' => 'admin@test.local',
            'password' => 'password',
            'status' => 'active',
        ]);

        $category = Category::create([
            'name' => ['ar' => 'قطع سيارات', 'en' => 'Auto Parts'],
            'slug' => 'auto-parts',
        ]);

        foreach ([
            ['فلتر زيت', 'Oil Filter', 'AAA-1', 'active', 30000],
            ['بطارية', 'Battery', 'BBB-2', 'active', 20000],
            ['مكابح', 'Brakes', 'CCC-3', 'draft', 10000],
        ] as [$ar, $en, $sku, $status, $price]) {
            Product::withoutGlobalScopes()->create([
                'tenant_id' => $this->supplier->id,
                'category_id' => $category->id,
                'name' => ['ar' => $ar, 'en' => $en],
                'slug' => strtolower($sku),
                'sku' => $sku,
                'base_price' => $price,
                'currency' => 'SYP',
                'status' => $status,
            ]);
        }
    }

    private function table(array $query = []): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson('/api/v1/admin/products?'.http_build_query($query + ['draw' => 1]));
    }

    public function test_it_answers_in_the_shape_the_library_expects(): void
    {
        $this->table(['length' => 2])
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data'])
            ->assertJsonPath('recordsTotal', 3)
            ->assertJsonPath('recordsFiltered', 3)
            ->assertJsonCount(2, 'data');
    }

    public function test_draw_comes_back_as_an_integer(): void
    {
        // قيمةٌ تعود كما جاءت نصّاً تُكتَب في الصفحة؛ فتُقسَر عدداً
        $response = $this->table(['draw' => '7"><script>alert(1)</script>']);

        $this->assertSame(7, $response->json('draw'));
    }

    public function test_the_global_search_reaches_both_languages_of_a_translated_column(): void
    {
        $this->table(['search' => ['value' => 'فلتر']])
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('recordsTotal', 3);

        $this->table(['search' => ['value' => 'Battery']])
            ->assertJsonPath('recordsFiltered', 1);
    }

    public function test_a_column_filter_narrows_one_column_alone(): void
    {
        $this->table([
            'columns' => [['data' => 'status', 'search' => ['value' => 'draft']]],
        ])
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.sku', 'CCC-3');
    }

    public function test_it_orders_by_a_declared_column(): void
    {
        $ascending = $this->table([
            'columns' => [['data' => 'sku']],
            'order' => [['column' => 0, 'dir' => 'asc']],
        ])->json('data.*.sku');

        $this->assertSame(['AAA-1', 'BBB-2', 'CCC-3'], $ascending);

        $descending = $this->table([
            'columns' => [['data' => 'sku']],
            'order' => [['column' => 0, 'dir' => 'desc']],
        ])->json('data.*.sku');

        $this->assertSame(['CCC-3', 'BBB-2', 'AAA-1'], $descending);
    }

    public function test_an_undeclared_column_is_ignored_not_passed_to_the_query(): void
    {
        /*
         * لو مرّ ما يأتي من المتصفّح إلى orderBy لانفجر الاستعلام أو —
         * وهو الأسوأ — نُفِّذ. فالمتوقَّع هنا ردٌّ سليم بالترتيب
         * الافتراضي، لا خطأ خادم.
         */
        $response = $this->table([
            'columns' => [['data' => 'password) OR 1=1 --']],
            'order' => [['column' => 0, 'dir' => 'asc']],
        ]);

        $response->assertOk()->assertJsonPath('recordsTotal', 3);
        $this->assertSame(['CCC-3', 'BBB-2', 'AAA-1'], $response->json('data.*.sku'));
    }

    public function test_an_undeclared_column_filter_is_ignored(): void
    {
        $this->table([
            'columns' => [['data' => 'currency', 'search' => ['value' => 'USD']]],
        ])->assertJsonPath('recordsFiltered', 3);
    }

    public function test_the_page_length_is_capped(): void
    {
        // ‎-1‎ تعني «الكلّ» عند المكتبة، ولا ينبغي أن تعني ذلك في جدولٍ كبير
        $this->table(['length' => -1])->assertOk()->assertJsonCount(3, 'data');
        $this->table(['length' => 99999])->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_an_export_call_takes_everything_that_matched_not_the_page(): void
    {
        // ملفٌّ فيه الصفحة المعروضة وحدها يظنّ صاحبه أنّه أخذ الجدول كلّه
        $response = $this->table(['length' => 1, 'start' => 2, 'export' => 1]);

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('truncated', false);
    }

    public function test_an_export_still_obeys_the_search(): void
    {
        $this->table(['export' => 1, 'search' => ['value' => 'فلتر']])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('recordsFiltered', 1);
    }

    public function test_records_total_counts_your_scope_not_the_whole_table(): void
    {
        $other = $this->makeSupplier('مستورد آخر');
        $user = User::create([
            'tenant_id' => $other->id,
            'name' => 'صاحب الكتالوج الآخر',
            'email' => 'other@test.local',
            'password' => 'password',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        // الكتالوج العام فيه ثلاثة منتجات، ولا شيء منها لهذا المستأجر
        $this->getJson('/api/v1/my/products?draw=1')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 0)
            ->assertJsonPath('recordsFiltered', 0);
    }

    public function test_my_products_search_covers_every_column_except_the_picture(): void
    {
        $owner = User::create([
            'tenant_id' => $this->supplier->id,
            'name' => 'صاحب الكتالوج',
            'email' => 'catalog@test.local',
            'password' => 'password',
            'status' => 'active',
        ]);

        Product::withoutGlobalScopes()->where('sku', 'BBB-2')->update([
            'min_order_quantity' => 24,
            'unit_of_measure' => 'box',
            'brand' => 'Volt',
        ]);

        ProductMedia::create([
            'product_id' => Product::withoutGlobalScopes()->where('sku', 'BBB-2')->value('id'),
            'path' => 'products/zz-cover-token.jpg',
            'type' => 'image',
            'sort_order' => 0,
        ]);

        Currency::create([
            'code' => 'SYP',
            'name' => ['ar' => 'ليرة سورية', 'en' => 'Syrian Pound'],
            'symbol' => 'ل.س',
            'decimal_places' => 2,
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $search = fn (string $term) => $this->getJson('/api/v1/my/products?'.http_build_query([
            'draw' => 1,
            'search' => ['value' => $term],
        ]));

        $search('بطارية')->assertOk()->assertJsonPath('recordsFiltered', 1)->assertJsonPath('data.0.sku', 'BBB-2');
        $search('Battery')->assertJsonPath('recordsFiltered', 1);
        $search('BBB-2')->assertJsonPath('recordsFiltered', 1);
        $search('Volt')->assertJsonPath('recordsFiltered', 1);
        $search('200')->assertJsonPath('recordsFiltered', 1)->assertJsonPath('data.0.sku', 'BBB-2');
        $search('box')->assertJsonPath('recordsFiltered', 1);
        $search('24 box')->assertJsonPath('recordsFiltered', 1);
        $search('مسودة')->assertJsonPath('recordsFiltered', 1)->assertJsonPath('data.0.sku', 'CCC-3');
        $search('ل.س')->assertJsonPath('recordsFiltered', 3);
        $search('zz-cover-token')->assertJsonPath('recordsFiltered', 0);

        $this->getJson('/api/v1/my/products?'.http_build_query([
            'draw' => 1,
            'columns' => [
                ['data' => 'price', 'search' => ['value' => '300']],
            ],
        ]))->assertOk()->assertJsonPath('recordsFiltered', 1)->assertJsonPath('data.0.sku', 'AAA-1');
    }
}
