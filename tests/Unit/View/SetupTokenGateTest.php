<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\View;

use Nowo\SiteBackupBundle\View\SetupTokenGate;
use PHPUnit\Framework\TestCase;

final class SetupTokenGateTest extends TestCase
{
    public function testDefaults(): void
    {
        foreach ([new SetupTokenGate(), SetupTokenGate::fromConfig(null), SetupTokenGate::fromConfig([])] as $gate) {
            self::assertNull($gate->illustration);
            self::assertSame(SetupTokenGate::DEFAULT_HOWTO_STEPS, $gate->howtoSteps);
            self::assertSame('NowoSiteBackupBundle', $gate->translationDomain);
        }
    }

    public function testStringShorthandEmitsNoWebpByDefault(): void
    {
        $gate = SetupTokenGate::fromConfig(['illustration' => ' illustrations/error-403.JPG ']);

        self::assertSame(['src' => 'illustrations/error-403.JPG', 'webp' => null, 'width' => null, 'height' => null, 'alt' => SetupTokenGate::DEFAULT_ILLUSTRATION_ALT], $gate->illustration, 'PNG/JPEG-only hosts get no broken <source> (1.22.1)');
        self::assertSame('illustrations/error-403.webp', SetupTokenGate::fromConfig(['illustration' => ['src' => 'illustrations/error-403.JPG', 'webp' => true]])->illustration['webp'] ?? null, 'webp: true derives the sibling');
    }

    public function testFullFormAndSanitizing(): void
    {
        $gate = SetupTokenGate::fromConfig([
            'illustration'       => ['src' => 'a.png', 'webp' => 'b.webp', 'width' => 10, 'height' => 0, 'alt' => 'alt.key'],
            'howto_steps'        => ['one', '', '  ', 3, 'two'],
            'translation_domain' => 'messages',
        ]);

        self::assertSame(['src' => 'a.png', 'webp' => 'b.webp', 'width' => 10, 'height' => null, 'alt' => 'alt.key'], $gate->illustration);
        self::assertSame(['one', 'two'], $gate->howtoSteps);
        self::assertSame('messages', $gate->translationDomain);

        self::assertNull(SetupTokenGate::fromConfig(['illustration' => ['src' => 'a.png', 'webp' => false]])->illustration['webp'] ?? null);
        self::assertNull(SetupTokenGate::fromConfig(['illustration' => ['src' => 'a.png', 'webp' => '']])->illustration['webp'] ?? null);
        self::assertSame('a.webp', SetupTokenGate::fromConfig(['illustration' => ['src' => 'a.png', 'webp' => true]])->illustration['webp'] ?? null);
        self::assertNull(SetupTokenGate::fromConfig(['illustration' => ['src' => 'a.svg']])->illustration['webp'] ?? null);
        self::assertSame([], SetupTokenGate::fromConfig(['howto_steps' => []])->howtoSteps);
        self::assertSame('NowoSiteBackupBundle', SetupTokenGate::fromConfig(['translation_domain' => ' '])->translationDomain);
    }

    public function testInvalidIllustrationIsIgnored(): void
    {
        foreach ([null, '', ' ', [], ['src' => ''], ['src' => 3], 42] as $illustration) {
            self::assertNull(SetupTokenGate::fromConfig(['illustration' => $illustration])->illustration);
        }
    }
}
