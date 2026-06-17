<?php

namespace TryHackX\HomepageBlocks\Api;

use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Nadpisuje reguły walidacji długości pól `title` / `content` na zasobach API
 * dyskusji i postów, zgodnie z ustawieniami admina.
 *
 * Używa wyłącznie PUBLICZNEGO API schematu Flarum 2.x
 * (`Flarum\Api\Schema\Concerns\HasValidationRules`): `getRules()` do odczytu,
 * `rules([], …, override: true)` do wyczyszczenia oraz `rule()` / `minLength()` /
 * `maxLength()` do odtworzenia — bez refleksji na wewnętrznym stanie rdzenia
 * (audyt H1). Pozwala ROZLUŹNIĆ rdzeniowe limity (np. krótszy minimalny tytuł),
 * czego samo `minLength()` nie umie, bo tylko DOKŁADA regułę. Logika żyje w tej
 * klasie (z wstrzykniętym repozytorium ustawień), więc jest testowalna.
 *
 * Działa na ścieżce serializacji (per pole każdej dyskusji/posta), dlatego
 * `catch (\Throwable)` jest hot-path-owym bezpiecznikiem: w razie zmiany API
 * rdzenia zachowuje pole z rdzeniowymi limitami zamiast wywracać odpowiedź.
 */
class FieldLengthModifier
{
    /** Strażnik: ostrzeżenie o nieudanym nadpisaniu reguł logujemy raz na proces (patrz catch w replaceMinMax). */
    private static bool $overrideFailureLogged = false;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected LoggerInterface $logger
    ) {}

    /**
     * @param  mixed $field  Flarum\Api\Schema\Str (pole `title`)
     * @return mixed
     */
    public function applyTitle($field)
    {
        if (! $this->enabled('title_length_enabled')) {
            return $field;
        }

        // Kolumna varchar(200): min >= 1, max <= 200.
        $min = max(1, (int) ($this->settings->get('tryhackx-homepage-blocks.title_min_length') ?: 3));
        $max = min(200, max($min, (int) ($this->settings->get('tryhackx-homepage-blocks.title_max_length') ?: 200)));

        return $this->replaceMinMax($field, $min, $max);
    }

    /**
     * @param  mixed $field  Flarum\Api\Schema\Str (pole `content`)
     * @return mixed
     */
    public function applyContent($field)
    {
        if (! $this->enabled('content_length_enabled')) {
            return $field;
        }

        // Kolumna mediumtext: min >= 0, max <= 16 000 000.
        $min = max(0, (int) ($this->settings->get('tryhackx-homepage-blocks.content_min_length') ?: 1));
        $max = min(16000000, max($min, (int) ($this->settings->get('tryhackx-homepage-blocks.content_max_length') ?: 500000)));

        return $this->replaceMinMax($field, $min, $max);
    }

    protected function enabled(string $key): bool
    {
        return (bool) $this->settings->get('tryhackx-homepage-blocks.' . $key);
    }

    /**
     * @param  mixed $field
     * @return mixed
     */
    protected function replaceMinMax($field, ?int $min, ?int $max)
    {
        try {
            // Flarum 2.x udostępnia PUBLICZNE API reguł walidacji schematu, więc nie
            // sięgamy już refleksją po prywatne `$rules` (audyt H1 — zerwane sprzężenie
            // z wewnętrznym stanem rdzenia, wersjo-odporne):
            //   - getRules()             — odczyt aktualnych reguł,
            //   - rules([], true, true)  — wyczyszczenie wszystkich (override),
            //   - rule($r, $cond)        — ponowne dodanie (z zachowaniem warunku),
            //   - minLength()/maxLength()— nasze limity.
            // Usuwamy istniejące min:/max:, odtwarzamy resztę 1:1, dokładamy nasze.
            $kept = array_filter($field->getRules(), static function ($entry) {
                $r = $entry['rule'] ?? '';
                return ! (is_string($r) && (str_starts_with($r, 'min:') || str_starts_with($r, 'max:')));
            });

            $field->rules([], true, true); // override: wyczyść wszystkie reguły
            foreach ($kept as $entry) {
                $field->rule($entry['rule'], $entry['condition'] ?? true);
            }

            if ($min !== null) {
                $field->minLength($min);
            }
            if ($max !== null) {
                $field->maxLength($max);
            }
        } catch (\Throwable $e) {
            // Hot-path: ta metoda biegnie per pole KAŻDEJ dyskusji/posta przy
            // serializacji — nigdy nie wolno jej wywrócić odpowiedzi API. Gdyby rdzeń
            // zmienił to publiczne API, zostawiamy pole z rdzeniowymi limitami i logujemy
            // RAZ na proces. BEZ zapisu do ustawień (audyt H3: koniec z zapisem na
            // ścieżce GET — dawna „flaga ostrzeżenia w adminie" usunięta wraz z refleksją).
            if (! self::$overrideFailureLogged) {
                self::$overrideFailureLogged = true;
                try {
                    $this->logger->warning(
                        '[tryhackx-homepage-blocks] Nie udało się nadpisać reguł długości pola '
                        . '(zmiana publicznego API schematu Flarum?). Limity długości tytułu/treści '
                        . 'mogą nie być egzekwowane.',
                        ['exception' => $e]
                    );
                } catch (\Throwable $ignored) {
                    // logowanie jest best-effort — nie wywracajmy serializacji pola
                }
            }
        }

        return $field;
    }
}
