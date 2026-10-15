<?php

declare(strict_types=1);

namespace WapplerSystems\FormMautic\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use WapplerSystems\FormMautic\DoubleOptIn\DoubleOptInService;

/**
 * Answers the link in Mautic's DOI email: /newsletter/confirm/{token}
 *
 * GET only shows a button, the POST it sends confirms. Mail security scanners
 * (Safe Links & co.) open every link in an incoming message; confirming on GET
 * would let them subscribe exactly the people a bot signed up against their will.
 */
final class DoubleOptInConfirmMiddleware implements MiddlewareInterface
{
    private const PATH_PATTERN = '#^(?:/[a-z]{2})?/newsletter/confirm/([^/]+)/?$#';
    private const LABELS = 'LLL:EXT:form_mautic/Resources/Private/Language/locallang_doi.xlf:';

    public function __construct(
        private readonly DoubleOptInService $doubleOptIn,
        private readonly ViewFactoryInterface $viewFactory,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site || !preg_match(self::PATH_PATTERN, $request->getUri()->getPath(), $matches)) {
            return $handler->handle($request);
        }

        $token = $matches[1];
        $row = $this->doubleOptIn->findPending($token);
        $language = $this->resolveLanguage($site, $request, $row);

        if ($row === null) {
            return $this->render($request, $site, $language, 'invalid', 404);
        }

        if ($request->getMethod() !== 'POST') {
            return $this->render($request, $site, $language, 'ask');
        }

        if (!$this->doubleOptIn->confirm($row, $token)) {
            return $this->render($request, $site, $language, 'error', 503);
        }

        $confirmedPageUid = (int)$site->getSettings()->get('formMautic.doubleOptIn.confirmedPageUid', 0);
        if ($confirmedPageUid > 0) {
            $uri = $site->getRouter()->generateUri($confirmedPageUid, ['_language' => $language]);
            return $this->responseFactory->createResponse(303)->withHeader('Location', (string)$uri);
        }

        return $this->render($request, $site, $language, 'confirmed');
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function resolveLanguage(Site $site, ServerRequestInterface $request, ?array $row): SiteLanguage
    {
        if ($row !== null) {
            try {
                return $site->getLanguageById((int)$row['language_id']);
            } catch (\InvalidArgumentException) {
            }
        }
        $language = $request->getAttribute('language');
        return $language instanceof SiteLanguage ? $language : $site->getDefaultLanguage();
    }

    private function render(ServerRequestInterface $request, Site $site, SiteLanguage $language, string $state, int $status = 200): ResponseInterface
    {
        $languageService = $this->languageServiceFactory->createFromSiteLanguage($language);
        $label = static fn(string $key): string => $languageService->sL(self::LABELS . $key);

        $templateRootPaths = ['EXT:form_mautic/Resources/Private/Templates/'];
        $override = (string)$site->getSettings()->get('formMautic.doubleOptIn.templateRootPath', '');
        if ($override !== '') {
            $templateRootPaths[] = $override;
        }

        $view = $this->viewFactory->create(new ViewFactoryData(templateRootPaths: $templateRootPaths, request: $request));
        $view->assignMultiple([
            'state' => $state,
            'language' => $language,
            'siteTitle' => $language->getWebsiteTitle() ?: ($site->getConfiguration()['websiteTitle'] ?? ''),
            'homeUrl' => (string)$language->getBase(),
            'formAction' => (string)$request->getUri()->withQuery('')->withFragment(''),
            'labels' => [
                'title' => $label($state . '.title'),
                'text' => $label($state . '.text'),
                'button' => $label('ask.button'),
                'home' => $label('home'),
            ],
        ]);

        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withBody($this->streamFactory->createStream($view->render('DoubleOptIn/Confirm')));
    }
}
