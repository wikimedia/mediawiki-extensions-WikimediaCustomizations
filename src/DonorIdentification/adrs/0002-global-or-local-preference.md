# Phabricator tasks:

- [T416946](https://phabricator.wikimedia.org/T416946): Add a preference for receiving donor information from CiviCRM

# Status
Accepted
Date: April 16 2026

# Problem statement

Should the donor preference be global or local?

# Decision Outcome

Donation status should be a global preference.

Performance of the import script is very important. A local preference would require importing to every Wikimedia project. Since users are not active on all projects this would be wasteful.

From a product/design perspective, having different user experiences across different wikis is confusing (e.g. why do I have a donor badge on English Wikipedia, but not French?)

The GlobalPreference extension supports the concept of automatic globals. When preferences are global, and not hidden, they will appear on the preferences page and operate just like any other preference.

Note: It is important that the language for consent reflects that the preference will work across projects. We have not made decisions about this process yet but will follow up later.

# Decision Drivers

* User experience
    * Sneha (Designer) said it would be confusing if the badge shows on some wikis but not others
* Technical limitations
    * We can only detect the donation cookie on Wikipedia, so if we want to use donor identification outside Wikipedia products, global is beneficial.
    * Support in Extension:GlobalPreferences - we don't want to make major changes to the extension
* Performance
    * How does the local vs global debate impact the importing of data.

