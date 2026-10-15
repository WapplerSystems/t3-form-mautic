<?php

declare(strict_types=1);

namespace WapplerSystems\FormMautic\DoubleOptIn;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use WapplerSystems\FormMautic\Mautic\Api;

/**
 * Double opt-in against Mautic.
 *
 * On sign-up only the bare email goes to Mautic, tagged "doi-pending" and carrying
 * a fresh confirm_token that Mautic's DOI email links to. The other form fields wait
 * in tx_formmautic_doi. Name, country and language therefore reach Mautic only after
 * the click, so a bot cannot overwrite the data of an existing contact.
 *
 * A new pending contact is also put on the email Do-Not-Contact list (reason "manual"),
 * which keeps it out of every segment broadcast, including segments whose filters
 * match contacts without a country. The DOI mail still arrives because Mautic's
 * single-contact send API ignores DNC. Confirmation lifts the DNC entry.
 *
 * Mautic deletes contacts that still carry "doi-pending" after EXPIRY_DAYS
 * (segment "doi-abgelaufen" + campaign with a "delete contact" action).
 */
class DoubleOptInService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const TABLE = 'tx_formmautic_doi';
    public const PENDING_TAG = 'doi-pending';
    public const CONFIRMED_TAG = 'doi-confirmed';
    public const TOKEN_FIELD = 'confirm_token';
    public const EXPIRY_DAYS = 10;

    /** At most one confirmation email per address within this window, against subscription bombing. */
    private const RESEND_LOCK_SECONDS = 86400;

    public function __construct(
        private readonly Api $mautic,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, mixed> $fields Mautic fields to apply after confirmation (without email)
     */
    public function request(
        string $email,
        array $fields,
        int $confirmationEmailId,
        int $segmentId,
        string $siteIdentifier,
        int $languageId,
        string $formIdentifier,
    ): DoubleOptInResult {
        $email = mb_strtolower(trim($email));
        $now = time();
        $this->purgeExpired($now);

        if ($this->hasRecentRequest($email, $now)) {
            return DoubleOptInResult::Throttled;
        }

        $contact = $this->mautic->lookupContactByEmail($email);
        if ($contact === false) {
            return DoubleOptInResult::Failed;
        }

        $token = bin2hex(random_bytes(24));

        if ($contact === null) {
            $contact = $this->mautic->upsertContact([
                'email' => $email,
                self::TOKEN_FIELD => $token,
                'tags' => self::PENDING_TAG,
            ]);
            if ($contact !== null && $this->wasCreatedBefore($contact, $now - 300)) {
                // Lookup and create raced, the address existed after all. It must not
                // carry the pending tag, or the cleanup would delete a real contact.
                $this->mautic->editContact((int)$contact['id'], ['tags' => ['-' . self::PENDING_TAG], self::TOKEN_FIELD => '', 'overwriteWithBlank' => true]);
                return DoubleOptInResult::AlreadySubscribed;
            }
            if ($contact !== null && !$this->mautic->addToDoNotContact((int)$contact['id'], 'email', Api::DNC_REASON_MANUAL, 'DOI pending')) {
                $this->logger?->error('DOI request: could not put pending contact {id} on DNC', ['id' => $contact['id']]);
                return DoubleOptInResult::Failed;
            }
        } elseif ($this->mautic->hasTag($contact, self::PENDING_TAG)) {
            // Our own unconfirmed contact (on DNC by design): new token, new mail.
            $contact = $this->mautic->editContact((int)$contact['id'], [self::TOKEN_FIELD => $token]);
        } elseif ($this->mautic->isOnDoNotContactList($contact)) {
            // Unsubscribed or bounced earlier: no mail before someone lifts that by hand.
            return DoubleOptInResult::DoNotContact;
        } else {
            // Already a reachable contact: nothing to confirm, and no mail to someone
            // whose address a bot happens to submit.
            return DoubleOptInResult::AlreadySubscribed;
        }

        if ($contact === null || !isset($contact['id'])) {
            return DoubleOptInResult::Failed;
        }
        $contactId = (int)$contact['id'];

        // Record before sending: a crash after the mail must still block a resend.
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'crdate' => $now,
            'email' => $email,
            'token_hash' => $this->hashToken($token),
            'contact_id' => $contactId,
            'payload' => json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'segment_id' => $segmentId,
            'site_identifier' => $siteIdentifier,
            'language_id' => $languageId,
            'form_identifier' => $formIdentifier,
        ]);

        if (!$this->mautic->sendEmailToContact($confirmationEmailId, $contactId)) {
            return DoubleOptInResult::Failed;
        }

        return DoubleOptInResult::Sent;
    }

    /**
     * @return array<string, mixed>|null the pending row, or null for an unknown, used or expired token
     */
    public function findPending(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('token_hash', $queryBuilder->createNamedParameter($this->hashToken($token))),
                $queryBuilder->expr()->eq('confirmed_at', 0),
                $queryBuilder->expr()->gt('crdate', $queryBuilder->createNamedParameter(time() - self::EXPIRY_DAYS * 86400, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $row from findPending()
     */
    public function confirm(array $row, string $token): bool
    {
        $contactId = (int)$row['contact_id'];
        $contact = $this->mautic->getContact($contactId);
        if ($contact === null) {
            $this->logger?->warning('DOI confirm: Mautic contact {id} is gone', ['id' => $contactId]);
            return false;
        }

        // The newest request wins: an older link of the same address is void.
        if (!hash_equals((string)$this->mautic->getFieldValue($contact, self::TOKEN_FIELD), $token)) {
            $this->logger?->info('DOI confirm: token superseded for contact {id}', ['id' => $contactId]);
            return false;
        }

        $fields = json_decode((string)$row['payload'], true) ?: [];
        $fields[self::TOKEN_FIELD] = '';
        $fields['tags'] = ['-' . self::PENDING_TAG, self::CONFIRMED_TAG];
        // Without it Mautic ignores the empty token instead of clearing it.
        $fields['overwriteWithBlank'] = true;
        // Set by request() for new contacts; without lifting it no newsletter would arrive.
        // Lifted before the token is cleared, so a failure leaves the link usable.
        if ($this->mautic->isOnDoNotContactList($contact) && !$this->mautic->removeFromDoNotContact($contactId)) {
            $this->logger?->error('DOI confirm: could not lift DNC of contact {id}', ['id' => $contactId]);
            return false;
        }

        if ($this->mautic->editContact($contactId, $fields) === null) {
            return false;
        }

        $segmentId = (int)$row['segment_id'];
        if ($segmentId > 0 && !$this->mautic->addToSegment($contactId, $segmentId)) {
            $this->logger?->warning('DOI confirm: adding contact {id} to segment {segment} failed', [
                'id' => $contactId,
                'segment' => $segmentId,
            ]);
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, ['confirmed_at' => time()], ['uid' => (int)$row['uid']]);
        // Other open requests of this address are settled by this confirmation.
        $connection->delete(self::TABLE, ['email' => $row['email'], 'confirmed_at' => 0]);

        return true;
    }

    /**
     * @param array<string, mixed> $contact
     */
    private function wasCreatedBefore(array $contact, int $timestamp): bool
    {
        $dateAdded = strtotime((string)($contact['dateAdded'] ?? ''));
        return $dateAdded !== false && $dateAdded < $timestamp;
    }

    private function hasRecentRequest(string $email, int $now): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (bool)$queryBuilder->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('email', $queryBuilder->createNamedParameter($email)),
                $queryBuilder->expr()->gt('crdate', $queryBuilder->createNamedParameter($now - self::RESEND_LOCK_SECONDS, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Unconfirmed rows hold personal data without consent: drop them once the link has expired.
     */
    private function purgeExpired(int $now): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('confirmed_at', 0),
                $queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($now - self::EXPIRY_DAYS * 86400, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
