<?php

namespace TryHackX\HomepageBlocks\Tests\integration\forum;

use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Renders the forum so this extension's resources/less/forum.less is actually
 * compiled (with core's LESS variables), and checks the theme / style wiring.
 */
class FrontendAssetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'tryhackx-homepage-blocks');
    }

    protected function compiledCss(string $frontend): string
    {
        $css = '';
        foreach (glob($this->tmpDir() . "/public/assets/$frontend*.css") ?: [] as $file) {
            $css .= file_get_contents($file);
        }

        return $css;
    }

    protected function forumAttributes(): array
    {
        $response = $this->send($this->request('GET', '/api'));
        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true)['data']['attributes'];
    }

    #[Test]
    public function the_palette_follows_flarums_colour_scheme(): void
    {
        $this->assertSame(200, $this->send($this->request('GET', '/'))->getStatusCode());

        $css = $this->compiledCss('forum');

        // Auto: Flarum's live theme variables, per data-theme.
        $this->assertMatchesRegularExpression('/\[data-theme\^=light\]\s*\{[^}]*--hbs-control:var\(--control-bg/', $css);
        $this->assertMatchesRegularExpression('/\[data-theme\^=dark\]\s*\{[^}]*--hbs-control:var\(--control-bg\)/', $css);
        // Forced modes: the forum's own palette, resolved from core's LESS variables.
        $this->assertMatchesRegularExpression('/html\.HomepageBlocks--light\s*\{[^}]*--hbs-body:#fff/', $css);
        $this->assertMatchesRegularExpression('/html\.HomepageBlocks--dark\s*\{[^}]*--hbs-body:hsl\(/', $css);
        $this->assertDoesNotMatchRegularExpression('/--hbs-button-color:if\(/', $css);
        // Style tokens + the clear button's theme-aware glow.
        $this->assertMatchesRegularExpression('/\.HomepageSection\s*\{[^}]*--hb-header-bg:var\(--hbs-control\)/', $css);
        $this->assertStringContainsString('rgba(var(--hb-clear-rgb), 0.35)', $css);
        // No leftovers from the old JS body class / LESS palette.
        $this->assertStringNotContainsString('body.HomepageBlocks--dark', $css);
        $this->assertStringNotContainsString('@hb-', $css);
    }

    #[Test]
    public function the_original_style_is_compiled(): void
    {
        $this->assertSame(200, $this->send($this->request('GET', '/'))->getStatusCode());

        $css = $this->compiledCss('forum');

        $this->assertStringContainsString('.HomepageSection--original', $css);
        $this->assertStringContainsString('color-mix(in srgb, var(--hbs-primary) 20%, var(--hbs-body))', $css);
        $this->assertMatchesRegularExpression('/\.HomepageSection--original \.TrackerStats-item\s*\{[^}]*border-radius:12px/', $css);
    }

    #[Test]
    public function the_style_setting_is_normalised(): void
    {
        $this->assertSame('fitted', $this->forumAttributes()['tryhackx-homepage-blocks.style']);
    }

    #[Test]
    public function the_original_style_reaches_the_forum(): void
    {
        $this->setting('tryhackx-homepage-blocks.style', 'original');

        $this->assertSame('original', $this->forumAttributes()['tryhackx-homepage-blocks.style']);
    }

    #[Test]
    public function an_unknown_style_falls_back_to_fitted(): void
    {
        $this->setting('tryhackx-homepage-blocks.style', '<script>');

        $this->assertSame('fitted', $this->forumAttributes()['tryhackx-homepage-blocks.style']);
    }
}
