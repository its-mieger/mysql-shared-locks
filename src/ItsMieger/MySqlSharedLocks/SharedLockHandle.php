<?php
	namespace ItsMieger\MySqlSharedLocks;


	use ItsMieger\MySqlSharedLocks\Exception\SharedLockReleaseException;
	use ItsMieger\MySqlSharedLocks\Exception\SharedLockRemainingTTLException;

	/**
	 * Access to an existing lock
	 * @package ItsMieger\MySqlSharedLocks
	 */
	class SharedLockHandle
	{

		protected $lockName = null;

		/**
		 * SharedLockHandle constructor.
		 * @param string|null $lockName
		 */
		public function __construct($lockName) {
			$this->lockName = $lockName;
		}


		/**
		 * Releases the lock
		 * @return $this
		 * @throws SharedLockReleaseException Thrown if the lock could not be released. It must be assumed that the lock did not exist anymore for this process
		 */
		public function release() {
			SharedLock::unlock($this->lockName);

			return $this;
		}

		/**
		 * Checks if the lock is still acquired for the process and the at least specified amount of TTL is remaining for the lock
		 * @param int $minRemainingTTL The minimum required remaining TTL
		 * @return $this
		 * @throws SharedLockRemainingTTLException Thrown if specified TTL is not remaining or if it could not be verified that the lock still exists for this process.
		 */
		public function assertTTL($minRemainingTTL = 1) {
			SharedLock::assertTTL($this->lockName, $minRemainingTTL);

			return $this;
		}

	}