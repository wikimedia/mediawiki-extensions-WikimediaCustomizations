# Code ownership

WikimediaCustomizations serves as the place to store Wikimedia-specific supplements to other
extensions, and other Wikimedia-specific customizations which are too simple to merit an extension
of their own but too complex to handle via configuration overrides. This means that the extension
won't be owned by a single team; extension-related parts will be owned by the team that owns the
extension, and other parts will be generally owned by whoever added them.

Please structure new code in such a way that it is easy to tell who owns what, and add an entry to
this file documenting the ownership. E.g.

> ## example component
>
> * [Files/Directories]: [list of files/directories]
> * Contact: [team page URL]
>
> [description of what the component does]

The general expectation is the same as with deploying a new extension: you shouldn't do it unless
your team is taking ownership of the code, or you have arranged ownership with some other team.

The exception is moving over legacy unowned code from operations/mediawiki-config etc. You should
still add an OWNERS.md entry, but mark it as unowned.

## BadEmailDomain

* Directories:
  * src/BadEmailDomain
  * tests/phpunit/unit/BadEmailDomain
  * tests/phpunit/data/BadEmailDomain
* Contact: https://www.mediawiki.org/wiki/Product_Safety_and_Integrity

Prevents the use of email providers which appear on a deny-list.
Used to disallow disposable email addresses.

Originally moved here from the private Wikimedia repo, see history there for more context.

## EmailAuth

* Directories:
  * src/EmailAuth
  * tests/phpunit/integration/EmailAuth
* Dependencies: EmailAuth, IPReputation, LoginNotify, OATHAuth, WikimediaEvents, cldr (all but
  the first optional).
* Legacy code, no assigned owner

Business logic for when the EmailAuth extension should perform email verification.

Most of it should probably be moved to EmailAuth.

## RateLimit

* Directories:
  * src/RateLimit
* Contact: https://www.mediawiki.org/wiki/MediaWiki_Platform_Team

Adds a rate limit class ('rlc') field to JWTs associated with login sessions.
Used to enforce low-level rate limits (outside of MediaWiki).
Historical context: T399632, T415588.

## Attribution API

* Directory: `src/Attribution`
* Contact: [MediaWiki Interfaces Team](https://www.mediawiki.org/wiki/MediaWiki_Interfaces_Team)

Experimental REST API definition and related code for exposing structured attribution information about Wikimedia page or media, as defined by the Attribution Framework work.

To expose the REST endpoint in Special:RestSandbox, add this to `LocalSettings.php`:

```php
$wgRestSandboxSpecs['attribution.v0-beta'] = [
    'url' => $wgScriptPath . '/rest.php/specs/v0/module/attribution/v0-beta',
    'name' => 'Attribution API',
];
```

## ...
