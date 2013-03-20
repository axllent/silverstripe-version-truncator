# Version truncator for SilverStripe 3
An extension for SilverStripe 3 to automatically truncate old SiteTree versions,
only keeping the last xxx versions per page.

It works for any page extending the SiteTree model, and includes all child models
(eg: Page extends SiteTree, so both Page_versions & SiteTree_versions are truncated)

## Features
* Purge all but the last &lt;xxx&gt; **published** versions of a page (optional)
* Purge all but the last &lt;xxx&gt; **draft** versions of a page (optional)
* Purge all **redundant** versions of a page before switching Page Type (optional)
* Optimize tables / database after purging (optional).
* Supports MySQL, PostgreSQL & SQLite databases

## Requirements
* SilverStripe 3+

## Usage
Install the module, then add the following optional settings to your _config.php
<pre>
VersionTruncator::set_version_limit(10); // Default false
VersionTruncator::set_draft_limit(5); // Default same as set_version_limit
VersionTruncator::set_delete_old_page_types(true); // Default false
VersionTruncator::set_vacuum_tables(true); // Default false
</pre>