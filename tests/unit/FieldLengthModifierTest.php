<?php

namespace TryHackX\HomepageBlocks\Tests\unit;

use Flarum\Api\Schema\Str;
use Flarum\Settings\SettingsRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TryHackX\HomepageBlocks\Api\FieldLengthModifier;

/**
 * Title / content length overrides on the API schema fields (rules read back through
 * the public HasValidationRules API, like core validates them). A Str field always
 * starts with core's own `string` rule — it must survive the override.
 */
class FieldLengthModifierTest extends TestCase
{
    private function modifier(array $values): FieldLengthModifier
    {
        $prefixed = [];
        foreach ($values as $key => $value) {
            $prefixed['tryhackx-homepage-blocks.' . $key] = $value;
        }

        $settings = new class($prefixed) implements SettingsRepositoryInterface {
            public function __construct(private array $values)
            {
            }

            public function all(): array
            {
                return $this->values;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->values[$key] = $value;
            }

            public function delete(string $keyLike): void
            {
                unset($this->values[$keyLike]);
            }
        };

        return new FieldLengthModifier($settings, new NullLogger());
    }

    /** @return string[] */
    private function rules(Str $field): array
    {
        return array_values(array_filter(array_column($field->getRules(), 'rule'), 'is_string'));
    }

    #[Test]
    public function core_limits_are_replaced_and_other_rules_kept(): void
    {
        $field = Str::make('title')->rule('required')->minLength(3)->maxLength(80);
        $this->modifier(['title_length_enabled' => '1', 'title_min_length' => '2', 'title_max_length' => '150'])->applyTitle($field);

        $this->assertSame(['string', 'required', 'min:2', 'max:150'], $this->rules($field));
    }

    #[Test]
    public function a_zero_content_minimum_is_honoured(): void
    {
        $field = Str::make('content')->minLength(1)->maxLength(65535);
        $this->modifier(['content_length_enabled' => '1', 'content_min_length' => '0', 'content_max_length' => '1000'])->applyContent($field);

        $this->assertSame(['string', 'min:0', 'max:1000'], $this->rules($field), 'used to turn into min:1');
    }

    #[Test]
    public function empty_or_meaningless_values_fall_back_to_the_defaults(): void
    {
        $content = Str::make('content')->minLength(1)->maxLength(65535);
        $this->modifier(['content_length_enabled' => '1', 'content_min_length' => '', 'content_max_length' => '0'])->applyContent($content);
        $this->assertSame(['string', 'min:1', 'max:500000'], $this->rules($content), 'a maximum of 0 means "default", not "max = min"');

        $title = Str::make('title')->minLength(3)->maxLength(80);
        $this->modifier(['title_length_enabled' => '1', 'title_min_length' => 'abc', 'title_max_length' => '999'])->applyTitle($title);
        $this->assertSame(['string', 'min:3', 'max:200'], $this->rules($title), 'varchar(200) caps the maximum');

        $title = Str::make('title')->minLength(3)->maxLength(80);
        $this->modifier(['title_length_enabled' => '1', 'title_min_length' => '0'])->applyTitle($title);
        $this->assertSame(['string', 'min:1', 'max:200'], $this->rules($title), 'a title needs at least one character');
    }

    #[Test]
    public function nothing_changes_while_the_override_is_off(): void
    {
        $field = Str::make('title')->minLength(3)->maxLength(80);
        $this->modifier(['title_length_enabled' => '0', 'title_max_length' => '200'])->applyTitle($field);

        $this->assertSame(['string', 'min:3', 'max:80'], $this->rules($field));
    }
}
