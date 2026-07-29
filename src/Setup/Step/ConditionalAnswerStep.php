<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Step;

use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepInterface;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;

use function is_string;

/**
 * Enables the inner step only when a setup answer matches the expected value.
 */
final class ConditionalAnswerStep implements SetupStepInterface
{
    public function __construct(
        private readonly SetupStepInterface $inner,
        private readonly string $answerKey,
        private readonly string $expectedValue,
    ) {
    }

    public function getId(): string
    {
        return $this->inner->getId();
    }

    public function getLabel(): string
    {
        return $this->inner->getLabel();
    }

    public function getUiKind(): string
    {
        return $this->inner->getUiKind();
    }

    public function isEnabled(SetupContext $ctx): bool
    {
        $answer = $ctx->getAnswer($this->answerKey);

        return is_string($answer)
            && $answer === $this->expectedValue
            && $this->inner->isEnabled($ctx);
    }

    public function isComplete(SetupContext $ctx): bool
    {
        return $this->inner->isComplete($ctx);
    }

    public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
    {
        return $this->inner->run($ctx, $input);
    }
}
