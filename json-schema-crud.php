<?php

class Schema_Model_CRUD
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

    public function create($data)
    {
        $valid = Schema_Model::validate($this->name, $data);

        $keys = implode(', ', array_keys($valid['data']));
        $values = implode(', ', $valid['data']);

        $query = "INSERT INTO {$this->name} ({$keys}) VALUES ({$values})";

        Schema_Model::$pdo->exec($query);
        $id = (integer) Schema_Model::$pdo->lastInsertId();

        foreach ($valid['references'] as $ref_name => $ref_data) {
            Schema_Model::relation($this->name, $id, $ref_name, $ref_data);
        }

        return $this($id);
    }

    public function read(array $where = array(), array $clauses = array())
    {
        $keys = array_keys($where);
        $values = array_map(array(Schema_Model::$pdo, 'quote'), $where);

        $query = "SELECT * FROM {$this->name}";
        if (!empty($where)) {
            $query .= ' WHERE '
                .implode(' AND ', array_map(function ($key, $value) {
                    return "{$key} = {$value}";
                }, $keys, $values));
        }

        $order_by = empty($clauses['order_by'])
            ? 'date'
            : $clauses['order_by'];
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
            ? empty($clauses['page'])
                ? 0
                : $limit * (integer) $clauses['page']
            : (integer) $clauses['offset'];
        $query .= " OFFSET {$offset}";

        $statement = Schema_Model::$pdo->query($query);

        return $statement->fetchAll(PDO::FETCH_OBJ);
    }

    public function update($id, $data)
    {
        $valid = Schema_Model::validate($this->name, $data);

        $set = implode(', ', array_map(function ($key, $value) {
            return "{$key} = {$value}";
        }, array_keys($valid['data']), $valid['data']));

        $where = 'id = '.(integer) $id;

        $query = "UPDATE {$this->name} SET {$set} WHERE {$where}";

        Schema_Model::$pdo->exec($query);

        foreach ($valid['references'] as $ref_name => $ref_data) {
            Schema_Model::relation($this->name, (integer) $id, $ref_name, $ref_data);
        }

        return $this($id);
    }

    public function delete($id)
    {
        $where = 'id = '.(integer) $id;

        $query = "DELETE FROM {$this->name} WHERE {$where}";

        return Schema_Model::$pdo->exec($query);
    }
}

class Schema_Model
{
    public static $pdo;
    private static $directory,
        $schemas = array(),
        $tables = array(),
        $validator;

    public function __construct($pdo, $directory)
    {
        // Set PDO Instance
        self::$pdo = $pdo;
        self::$directory = rtrim($directory, '/');
    }

    public function __call($name, $args)
    {
        $crud = $this->{$name};

        return $crud((integer) array_shift($args));
    }

    public function __get($name)
    {
        return self::get_crud($name);
    }

    private static function get_crud($name)
    {
        if (empty(self::$tables[$name])) {
            self::$tables[$name] = new Schema_Model_CRUD($name);
        }

        return self::$tables[$name];
    }

    public static function relation($table, $id, $ref_name, $ref_data)
    {
        $crud = self::get_crud($ref_name);
        $reference = empty($ref_data->id)
            ? $crud->create($ref_data)
            : $crud->update($ref_data->id, $ref_data);

        $query = "INSERT INTO {$table}_{$ref_name} ({$table}_id, {$ref_name}_id)"
            ." VALUES ({$id}, {$reference->id})"
            ." ON DUPLICATE KEY UPDATE {$table}_id={$table}_id";
        self::$pdo->exec($query);
    }

    public static function validate($name, $data)
    {
        if (empty(self::$validator)) {
            require_once 'vendor/autoload.php';
            self::$validator = new JsonSchema\Validator();
        }

        if (empty(self::$schemas[$name])) {
            $schema_file = self::$directory."/{$name}.json";
            self::$schemas[$name] = json_decode(file_get_contents($schema_file), true);
            self::$schemas[$name]['$ref'] = $schema_file;
        }
        $schema = self::$schemas[$name];

        $references = array();
        if (!empty($schema['properties'])) {
            $keys = array_keys((array) $data);
            $values = array_map(function ($key, $value) use ($schema, &$references) {
                if (is_object($value) || is_array($value)) {
                    if (!empty($schema['properties'][$key])
                        && !empty($schema['properties'][$key]['$ref'])
                    ) {
                        $name = basename($schema['properties'][$key]['$ref'], '.json');
                        $references[$name] = $value;

                        return;
                    }

                    return json_encode($value);
                }

                return $value;
            }, $keys, (array) $data);
            $data = array_filter(array_combine($keys, $values));
        }

        self::$validator->check($data, $schema);

        if (!self::$validator->isValid()) {
            $errors = json_encode(self::$validator->getErrors(), JSON_PRETTY_PRINT);
            throw new Exception($errors);
        }

        return array(
            'data' => array_combine(
                array_keys((array) $data),
                array_map(array(self::$pdo, 'quote'), $data)
            ),
            'references' => $references,
        );
    }
}
