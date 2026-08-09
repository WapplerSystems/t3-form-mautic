<?php

declare(strict_types=1);

namespace WapplerSystems\FormMautic\Validators;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Extbase\Validation\Validator\AbstractValidator;
use WapplerSystems\FormMautic\Mautic\Api;

/**
 * Checks whether the submitted email is on Mautic's Do-Not-Contact list.
 *
 * FAIL OPEN: if Mautic is unreachable, the validator logs a warning and
 * allows the submission through — a visitor should never be blocked because
 * Mautic itself is down.
 */
class MauticEmailValidator extends AbstractValidator implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $acceptsEmptyValues = true;

    public function __construct(
        private readonly Api $api,
    ) {}

    protected function isValid(mixed $value): void
    {
        $email = trim((string)$value);
        if ($email === '') {
            return;
        }

        if ($this->api->getBaseUrl() === '' || $this->api->getToken() === null) {
            $this->logger?->warning('MauticEmailValidator: Mautic API not available — failing open');
            return;
        }

        $contact = $this->api->findContactByEmail($email);
        if ($contact === null) {
            return;
        }

        if ($this->api->isOnDoNotContactList($contact)) {
            $this->addError(
                'This e-mail address has previously unsubscribed and cannot be used here.',
                1735689601,
            );
        }
    }
}
