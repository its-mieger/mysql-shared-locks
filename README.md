# MySQL shared locks

This package implements a MySQL based shared locks for distributed applications.

Following features are provided:
	
* named logs without prior registration
* timeout for lock acquiring
* TTL (time to live) for locks
* Locks are independent from Database transactions 

The implemented locks guarantee to be **released by end of the process** holding them
(if not done explicitly before) and they also guarantee to **never block longer than
their TTL**, even the process holding them hangs longer.

## Implementation details
* Locks are managed using a database table (MyISAM engine to ignore transactions)
* Trying to acquire a lock twice is prevented by unique index on database table
* After lock is insert in the table MySQL's `GET_LOCK`- and `RELEASE_LOCK`-functions
  are used to block other processes until lock becomes free again.
* The entry in the lock table is representing the lock, not the `GET_LOCK`-object.
  Therefore any SQL session which still holds the `GET_LOCK`-object for the
  current lock is terminated via `KILL`. This also informs the other process that
  it no longer has the lock.
* Obsolete locks (TTL exceeded) are cleared from the able on unsuccessful
  acquires.


## Requirements

* PHP >= 5.6
* MySQL >= 5.7.5 (before 5.7.5 only one named lock per session can be acquired)


## Database setup

Run the following SQL to create the locks table:

	CREATE TABLE `shared_locks` (
	  `name` varchar(64) NOT NULL,
	  `connection_id` bigint(20) NOT NULL,
	  `created` bigint(20) NOT NULL,
	  `ttl` bigint(20) NOT NULL,
	  `lock_acquired` tinyint(1) NOT NULL,
	  UNIQUE KEY `shared_locks_ix1` (`name`)
	) ENGINE=MyISAM DEFAULT CHARSET=latin1
	
	
## Example usage

	// configure database connection
	SharedLock::configureConnection(DB_HOST, DB_DATABASE, DB_USER, DB_PASSWORD);
	
	// Acquire lock (timeout = 5s, TTL = 10s)
	$lock = SharedLock::lock($lockName, 5, 10);
	
	// Release
	$lock->release();
	
## SQL identifier quoting

By default MySQL's default identifier quotes '`' are used. However you
may adapt the quoting style if using ANSI_QUOTES quotes to '"':

	SharedLock::setQuoteStyle(SharedLock::QUOTE_STYLE_ANSI);
	
	
### Being aware that a process rarely might exceed the lock TTL

Imagine you having a process which has acquired a lock. When this process hangs
for a long time an then suddenly continues running the lock which it acquired
might be taken over by another process. This case should rarely happen but
nevertheless it may.

There are two strategies to handle this:

#### 1. Using same database connection for locks and application code
You may pass the database connection for the locks using following command:

	SharedLock::setConnection($pdoConnection);
	
Then use transactions to only commit data if lock has not exceeded meanwhile. Eg.:

	// start transaction
	$pdoConnection->beginTransaction();

	try {
		$lock = SharedLock::lock('namedLock', 5, 10);
		
		/*
		 * Your application code placed here
		 */
	
		// commit transaction
		$pdoConnection->commit();
				
	}
	catch (Exception $ex) {

		// rollback transaction
		$pdoConnection->rollBack();
		
		throw $ex;
	}
	finally {
	
		// release lock
		if (!empty($lock))
			$lock->release();
	}
	
	
If the lock would have been released meanwhile the SQL session would already have
been killed and therefore your transaction will fail.


#### 2. Using assertTTL
If using the same database connection for locks and application code in combination
with transactions is not an option (or you don't use the lock for synchronizing DB
operations), you can use the `assertTTL` function to check if the lock is still
held and if there is enough time remaining for the process to complete the
operation without fearing to lose lock meanwhile:

	try {
		$lock = SharedLock::lock('namedLock', 5, 10);
		
		/*
		 * Your application code placed here
		 */
		
		// check if still locked
		$lock->assertTTL(5);
		
		/*
		 * Your application code placed here
		 */
	}
	finally {
	
		// release lock
		if (!empty($lock))
			$lock->release();
	}


