<?php
/**
 * Version Truncator for SilverStripe
 * ==================================
 *
 * A SilerStripe extension to automatically delete old published & draft
 * versions from all classes extending the SiteTree (like Page) upon save.
 *
 * If set (eg: VersionTruncator::set_limit(20); in your _config.php)
 * the class will automatically delete all but the latest (edited) 20 versions
 * of the page once saved.
 *
 * License: MIT-style license http://opensource.org/licenses/MIT
 * Authors: Techno Joy development team (www.technojoy.co.nz)
 */

class VersionTruncator extends SiteTreeExtension {

	protected static $version_limit = 10;
		static function set_version_limit($v) {self::$version_limit = $v;}

	protected static $draft_limit = 5;
		static function set_draft_limit($v) {self::$draft_limit = $v;}

	protected static $vacuum_tables = false;
		static function set_vacuum_tables($v) {self::$vacuum_tables = $v;}

	protected static $delete_old_page_types = false;
		static function set_delete_old_page_types($v) {self::$delete_old_page_types = $v;}

	/*
	 * Automatically invoked with any save() on a SiteTree object
	 * @param null
	 * @return null
	 */
	public function onAfterWrite() {

		parent::onAfterWrite();

		$ID = $this->owner->ID;
		$className = $this->owner->ClassName;
		$subClasses = ClassInfo::dataClassesFor($className);
		$versionsToDelete = array();

		$version_limit = (self::$version_limit && is_numeric(self::$version_limit)) ? self::$version_limit : false;

		if ($version_limit) {
			$search = DB::query('SELECT "RecordID", "Version" FROM "SiteTree_versions"
				 WHERE "RecordID" = ' . $ID  . ' AND "ClassName" = \'' . $className . '\'
				 AND "PublisherID" > 0
				 ORDER BY "LastEdited" DESC LIMIT ' . $version_limit .', 200');
			foreach ($search as $row)
				array_push($versionsToDelete, array('RecordID' => $row['RecordID'], 'Version' => $row['Version']));
		}

		$draft_limit = (self::$draft_limit && is_numeric(self::$draft_limit)) ? self::$draft_limit : $version_limit;

		if ($draft_limit) {
			$search = DB::query('SELECT "RecordID", "Version" FROM "SiteTree_versions"
				 WHERE "RecordID" = ' . $ID  . ' AND "ClassName" = \'' . $className . '\'
				 AND "PublisherID" = 0
				 ORDER BY "LastEdited" DESC LIMIT ' . $draft_limit .', 200');
			foreach ($search as $row)
				array_push($versionsToDelete, array('RecordID' => $row['RecordID'], 'Version' => $row['Version']));
		}

		if (self::$delete_old_page_types) {
			$search = DB::query('SELECT "RecordID", "Version" FROM "SiteTree_versions"
				 WHERE "RecordID" = ' . $ID  . ' AND "ClassName" != \'' . $className . '\'');
			foreach ($search as $row)
				array_push($versionsToDelete, array('RecordID' => $row['RecordID'], 'Version' => $row['Version']));
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
					if (DB::affectedRows() == 1)
						array_push($affected_tables, $versionsTable);
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
	protected function vacuumTables($raw_tables) {

		$tables = array_unique($raw_tables);

		if (self::$vacuum_tables && count($tables) > 0) {
			global $databaseConfig;

			foreach ($tables as $table) {
				if (preg_match('/mysql/i', $databaseConfig['type']))
					DB::query('OPTIMIZE table "' . $table . '"');

				else if (preg_match('/postgres/i', $databaseConfig['type']))
					DB::query('VACUUM "' . $table . '"');
			}
			/* Sqlite just optimizes the database, not each table */
			if (preg_match('/sqlite/i', $databaseConfig['type']))
				DB::query('VACUUM');
		}
	}

}
