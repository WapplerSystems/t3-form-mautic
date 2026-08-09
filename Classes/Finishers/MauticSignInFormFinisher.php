<?php

declare(strict_types=1);

namespace WapplerSystems\FormMautic\Finishers;

use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use WapplerSystems\FormMautic\Mautic\Api;

/**
 * Upserts a contact into Mautic and optionally adds them to a segment.
 * Log-only on failure — no user-facing rejection (unlike Mailchimp's 400 handler).
 */
class MauticSignInFormFinisher extends AbstractFinisher
{
    public function __construct(
        private readonly Api $api,
    ) {}

    protected function executeInternal(): ?string
    {
        $this->logger?->info('MauticSignIn: finisher invoked');

        $formRuntime = $this->finisherContext->getFormRuntime();
        $emailField = (string)($this->parseOption('emailField') ?: 'email');
        $email = $formRuntime[$emailField] ?? null;

        if (empty($email)) {
            $this->logger?->warning('MauticSignIn: aborting — no email value in form field "{field}"', [
                'field' => $emailField,
            ]);
            return null;
        }

        if ($this->api->getBaseUrl() === '' || $this->api->getToken() === null) {
            $this->logger?->warning('MauticSignIn: aborting — Mautic API not available');
            return null;
        }

        $payload = ['email' => $email];

        $fieldMapping = $this->parseOption('fieldMapping');
        if (is_array($fieldMapping)) {
            foreach ($fieldMapping as $formField => $mauticField) {
                $value = $formRuntime[(string)$formField] ?? null;
                if ($value !== null && $value !== '') {
                    $payload[(string)$mauticField] = $value;
                }
            }
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

        $this->logger?->info('MauticSignIn: upserting contact', [
            'email' => $email,
            'fields' => array_keys($payload),
        ]);

        $contact = $this->api->upsertContact($payload);
        if ($contact === null) {
            $this->logger?->error('MauticSignIn: upsertContact failed');
            return null;
        }

        $this->logger?->info('MauticSignIn: contact upserted', [
            'contactId' => $contact['id'] ?? 'unknown',
        ]);

        $segmentId = $this->parseOption('segmentId');
        if ($segmentId !== null && $segmentId !== '' && isset($contact['id'])) {
            $segmentIdInt = (int)$segmentId;
            if ($segmentIdInt > 0) {
                $added = $this->api->addToSegment((int)$contact['id'], $segmentIdInt);
                $this->logger?->info('MauticSignIn: addToSegment {result}', [
                    'result' => $added ? 'ok' : 'failed',
                    'segmentId' => $segmentIdInt,
                    'contactId' => $contact['id'],
                ]);
            }
        }

        return null;
    }
}
