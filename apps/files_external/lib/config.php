<?php
/**
 * @author Andreas Fischer <bantu@owncloud.com>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Björn Schießle <schiessle@owncloud.com>
 * @author Frank Karlitschek <frank@owncloud.org>
 * @author Jesús Macias <jmacias@solidgear.es>
 * @author Joas Schilling <nickvergessen@owncloud.com>
 * @author Lukas Reschke <lukas@owncloud.com>
 * @author Michael Gapczynski <GapczynskiM@gmail.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Philipp Kapfer <philipp.kapfer@gmx.at>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
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

use phpseclib\Crypt\AES;
use \OCA\Files_External\Appinfo\Application;
use \OCA\Files_External\Lib\Backend\LegacyBackend;
use \OCA\Files_External\Lib\StorageConfig;
use \OCA\Files_External\Lib\Backend\Backend;
use \OCP\Files\StorageNotAvailableException;

/**
 * Class to configure mount.json globally and for users
 */
class OC_Mount_Config {
	// TODO: make this class non-static and give it a proper namespace

	const MOUNT_TYPE_GLOBAL = 'global';
	const MOUNT_TYPE_GROUP = 'group';
	const MOUNT_TYPE_USER = 'user';
	const MOUNT_TYPE_PERSONAL = 'personal';

	// whether to skip backend test (for unit tests, as this static class is not mockable)
	public static $skipTest = false;

	/** @var Application */
	public static $app;

	/**
	 * @param string $class
	 * @param array $definition
	 * @return bool
	 * @deprecated 8.2.0 use \OCA\Files_External\Service\BackendService::registerBackend()
	 */
	public static function registerBackend($class, $definition) {
		$backendService = self::$app->getContainer()->query('OCA\Files_External\Service\BackendService');
		$auth = self::$app->getContainer()->query('OCA\Files_External\Lib\Auth\Builtin');

		$backendService->registerBackend(new LegacyBackend($class, $definition, $auth));

		return true;
	}

	/**
	 * Returns the mount points for the given user.
	 * The mount point is relative to the data directory.
	 *
	 * @param string $uid user
	 * @return array of mount point string as key, mountpoint config as value
	 *
	 * @deprecated 8.2.0 use UserGlobalStoragesService::getStorages() and UserStoragesService::getStorages()
	 */
	public static function getAbsoluteMountPoints($uid) {
		$mountPoints = array();

		$userGlobalStoragesService = self::$app->getContainer()->query('OCA\Files_External\Service\UserGlobalStoragesService');
		$userStoragesService = self::$app->getContainer()->query('OCA\Files_External\Service\UserStoragesService');
		$user = self::$app->getContainer()->query('OCP\IUserManager')->get($uid);

		$userGlobalStoragesService->setUser($user);
		$userStoragesService->setUser($user);

		foreach ($userGlobalStoragesService->getStorages() as $storage) {
			/** @var \OCA\Files_external\Lib\StorageConfig $storage */
			$mountPoint = '/'.$uid.'/files'.$storage->getMountPoint();
			$mountEntry = self::prepareMountPointEntry($storage, false);
			foreach ($mountEntry['options'] as &$option) {
				$option = self::setUserVars($uid, $option);
			}
			$mountPoints[$mountPoint] = $mountEntry;
		}

		foreach ($userStoragesService->getStorages() as $storage) {
			$mountPoint = '/'.$uid.'/files'.$storage->getMountPoint();
			$mountEntry = self::prepareMountPointEntry($storage, true);
			foreach ($mountEntry['options'] as &$option) {
				$option = self::setUserVars($uid, $option);
			}
			$mountPoints[$mountPoint] = $mountEntry;
		}

		$userGlobalStoragesService->resetUser();
		$userStoragesService->resetUser();

		return $mountPoints;
	}

	/**
	 * Get the system mount points
	 *
	 * @return array
	 *
	 * @deprecated 8.2.0 use GlobalStoragesService::getStorages()
	 */
	public static function getSystemMountPoints() {
		$mountPoints = [];
		$service = self::$app->getContainer()->query('OCA\Files_External\Service\GlobalStoragesService');

		foreach ($service->getStorages() as $storage) {
			$mountPoints[] = self::prepareMountPointEntry($storage, false);
		}

		return $mountPoints;
	}

