<?php

class LadderDB {
	protected $conn;
	protected $databases;
	protected $database_id;
	
	protected static $instance;
	
	public static function factory() {
		if (! (bool) self::$instance) {
			self::$instance = new LadderDB;
		}
		
		return self::$instance;
	}

	public function __construct() {
		if (is_string($this->databases = Config::item('database.database'))) {
			$this->databases = array($this->databases);
		}

		$this->database_id = -1;
	}

	public function __get($key) {
		if ($key === 'name') {
			return $this->databases[$this->database_id];
		}
	}

	protected function connect() {
		// No need to connect again if we have a resource.
		if ((bool) $this->conn) {
			return;
		}

		// Grab the port, prefix with colon if it's set.
		if ((bool) $port = Config::item('database.port')) {
			$port = ':'.$port;
		}

		// Initialise the options.
		$parsed_options = 0;

		// Check for options.
		if ((bool) Config::item('database.compress')) {
			$parsed_options |= MYSQL_CLIENT_COMPRESS;
		}

		// Attempt to connect.
		$host = Config::item('database.hostname').$port;
		echo 'Connecting to ', $host, '... ';
		$this->conn = mysql_connect(
			$host,
			Config::item('database.username'),
			Config::item('database.password'),
			FALSE,
			$parsed_options
		);

		if (! (bool) $this->conn)
			throw new Exception('Unable to connect to database at '.$host.' '.mysql_error());

		// Grab the version number from the server now we're connected.
		$version = $this->query('SELECT @@version');
		$version = mysql_fetch_row($version);
		$version = $version[0];
		
		echo sprintf('Connected. Server version %s.', $version), PHP_EOL;

		if ($this->database_id > -1) {
			if (! mysql_select_db($this->name, $this->conn))
				throw new Exception('Invalid database: '.$this->name);
		}
	}

	protected function disconnect() {
		mysql_close($this->conn);
		$this->conn = FALSE;
	}

	public function reconnect() {
		$this->disconnect();
		$this->connect();
	}

	public function escape_value($value) {
		$this->connect();
		return mysql_real_escape_string($value, $this->conn);
	}

	public function query($sql, $show_sql = NULL) {
		$this->connect();

		// If nothing passed, use params to set option.
		if ($show_sql === NULL) {
			global $params;
			$show_sql = $params['show-sql'];
		}

		// Should we show this SQL?
		if ($show_sql) {
			echo $sql, "\n";
		}
		
		$res = mysql_query($sql, $this->conn);

		if (! (bool) $res) {
			$error = mysql_error($this->conn);

			$warnings = array();

			// See if we need to ask for warnings information.
			if (strpos($error, 'Check warnings') !== FALSE) {
				$warn_query = mysql_query('SHOW WARNINGS', $this->conn);

				while ((bool) $warn_row = mysql_fetch_object($warn_query)) {
					$warnings[] = $warn_row->Level.' - '.$warn_row->Message;
				}
			}

			throw new Exception($error.' '.implode(', ', $warnings));
		} else {
			return $res;
		}
	}

	public function insert_id() {
		return mysql_insert_id($this->conn);
	}

	public function next_database($output = TRUE) {
		$this->connect();

		++$this->database_id;

		if ($this->database_id < count($this->databases)) {
			if (! mysql_select_db($this->name, $this->conn))
				throw new Exception('Invalid database: '.$this->name);

			if ((bool) $output) {
				echo $this->name, '... ', "\n";
			}
			$this->check_migrations_table();

			return TRUE;
		} else {
			return FALSE;
		};
	}

	public function get_migrations_table() {
		static $table_name;

		if (! (bool) $table_name) {
			// Get the migrations table name from the config.
			$table_name = Config::item('database.migrations_table', 'migrations');
		}

		return $table_name;
	}
	
	/**
	 * Get the name of the kvdata table.
	 * @since 0.6.0
	 */
	public function get_kvdata_table() {
		static $table_name;
		
		if (! (bool) $table_name) {
			$table_name = Config::item('database.kvdata_table', 'migrations_kvdata');
		}
			
		return $table_name;
	}

	/**
	 * Check that the migrations tables exist. If not, create them.
	 */
	public function check_migrations_table() {
		// Check the migrations table exists
		if (! (bool) Table::exists($this->get_migrations_table())) {
			Table::factory($this->get_migrations_table())
				->column('migration', 'integer', array('null' => FALSE))
				->column('applied', 'datetime', array('null' => FALSE))
				->index('primary', 'migration')
				->execute()
			;
		}
		
		// Ensure that the new `kvdata` table exists.
		if (! Table::exists($this->get_kvdata_table())) {
			Table::factory($this->get_kvdata_table())
				->column('migration', 'integer', array('null' => FALSE))
				->column('kvdata', 'text', array('null' => FALSE))
				->index('primary', 'migration')
				->execute()
			;
		}
	}

	public function get_current_migration() {
		// Find what the maximum migration is...
		$migration_query = $this->query(
			'SELECT MAX(`migration`) AS `migration` FROM `'
			.$this->get_migrations_table().'`',
			FALSE
		);
		$migration_result = mysql_fetch_object($migration_query);
		return (int) $migration_result->migration;
	}

	public function get_migrations() {
		// Query the table.
		$query = $this->query(sprintf(
			'SELECT `migration`  FROM `%s` ORDER BY `migration`',
			$this->get_migrations_table()
		));

		// Initialise our result array.
		$result = array();

		// Loop over each row.
		while ($row = mysql_fetch_object($query)) {
			$result[] = (int) $row->migration;
		}

		return $result;
	}

	public function has_migration($id) {
		$query = $this->query(sprintf(
			'SELECT `migration` FROM `%s` WHERE `migration` = %d',
			$this->get_migrations_table(), (int) $id
		));

		return mysql_num_rows($query) == 1;
	}

	/**
	 * Remove a migration from the database.
	 * @param integer $migration The migration id to remove.
	 */
	public function remove_migration($id) {
		$query = $this->query(sprintf(
			'DELETE FROM `%s` WHERE `migration` = %d',
			$this->get_migrations_table(), (int) $id
		));
	}

	public function add_migration($id) {
		$query = $this->query(sprintf(
			'INSERT INTO `%s` (`migration`, `applied`) VALUES (%d, NOW())',
			$this->get_migrations_table(), (int) $id
		));
	}
}