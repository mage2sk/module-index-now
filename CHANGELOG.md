# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.3] - 2026-10-04

### Fixed
- The API Key field in Stores > Configuration > Panth Extensions > IndexNow is now checked in the browser before the configuration is saved. A key that is not 8 to 128 letters, digits or dashes shows the error under the field and the typed value stays in place; before, the page reloaded with an error message and the field was emptied.
