<?php

namespace TryHackX\HomepageBlocks\Provider;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\Paths;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore as IlluminateFileStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use TryHackX\HomepageBlocks\Cache\CacheStore;
use TryHackX\HomepageBlocks\Cache\FileStore;
use TryHackX\HomepageBlocks\Cache\Store;

/**
 * Wiąże {@see Store} z właściwą implementacją, wybieraną AUTOMATYCZNIE z bieżącej
 * konfiguracji cache Flarum — bez nowego ustawienia admina:
 *
 *   - Gdy `cache.store` jest oparty na współdzielonym, lockowalnym driverze
 *     (Redis/Memcached itp. — np. po zainstalowaniu rozszerzenia Redis, które
 *     przepina binding) → {@see CacheStore} (spójność cross-node).
 *   - W każdym innym wypadku (w tym domyślny plikowy cache Flarum) → {@see FileStore}
 *     (natywny, bez zależności od cache — sprawdzona ścieżka na 1 serwerze).
 *
 * Dzięki temu instalacja jednoserwerowa (większość forów) zachowuje się jak dotąd,
 * a klaster z Redisem dostaje cross-node „za darmo", gdy tylko admin skonfiguruje
 * współdzielony cache. Ryzyko forward-compat zamknięte w {@see CacheStore}.
 */
class StoreProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Store::class, function ($container) {
            // Ścieżka domyślna: natywny magazyn plikowy.
            $fileStore = fn () => new FileStore($container->make(Paths::class));

            try {
                $repo = $container->make('cache.store');
                if (!$repo instanceof Repository) {
                    return $fileStore();
                }

                $underlying = $repo->getStore();

                // Cross-node tylko gdy driver jest lockowalny I współdzielony między
                // hostami. Pliki / array / null są LOKALNE dla node'a — wtedy natywny
                // FileStore jest równoważny, a do tego nie wnosi zależności od cache.
                $isShared = $underlying instanceof LockProvider
                    && !($underlying instanceof IlluminateFileStore)
                    && !($underlying instanceof ArrayStore)
                    && !($underlying instanceof NullStore);

                return $isShared ? new CacheStore($repo) : $fileStore();
            } catch (\Throwable $e) {
                // Cokolwiek pójdzie nie tak przy introspekcji cache — zostań przy plikach.
                return $fileStore();
            }
        });
    }
}
