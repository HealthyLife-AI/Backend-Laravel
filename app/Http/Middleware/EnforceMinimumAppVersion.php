<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiCodeException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audit MO-06: refuses a patient app older than config('app_versions') with
 * 426 `app_update_required`, so an installed build that cannot work with a
 * later API change shows a blocking update screen instead of failing oddly.
 *
 * Runs first in the `api` group, before authentication, so an app whose
 * token has expired (or that is signed out) still learns it must update.
 * Only me/* and auth/* are checked. No header, or one that does not parse,
 * passes: builds that never sent it are not locked out by this.
 */
class EnforceMinimumAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $sent = $this->version((string) $request->header('X-App-Version'));

        if ($sent !== null && $request->is('api/v1/me/*', 'api/v1/auth/*')) {
            $platform = strtolower((string) $request->header('X-App-Platform')) === 'ios' ? 'ios' : 'android';
            $minimum = (string) config("app_versions.min_supported.{$platform}", '0.0.0');

            if (version_compare($sent, $this->version($minimum) ?? '0.0.0', '<')) {
                throw new ApiCodeException(
                    trans('app_update.required', [], $this->locale($request)),
                    'app_update_required',
                    426,
                    ['store_url' => config("app_versions.store_url.{$platform}"), 'min_supported' => $minimum],
                );
            }
        }

        return $next($request);
    }

    /** "1.4.2" or "1.4.2+17" → "1.4.2"; anything else → null. */
    private function version(string $raw): ?string
    {
        return preg_match('/^\d+(?:\.\d+){0,3}/', trim($raw), $m) === 1 ? $m[0] : null;
    }

    /** Arabic unless the app asked for English: the patients are Arabic first. */
    private function locale(Request $request): string
    {
        return str_starts_with(strtolower((string) $request->header('Accept-Language')), 'en') ? 'en' : 'ar';
    }
}
