# Attribution Module

This module provides the logic for the **Attribution API**, which serves a REST endpoint to retrieve attribution, licensing, and trust-related signals for specific articles and media files.

## Purpose

The Attribution endpoint is designed to provide a comprehensive set of metadata required to properly attribute a piece of content or a file, including:
* **Essential Data**: Title, artist/credit, and license information.
* **Trust and Relevance**: Page views, reference counts, and contributor metrics.
* **Calls to Action**: Donation and participation links.

## Endpoints

The endpoint is registered as a REST handler under the `attribution/` path.

Sample request: https://en.wikipedia.org/w/rest.php/attribution/v0-beta/pages/Earth/signals?expand=trust_and_relevance,calls_to_action

## Documentation

For detailed information on the API specification, parameters, and response schemas, please refer to the official documentation:
 * [https://www.mediawiki.org/wiki/Attribution_API](https://www.mediawiki.org/wiki/Attribution_API)
 * [Wikimedia Attribution Framework](https://wikimedia-attribution.toolforge.org/)
 * [AttributionAPI in RESTSandbox](https://en.wikipedia.org/w/index.php?api=attribution.v0-beta&title=Special%3ARestSandbox)
