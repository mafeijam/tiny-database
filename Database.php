<?php

require 'DB.php';
require 'functions.php';

class Database
{
	protected $pdo;
	protected $table;
	protected $fetchMode;
	protected $debug;
	protected $type;
	protected $currentConfig;

	protected $defaultConfigFile = 'config.php';
	protected $defaultConfig = [
				'driver'	=> 'mysql',
				'host' 		=> 'localhost',
				'dbname' 	=> 'test',
				'username' 	=> 'root',
				'password' 	=> '',
				'fetchMode' => PDO::FETCH_OBJ,
				'debug'		=> true
			];

	protected $log = [];
	protected $select = false;
	protected $sql = null;
	protected $union = null;
	protected $value = [];
	protected $results = null;
	protected $hidden = [];
	protected $operators = ['=', '>', '<', '>=', '<=', '<>', '!=', 'like'];

	protected static $instance;
	protected static $connection = [];

	private function __construct($setting)
	{
		$config = $this->parseConfig($setting);
		$this->setup($config);
		$this->connect($config);
	}

	protected function parseConfig($setting)
	{
		$this->type = 'default config';
		$config = $this->defaultConfig;

		if (is_file($setting)) {
			$this->type = 'other file : ' . $setting;
			$config = require $setting;
		} elseif (file_exists($this->defaultConfigFile)) {
			$this->type = 'default file';
			$config = require $this->defaultConfigFile;
		}

		if (is_array($setting)) {
			$this->type = 'setting options - replace';
			$config = array_merge($config, $setting);
		} elseif (is_string($setting) and !is_file($setting) and $setting != '') {
			$this->type = 'setting options - dbname';
			$config['dbname'] = $setting;
		} elseif (is_bool($setting)) {
			$this->type = 'setting options - debug';
			$config['debug'] = $setting;
		} elseif (is_numeric($setting)) {
			$this->type = 'setting options - fetch mode';
			$config['fetchMode'] = $setting;
		}

		return $config;
	}

	protected function setup($config)
	{
		$this->fetchMode = $config['fetchMode'];
		$this->debug = $config['debug'];
		$this->currentConfig = $config;
	}

	protected function connect($config)
	{
		$dsn = $config['driver'] . ':host=' . $config['host'] . ';dbname=' . $config['dbname'] . ';charset=' . $config['charset'];

		try {
			$this->pdo = new PDO($dsn, $config['username'], $config['password']);
		} catch (PDOException $e) {
			exit($e->getMessage());
		}
	}

	public static function run($setting = null)
	{
		isset(static::$instance) ?: static::$instance = new static($setting);

		return static::$instance;
	}

	public static function newConnection($config)
	{
		static::$connection[] = new static($config);
		return end(static::$connection);
	}

	public function pdo()
	{
		return $this->pdo;
	}

	public function raw($sql)
	{
		$results = $this->pdo->query($sql)->fetchAll($this->fetchMode);

		return $this->debug ? pr($results) : $results;
	}

	public function table($name)
	{
		$this->table = $name;
		return $this;
	}

	public function join($table, $LK, $FK, $columns = '*')
	{
		$this->sql = "SELECT $columns FROM $this->table INNER JOIN $table ON $this->table.$LK = $table.$FK";
		$this->select = true;
		return $this;
	}

	public function hasTable($name)
	{
		$tables = $this->pdo->query('show tables')->fetchAll(PDO::FETCH_NUM);

		foreach ($tables as $table) {
			foreach ($table as $t) {
				$list[] = $t;
			}
		}

		if ($this->debug) d(in_array($name, $list));

		return in_array($name, $list);
	}

	public function select($column = '*')
	{
		$this->sql = "SELECT $column FROM $this->table";
		$this->select = true;
		return $this;
	}

	public function all()
	{
		return $this->select()->get();
	}

	public function only($max)
	{
		return $this->select()->limit($max)->get();
	}

	public function latest()
	{
		return $this->select()->orderById()->limit(1)->get();
	}

	public function count()
	{

		if (isset($this->sql)) {
			$count = count($this->results());
		} else {
			$count = $this->currentConfig['fetchMode'] == PDO::FETCH_OBJ ?
			$this->select('count(*) as c')->results()[0]->c :
			$this->select('count(*) as c')->results()[0][0];
		}

		if ($this->debug) {
			pl($count);
		}

		return $count;
	}

