<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * TRUSTED_PROXIES: the exact address(es) of the reverse proxy in front of the
 * app (Taqat), comma-separated IPs or CIDRs. Only requests arriving FROM one
 * of them have their X-Forwarded-* headers believed, so the client IP, the
 * scheme (https) and the host are the real ones. Empty = trust nothing (the
 * old behaviour). "*" is refused at boot: it would let anyone who can reach
 * the app port forge X-Forwarded-For.
 */
final class TrustedProxies
{
    /**
     * @param  string|list<string>|null  $value
     * @return list<string>|null
     */
    public static function parse(string|array|null $value): ?array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $proxies = array_values(array_filter(array_map('trim', $items), fn ($p) => $p !== ''));

        foreach ($proxies as $proxy) {
            if ($proxy === '*' || $proxy === '**') {
                throw new RuntimeException('TRUSTED_PROXIES must list the proxy address(es) exactly; "*" is not allowed because it lets anyone forge X-Forwarded-For. Read remote_addr from GET /api/v1/system/scheduler-status and set that.');
            }

            if (! self::isAddressOrRange($proxy)) {
                throw new RuntimeException("TRUSTED_PROXIES contains \"{$proxy}\", which is not an IP address or CIDR range.");
            }
        }

        return $proxies === [] ? null : $proxies;
    }

    /** Whether $remoteAddr is covered by the configured proxies (false when none are configured). */
    public static function covers(?string $remoteAddr, ?array $proxies): bool
    {
        return $remoteAddr !== null && $proxies !== null && $proxies !== [] && IpUtils::checkIp($remoteAddr, $proxies);
    }

    private static function isAddressOrRange(string $value): bool
    {
        [$ip, $bits] = array_pad(explode('/', $value, 2), 2, null);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

        return $bits === null || (ctype_digit($bits) && (int) $bits <= $max);
    }
}
