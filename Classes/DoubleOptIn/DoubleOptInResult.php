<?php

declare(strict_types=1);

namespace WapplerSystems\FormMautic\DoubleOptIn;

enum DoubleOptInResult: string
{
    case Sent = 'sent';
    case Throttled = 'throttled';
    case AlreadySubscribed = 'already-subscribed';
    case DoNotContact = 'do-not-contact';
    case Failed = 'failed';
}
