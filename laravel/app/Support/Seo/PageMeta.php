<?php

namespace App\Support\Seo;

use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Tenancy\Tenant;
use App\Support\Money\Money;

/**
 * بيانات الصفحة التي يقرأها محرك البحث وبطاقة مشاركة الرابط
 * قبل أن يعمل المتصفح. الصورة عنوان مطلق حتى تظهر في واتساب وغيره.
 */
final class PageMeta
{
    /** @return array<string, mixed> */
    public function resolve(string $rawPath): array
    {
        [$path, $query] = $this->normalize($rawPath);
        $segments = $path === '/' ? [] : explode('/', trim($path, '/'));

        $page = match ($segments[0] ?? '') {
            '' => $this->home(),
            'products' => $this->products($segments[1] ?? null, $query),
            'suppliers' => $this->suppliers($segments[1] ?? null, $query),
            'about' => $this->simple($this->copy('about'), $this->copy('aboutBody')),
            default => $this->missing(),
        };

        if ($this->isPrivate($path)) {
            $page['robots'] = 'noindex,nofollow';
        }

        $page['url'] = $this->canonical($path, $query);
        $page['locale'] = app()->getLocale() === 'en' ? 'en_US' : 'ar_SY';
        $page['site_name'] = $this->copy('name');
        $page['json_ld']['@context'] = 'https://schema.org';

        return $page;
    }

    /** @return array<int, array{loc: string, lastmod: string|null, priority: string}> */
    public function urls(): array
    {
        $urls = [
            $this->entry('/', '1.0', null),
            $this->entry('/products', '0.9', null),
            $this->entry('/suppliers', '0.9', null),
            $this->entry('/about', '0.4', null),
        ];

        foreach (Category::active()->orderBy('sort_order')->get() as $category) {
            $urls[] = $this->entry('/products?category='.$category->slug, '0.8', $category->updated_at?->toAtomString());
            if ($category->parent_id === null) {
                $urls[] = $this->entry('/suppliers?category='.$category->slug, '0.7', $category->updated_at?->toAtomString());
            }
        }

        foreach (Product::active()->orderBy('id')->get(['slug', 'updated_at']) as $product) {
            $urls[] = $this->entry('/products/'.$product->slug, '0.7', $product->updated_at?->toAtomString());
        }

        foreach (Tenant::query()->where('type', Tenant::TYPE_SUPPLIER)->where('status', 'active')->orderBy('id')->get(['slug', 'updated_at']) as $supplier) {
            $urls[] = $this->entry('/suppliers/'.$supplier->slug, '0.6', $supplier->updated_at?->toAtomString());
        }

        return $urls;
    }

    /** @param array<string, mixed> $query */
    private function products(?string $slug, array $query): array
    {
        if ($slug !== null) {
            if (! $this->isSlug($slug)) {
                return $this->missing();
            }

            $product = Product::active()
                ->where('slug', $slug)
                ->with(['media', 'category.parent', 'tenant.supplierProfile'])
                ->first();

            if ($product === null) {
                return $this->missing();
            }

            $name = (string) $product->t('name');
            $description = $this->excerpt($product->t('description'))
                ?: $this->copy('productFallback', $name);
            $images = $product->media
                ->map(fn ($media) => $this->storageUrl($media->path))
                ->filter()
                ->values()
                ->all();
            $image = $images[0] ?? $this->logo();

            $graph = [
                $this->breadcrumb([
                    [$this->copy('home'), '/'],
                    [$this->copy('products'), '/products'],
                    [$name, '/products/'.$product->slug],
                ]),
                array_filter([
                    '@type' => 'Product',
                    'name' => $name,
                    'description' => $description,
                    'image' => $images === [] ? [$image] : $images,
                    'sku' => $product->sku,
                    'url' => $this->canonical('/products/'.$product->slug, []),
                    'brand' => $product->brand ? ['@type' => 'Brand', 'name' => $product->brand] : null,
                    'category' => $product->category?->t('name'),
                    'offers' => $product->price_visible ? [
                        '@type' => 'Offer',
                        'url' => $this->canonical('/products/'.$product->slug, []),
                        'priceCurrency' => $product->currency,
                        'price' => Money::of((int) $product->base_price, $product->currency)->toDecimal(),
                        'availability' => 'https://schema.org/InStock',
                    ] : null,
                ]),
            ];

            return $this->page($name, $description, $image, $name, 'product', $graph);
        }

        $category = $this->categoryFromQuery($query);

        if ($category !== null) {
            return $this->categoryPage($category, '/products');
        }

        return $this->simple(
            $this->copy('products'),
            $this->copy('productsBody'),
            [
                $this->breadcrumb([
                    [$this->copy('home'), '/'],
                    [$this->copy('products'), '/products'],
                ]),
                [
                    '@type' => 'CollectionPage',
                    'name' => $this->copy('products'),
                    'description' => $this->copy('productsBody'),
                    'url' => $this->canonical('/products', []),
                ],
            ],
        );
    }

