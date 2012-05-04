<?php

/**
 * Database Class
 *
 * This is the Mysidia Adoptables database class.
 *
 * @package		Mysidia Adoptables
 * @subpackage	Drivers
 * @category	Database
 * @since		1.3.2
 * @author		Mysidia Dev Team
 */
class DB extends PDO
{
	/**
	 * Table prefix.
	 *
	 * @access	public
	 * @var		string
	 */
	public $prefix;

	/**
	 * Keep track of total rows from each query.
	 *
	 * @access  private
	 * @var 	array
	 */
	private $_total_rows = array();

	/**
	 * Data for generating queries.
	 *
	 * @access	private
	 * @var		array
	 */
	private $_queries = array(array());

	/**
	 * The ID of the currently active query.
	 *
	 * @see		self::$_query
	 * @access	private
	 * @var		int
	 */
	private $_active = 0;

	/**
	 * Count how many queries have been created (not executed).
	 *
	 * @access	private
	 * @var		int
	 */
	private $_query_counter = 0;

	/**
	 * 
	 * A reference to the currently active query.
	 * 
	 * @see		self::$_queries
	 * @access	private
	 * @var		array
	 */
	private $_query = array();

	/**
	 * If you don't know what this is, you shouldn't be here.
	 *
	 * @param	string 	$dbname
	 * @param	string 	$host
	 * @param	string 	$user
	 * @param	string 	$password
	 * @param	string 	$prefix		Table prefix
	 * @access	public
	 */
	public function __construct($dbname, $host, $user, $password, $prefix = 'adopts_')
	{
		parent::__construct('mysql:host=' . $host . ';dbname=' . $dbname, $user, $password);
		$this->prefix = $prefix;
		$this->_update_active_query();
	}

	/**
	 * Starts a new query and set it as the active one.
	 *
	 * @see		self::_update_active_query()
	 * @access	public
	 * @return	self
	 */
	public function start_query()
	{
		$this->_queries[++$this->_query_counter] = array();
		$this->_active++;
		$this->_update_active_query();
		return $this;
	}

	/**
	 * Change the currenty active query.
	 *
	 * @see		self::_update_active_query()
	 * @access	public
	 * @return	self
	 */
	public function set_active($id)
	{
		$this->_active =  isset($this->_queries[$id]) ? $id : $this->_active;
		$this->_update_active_query();
		return $this;
	}

	/**
	 * Insert a new row to a table.
	 *
	 * @param	string	$table_name
	 * @param	array	$data		A key-value pair with keys that correspond to the fields of the table
	 * @access	public
	 * @return	self
	 */
	public function insert($table_name, array $data)
	{
		$this->_query['operation'] = 'insert';
		$this->_query['fields'] = array_keys($data);
		$this->_query['data'] = array_values($data);
		$this->_query['table'] = $table_name;

		return $this;
	}

	/**
	 * Updates rows of a table.
	 *
	 * @param	string	$table_name
	 * @param	array	$data		A key-value pair with keys that correspond to the fields of the table
	 * @access	public
	 * @return	self
	 */
	public function update($table_name, array $data)
	{
		$fields = array_keys($data);

		foreach ($fields as &$field)
		{
			$field = trim($field);
			if (strpos($field, '.') === FALSE)
			{
				$field = $table_name . '.' . $field;
			}
		}

		$this->_query['operation'] = 'update';
		$this->_query['fields'] = $fields;
		$this->_query['data'] = array_values($data);
		$this->_query['table'] = $table_name;

		return $this;
	}

	/**
	 * Deletes rows from a table.
	 *
	 * @param	string	$table_name
	 * @access	public
	 * @return	self
	 */
	public function delete($table_name)
	{
		$this->_query['operation'] = 'delete';
		$this->_query['table'] = $table_name;

		return $this;
	}

