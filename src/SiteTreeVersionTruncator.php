<?php

namespace Axllent\VersionTruncator;

use Axllent\VersionTruncator\VersionTruncator;
use SilverStripe\CMS\Model\SiteTreeExtension;

/**
 * SiteTree Version Truncator for SilverStripe
 * ===========================================
 *
 * A SilerStripe extension to automatically delete old published & draft
 * versions from all classes extending the SiteTree (like Page) upon save/publish.
 *
 * Please refer to the README.md for confirguration options.
 *
 * License: MIT-style license http://opensource.org/licenses/MIT
 * Authors: Techno Joy development team (www.technojoy.co.nz)
 */

class SiteTreeVersionTruncator extends SiteTreeExtension
{

    /*
     * Automatically invoked after every write() on a SiteTree object
     * @param null
     * @return null
     */
    public function onAfterWrite()
    {
        parent::onAfterWrite();

        VersionTruncator::pruneByID($this->owner->ID);
    }
}