	public function insert(array $data)
	{
		if (func_num_args() > 1) {
			foreach (func_get_args() as $data) {
				$this->insert($data);
			}
			return $this;
		}

		$feilds = implode(',', array_keys($data));
		$values = array_values($data);
		$params = rtrim(str_repeat('?,', count($values)), ',');

		$this->value = $values;
		$this->sql = "INSERT INTO $this->table ($feilds) VALUES ($params)";
		$this->exec();

		if ($this->debug) {
			$id = $this->pdo->lastInsertId();
			pl('last inserted record');
			$this->whereId($id)->get();
		}
	}

	public function update(array $data, $id = null, $value = null)
	{
		$feilds = implode(' = ?, ', array_keys($data)) . ' = ?';
		$values = array_values($data);

		if (isset($value)) {
			array_push($values, $value);
			$this->value = $values;
			$this->sql = "UPDATE $this->table SET $feilds WHERE $id = ?";
		} elseif (is_numeric($id)) {
			array_push($values, $id);
			$this->value = $values;
			$this->sql = "UPDATE $this->table SET $feilds WHERE id = ?";
		} else {
			krsort($values);
			foreach ($values as $value) {
				array_unshift($this->value, $value);
			}
			$this->sql = "UPDATE $this->table SET $feilds $this->sql";
		}

		$this->exec();
	}

	public function delete($id = null, $value = null)
	{
		if (is_null($id)) {
			$this->sql = "DELETE FROM $this->table $this->sql";
		} elseif (is_array($id)) {
			$values = array_values($id);
			$params = rtrim(str_repeat('?,', count($values)), ',');
			$this->value = $values;
			$this->sql = "DELETE FROM $this->table WHERE id IN ($params)";
		} elseif (isset($value)) {
			array_push($this->value, $value);
			$this->sql = "DELETE FROM $this->table WHERE $id = ?";
		} else {
			array_push($this->value, $id);
			$this->sql = "DELETE FROM $this->table WHERE id = ?";
		}

		$this->exec();
	}

	public function find($id)
	{
		return $this->whereId($id)->get();
	}

	public function where($key, $operator = null, $value = null, $type = 'AND')
	{
		if ($key instanceof Closure) {
			return $this->whereNested($key, $type);
		}

		if (!in_array($operator, $this->operators, true)) {
			list($value, $operator) = [$operator, '='];
		}

		if (is_null($this->sql)) {
			$this->sql = "WHERE $key $operator ?";
		} elseif ($this->select) {
			$this->select = false;
			$this->sql .= " WHERE $key $operator ?";
		} else {
			$this->sql .= " $type $key $operator ?";
		}

		array_push($this->value, $value);

		return $this;
	}

	public function whereIn($key, $values = null, $type = 'AND')
	{
		if (is_array($key)) $values = $key;

		$params = rtrim(str_repeat('?, ', count($values)), ', ');

		if (is_null($this->sql)) {
			$this->sql = is_array($key) ?
			"WHERE id in ($params)" :
			"WHERE $key in ($params)";
		} elseif ($this->select) {
			$this->select = false;
			$this->sql = is_array($key) ?
			"$this->sql WHERE id in ($params)" :
			"$this->sql WHERE $key in ($params)";
		} else {
			$this->sql .=  is_array($key) ?
			" $type id in ($params)" :
			" $type $key in ($params)";
		}

		foreach ($values as $value) {
			array_push($this->value, $value);
		}

		return $this;
	}

	public function orWhere($key, $operator = null, $value = null)
	{
		return $this->where($key, $operator, $value, 'OR');
	}

	public function orWhereIn($key, $value = null)
	{
		return $this->whereIn($key, $value, 'OR');
	}

	protected function whereNested(Closure $callback, $type)
	{
		if (is_null($this->sql)) {
			call_user_func($callback, $this);
			$this->sql = str_replace('WHERE ', 'WHERE (', $this->sql) . ')';
		} else {
			$first = $this->sql;
			$len = strlen($first) + 5;

			call_user_func($callback, $this);

			$second = substr($this->sql, $len);
			$this->sql = "$first $type ($second)";
		}

		return $this;
	}

	public function union()
	{
		$this->union = $this->sql;
		return $this;
	}

	public function orderBy($key, $order = 'asc')
	{
		$this->sql .= " ORDER BY $key $order";
		return $this;
	}

	public function limit($max, $offset = 0)
	{
		$this->sql .= " LIMIT $offset, $max";
		return $this;
	}

	public function get()
	{
		$results = $this->results();

		if ($this->debug) pr($results);

		return $results;
	}

	public function column($column)
	{
		$this->sql = "SELECT $column FROM $this->table $this->sql";

		$result = $this->results();

		if ($this->debug) pr($result);

		return $result;
	}