	/**
	 * Get the personal mount points of the current user
	 *
	 * @return array
	 *
	 * @deprecated 8.2.0 use UserStoragesService::getStorages()
	 */
	public static function getPersonalMountPoints() {
		$mountPoints = [];
		$service = self::$app->getContainer()->query('OCA\Files_External\Service\UserStoragesService');

		foreach ($service->getStorages() as $storage) {
			$mountPoints[] = self::prepareMountPointEntry($storage, true);
		}

		return $mountPoints;
	}

	/**
	 * Convert a StorageConfig to the legacy mountPoints array format
	 * There's a lot of extra information in here, to satisfy all of the legacy functions
	 *
	 * @param StorageConfig $storage
	 * @param bool $isPersonal
	 * @return array
	 */
	private static function prepareMountPointEntry(StorageConfig $storage, $isPersonal) {
		$mountEntry = [];

		$mountEntry['mountpoint'] = substr($storage->getMountPoint(), 1); // remove leading slash
		$mountEntry['class'] = $storage->getBackend()->getIdentifier();
		$mountEntry['backend'] = $storage->getBackend()->getText();
		$mountEntry['authMechanism'] = $storage->getAuthMechanism()->getIdentifier();
		$mountEntry['personal'] = $isPersonal;
		$mountEntry['options'] = self::decryptPasswords($storage->getBackendOptions());
		$mountEntry['mountOptions'] = $storage->getMountOptions();
		$mountEntry['priority'] = $storage->getPriority();
		$mountEntry['applicable'] = [
			'groups' => $storage->getApplicableGroups(),
			'users' => $storage->getApplicableUsers(),
		];
		// if mountpoint is applicable to all users the old API expects ['all']
		if (empty($mountEntry['applicable']['groups']) && empty($mountEntry['applicable']['users'])) {
			$mountEntry['applicable']['users'] = ['all'];
		}

		$mountEntry['id'] = $storage->getId();

		return $mountEntry;
	}

	/**
	 * fill in the correct values for $user
	 *
	 * @param string $user user value
	 * @param string|array $input
	 * @return string
	 */
	public static function setUserVars($user, $input) {
		if (is_array($input)) {
			foreach ($input as $key => $value) {
				if (is_string($value)) {
					$input[$key] = str_replace('$user', $user, $value);
				}
			}
		} else {
			if (is_string($input)) {
				$input = str_replace('$user', $user, $input);
			}
		}
		return $input;
	}

	/**
	 * Test connecting using the given backend configuration
	 *
	 * @param string $class backend class name
	 * @param array $options backend configuration options
	 * @param boolean $isPersonal
	 * @return int see self::STATUS_*
	 * @throws Exception
	 */
	public static function getBackendStatus($class, $options, $isPersonal, $testOnly = true) {
		if (self::$skipTest) {
			return StorageNotAvailableException::STATUS_SUCCESS;
		}
		foreach ($options as $key => $option) {
			$options[$key] = self::setUserVars(OCP\User::getUser(), $option);
		}
		if (class_exists($class)) {
			try {
				/** @var \OC\Files\Storage\Common $storage */
				$storage = new $class($options);

				try {
					$result = $storage->test($isPersonal, $testOnly);
					$storage->setAvailability($result);
					if ($result) {
						return StorageNotAvailableException::STATUS_SUCCESS;
					}
				} catch (\Exception $e) {
					$storage->setAvailability(false);
					throw $e;
				}
			} catch (Exception $exception) {
				\OCP\Util::logException('files_external', $exception);
				throw $exception;
			}
		}
		return StorageNotAvailableException::STATUS_ERROR;
	}

	/**
	 * Read the mount points in the config file into an array
	 *
	 * @param string|null $user If not null, personal for $user, otherwise system
	 * @return array
	 */
	public static function readData($user = null) {
		if (isset($user)) {
			$jsonFile = \OC::$server->getUserManager()->get($user)->getHome() . '/mount.json';
		} else {
			$config = \OC::$server->getConfig();
			$datadir = $config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data/');
			$jsonFile = $config->getSystemValue('mount_file', $datadir . '/mount.json');
		}
		if (is_file($jsonFile)) {
			$mountPoints = json_decode(file_get_contents($jsonFile), true);
			if (is_array($mountPoints)) {
				return $mountPoints;
			}
		}
		return array();
	}

