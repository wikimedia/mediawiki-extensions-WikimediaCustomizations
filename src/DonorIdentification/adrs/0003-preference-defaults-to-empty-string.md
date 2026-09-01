# Phabricator tasks:

- [T416946](https://phabricator.wikimedia.org/T420628): Preference opt-out for donor identification

# Status
Accepted
Date: Sept 1 2026

# Problem statement

What should the donor preference be by default?

# Decision Outcome

The donor preference should default to empty string. This avoids having to parse a JSON to determine whether the user is a donor or not.

# Decision Drivers

* API
** It should be possibly to easily query whether a user is a donor or not via JavaScript and in PHP.
* Future private Donor API for CiviCRM.
** In future CiviCRM needs to be able to query MediaWiki to easily access all consenting donors. If the preference is always a JSON blob it means that this can only be done by LIKE queries or parsing the JSON. If it is an empty string can cheaply be ignored.
* Database
** It should be easy to clear out database rows where the user has revoked consent
** We should not store more than we need to in the database
Legal
** Revoking consent should not leave any digital footprint.

