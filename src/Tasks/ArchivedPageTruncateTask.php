<?php

namespace Axllent\VersionTruncator\Tasks;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use SilverStripe\SiteConfig\SiteConfig;

class ArchivedPageTruncateTask extends BuildTask
{

    protected $title = 'Archived Page Truncate';

    protected $description = 'Truncate Archived paged';

    public function run($request)
    {
        $template = (php_sapi_name() === 'cli') ? '%s'.PHP_EOL : '%s<br>'.PHP_EOL;

        $singleton = singleton(SiteTree::class);
        $list = $singleton->get();
        $baseTable = $singleton->baseTable();

        $list = $list
            ->setDataQueryParam('Versioned.mode', 'latest_versions');

        $draftTable = $baseTable . '_Draft';
        $list = $list
            ->leftJoin(
                $draftTable,
                "\"{$baseTable}\".\"ID\" = \"{$draftTable}\".\"ID\""
            );

        if ($singleton->hasStages()) {
            $liveTable = $baseTable . '_Live';
            $list = $list->leftJoin(
                $liveTable,
                "\"{$baseTable}\".\"ID\" = \"{$liveTable}\".\"ID\""
            );
        }

        $list = $list->where("\"{$draftTable}\".\"ID\" IS NULL");
        $list = $list->sort('LastEdited DESC')->limit(500);

        foreach ($list as $page) {
            $versions = $page->allVersions();
            echo sprintf($template, sprintf("Deleting Page %s, ID: %d, LastEdited: %s, Versions: %d", $page->Title, $page->ID, $page->LastEdited, $versions->Count()));
            $delSQL = sprintf(
                'DELETE FROM "SiteTree_Versions"
                    WHERE "RecordID" = %d',
                $page->ID
            );
            DB::query($delSQL);
        }

    }
}
