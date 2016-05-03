<?php

abstract class DbModel
{
    protected static $table;
    protected static $conn;
    protected static $primaryKey = 'id';

    public static function setConnection(PDO $conn)
    {
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$conn = $conn;
    }


    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    public static function query($value='')
    {
        return new QueryBuilder(self::$conn, [self::table() => '*'], static::class);
    }

    public static function dbName()
    {
        return self::$conn->query('select database()')->fetchColumn();
    }

    public static function table()
    {
        $currentClass = get_called_class();
        return $currentClass::$table;
    }

    public static function idColumn()
    {
        $currentClass = get_called_class();
        return $currentClass::$primaryKey;
    }

    public function id()
    {
        return isset($this->{self::idColumn()}) ? $this->{self::idColumn()} : null;
    }

    public function getColumnNames()
    {
        // Works ONLY for MySQL
        $sql = 'select column_name from information_schema.columns where table_schema="'.self::dbName().'" and table_name="'.self::table().'"';
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

    public static function create(array $attributes)
    {
        $currentClass = get_called_class();
        $new_object = new $currentClass;
        $new_object->fill($attributes)->save();
        return $new_object;
    }

    public function update(array $attributes)
    {
        $this->fill($attributes)->save();
        return $this;
    }

    public function delete()
    {
        $sql = sprintf("DELETE FROM `%s` WHERE `%s` = ?", self::table(), self::idColumn());
        self::$conn->prepare($sql)->execute([$this->id()]);
        $this->{self::idColumn()} = null;
    }

    // TODO: custom setters/getters
    public function fill(array $attributes)
    {
        foreach ($attributes as $attribute => $value) {
            $this->$attribute = $value;
        }
        return $this;
    }

    public function save()
    {
        // TODO: automatically update timestamps
        if ($this->isNew()) {
            $this->_insertQuery();
        }
        else {
            $this->_updateQuery();
        }
        return $this;
    }

    public function isNew()
    {
        return empty($this->id());
    }

    protected function _insertQuery()
    {
        $attributes = $this->toArray();
        unset($attributes[self::idColumn()]);

        $columns = [];
        $bindings = [];
        $values = [];
        foreach ($attributes as $column => $value) {
            $columns[] = sprintf('`%s`', $column);
            $bindings[] = "?";
            $values[] = $value;
        }

        $sql = sprintf("INSERT INTO `%s` (%s) VALUES (%s)", self::table(), join(', ', $columns), join(', ', $bindings));
        self::$conn->prepare($sql)->execute($values);
        $this->{self::idColumn()} = self::$conn->lastInsertId();
    }

    protected function _updateQuery()
    {
        $attributes = $this->toArray();
        unset($attributes[self::idColumn()]);

        $updates = [];
        $values = [];
        foreach ($attributes as $column => $value) {
            $updates[] = sprintf('`%s` = ?', $column);
            $values[] = $value;
        }
        $values[] = $this->id();

        $sql = sprintf("UPDATE `%s` SET %s WHERE `%s` = ?", self::table(), join(', ', $updates), self::idColumn());
        self::$conn->prepare($sql)->execute($values);
    }

    public function hasMany($slaveClass, $slaveForeignKey, $localKey)
    {
        // TODO: automatically guess $slaveForeignKey and $localKey (guess standard column names)
        $localTable = self::table();
        $slaveTable = $slaveClass::table();
        $query = new QueryBuilder(self::$conn, [$slaveTable => '*', $localTable => array()], $slaveClass);
        return $query->where("`$slaveTable`.`$slaveForeignKey` = `$localTable`.`$localKey` AND `$localTable`.`$localKey` = ?", [$this->id()]);
    }

    public function belongsTo($masterClass, $localKey, $masterForeignKey)
    {
        // TODO: automatically guess $masterForeignKey and $localKey (guess standard column names)
        $localTable = self::table();
        $masterTable = $masterClass::table();
        $query = new QueryBuilder(self::$conn, [$masterTable => '*', $localTable => array()], $masterClass);
        return $query->where("`$masterTable`.`$masterForeignKey` = `$localTable`.`$localKey` AND `$localTable`.`$localKey` = ?", [$this->{$localKey}]);
    }
}

class QueryBuilder
{
    protected $conn, $query, $bindings;

