<?php

namespace TryHackX\HomepageBlocks\Concerns;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Współdzielone, odporne na spoofing wyznaczanie IP klienta.
 *
 * Używamy IP wyznaczonego przez rdzeń Flarum (atrybut `ipAddress`, ustawiany
 * przez middleware `ProcessIp` z poszanowaniem konfiguracji zaufanych proxy),
 * z fallbackiem na REMOTE_ADDR. Wcześniejsza wersja `PointsManager::getIp()`
 * ufała bezpośrednio nagłówkom X-Forwarded-For / X-Real-IP — a te są w pełni
 * spoofowalne przez klienta, co pozwalało gościowi rotować nagłówek i w
 * nieskończoność odświeżać własny kubełek punktów, całkowicie omijając
 * bramkę captcha / limit akcji.
 *
 * Lustrzane rozwiązanie istnieje w tryhackx/flarum-magnet-link
 * (Concerns\ResolvesClientIp) — celowo NIE współdzielimy go między paczkami,
 * aby homepage-blocks nie zależał od magnet-linka w kwestii bezpieczeństwa.
 */
trait ResolvesClientIp
{
    protected function getClientIp(ServerRequestInterface $request): string
    {
        $ip = $request->getAttribute('ipAddress');
        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }
}
