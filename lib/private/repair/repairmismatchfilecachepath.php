<?php
/**
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Repair;

use OC\Hooks\BasicEmitter;
use OCP\ILogger;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use OCP\Files\IMimeTypeLoader;
use OCP\IDBConnection;

/**
 * Repairs file cache entry which path do not match the parent-child relationship
 */
class RepairMismatchFileCachePath extends BasicEmitter implements \OC\RepairStep {

	const CHUNK_SIZE = 10000;

	/** @var IDBConnection */
	protected $connection;

	/** @var IMimeTypeLoader */
	protected $mimeLoader;

	/** @var int */
	protected $dirMimeTypeId;

	/** @var int */
	protected $dirMimePartId;

	/** @var int|null */
	protected $storageNumericId = null;

	/** @var bool */
	protected $countOnly = true;

	/**
	 * @param \OCP\IDBConnection $connection
	 * @param IMimeTypeLoader $mimeLoader
	 */
	public function __construct(IDBConnection $connection, IMimeTypeLoader $mimeLoader) {
		$this->connection = $connection;
		$this->mimeLoader = $mimeLoader;
	}

	public function getName() {
		if ($this->countOnly) {
			return 'Detect file cache entries with path that does not match parent-child relationships';
		} else {
			return 'Repair file cache entries with path that does not match parent-child relationships';
		}
	}

	/**
	 * Sets the numeric id of the storage to process or null to process all.
	 *
	 * @param int $storageNumericId numeric id of the storage
	 */
	public function setStorageNumericId($storageNumericId) {
		$this->storageNumericId = $storageNumericId;
	}

	/**
	 * Sets whether to actually repair or only count entries
	 *
	 * @param bool $countOnly count only
	 */
	public function setCountOnly($countOnly) {
		$this->countOnly = $countOnly;
	}

