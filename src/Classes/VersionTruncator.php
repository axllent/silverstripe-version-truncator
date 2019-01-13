<?php

namespace Axllent\VersionTruncator;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;

class VersionTruncator
{
    /**
     * @Config
     * How many published versions to keep
     */
    private static $keep_versions = 10;

    /**
     * @Config
     * How many draft versions to keep
     */
    private static $keep_drafts = 5;

    /**
     * @Config
     * Keep page versions that have a different URLSegment (for redirects)
     */
    private static $keep_redirects = true;

    /**
     * @Config
     * Keep page versions where page type (ClassName) has changed
     */
    private static $keep_old_page_types = false;

    /**
     * Prune SiteTree by ID
     *
     * @param  Int
     * @return Int
     */
    public static function pruneByID($RecordID)
    {
        $keep_versions = Config::inst()->get(VersionTruncator::class, 'keep_versions');
        $keep_drafts = Config::inst()->get(VersionTruncator::class, 'keep_drafts');
        $keep_redirects = Config::inst()->get(VersionTruncator::class, 'keep_redirects');
        $keep_old_page_types = Config::inst()->get(VersionTruncator::class, 'keep_old_page_types');

        $query = new SQLSelect();
        $query->setFrom('SiteTree_Versions');
        $query->addWhere('RecordID = ' . $RecordID);
        $query->setOrderBy('LastEdited DESC');

        $result = $query->execute();

        $publishedCount = 0;
        $draftCount = 0;
        $seen_url_segments = [];
        $versionsToDelete = [];

        foreach ($result as $row) {
            $ID = $row['ID'];
            $RecordID = $row['RecordID'];
            $ClassName = $row['ClassName'];
            $Version = $row['Version'];
            $WasPublished = $row['WasPublished'];
            $URLSegment = $row['ParentID'] . $row['URLSegment'];

            $live_version = SiteTree::get()->byID($row['RecordID']);

            if ( // automatically delete versions of old page types
                !$keep_old_page_types &&
                $live_version &&
                $live_version->ClassName != $ClassName
            ) {
                array_push($versionsToDelete, [
                    'RecordID'  => $RecordID,
                    'Version'   => $Version,
                    'ClassName' => $ClassName,
                ]);
            } elseif (!$WasPublished && $keep_drafts) { // draft
                $draftCount++;
                if ($draftCount > $keep_drafts) {
                    array_push($versionsToDelete, [
                        'RecordID'  => $RecordID,
                        'Version'   => $Version,
                        'ClassName' => $ClassName,
                    ]);
                }
            } elseif ($keep_versions) { // published
                $publishedCount++;
                if ($publishedCount > $keep_versions) {
                    if (!$keep_redirects || in_array($URLSegment, $seen_url_segments)) {
                        array_push($versionsToDelete, [
                            'RecordID'  => $RecordID,
                            'Version'   => $Version,
                            'ClassName' => $ClassName,
                        ]);
                    }
                }
                // add page to "seen URLs" if $preserve_redirects
                if ($keep_redirects && !in_array($URLSegment, $seen_url_segments)) {
                    array_push($seen_url_segments, $URLSegment);
                }
            }
        }

        $affected_rows = 0;

        if (count($versionsToDelete) > 0) {
            $table_list = DB::table_list();
            $affected_tables = [];
            $doschema = new DataObjectSchema();
            foreach ($versionsToDelete as $d) {
                $subclasses = ClassInfo::dataClassesFor($d['ClassName']);
                foreach ($subclasses as $subclass) {
                    $table_name = strtolower($doschema->tableName($subclass));
                    if (!empty($table_list[$table_name . '_versions'])) {
                        DB::query('DELETE FROM "' . $table_list[$table_name . '_versions'] . '" WHERE
							"RecordID" = ' . $d['RecordID'] . ' AND "Version" = ' . $d['Version']);
                        $affected_rows = $affected_rows + DB::affected_rows();
                    }
                }
            }
        }

        return $affected_rows;
    }

    /**
     * Remove ALL previous versions of a SiteTree record
     *
     * @param  Null
     * @return Int
     */
    public static function deleteAllButLive()
    {
        $query = new SQLSelect();
        $query->setFrom('SiteTree_Versions');

        $versionsToDelete = [];

        $results = $query->execute();

        foreach ($results as $row) {
            $ID = $row['ID'];
            $RecordID = $row['RecordID'];
            $Version = $row['Version'];
            $ClassName = $row['ClassName'];

            // is record is live?
            $query = new SQLSelect();
            $query->setSelect('Count(*) as Count');
            $query->setFrom('SiteTree_Live');
            $query->addWhere('ID = ' . $RecordID);
            $query->addWhere('Version = ' . $Version);

            $is_live = $query->execute()->value();

            if (!$is_live) {
                array_push($versionsToDelete, [
                    'RecordID'  => $RecordID,
                    'Version'   => $Version,
                    'ClassName' => $ClassName,
                ]);
            }
        }

        $affected_rows = 0;

        if (count($versionsToDelete) > 0) {
            $table_list = DB::table_list();
            $affected_tables = [];
            $doschema = new DataObjectSchema();
            foreach ($versionsToDelete as $d) {
                $subclasses = ClassInfo::dataClassesFor($d['ClassName']);
                foreach ($subclasses as $subclass) {
                    $table_name = strtolower($doschema->tableName($subclass));
                    if (!empty($table_list[$table_name . '_versions'])) {
                        DB::query('DELETE FROM "' . $table_list[$table_name . '_versions'] . '" WHERE
							"RecordID" = ' . $d['RecordID'] . ' AND "Version" = ' . $d['Version']);
                        $affected_rows = $affected_rows + DB::affected_rows();
                    }
                }
            }
        }

        return $affected_rows;
    }
}
