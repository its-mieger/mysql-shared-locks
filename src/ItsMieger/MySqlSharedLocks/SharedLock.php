<?php
	namespace ItsMieger\MySqlSharedLocks;


	use ItsMieger\MySqlSharedLocks\Exception\SharedLockAcquireException;
	use ItsMieger\MySqlSharedLocks\Exception\SharedLockException;
	use ItsMieger\MySqlSharedLocks\Exception\SharedLockReleaseException;
	use ItsMieger\MySqlSharedLocks\Exception\SharedLockRemainingTTLException;
	use ItsMieger\MySqlSharedLocks\Exception\SharedLockTimeoutException;
	use ItsMieger\MySqlSharedLocks\Exception\TableRepairException;
	use PDO;

	/**
	 * Implements a static interface for shared locks based on MySQL
	 * @package ItsMieger\MySqlSharedLocks
	 */
	class SharedLock
	{
		const QUOTE_STYLE_DEFAULT = 0;
		const QUOTE_STYLE_ANSI = 1;

		protected static $connectionConfig = [];
		protected static $connection = null;
		protected static $locks_table = 'shared_locks';

		protected static $tableLocked = false;
		protected static $quoteStyle = self::QUOTE_STYLE_DEFAULT;

		/**
		 * Configures the database connection
		 * @param string $host The host
		 * @param string $dbName The database name
		 * @param string $username The username
		 * @param string $password The password
		 * @param string $charset
		 * @param int $port The database port
		 * @param string|null $additionalDsn Additional DSN options string
		 */
		public static function configureConnection($host, $dbName, $username, $password, $charset = 'utf8mb4', $port = null, $additionalDsn = null) {

			self::$connectionConfig = [
				'host'          => $host,
				'db'            => $dbName,
				'user'          => $username,
				'port'          => $port,
				'password'      => $password,
				'charset'       => $charset,
				'additionalDsn' => $additionalDsn
			];
			self::$connection = null;
		}

		/**
		 * Sets the PDO connection
		 * @param PDO $pdo The pdo instance
		 */
		public static function setConnection(PDO $pdo) {
			self::$connection = $pdo;
		}

		/**
		 * Sets the identifier quote style to use
		 * @param int The identifier $quoteStyle
		 */
		public static function setQuoteStyle($quoteStyle) {
			switch($quoteStyle) {
				case self::QUOTE_STYLE_DEFAULT:
				case self::QUOTE_STYLE_ANSI:
					self::$quoteStyle = $quoteStyle;
					break;
				default:
					throw new \InvalidArgumentException("Invalid quote style \"$quoteStyle\"");
			}
		}


		/**
		 * Gets the database connection
		 * @return PDO The database connection instance
		 */
		public static function getConnection() {

			if (!self::$connection) {
				$conf = self::$connectionConfig;

				$conn = new \PDO('mysql:host=' . $conf['host']
				                 . ';dbname=' . $conf['db']
				                 . ';charset=' . $conf['charset']
				                 . (!empty($conf['port']) ? ';port=' . $conf['port'] : '')
				                 . (!empty($conf['additionalDsn']) ? ';' . $conf['additionalDsn'] : ''), $conf['user'], $conf['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

				// disable "prepare emulation" (see: http://wiki.hashphp.org/PDO_Tutorial_for_MySQL_Developers)
				$conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

				self::$connection = $conn;
			}

			return self::$connection;
		}

		/**
		 * Returns the database table which holds the locks
		 * @return string The table name
		 */
		public static function getTable() {
			return self::$locks_table;
		}

		/**
		 * Acquires a named lock
		 * @param string $name The lock name
		 * @param float $timeout The timeout for lock acquiring in seconds
		 * @param int $ttl The maximum TTL for the lock which is established. If the TTL elapses, other processes might take over the lock
		 * @return SharedLockHandle A handle for easy releasing the established lock
		 * @throws SharedLockAcquireException Thrown if an unexpected error occurred
		 * @throws SharedLockTimeoutException Thrown if the operation did not succeed due to timeout elapsed
		 * @throws SharedLockException
		 */
		public static function lock($name, $timeout, $ttl) {

			try {
				$startTime = microtime(true);

				do {
					// try to get lock
					$acquired = self::getLock($name, $ttl);

					if (!$acquired) {

						// try to clean obsolete locks
						if (self::cleanLock($name)) {

							// s.th. was cleaned => continue without waiting
							continue;
						}

						// break if timeout exceeded
						if (microtime(true) >= $startTime + $timeout)
							break;


						// sleep until lock release (timeout when lock expires or when timeout exceeded)
						$remainingTimeout = ceil($timeout - (microtime(true) - $startTime));
						$remainingTTL     = self::getExistingLockTTL($name);

						self::sleepUntilRelease($name, min($remainingTimeout, $remainingTTL));
					}

				} while (!$acquired);


				// throw exception if not acquired
				if (!$acquired)
					throw new SharedLockTimeoutException($name, $timeout);
			}
			catch(SharedLockTimeoutException $ex) {
				throw $ex;
			}
			catch (TableRepairException $ex) {
				throw new SharedLockAcquireException($name, $ex->getMessage(), 0, $ex);
			}
			catch(\Exception $ex) {
				throw new SharedLockAcquireException($name, '', 0, $ex);
			}
			
			return new SharedLockHandle($name);
		}


		/**
		 * Releases the specified lock
		 * @param string $name The lock name
		 * @throws SharedLockReleaseException Thrown if the lock could not be released. It must be assumed that the lock did not exist anymore for this process
		 */
		public static function unlock($name) {

			try {

				// deregister lock
				$released = self::deregisterLock($name);

				// release lock
				/** @noinspection PhpStatementHasEmptyBodyInspection */
				while (self::releaseLock($name)) {
				}

				// throw exception if could not be released
				if (!$released)
					throw new SharedLockReleaseException($name);
			}
			catch (SharedLockReleaseException $ex) {
				throw $ex;
			}
			catch (TableRepairException $ex) {
				throw new SharedLockReleaseException($name, $ex->getMessage(), 0, $ex);
			}
			catch (\Exception $ex) {
				throw new SharedLockReleaseException($name, '', 0, $ex);
			}
		}

		/**
		 * Checks if the specified lock is still acquired for the process and the at least specified amount of TTL is remaining for the lock
		 * @param string $name The lock name
		 * @param int $minRemainingTTL The minimum required remaining TTL
		 * @throws SharedLockRemainingTTLException Thrown if specified TTL is not remaining or if it could not be verified that the lock still exists for this process.
		 * @throws SharedLockException
		 */
		public static function assertTTL($name, $minRemainingTTL = 1) {
			try {
				if (!self::hasRemainingTTL($name, $minRemainingTTL))
					throw new SharedLockRemainingTTLException($name, $minRemainingTTL);
			}
			catch (SharedLockRemainingTTLException $ex) {
				throw $ex;
			}
			catch (TableRepairException $ex) {
				throw new SharedLockException($name, $ex->getMessage(), 0, $ex);
			}
			catch (\Exception $ex) {
				throw new SharedLockRemainingTTLException($name, $minRemainingTTL, '', 0, $ex);
			}
		}


		/**
		 * Tries to get the lock
		 * @param string $name The lock name
		 * @param int $ttl The TTL for the lock in seconds
		 * @return bool True if got lock. Else false.
		 * @throws \Exception
		 */
		protected static function getLock($name, $ttl) {

			// try to register lock
			if (!self::registerLock($name, $ttl))
				return false;


			// we are the lock holder, so let's also get the lock
			$tries = 0;
			try {
				while (!self::awaitLock($name, 0)) {    /* we don't wait, since no other process is legitimated to have the lock */
					// kill any other session holding the lock, because we are the legitimate lock holder
					$sessionId = self::getLockingSessionId($name);

					if ($sessionId) {
						try {
							self::killSession($sessionId);
						}
						catch (\PDOException $ex) {
							// ignore exception since session might have terminated meanwhile
						}
					}
					else {
						// wait for session to terminate
						usleep(200000); // 200ms
					}

					if (++$tries > 20)
						throw new SharedLockAcquireException($name, 'Could not acquire lock. Other session holds it and does not terminate.');
				}

				// mark the lock as acquired (this mark is used on cleaning to detect died processes faster)
				if (!self::markLockAcquired($name)) {
					throw new SharedLockAcquireException($name, 'Could not acquire lock. Could not mark database lock as acquired.');
				}
			}
			catch (\Exception $ex) {
				// s.th. went wrong => we do not have the lock, so deregister it

				try {
					self::deregisterLock($name);
				}
				catch (\Exception $ex) {
				}


				throw $ex;
			}

			return true;
		}


		/**
		 * Register the lock ownership
		 * @param string $name The lock name
		 * @param int $ttl The TTL for the lock
		 * @return bool True on success. False on error.
		 * @throws TableRepairException
		 */
		protected static function registerLock($name, $ttl) {
			try {
				// register us as lock holder (this will fail with duplicate key exception if already in use)
				self::sqlExecute('INSERT INTO ' . self::quoteIdentifier(self::$locks_table). ' (' . implode(', ', static::quoteIdentifier(['name', 'created', 'ttl', 'connection_id', 'lock_acquired'])) . ') VALUES (:name, unix_timestamp(), :ttl, connection_id(), false)', [':name' => $name, ':ttl' => $ttl]);

				return true;
			}
			catch (\PDOException $ex) {

				// duplicate key [23000] exception is expected here, so simply return false if thrown
				if ($ex->getCode() == 23000)
					return false;

				throw $ex;
			}
		}

		/**
		 * Marks the database lock as acquired. This mark is used on cleaning to detect died processes faster
		 * @param string $name The lock name
		 * @return bool True if marked. Else false
		 * @throws TableRepairException
		 */
		protected static function markLockAcquired($name) {
			$stmt = self::sqlExecute('UPDATE ' . self::quoteIdentifier(self::$locks_table) . ' SET ' . static::quoteIdentifier('lock_acquired') . ' = true WHERE ' . static::quoteIdentifier('name') .' = :name AND ' . static::quoteIdentifier('connection_id') . ' = connection_id()', [':name' => $name]);

			return $stmt->rowCount() == 1;
		}

		/**
		 * Deregisters the specified lock
		 * @param string $name The lock name
		 * @return boolean True if lock was released. False if there was nothing to release
		 * @throws TableRepairException
		 */
		protected static function deregisterLock($name) {
			$stmt = self::sqlExecute('DELETE FROM ' . self::quoteIdentifier(self::$locks_table) . ' WHERE ' . static::quoteIdentifier('name') . ' = :name AND ' . static::quoteIdentifier('connection_id') . ' = connection_id()', [':name' => $name]);

			return $stmt->rowCount() == 1;
		}

		/**
		 * Checks if the specified lock has at least the specified time to live before it might be killed
		 * @param string $name The lock name
		 * @param int $minTTL The minimum remaining TTL
		 * @return bool True if marked. Else false
		 * @throws TableRepairException
		 */
		protected static function hasRemainingTTL($name, $minTTL) {
			$ret = self::sqlFetchValue('SELECT 1 FROM ' . self::quoteIdentifier(self::$locks_table) . ' WHERE ' . static::quoteIdentifier('name') . ' = :name AND ' . static::quoteIdentifier('connection_id') . ' = connection_id() AND ' . static::quoteIdentifier('created') . ' + ' . static::quoteIdentifier('ttl') . ' > unix_timestamp() + :assertTTL', [':name' => $name, ':assertTTL' => $minTTL]);

			return $ret == 1;
		}

		/**
		 * Removes obsolete locks
		 * @param string $name The lock name
		 * @return boolean True if there was an obsolete lock removed. Else false.
		 * @throws TableRepairException
		 */
		protected static function cleanLock($name) {
			$stmt = self::sqlExecute('DELETE FROM ' . self::quoteIdentifier(self::$locks_table) . ' WHERE name = :name AND (' . static::quoteIdentifier('created') . ' + ' . static::quoteIdentifier('ttl') . ' < unix_timestamp() OR (' . static::quoteIdentifier('lock_acquired') . ' AND IFNULL(IS_USED_LOCK(:name2),0) != ' . static::quoteIdentifier('connection_id') . '))', [':name' => $name, ':name2' => $name]);

			return $stmt->rowCount() == 1;
		}

		/**
		 * Gets the remaining TTL for the currently existing lock
		 * @param string $name The lock name
		 * @return boolean The remaining TTL for the currently existing lock in seconds. If no lock exists, 0 is returned
		 * @throws TableRepairException
		 */
		protected static function getExistingLockTTL($name) {
			return self::sqlFetchValue('SELECT ' . static::quoteIdentifier('created') . ' + ' . static::quoteIdentifier('ttl') . ' - unix_timestamp() FROM ' . self::quoteIdentifier(self::$locks_table) . ' WHERE ' . static::quoteIdentifier('name') . ' = :name', [':name' => $name]) ?: 0;
		}

		/**
		 * Wait until the lock is free
		 * @param string $name The lock name
		 * @param int $timeout The look timeout
		 * @return bool True if we got the lock. Else false.
		 * @throws TableRepairException
		 */
		protected static function awaitLock($name, $timeout) {
			$result = self::sqlFetchValue('SELECT GET_LOCK(:name, :timeout)', [':name' => $name, ':timeout' => $timeout]);

			return $result == 1;
		}

		/**
		 * Releases the specified lock
		 * @param string $name The lock name
		 * @return bool True on success. Else false.
		 * @throws TableRepairException
		 */
		protected static function releaseLock($name) {
			$result = self::sqlFetchValue('SELECT RELEASE_LOCK(:name)', [':name' => $name]);

			return $result == 1;
		}

		/**
		 * Lets the process sleep until specified lock is released or timeout is elapsed
		 * @param string $lockName The lock name
		 * @param int $timeout The timeout
		 * @throws TableRepairException
		 */
		protected static function sleepUntilRelease($lockName, $timeout) {

			// try to get lock
			$gotLock = self::awaitLock($lockName, $timeout);

			// release lock if we got it (we only wanted to wait until it was release)
			if ($gotLock)
				self::releaseLock($lockName);
		}


		/**
		 * Get the id of the session holding the lock
		 * @param string $name The lock name
		 * @return bool True if we got the lock. Else false.
		 * @throws TableRepairException
		 */
		protected static function getLockingSessionId($name) {
			$result = self::sqlFetchValue('SELECT IS_USED_LOCK(:name)', [':name' => $name]);

			return $result == 1;
		}

		/**
		 * Kills the session with the specified id
		 * @param int $sessionId The session id
		 * @return \PDOStatement
		 * @throws TableRepairException
		 */
		protected static function killSession($sessionId) {
			return self::sqlExecute('KILL :session', [':session' => $sessionId]);
		}


		/**
		 * Fetches a single value from the database
		 * @param string $sql The SQL query
		 * @param array $parameters The parameters
		 * @return mixed The value
		 * @throws TableRepairException
		 */
		protected static function sqlFetchValue($sql, $parameters = []) {

			$stmt = self::sqlExecute($sql, $parameters);

			return $stmt->fetchColumn();
		}

		/**
		 * Executes the specified statement
		 * @param string $sql The SQL query
		 * @param array $parameters The parameters
		 * @return \PDOStatement The executed statement
		 * @throws TableRepairException
		 * @throws \PDOException
		 */
		protected static function sqlExecute($sql, $parameters = []) {
			// prepare statement
			$stmt = self::getConnection()->prepare($sql);
			/** @noinspection PhpMethodParametersCountMismatchInspection */
			$stmt->setFetchMode(PDO::FETCH_COLUMN, 0);

			$repairTried = false;
			do {
				$retry = false;

				try {
					$stmt->execute($parameters);
				}
				catch (\PDOException $ex) {

					// maybe the table is corrupted and we can repair it ourselves
					if (
						!$repairTried && (
							// error detection according to https://dev.mysql.com/doc/refman/8.0/en/corrupted-myisam-tables.html
							(!empty($ex->errorInfo[1]) && $ex->errorInfo[1] == 1032) ||
							(!empty($ex->errorInfo[2]) && strstr($ex->errorInfo[2], 'Incorrect key file for table') !== false)
						)
					) {
						// try to repair table and execute again
						static::tryRepairTable();
						$repairTried = true;

						// try again
						$retry = true;
					}
					else {
						throw $ex;
					}
				}
			} while ($retry);

			return $stmt;
		}

		/**
		 * Executes the specified statement using ASSOC fetch mode
		 * @param string $sql The SQL query
		 * @param array $parameters The parameters
		 * @return \PDOStatement The executed statement
		 */
		protected static function sqlExecuteFetchAssoc($sql, $parameters = []) {

			// prepare statement
			$stmt = self::getConnection()->prepare($sql);
			/** @noinspection PhpMethodParametersCountMismatchInspection */
			$stmt->setFetchMode(PDO::FETCH_ASSOC);

			$stmt->execute($parameters);

			return $stmt;
		}

		/**
		 * Quotes the given SQL identifier
		 * @param string|string[] $name The identifier(s)
		 * @return string|string{] The quoted identifier name
		 */
		protected static function quoteIdentifier($name) {

			if (is_array($name))
				return array_map([static::class, __METHOD__], $name);

			switch(static::$quoteStyle) {
				case self::QUOTE_STYLE_DEFAULT:
					return "`$name`";
				case self::QUOTE_STYLE_ANSI:
					return "\"$name\"";
				default:
					throw new \RuntimeException('Invalid quote style "' . static::$quoteStyle . '" set');
			}
		}


		/**
		 * Tries to repair the locks table if corrupted
		 * @throws TableRepairException
		 */
		public static function tryRepairTable() {

			$stmt = static::sqlExecuteFetchAssoc('REPAIR TABLE ' . static::quoteIdentifier(self::$locks_table));

			$result = $stmt->fetchAll();

			$messages = [];
			$status = false;

			foreach($result as $row) {
				switch($row['Msg_type']) {
					case 'status':
						$status = $row['Msg_text'];
						break;
					default:
						$messages[] = $row['Msg_type'] . ': ' . $row['Msg_text'];
				}
			}

			if (!$status || strtoupper($status) !== 'OK')
				throw new TableRepairException('Table ' . self::$locks_table . " seams to be corrupted. Repair failed with status \"$status\". (" . implode(', ', $messages) . ')');

		}

	}