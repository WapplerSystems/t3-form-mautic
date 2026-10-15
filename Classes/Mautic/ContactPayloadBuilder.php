<?php

declare(strict_types=1);

namespace WapplerSystems\FormMautic\Mautic;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Country\CountryProvider;
use TYPO3\CMS\Form\Domain\Runtime\FormRuntime;

/**
 * Turns submitted form values into the flat field map Mautic's contact API expects.
 * Shared by the direct sign-in and the double opt-in finisher.
 */
class ContactPayloadBuilder implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly CountryProvider $countryProvider,
    ) {}

    /**
     * @param mixed $fieldMapping JSON string (form editor) or map (YAML definition)
     * @return array<string, mixed> Mautic field alias => value, without the email
     */
    public function build(FormRuntime $formRuntime, mixed $fieldMapping): array
    {
        $payload = [];

        foreach ($this->normalizeFieldMapping($fieldMapping) as $formField => $mauticField) {
            $value = $formRuntime[$formField] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $payload[$mauticField] = $this->normalizeValue($mauticField, $value);
        }

        if (!isset($payload['firstname']) && !isset($payload['lastname'])) {
            $firstName = $formRuntime['firstName'] ?? '';
            $lastName = $formRuntime['lastName'] ?? '';
            if ($firstName !== '') {
                $payload['firstname'] = $firstName;
            }
            if ($lastName !== '') {
                $payload['lastname'] = $lastName;
            }
        }

        return $payload;
    }

    /**
     * The form editor stores the mapping as a JSON string, a YAML form definition
     * can provide it as a plain map. Accept both.
     *
     * @return array<string, string>
     */
    public function normalizeFieldMapping(mixed $fieldMapping): array
    {
        if (is_string($fieldMapping)) {
            $fieldMapping = trim($fieldMapping);
            if ($fieldMapping === '') {
                return [];
            }
            $decoded = json_decode($fieldMapping, true);
            if (!is_array($decoded)) {
                $this->logger?->warning('Mautic: fieldMapping is not valid JSON, ignoring', [
                    'fieldMapping' => $fieldMapping,
                ]);
                return [];
            }
            $fieldMapping = $decoded;
        }

        if (!is_array($fieldMapping)) {
            return [];
        }

        $result = [];
        foreach ($fieldMapping as $formField => $mauticField) {
            $result[(string)$formField] = (string)$mauticField;
        }
        return $result;
    }

    /**
     * Mautic's core "country" field is a select list of English country names,
     * while EXT:form's CountrySelect submits an alpha-2 ISO code. Translate it,
     * otherwise Mautic silently drops the value.
     */
    private function normalizeValue(string $mauticField, mixed $value): mixed
    {
        if ($mauticField !== 'country' || !is_string($value) || strlen($value) !== 2) {
            return $value;
        }

        $country = $this->countryProvider->getByAlpha2IsoCode(strtoupper($value));

        return $country?->getName() ?? $value;
    }
}
