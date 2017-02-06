<?php

namespace Axllent\VersionTruncator\Tasks;

use Axllent\VersionTruncator\VersionTruncator;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DB;
use SilverStripe\Dev\BuildTask;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\Versioning\Versioned;

/**
 * Prunes the database of old SiteTree versions & drafts
 */
class TruncateVersionsTask extends BuildTask
{

    private static $segment = 'TruncateVersionsTask';

    protected $title = 'Purge old SiteTree versions';

    protected $description = 'Delete old SiteTree versions as well as drafts from the database';


    public function run($request)
    {
        $this->keep_versions = Config::inst()->get('Axllent\VersionTruncator\VersionTruncator', 'keep_versions');
        $this->keep_drafts = Config::inst()->get('Axllent\VersionTruncator\VersionTruncator', 'keep_drafts');

        echo '<h3>Select a task:</h3>
			<ul>
				<li>
					<p>
						<a href="?cleanup=1" onclick="return Confirm(\'Cleanup\')">Cleanup</a>
						- Force a cleanup of the database keeping only the latest <strong>' . $this->keep_versions . '</strong> SiteTree versions
						and <strong>' . $this->keep_drafts . '</strong> drafts.
					</p>
				</li>
				<li>
					<p>
						<a href="?reset=1" onclick="return Confirm()">Reset</a>
						- Delete ALL old SiteTree versions, keeping only the latest <strong>published</strong> version.<br />
						This deletes all references to old pages, drafts, including previous pages with different URLSegments (redirects).
					</p>
				</li>
			</ul>
			<script type="text/javascript">
				function Confirm(q) {
					if (q == "Cleanup") {
						var question = "Please confirm you wish to clean the SiteTree database?";
					} else {
						var question = "WARNING: Please confirm you wish to delete ALL SiteTree versions except for the most recent PUBLISHED versions?";
					}
					if (confirm(question)) {
						return true;
					}
					return false;
				}
			</script>
		';

        $reset = $request->getVar('reset');
        $cleanup = $request->getVar('cleanup');

        if ($reset) {
            $this->deleteAllButLive();
        } elseif ($cleanup) {
            $this->cleanupVersions();
        }
    }

    private function cleanupVersions()
    {
        $current_sitetree = SiteTree::get();

        $current_ids = [];

        $deleted = 0;

        foreach ($current_sitetree as $p) {
            $current_ids[] = $p->ID;
            $deleted = $deleted + VersionTruncator::pruneByID($p->ID);
        }

        DB::alteration_message($deleted . ' records deleted.', 'changed');
    }

    private function deleteAllButLive()
    {
        $deleted = VersionTruncator::deleteAllButLive();
        DB::alteration_message($deleted . ' records deleted.', 'changed');
    }
}
