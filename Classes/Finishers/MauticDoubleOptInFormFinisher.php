<?php

declare(strict_types=1);

namespace WapplerSystems\FormMautic\Finishers;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use WapplerSystems\FormMautic\DoubleOptIn\DoubleOptInResult;
use WapplerSystems\FormMautic\DoubleOptIn\DoubleOptInService;
use WapplerSystems\FormMautic\Mautic\ContactPayloadBuilder;

/**
 * Starts a Mautic double opt-in: Mautic sends its DOI email, the form fields are
 * applied to the contact only once the link in it has been confirmed.
 *
 * The visitor always proceeds to the next finisher, whatever the outcome, so the
 * form does not reveal whether an address is already known.
 */
class MauticDoubleOptInFormFinisher extends AbstractFinisher
{
    public function __construct(
        private readonly DoubleOptInService $doubleOptIn,
        private readonly ContactPayloadBuilder $payloadBuilder,
    ) {}

    protected function executeInternal(): ?string
    {
        $formRuntime = $this->finisherContext->getFormRuntime();
        $emailField = (string)($this->parseOption('emailField') ?: 'email');
        $email = trim((string)($formRuntime[$emailField] ?? ''));
        if ($email === '') {
            $this->logger?->warning('MauticDoubleOptIn: no email value in form field "{field}"', ['field' => $emailField]);
            return null;
        }

        $request = $this->finisherContext->getRequest();
        $site = $request->getAttribute('site');
        $language = $request->getAttribute('language');
        $languageCode = $this->resolveLanguageCode($formRuntime[(string)$this->parseOption('languageField')] ?? null, $language);

        $emailId = $this->resolveConfirmationEmailId($languageCode);
        if ($emailId === 0) {
            $this->logger?->error('MauticDoubleOptIn: no confirmation email configured for "{language}"', ['language' => $languageCode]);
            return null;
        }

        $result = $this->doubleOptIn->request(
            $email,
            $this->payloadBuilder->build($formRuntime, $this->parseOption('fieldMapping')),
            $emailId,
            (int)$this->parseOption('segmentId'),
            $site instanceof Site ? $site->getIdentifier() : '',
            $language instanceof SiteLanguage ? $language->getLanguageId() : 0,
            $formRuntime->getFormDefinition()->getIdentifier(),
        );

        $context = ['email' => $email, 'result' => $result->value, 'emailId' => $emailId];
        if ($result === DoubleOptInResult::Failed) {
            $this->logger?->error('MauticDoubleOptIn: request failed', $context);
        } else {
            $this->logger?->info('MauticDoubleOptIn: {result}', $context);
        }

        return null;
    }

    /**
     * The newsletter language field wins over the page language: someone signing up
     * on the German page for the English newsletter expects an English email.
     */
    private function resolveLanguageCode(mixed $fieldValue, mixed $language): string
    {
        if (is_string($fieldValue) && $fieldValue !== '') {
            return strtolower(substr($fieldValue, 0, 2));
        }
        if ($language instanceof SiteLanguage) {
            return $language->getLocale()->getLanguageCode();
        }
        return '';
    }

    private function resolveConfirmationEmailId(string $languageCode): int
    {
        $emails = $this->parseOption('confirmationEmails');
        if (!is_array($emails)) {
            return 0;
        }
        return (int)($emails[$languageCode] ?? $emails['default'] ?? 0);
    }
}
