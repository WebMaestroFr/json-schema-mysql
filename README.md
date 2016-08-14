# JSON Schema to MySQL Tables

## Shell
`php -q /path/to/json-schema-mysql/json-schema-mysql.php "mysql:dbname=MyDB;host=localhost;port=3306" "user" "password" "/path/to/json/schema/directory"`

## PHP
```
// Instanciate PDO
$pdo = new PDO("mysql:dbname=MyDB;host=localhost;port=3306", "user", "password");
// Instanciate JSON_Schema_MySQL
$sql_schema = new JSON_Schema_MySQL($pdo);
// Generate tables from all .json files in a directory
$sql_schema->create_tables_from_dir("/path/to/json/schema/directory");
// Or, generate table from a single .json file
$sql_schema->create_table_from_file("/path/to/schema.json");
```

Feel free to whatever. Contributions are welcome.
