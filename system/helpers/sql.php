<?php

class sql {
	protected static $db;
	
	protected static $defaults = array(
		'varchar' => array('limit' => 255, 'null' => TRUE),
		'char' => array('limit' => 255, 'null' => TRUE),
		'tinyint' => array('null' => TRUE),
		'smallint' => array('null' => TRUE),
		'mediumint' => array('null' => TRUE),
		'integer' => array('null' => TRUE, 'autoincrement' => FALSE),
		'bigint' => array('null' => TRUE),
		'datetime' => array('null' => TRUE),
		'date' => array('null' => TRUE),
		'float' => array('limit' => '9,3', 'null' => TRUE),
		'decimal' => array('limit' => '9,3', 'null' => TRUE),
		'text' => array('null' => TRUE),
		'enum' => array('null' => TRUE),
	);

	protected static $overrides;
	
	public static function init() {
		self::$db = Database::factory();
		self::reset_defaults();
	}

	public static function add_table($name, $columns, $indexes, $triggers, $constraints, $options = array()) {
		$commands = array();

		foreach ($columns as $column) {
			$commands[] = substr(self::add_column(FALSE, $column), 3);
		}

		foreach ($indexes as $index) {
			$commands[] = substr(self::add_index(FALSE, $index), 3);
		}

		foreach ($constraints as $constraint) {
			$commands[] = substr(self::add_constraint(FALSE, $constraint), 3);
		}

		/**
		 * Parse the options array into a fresh array.
		 */
		$options_parsed = array();
		foreach ($options as $opt_key => $opt_value) {
			$options_parsed[] = sprintf('%s=%s', strtoupper($opt_key), $opt_value);
		}

		// Make it a string.
		$options_parsed = implode(' ', $options_parsed);

		// If there's a value, prepend a space.
		if ((bool) $options_parsed) {
			$options_parsed = ' '.$options_parsed;
		}

		self::$db->query(sprintf("CREATE TABLE `%s` (\n\t%s\n)%s",
			$name, implode(",\n\t", $commands), $options_parsed));

		foreach ($triggers as $trigger)
			self::$db->query(self::add_trigger(FALSE, $trigger));
	}

	public static function drop_table($name, $if_exists = FALSE) {
		if ((bool) $if_exists) {
			self::$db->query('DROP TABLE IF EXISTS `'.$name.'`');
		} else {
			self::$db->query('DROP TABLE `'.$name.'`');
		}
	}

	public static function rename_table($from, $to) {
		self::$db->query(sprintf('RENAME TABLE `%s` TO `%s`', $from, $to));
	}

	public static function add_column($table, $column) {
		list($name, $type, $options) = $column;

		$command = sprintf('ADD `%s` %s', $name, self::parsefieldoptions($type, $options));

		if (FALSE === $table)
			return $command;

		self::$db->query(sprintf('ALTER TABLE `%s` %s', $table, $command));
	}

	public static function alter_column($table, $column) { // $name, $type, $options = array()) {
		list($name, $type, $options) = $column;

		// Do they want to rename the field?
		if (! (bool) $new_name = arr::val($options, 'name'))
			$new_name = $name;

		$command = sprintf('CHANGE `%s` `%s` %s', $name, $new_name, self::parsefieldoptions($type, $options));
		if (FALSE === $table)
			return $command;

		self::$db->query(sprintf('ALTER TABLE `%s` %s', $table, $command));
	}

	public static function drop_column($table, $column) {
		$command = sprintf('DROP COLUMN `%s`', $column);

		if (FALSE === $table)
			return $command;

		self::$db->query(sprintf('ALTER TABLE `%s` %s', $table, $command));
	}

