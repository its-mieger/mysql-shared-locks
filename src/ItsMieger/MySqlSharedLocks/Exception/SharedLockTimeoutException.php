<?php
	/**
	 * Created by PhpStorm.
	 * User: chris
	 * Date: 03.08.17
	 * Time: 13:33
	 */

	namespace ItsMieger\MySqlSharedLocks\Exception;


	use Throwable;

	class SharedLockTimeoutException extends SharedLockAcquireException
	{

		protected $timeout;

		/**
		 * SharedLockException constructor.
		 * @param string $lockName The lock name
		 * @param int $timeout The timeout which exceeded
		 * @param string $message [optional] The Exception message to throw.
		 * @param int $code [optional] The Exception code.
		 * @param Throwable $previous [optional] The previous throwable used for the exception chaining.
		 */
		public function __construct($lockName, $timeout, $message = "", $code = 0, Throwable $previous = null) {
			$this->timeout = $timeout;

			if (empty($message))
				$message = 'Acquiring lock "' . $lockName . '" timed out after ' . $timeout . ' seconds';

			parent::__construct($lockName, $message, $code, $previous);
		}

		/**
		 * @return int
		 */
		public function getTimeout() {
			return $this->timeout;
		}


	}