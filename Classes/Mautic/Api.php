<?php

declare(strict_types=1);

namespace WapplerSystems\FormMautic\Mautic;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RequestFactory;
use WapplerSystems\OauthService\Domain\Model\Client;
use WapplerSystems\OauthService\Domain\Repository\ClientRepository;
use WapplerSystems\OauthService\Service\TokenAcquisitionService;

/**
 * Thin wrapper around Mautic REST API — calls the API directly via
 * RequestFactory + Bearer token, no external SDK required.
 */
class Api implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** Mautic\LeadBundle\Entity\DoNotContact reasons */
    public const DNC_REASON_UNSUBSCRIBED = 1;
    public const DNC_REASON_MANUAL = 3;

    private ?Client $client = null;

    private string $baseUrl = '';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ClientRepository $clientRepository,
        private readonly TokenAcquisitionService $tokenAcquisitionService,
    ) {}

    public function getBaseUrl(): string
    {
        $this->ensureClient();
        return $this->baseUrl;
    }

    private function ensureClient(): bool
    {
        if ($this->client !== null) {
            return true;
        }

        $client = $this->clientRepository->findActiveByProvider('mautic');
        if ($client === null) {
            $this->logger?->warning('Mautic Api: no active client for provider "mautic"');
            return false;
        }

        $baseUrl = (string)$client->getMetadataValue('baseUrl', '');
        if ($baseUrl === '') {
            $this->logger?->warning('Mautic Api: client has no baseUrl in metadata');
            return false;
        }

        $this->client = $client;
        $this->baseUrl = rtrim($baseUrl, '/');
        return true;
    }

    public function getToken(): ?string
    {
        if (!$this->ensureClient()) {
            return null;
        }

        try {
            return $this->tokenAcquisitionService->getClientCredentialsToken('mautic');
        } catch (\RuntimeException $e) {
            $this->logger?->warning('Mautic Api: client_credentials token acquisition failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param array<string, mixed> $options extra RequestFactory options (json body, etc.)
     * @return array<string, mixed>|null
     */
    public function request(string $method, string $path, array $options = []): ?array
    {
        if (!$this->ensureClient()) {
            return null;
        }

        $token = $this->getToken();
        if ($token === null) {
            $this->logger?->warning('Mautic Api: no token available for request');
            return null;
        }

        $url = $this->baseUrl . $path;

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ]);

        try {
            $response = $this->requestFactory->request($url, $method, $options);
            $body = (string)$response->getBody();
            $data = json_decode($body, true);
            if (!is_array($data)) {
                $this->logger?->warning('Mautic Api: unexpected response body', [
                    'method' => $method,
                    'path' => $path,
                    'body' => $body,
                ]);
                return null;
            }
            return $data;
        } catch (\Throwable $e) {
            $this->logger?->warning('Mautic Api: request failed', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Upsert a contact by email.
     *
     * @param array<string, mixed> $fields flat key-value pairs sent to POST /api/contacts/new
     * @return array<string, mixed>|null the contact array from the response, or null on failure
     */
    public function upsertContact(array $fields): ?array
    {
        $data = $this->request('POST', '/api/contacts/new', [
            'form_params' => $fields,
        ]);

        if (!is_array($data) || !isset($data['contact']) || !is_array($data['contact'])) {
            $this->logger?->warning('Mautic Api: upsertContact — unexpected response', [
                'response' => $data ?? '(null)',
            ]);
            return null;
        }

        return $data['contact'];
    }

    /**
     * Find a contact by email.
     *
     * @return array<string, mixed>|null the single contact array, or null if not found / error
     */
    public function findContactByEmail(string $email): ?array
    {
        $data = $this->request('GET', '/api/contacts?search=email:' . urlencode($email) . '&limit=1');

        if (!is_array($data)) {
            return null;
        }

        $total = (int)($data['total'] ?? 0);
        if ($total === 0 || empty($data['contacts']) || !is_array($data['contacts'])) {
            return null;
        }

        return reset($data['contacts']) ?: null;
    }

    /**
     * Like findContactByEmail(), but tells a failed request apart from "no such contact".
     *
     * @return array<string, mixed>|null|false the contact, null if there is none, false on error
     */
    public function lookupContactByEmail(string $email): array|null|false
    {
        $data = $this->request('GET', '/api/contacts?search=' . urlencode('email:' . $email) . '&limit=5');
        if (!is_array($data) || !array_key_exists('total', $data)) {
            return false;
        }

        foreach ($data['contacts'] ?? [] as $contact) {
            if (is_array($contact) && strcasecmp((string)$this->getFieldValue($contact, 'email'), $email) === 0) {
                return $contact;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null the contact array, or null if not found / error
     */
    public function getContact(int $contactId): ?array
    {
        $data = $this->request('GET', '/api/contacts/' . $contactId);
        return is_array($data['contact'] ?? null) ? $data['contact'] : null;
    }

    /**
     * Patch a contact. Tags given as "-name" are removed, plain names are added.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|null the updated contact, or null on failure
     */
    public function editContact(int $contactId, array $fields): ?array
    {
        $data = $this->request('PATCH', '/api/contacts/' . $contactId . '/edit', [
            'json' => $fields,
        ]);
        return is_array($data['contact'] ?? null) ? $data['contact'] : null;
    }

    /**
     * Send a template email to one contact. Mautic refuses contacts on the DNC list.
     */
    public function sendEmailToContact(int $emailId, int $contactId): bool
    {
        $data = $this->request('POST', '/api/emails/' . $emailId . '/contact/' . $contactId . '/send');
        return !empty($data['success']);
    }

    /**
     * @param array<string, mixed> $contact a full contact payload
     */
    public function hasTag(array $contact, string $tag): bool
    {
        foreach ($contact['tags'] ?? [] as $entry) {
            if (is_array($entry) && strcasecmp((string)($entry['tag'] ?? ''), $tag) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $contact a full contact payload
     */
    public function getFieldValue(array $contact, string $alias): mixed
    {
        return $contact['fields']['all'][$alias] ?? null;
    }

    public function addToSegment(int $contactId, int $segmentId): bool
    {
        $data = $this->request('POST', '/api/segments/' . $segmentId . '/contact/' . $contactId . '/add');
        return is_array($data);
    }

    public function removeFromSegment(int $contactId, int $segmentId): bool
    {
        $data = $this->request('POST', '/api/segments/' . $segmentId . '/contact/' . $contactId . '/remove');
        return is_array($data);
    }

    public function addToDoNotContact(int $contactId, string $channel = 'email', int $reason = self::DNC_REASON_UNSUBSCRIBED, string $comments = ''): bool
    {
        $body = ['reason' => $reason];
        if ($comments !== '') {
            $body['comments'] = $comments;
        }
        $data = $this->request('POST', '/api/contacts/' . $contactId . '/dnc/' . $channel . '/add', [
            'json' => $body,
        ]);
        return is_array($data);
    }

    public function removeFromDoNotContact(int $contactId, string $channel = 'email'): bool
    {
        $data = $this->request('POST', '/api/contacts/' . $contactId . '/dnc/' . $channel . '/remove');
        return is_array($data);
    }

    /**
     * Inspects the contact payload's doNotContact array to determine whether
     * the given channel is on the DNC list.
     *
     * @param array<string, mixed> $contact a full contact payload from findContactByEmail()
     */
    public function isOnDoNotContactList(array $contact, string $channel = 'email'): bool
    {
        $dnc = $contact['doNotContact'] ?? [];
        if (!is_array($dnc)) {
            return false;
        }

        foreach ($dnc as $entry) {
            if (is_array($entry) && ($entry['channel'] ?? '') === $channel) {
                return true;
            }
        }

        return false;
    }
}
