<?php
/**
 * JSON Schema MySQL database builder.
 *
 * Create MySQL tables from JSON Schema files.
 *
 * @author Étienne BAUDRY  <etienne@webmaestro.fr>
 */

// Check if shell arguments are given
if (!empty($argv)) {
    // Exclude file path
    array_shift($argv);
    // Use three first arguments to instanciate PDO (dsn, username, password)
    var_dump($argv);
    $pdo = new PDO(array_shift($argv), array_shift($argv), array_shift($argv));
    // Create instance of converter
    $sql_schema = new SQL_Schema($pdo);
    // For each following arguments (schema.json, ...)
    foreach ($argv as $file) {
        // Create MySQL table (if not exists)
        $sql_schema->create_table($file);
    }
}

class SQL_Schema
{
    private $pdo,
        // Record of relations between tables
        $references = array(),
        // Record of relative paths
        $dirnames = array();

    public function __construct($pdo)
    {
        // Set PDO Instance
        $this->pdo = $pdo;
    }

    public function create_table($file, $name = null)
    {
        if (null === $name) {
            // Default name to file basename
            $name = basename($file, '.json');
        }
        // Check file
        if (file_exists($file)
            // Get JSON string
            && ($json = file_get_contents($file))
            // Decode JSON
            && ($schema = json_decode($json))
        ) {
            // Add dirname to the record
            $this->dirnames[$name] = dirname($file);
            // Open a reference record
            $this->references[$name] = array();
            // Get SQL columns definitions
            $definitions = $this->get_definitions($name, $schema);
            // Start query string
            $sql = "CREATE TABLE IF NOT EXISTS {$name} ("
                // Add columns
.implode(', ', $definitions)
            // Close
.')';
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

        return;
    }

    private function create_reference($property)
    {
        // Check for reference
        if (property_exists($property, '$ref')
            && ((filter_var($property->{'$ref'}, FILTER_VALIDATE_URL)
                    // If remote, fetch raw URL
                    && ($json = file_get_contents($property->{'$ref'}))
                ) || (preg_match('/^([^\.]+)\.json$/', $property->{'$ref'}, $matches)
                    // If local file, fetch relative from dirname
                    && ($file = "{$this->dirnames[$name]}/{$property->{'$ref'}}")
                    && (file_exists($file))
                    && ($json = file_get_contents($file))
                    // Default name is file basename
                    && ($name = $matches[1])
                ))
        ) {
            // Decode JSON
            $ref = json_decode($json);
            // Merge properties, current > fetched
            $property = self::merge_recursive_distinct($ref, $property);
            // Create fetched object table (recursive)
            $this->create_table($property, $name);
            // Record SQL relation table
            $this->references[$table][] = "CREATE TABLE IF NOT EXISTS {$table}_{$name} ("
                ."{$table}_id INTEGER NOT NULL, "
                ."{$name}_id INTEGER NOT NULL, "
                ."PRIMARY KEY ({$table}_id, {$name}_id), "
                ."FOREIGN KEY ({$table}_id) REFERENCES {$table}(id) ON DELETE CASCADE, "
                ."FOREIGN KEY ({$name}_id) REFERENCES {$name}(id) ON DELETE CASCADE"
            .')';
            // Return current
            return $property;
        }

        return;
    }

    private function get_definitions($table_name, stdClass $schema)
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
            if ($this->create_reference($property)) {
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