	/**
	 * Fixes the broken entry's path.
	 *
	 * @param int $fileId file id of the entry to fix
	 * @param string $wrongPath wrong path of the entry to fix
	 * @param int $correctStorageNumericId numeric idea of the correct storage
	 * @param string $correctPath value to which to set the path of the entry 
	 * @return bool true for success
	 */
	private function fixEntryPath($fileId, $wrongPath, $correctStorageNumericId, $correctPath) {
		// delete target if exists
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('filecache')
			->where($qb->expr()->eq('storage', $qb->createNamedParameter($correctStorageNumericId)));

		if ($correctPath === '' && $this->connection->getDatabasePlatform() instanceof OraclePlatform) {
			$qb->andWhere($qb->expr()->isNull('path'));
		} else {
			$qb->andWhere($qb->expr()->eq('path', $qb->createNamedParameter($correctPath)));
		}
		$entryExisted = $qb->execute() > 0;

		$qb = $this->connection->getQueryBuilder();
		$qb->update('filecache')
			->set('path', $qb->createNamedParameter($correctPath))
			->set('path_hash', $qb->createNamedParameter(md5($correctPath)))
			->set('storage', $qb->createNamedParameter($correctStorageNumericId))
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId)));
		$qb->execute();
	}

	private function addQueryConditionsParentIdWrongPath($qb) {
		// thanks, VicDeo!
		if ($this->connection->getDatabasePlatform() instanceof MySqlPlatform) {
			$concatFunction = $qb->createFunction("CONCAT(fcp.path, '/', fc.name)");
		} else {
			$concatFunction = $qb->createFunction("(fcp.`path` || '/' || fc.`name`)");
		}

		if ($this->connection->getDatabasePlatform() instanceof OraclePlatform) {
			$emptyPathExpr = $qb->expr()->isNotNull('fcp.path');
		} else {
			$emptyPathExpr = $qb->expr()->neq('fcp.path', $qb->expr()->literal(''));
		}

		$qb
			->from('filecache', 'fc')
			->from('filecache', 'fcp')
			->where($qb->expr()->eq('fc.parent', 'fcp.fileid'))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->neq(
						$qb->createFunction($concatFunction),
						'fc.path'
					),
					$qb->expr()->neq('fc.storage', 'fcp.storage')
				)
			)
			->andWhere($emptyPathExpr)
			// yes, this was observed in the wild...
			->andWhere($qb->expr()->neq('fc.fileid', 'fcp.fileid'));

		if ($this->storageNumericId !== null) {
			// use the target storage of the failed move when filtering
			$qb->andWhere(
				$qb->expr()->eq('fc.storage', $qb->createNamedParameter($this->storageNumericId))
			);
		}
	}

	private function addQueryConditionsNonExistingParentIdEntry($qb, $storageNumericId = null) {
		// Subquery for parent existence
		$qbe = $this->connection->getQueryBuilder();
		$qbe->select($qbe->expr()->literal('1'))
			->from('filecache', 'fce')
			->where($qbe->expr()->eq('fce.fileid', 'fc.parent'));

		// Find entries to repair
		// select fc.storage,fc.fileid,fc.parent as "wrongparent",fc.path,fc.etag
		// and not exists (select 1 from oc_filecache fc2 where fc2.fileid = fc.parent)
		$qb->select('storage', 'fileid', 'path', 'parent')
			// from oc_filecache fc
			->from('filecache', 'fc')
			// where fc.parent <> -1
			->where($qb->expr()->neq('fc.parent', $qb->createNamedParameter(-1)))
			// and not exists (select 1 from oc_filecache fc2 where fc2.fileid = fc.parent)
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->eq('fc.fileid', 'fc.parent'),
					$qb->createFunction('NOT EXISTS (' . $qbe->getSQL() . ')')
				)
			);

		if ($storageNumericId !== null) {
			// filter on destination storage of a failed move
			$qb->andWhere($qb->expr()->eq('fc.storage', $qb->createNamedParameter($storageNumericId)));
		}
	}

	private function countResultsToProcessParentIdWrongPath($storageNumericId = null) {
		$qb = $this->connection->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'));
		$this->addQueryConditionsParentIdWrongPath($qb, $storageNumericId);
		$results = $qb->execute();
		$count = $results->fetchColumn(0);
		$results->closeCursor();
		return $count;
	}

	private function countResultsToProcessNonExistingParentIdEntry($storageNumericId = null) {
		$qb = $this->connection->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'));
		$this->addQueryConditionsNonExistingParentIdEntry($qb, $storageNumericId);
		$results = $qb->execute();
		$count = $results->fetchColumn(0);
		$results->closeCursor();
		return $count;
	}


	/**
	 * Outputs a report about storages with wrong path that need repairing in the file cache
	 */
	private function reportAffectedStoragesParentIdWrongPath() {
		$qb = $this->connection->getQueryBuilder();
		$qb->selectDistinct('fc.storage');
		$this->addQueryConditionsParentIdWrongPath($qb);

		// TODO: max results + paginate ?
		// TODO: join with oc_storages / oc_mounts to deliver user id ?

		$results = $qb->execute();
		$rows = $results->fetchAll();
		$results->closeCursor();

		$storageIds = [];
		foreach ($rows as $row) {
			$storageIds[] = $row['storage'];
		}

		if (!empty($storageIds)) {
			$this->emit('\OC\Repair', 'warning', array(
				'The file cache contains entries with invalid path values for the following storage numeric ids: ' . implode(' ', $storageIds)
			));
			$this->emit('\OC\Repair', 'warning', array(
				'Please run `occ files:scan --all --repair` to repair ' .
				'all affected storages or run `occ files:scan userid --repair for ' .
				'each user with affected storages'
			));
		}
	}

	/**
	 * Outputs a report about storages with non existing parents that need repairing in the file cache
	 */
	private function reportAffectedStoragesNonExistingParentIdEntry() {
		$qb = $this->connection->getQueryBuilder();
		$qb->selectDistinct('fc.storage');
		$this->addQueryConditionsNonExistingParentIdEntry($qb);

		// TODO: max results + paginate ?
		// TODO: join with oc_storages / oc_mounts to deliver user id ?

		$results = $qb->execute();
		$rows = $results->fetchAll();
		$results->closeCursor();

		$storageIds = [];
		foreach ($rows as $row) {
			$storageIds[] = $row['storage'];
		}

		if (!empty($storageIds)) {
			$this->emit('\OC\Repair', 'warning', array(
				'The file cache contains entries where the parent id does not point to any existing entry for the following storage numeric ids: ' . implode(' ', $storageIds)
			));
			$this->emit('\OC\Repair', 'warning', array(
				'Please run `occ files:scan --all --repair` to repair all affected storages'
			));
		}
	}

	/**
	 * Repair all entries for which the parent entry exists but the path
	 * value doesn't match the parent's path.
	 *
	 * @param int|null $storageNumericId storage to fix or null for all
	 * @return int[] storage numeric ids that were targets to a move and needs further fixing
	 */
	private function fixEntriesWithCorrectParentIdButWrongPath($storageNumericId = null) {
		$totalResultsCount = 0;
		$affectedStorages = [$storageNumericId => true];

		// find all entries where the path entry doesn't match the path value that would
		// be expected when following the parent-child relationship, basically
		// concatenating the parent's "path" value with the name of the child
		$qb = $this->connection->getQueryBuilder();
		$qb->select('fc.storage', 'fc.fileid', 'fc.name')
			->selectAlias('fc.path', 'path')
			->selectAlias('fc.parent', 'wrongparentid')
			->selectAlias('fcp.storage', 'parentstorage')
			->selectAlias('fcp.path', 'parentpath');
		$this->addQueryConditionsParentIdWrongPath($qb, $storageNumericId);
		$qb->setMaxResults(self::CHUNK_SIZE);

		do {
			$results = $qb->execute();
			// since we're going to operate on fetched entry, better cache them
			// to avoid DB lock ups
			$rows = $results->fetchAll();
			$results->closeCursor();

			$this->connection->beginTransaction();
			$lastResultsCount = 0;
			foreach ($rows as $row) {
				$wrongPath = $row['path'];
				$correctPath = $row['parentpath'] . '/' . $row['name'];
				// make sure the target is on a different subtree
				if (substr($correctPath, 0, strlen($wrongPath)) === $wrongPath) {
					// the path based parent entry is referencing one of its own children,
					// fix the entry's parent id instead
					// note: fixEntryParent cannot fail to find the parent entry by path
					// here because the reason we reached this code is because we already
					// found it
					$this->fixEntryParent(
						$row['storage'],
						$row['fileid'],
						$row['path'],
						$row['wrongparentid'],
						true
					);
				} else {
					$this->fixEntryPath(
						$row['fileid'],
						$wrongPath,
						$row['parentstorage'],
						$correctPath
					);
					// we also need to fix the target storage
					$affectedStorages[$row['parentstorage']] = true;
				}
				$lastResultsCount++;
			}
			$this->connection->commit();

			$totalResultsCount += $lastResultsCount;

			// note: this is not pagination but repeating the query over and over again
			// until all possible entries were fixed
		} while ($lastResultsCount > 0);

		if ($totalResultsCount > 0) {
			$this->emit('\OC\Repair', 'info', array("Fixed $totalResultsCount file cache entries with wrong path"));
		}

		return array_keys($affectedStorages);
	}

	/**
	 * Gets the file id of the entry. If none exists, create it
	 * up to the root if needed.
	 *
	 * @param int $storageId storage id
	 * @param string $path path for which to create the parent entry
	 * @return int file id of the newly created parent
	 */
	private function getOrCreateEntry($storageId, $path, $reuseFileId = null) {
		if ($path === '.') {
			$path = '';
		}
		// find the correct parent
		$qb = $this->connection->getQueryBuilder();
		// select fileid as "correctparentid"
		$qb->select('fileid')
			// from oc_filecache
			->from('filecache')
			// where storage=$storage and path='$parentPath'
			->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId)));


		if ($path === '' && $this->connection->getDatabasePlatform() instanceof OraclePlatform) {
			$qb->andWhere($qb->expr()->isNull('path'));
		} else {
			$qb->andWhere($qb->expr()->eq('path', $qb->createNamedParameter($path)));
		}
		$results = $qb->execute();
		$rows = $results->fetchAll();
		$results->closeCursor();

		if (!empty($rows)) {
			return $rows[0]['fileid'];
		}

		if ($path !== '') {
			$parentId = $this->getOrCreateEntry($storageId, dirname($path));
		} else {
			// root entry missing, create it
			$parentId = -1;
		}

		$qb = $this->connection->getQueryBuilder();
		$values = [
			'storage' => $qb->createNamedParameter($storageId),
			'path' => $qb->createNamedParameter($path),
			'path_hash' => $qb->createNamedParameter(md5($path)),
			'name' => $qb->createNamedParameter(basename($path)),
			'parent' => $qb->createNamedParameter($parentId),
			'size' => $qb->createNamedParameter(-1),
			'etag' => $qb->createNamedParameter('zombie'),
			'mimetype' => $qb->createNamedParameter($this->dirMimeTypeId),
			'mimepart' => $qb->createNamedParameter($this->dirMimePartId),
		];

		if ($reuseFileId !== null) {
			// purpose of reusing the fileid of the parent is to salvage potential
			// metadata that might have previously been linked to this file id
			$values['fileid'] = $qb->createNamedParameter($reuseFileId);
		}
		$qb->insert('filecache')->values($values);
		$qb->execute();

		// If we reused the fileid then this is the id to return
		if($reuseFileId !== null) {
			// with Oracle, the trigger gets in the way and does not let us specify
			// a fileid value on insert
			if ($this->connection->getDatabasePlatform() instanceof OraclePlatform) {
				$lastFileId = $this->connection->lastInsertId('*PREFIX*filecache');
				if ($reuseFileId !== $lastFileId) {
					// use update to set it directly
					$qb = $this->connection->getQueryBuilder();
					$qb->update('filecache')
						->set('fileid', $qb->createNamedParameter($reuseFileId))
						->where($qb->expr()->eq('fileid', $qb->createNamedParameter($lastFileId)));
					$qb->execute();
				}
			}
			return $reuseFileId;
		} else {
			// Else we inserted a new row with auto generated id, use that
			return $this->connection->lastInsertId('*PREFIX*filecache');
		}
	}

	/**
	 * Fixes the broken entry's path.
	 *
	 * @param int $storageId storage id of the entry to fix
	 * @param int $fileId file id of the entry to fix
	 * @param string $path path from the entry to fix
	 * @param int $wrongParentId wrong parent id
	 * @param bool $parentIdExists true if the entry from the $wrongParentId exists (but is the wrong one),
	 * false if it doesn't
	 * @return bool true if the entry was fixed, false otherwise
	 */
	private function fixEntryParent($storageId, $fileId, $path, $wrongParentId, $parentIdExists = false) {
		if (!$parentIdExists) {
			// if the parent doesn't exist, let us reuse its id in case there is metadata to salvage
			$correctParentId = $this->getOrCreateEntry($storageId, dirname($path), $wrongParentId);
		} else {
			// parent exists and is the wrong one, so recreating would need a new fileid
			$correctParentId = $this->getOrCreateEntry($storageId, dirname($path));
		}

		$this->connection->beginTransaction();

		$qb = $this->connection->getQueryBuilder();
		$qb->update('filecache')
			->set('parent', $qb->createNamedParameter($correctParentId))
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId)));
		$qb->execute();

		$this->connection->commit();

		return true;
	}

	/**
	 * Repair entries where the parent id doesn't point to any existing entry
	 * by finding the actual parent entry matching the entry's path dirname.
	 * 
	 * @param int|null $storageNumericId storage to fix or null for all
	 * @return int number of results that were fixed
	 */
	private function fixEntriesWithNonExistingParentIdEntry($storageNumericId = null) {
		$qb = $this->connection->getQueryBuilder();
		$this->addQueryConditionsNonExistingParentIdEntry($qb, $storageNumericId);
		$qb->setMaxResults(self::CHUNK_SIZE);

		$totalResultsCount = 0;
		do {
			$results = $qb->execute();
			// since we're going to operate on fetched entry, better cache them
			// to avoid DB lock ups
			$rows = $results->fetchAll();
			$results->closeCursor();

			$lastResultsCount = 0;
			foreach ($rows as $row) {
				$this->fixEntryParent(
					$row['storage'],
					$row['fileid'],
					$row['path'],
					$row['parent'],
					// in general the parent doesn't exist except
					// for the one condition where parent=fileid
					$row['parent'] === $row['fileid']
				);
				$lastResultsCount++;
			}

			$totalResultsCount += $lastResultsCount;

			// note: this is not pagination but repeating the query over and over again
			// until all possible entries were fixed
		} while ($lastResultsCount > 0);

		if ($totalResultsCount > 0) {
			$this->emit('\OC\Repair', 'info', array("Fixed $totalResultsCount file cache entries with wrong parent"));
		}

		return $totalResultsCount;
	}

	/**
	 * Run the repair step
	 */
	public function run() {

		$this->dirMimeTypeId = $this->mimeLoader->getId('httpd/unix-directory');
		$this->dirMimePartId = $this->mimeLoader->getId('httpd');

		if ($this->countOnly) {
			$this->reportAffectedStoragesParentIdWrongPath();
			$this->reportAffectedStoragesNonExistingParentIdEntry();
		} else {
			$brokenPathEntries = $this->countResultsToProcessParentIdWrongPath($this->storageNumericId);
			$brokenParentIdEntries = $this->countResultsToProcessNonExistingParentIdEntry($this->storageNumericId);

			$totalFixed = 0;

			/*
			 * This repair itself might overwrite existing target parent entries and create
			 * orphans where the parent entry of the parent id doesn't exist but the path matches.
			 * This needs to be repaired by fixEntriesWithNonExistingParentIdEntry(), this is why
			 * we need to keep this specific order of repair.
			 */
			$affectedStorages = $this->fixEntriesWithCorrectParentIdButWrongPath($this->storageNumericId);

			if ($this->storageNumericId !== null) {
				foreach ($affectedStorages as $storageNumericId) {
					$this->fixEntriesWithNonExistingParentIdEntry($storageNumericId);
				}
			} else {
				// just fix all
				$this->fixEntriesWithNonExistingParentIdEntry();
			}
		}
	}
}