    /** @param array<string, mixed> $query */
    private function suppliers(?string $slug, array $query): array
    {
        if ($slug !== null) {
            if (! $this->isSlug($slug)) {
                return $this->missing();
            }

            $supplier = Tenant::query()
                ->where('type', Tenant::TYPE_SUPPLIER)
                ->where('status', 'active')
                ->where('slug', $slug)
                ->with('supplierProfile')
                ->first();

            if ($supplier === null) {
                return $this->missing();
            }

            $profile = $supplier->supplierProfile;
            $name = (string) ($profile?->t('display_name') ?: $supplier->name);
            $description = $this->excerpt($profile?->t('description'))
                ?: $this->copy('supplierFallback', $name);
            $image = $this->storageUrl($profile?->logo_path) ?? $this->logo();

            return $this->page($name, $description, $image, $name, 'profile', [
                $this->breadcrumb([
                    [$this->copy('home'), '/'],
                    [$this->copy('suppliers'), '/suppliers'],
                    [$name, '/suppliers/'.$supplier->slug],
                ]),
                array_filter([
                    '@type' => 'Organization',
                    'name' => $name,
                    'description' => $description,
                    'image' => $image,
                    'logo' => $image,
                    'url' => $this->canonical('/suppliers/'.$supplier->slug, []),
                    'address' => $supplier->city ? [
                        '@type' => 'PostalAddress',
                        'addressLocality' => $supplier->city,
                        'addressCountry' => $supplier->country_code,
                    ] : null,
                ]),
            ]);
        }

        $category = $this->categoryFromQuery($query);

        if ($category !== null) {
            return $this->categoryPage($category, '/suppliers');
        }

        return $this->simple(
            $this->copy('suppliers'),
            $this->copy('suppliersBody'),
            [
                $this->breadcrumb([
                    [$this->copy('home'), '/'],
                    [$this->copy('suppliers'), '/suppliers'],
                ]),
                [
                    '@type' => 'CollectionPage',
                    'name' => $this->copy('suppliers'),
                    'description' => $this->copy('suppliersBody'),
                    'url' => $this->canonical('/suppliers', []),
                ],
            ],
        );
    }

    private function categoryPage(Category $category, string $base): array
    {
        $name = (string) $category->t('name');
        $description = $this->excerpt($category->t('description'))
            ?: $this->copy('categoryFallback', $name);
        $root = $category->parent ?? $category;
        $image = $this->storageUrl($root->image_path) ?? $this->storageUrl($category->image_path) ?? $this->logo();
        $url = $base.'?category='.$category->slug;

        return $this->page($name, $description, $image, $name, 'website', [
            $this->breadcrumb([
                [$this->copy('home'), '/'],
                [$this->copy('categories'), '/#categories'],
                [$name, $url],
            ]),
            [
                '@type' => 'CollectionPage',
                'name' => $name,
                'description' => $description,
                'image' => $image,
                'url' => $this->canonical($base, ['category' => $category->slug]),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function home(): array
    {
        $name = $this->copy('name');
        $description = $this->copy('tagline').'. '.$this->copy('descriptor');

        return $this->page($this->copy('tagline'), $description, $this->logo(), $name, 'website', [
            [
                '@type' => 'Organization',
                'name' => $name,
                'alternateName' => 'ALMUSTAWRED',
                'url' => $this->origin(),
                'logo' => $this->logo(),
                'description' => $description,
            ],
            [
                '@type' => 'WebSite',
                'name' => $name,
                'url' => $this->origin(),
                'inLanguage' => app()->getLocale() === 'en' ? 'en' : 'ar',
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => $this->origin().'/products?q={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ], $name.' · '.$this->copy('tagline'));
    }

    /** @param array<int, array<string, mixed>> $graph */
    private function simple(string $heading, string $description, array $graph = []): array
    {
        return $this->page($heading, $description, $this->logo(), $heading, 'website', $graph);
    }

    /** @param array<int, array<string, mixed>> $graph */
    private function page(
        string $heading,
        string $description,
        string $image,
        string $imageAlt,
        string $type,
        array $graph,
        ?string $title = null,
    ): array {
        return [
            'title' => $title ?? ($heading.' · '.$this->copy('name')),
            'description' => $description,
            'image' => $image,
            'image_alt' => $imageAlt,
            'type' => $type,
            'robots' => 'index,follow',
            'json_ld' => ['@graph' => array_values(array_filter($graph))],
        ];
    }

    /** @return array<string, mixed> */
    private function missing(): array
    {
        $page = $this->home();
        $page['robots'] = 'noindex,nofollow';

        return $page;
    }

    /** @param array<string, mixed> $query */
    private function categoryFromQuery(array $query): ?Category
    {
        $slug = $query['category'] ?? null;

        if (! is_string($slug) || ! $this->isSlug($slug)) {
            return null;
        }

        return Category::active()->where('slug', $slug)->with('parent')->first();
    }

    /** @param array<int, array{0: string, 1: string}> $crumbs */
    private function breadcrumb(array $crumbs): array
    {
        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $crumb, int $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb[0],
                'item' => $this->absolute($crumb[1]),
            ], $crumbs, array_keys($crumbs)),
        ];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function normalize(string $rawPath): array
    {
        $rawPath = trim($rawPath);
        $parts = parse_url(str_contains($rawPath, '://') ? $rawPath : 'http://local'.$rawPath);
        $path = '/'.trim((string) ($parts['path'] ?? '/'), '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        if (str_contains($path, '..')) {
            $path = '/';
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        return [$path, $query];
    }

    /** @param array<string, mixed> $query */
    private function canonical(string $path, array $query): string
    {
        $kept = array_filter([
            'category' => is_string($query['category'] ?? null) ? $query['category'] : null,
            'q' => is_string($query['q'] ?? null) ? $query['q'] : null,
        ], fn ($value) => is_string($value) && $value !== '');

        $url = $this->absolute($path);

        return $kept === [] ? $url : $url.'?'.http_build_query($kept);
    }

    private function absolute(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/'.$path;
        }

        return $this->origin().($path === '/' ? '' : $path);
    }

    private function origin(): string
    {
        return rtrim((string) config('app.frontend_url'), '/');
    }

    private function logo(): string
    {
        return $this->origin().'/brand/logo.png';
    }

    private function storageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return asset('storage/'.$path);
    }

    private function excerpt(?string $text): string
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $text)));

        if ($plain === '') {
            return '';
        }

        if (mb_strlen($plain) <= 180) {
            return $plain;
        }

        return rtrim(mb_substr($plain, 0, 179)).'…';
    }

