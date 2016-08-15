<?php

class Model_CRUD
{
    private $name;

    public function __construct($name)
    {
        $this->name = $name;
    }

    public function __invoke($id)
    {
        $results = $this->read(array('id' => $id));

        return array_shift($results);
    }

    public function create(array $data)
    {
        $keys = implode(', ', array_map('sanitize_key', array_keys($data)));
        $values = implode(', ', array_map(array(Model::$pdo, 'quote'), $data));

        $query = "INSERT INTO {$this->name} ({$keys}) VALUES ({$values})";

        if (Model::$pdo->exec($query)) {
            return $this(Model::$pdo->lastInsertId());
        }
    }

    public function read(array $where = array(), array $clauses = array())
    {
        $keys = array_map('sanitize_key', array_keys($where));
        $values = array_map(array(Model::$pdo, 'quote'), $where);

        $query = "SELECT * FROM {$this->name}";
        if (!empty($where)) {
            $query .= ' WHERE '
                .implode(' AND ', array_map(function ($key, $value) {
                    return "{$key} = {$value}";
                }, $keys, $values));
        }

        $order_by = empty($clauses['order_by'])
            ? 'date'
            : sanitize_key($clauses['order_by']);
        $query .= " ORDER BY {$order_by} ";
        $query .= empty($clauses['order'])
            || 'd' === strtolower(substr($clauses['order'], 0, 1))
            ? 'DESC'
            : 'ASC';

        $limit = empty($clauses['limit'])
            ? 24
            : (integer) $clauses['limit'];
        $query .= " LIMIT {$limit}";

        $offset = empty($clauses['offset'])
            ? 0
            : (integer) $clauses['offset'];
        $query .= " OFFSET {$offset}";

        $statement = Model::$pdo->query($query);

        return $statement->fetchAll(PDO::FETCH_OBJ);
    }

    public function update($id, array $data = array())
    {
        $keys = array_map('sanitize_key', array_keys($data));
        $values = array_map(array(Model::$pdo, 'quote'), $data);
        $set = implode(', ', array_map(function ($key, $value) {
            return "{$key} = {$value}";
        }, $keys, $values));

        $where = 'id = '.(integer) $id;

        $query = "UPDATE {$this->name} SET {$set} WHERE {$where}";

        if (Model::$pdo->exec($query)) {
            return $this($id);
        }
    }

    public function delete($id)
    {
        $where = 'id = '.(integer) $id;

        $query = "DELETE FROM {$this->name} WHERE {$where}";

        return Model::$pdo->exec($query);
    }
}

class Model
{
    public static $pdo;
    private static $tables = array();

    public function __construct($pdo)
    {
        // Set PDO Instance
        $this->pdo = $pdo;
    }

    public function __call($name, $args)
    {
        $crud = $this->{$name};

        return $crud((integer) array_shift($args));
    }

    public function __get($name)
    {
        $name = sanitize_key($name);
        if (empty(self::$tables[$name])) {
            self::$tables[$name] = new Model_CRUD($name);
        }

        return self::$tables[$name];
    }
}
