<?php

use Delight\Db\PdoDatabase;

// Include the Delight-IM/db package
require_once __DIR__ . '/../vendor/autoload.php';

// Get the table name from the user input
$tableName = readline('Enter table name: ');

// Connect to the database using the PDO driver
$db = new PdoDatabase('mysql:host=localhost;dbname=my_database;charset=utf8mb4', 'my_username', 'my_password');

// Get the column names and types for the specified table
$columnData = $db->select('DESCRIBE ' . $tableName);

// Create the class name based on the table name (e.g. "users" -> "User")
$className = ucwords($tableName, '_');

// Generate the PHP code for the CRUD model based on the column data
$modelCode = <<<PHP
namespace App\Models;

use Delight\Db\PdoDatabase;

class $className
{
    private PdoDatabase \$db;

    public function __construct(PdoDatabase \$db)
    {
        \$this->db = \$db;
    }

    public function getAll$className()
    {
        return \$this->db->select('SELECT * FROM $tableName');
    }

    public function get$classNameById(\$id)
    {
        return \$this->db->select('SELECT * FROM $tableName WHERE id = ?', [\$id])->fetch();
    }

    public function create$className(${
        implode(', ', array_map(function ($column) {
            return '$' . $column['Field'];
        }, $columnData))
    })
    {
        ${implode("\n        ", array_map(function ($column) {
            return '$' . $column['Field'] . ' = $this->db->quote($' . $column['Field'] . ');';
        }, $columnData))}

        \$this->db->insert('INSERT INTO $tableName (${implode(', ', array_map(function ($column) {
            return $column['Field'];
        }, $columnData))}) VALUES (${implode(', ', array_map(function ($column) {
            return '$' . $column['Field'];
        }, $columnData))})');

        return \$this->db->lastInsertId();
    }

    public function update$className(\$id${implode(', ', array_map(function ($column) {
        return ', $' . $column['Field'];
    }, $columnData))})
    {
        ${implode("\n        ", array_map(function ($column) {
            return '$' . $column['Field'] . ' = $this->db->quote($' . $column['Field'] . ');';
        }, $columnData))}

        \$this->db->update('UPDATE $tableName SET ${implode(', ', array_map(function ($column) {
            return $column['Field'] . ' = $' . $column['Field'];
        }, $columnData))} WHERE id = ?', array_merge([\$id], array_map(function ($column) {
            return '$' . $column['Field'];
        }, $columnData)));

        return true;
    }

    public function delete$className(\$id)
    {
        \$this->db->delete('DELETE FROM $tableName WHERE id = ?', [\$id]);

        return true;
    }
}
PHP;

// Save the generated PHP code to a file
file_put_contents(__DIR__ . "/app/Models/$className.php", $modelCode);

// Output a success message
echo "CRUD model for table '$tableName' generated successfully.\n";
