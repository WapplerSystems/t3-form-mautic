<?php

return [
    'frontend' => [
        'wapplersystems/form-mautic/double-opt-in-confirm' => [
            'target' => \WapplerSystems\FormMautic\Middleware\DoubleOptInConfirmMiddleware::class,
            // The confirm path carries no language prefix: answer it before TYPO3
            // redirects a language-less URL or tries to resolve it as a page.
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/base-redirect-resolver',
            ],
        ],
    ],
];