	/**
	 * Selects rows from a table.
	 * The first parameter can be either an array, a string, or an empty value.
	 *
	 * @param	mixed	$fields		Fields to be selected from the table
	 * @param	string	$table_name	A key-value pair with keys that correspond to the fields of the table
	 * @access	public
	 * @return	self
	 */
	public function select($fields = '*', $table_name)
	{
		if ($fields !== '*' AND ! empty($fields))
		{
			if (is_string($fields))
			{
				$fields = explode(',', $fields);
			}

			foreach ($fields as &$field)
			{
				$field = trim($field);
				if (strpos($field, '.') === FALSE)
				{
					$field = $table_name . '.' . $field;
				}
			}
		}
		else
		{
			$fields = '*';
		}
		
		$this->_query['operation'] = 'select';
		$this->_query['fields'] = $fields;
		$this->_query['table'] = $table_name;

		return $this;
	}

	/**
	 * Joins a table. 
	 *
	 * @param	string	$table_name
	 * @param	string	$condition
	 * @param	string	$type		Join type
	 * @access	public
	 * @return	self
	 */
	public function join($table_name, $condition, $type = 'INNER')
	{
		$this->_query['joins'][] = array(
			'table' => $table_name,
			'cond'  => $condition,
			'type'  => $type
		);

		return $this;
	}

	/**
	 * Adds a constraint to the query.
	 *
	 * @see		self::where_having()
	 * @param	mixed	$field
	 * @param	mixed	$value
	 * @param	string	$logic		The logic operator to use to join with the previous condition
	 * @access	public
	 * @return	self
	 */
	public function where($field, $value = NULL, $logic = 'AND')
	{
		return $this->where_having($field, $value, $logic, 'where');
	}

	/**
	 * Orders the result of the query.
	 *
	 * @param	mixed	$fields	
	 * @param	mixed	$order
	 * @access	public
	 * @return	self
	 */
	public function order_by($fields, $order = array('ASC'))
	{
		if (is_string($fields))
		{
			$fields = explode(',', $fields);
		}
		elseif (is_array($fields))
		{
			$order = array_values($fields);
			$fields = array_keys($fields);
		}

		if (is_string($order))
		{
			$order = array($order);
			$order = array_pad($order, count($fields), 'ASC');
		}

		foreach ($fields as &$field)
		{
			$field = trim($field);
		}

		$this->_query['sort']['fields'] = $fields;
		$this->_query['sort']['order'] = $order;

		return $this;
	}

	/**
	 * Aggregates the query result.
	 *
	 * @param	mixed	$fields	
	 * @access	public
	 * @return	self
	 */
	public function group_by($fields)
	{
		if (is_string($fields))
		{
			$fields = explode(',', $fields);
		}

		foreach ($fields as &$field)
		{
			$field = trim($field);
		}

		$this->_query['group'] = $fields;
		return $this;
	}

	/**
	 * Filters the query result.
	 *
	 * @see		self::where_having()
	 * @param	mixed	$field
	 * @param	mixed	$value
	 * @param	string	$logic		The logic operator to use to join with the previous condition
	 * @access	public
	 * @return	self
	 */
	public function having($field, $value = NULL, $logic = 'AND')
	{
		return $this->where_having($field, $value, $logic, 'having');
	}

	/**
	 * Limits the total rows returned.
	 *
	 * @param	int		$total
	 * @param	int		$offset
	 * @return	self
	 */
	public function limit($total, $offset = NULL)
	{
		$this->_query['limit'] = array(
			'total'	 => $total,
			'offset' => $offset
		);

		return $this;
	}

	/**
     * Get total rows affected by previous queries.
	 *
     * @return int
     */
    public function get_total_rows()
    {
        return $this->_total_rows[$this->_active];
    }

	/**
	 * Executes the currently active query.
	 *
	 * @see		self::$_query
	 * @param	bool	$keep_query	Whether or not to keep the query for later use
	 * @return	object				A PDOStatement object
	 */
	public function run($keep_query = FALSE)
	{
		$sql = call_user_func_array(array($this, '_' . $this->_query['operation'] . '_query'), array());

		$stmt = $this->prepare($sql);
		$this->_bind_data($stmt);
		$this->_query = $keep_query === FALSE ? array() : $this->_query;

		if ( ! $stmt->execute())
		{
			$error = $stmt->errorInfo();
			throw new Exception('Database error ' . $error[1] . ' - ' . $error[2]);
		}

		$this->_total_rows[] = $stmt->rowCount();
		return $stmt;
	}