	public static function add_index($table, $index) {
		list($name, $columns, $options) = $index;

		if (! (bool) $columns)
			$columns = array($name);

		// Handle single-column indexes.
		if (is_string($columns))
			$columns = array($columns);

		if (! (bool) $columns)
			throw new Exception('Unknown type of columns? '.gettype($columns).': '.$columns);

		$cols = '';
		foreach ($columns as $column)
			$cols .= sprintf('`%s`, ', $column);

		if (arr::val($options, 'unique') === TRUE)
			$uniq = 'UNIQUE ';
		else
			$uniq = FALSE;

		if (($name == 'PRIMARY') OR (arr::val($options, 'primary') === TRUE)) {
			$uniq = 'PRIMARY ';
			$name = FALSE;
		}

		$cols = substr($cols, 0, -2);

		if ((bool) $name)
			$name = '`'.$name.'`';

		$command = sprintf('ADD %sKEY %s (%s)', $uniq, $name, $cols);

		if (FALSE === $table)
			return $command;

		self::$db->query(sprintf('ALTER TABLE `%s` %s', $table, $command));
	}

	public static function drop_index($table, $index) {
		$command = sprintf('DROP KEY `%s`', $index);

		if (FALSE === $table)
			return $command;

		self::$db->query(sprintf('ALTER TABLE `%s` %s', $table, $command));
	}

	public static function add_trigger($table, $trigger) {
		list($name, $when, $event, $on_table, $sql) = $trigger;

		$command = sprintf(
			'CREATE TRIGGER `%s` %s %s ON `%s` FOR EACH ROW BEGIN%sEND',
			$name, strtoupper($when), strtoupper($event),
			$on_table, "\n".$sql."\n"
		);

		if (FALSE === $table)
			return $command;

		self::$db->query($command);
	}

	public static function drop_trigger($table, $trigger) {
		$command = sprintf('DROP TRIGGER `%s`', $trigger);

		if (FALSE === $table)
			return $command;

		self::$db->query($command);
	}

	public static function add_constraint($table, $constraint) {
		list($name, $index, $reference_table, $reference_fields, $cascade) = $constraint;

		// Escape the field names.
		foreach ($reference_fields as &$field) {
			$field = '`'.$field.'`';
		}

		// Patch the cascade items to work in SQL.
		foreach ($cascade as &$cas) {
			$cas = 'ON '.strtoupper($cas).' CASCADE';
		}

		$command = sprintf(
			'ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (%s) %s',
			$name, $index, $reference_table, implode(', ', $reference_fields),
			implode(' ', $cascade)
		);

		if ($table === FALSE) {
			return $command;
		}

		self::$db->query($command);
	}

	public static function drop_constraint($table, $constraint) {
		$command = sprintf('DROP FOREIGN KEY `%s`', $constraint);

		if (FALSE === $table)
			return $command;

		self::$db->query(sprintf('ALTER TABLE `%s` %s', $table, $command));
	}

	public static function alter($table, $columns, $indexes, $triggers, $constraints, $options) {
		$changes = array();

		foreach ($columns['drop'] as $column)
			$changes[] = self::drop_column(FALSE, $column);
		foreach ($columns['alter'] as $column)
			$changes[] = self::alter_column(FALSE, $column);
		foreach ($columns['add'] as $column)
			$changes[] = self::add_column(FALSE, $column);

		foreach ($indexes['drop'] as $index)
			$changes[] = self::drop_index(FALSE, $index);
		foreach ($indexes['add'] as $index)
			$changes[] = self::add_index(FALSE, $index);

		foreach ($constraints['drop'] as $constraint)
			$changes[] = self::drop_constraint(FALSE, $constraint);
		foreach ($constraints['add'] as $constraint)
			$changes[] = self::add_constraint(FALSE, $constraint);

		if ((bool) $changes)
			self::$db->query(sprintf('ALTER TABLE `%s` '."\n\t".'%s', $table, implode(",\n\t", $changes)));

		foreach ($triggers['drop'] as $trigger)
			self::$db->query(self::drop_trigger(FALSE, $trigger));
		foreach ($triggers['add'] as $trigger)
			self::$db->query(self::add_trigger(FALSE, $trigger));
	}

