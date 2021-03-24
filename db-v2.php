<?php
class nothing {
  public function __call($method, $params) {
    return $this;
  }
}

class database {
  public    $runMode         = null;
  protected $table           = null;
  protected $incrementCollum = 'Id';

  protected $connection      = null;
  protected $statement       = null;
  protected $sql             = null;

  protected $limit           = 10;
  protected $offset          = 0;

  protected $wheres          = [];
  protected $selects         = [];

  public    $operators       = [ '=', '!=', '<', '>', '<=', '>=', 'OR', 'LIKE', 'AND' ];
  public    $dataTypes       = [ 'i', 'd', 's', 'b' ];
  public    $collum          = [];
  public    $data;

  public function __construct($config, $mode = 1)
  {
    $this->connection = new mysqli($config['host'], $config['user'], $config['pass'], $config['name']);
    $this->runMode = $mode == 1 ? 'production' : 'development';
  }

  public function invalidOperator($operator)
  {
    return !in_array(strtolower($operator), $this->operators, true);
  }

  public function invalidId($id)
  {
    return gettype($id) === "array";
  }

  public function showErrors()
  {
    if ($this->runMode == 'development')
      exit($this->connection->error);
    else
      return new nothing();
  }

  public function flush()
  {
    $this->table     = null;
    $this->where     = null;
    $this->selects   = null;
    $this->sql       = null;
    $this->statement = null;
    $this->data      = null;
  }

  public function table($table)
  {
    $this->table = $table;

    return $this;
  }

  public function setIncrementCollum($name)
  {
    $this->IncrementCollum = $name;

    return $this;
  }

  public function select(...$rows)
  {
    $this->selects = $rows;

    return $this;
  }

  public function where($collum, $operator = null, $value = null, $type = 's', $boolean = 'AND')
  {

    if ( $this->invalidOperator($operator) )
      [ $value, $operator ] = [ $operator, '=' ];

    $this->wheres[] = [
      'collum' => $collum,
      'operator' => $operator,
      'value' => $value,
      'type' => $type,
      'bollean' => $boolean
    ];

    return $this;
  }

  public function orWhere($collum, $operator = null, $value = null, $type = 's')
  {
    return $this->where($collum, $operator, $value, $type, 'OR');
  }

  public function createSetFields (array $data)
  {
    $params = [];
    foreach ($data as $key => $param) {
      $params[] = "$key = ?";
    }
    return implode(', ', $params);
  }

  public function buildSelectQuery()
  {
    $select  = !empty($this->selects) ? implode(', ', $this->selects) : '*';
    $this->sql = "SELECT $select FROM $this->table";
  }

  public function buildUpdateQuery()
  {
    $setFields = $this->createSetFields($this->data);
    $this->sql = "UPDATE $this->table SET $setFields";
  }

  public function buildSqlQuery($method = 'SELECT')
  {
    $wheres  = [];
    $types   = [];
    $values  = [];

    if ($method == 'SELECT')
      $this->buildSelectQuery();

    if ($method == 'UPDATE') {
      $this->buildUpdateQuery();
      $this->data = array_values($this->data);
      $types[] = str_repeat('s', count($this->data));
    }

    if ($this->wheres) {
      foreach ($this->wheres as $arr) {
        [ $wheres[], $values[], $types[] ] = [
          " {$arr['bollean']} {$arr['collum']} {$arr['operator']} ?",
          $arr['value'],
          $arr['type']
        ];

      }

      $this->sql .= " WHERE 1=1" . implode('', $wheres);
    }

    if ($method == 'SELECT')
      $this->sql .= " LIMIT $this->limit OFFSET $this->offset";

    return [ $this->sql, $types, $values ];
  }

  public function query()
  {
    $queryData = $this->buildSqlQuery();

    $this->statement = $this->connection->prepare($this->sql);

    if (!$this->statement)
      return $this->showErrors();

    if ($this->wheres) {
      $this->statement->bind_param(implode('', $queryData[1]), ...$queryData[2]);
    }

    $this->statement->execute();
    $result = $this->statement->get_result();
    $this->flush();

    return $result;
  }

  public function get()
  {
    $result = $this->query();
    if ( is_a($result, 'nothing') )
      return false;

    $returnData = [];
    while ( $row = $result->fetch_object() )
    {
      $returnData[] = $row;
    }

    return $returnData;
  }

  public function first ()
  {
    $result = $this->query();

    return $result->fetch_object();
  }

  public function insert($data)
  {
    $keys = array_keys($data);
    $fields = implode(', ', $keys);

    $values = array_values($data);
    $questionMarkArr = implode(', ', array_fill(0, count($data), '?'));

    $this->sql = "INSERT INTO $this->table ($fields) VALUES ($questionMarkArr)";

    $this->statement = $this->connection->prepare($this->sql);

    if (!$this->statement)
      return $this->showErrors();

    $this->statement->bind_param( str_repeat('s', count($values)), ...$values );
    $this->statement->execute();
    $this->flush();

    return $this->statement->affected_rows;
  }

  public function update($where = null, $data = null)
  {
    if ( $this->invalidId($where) )
      [ $data, $where ] = [ $where, $data ];

    $this->data = $data;
    $queryData = $this->buildSqlQuery('UPDATE');

    if ( !$this->invalidId($where) && !preg_match('/WHERE/', $this->sql) )
      $this->sql .= " WHERE $this->incrementCollum = $where";

    $this->statement = $this->connection->prepare($this->sql);

    if (!$this->statement)
      return $this->showErrors();

    $this->statement->bind_param(implode('', $queryData[1]), ...$this->data, ...$queryData[2]);

    $this->statement->execute();
    $result = $this->statement->affected_rows;
    $this->flush();

    return $result;
  }

  public function deleteRow ($Id)
  {
    $this->sql = "DELETE FROM $this->table WHERE $this->IncrementCollum = $Id";

    $this->statement = $this->connection->query($this->sql);
    $this->flush();

    return $this->statement->affected_rows;
  }
}
?>