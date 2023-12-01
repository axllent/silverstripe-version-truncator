<?php

namespace Axllent\VersionTruncator;

use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Versioned;

class VersionTruncator extends DataExtension
{
    /**
     * Cached Config::inst()
     *
     * @var mixed
     */
    private $conf = false;

    /**
     * Runs after a versioned DataObject is published.
     *
     * @return void
     */
    public function onAfterPublish()
    {
        if (!$this->config('keep_versions')) {
            // skip this DataObject
            return;
        }

        $oldMode = Versioned::get_reading_mode();
        if ('Stage.Stage' != $oldMode) {
            Versioned::set_reading_mode('Stage.Stage');
        }

        $has_stages = $this->owner->hasStages();
        if ($has_stages) {
            $this->doVersionCleanup();
        }

        if ('Stage.Stage' != $oldMode) {
            Versioned::set_reading_mode($oldMode);
        }
    }

    /**
     * Version cleanup
     *
     * @return int
     */
    public function doVersionCleanup()
    {
        // array of version IDs to delete
        $to_delete = [];

        // Base table has Versioned data
        $baseTable = $this->owner->baseTable();

        $total_deleted = 0;

        $keep_versions = $this->config('keep_versions');
        if (is_int($keep_versions) && $keep_versions > 0) {
            $query = new SQLSelect();
            $query->setSelect(['ID', 'Version', 'LastEdited']);
            $query->setFrom($baseTable . '_Versions');
            $query->addWhere(
                [
                    '"RecordID" = ?'     => $this->owner->ID,
                    '"WasPublished" = ?' => 1,
                ]
            );
            if ('SiteTree' == $baseTable && $this->config('keep_redirects')) {
                $query->addWhere(
                    [
                        '"URLSegment" = ?' => $this->owner->URLSegment,
                        '"ParentID" = ?'   => $this->owner->ParentID,
                    ]
                );
            }
            $query->setOrderBy('LastEdited DESC, ID DESC');
            $query->setLimit(100, $keep_versions);

            $results = $query->execute();

            foreach ($results as $result) {
                array_push($to_delete, $result['Version']);
            }

            if ('SiteTree' == $baseTable
                && $this->config('keep_redirects')
            ) {
                // Get the most recent Version IDs of all published pages to ensure
                // we leave at least X versions even if a URLSegment or ParentID
                // has changed.
                $query = new SQLSelect();
                $query->setSelect(
                    ['Version', 'LastEdited']
                );
                $query->setFrom($baseTable . '_Versions');
                $query->addWhere(
                    [
                        '"RecordID" = ?'     => $this->owner->ID,
                        '"WasPublished" = ?' => 1,
                    ]
                );
                $query->setOrderBy('LastEdited DESC');
                $query->setLimit($keep_versions, 0);

                $results = $query->execute();

                $to_keep = [];
                foreach ($results as $result) {
                    array_push($to_keep, $result['Version']);
                }

                // only keep a single historical record of moved/renamed
                // unless they within the `keep_versions` range
                $query = new SQLSelect();
                $query->setSelect(
                    ['Version', 'LastEdited', 'URLSegment', 'ParentID']
                );
                $query->setFrom($baseTable . '_Versions');
                $query->addWhere(
                    [
                        '"RecordID" = ?'     => $this->owner->ID,
                        '"WasPublished" = ?' => 1,
                        '"Version" NOT IN (' . implode(',', $to_keep) . ')',
                        '"URLSegment" != ? OR "ParentID" != ?' => [
                            $this->owner->URLSegment,
                            $this->owner->ParentID,
                        ],
                    ]
                );
                $query->setOrderBy('LastEdited DESC');

                $results = $query->execute();

                $moved_pages = [];

                // create a `ParentID - $URLSegment` array to keep only a single
                // version of each for URL redirection
                foreach ($results as $result) {
                    $key = $result['ParentID'] . ' - ' . $result['URLSegment'];

                    if (in_array($key, $moved_pages)) {
                        array_push($to_delete, $result['Version']);
                    } else {
                        array_push($moved_pages, $key);
                    }
                }
            }
        }

        $keep_drafts = $this->config('keep_drafts');

        // remove drafts keeping `keep_drafts`
        if (is_int($keep_drafts)) {
            $query = new SQLSelect();
            $query->setSelect(['ID', 'Version', 'LastEdited']);
            $query->setFrom($baseTable . '_Versions');
            $query->addWhere(
                'RecordID = ' . $this->owner->ID,
                'WasPublished = 0'
            );
            $query->setOrderBy('LastEdited DESC, ID DESC');
            $query->setLimit(100, $this->config('keep_drafts'));

            $results = $query->execute();

            foreach ($results as $result) {
                array_push($to_delete, $result['Version']);
            }
        }

        if (!count($to_delete)) {
            return;
        }

        // Ugly (borrowed from DataObject::class), but returns all
        // database tables relating to DataObject
        $srcQuery = DataList::create($this->owner->ClassName)
            ->filter('ID', $this->owner->ID)
            ->dataQuery()
            ->query();
        $queriedTables = $srcQuery->queriedTables();

        foreach ($queriedTables as $table) {
            $delSQL = sprintf(
                'DELETE FROM "%s_Versions"
                    WHERE "Version" IN (%s)
                    AND "RecordID" = %d',
                $table,
                implode(',', $to_delete),
                $this->owner->ID
            );

            DB::query($delSQL);

            $total_deleted += DB::affected_rows();
        }

        return $total_deleted;
    }

    /**
     * Return a config variable
     *
     * @param string $key Config key
     *
     * @return mixed
     */
    private function config(string $key)
    {
        if (!$this->conf) {
            $this->conf = Config::inst();
        }

        return $this->conf->get(
            $this->owner->ClassName,
            $key
        );
    }
}
