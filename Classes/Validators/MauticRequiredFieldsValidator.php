<?php

declare(strict_types=1);

namespace WapplerSystems\FormMautic\Validators;

use TYPO3\CMS\Extbase\Validation\Validator\AbstractValidator;

/**
 * Purpose-specific required-field check with a Mautic-labelled error message.
 * Editors attach this to whichever field(s) they've mapped into a Mautic
 * finisher's fieldMapping, giving them a clearly-named validator with its own
 * translatable message — it does NOT auto-discover which fields need it.
 */
class MauticRequiredFieldsValidator extends AbstractValidator
{
    protected $acceptsEmptyValues = false;

    protected function isValid(mixed $value): void
    {
        if (trim((string)$value) === '') {
            $this->addError(
                'This field is required for the newsletter sign-up.',
                1735689603,
            );
        }
    }
}
