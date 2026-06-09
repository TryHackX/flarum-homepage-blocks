<?php

namespace TryHackX\HomepageBlocks\Api;

use Flarum\Settings\SettingsRepositoryInterface;

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
    public function __construct(
        protected SettingsRepositoryInterface $settings
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
        } catch (\Throwable $e) {
            // Reflection zawiodła (np. zmiana schematu rdzenia) — zachowaj pole bez zmian.
        }

        return $field;
    }
}
