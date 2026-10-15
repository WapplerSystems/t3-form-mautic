<?php

declare(strict_types=1);

namespace WapplerSystems\FormMautic\Validators;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\RateLimiter\RateLimiterFactoryInterface;
use TYPO3\CMS\Extbase\Validation\Validator\AbstractValidator;

/**
 * Limits how often one IP address may submit a form, e.g. 5 per hour.
 *
 * Attach it to a single, always-present field (the email field): it is evaluated
 * once per submission, and every submission counts, valid or not. Several
 * instances with different "id" options stack, e.g. a per-hour and a per-day limit.
 * The counter lives in TYPO3's rate limiter storage, keyed by the remote address.
 */
class SubmissionRateLimitValidator extends AbstractValidator implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $supportedOptions = [
        'id' => ['form-submission', 'Name of the limiter, separate counter per id', 'string'],
        'limit' => [5, 'Submissions allowed per interval', 'integer'],
        'interval' => ['1 hour', 'Sliding window, e.g. "1 hour" or "1 day"', 'string'],
    ];

    protected $acceptsEmptyValues = false;

    public function __construct(
        private readonly RateLimiterFactoryInterface $rateLimiterFactory,
    ) {}

    protected function isValid(mixed $value): void
    {
        $request = $this->getRequest() ?? $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return;
        }

        $limiter = $this->rateLimiterFactory->createRequestBasedLimiter($request, [
            'id' => 'form-mautic-' . $this->options['id'],
            'policy' => 'sliding_window',
            'limit' => (int)$this->options['limit'],
            'interval' => (string)$this->options['interval'],
        ]);

        if ($limiter->consume()->isAccepted()) {
            return;
        }

        $this->logger?->notice('Form submission rate limit "{id}" hit by {ip}', [
            'id' => $this->options['id'],
            'ip' => $request->getAttribute('normalizedParams')?->getRemoteAddress() ?? '',
        ]);
        $this->addError(
            // Translated by EXT:form via "validation.error.1792060001" in form.xlf
            'Too many sign-ups from your connection. Please try again later.',
            1792060001,
        );
    }
}
