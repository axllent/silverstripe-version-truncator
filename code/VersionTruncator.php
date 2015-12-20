<?php
/**
 * Version Truncator for SilverStripe
 * ==================================
 *
 * A SilerStripe extension to automatically delete old published & draft
 * versions from all classes extending the SiteTree (like Page) upon save.
 *
 * Please refer to the README.md for confirguration options.
 *
 * License: MIT-style license http://opensource.org/licenses/MIT
 * Authors: Techno Joy development team (www.technojoy.co.nz)
 */

class VersionTruncator extends SiteTreeExtension
{

    private static $version_limit = 10;

    private static $draft_limit = 5;

    private static $vacuum_tables = false;

    private static $delete_old_page_types = false;

    /*
     * Automatically invoked with any save() on a SiteTree object
     * @param null
     * @return null
     */
    public function onAfterWrite()
    {
        parent::onAfterWrite();

        $ID = $this->owner->ID;
        $className = $this->owner->ClassName;
        $subClasses = ClassInfo::dataClassesFor($className);
        $versionsToDelete = array();

        $version_limit = Config::inst()->get('VersionTruncator', 'version_limit');

        if (is_numeric($version_limit)) {
            $search = DB::query('SELECT "RecordID", "Version" FROM "SiteTree_versions"
				 WHERE "RecordID" = ' . $ID  . ' AND "ClassName" = \'' . $className . '\'
				 AND "PublisherID" > 0
				 ORDER BY "LastEdited" DESC LIMIT ' . $version_limit .', 200');
            foreach ($search as $row) {
                array_push($versionsToDelete, array('RecordID' => $row['RecordID'], 'Version' => $row['Version']));
            }
        }

        $draft_limit = Config::inst()->get('VersionTruncator', 'draft_limit');

        if (is_numeric($draft_limit)) {
            $search = DB::query('SELECT "RecordID", "Version" FROM "SiteTree_versions"
				 WHERE "RecordID" = ' . $ID  . ' AND "ClassName" = \'' . $className . '\'
				 AND "PublisherID" = 0
				 ORDER BY "LastEdited" DESC LIMIT ' . $draft_limit .', 200');
            foreach ($search as $row) {
                array_push($versionsToDelete, array('RecordID' => $row['RecordID'], 'Version' => $row['Version']));
            }
        }

        $delete_old_page_types = Config::inst()->get('VersionTruncator', 'delete_old_page_types');

        if ($delete_old_page_types) {
            $search = DB::query('SELECT "RecordID", "Version" FROM "SiteTree_versions"
				 WHERE "RecordID" = ' . $ID  . ' AND "ClassName" != \'' . $className . '\'');
            foreach ($search as $row) {
                array_push($versionsToDelete, array('RecordID' => $row['RecordID'], 'Version' => $row['Version']));
            }
        }

        /* If versions to delete, start deleting */
        if (count($versionsToDelete) > 0) {
            $affected_tables = array();
            foreach ($subClasses as $subclass) {
                $versionsTable = $subclass . '_versions';
                foreach ($versionsToDelete as $d) {
                    DB::query('DELETE FROM "' . $versionsTable . '"' .
                        ' WHERE "RecordID" = ' . $d['RecordID'] .
                        ' AND "Version" = ' . $d['Version']);
                    if (DB::affectedRows() == 1) {
                        array_push($affected_tables, $versionsTable);
                    }
                }
            }

            $this->vacuumTables($affected_tables);
        }
    }

    /*
     * Optimize the tables that are affected
     * @param Array
     * @return null
     */
    protected function vacuumTables($raw_tables)
    {
        $vacuum_tables = Config::inst()->get('VersionTruncator', 'vacuum_tables');

        $tables = array_unique($raw_tables);

        if ($vacuum_tables && count($tables) > 0) {
            global $databaseConfig;

            foreach ($tables as $table) {
                if (preg_match('/mysql/i', $databaseConfig['type'])) {
                    DB::query('OPTIMIZE table "' . $table . '"');
                } elseif (preg_match('/postgres/i', $databaseConfig['type'])) {
                    DB::query('VACUUM "' . $table . '"');
                }
            }
            /* Sqlite just optimizes the database, not each table */
            if (preg_match('/sqlite/i', $databaseConfig['type'])) {
                DB::query('VACUUM');
            }
        }
    }


    /*
     * Warnings for older configs
     */
    public static function set_version_limit($v)
    {
        Deprecation::notice('3.0', 'VersionTruncator::set_version_limit() is deprecated.');
        Config::inst()->update('VersionTruncator', 'version_limit', $v);
    }

    public static function set_draft_limit($v)
    {
        Deprecation::notice('3.0', 'VersionTruncator::set_draft_limit() is deprecated.');
        Config::inst()->update('VersionTruncator', 'draft_limit', $v);
    }

    public static function set_vacuum_tables($v)
    {
        Deprecation::notice('3.0', 'VersionTruncator::set_vacuum_tables() is deprecated.');
        Config::inst()->update('VersionTruncator', 'vacuum_tables', $v);
    }

    public static function set_delete_old_page_types($v)
    {
        Deprecation::notice('3.0', 'VersionTruncator::set_delete_old_page_types() is deprecated.');
        Config::inst()->update('VersionTruncator', 'delete_old_page_types', $v);
    }
}
