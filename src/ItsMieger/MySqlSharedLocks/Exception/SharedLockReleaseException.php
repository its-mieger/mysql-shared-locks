<?php
	/**
	 * Created by PhpStorm.
	 * User: chris
	 * Date: 04.08.17
	 * Time: 08:30
	 */

	namespace ItsMieger\MySqlSharedLocks\Exception;


	use Throwable;

	class SharedLockReleaseException extends SharedLockException
	{

		/**
		 * @param string $lockName The lock name
		 * @param string $message [optional] The Exception message to throw.
		 * @param int $code [optional] The Exception code.
		 * @param Throwable $previous [optional] The previous throwable used for the exception chaining.
		 */
		public function __construct($lockName, $message = "", $code = 0, Throwable $previous = null) {

			if (empty($message))
				$message = 'Releasing lock "' . $lockName . '" failed';

			parent::__construct($lockName, $message, $code, $previous);
		}
	}