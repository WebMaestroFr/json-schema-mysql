<?php
/**
 * JSON Schema MySQL database builder.
 *
 * Create MySQL tables from JSON Schema files.
 * php -q /json-schema-mysql/schema.php "mysql:dbname=database;host=localhost;port=3306" "username" "password" "/schemas"
 *
 * @author Étienne BAUDRY  <etienne@webmaestro.fr>
 */
 require_once 'json-schema-crud.php';

// Check if shell arguments are given
if (!empty($argv) && count($argv) === 5) {
    // Exclude first (file path) argument
    array_shift($argv);
    // Use second (dsn), third (username) and fourth (password) arguments to instanciate PDO
    $pdo = new PDO(array_shift($argv), array_shift($argv), array_shift($argv));
    // Create instance of converter
    $sql_schema = new JSON_Schema_MySQL($pdo);
    // Fifth argument as source directory
    $sql_schema->create_tables_from_dir(array_shift($argv));
}

class JSON_Schema_MySQL
{
    private $pdo,
        // Record of relations between tables
        $references = array(),
        // Record of file path
        $dirname = '';

    public function __construct($pdo)
    {
        // Set PDO Instance
        $this->pdo = $pdo;
    }

    public function create_tables_from_dir($directory)
    {
        if (is_dir($directory)) {
            // $this->dirname = dirname($directory);
            $schema_files = glob(rtrim($directory, '/').'/*.json');
            foreach ($schema_files as $file) {
                // Create MySQL table (if not exists)
                $this->create_table_from_file($file);
            }
        }
    }

    public function create_table_from_file($file, $name = null)
    {
        if (null === $name) {
            // Default name to file basename
            $name = basename($file, '.json');
        }
        $this->dirname = dirname($file);
        // Check file
        if (file_exists($file)
            // Get JSON string
            && ($json = file_get_contents($file))
            // Decode JSON
            && ($schema = json_decode($json))
        ) {
            $this->create_table($schema, $name);
        }
    }

    public function create_table($schema, $name)
    {
        if (!property_exists($schema, 'type') || 'object' !== $schema->type) {
            // Only object type should be creating tables
            return;
        }
        // Open a reference record
        $this->references[$name] = array();
        // Get SQL columns definitions
        $definitions = $this->get_definitions($schema, $name);
        // Start query string
        $sql = "CREATE TABLE IF NOT EXISTS {$name} (".implode(', ', $definitions).')';
        // Add comment if any title/description
        if ($comment = $this->get_comment($schema)) {
            $sql .= " COMMENT {$comment}";
        }
        // Execute SQL
        $this->pdo->exec($sql);
        // If any reference was recorded...
        foreach ($this->references[$name] as $sql) {
            // ... execute SQL to create relation table
            $this->pdo->exec($sql);
        }
        // Return schema object
        return $schema;
    }

    private function get_definitions(stdClass $schema, $table_name)
    {
        // Default id and date columns
        $default_properties = array(
            'id' => array('type' => 'integer'),
            'date' => array('type' => 'date'),
        );
        $properties = property_exists($schema, 'properties')
            // Merge properties, current > default
            ? self::merge_recursive_distinct($default_properties, $schema->properties)
            : $default_properties;

        // Required fields
        $default_required = array_keys($default_properties);
        $required = property_exists($schema, 'required')
            // Merge required
            ? array_merge($default_required, $schema->required)
            : $default_required;

        // Return map of each property (column)
        return array_filter(array_map(function ($name, $property) use ($table_name, $required) {
            if (empty($property)) {
                // Next if empty
                return;
            }
            // Deep conversion to object
            $property = json_decode(json_encode($property));
            // Check if reference
            if ($this->create_reference($table_name, $property)) {
                // All sorted out, next !
                return;
            }
            // Get property's type
            $type = $this->get_type($table_name, $name, $property);
            // Start SQL string
            $sql = "{$name} {$type}";
            // Check if "not null" applies
            if (in_array($name, $required)
                || (property_exists($property, 'required') && $property->required)
                || (property_exists($property, 'minItems') && $property->minItems > 0)
            ) {
                $sql .= ' NOT NULL';
            }
            switch ($name) {
                case 'id':
                    // Id has to be our primary key
                    $sql .= ' PRIMARY KEY AUTO_INCREMENT UNIQUE';
                    break;
                case 'date':
                    // Date has to be our timestamp
                    $sql .= ' DEFAULT CURRENT_TIMESTAMP';
                    break;
                default:
                    // Check regular column for default
                    if (property_exists($property, 'default')) {
                        $default = $this->pdo->quote($property->default);
                        $sql .= " DEFAULT {$default}";
                    }
            }
            // Add comment if any title/description
            if ($comment = $this->get_comment($property)) {
                $sql .= " COMMENT {$comment}";
            }
            // Map column definition SQL
            return $sql;
        }, array_keys((array) $properties), (array) $properties));
    }

