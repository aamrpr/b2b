<?php

namespace Tests\Feature\Support;

use App\Support\ApiDocs\Document;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * الوثيقة تقول ما تفعله الواجهة فعلاً.
 *
 * ومواصفةٌ مكتوبة بيدٍ تنحرف بالصمت: يُضاف مسارٌ ولا يُوصَف، أو يُحذَف
 * ويبقى وصفه، فتصير الوثيقة أسوأ من لا وثيقة — من قرأها بُني على ما
 * لم يبق. فهذه الاختبارات هي ما يجعل الانحراف يفشل البناء.
 */
class ApiDocsTest extends TestCase
{
    public function test_every_registered_route_is_described(): void
    {
        $this->artisan('api:docs --check')->assertExitCode(0);
    }

    public function test_described_route_count_matches_the_route_table(): void
    {
        $registered = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
            ->sum(fn ($route) => count(array_diff($route->methods(), ['HEAD'])));

        $described = collect(Document::build()['paths'])->sum(fn (array $operations) => count($operations));

        $this->assertSame($registered, $described);
    }

    public function test_every_operation_is_explained(): void
    {
        foreach (Document::build()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $where = strtoupper($method)." {$path}";

                $this->assertNotEmpty($operation['summary'] ?? '', "لا عنوان لـ {$where}");
                $this->assertNotEmpty($operation['tags'] ?? [], "لا وسم لـ {$where}");
                $this->assertNotEmpty($operation['responses'] ?? [], "لا استجابة لـ {$where}");

                // سطر الصلاحية يُحقَن في كل وصف، فغيابه يعني أنّ الوصف لم يُزيَّن
                $this->assertStringContainsString('من يستدعيه', $operation['description'] ?? '', "لا وصف لـ {$where}");
            }
        }
    }

    public function test_path_parameters_are_declared(): void
    {
        foreach (Document::build()['paths'] as $path => $operations) {
            preg_match_all('/\{(\w+)\}/', $path, $matches);
            $expected = $matches[1];

            foreach ($operations as $method => $operation) {
                $declared = collect($operation['parameters'] ?? [])
                    ->where('in', 'path')
                    ->pluck('name')
                    ->all();

                sort($expected);
                sort($declared);

                $this->assertSame($expected, $declared, 'مُعاملات المسار في '.strtoupper($method)." {$path}");
            }
        }
    }

    public function test_every_reference_resolves(): void
    {
        $document = Document::build();

        foreach ($this->references($document) as $reference) {
            $node = $document;

            foreach (explode('/', ltrim($reference, '#/')) as $segment) {
                $this->assertIsArray($node, "إشارةٌ معطوبة: {$reference}");
                $this->assertArrayHasKey($segment, $node, "إشارةٌ لا تُحَلّ: {$reference}");
                $node = $node[$segment];
            }
        }
    }

    public function test_authenticated_operations_answer_401_and_public_ones_do_not(): void
    {
        foreach (Document::build()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $where = strtoupper($method)." {$path}";
                $isPublic = ($operation['security'] ?? null) === [];

                $this->assertSame(
                    ! $isPublic,
                    isset($operation['responses']['401']),
                    "استجابة ٤٠١ في {$where}",
                );
            }
        }
    }

    public function test_the_page_and_the_spec_are_served_when_enabled(): void
    {
        config(['b2b.docs.enabled' => true]);

        $this->get('api/docs')->assertOk()->assertSee('swagger', false);

        $this->getJson('api/docs/openapi.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonPath('info.version', '1.0.0')
            ->assertJsonStructure(['paths', 'tags', 'components' => ['schemas', 'responses', 'securitySchemes']]);
    }

    public function test_the_docs_are_hidden_when_disabled(): void
    {
        config(['b2b.docs.enabled' => false]);

        $this->get('api/docs')->assertNotFound();
        $this->get('api/docs/openapi.json')->assertNotFound();
    }

    /** @return list<string> كل إشارات $ref في الوثيقة */
    private function references(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $found[] = $value;

                continue;
            }

            $found = [...$found, ...$this->references($value)];
        }

        return $found;
    }
}
