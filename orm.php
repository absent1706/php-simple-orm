<?php

abstract class DbModel
{
    protected static $table;
    protected static $conn;
    protected static $primaryKey = 'id';

    public static function setConnection(PDO $conn)
    {
        self::$conn = $conn;
    }

    public static function query($value='')
    {
        return new QueryBuilder(self::$conn, self::getTableName(), static::class);
    }

    public static function getTableName()
    {
        $currentClass = get_called_class();
        return $currentClass::$table;
    }

    public static function getPrimaryKey()
    {
        $currentClass = get_called_class();
        return $currentClass::$primaryKey;
    }

    public function getColumnNames()
    {
        $sql = 'select column_name from information_schema.columns where table_name="'.self::getTableName().'"';
        $stmt = self::$conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function toArray()
    {
        $columns = self::getColumnNames();
        $result = [];
        foreach ($columns as $column) {
            $result[$column] = isset($this->{$column}) ? $this->{$column} : null;
        }

        return $result;
    }

    public function save()
    {
        $array = $this->toArray();
        unset($array[self::getPrimaryKey()]);

        if ($this->isNew()) {
            $columns = [];
            $bindings = [];
            $values = [];
            foreach ($array as $column => $value) {
                $columns[] = sprintf('`%s`', $column);
                $bindings[] = "?";
                $values[] = $value;
            }
            $sql = sprintf("INSERT INTO `%s` (%s) VALUES (%s)", self::getTableName(), join(', ', $columns), join(', ', $bindings));
            self::$conn->prepare($sql)->execute($values);
            $this->{self::getPrimaryKey()} = self::$conn->lastInsertId();
        }
        else {
            $updates = [];
            $values = [];
            foreach ($array as $column => $value) {
                $updates[] = sprintf('`%s` = ?', $column);
                $values[] = $value;
            }
            $values[] = $this->id();

            $sql = sprintf("UPDATE `%s` SET %s WHERE `%s` = ?", self::getTableName(), join(', ', $updates), self::getPrimaryKey());
            self::$conn->prepare($sql)->execute($values);
        }
    }

    public function isNew()
    {
        return empty($this->id());
    }

    public function id()
    {
        return $this->{self::getPrimaryKey()};
    }

    public function __get($attribute)
    {
        return null;
    }
}

class QueryBuilder
{
    protected $conn, $query, $bindings;

    public function __construct($conn, $table, $resultClass = null)
    {
        $this->conn = $conn;
        $this->query = "select * from $table";
        $this->resultClass = empty($resultClass) ? 'stdClass' : $resultClass;
    }

    public function all()
    {
        $statement = $this->conn->prepare($this->query);
        $statement->execute($this->bindings);
        return $statement->fetchAll(PDO::FETCH_CLASS, $this->resultClass);
    }

    public function first()
    {
        return $this->limit(1)->all()[0];
    }

    public function limit($limit)
    {
        $this->query .= " LIMIT 1";
        return $this;
    }

    public function where($condition, $bindings)
    {
        $this->query .= " WHERE $condition";
        $this->bindings = $bindings;
        return $this;
    }

    public function order_by($order)
    {
        $this->query .= " ORDER BY $order";
        return $this;
    }
}

/* ===================== DEMO ===================== */
class Post extends DbModel
{
    protected static $table = 'posts';
}

$connection_string = "mysql:host=localhost;dbname=test";
$user = 'root';
$password = '';

$conn = new PDO($connection_string, $user, $password);
$conn->exec("
    DROP TABLE IF EXISTS posts;
    CREATE TABLE `posts` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `title` TEXT NULL,
        `body` TEXT NULL,
        PRIMARY KEY (`id`)
    );

    INSERT INTO posts (id, title, body) VALUES (1, 'post 1', 'body 1');
    INSERT INTO posts (id, title, body) VALUES (2, 'post 2', 'body 2');
    INSERT INTO posts (id, title, body) VALUES (3, 'post 3', 'body 3');
    INSERT INTO posts (id, title, body) VALUES (4, 'post 4', 'body 4');
");

DbModel::setConnection($conn);

var_dump(Post::getColumnNames());
var_dump(Post::query()->where('id < ?', [3])->order_by('id DESC')->all());
var_dump(Post::query()->first()->toArray());

$p = new Post;
$p->title='new title';
$p->body='new body';
$p->save();
echo "Inserted post with id ".$p->id()."\n";

$p2 = Post::query()->first();
$p2->title = 'Changed title 1';
$p2->save();
echo "Updated post ".$p2->id();
