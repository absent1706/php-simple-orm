<?php

abstract class DbModel
{
    protected static $table;
    protected static $conn;
    protected $primaryKey = 'id';

    public static function setConnection (PDO $conn)
    {
        self::$conn = $conn;
    }

    public static function query($value='')
    {
        $currentClass = get_called_class();

        return new QueryBuilder(self::$conn, $currentClass::$table, static::class);
    }
}

class QueryBuilder
{
    public function __construct($conn, $table, $resultClass = null){
        $this->conn = $conn;
        $this->query = "select * from $table";
        $this->resultClass = empty($resultClass) ? 'stdClass' : $resultClass;
    }

    public function all()
    {
        return $this->conn->query($this->query)->fetchAll(PDO::FETCH_CLASS, $this->resultClass);
    }


}

/* ===================== DEMO ===================== */
class Post extends DbModel
{
    protected static $table = 'posts';
}

$connection_string = 'sqlite::memory:';
// $connection_string = "mysql:host=localhost;dbname=test";
$user = 'root';
$password = '';

$conn = new PDO($connection_string, $user, $password);
$conn->exec("
    DROP TABLE IF EXISTS posts;
    CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, body TEXT);
    INSERT INTO posts (id, title, body) VALUES (1, 'post 1', 'body 1');
    INSERT INTO posts (id, title, body) VALUES (2, 'post 2', 'body 2');
    INSERT INTO posts (id, title, body) VALUES (3, 'post 3', 'body 3');
    INSERT INTO posts (id, title, body) VALUES (4, 'post 4', 'body 4');
");

DbModel::setConnection($conn);
var_dump(Post::query()->all());