	/**
	 * Prepare the identifier for the query.
	 * This function will adds things like table prefix, backticks and whatnot.
	 *
	 * @param	mixed	$field
	 * @param	bool	$add_prefix
	 * @return	string
	 */
	public function prepare_identifier($field, $add_prefix = TRUE)
	{
		$field = (array) $field;
		foreach ($field as &$f)
		{
			$f = '`' . ($add_prefix === TRUE ? $this->prefix : NULL) . implode('`.`', explode('.', trim($f))) . '`';
		}

		return implode(',', $field);
	}

	/**
	 * Processes the WHERE and HAVING clause.
	 *
	 * @param	mixed	$field
	 * @param	mixed	$value
	 * @param	string	$logic
	 * @param	string	$clause		Tells which clause to process
	 * @return	self
	 */
	protected function where_having($field, $value = NULL, $logic = 'AND', $clause = 'where')
	{
		if (is_string($field))
		{
			$field = array($field => $value);
		}

		$fields = $field;
		foreach ($fields as $field => $value)
		{
			$this->_query[$clause][] = array(
				'field' => $field,
				'value' => $value,
				'logic' => $logic
			);
		}

		return $this;
	}

	/**
	 * Generates the SELECT query.
	 *
	 * @return	string
	 */
	protected function _select_query()
	{
		$sql = 'SELECT ' . ($this->_query['fields'] === '*' 
				? '*' 
				: $this->prepare_identifier($this->_query['fields']))
				. ' FROM ' . $this->prepare_identifier($this->_query['table']) . ' '
				. $this->_joins() . ' ' . $this->_where() . ' ' . $this->_group_by() . ' '
				. ' ' . $this->_where_having('having') . ' ' . $this->_order_by() . ' ' . $this->_limit();

		return $sql;
	}

	/**
	 * Generates the INSERT query.
	 *
	 * @return	string
	 */
	protected function _insert_query()
	{
		$this->_query['assoc_field'] = TRUE;

		$sql = 'INSERT INTO ' . $this->prepare_identifier($this->_query['table']) . ' '
				. '(' . $this->prepare_identifier($this->_query['fields'], FALSE) . ') VALUES '
				. '(:' . implode(', :', $this->_query['fields']) .')';

		return $sql;
	}

	/**
	 * Generates the DELETE query.
	 *
	 * @return	string
	 */
	protected function _delete_query()
	{
		$sql = 'DELETE FROM ' . $this->prepare_identifier($this->_query['table'])
				. ' ' . $this->_where() . ' ' . $this->_order_by() . ' ' . $this->_limit();

		return $sql;
	}

	/**
	 * Generates the UPDATE query.
	 *
	 * @return	string
	 */
	protected function _update_query()
	{
		$this->_query['assoc_field'] = TRUE;

		$sql = 'UPDATE ' . $this->prepare_identifier($this->_query['table']) . ' SET ';
		$update_fields = array();

		foreach ($this->_query['fields'] as $field)
		{
			$update_fields[] = $this->prepare_identifier($field) . ' = :' . str_replace('.', '_', $field);
		}
		
		$sql .= implode(', ', $update_fields) . ' ' . $this->_where()
				. ' ' . $this->_order_by() . ' ' . $this->_limit();

		return $sql;
	}

	/**
	 * Generates the JOIN clause.
	 *
	 * @return	string
	 */
	protected function _joins()
	{
		$joins_sql = '';

		if(isset($this->_query['joins']))
		{
			foreach ($this->_query['joins'] as $join)
			{
				$join['cond'] = explode('=', $join['cond']);

				for ($i = 0; $i <= 1; $i++)
				{
					$join['cond'][$i] = $this->prepare_identifier($join['cond'][$i]);
				}

				$join_cond = $join['cond'][0] . ' = ' . $join['cond'][1];
				$joins_sql .= strtoupper($join['type']) . ' JOIN ' . $this->prepare_identifier($join['table'])
							  . ' ON ' . $join_cond;
			}
		}

		return $joins_sql;
	}

