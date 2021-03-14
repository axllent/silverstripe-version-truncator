<?php
namespace Axllent\VersionTruncator\Tasks;

use Axllent\VersionTruncator\VersionTruncator;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Versioned;

/**
 * Prunes the database of old SiteTree versions & drafts
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
            print '<h3>Select a task:</h3>
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
                            - Deleted all versions of deleted files.
                        </p>
                    </li>
                    <li>
                        <p>
                            <a href="?reset=1" onclick="return Confirm()">Reset all</a>
                            - This prunes ALL previous versions for all published versioned
                            DataObjects, keeping only the latest single <strong>published</strong>
                            version.<br />
                            This deletes all references to old pages, drafts, and previous
                            pages with different URLSegments (redirects). Unpublished DataObjects,
                            including those that have drafts are not modified.
                        </p>
                    </li>
                </ul>
                <script type="text/javascript">
                    function Confirm(q) {
                        var question = "WARNING: Please confirm you wish to delete ALL historical " +
                        "versions for all versioned DataObjects?";
                        if (confirm(question)) {
                            return true;
                        }
                        return false;
                    }
                </script>
            ';
        }

        $reset = $request->getVar('reset');
        $prune = $request->getVar('prune');
        $files = $request->getVar('files');

        if ($reset) {
            $this->_reset();
        } elseif ($prune) {
            $this->_prune();
        } elseif ($files) {
            $this->_pruneDeletedFileVersions();
        }
    }

    /**
     * Prune all published DataObjects which are published according to config
     *
     * @return void
     */
    private function _prune()
    {
        $classes = $this->_getAllVersionedDataClasses();

        DB::alteration_message('Pruning all DataObjects');

        $total = 0;

        foreach ($classes as $class) {
            $records = Versioned::get_by_stage($class, Versioned::DRAFT);
            $deleted = 0;

            foreach ($records as $r) {
                if ($r->isLiveVersion()) {
                    $deleted += $r->doVersionCleanup();
                }
            }

            if ($deleted > 0) {
                DB::alteration_message(
                    'Deleted ' . $deleted . ' versioned ' . $class . ' records'
                );

                $total += $deleted;
            }
        }

        DB::alteration_message('Completed, pruned ' . $total . ' records');
    }

    /**
     * Prune versions of deleted files/folders
     *
     * @return HTTPResponse
     */
    private function _pruneDeletedFileVersions()
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

        DB::alteration_message('Completed, pruned ' . $deleted . ' File records');
    }

    /**
     * Delete all previous records of published records
     *
     * @return HTTPResponse
     */
    private function _reset()
    {
        DB::alteration_message('Pruning all published records');

        $classes = $this->_getAllVersionedDataClasses();

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
                DB::alteration_message(
                    'Deleted ' . $deleted . ' versioned ' . $class . ' records'
                );
                $total += $deleted;
            }
        }

        DB::alteration_message('Completed, pruned ' . $total . ' records');

        $this->_pruneDeletedFileVersions();
    }

    /**
     * Get all versioned database classes
     *
     * @return array
     */
    private function _getAllVersionedDataClasses()
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
