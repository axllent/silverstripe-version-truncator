<?php

class TruncateVersionsTask extends BuildTask {
 
	protected $title = 'Truncate SiteTree versions';
 
	protected $description = "Truncate old SiteTree versions, only keeping the last xx versions per page (set in config)";
 
	protected $enabled = true;
 
	function run($request) {
		$this->truncateSiteTreeVersions();
	}
 
	function truncateSiteTreeVersions() {
		
		//$pages = SiteTree::get();
		$pages = Versioned::get_including_deleted('SiteTree')->sort('ID ASC');
		
		$totalDeleted = 0;
				
		foreach ($pages as $p) {
			$deletedCount = $p->truncateVersions();
			if ($deletedCount) {
				echo '['.$p->ID.'] '.$p->Title.': Removed '.$deletedCount.' versions<br>';
				$totalDeleted = $totalDeleted + $deletedCount;
			}
		}
		
		echo $totalDeleted ? "<br><b>Total: $totalDeleted versions removed</b>" : "No versions to remove";
	}
}

class RemoveDeletedSiteTreeTask extends BuildTask {
 
	protected $title = 'Remove deleted SiteTree objects';
 
	protected $description = "Completely remove deleted SiteTree objects from the database";
 
	protected $enabled = true;
 
	function run($request) {
		$this->obliterateDeleted();
	}
 
	function obliterateDeleted() {
		
		//Find deleted records (get ID only)
		$search = DB::query('SELECT DISTINCT "RecordID" FROM "SiteTree_versions"
			LEFT JOIN "SiteTree" ON  "SiteTree_versions".RecordID = "SiteTree".ID
			WHERE "SiteTree".ID IS NULL
			ORDER BY "RecordID" ASC');
		
		//Fetch as SiteTree objects
		$IDs = array();
		foreach ($search as $row) {
			$IDs[] = $row['RecordID'];
		}
		//$pages = Versioned::get_including_deleted('SiteTree')->byIDS($IDs);
		$pages = Versioned::get_including_deleted('SiteTree');
		$totalCount = 0;
		
		//Obliterate them
		foreach ($pages as $p) {
			$ID = $p->ID;
			//Just work on deleted pages. $pages->byIDS($IDs) didn't work for some reason.
			if (in_array($ID, $IDs)) {
				$className = $p->ClassName;
				$subClasses = ClassInfo::dataClassesFor($className);
	
				//Delete all versions
				echo '<b>'.$p->Title.'</b><br>';
				$affected_tables = array();
				foreach ($subClasses as $subclass) {
					$versionsTable = $subclass . '_versions';
					
					//Count versions
					$count = DB::query('SELECT COUNT(*) FROM "' . $versionsTable . '"' .
						' WHERE "RecordID" = ' . $ID)->value();
						
					//Goodbye
					if ($count) {
						$totalCount++;
						DB::query('DELETE FROM "' . $versionsTable . '"' . ' WHERE "RecordID" = ' . $ID);
						array_push($affected_tables, $versionsTable);
						echo "Deleted record $ID in $versionsTable ($count records)<br>";
					}
				}
				//Clean up
				$this->vacuumTables($affected_tables);
			}
		}
		if ($totalCount) {
			echo "<br><br><b>Total Records Deleted: $totalCount</b>";
		} else {
			echo "<b>No deleted pages found.</b>";
		}
	}
	
	/*
	 * Optimize the tables that are affected
	 * @param Array
	 * @return null
	 */
	protected function vacuumTables($raw_tables) {

		$vacuum_tables = Config::inst()->get('VersionTruncator', 'vacuum_tables');

		$tables = array_unique($raw_tables);

		if ($vacuum_tables && count($tables) > 0) {
			global $databaseConfig;

			foreach ($tables as $table) {
				if (preg_match('/mysql/i', $databaseConfig['type'])) {
					DB::query('OPTIMIZE table "' . $table . '"');
				}

				else if (preg_match('/postgres/i', $databaseConfig['type'])) {
					DB::query('VACUUM "' . $table . '"');
				}
			}
			/* Sqlite just optimizes the database, not each table */
			if (preg_match('/sqlite/i', $databaseConfig['type'])) {
				DB::query('VACUUM');
			}
		}
	}
}