<?php

namespace Axllent\VersionTruncator\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Versioned;

/**
 * Prunes DataObject versions & drafts
 */
class TruncateVersionsTask extends BuildTask
{
    /**
     * URL segment
     *
     * @var string
     */
    private static $segment = 'TruncateVersionsTask';

    /**
     * Task title
     *
     * @var string
     */
    protected $title = 'Prune old DataObject versions';

    /**
     * Task description
     *
     * @var string
     */
    protected $description = 'Delete old versioned DataObject versions from the database';

    /**
     * Run task
     *
     * @param HTTPRequest $request HTTP request
     *
     * @return HTTPResponse
     */
    public function run($request)
    {
        if (!Director::is_cli()) {
            echo '<h3>Select a task:</h3>
                <p>You do not normally need to run these tasks, as pruning is run automatically
                whenever a versioned DataObject is published.</p>
                <ul>
                    <li>
                        <p>
                            <a href="?prune=1">Prune all</a>
                            - Prune all published versioned DataObjects according to your policies.
                            This is normally not required as pruning is done automatically when any
                            versioned record is published.
                        </p>
                    </li>
                    <li>
                        <p>
                            <a href="?files=1">Prune deleted files</a>
                            - Delete all versions belonging to deleted files.
                        </p>
                    </li>
                    <li>
                        <p>
                            <a href="?reset=1" onclick="return confirm(`WARNING!!!\nPlease confirm you wish to delete ALL historical versions for all versioned DataObjects?`)">Reset all</a>
                            - This prunes ALL previous versions for published DataObjects, keeping only
                            the latest single <strong>published</strong> version.<br />
                            This deletes all references to old pages, drafts, and previous
                            pages with different URLSegments (redirects). Unpublished DataObjects,
                            including those that have drafts are not modified.
                        </p>
                    </li>
                    <li>
                    <p>
                        <a href="?archived=1" onclick="return confirm(`WARNING!!!\nPlease confirm you wish to delete ALL archived DataObjects?`)">Delete archived DataObjects</a>
                        - Delete all <b>archived</b> DataObjects.
                    </p>
                </li>
                </ul>
            ';
        }

        $reset    = $request->getVar('reset');
        $prune    = $request->getVar('prune');
        $files    = $request->getVar('files');
        $archived = $request->getVar('archived');

        if ($reset) {
            $this->reset();
        } elseif ($prune) {
            $this->prune();
        } elseif ($files) {
            $this->pruneDeletedFileVersions();
        } elseif ($archived) {
            $this->deleteArchivedDataObjects();
        }
    }

    /**
     * Prune all published DataObjects which are published according to config
     *
     * @return void
     */
    private function prune()
    {
        $classes = $this->getAllVersionedDataClasses();

        DB::alteration_message('Pruning all DataObjects');

        $total = 0;

        foreach ($classes as $class) {
            $records = Versioned::get_by_stage($class, Versioned::DRAFT);
            $deleted = 0;

            foreach ($records as $r) {
                // check if stages are present
                if (!$r->hasStages()) {
                    continue;
                }

                if ($r->isLiveVersion()) {
                    $deleted += $r->doVersionCleanup();
                }
            }

            if ($deleted > 0) {
                DB::alteration_message("Deleted {$deleted} versioned {$class} records");

                $total += $deleted;
            }
        }

        DB::alteration_message("Completed, pruned {$total} records");
    }

    /**
     * Prune versions of deleted files/folders
     *
     * @return void
     */
    private function pruneDeletedFileVersions()
    {
        DB::alteration_message('Pruning all deleted File DataObjects');

        $query = new SQLSelect();
        $query->setSelect(['RecordID']);
        $query->setFrom('File_Versions');
        $query->addWhere(
            [
                '"WasDeleted" = ?' => 1,
            ]
        );

        $to_delete = [];

        $results = $query->execute();

        foreach ($results as $result) {
            array_push($to_delete, $result['RecordID']);
        }

        if (!count($to_delete)) {
            DB::alteration_message('Completed, pruned 0 File records');

            return;
        }

        $deleteSQL = sprintf(
            'DELETE FROM File_Versions WHERE "RecordID" IN (%s)',
            implode(',', $to_delete)
        );

        DB::query($deleteSQL);

        $deleted = DB::affected_rows();

        DB::alteration_message("Completed, pruned {$deleted} File records");
    }

    /**
     * Delete all previous records of published records
     *
     * @return void
     */
    private function reset()
    {
        DB::alteration_message('Pruning all published records');

        $classes = $this->getAllVersionedDataClasses();

        $total = 0;

        foreach ($classes as $class) {
            $records = Versioned::get_by_stage($class, Versioned::DRAFT);
            $deleted = 0;

            // set to minimum
            $class::config()->set('keep_versions', 1);
            $class::config()->set('keep_drafts', 0);
            $class::config()->set('keep_redirects', false);

            foreach ($records as $r) {
                if ($r->isLiveVersion()) {
                    $deleted += $r->doVersionCleanup();
                }
            }

            if ($deleted > 0) {
                DB::alteration_message("Deleted {$deleted} versioned {$class} records");
                $total += $deleted;
            }
        }

        DB::alteration_message("Completed, pruned {$total} records");

        $this->pruneDeletedFileVersions();
    }

    /**
     * Delete All Archived DataObjects
     *
     * @return void
     */
    private function deleteArchivedDataObjects()
    {
        $total = 0;

        DB::alteration_message('Deleting all archived DataObjects');

        $classes = $this->getAllVersionedDataClasses();

        foreach ($classes as $class) {
            $singleton = singleton($class);
            $list      = $singleton->get();
            $baseTable = $singleton->baseTable();

            $list = $list->setDataQueryParam('Versioned.mode', 'latest_versions');

            $draftTable = $baseTable . '_Draft';
            $list       = $list
                ->leftJoin(
                    $draftTable,
                    "\"{$baseTable}\".\"ID\" = \"{$draftTable}\".\"ID\""
                );

            if ($singleton->hasStages()) {
                $liveTable = $baseTable . '_Live';
                $list      = $list->leftJoin(
                    $liveTable,
                    "\"{$baseTable}\".\"ID\" = \"{$liveTable}\".\"ID\""
                );
            }

            $list = $list->where("\"{$draftTable}\".\"ID\" IS NULL");

            $deleted = 0;

            foreach ($list as $rec) {
                $deleteSQL = sprintf(
                    'DELETE FROM "%s_Versions"
                        WHERE "RecordID" = %s',
                    $baseTable,
                    $rec->ID
                );
                DB::query($deleteSQL);

                ++$deleted;
            }

            if ($deleted > 0) {
                DB::alteration_message("Deleted {$deleted} archived {$class} records");
                $total += $deleted;
            }
        }

        DB::alteration_message("Completed, deleted {$total} archived DataObjects");
    }

    /**
     * Get all versioned database classes
     *
     * @return array
     */
    private function getAllVersionedDataClasses()
    {
        $all_classes       = ClassInfo::subclassesFor(DataObject::class);
        $versioned_classes = [];
        foreach ($all_classes as $c) {
            if (DataObject::has_extension($c, Versioned::class)) {
                array_push($versioned_classes, $c);
            }
        }

        return array_reverse($versioned_classes);
    }
}
