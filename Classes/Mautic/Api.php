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

    public function addToDoNotContact(int $contactId, string $channel = 'email'): bool
    {
        $data = $this->request('POST', '/api/contacts/' . $contactId . '/dnc/' . $channel . '/add', [
            'json' => ['reason' => 1],
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
