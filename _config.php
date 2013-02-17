<?php
/* Add extension to Sitetree */
Object::add_extension('SiteTree', 'VersionTruncator');

/* Set the number of most recent versions of each page to keep */
// VersionTruncator::set_version_limit(10); // Default false
// VersionTruncator::set_draft_limit(5); // Default same as set_version_limit
// VersionTruncator::set_delete_old_page_types(true); // Default false
// VersionTruncator::set_vacuum_tables(true); // Default false