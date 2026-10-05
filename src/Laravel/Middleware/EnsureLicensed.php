<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Middleware;

use Closure;
use Faveo\LicenseClient\Laravel\LicenseException;
use Faveo\LicenseClient\Laravel\LicenseManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware:
 *   ->middleware('license')                 main product licence must be valid
 *   ->middleware('license:calendar')        add-on licence
 */
final class EnsureLicensed
{
    public function __construct(private readonly LicenseManager $licenses) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string $addon = ''): Response
    {
        if (! $this->excepted($request)) {
            $status = $this->licenses->status($addon);

            if (! $status->ok()) {
                throw new LicenseException($status, $this->licenses->message($status));
            }
        }

        return $next($request);
    }

    private function excepted(Request $request): bool
    {
        /** @var list<string> $except */
        $except = (array) config('license-client.middleware.except', []);

        return $except !== [] && $request->is(...$except);
    }
}
