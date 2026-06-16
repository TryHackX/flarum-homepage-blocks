<?php

namespace TryHackX\HomepageBlocks\Api;

use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Nadpisuje reguły walidacji długości pól `title` / `content` na zasobach API
 * dyskusji i postów, zgodnie z ustawieniami admina.
 *
 * Rdzeń Flarum akumuluje reguły przez `rule()` i nie udostępnia publicznej metody
 * do USUNIĘCIA istniejącej reguły min/max. Żeby umożliwić ROZLUŹNIENIE limitów
 * (np. krótszy minimalny tytuł niż domyślny rdzeniowy), filtrujemy tablicę reguł
 * przez Reflection. Logika żyje w tej klasie (z wstrzykniętym repozytorium ustawień)
 * zamiast w globalnej funkcji w extend.php — dzięki temu jest testowalna i nie
 * zaśmieca globalnej przestrzeni nazw.
 *
 * UWAGA: to celowo świadome sprzężenie z wewnętrzną strukturą rdzenia
 * (`Flarum\Api\Schema\Concerns\HasValidationRules::$rules`, format
 * `['rule' => 'min:N', 'condition' => ...]`). Jeśli rdzeń kiedyś to zmieni,
 * `catch (\Throwable)` zachowa oryginalne pole zamiast wywracać walidację.
 */
class FieldLengthModifier
{
    /** Strażnik: ostrzeżenie o nieudanej refleksji logujemy raz na proces (patrz catch w replaceMinMax). */
    private static bool $reflectionFailureLogged = false;

    /** Ostatnio UTRWALONY stan refleksji (flaga ustawień). null = jeszcze nie zapisany w tym procesie. */
    private static ?bool $reflectionStateRecorded = null;

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
            $ref = new \ReflectionProperty($field, 'rules');
            $rules = $ref->getValue($field);

            // Odfiltruj istniejące reguły min:/max:.
            $rules = array_filter($rules, function ($rule) {
                $r = $rule['rule'] ?? '';
                return ! (is_string($r) && (str_starts_with($r, 'min:') || str_starts_with($r, 'max:')));
            });

            $ref->setValue($field, array_values($rules));

            if ($min !== null) {
                $field->minLength($min);
            }
            if ($max !== null) {
                $field->maxLength($max);
            }

            // Refleksja zadziałała — wyczyść ewentualną „stałą" flagę awarii (np. po
            // aktualizacji rdzenia, która znów udostępnia oczekiwaną strukturę reguł).
            $this->recordReflectionState(true);
        } catch (\Throwable $e) {
            // Reflection zawiodła (np. zmiana schematu rdzenia) — zachowaj pole bez zmian
            // i podnieś WYKRYWALNY sygnał: (1) flaga ustawień, którą czyta panel admina,
            // (2) log raz na proces. Metoda biegnie per pole każdej dyskusji/posta, więc
            // jedno i drugie utrwalamy najwyżej raz na proces — bez tego ciche wyłączenie
            // limitów długości było niezauważalne dla operatora (audyt #1/#5).
            $this->recordReflectionState(false);

            if (! self::$reflectionFailureLogged) {
                self::$reflectionFailureLogged = true;
                try {
                    $this->logger->warning(
                        '[tryhackx-homepage-blocks] Nie udało się nadpisać reguł długości pola '
                        . '(Reflection na HasValidationRules::$rules zawiodła — możliwa zmiana rdzenia '
                        . 'Flarum). Limity długości tytułu/treści mogą nie być egzekwowane.',
                        ['exception' => $e]
                    );
                } catch (\Throwable $ignored) {
                    // logowanie jest best-effort — nie wywracajmy serializacji pola
                }
            }
        }

        return $field;
    }

    /**
     * Utrwal stan ostatniej próby refleksji w fladze ustawień
     * `…field_length_reflection_failed`, którą panel admina pokazuje jako ostrzeżenie.
     *
     * Zapis NAJWYŻEJ raz na proces i TYLKO gdy stan faktycznie się zmienia
     * (read-modify), bo metoda-rodzic biegnie per pole każdej dyskusji/posta —
     * bezwarunkowy zapis zalałby tabelę ustawień. W normalnej pracy (refleksja działa,
     * flaga nieustawiona) to jeden odczyt z cache ustawień i zero zapisów.
     */
    protected function recordReflectionState(bool $ok): void
    {
        if (self::$reflectionStateRecorded === $ok) {
            return;
        }
        self::$reflectionStateRecorded = $ok;

        try {
            $key = 'tryhackx-homepage-blocks.field_length_reflection_failed';
            $current = (bool) $this->settings->get($key);
            // Zapisuj tylko realną zmianę: ok+ustawiona → wyczyść; !ok+nieustawiona → ustaw.
            if ($ok === $current) {
                $this->settings->set($key, $ok ? '0' : '1');
            }
        } catch (\Throwable $ignored) {
            // best-effort — sygnał dla admina nie może wywrócić serializacji pola
        }
    }
}
