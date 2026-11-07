<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * A shop types a web address and our server calls it, so it must never be able to
 * point that call at our own network. Checked when saved and again at every send,
 * because what a name points to can change, and the call is then pinned to the
 * address that was checked.
 */
class StoreCallbackGuard
{
    /** @throws ValidationException */
    public function assertAcceptable(?string $url): void
    {
        if (blank($url)) {
            return;
        }

        $message = $this->problemWith($url);

        if ($message !== null) {
            throw ValidationException::withMessages(['callbackUrl' => $message]);
        }
    }

    public function problemWith(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || blank($parts['host'] ?? null)) {
            return 'Use a full https:// address.';
        }

        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return 'The address can\'t include a login or a port other than 443.';
        }

        if ($this->safeAddressFor($parts['host']) === null) {
            return 'That address can\'t be reached from the internet, or doesn\'t exist.';
        }

        return null;
    }

    /**
     * The address to connect to. Null if the name doesn't resolve, or if any address
     * it resolves to isn't public: one bad answer is enough to refuse it.
     */
    public function safeAddressFor(string $host): ?string
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return null;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                return null;
            }
        }

        return $addresses[0];
    }

    /** @return array<int, string> */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }

    public function isPublic(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $packed = inet_pton($address);

        if (strlen($packed) === 16) {
            // An IPv4 address dressed as IPv6 (::ffff:127.0.0.1) gets judged as IPv4.
            if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
                return $this->isPublic((string) inet_ntop(substr($packed, 12)));
            }

            return true;
        }

        // Carrier-grade NAT, 100.64.0.0/10, which the filter above lets through.
        return ! (ord($packed[0]) === 100 && (ord($packed[1]) & 0xC0) === 64);
    }
}
