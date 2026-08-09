<?php

declare(strict_types=1);

namespace WapplerSystems\FormMautic\Validators;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Extbase\Validation\Validator\AbstractValidator;
use WapplerSystems\FormMautic\Mautic\Api;

/**
 * Verifies the Mautic API connection is configured and reachable.
 *
 * FAIL CLOSED: if Mautic is not reachable, the validator adds an error —
 * this exists specifically to catch misconfiguration/outage early, so the
 * visitor sees a "temporarily unavailable" message instead of a silent
 * success that never reaches Mautic.
 *
 * Ignores $value entirely — this is a service-availability check, not a
 * field-content check. Attach it to any field (typically the Email field)
 * solely to get an isValid() hook invoked during validation.
 */
class MauticConnectionValidator extends AbstractValidator implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $acceptsEmptyValues = true;

    public function __construct(
        private readonly Api $api,
    ) {}

    protected function isValid(mixed $value): void
    {
        if ($this->api->getBaseUrl() === '') {
            $this->logger?->warning('MauticConnectionValidator: no baseUrl configured');
            $this->addError(
                'This form is temporarily unavailable. Please try again later.',
                1735689602,
            );
            return;
        }

        if ($this->api->getToken() === null) {
            $this->logger?->warning('MauticConnectionValidator: token acquisition failed');
            $this->addError(
                'This form is temporarily unavailable. Please try again later.',
                1735689602,
            );
        }
    }
}
