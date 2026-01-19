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

## ...
