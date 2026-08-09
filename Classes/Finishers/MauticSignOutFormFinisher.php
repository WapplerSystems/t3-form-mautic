<?php

declare(strict_types=1);

namespace WapplerSystems\FormMautic\Finishers;

use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use WapplerSystems\FormMautic\Mautic\Api;

/**
 * Unsubscribes a contact: adds to DNC list and optionally removes from a segment.
 * If the contact doesn't exist in Mautic, logs "already absent" and returns.
 */
class MauticSignOutFormFinisher extends AbstractFinisher
{
    public function __construct(
        private readonly Api $api,
    ) {}

    protected function executeInternal(): void
    {
        $this->logger?->info('MauticSignOut: finisher invoked');

        $formRuntime = $this->finisherContext->getFormRuntime();
        $emailField = (string)($this->parseOption('emailField') ?: 'email');
        $email = $formRuntime[$emailField] ?? null;

        if (empty($email)) {
            $this->logger?->warning('MauticSignOut: aborting — no email value in form field "{field}"', [
                'field' => $emailField,
            ]);
            return;
        }

        if ($this->api->getBaseUrl() === '' || $this->api->getToken() === null) {
            $this->logger?->warning('MauticSignOut: aborting — Mautic API not available');
            return;
        }

        $this->logger?->info('MauticSignOut: looking up contact', ['email' => $email]);

        $contact = $this->api->findContactByEmail($email);
        if ($contact === null) {
            $this->logger?->info('MauticSignOut: contact not found — already absent', ['email' => $email]);
            return;
        }

        $contactId = (int)($contact['id'] ?? 0);
        if ($contactId <= 0) {
            $this->logger?->warning('MauticSignOut: contact has no id');
            return;
        }

        $added = $this->api->addToDoNotContact($contactId);
        $this->logger?->info('MauticSignOut: addToDoNotContact {result}', [
            'result' => $added ? 'ok' : 'failed',
            'contactId' => $contactId,
        ]);

        $segmentId = $this->parseOption('segmentId');
        if ($segmentId !== null && $segmentId !== '') {
            $segmentIdInt = (int)$segmentId;
            if ($segmentIdInt > 0) {
                $removed = $this->api->removeFromSegment($contactId, $segmentIdInt);
                $this->logger?->info('MauticSignOut: removeFromSegment {result}', [
                    'result' => $removed ? 'ok' : 'failed',
                    'segmentId' => $segmentIdInt,
                    'contactId' => $contactId,
                ]);
            }
        }
    }
}
