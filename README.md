# JSON Schema to MySQL Tables

This class converts a json-schema to a valid Sql table. Tested with MySql and Sqlite.  A big shoutout to [@WebMaestroFr](https://github.com/WebMaestroFr) for this helper.  

## PHP
The php version is getting tested. Delete tests/database/dbtest.db to start testing  
```php
// Instanciate PDO
$pdo = new \PDO("mysql:dbname=example;host=localhost;port=3306", "user", "password");
// Instanciate JSON_Schema_MySQL
$sql_schema = new \Bumip\JsonSchema\JsonSchemaSql($pdo);

// Or, generate table from a single .json file
$sql_schema->createTableFromFile("path/to/schema.json");

## Shell
will be changed to a proper cli tool in the future.
`php -q /path/to/json-schema-mysql/json-schema-mysql.php "mysql:dbname=example;host=localhost;port=3306" "user" "password" "/path/to/json/schema/directory"`


```

# CRUD Class **UNTESTED**

A CRUD class matching the DB architecture is available. **NOT TESTED**.

```php
require_once "path/to/json-schema-mysql/json-schema-crud.php";
// Instanciate PDO
$pdo = new PDO("mysql:dbname=example;host=localhost;port=3306", "user", "password");
// Instanciate JSON_Schema_MySQL
$crud = new Schema_Model($pdo, "path/to/json/schema/directory");

// Get model by id
$model = $crud->my_schema($id);
// Create model
$model = $crud->my_schema->create([
    "column_1" => "Value one",
    "column_2" => "Value two"
]);
// Get model rows
$models = $crud->my_schema->read([
    "column_1" => "First filter",
    "column_2" => "Second filter"
], [
    "order_by" => "date",
    "order"    => "DESC",
    "limit"    => 24,
    "page"     => 0
]);
// Update model
$model = $crud->my_schema->update($model->id[
    "column_1" => "New value one",
    "column_2" => "New value two"
]);
// Delete model
$crud->my_schema->delete($model->id);
```

Feel free to whatever. Contributions are welcome.