	public static function escape($value, $wrap = '\'') {
		if (is_string($value)) {
			$value = $wrap.self::$db->escape_value($value).$wrap;
		}

		// Convert null values to the NULL keyword.
		if ($value === NULL) {
			$value = 'NULL';
		}

		return $value;
	}

	public static function reset_defaults() {
		self::$overrides = array();
	}

	public static function set_default($field_type, $options) {
		if (! array_key_exists($field_type, self::$defaults))
			throw new Exception('Unknown field type: '.$field_type);
		
		if (! is_array($options))
			throw new Exception('Invalid options. Expected: Array, Actual: '
				.gettype($options));
		
		self::$overrides[$field_type] = $options;
	}

	/**
	 * Add in defaults if they're required.
	 */
	private static function parsefieldoptions($type, $options) {
		if ($type == 'string')
			$type = 'varchar';

		$defaults = array_merge(self::$defaults, self::$overrides);

		if (array_key_exists($type, $defaults))
			$options = array_merge($defaults[$type], $options);

		if (preg_match('/varchar|char|float|decimal/', $type))
			$sql = sprintf('%s(%s)', $type, $options['limit']);
		elseif ($type == 'enum') {
			$enum_options = array();
			foreach ($options['options'] as $option)
				$enum_options[] = self::escape($option);

			$enum_options = implode(',', $enum_options);

			$sql = sprintf('%s(%s)', $type, $enum_options);
		} else
			$sql = $type;

		if (arr::val($options, 'unsigned') === TRUE)
			$sql .= ' UNSIGNED';

		if (arr::val($options, 'null') === FALSE)
			$sql .= ' NOT NULL';

		if (arr::val($options, 'autoincrement') === TRUE)
			$sql .= ' AUTO_INCREMENT';
		
		if (($def = arr::val($options, 'default')) !== FALSE) {
			if (is_null($def))
				$sql .= ' DEFAULT NULL';
			else
				$sql .= ' DEFAULT '.self::escape($def);
		}

		if (($after = arr::val($options, 'after')) !== FALSE)
			$sql .= ' AFTER '.self::escape($after, '`');

		if (arr::val($options, 'first') !== FALSE)
			$sql .= ' FIRST';

		return $sql;
	}

	/**
	 * Build a string from a field => value associative array that can be used
	 * in a SET or WHERE clause.
	 * @param array $data Field to value associative array.
	 * @param string $join Glue to use between field-value pairs.
	 * @param boolean $compare Sets comparison mode, where ' = NULL'
	 * becomes 'IS NULL'. @since 0.4.12.
	 */
	protected static function set_data($data, $join = ', ', $compare = FALSE) {
		$values = array();
		foreach ($data as $field => $value) {
			if ((bool) $compare AND $value === NULL) {
				// Handle NULLs as a comparison.
				$values[] = sprintf('%s IS NULL', self::escape($field, '`'));
			} else {
				// SET style.
				$values[] = sprintf('%s = %s', self::escape($field, '`'), self::escape($value));
			}
		}
		
		return implode($join, $values);
	}
	
	public static function insert($name, $data, $extra = '') {
		if ((bool) $extra)
			$extra .= ' ';
		self::$db->query(sprintf('INSERT %sINTO `%s` SET %s', $extra, $name, self::set_data($data)));
	}

	public static function insert_id() {
		return self::$db->insert_id();
	}
	
	public static function update($name, $data, $where) {
		self::$db->query(sprintf(
			'UPDATE `%s` SET %s WHERE %s', $name,
			self::set_data($data), self::set_data($where, ' AND ', TRUE)
		));
	}
	
	public static function delete($name, $where) {
		self::$db->query(sprintf(
			'DELETE FROM `%s` WHERE %s', $name,
			self::set_data($where, ' AND ', TRUE)
		));
	}

	public static function truncate($name) {
		self::$db->query(sprintf('TRUNCATE TABLE `%s`', $name));
	}
}