    public function __construct($conn, $columnsToSelect, $resultClass = null)
    {
        $this->conn = $conn;

        $_columns = [];
        foreach ($columnsToSelect as $table => $columns) {
            if ($columns == '*') {
                $_columns[] = "`$table`.*";
            }
            else {
                foreach ($columns as $column) {
                    $_columns[] = "`$table`.`$column`";
                }
            }
        }
        $this->query = "select " . join(',',$_columns) . " from ".join(',',array_keys($columnsToSelect));
        $this->resultClass = empty($resultClass) ? 'stdClass' : $resultClass;
    }

    public function all()
    {
        $statement = $this->conn->prepare($this->query);
        $statement->execute($this->bindings);
        return $statement->fetchAll(PDO::FETCH_CLASS, $this->resultClass);
    }

    /* TODO: find method whic simply searches by id */
    /* TODO: findOrFail method */

    public function first()
    {
        $results = $this->limit(1)->all();
        return $results ? $results[0] : null;
    }

    public function limit($limit)
    {
        $this->query .= " LIMIT 1";
        return $this;
    }

    /* TODO: allow multiple WHERE's */
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

    public function user()
    {
        return $this->belongsTo('User', 'user_id', 'id');
    }
}

class User extends DbModel
{
    protected static $table = 'users';

    public function posts()
    {
        return $this->hasMany('Post', 'user_id', 'id');
    }
}

$connection_string = "mysql:host=localhost;dbname=test_db";
$user = 'root';
$password = '';

$conn = new PDO($connection_string, $user, $password);
$conn->exec("
    DROP TABLE IF EXISTS `users`;
    CREATE TABLE `users` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(255) NOT NULL,
        PRIMARY KEY (`id`)
    );

    DROP TABLE IF EXISTS `posts`;
    CREATE TABLE `posts` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `user_id` INT(11) NULL DEFAULT NULL,
        `title` TEXT NULL,
        `body` TEXT NULL,
        PRIMARY KEY (`id`),
        INDEX `FK_users_posts` (`user_id`),
        CONSTRAINT `FK_posts_posts` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
    );
");

DbModel::setConnection($conn);

$user1 = User::create(['name' => 'Peter']);
$user2 = User::create(['name' => 'Bob']);

/* general stuff demo */

$p = new Post;
$p->title = 'title 1';
$p->body = 'body 1';
$p->user_id = $user1->id();
$p->save();
echo "Inserted post with id ".$p->id()."\n";

$p1 = Post::query()->first();
$p1->title = 'Changed title 1';
$p1->save();
echo "Updated post ".$p1->id()."\n";

$p2 = Post::create(['user_id' => $user1->id(), 'title' => 'title 2', 'body' => 'body 2']);
$p2->update(['title' => 'title 2 - updated']);

$p3 = new Post(['user_id' => $user2->id(), 'title' => 'title 3', 'body' => 'body 3']);
$p3->save();
echo "Inserted post with id ".$p3->id()."\n";

$p3 = Post::query()->first();
$p3->delete();
echo "Deleted post with id ".$p3->id()."\n";

var_dump(Post::query()->where('id < ?', [3])->order_by('id DESC')->all());
var_dump(Post::query()->first()->toArray());

/* relations demo */
$post11 = Post::create(['user_id' => $user1->id(), 'title' => 'title 11', 'body' => 'body 11']);
$post12 = Post::create(['user_id' => $user1->id(), 'title' => 'title 12', 'body' => 'body 21']);
$post21 = Post::create(['user_id' => $user2->id(), 'title' => 'title 21', 'body' => 'body 21']);
$post22 = Post::create(['user_id' => $user2->id(), 'title' => 'title 22', 'body' => 'body 22']);

echo "Posts of user #".$user1->id()." :\n";
var_dump($user1->posts()->all());

echo "Posts of user #".$user2->id()." :\n";
var_dump($user2->posts()->all());

echo "Author of post #".$post11->id()." :\n";
var_dump($post11->user()->first());

echo "Author of post #".$post21->id()." :\n";
var_dump($post21->user()->first());