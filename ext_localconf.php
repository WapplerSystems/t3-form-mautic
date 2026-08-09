<?php

use TYPO3\CMS\Core\Utility\GeneralUtility;
use WapplerSystems\OauthService\Provider\ProviderDefinition;
use WapplerSystems\OauthService\Provider\ProviderRegistryInterface;

(static function () {
    $registry = GeneralUtility::makeInstance(ProviderRegistryInterface::class);
    $registry->register(new ProviderDefinition(
        identifier: 'mautic',
        title: 'Mautic',
        type: 'generic_oauth2',
        // Mautic is self-hosted: every installation runs on its own domain, so
        // there is no fixed authorizationUrl/tokenUrl (unlike Mailchimp's
        // login.mailchimp.com). authorizationPath/tokenPath are combined with
        // the per-client "baseUrl" entry in tx_oauthsvc_client.metadata by
        // oauth-service's MetadataDiscoveryService (requires oauth-service
        // >= 14.3.0, which added per-client base URL support for exactly this
        // case). Set metadata to e.g. {"baseUrl":"https://intewa-newsletter.de"}
        // on the client record.
        authorizationPath: '/oauth/v2/authorize',
        tokenPath: '/oauth/v2/token',
    ));
})();