	/**
	 * Get backend dependency message
	 * TODO: move into AppFramework along with templates
	 *
	 * @param Backend[] $backends
	 * @return string
	 */
	public static function dependencyMessage($backends) {
		$l = \OC::$server->getL10N('files_external');
		$message = '';
		$dependencyGroups = [];

		foreach ($backends as $backend) {
			foreach ($backend->checkDependencies() as $dependency) {
				if ($message = $dependency->getMessage()) {
					$message .= '<br />' . $l->t('<b>Note:</b> ') . $message;
				} else {
					$dependencyGroups[$dependency->getDependency()][] = $backend;
				}
			}
		}

		foreach ($dependencyGroups as $module => $dependants) {
			$backends = implode(', ', array_map(function($backend) {
				return '<i>' . $backend->getText() . '</i>';
			}, $dependants));
			$message .= '<br />' . OC_Mount_Config::getSingleDependencyMessage($l, $module, $backends);
		}

		return $message;
	}

	/**
	 * Returns a dependency missing message
	 *
	 * @param \OCP\IL10N $l
	 * @param string $module
	 * @param string $backend
	 * @return string
	 */
	private static function getSingleDependencyMessage(\OCP\IL10N $l, $module, $backend) {
		switch (strtolower($module)) {
			case 'curl':
				return (string)$l->t('<b>Note:</b> The cURL support in PHP is not enabled or installed. Mounting of %s is not possible. Please ask your system administrator to install it.', $backend);
			case 'ftp':
				return (string)$l->t('<b>Note:</b> The FTP support in PHP is not enabled or installed. Mounting of %s is not possible. Please ask your system administrator to install it.', $backend);
			default:
				return (string)$l->t('<b>Note:</b> "%s" is not installed. Mounting of %s is not possible. Please ask your system administrator to install it.', array($module, $backend));
		}
	}

	/**
	 * Encrypt passwords in the given config options
	 *
	 * @param array $options mount options
	 * @return array updated options
	 */
	public static function encryptPasswords($options) {
		if (isset($options['password'])) {
			$options['password_encrypted'] = self::encryptPassword($options['password']);
			// do not unset the password, we want to keep the keys order
			// on load... because that's how the UI currently works
			$options['password'] = '';
		}
		return $options;
	}

	/**
	 * Decrypt passwords in the given config options
	 *
	 * @param array $options mount options
	 * @return array updated options
	 */
	public static function decryptPasswords($options) {
		// note: legacy options might still have the unencrypted password in the "password" field
		if (isset($options['password_encrypted'])) {
			$options['password'] = self::decryptPassword($options['password_encrypted']);
			unset($options['password_encrypted']);
		}
		return $options;
	}

	/**
	 * Encrypt a single password
	 *
	 * @param string $password plain text password
	 * @return string encrypted password
	 */
	private static function encryptPassword($password) {
		$cipher = self::getCipher();
		$iv = \OCP\Util::generateRandomBytes(16);
		$cipher->setIV($iv);
		return base64_encode($iv . $cipher->encrypt($password));
	}

	/**
	 * Decrypts a single password
	 *
	 * @param string $encryptedPassword encrypted password
	 * @return string plain text password
	 */
	private static function decryptPassword($encryptedPassword) {
		$cipher = self::getCipher();
		$binaryPassword = base64_decode($encryptedPassword);
		$iv = substr($binaryPassword, 0, 16);
		$cipher->setIV($iv);
		$binaryPassword = substr($binaryPassword, 16);
		return $cipher->decrypt($binaryPassword);
	}

	/**
	 * Returns the encryption cipher
	 *
	 * @return AES
	 */
	private static function getCipher() {
		$cipher = new AES(AES::MODE_CBC);
		$cipher->setKey(\OC::$server->getConfig()->getSystemValue('passwordsalt', null));
		return $cipher;
	}

	/**
	 * Computes a hash based on the given configuration.
	 * This is mostly used to find out whether configurations
	 * are the same.
	 *
	 * @param array $config
	 * @return string
	 */
	public static function makeConfigHash($config) {
		$data = json_encode(
			array(
				'c' => $config['backend'],
				'a' => $config['authMechanism'],
				'm' => $config['mountpoint'],
				'o' => $config['options'],
				'p' => isset($config['priority']) ? $config['priority'] : -1,
				'mo' => isset($config['mountOptions']) ? $config['mountOptions'] : [],
			)
		);
		return hash('md5', $data);
	}
}
