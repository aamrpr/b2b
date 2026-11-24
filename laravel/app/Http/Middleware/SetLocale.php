<?php

namespace App\Http\Middleware;

use App\Services\Localization\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(private readonly LocaleResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolver->resolve($request);

        app()->setLocale($locale);

        $response = $next($request);

        $response->headers->set('Content-Language', $locale);
        $response->headers->set('X-Locale-Direction', LocaleResolver::isRtl($locale) ? 'rtl' : 'ltr');

        return $response;
    }
}
