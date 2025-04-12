<?php

namespace Axllent\VersionTruncator\Tasks;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Prunes DataObject versions & drafts
 */
class TruncateVersionsTask extends BuildTask
{
    /**
     * Command name
     */
    protected static string $commandName = 'TruncateVersionsTask';

    /**
     * Task title
     */
    protected string $title = 'Prune old DataObject versions';

    /**
     * Task description
     */
    protected static string $description = 'Delete old versioned DataObject versions from the database';

    /**
     * Execute task
     */
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        if ($input->getOption('prune')) {
            $this->prune($output);
        }
        if ($input->getOption('files')) {
            $this->pruneDeletedFileVersions($output);
        }
        if ($input->getOption('reset')) {
            $this->reset($output);
        }
        if ($input->getOption('archived')) {
            $this->deleteArchivedDataObjects($output);
        }

        $output->writeForHtml('<h3>Select a task:</h3>
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
                            <a href="?reset=1"
                                onclick="return confirm(`WARNING!!!\nPlease confirm you wish to delete ALL historical versions for all versioned DataObjects?`)">
                                Reset all
                            </a>
                            - This prunes ALL previous versions for published DataObjects, keeping only
                            the latest single <strong>published</strong> version.<br />
                            This deletes all references to old pages, drafts, and previous
                            pages with different URLSegments (redirects). Unpublished DataObjects,
                            including those that have drafts are not modified.
                        </p>
                    </li>
                    <li>
                    <p>
                        <a href="?archived=1"
                            onclick="return confirm(`WARNING!!!\nPlease confirm you wish to delete ALL archived DataObjects?`)">
                                Delete archived DataObjects
                            </a>
                        - Delete all <b>archived</b> DataObjects.
                    </p>
                </li>
                </ul>
            ', false);

        return Command::SUCCESS;
    }

    public function getOptions(): array
    {
        return [
            new InputOption(
                'prune',
                null,
                InputOption::VALUE_NONE,
                'Prune all published versioned DataObjects according to your policies'
            ),
            new InputOption(
                'files',
                null,
                InputOption::VALUE_NONE,
                'Delete all versions belonging to deleted files'
            ),
            new InputOption(
                'reset',
                null,
                InputOption::VALUE_NONE,
                'Delete ALL historical versions for all versioned DataObjects'
            ),
            new InputOption(
                'archived',
                null,
                InputOption::VALUE_NONE,
                'Delete archived DataObjects'
            ),
        ];
    }

    /**
     * Prune all published DataObjects which are published according to config
     *
     * @return void
     */
    private function prune(PolyOutput $output)
    {
        $classes = $this->getAllVersionedDataClasses();

        $output->writeln('Pruning all DataObjects');

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
                $output->writeln("Deleted {$deleted} versioned {$class} records");

                $total += $deleted;
            }
        }

        $output->writeln("Completed, pruned {$total} records");
    }

    /**
     * Prune versions of deleted files/folders
     *
     * @return void
     */
    private function pruneDeletedFileVersions(PolyOutput $output)
    {
        $output->writeln('Pruning all deleted File DataObjects');

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
            $output->writeln('Completed, pruned 0 File records');

            return;
        }

        $deleteSQL = sprintf(
            'DELETE FROM File_Versions WHERE "RecordID" IN (%s)',
            implode(',', $to_delete)
        );

        DB::query($deleteSQL);

        $deleted = DB::affected_rows();

        $output->writeln("Completed, pruned {$deleted} File records");
    }

    /**
     * Delete all previous records of published records
     *
     * @return void
     */
    private function reset(PolyOutput $output)
    {
        $output->writeln('Pruning all published records');

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
                $output->writeln("Deleted {$deleted} versioned {$class} records");
                $total += $deleted;
            }
        }

        $output->writeln("Completed, pruned {$total} records");

        $this->pruneDeletedFileVersions($output);
    }

    /**
     * Delete All Archived DataObjects
     *
     * @return void
     */
    private function deleteArchivedDataObjects(PolyOutput $output)
    {
        $total = 0;

        $output->writeln('Deleting all archived DataObjects');

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
                $output->writeln("Deleted {$deleted} archived {$class} records");
                $total += $deleted;
            }
        }

        $output->writeln("Completed, deleted {$total} archived DataObjects");
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