    private function create_reference($table, $property)
    {
        // Check for reference
        if ($schema = self::get_reference($property)) {
            // Merge properties, current > fetched
            $name = basename($property->{'$ref'}, '.json');
            // Create fetched object table (recursive)
            $this->create_table($schema, $name);
            // Record SQL relation table
            $this->references[$table][] = "CREATE TABLE IF NOT EXISTS {$table}_{$name} ("
                ."{$table}_id INTEGER NOT NULL, "
                ."{$name}_id INTEGER NOT NULL, "
                ."PRIMARY KEY ({$table}_id, {$name}_id), "
                ."INDEX ({$table}_id), "
                ."FOREIGN KEY ({$table}_id) REFERENCES {$table}(id) ON DELETE CASCADE, "
                ."FOREIGN KEY ({$name}_id) REFERENCES {$name}(id) ON DELETE CASCADE"
            .')';
            // Return current
            return $schema;
        }

        return;
    }

    private function get_type($table_name, $property_name, stdClass $property)
    {
        // If enum property...
        if (property_exists($property, 'enum')) {
            // ... return ENUM type, how convenient
            return 'ENUM ('
                .implode(', ', array_map(function ($enum_item) {
                    return $this->pdo->quote($enum_item);
                }, $property->enum))
            .')';
        }
        // Check type and switch
        if (property_exists($property, 'type')) {
            // Return MySQL equivalent
            switch ($property->type) {
                case 'number':
                    return 'DECIMAL';
                case 'date':
                    return 'TIMESTAMP';
                case 'integer':
                case 'boolean':
                    // "Regular" type
                    return strtoupper($property->type);
                case 'array':
                case 'object':
                    // return 'JSON'; Doesn't seem to work...
            }
        }
        // Return text by default
        return 'TEXT';
    }

    private static function get_reference($property)
    {
        if (property_exists($property, '$ref')) {
            if (filter_var($property->{'$ref'}, FILTER_VALIDATE_URL)) {
                $file = $property->{'$ref'};
            } elseif (preg_match('/^([^\.]+)\.json$/', $property->{'$ref'})) {
                $file = "{$this->dirname}/{$property->{'$ref'}}";
            } else {
                return;
            }
            if ($json = @file_get_contents($file)) {
                $ref = json_decode($json);

                return self::merge_recursive_distinct($ref, $property);
            }
        }

        return;
    }

    private function get_comment(stdClass $schema)
    {
        $comment = array();
        // Check if title
        if (property_exists($schema, 'title')) {
            $comment['title'] = $schema->title;
        }
        // Check if description
        if (property_exists($schema, 'description')) {
            $comment['description'] = $schema->description;
        }
        // Return comments found separated with " - "
        return empty($comment)
            ? null
            : $this->pdo->quote(implode(' - ', $comment));
    }

    public static function merge_recursive_distinct()
    {
        $arrays = func_get_args();
        $merged = array_shift($arrays);
        if ($return_object = is_object($merged)) {
            $merged = json_decode(json_encode($merged), true);
        }
        foreach ($arrays as $array) {
            if (is_object($array)) {
                $array = json_decode(json_encode($array), true);
            }
            foreach ($array as $key => $value) {
                if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                    $merged[$key] = self::merge_recursive_distinct($merged[$key], $value);
                } else {
                    $merged[$key] = $value;
                }
            }
        }

        return $return_object
            ? json_decode(json_encode($merged), false)
            : $merged;
    }
}
