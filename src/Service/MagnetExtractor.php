<?php

namespace TryHackX\HomepageBlocks\Service;

/**
 * Wyciąga info-hashe magnetów z treści posta (surowy XML s9e po sformatowaniu LUB
 * czysty tekst). Źródła, w tej kolejności:
 *
 *   1. ciała tagów <MAGNET>…</MAGNET> (BBCode [magnet] z tryhackx/flarum-magnet-link),
 *   2. zdekodowany tekst całego posta: URI `magnet:?…` oraz fragmenty `btih:<hash>`,
 *   3. (opcjonalnie) gołe 40-znakowe hex — po usunięciu poddrzew <URL>, <CODE>, <C>,
 *      <EMAIL>, w których commit-SHA / IOC-e są niemal pewnymi fałszywymi trafieniami.
 *
 * Wynik: [hash (lowercase hex40) => ['magnet' => string|null, 'name' => string|null]],
 * zdeduplikowany po hashu (pierwsze wystąpienie wygrywa). Base32 → hex.
 */
final class MagnetExtractor
{
    /**
     * @return array<string, array{magnet: ?string, name: ?string}>
     */
    public static function extract(string $xmlOrText, bool $bareHashes = false): array
    {
        $found = [];
        $add = function (string $hash, ?string $magnet, ?string $name) use (&$found): void {
            $hash = strtolower($hash);
            if (!isset($found[$hash])) {
                $found[$hash] = ['magnet' => $magnet, 'name' => $name];
            } elseif ($found[$hash]['magnet'] === null && $magnet !== null) {
                $found[$hash]['magnet'] = $magnet;
                if ($found[$hash]['name'] === null) $found[$hash]['name'] = $name;
            }
        };

        // 1) <MAGNET> bodies (strip s9e <s>/<e> markers, decode entities)
        if (stripos($xmlOrText, '<MAGNET') !== false && preg_match_all('/<MAGNET[^>]*>(.*?)<\/MAGNET>/is', $xmlOrText, $m)) {
            foreach ($m[1] as $body) {
                $body = preg_replace('/<s>.*?<\/s>/is', '', $body);
                $body = preg_replace('/<e>.*?<\/e>/is', '', $body);
                $uri = trim(html_entity_decode(strip_tags((string) $body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                foreach (self::hashesFromMagnet($uri) as [$hash, $name]) {
                    $add($hash, $uri, $name);
                }
            }
        }

        // 2) decoded full text: magnet URIs and btih fragments
        $text = html_entity_decode($xmlOrText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match_all('/magnet:\?[^\s"\'<>\[\]]+/i', $text, $mm)) {
            foreach ($mm[0] as $uri) {
                foreach (self::hashesFromMagnet($uri) as [$hash, $name]) {
                    $add($hash, $uri, $name);
                }
            }
        }
        if (preg_match_all('/btih:([a-f0-9]{40}|[a-z2-7]{32})(?![a-z0-9])/i', $text, $bm)) {
            foreach ($bm[1] as $raw) {
                $hash = self::normalizeHash($raw);
                if ($hash !== null) $add($hash, null, null);
            }
        }

        // 3) bare 40-hex (opt-in) — outside URLs / code / emails
        if ($bareHashes) {
            $stripped = preg_replace('/<(URL|CODE|C|EMAIL)\b[^>]*>.*?<\/\1>/is', ' ', $xmlOrText) ?? $xmlOrText;
            $stripped = html_entity_decode(strip_tags($stripped), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match_all('/(?<![a-f0-9])([a-f0-9]{40})(?![a-f0-9])/i', $stripped, $hm)) {
                foreach ($hm[1] as $raw) $add(strtolower($raw), null, null);
            }
        }

        return $found;
    }

    // ───────── "Only sync magnets that point at our tracker" ─────────

    /**
     * Keeps only the entries whose magnet URI announces to one of $hosts (see magnetPointsAt()).
     * Entries without a magnet (bare btih: fragments / bare 40-hex) never match — the tracker
     * cannot be inferred from a hash alone. Returns [kept entries, skipped entries] (same shape).
     *
     * @param  array<string, array{magnet: ?string, name: ?string}> $found  output of extract()
     * @param  string[] $hosts  configured hosts, see parseHostList()
     * @return array{0: array<string, array{magnet: ?string, name: ?string}>, 1: array<string, array{magnet: ?string, name: ?string}>}
     */
    public static function filterByTrackerHosts(array $found, array $hosts): array
    {
        $kept = [];
        $skipped = [];
        foreach ($found as $hash => $meta) {
            $magnet = $meta['magnet'] ?? null;
            if ($magnet !== null && $magnet !== '' && self::magnetPointsAt($magnet, $hosts)) {
                $kept[$hash] = $meta;
            } else {
                $skipped[$hash] = $meta;
            }
        }
        return [$kept, $skipped];
    }

    /** True when at least one `tr=` announce URL of the magnet has a host from $hosts (case-insensitive). */
    public static function magnetPointsAt(string $magnet, array $hosts): bool
    {
        if (!$hosts) return false;
        $want = [];
        foreach ($hosts as $h) {
            $h = self::hostOf((string) $h);
            if ($h !== null) $want[$h] = true;
        }
        if (!$want) return false;
        foreach (self::trackerHostsOf($magnet) as $host) {
            if (isset($want[$host])) return true;
        }
        return false;
    }

    /** Lowercase hosts of every `tr=` parameter of a magnet URI (deduplicated). @return string[] */
    public static function trackerHostsOf(string $magnet): array
    {
        if (!preg_match_all('/[?&]tr=([^&\s]+)/i', $magnet, $m)) return [];
        $hosts = [];
        foreach ($m[1] as $raw) {
            $h = self::hostOf(rawurldecode(str_replace('+', '%20', $raw)));
            if ($h !== null) $hosts[$h] = true;
        }
        return array_keys($hosts);
    }

    /**
     * Admin setting → host list: comma / semicolon / whitespace / newline separated entries, each a
     * hostname, an IP, `host:port` or a full URL. Normalised to lowercase hosts, deduplicated.
     * @return string[]
     */
    public static function parseHostList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $entry) {
            $h = self::hostOf($entry);
            if ($h !== null) $out[$h] = true;
        }
        return array_keys($out);
    }

    /** Lowercase host of a URL, a `host:port` pair or a bare hostname / IP; null when unparsable. */
    public static function hostOf(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') return null;
        $host = preg_match('#^[a-z][a-z0-9+.\-]*://#i', $s) ? parse_url($s, PHP_URL_HOST) : parse_url('//' . $s, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            // parse_url() rejects a few odd shapes (e.g. "host:port/path" without scheme) — fall back to
            // "everything before the first ':' or '/'", which is all we need for a hostname / IPv4.
            if (!preg_match('#^([a-z0-9._\-]+)(?::\d+)?(?:[/?\#]|$)#i', $s, $m)) return null;
            $host = $m[1];
        }
        $host = strtolower(rtrim($host, '.'));
        return $host !== '' ? $host : null;
    }

    /** @return array<int, array{0: string, 1: ?string}> [[hash, name], …] — usually one entry */
    private static function hashesFromMagnet(string $uri): array
    {
        if (!preg_match('/^magnet:\?/i', $uri)) return [];
        if (!preg_match_all('/[?&]xt=urn:btih:([a-f0-9]{40}|[a-z2-7]{32})(?![a-z0-9])/i', $uri, $m)) return [];
        $name = null;
        if (preg_match('/[?&]dn=([^&]+)/i', $uri, $dn)) {
            $name = trim(rawurldecode(str_replace('+', ' ', $dn[1])));
            $name = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $name) ?? '';
            $name = $name !== '' ? mb_substr($name, 0, 255) : null;
        }
        $out = [];
        foreach ($m[1] as $raw) {
            $hash = self::normalizeHash($raw);
            if ($hash !== null) $out[] = [$hash, $name];
        }
        return $out;
    }

    /** hex40 or base32-32 → lowercase hex40, or null. */
    public static function normalizeHash(string $raw): ?string
    {
        if (preg_match('/^[a-f0-9]{40}$/i', $raw)) return strtolower($raw);
        if (preg_match('/^[a-z2-7]{32}$/i', $raw)) return self::base32ToHex(strtoupper($raw));
        return null;
    }

    private static function base32ToHex(string $b32): ?string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
            $v = strpos($alphabet, $b32[$i]);
            if ($v === false) return null;
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $hex = '';
        for ($i = 0; $i + 4 <= strlen($bits); $i += 4) {
            $hex .= dechex(bindec(substr($bits, $i, 4)));
        }
        return strlen($hex) === 40 ? $hex : null;
    }
}
