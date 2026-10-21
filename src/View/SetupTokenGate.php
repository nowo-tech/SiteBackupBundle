<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\View;

use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function preg_replace;
use function trim;

/**
 * Presentation options of the setup-token gate (v1.22+, {@code setup.token_gate}): an optional illustration rendered
 * as {@code <picture>} (WebP source + PNG/JPEG fallback with intrinsic width/height) above the auth panel, and the
 * how-to steps of the {@code <details>} disclosure. Each step is a translation key (or an already translated text)
 * of {@see self::$translationDomain}, rendered with the {@code %path%} parameter ({@code <setup prefix>?token=…}).
 *
 * Immutable and request-agnostic (FrankenPHP worker safe): built once from the bundle configuration.
 */
final readonly class SetupTokenGate
{
    /** Default how-to steps (bundle translations). */
    public const DEFAULT_HOWTO_STEPS = ['setup.token.step_env', 'setup.token.step_url', 'setup.token.step_header'];

    /** Default alt text key of the illustration (bundle translations). */
    public const DEFAULT_ILLUSTRATION_ALT = 'setup.token.illustration_alt';

    /**
     * @param array{src: string, webp: string|null, width: int|null, height: int|null, alt: string}|null $illustration
     * @param list<string> $howtoSteps empty list = no how-to disclosure
     */
    public function __construct(
        public ?array $illustration = null,
        public array $howtoSteps = self::DEFAULT_HOWTO_STEPS,
        public string $translationDomain = SiteBackupView::BUNDLE,
    ) {
    }

    /**
     * Builds the gate from the processed {@code setup.token_gate} configuration (null / missing keys = defaults).
     *
     * @param array<string, mixed>|null $config
     */
    public static function fromConfig(?array $config): self
    {
        $config ??= [];
        $domain = is_string($config['translation_domain'] ?? null) && trim($config['translation_domain']) !== '' ? $config['translation_domain'] : SiteBackupView::BUNDLE;

        $steps = self::DEFAULT_HOWTO_STEPS;
        if (is_array($config['howto_steps'] ?? null)) {
            $steps = [];
            foreach ($config['howto_steps'] as $step) {
                if (is_string($step) && trim($step) !== '') {
                    $steps[] = $step;
                }
            }
        }

        return new self(self::illustration($config['illustration'] ?? null), $steps, $domain);
    }

    /**
     * @return array{src: string, webp: string|null, width: int|null, height: int|null, alt: string}|null
     */
    private static function illustration(mixed $illustration): ?array
    {
        if (is_string($illustration)) {
            $illustration = ['src' => $illustration];
        }
        if (!is_array($illustration) || !is_string($illustration['src'] ?? null) || trim($illustration['src']) === '') {
            return null;
        }

        $src  = trim($illustration['src']);
        $webp = $illustration['webp'] ?? null;
        if ($webp === null || $webp === true) {
            // Derived sibling: illustrations/error-403.png => illustrations/error-403.webp (src already WebP: none).
            $webp = preg_match('/\.(png|jpe?g)$/i', $src) === 1 ? (string) preg_replace('/\.(png|jpe?g)$/i', '.webp', $src) : null;
        } elseif (!is_string($webp) || trim($webp) === '') {
            $webp = null;
        }

        $alt = is_string($illustration['alt'] ?? null) && trim($illustration['alt']) !== '' ? $illustration['alt'] : self::DEFAULT_ILLUSTRATION_ALT;

        return [
            'src'    => $src,
            'webp'   => $webp,
            'width'  => is_int($illustration['width'] ?? null) && $illustration['width'] > 0 ? $illustration['width'] : null,
            'height' => is_int($illustration['height'] ?? null) && $illustration['height'] > 0 ? $illustration['height'] : null,
            'alt'    => $alt,
        ];
    }
}