	public function results()
	{
		if (!$this->select and preg_match('#^where#i', $this->sql)) {
			$this->sql = "SELECT * FROM $this->table $this->sql";
		}

		if (isset($this->union)) {
			$this->sql = "$this->union UNION $this->sql";
		}

		$this->log();
		$this->showDebug();
		$stmt = $this->pdo->prepare($this->sql);
		$stmt->execute($this->value);

		$results = 'no results';

		if ($stmt->rowCount()) {
			$results = $this->results = $stmt->fetchAll($this->fetchMode);
			$this->removeHiddenIfAny();
		}

		$this->reset();
		return $results;
	}

	public function filter($key, $filter)
	{
		is_array($filter) ?: $filter = [$filter];

		if (is_array($key)) {
			foreach ($this->results() as $d) {
				foreach ($key as $k) {
					$list[] = $d->$k;
				}
			}
		} else {
			foreach ($this->results() as $d) {
				$list[] = $d->$key;
			}
		}

		if ($this->debug) pr(array_diff($list, $filter));

		return array_diff($list, $filter);
	}

	public function exec()
	{
		$this->log();
		$this->showDebug();
		$this->pdo->prepare($this->sql)->execute($this->value);
		$this->reset();
	}

	public function reset()
	{
		$this->sql = null;
		$this->union = null;
		$this->value = [];
		$this->results = null;
		$this->select = false;
	}

	public function __call($method, $args)
	{
		if (preg_match('#^join#i', $method)) {
			return $this->dynamicJoin($method, $args);
		}

		if (preg_match('#^orderby#i', $method)) {
			return $this->dynamicOrderBy($method, $args);
		}

		return $this->dynamicWhere($method, $args);
	}

	protected function dynamicWhere($method, $args)
	{
		$where = 'where';
		$key = substr($method, 5);

		if (preg_match('#^orwhere#i', $method)) {
			$where = 'orWhere';
			$key = substr($method, 7);
		} elseif (preg_match('#^and#i', $method)) {
			$key = substr($method, 3);
		} elseif (preg_match('#^or#i', $method)) {
			$where = 'orWhere';
			$key = substr($method, 2);
		} elseif (!preg_match('#^where#i', $method)) {
			exit("invalid method $method");
		}

		$key = preg_replace('#([a-z])([A-Z])#', '$1_$2', $key);
		$key = strtolower($key);

		return count($args) == 2 ?
			$this->$where($key, $args[0], $args[1]) :
			$this->$where($key, $args[0]);
	}

	protected function dynamicJoin($method, $args)
	{
		$table = strtolower(substr($method, 4));

		return count($args) == 3 ?
			$this->join($table, $args[0], $args[1], $args[2]) :
			$this->join($table, $args[0], $args[1]);
	}

	protected function dynamicOrderBy($method, $args)
	{
		$key = substr($method, 7);
		$key = preg_replace('#([a-z])([A-Z])#', '$1_$2', $key);
		$key = strtolower($key);

		return count($args) == 1 ?
			$this->orderBy($key, $args[0]) :
			$this->orderBy($key);
	}

	public function truncate()
	{
		$this->pdo->exec("TRUNCATE TABLE $this->table");
	}

	public function hidden($hidden)
	{
		array_push($this->hidden, $hidden);
		return $this;
	}

	protected function removeHiddenIfAny()
	{
		if (count($this->hidden)) {
			$this->processRemoveHidden($this->hidden);
		}
	}

	protected function processRemoveHidden($hiddens)
	{
		foreach ($hiddens as $hidden) {
			foreach ($this->results as $result) {
				if (property_exists($result, $hidden)) {
					unset($result->$hidden);
				}
			}
		}
	}

	public function debug()
	{
		$this->debug = true;
		return $this;
	}

	protected function showDebug()
	{
		if ($this->debug) {
			echo "<small>debug sql: </small><b> $this->sql </b>";
			echo count($this->value) ? '<br><small>debug value: </small><b> ' . implode(', ', $this->value) . '</b>' : null;
		}
	}

	protected function log()
	{
		$this->log[] = "sql: $this->sql | value: " . implode(', ', $this->value);
	}

	public function info($output = true)
	{
		static $i = 1;

		$info = [
			'type'			 => $this->type,
			'config'		 => $this->currentConfig,
			'queryLog'		 => $this->log,
			'newConnection'  => count(static::$connection)
		];

		if ($i == count(static::$connection)) {
			$i++;
			foreach (static::$connection as $key => $value) {
				$key++;
				$key = 'connection_' . $key . '_info';
				$info[$key] = $value->info(false);
				unset($info[$key]['newConnection']);
			}
		}

		if ($output and $this->debug) {
			pr($info);
		}

		return $info;
	}
}