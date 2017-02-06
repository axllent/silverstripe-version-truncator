# Version truncator for SilverStripe

An extension for SilverStripe to automatically delete old SiteTree page versions,
keeping the last XX versions per page.

It works for any page extending the SiteTree model, and includes all child models
(eg: Page extends SiteTree, so both Page_versions & SiteTree_versions are truncated).

## Features

* Delete all but the last XX **published** versions of a page (default 10)
* Delete all but the last XX **draft** versions of a page (default 5)
* Optionally keep old versions where the URLSegment has changed to preserve redirects (default true)
* Delete all **redundant** versions of a page when switching Page Type (default true)

## Tasks

The module adds two manual tasks to:
1. Force a run over the entire database
2. Force a "reset", keeping only the latest published version of each currently published page.

## Requirements

* SilverStripe 4+

## Installation

Installation can be done either by composer or by manually downloading a release.

### Via composer

`composer require "axllent/silverstripe-version-truncator"`

## Configuration

Configuration is optional, however you can create a YML file (eg: `mysite/_config/version-truncator.yml`)
and add/edit the following values:

```
Axllent\VersionTruncator\VersionTruncator:
  keep_versions: 10           # how many (published) versions of each page to keep
  keep_drafts: 5              # how many drafts of each page to keep
  keep_redirects: true        # keep page versions that have a different URLSegment (for redirects)
  keep_old_page_types: false  # keep page versions where page type (ClassName) has changed
```
