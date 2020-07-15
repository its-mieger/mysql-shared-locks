<?php
	/**
	 * Created by PhpStorm.
	 * User: chris
	 * Date: 03.08.17
	 * Time: 13:06
	 */

	namespace SharedLockTest;


	use ItsMieger\MySqlSharedLocks\Exception\SharedLockReleaseException;
	use ItsMieger\MySqlSharedLocks\Exception\SharedLockRemainingTTLException;
	use ItsMieger\MySqlSharedLocks\Exception\SharedLockTimeoutException;
	use ItsMieger\MySqlSharedLocks\SharedLock;

	class SharedLockTest extends AbstractSharedLockTest
	{

		/**
		 * Test if lock acquire is working
		 */
		public function testAcquire() {

			$lockName = uniqid();

			// simply try to lock
			SharedLock::lock($lockName, 5, 10);
		}

		/**
		 * Test if lock is released if explicitly done
		 */
		public function testLockExplicitRelease() {
			$lockName = uniqid();

			$this->fork(
				function ($sh) use ($lockName) {
					// parent

					// wait for lock to be acquired
					$this->assertNextMessage('acquired', $sh);

					$this->assertDurationLessThan(5, function () use ($lockName) {
						try {
							SharedLock::lock($lockName, 5, 10);
						}
						catch (SharedLockTimeoutException $ex) {
							$this->fail('Lock was not acquired but should be released by other process within timeout');
						}
					});

				},
				function ($sh) use ($lockName) {
					//child

					SharedLock::lock($lockName, 0, 10);

					$this->sendMessage('acquired', $sh);

					sleep(3);

					// explicit release
					SharedLock::unlock($lockName);

					sleep(2);
				}
			);
		}

		/**
		 * Test if locks are cleaned immediately if they had the database lock and do not have it anymore
		 */
		public function testLockCleanDiedProcessesWhichHadLock() {
			$lockName = uniqid();

			$this->fork(
				function ($sh) use ($lockName) {
					// parent

					// wait for lock to be acquired
					$this->assertNextMessage('acquired', $sh);

					$this->assertDurationLessThan(3, function () use ($lockName) {
						try {
							SharedLock::lock($lockName, 3, 10);
						}
						catch (SharedLockTimeoutException $ex) {
							$this->fail('Lock was not acquired but should be cleaned since holding process already should have died');
						}
					});

				},
				function ($sh) use ($lockName) {
					//child

					SharedLock::lock($lockName, 0, 10);

					$this->sendMessage('acquired', $sh);

					sleep(1);
				}
			);
		}

		/**
		 * Test if locks are cleaned (even if wait timeout 0) if they had the database lock and do not have it anymore
		 */
		public function testLockCleanDiedProcessesWhichHadLockTimeout0() {
			$lockName = uniqid();

			$this->fork(
				function ($sh) use ($lockName) {
					// parent

					// wait for lock to be acquired
					$this->assertNextMessage('acquired', $sh);

					sleep(1);

					$this->assertDurationLessThan(3, function () use ($lockName) {
						try {
							SharedLock::lock($lockName, 0, 10);
						}
						catch (SharedLockTimeoutException $ex) {
							$this->fail('Lock was not acquired but should be cleaned since holding process already should have died');
						}
					});

				},
				function ($sh) use ($lockName) {
					//child

					SharedLock::lock($lockName, 0, 10);

					$this->sendMessage('acquired', $sh);

				}
			);
		}

		/**
		 * Test if locks are cleaned after their TTL if they did not have the database lock
		 */
		public function testLockCleanDiedProcessesWhichDidNotHaveLock() {
			$lockName = uniqid();

			$this->fork(
				function ($sh) use ($lockName) {
					// parent

					// wait for lock to be acquired
					$this->assertNextMessage('acquired', $sh);

					$this->assertDurationGreaterThan(6, function () use ($lockName) {
						try {
							SharedLock::lock($lockName, 10, 10);
						}
						catch (SharedLockTimeoutException $ex) {
							$this->fail('Lock was not acquired but should be cleaned since holding process already should have died');
						}
					});

				},
				function ($sh) use ($lockName) {
					//child

					SharedLock::lock($lockName, 0, 8);

					// simulate that the database lock was not hold
					if ($this->sqlExecute('UPDATE ' . SharedLock::getTable() . ' SET lock_acquired = false WHERE name = :name', [':name' => $lockName])->rowCount() != 1) {
						$this->fail('Test-Scenario-Error: Simulating lock_acquired = false failed');
					}

					$this->sendMessage('acquired', $sh);

					sleep(1);
				}
			);
		}


		/**
		 * Test if acquire times out after specified amount of time
		 */
		public function testLockTimeout() {

			$lockName = uniqid();

			$this->fork(
				function ($sh) use ($lockName) {
					// parent

					// wait for lock to be acquired
					$this->assertNextMessage('acquired', $sh);

					$this->assertDurationLessThan(4, function() use ($lockName) {
						try {
							SharedLock::lock($lockName, 3, 10);

							$this->fail('Lock was acquired but should be held by other process');
						}
						catch (SharedLockTimeoutException $ex) {
						}
					});

				},
				function  ($sh) use ($lockName) {
					//child

					SharedLock::lock($lockName, 0, 10);

					$this->sendMessage('acquired', $sh);

					// avoid implicit release before test finished
					sleep(4);
				}
			);
		}

		/**
		 * Test if lock release throws exception if not locked anymore
		 */
		public function testLockReleaseFailsNotLockedAnymore() {

			$lockName = uniqid();

			SharedLock::lock($lockName, 0, 10);

			// simulate that the database lock was not hold
			if ($this->sqlExecute('DELETE FROM ' . SharedLock::getTable() . ' WHERE name = :name', [':name' => $lockName])->rowCount() != 1) {
				$this->fail('Test-Scenario-Error: Simulating lock deleted failed');
			}

			$this->setExpectedException(SharedLockReleaseException::class);

			SharedLock::unlock($lockName);
		}

		/**
		 * Test if lock release throws exception if not locked anymore
		 */
		public function testLockReleaseFailsConnectionLoss() {

			$lockName = uniqid();

			SharedLock::lock($lockName, 0, 10);

			// simulate that the database lock was not hold
			try {
				$this->sqlExecute('KILL connection_id()');
			}
			catch (\PDOException $ex) {
				if ($ex->getCode() != 70100)
					throw $ex;
			}

			$this->setExpectedException(SharedLockReleaseException::class);

			SharedLock::unlock($lockName);
		}

		/**
		 * Test if assert ttl is successful if enough TTL remaining
		 */
		public function testAssertTTL() {

			$lockName = uniqid();

			SharedLock::lock($lockName, 0, 10);

			SharedLock::assertTTL($lockName, 5);
		}

		/**
		 * Test if assert ttl throws exception if not enough TTL remaining
		 */
		public function testAssertTTLFailsNotEnoughRemaining() {

			$lockName = uniqid();

			SharedLock::lock($lockName, 0, 10);

			$this->setExpectedException(SharedLockRemainingTTLException::class);

			SharedLock::assertTTL($lockName, 20);
		}

		/**
		 * Test if assert ttl throws exception if not locked anymore
		 */
		public function testAssertTTLFailsNotLockedAnymore() {

			$lockName = uniqid();

			SharedLock::lock($lockName, 0, 10);

			// simulate that the database lock was not hold
			if ($this->sqlExecute('DELETE FROM ' . SharedLock::getTable() . ' WHERE name = :name', [':name' => $lockName])->rowCount() != 1) {
				$this->fail('Test-Scenario-Error: Simulating lock deleted failed');
			}

			$this->setExpectedException(SharedLockRemainingTTLException::class);

			SharedLock::assertTTL($lockName, 1);
		}

		/**
		 * Test if assert ttl throws exception if not locked anymore
		 */
		public function testAssertTTLFailsConnectionLoss() {

			$lockName = uniqid();

			SharedLock::lock($lockName, 0, 10);

			// simulate that the database lock was not hold
			try {
				$this->sqlExecute('KILL connection_id()');
			}
			catch (\PDOException $ex) {
				if ($ex->getCode() != 70100)
					throw $ex;
			}

			$this->setExpectedException(SharedLockRemainingTTLException::class);

			SharedLock::assertTTL($lockName, 1);
		}


		public function testRepairTable() {
			SharedLock::tryRepairTable();
		}
	}