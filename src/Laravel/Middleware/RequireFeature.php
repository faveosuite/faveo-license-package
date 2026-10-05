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
 *   ->middleware('license.feature:sla')            feature granted by the main licence
 *   ->middleware('license.feature:sync,calendar')  feature granted by an add-on licence
 */
final class RequireFeature
{
    public function __construct(private readonly LicenseManager $licenses) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string $feature, string $addon = ''): Response
    {
        $status = $this->licenses->status($addon);

        if (! $status->allows($feature)) {
            $message = $status->ok() ? $this->licenses->text('feature_not_licensed') : $this->licenses->message($status);

            throw new LicenseException($status, $message, $feature);
        }

        return $next($request);
    }
}
