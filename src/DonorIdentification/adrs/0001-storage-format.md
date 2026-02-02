# Phabricator tasks:

- [T416946](https://phabricator.wikimedia.org/T416946): Add a preference for receiving donor information from CiviCRM

# Status
Accepted
Date: April 16 2026

# Problem statement

Fundraising has existing donor segment IDs for categorizing donors that we want to reuse in products in a way that is privacy conscious and considerate of product and technologies with user consent.

The information is limited, avoiding sensitive information such as amount donated, and personal information.

For non-consenting users no information must be stored against their account that may identify them as a donor.

# Decision Outcome

We will store preferences as a JSON blob. The default preference will reflect a non-consenting donor `{ value: 0 }`

We will use the donor segment IDs directly to avoid fragmentation with existing fundraising categorization. The categories are as follows:

Markdown
| ID | Name | Description | Source | Category |
| :--- | :--- | :--- | :--- | :--- |
| **0** | Opted out | No consent given and/or not a donor | Default | New / Recent Supporter |
| **1** | Donor | Consented and we know they are a donor from their cookie | MediaWiki (consent flow) | New / Recent Supporter |
| **2** | Active Recurring | Gave monthly recurring within last month | CiviCRM | Sustaining Donor |
| **4** | Delinquent Recurring | Gave monthly recurring 1-3 months ago | CiviCRM | Sustaining Donor |
| **6** | Recent Lapsed Recurring | Gave monthly recurring 3-6 months ago | CiviCRM | Sustaining Donor |
| **8** | Deep Lapsed Recurring | Gave monthly recurring 6-36 months ago | CiviCRM | Lapsed Supporter |
| **12** | Active Annual Recurring | Has an active annual recurring plan | CiviCRM | Sustaining Donor |
| **14** | Delinquent Annual Recurring | Annual plan cancelled within last 3 months | CiviCRM | Sustaining Donor |
| **16** | Lapsed Annual Recurring | Annual plan cancelled 3-13 months ago | CiviCRM | Lapsed Supporter |
| **20** | Consecutive | Gave last FY and this FY to date | CiviCRM | Returning / Loyal Supporter |
| **25** | New | First donation this FY | CiviCRM | New / Recent Supporter |
| **30** | Active | Gave in this FY | CiviCRM | New / Recent Supporter |
| **35** | Lybunt | Gave last FY but not this FY to date | CiviCRM | New / Recent Supporter |
| **50** | Lapsed | Last gave in the FY before last | CiviCRM | Lapsed Supporter |
| **60** | Deep Lapsed | Last gave 2-5 FYs ago | CiviCRM | Lapsed Supporter |
| **70** | Ultra Lapsed | Gave prior to 5 FYs ago | CiviCRM | Lapsed Supporter |
| **1000** | Non Donor | No donations in last 200 months OR consented but not categorized as a donor | CiviCRM, MediaWiki | Contactable Reader (email address shared but no donations) / consent |

When a user has an ID greater than 0, we can assume that we also have their consent.

We will store the values as a JSON blob `{ value:X }` to allow us to expand or alter the format in future.
For example, we may need to add an expiration date or version number in future. `{ value:X, version: 2, expires: 2026-04-20 }`. We have confirmed with SRE that a maintenance script can work on all user preferences and decode JSON if necessary provided it runs on batches of 500 and reads from a replica. We already have a precedent for scripts that do this daily on tens of millions of rows.

# Decision Drivers

* Technical
    * We plan to use client preferences API for logged out users. Client preferences does not support negative numbers, so restricting status codes to positive integers will avoid needing to map these numbers to other numbers.
    * The format must not impact user performance
    * The format must not create new problems for SRE
* Legal
    * it is essential we do not store anything for non-donors without their consent. The default value is therefore should be opted-out/no consent
    * we should support expiring of consent in future
* Expansibility
    * Fundraising are planning on updating status codes in the next fiscal year, so we want to be able to recategorize donors in future via future maintenance scripts to support this. For example we might need to mapping existing status codes to new ones.
    *  We may want to create new types of donor categories in future. It should be possible to support this.
