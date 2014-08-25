PDOWrapper
=======================

Use commonly-used PDO statements easily and avoid repetition.

Author:  Mike Rodarte

Created: 2014-08-25


Included Files
====================
The files included in this package are required for proper usage.
PDOWrapper.php - Main PHP file
Log.php - Log class (used for logging)
helpers.php - miscellaneous PHP functions and constants

Basic Usage
====================
$myquery = new PDOWrapper('mysql', 'localhost', 'user', 'pass', 'database', 'assoc');
$myquery->logLevel(2); // set log level to show warnings and errors
$query = 'SELECT * FROM some_table WHERE id = ?';
$rows = $myquery->select($query, 15);
// do something with $rows

$insert_id = $myquery->insert('some_table', array('first' => 'Bob', 'last' => 'Dylan', 'phone' => '8005551212'));
$updated = $myquery->update('some_table', array('phone' => '2135551234'), array('id' => $insert_id));