	/**
	 * Generates the WHERE clause.
	 *
	 * @see		self::_where_having()
	 * @return	string
	 */
	protected function _where()
	{
		return $this->_where_having();
	}

	/**
	 * Generates either the WHERE or the HAVING clause.
	 *
	 * @param	string	$clause		Tells which clause to process
	 * @return	string
	 */
	protected function _where_having($clause = 'where')
	{
		if ( ! isset($this->_query[$clause]))
		{
			return '';
		}

		$this->_query[$clause . '_clause'] = TRUE;

		$this->_query[$clause][0]['logic'] = NULL;
		$sql = strtoupper($clause);

		foreach ($this->_query[$clause] as $clause_arr)
		{
			$sql .= ' ' . strtoupper($clause_arr['logic']) . ' ' . $this->prepare_identifier($clause_arr['field']);
			if ($clause_arr['value'] === NULL)
			{
				$sql .= ' IS NULL';
			}
			else
			{
				$sql .= ' = :' . $clause . '_' . str_replace('.', '_', $clause_arr['field']);
			}
		}

		return $sql;
	}

	/**
	 * Generates the GROUP BY clause.
	 *
	 * @return	string
	 */
	protected function _group_by()
	{
		$sql = '';

		if (isset($this->_query['group']))
		{
			$sql = 'GROUP BY ' . $this->prepare_identifier($this->_query['group']);
		}

		return $sql;
	}

	/**
	 * Generates the HAVING clause.
	 *
	 * @see		self::_where_having()
	 * @return	string
	 */
	protected function _having()
	{
		return $this->_where_having('having');
	}

	/**
	 * Generates the ORDER BY clause.
	 *
	 * @return 	string
	 */
	protected function _order_by()
	{
		$sql = '';

		if (isset($this->_query['sort']))
		{
			$sql = 'ORDER BY ';

			$total = count($this->_query['sort']['fields']);
			$order_fields = array();
			for ($i = 0; $i < $total; $i++)
			{
				$order_fields[] = $this->prepare_identifier($this->_query['sort']['fields'][$i]) . ' '
								  . strtoupper($this->_query['sort']['order'][$i]);
			}

			$sql .= implode(', ', $order_fields);
		}

		return $sql;
	}

	/**
	 * Generates the LIMIT clause.
	 *
	 * @return	string
	 */
	protected function _limit()
	{
		$sql = '';

		if (isset($this->_query['limit']))
		{
			$sql = 'LIMIT ';
			$sql .= ($this->_query['limit']['offset'] !== NULL ? $offset . ', ' : '') 
					. $this->_query['limit']['total']; 
		}

		return $sql;
	}

	/**
	 * Update the active query according to the active query ID.
	 *
	 * @see		self::$_query
	 * @see		self::$_active
	 * @return	self
	 */
	private function _update_active_query()
	{
		$this->_query =& $this->_queries[$this->_active];
		return $this;
	}

	/**
	 * Binds data to a prepared statement.
	 *
	 * @param 	object  $stmt 		A reference to a PDOStatement object
	 * @access  private
	 * @return  self
	 */
	private function _bind_data(&$stmt)
	{
		if (isset($this->_query['assoc_field']))
		{
			$total = count($this->_query['fields']);
		
			for ($i = 0; $i < $total; $i++)
			{
				$stmt->bindParam(':' . str_replace('.', '_', $this->_query['fields'][$i]), $this->_query['data'][$i]);
			}
		}
		
		$this->_where_having_data($stmt)->_where_having_data($stmt, 'having');

		return $this;
	}

	private function _where_having_data(&$stmt, $clause = 'where')
	{
		if (isset($this->_query[$clause . '_clause']))
		{
			foreach ($this->_query[$clause] as $clause_arr)
			{
				if ($clause_arr['value'] !== NULL)
				{
					$stmt->bindParam(':' . $clause . '_' . str_replace('.', '_', $clause_arr['field']), $clause_arr['value']);
				}
			}
		}

		return $this;
	}
}