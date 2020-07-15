<?php
	/**
	 * Created by PhpStorm.
	 * User: chris
	 * Date: 03.08.17
	 * Time: 13:06
	 */

	namespace SharedLockTest;


	// include configuration
	use ItsMieger\MySqlSharedLocks\SharedLock;
	use PDO;

	require_once 'conf.php';


	class AbstractSharedLockTest extends \PHPUnit_Framework_TestCase
	{
		/**
		 * @before
		 */
		protected function configureConnection() {

			SharedLock::configureConnection(TEST_DB_HOST, TEST_DB_DATABASE, TEST_DB_USER, TEST_DB_PASSWORD, 'utf8mb4', TEST_DB_PORT );
		}


		public function assertDurationLessThan($expectedMicroTime, callable $fn) {
			$ts = microtime(true);

			call_user_func($fn);

			$this->assertLessThan($ts + $expectedMicroTime, microtime(true));
		}

		public function assertDurationGreaterThan($expectedMicroTime, callable $fn) {
			$ts = microtime(true);

			call_user_func($fn);

			$this->assertGreaterThan($ts + $expectedMicroTime, microtime(true));
		}

		public function getNextMessage($handle, $timeout = 1000000) {
			$timeWaited = 0;

			while (!($lastRead = fgets($handle))) {
				usleep(50000);
				$timeWaited += 50000;
				if ($timeWaited > $timeout)
					$this->fail('Timeout waiting for message.');
			}

			return $lastRead;
		}

		public function assertNextMessage($expected, $handle, $timeout = 1000000) {
			$timeWaited = 0;

			while (!($lastRead = fgets($handle))) {
				usleep(50000);
				$timeWaited += 50000;
				if ($timeWaited > $timeout)
					$this->fail('Timeout waiting for message "' . $expected . '"');
			}
			$this->assertEquals($expected, $lastRead);
		}

		public function assertNoMessage($handle, $forDurationSeconds) {
			sleep($forDurationSeconds);
			$this->assertEmpty(fgets($handle));
		}

		public function waitForChild($pid) {
			if (posix_kill($pid, 0))
				while ($pid != pcntl_wait($status)) {
				}
		}

		public function sendMessage($message, $handle) {
			fwrite($handle, $message);
		}

		public function fork(callable $parentFn, callable $childFn) {
			$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
			$pid     = pcntl_fork();
			if ($pid == -1) {
				throw new \Exception('Could not fork');
			}
			else if ($pid) {
				// parent

				// close socket
				fclose($sockets[0]);

				// non-blocking read
				stream_set_blocking($sockets[1], false);

				// call user function
				call_user_func($parentFn, $sockets[1], $pid);


				// close socket
				fclose($sockets[1]);

				// wait for child to die
				$this->waitForChild($pid);
			}
			else {
				// child

				// close socket
				fclose($sockets[1]);

				// call user function
				call_user_func($childFn, $sockets[0]);

				// close socket
				fclose($sockets[0]);

				die();
			}
		}


		/**
		 * Fetches a single value from the database
		 * @param string $sql The SQL query
		 * @param array $parameters The parameters
		 * @return mixed The value
		 */
		protected function sqlFetchValue($sql, $parameters = []) {

			$stmt = $this->sqlExecute($sql, $parameters);

			return $stmt->fetchColumn();
		}

		/**
		 * Executes the specified statement
		 * @param string $sql The SQL query
		 * @param array $parameters The parameters
		 * @return \PDOStatement The executed statement
		 */
		protected function sqlExecute($sql, $parameters = []) {
			// prepare statement
			$stmt = SharedLock::getConnection()->prepare($sql);
			/** @noinspection PhpMethodParametersCountMismatchInspection */
			$stmt->setFetchMode(PDO::FETCH_COLUMN, 0);

			$stmt->execute($parameters);

			return $stmt;
		}
	}