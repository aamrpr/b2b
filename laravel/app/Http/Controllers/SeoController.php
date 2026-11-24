<?php

namespace App\Http\Controllers;

use App\Support\Seo\PageMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SeoController extends Controller
{
    public function __construct(private readonly PageMeta $meta) {}

    /** بيانات المشاركة والفهرسة لمسار الواجهة، لا لمسار الواجهة البرمجية. */
    public function document(Request $request): JsonResponse
    {
        $path = (string) $request->query('path', '/');

        return response()->json($this->meta->resolve($path));
    }

    public function sitemap(): Response
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $body .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($this->meta->urls() as $url) {
            $body .= '  <url><loc>'.e($url['loc']).'</loc>';
            if ($url['lastmod']) {
                $body .= '<lastmod>'.e($url['lastmod']).'</lastmod>';
            }
            $body .= '<priority>'.e($url['priority']).'</priority></url>'."\n";
        }

        $body .= '</urlset>';

        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $sitemap = rtrim((string) config('app.frontend_url'), '/').'/sitemap.xml';

        $body = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /account',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /messages',
            'Disallow: /my/',
            'Disallow: /rfq',
            '',
            'Sitemap: '.$sitemap,
            '',
        ]);

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
