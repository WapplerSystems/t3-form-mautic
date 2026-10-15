# Form Mautic

TYPO3 v14 form finishers and validators that connect EXT:form to a self-hosted
[Mautic](https://mautic.org) instance: sign-up, unsubscribe and a double opt-in
whose confirmation link survives mail security scanners.

| | |
| --- | --- |
| Extension key | `form_mautic` |
| Composer | `wapplersystems/form-mautic` |
| TYPO3 | 14 |
| Mautic | tested with 7.1 |

## Installation

```bash
composer require wapplersystems/form-mautic
vendor/bin/typo3 extension:setup -e form_mautic
```

Add the site set **Form Mautic** (`wapplersystems/form-mautic`) as a dependency of
your site, or include the static TypoScript "Form Mautic" in a classic setup.

## Connecting Mautic

The API is reached through [`wapplersystems/oauth-service`](https://github.com/WapplerSystems/t3-oauth-service)
with the OAuth2 `client_credentials` grant. Create an API client in Mautic
(Settings → API Credentials, OAuth 2), then register it in TYPO3:

```bash
cat client-secret.txt | vendor/bin/typo3 oauth-service:create-client \
  --provider=mautic --client-id="<id>" --secret-stdin \
  --metadata='{"baseUrl":"https://mautic.example.com"}'
vendor/bin/typo3 oauth-service:test-connection mautic
```

The Mautic API user must have a role, otherwise Mautic 7 answers every call with
a 500.

## Finishers

### `MauticSignIn`

Creates or updates the contact right away and optionally adds it to a segment.

```yaml
finishers:
  -
    identifier: MauticSignIn
    options:
      emailField: email-1
      segmentId: ''
      fieldMapping:
        text-1: firstname
        country: country
```

`fieldMapping` maps form element identifiers to Mautic field aliases (a JSON
string works as well, which is what the form editor stores). An ISO alpha-2
value mapped to `country` is converted to the English country name Mautic
expects.

### `MauticDoubleOptIn`

Double opt-in in which Mautic sends the confirmation email and TYPO3 handles
the click.

```yaml
finishers:
  -
    identifier: MauticDoubleOptIn
    options:
      emailField: email-1
      languageField: language      # optional, picks the email per language
      segmentId: ''                # optional, added on confirmation
      fieldMapping:
        text-1: firstname
        country: country
        language: preferred_locale
      confirmationEmails:          # Mautic email ids (type "template")
        de: 194
        en: 195
        default: 195
  -
    identifier: Redirect
    options:
      pageUid: '<page "please check your inbox">'
```

What happens:

1. Only the bare address goes to Mautic, with the tag `doi-pending`, a random
   `confirm_token` and a Do-Not-Contact entry (reason "manual"). The DNC entry
   keeps the contact out of every segment broadcast. The other form values wait
   in the table `tx_formmautic_doi`.
2. Mautic sends the confirmation email. The single-contact send API ignores DNC,
   so the mail still arrives.
3. The link `https://<site>/newsletter/confirm/{contactfield=confirm_token}`
   shows a page with a button. Opening the link confirms nothing, so mail
   scanners that follow every link cannot subscribe anyone.
4. The button lifts the DNC entry, applies the stored fields, replaces
   `doi-pending` with `doi-confirmed` and clears the token.

Safeguards against subscription bombing:

* at most one confirmation email per address within 24 hours,
* an address that already exists in Mautic and is reachable gets no email and
  no changes,
* addresses on the DNC list (unsubscribed, bounced) get no email.

The visitor always proceeds to the next finisher, so the form does not reveal
whether an address is known.

### `MauticSignOut`

Adds the contact to the DNC list and optionally removes it from a segment.

## Validators

| Identifier | Purpose |
| --- | --- |
| `SubmissionRateLimit` | Limits submissions per IP through TYPO3's rate limiter. Options `id`, `limit`, `interval` (e.g. `1 hour`); several instances with different ids stack. Attach to one always-present field. |
| `MauticEmailValidator` | Rejects addresses on Mautic's DNC list. Fails open when Mautic is unreachable. |
| `MauticConnectionValidator` | Fails when the Mautic API is not reachable. |
| `MauticRequiredFieldsValidator` | Required check with a Mautic-specific message. |

```yaml
validators:
  -
    identifier: SubmissionRateLimit
    options:
      id: newsletter-hour
      limit: 5
      interval: '1 hour'
```

## Setting up Mautic for the double opt-in

* A contact field `confirm_token` (text).
* One email per language of type *template* with the confirm link
  `https://<site>/newsletter/confirm/{contactfield=confirm_token}`.
* Cleanup of unconfirmed sign-ups: a segment with the filters
  "tags including `doi-pending`" and "date added less than `-10 days`", and a
  published campaign with that segment as source and the single action
  "Delete contact". Only contacts created by the finisher carry `doi-pending`.
* Cron jobs for `mautic:segments:update`, `mautic:campaigns:rebuild` and
  `mautic:campaigns:trigger`. Without `campaigns:trigger` no campaign action runs.

Unconfirmed rows in `tx_formmautic_doi` are deleted after 10 days, confirmed
rows remain as proof of consent.

## Site settings

| Setting | Default | |
| --- | --- | --- |
| `formMautic.doubleOptIn.confirmedPageUid` | `0` | Page to redirect to after a confirmation. Without one the confirm page shows its own thank-you message. |
| `formMautic.doubleOptIn.templateRootPath` | `''` | Own templates for the confirm page; must contain `DoubleOptIn/Confirm.html`. |

The confirm page is translated into English, German, French, Spanish,
Portuguese and Dutch and follows the site language of the sign-up.

## License

GPL-2.0-or-later
