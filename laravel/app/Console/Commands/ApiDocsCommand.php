<?php

namespace App\Console\Commands;

use App\Support\ApiDocs\Document;
use Illuminate\Console\Command;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * مواصفة OpenAPI: اطبعها، أو اكتبها ملفاً، أو قابلها بجدول المسارات.
 *
 * المواصفة مكتوبة بيد، والمكتوب بيدٍ ينحرف: يُضاف مسارٌ ولا يُوصَف،
 * أو يُحذَف ويبقى وصفه. فـ`--check` يقابل الوصف بالمسارات المسجَّلة
 * فعلاً في Laravel ويفشل عند أوّل فرق — ويجري في الاختبارات، فالانحراف
 * يُكشَف في البناء لا في وجه من قرأ الوثيقة.
 */
class ApiDocsCommand extends Command
{
    protected $signature = 'api:docs
        {--check : يقابل المواصفة بجدول المسارات ولا يكتب شيئاً}
        {--write : يكتب المواصفة إلى public/openapi.json}';

    protected $description = 'بناء مواصفة OpenAPI للواجهة البرمجية والتحقّق من مطابقتها للمسارات';

    public function handle(): int
    {
        $document = Document::build();
        $described = $this->describedRoutes($document);
        $registered = $this->registeredRoutes();

        $missing = array_values(array_diff($registered, $described));
        $extra = array_values(array_diff($described, $registered));

        if ($this->option('check')) {
            return $this->report($missing, $extra, count($registered));
        }

        if ($this->option('write')) {
            $path = public_path('openapi.json');
            file_put_contents(
                $path,
                json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            );

            $this->info("كُتبت المواصفة في {$path}");
            $this->line(sprintf('  %d مساراً · %d مخطَّطاً', count($described), count($document['components']['schemas'])));

            if ($missing !== [] || $extra !== []) {
                $this->newLine();
                $this->warn('ومع ذلك فيها فرقٌ عن جدول المسارات — شغّل api:docs --check.');
            }

            return self::SUCCESS;
        }

        $this->line((string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /** "GET /products/{slug}" لكل عملية في المواصفة. */
    private function describedRoutes(array $document): array
    {
        $signatures = [];

        foreach ($document['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $signatures[] = strtoupper($method).' '.$path;
            }
        }

        sort($signatures);

        return $signatures;
    }

    /** الشكل نفسه، من مسارات api/v1 المسجَّلة في Laravel. */
    private function registeredRoutes(): array
    {
        $signatures = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $path = '/'.substr($route->uri(), strlen('api/v1/'));

            foreach ($route->methods() as $method) {
                // HEAD يرافق GET تلقائياً، ولا يُوصَف على حدة
                if ($method === 'HEAD') {
                    continue;
                }

                $signatures[] = $method.' '.$path;
            }
        }

        sort($signatures);

        return $signatures;
    }

    private function report(array $missing, array $extra, int $total): int
    {
        if ($missing === [] && $extra === []) {
            $this->info("المواصفة تطابق جدول المسارات: {$total} مساراً، كلّها موصوفة.");

            return self::SUCCESS;
        }

        if ($missing !== []) {
            $this->newLine();
            $this->error('مسارات مسجَّلة وغير موصوفة ('.count($missing).'):');
            foreach ($missing as $signature) {
                $this->line("  <fg=red>−</> {$signature}");
            }
        }

        if ($extra !== []) {
            $this->newLine();
            $this->error('مسارات موصوفة وغير مسجَّلة ('.count($extra).'):');
            foreach ($extra as $signature) {
                $this->line("  <fg=yellow>+</> {$signature}");
            }
        }

        $this->newLine();
        $this->line('الوصف في app/Support/ApiDocs/Groups.');

        return self::FAILURE;
    }
}