    private function isSlug(string $slug): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]{0,120}$/', $slug);
    }

    private function isPrivate(string $path): bool
    {
        foreach (['/login', '/register', '/account', '/messages', '/admin', '/my', '/rfq', '/rfqs', '/rfq-inbox'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @return array{loc: string, lastmod: string|null, priority: string} */
    private function entry(string $path, string $priority, ?string $lastmod): array
    {
        return [
            'loc' => $this->absolute(strtok($path, '?') ?: '/').(str_contains($path, '?') ? substr($path, (int) strpos($path, '?')) : ''),
            'lastmod' => $lastmod,
            'priority' => $priority,
        ];
    }

    private function copy(string $key, string $name = ''): string
    {
        $en = app()->getLocale() === 'en';

        $text = $en ? [
            'name' => 'ALMUSTAWRED',
            'tagline' => 'Your goods start here',
            'descriptor' => 'The link between wholesalers and importers',
            'home' => 'Home',
            'products' => 'Products',
            'suppliers' => 'Importers',
            'categories' => 'Categories',
            'about' => 'About',
            'aboutBody' => 'ALMUSTAWRED connects wholesalers with importers for wholesale trade.',
            'productsBody' => 'Browse wholesale products from verified importers: price, minimum quantity, and lead time.',
            'suppliersBody' => 'Importer directory. Choose a supplier by category and city, then contact them directly.',
            'productFallback' => 'Wholesale product on ALMUSTAWRED: '.$name,
            'supplierFallback' => $name.' on ALMUSTAWRED, the wholesale marketplace.',
            'categoryFallback' => 'Wholesale products in '.$name.' from importers on the marketplace.',
        ] : [
            'name' => 'المستورد',
            'tagline' => 'بضاعتك تبدأ من هون',
            'descriptor' => 'حلقة الوصل بين تاجر الجملة والمستورد',
            'home' => 'الرئيسية',
            'products' => 'المنتجات',
            'suppliers' => 'المستوردون',
            'categories' => 'الأصناف',
            'about' => 'عن المنصة',
            'aboutBody' => 'المستورد تربط تاجر الجملة بالمستورد لتجارة الجملة.',
            'productsBody' => 'تصفّح منتجات الجملة من مستوردين موثّقين: السعر، الكمية الدنيا، ومدة التوريد.',
            'suppliersBody' => 'دليل المستوردين. اختر مورّداً حسب الصنف والمدينة وتواصل معه مباشرة.',
            'productFallback' => 'منتج جملة على المستورد: '.$name,
            'supplierFallback' => $name.' على المستورد، سوق الجملة.',
            'categoryFallback' => 'منتجات الجملة في '.$name.' من مستوردين على المنصة.',
        ];

        return $text[$key] ?? '';
    }
}
