<?php

declare(strict_types=1);

namespace Sayhey\SSMedoo;

class Model
{

    private $medooTable = null;                     // Table

    /**
     * 定义表名
     * @return string
     */
    public static function tableName(): string
    {
        return '';
    }

    /**
     * 针对表进行自定义数据库配置
     * @return array
     */
    public static function getConnectionConfig(): array
    {
        return [];
    }

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->medooTable = new Table(static::class);
    }

    /**
     * 魔术get
     * @param string $property
     * @return mixed
     */
    public function __get(string $property)
    {
        return $this->medooTable->getProperty($property);
    }

    /**
     * 魔术set
     * @param string $property
     * @param mixed $value
     * @return void
     */
    public function __set(string $property, $value): void
    {
        $this->medooTable->setProperty($property, $value);
    }

    /**
     * 保存模型
     * @param bool $reload
     * @return bool
     */
    public function save(bool $reload = false): bool
    {
        return $this->medooTable->save($reload);
    }

    /**
     * 初始化db
     * @return Table
     */
    public static function db(): Table
    {
        return (new static())->medooTable;
    }

    /**
     * 载入模型
     * @param type $value
     * @param string|null $column
     * @return self|null
     */
    public static function load($value, ?string $column = null): ?self
    {
        $model = new static();

        if (!$column) {
            $column = $model->medooTable->primaryKey();
        }

        if (!$data = $model->medooTable->where($column, $value)->first()) {
            return null;
        }

        $model->medooTable->initData($data);

        return $model;
    }

    /**
     * 载入模型通过已有数据
     * @param array $data
     * @return self
     */
    public static function loadByData(array $data): self
    {
        $model = new static();
        $model->medooTable->initData($data);
        return $model;
    }

    /**
     * 开启事务
     * @return bool
     */
    public static function beginTransaction(): bool
    {
        if (1 === ++$this->transactions) {
            return Connection::getInstance(static::getConnectionConfig())->medoo->pdo->beginTransaction();
        }
    }

    /**
     * 回滚事务
     * @return bool
     */
    public static function rollBack(): bool
    {
        if (0 === --$this->transactions) {
            return Connection::getInstance(static::getConnectionConfig())->medoo->pdo->rollBack();
        }
    }

    /**
     * 提交事务
     * @return bool
     */
    public static function commit(): bool
    {
        if (0 === --$this->transactions) {
            return Connection::getInstance(static::getConnectionConfig())->medoo->pdo->commit();
        }
    }

}

class Table extends Selector
{

    protected $table = '';      // 表名
    protected $alias = '';      // 表别名
    private $db = null;         // Connection
    private $data = [];         // 模型数据
    private $diffData = [];     // 待变更数据

    /**
     * 构造方法
     * @param string $modelClass 模型类名
     * @param string $alias 表别名
     */
    public function __construct(string $modelClass, string $alias = '')
    {
        if ('' === $this->table = $modelClass::tableName()) {
            trigger_error('tableName can not be empty', E_USER_ERROR);
        }

        $this->db = Connection::getInstance($modelClass::getConnectionConfig());
        $this->alias = $alias;
    }

    /**
     * Medoo raw
     * @param string $string
     * @param array $map
     * @return mixed
     */
    public static function raw(string $string, array $map = [])
    {
        return \Medoo\Medoo::raw($string, $map);
    }

    /**
     * 获取当前数据指定键值
     * @param string $property
     * @return type
     */
    public function getProperty(string $property)
    {
        return $this->diffData[$property] ?? $this->data[$property] ?? null;
    }

    /**
     * 设置当前数据指定键值
     * @param string $property
     * @param type $value
     * @return void
     */
    public function setProperty(string $property, $value): void
    {
        if (!array_key_exists($property, $this->data) || $this->data[$property] !== $value) {
            $this->diffData[$property] = $value;
        }
    }

    /**
     * 设置表别名
     * @param string $alias
     * @return self
     */
    public function alias(string $alias): self
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * 返回主键列名
     * @return string
     */
    public function primaryKey(): string
    {
        return $this->db->tableSetting['primary_key'] ?: 'id';
    }

    /**
     * 插入单条，并返回ID
     * @param array $data
     * @return string|null|int
     */
    public function insert(array $data)
    {
        if (!$this->db->medoo->insert($this->table, $data)) {
            return null;
        }

        return $this->lastId();
    }

    /**
     * 插入多条，并返回影响行数
     * @param array $data
     * @return int
     */
    public function insertBatch(array $data): int
    {
        if (!$pdos = $this->db->medoo->insert($this->table, $data)) {
            return 0;
        }
        return $pdos->rowCount();
    }

    /**
     * 删除
     * @return bool
     */
    public function delete(): bool
    {
        if (!$this->getWhere()) {
            trigger_error('Delete must with condition', E_USER_ERROR);
        }

        $ret = $this->db->medoo->delete($this->table, $this->getWhere());
        if (!$ret || !$ret->rowCount()) {
            return false;
        }

        return true;
    }

    /**
     * 更新，并返回影响行数
     * @param array $data
     * @return int
     */
    public function update($data): int
    {
        if (!$data) {
            return 0;
        }

        if (!$ret = $this->db->medoo->update($this->table, $data, $this->getWhere())) {
            return 0;
        }

        return $ret->rowCount();
    }

    /**
     * 查询单条
     * @return array
     */
    public function first(): array
    {
        return $this->limit(1)->all()[0] ?? [];
    }

    /**
     * 查询全部
     * @return array
     */
    public function all(): array
    {
        $select = $this->buildMedooSelectParams();

        $this->clearQueryBuilder();

        return $this->db->medoo->select(...$select);
    }

    /**
     * 查询总数，且保留查询条件
     * @return int
     */
    public function total(): int
    {
        $columns = $this->columns;
        $order = $this->order;
        $limit = $this->limit;

        $this->columns = ['count' => self::raw('COUNT(1)')];
        $this->order = [];
        $this->limit = [];

        $select = $this->buildMedooSelectParams();

        $this->columns = $columns;
        $this->order = $order;
        $this->limit = $limit;

        $data = $this->db->medoo->select(... $select);

        return intval($data[0]['count']);
    }

    /**
     * 重载模型
     * @return void
     */
    public function reload(): void
    {
        $pk = $this->primaryKey();
        if (isset($this->data[$pk])) {
            $this->clearQueryBuilder();
            $this->initData($this->select('*')->where($pk, $this->data[$pk])->first());
        }
    }

    /**
     * 初始化数据
     * @param array $data
     * @return void
     */
    public function initData(array $data): void
    {
        $this->data = $data;
        $this->diffData = [];
    }

    /**
     * 获取currData
     * @return array
     */
    public function currData(): array
    {
        return array_merge($this->data, $this->diffData);
    }

    /**
     * 获取模型修改的数据
     * @return array
     */
    public function diffData(): array
    {
        return $this->diffData;
    }

    /**
     * 新增模型前调用函数
     * @return self
     */
    public function beforeCreate(): self
    {
        $this->diffData = array_merge($this->diffData, $this->db->tableSetting['before_create'] ?: []);
        return $this;
    }

    /**
     * 更新模型前调用函数
     * @return self
     */
    public function beforeUpdate(): self
    {
        $this->diffData = array_merge($this->diffData, $this->db->tableSetting['before_update'] ?: []);
        return $this;
    }

    /**
     * 保存模型
     * @param bool $reload
     * @return bool
     */
    public function save(bool $reload = false): bool
    {
        $idKey = $this->primaryKey();

        // 无原始数据则插入
        if (!$this->data) {
            if (!$this->diffData) {
                return false;
            }

            if (!$this->db->medoo->insert($this->table, $this->diffData)) {
                return false;
            }

            $this->data = $this->diffData;
            $this->diffData = [];

            if (!$this->data[$idKey] = $this->lastId()) {
                return false;
            }

            if ($reload) {
                $this->reload();
            }

            return true;
        }

        // 有原始数据则更新
        if (!$this->diffData) {
            return false;
        }

        $ret = $this->db->medoo->update($this->table, $this->diffData, [$idKey => $this->data[$idKey]]);
        if (!$ret || !$ret->rowCount()) {
            return false;
        }

        return true;
    }

    /**
     * 返回最后插入ID
     * @return int|string|null
     */
    private function lastId()
    {
        $lastId = $this->db->medoo->id();

        if (is_numeric($lastId) && false === strpos($lastId, '.')) {
            return intval($lastId);
        }

        return $lastId;
    }

    /**
     * 获取Medoo
     * @return \Medoo\Medoo
     */
    public function medoo(): \Medoo\Medoo
    {
        return $this->db->medoo;
    }

}

class Selector
{

    protected $join = [];             // 查询联表
    protected $columns = '*';         // 查询列名
    protected $where = [];            // 查询条件
    protected $order = [];            // 查询排序
    protected $group = [];            // 查询分组
    protected $having = [];           // 查询过滤
    protected $limit = [];            // 查询限制
    protected $selectIndex = 0;       // Medoo复合查询索引

    /**
     * 清空查询构造器
     * @return void
     */
    public function clearQueryBuilder(): void
    {
        $this->join = [];
        $this->columns = '*';
        $this->where = [];
        $this->order = [];
        $this->group = [];
        $this->having = [];
        $this->limit = [];
        $this->selectIndex = 0;
    }

    /**
     * 查询字段
     * @param string|array $columns
     * @return self
     */
    public function select(... $columns): self
    {
        $columnArr = [];
        foreach ($columns as $column) {
            if (is_array($column)) {
                $columnArr = array_merge($columnArr, $column);
            } else {
                $columnArr[] = $column;
            }
        }

        if (empty($columnArr) || ['*'] === $columnArr) {
            $this->columns = '*';
        } else {
            $this->columns = $columnArr;
        }

        return $this;
    }

    /**
     * 添加查询条件
     * @param string $tag
     * @param string $column
     * @param mixed $value
     * @return self
     */
    private function addWhere(string $tag, string $column, $value): self
    {
        if ('AND' === $tag || 'OR' === $tag) {
            $this->where[$tag . ' #' . (++$this->selectIndex)] = $value;
        } else {
            $this->where[$column . $tag] = $value;
        }

        return $this;
    }

    /**
     * 等于/包含/为NULL
     * @param string $column
     * @param string|number|array|null $value
     * @return self
     */
    public function where(string $column, $value): self
    {
        return $this->addWhere('', $column, $value);
    }

    /**
     * 不等于/不包含/不为NULL
     * @param string $column
     * @param string|number|array|null $value
     * @return self
     */
    public function whereNot(string $column, $value): self
    {
        return $this->addWhere('[!]', $column, $value);
    }

    /**
     * 大于
     * @param string $column
     * @param string|number $value
     * @return self
     */
    public function whereGT(string $column, $value): self
    {
        return $this->addWhere('[>]', $column, $value);
    }

    /**
     * 大于等于
     * @param string $column
     * @param string|number $value
     * @return self
     */
    public function whereGE(string $column, $value): self
    {
        return $this->addWhere('[>=]', $column, $value);
    }

    /**
     * 小于
     * @param string $column
     * @param string|number $value
     * @return self
     */
    public function whereLT(string $column, $value): self
    {
        return $this->addWhere('[<]', $column, $value);
    }

    /**
     * 小于等于
     * @param string $column
     * @param string|number $value
     * @return self
     */
    public function whereLE(string $column, $value): self
    {
        return $this->addWhere('[<=]', $column, $value);
    }

    /**
     * 介于两者之间
     * @param string $column
     * @return self
     */
    public function whereBetween(string $column, $valueLeft, $valueRight): self
    {
        return $this->addWhere('[<>]', $column, [$valueLeft, $valueRight]);
    }

    /**
     * 在两者之外
     * @param string $column
     * @return self
     */
    public function whereNotBetween(string $column, $valueLeft, $valueRight): self
    {
        return $this->addWhere('[><]', $column, [$valueLeft, $valueRight]);
    }

    /**
     * 相似
     * @param string $column
     * @return self
     */
    public function whereLike(string $column, string $value): self
    {
        return $this->addWhere('[~]', $column, $value);
    }

    /**
     * 不相似
     * @param string $column
     * @return self
     */
    public function whereNotLike(string $column, string $value): self
    {
        return $this->addWhere('[!~]', $column, $value);
    }

    /**
     * 复合查询AND
     * @param callable $func
     * @return $this
     */
    public function whereAnd(callable $func): self
    {
        $new = new static($this->table, $this->alias);

        $func($new);

        $where = $new->getWhere();

        unset($new);

        return $this->addWhere('AND', '', $where);
    }

    /**
     * 复合查询OR
     * @param callable $func
     * @return $this
     */
    public function whereOr(callable $func): self
    {
        $new = new self($this->table, $this->alias);

        $func($new);

        $where = $new->getWhere();

        unset($new);

        return $this->addWhere('OR', '', $where);
    }

    /**
     * 排序
     * @param string|array $column
     * @param string $sort
     * @return self
     */
    public function orderBy($column, $sort = 'ASC'): self
    {
        if (is_array($column)) {
            $this->order = array_merge($this->order, $column);
        } else {
            $this->order[$column] = $sort;
        }

        return $this;
    }

    /**
     * 限制条数
     * @param int $limit
     * @param int $offset
     * @return self
     */
    public function limit(int $limit, int $offset = 0): self
    {
        $this->limit = [$offset, $limit];

        return $this;
    }

    /**
     * 分组
     * @param string|array $group
     * @return self
     */
    public function groupBy($group): self
    {
        $this->group = $group;
        return $this;
    }

    /**
     * 筛选
     * @param callable $func
     * @return array
     */
    public function having(callable $func): array
    {
        $new = new self($this->table, $this->alias);

        $func($new);

        $where = $new->where;

        unset($new);

        return $this->having = $where;
    }

    /**
     * 添加一个联表
     * @param string $tag
     * @param string $modelClass
     * @param string $alias
     * @param callable $func
     * @return self
     */
    private function addJoin(string $tag, string $modelClass, string $alias, callable $func): self
    {
        $new = new self($this->table, $this->alias);

        $func($new);

        $where = $new->getWhere();

        unset($new);

        $this->join[$tag . $modelClass::tableName() . '(' . $alias . ')'] = $where;

        return $this;
    }

    /**
     * 添加一个左联表
     * @param string $modelClass
     * @param string $alias
     * @param callable $func
     * @return self
     */
    public function leftJoin(string $modelClass, string $alias, callable $func): self
    {
        return $this->addJoin('[>]', $modelClass, $alias, $func);
    }

    /**
     * 添加一个右联表
     * @param string $modelClass
     * @param string $alias
     * @param callable $func
     * @return self
     */
    public function rightJoin(string $modelClass, string $alias, callable $func): self
    {
        return $this->addJoin('[<]', $modelClass, $alias, $func);
    }

    /**
     * 添加一个全联表
     * @param string $modelClass
     * @param string $alias
     * @param callable $func
     * @return self
     */
    public function fullJoin(string $modelClass, string $alias, callable $func): self
    {
        return $this->addJoin('[<>]', $modelClass, $alias, $func);
    }

    /**
     * 添加一个内联表
     * @param string $modelClass
     * @param string $alias
     * @param callable $func
     * @return self
     */
    public function innerJoin(string $modelClass, string $alias, callable $func): self
    {
        return $this->addJoin('[><]', $modelClass, $alias, $func);
    }

    /**
     * 获取查询条件
     * @return array
     */
    public function getWhere(): array
    {
        $where = $this->where;

        if (isset($where['LIMIT'])) {
            $where['.LIMIT'] = $where['LIMIT'];
            unset($where['LIMIT']);
        }

        if (isset($where['ORDER'])) {
            $where['.ORDER'] = $where['ORDER'];
            unset($where['ORDER']);
        }

        if (isset($where['GROUP'])) {
            $where['.GROUP'] = $where['GROUP'];
            unset($where['GROUP']);
        }

        if (isset($where['HAVING'])) {
            $where['.HAVING'] = $where['HAVING'];
            unset($where['HAVING']);
        }

        return $where;
    }

    /**
     * 构建查询条件
     * @return array
     */
    public function buildMedooSelectParams(): array
    {
        if ('' === $this->alias) {
            $select = [$this->table];
        } else {
            $select = [$this->table . '(' . $this->alias . ')'];
        }

        if (!empty($this->join)) {
            $select[] = $this->join;
        }

        $select[] = $this->columns ?? '*';

        $where = $this->getWhere();

        if (!empty($this->limit)) {
            $where['LIMIT'] = $this->limit[0] > 0 ? $this->limit : $this->limit[1];
        }
        if (!empty($this->order)) {
            $where['ORDER'] = $this->order;
        }
        if (!empty($this->group)) {
            $where['GROUP'] = $this->group;
        }
        if (!empty($this->having)) {
            $where['HAVING'] = $this->having;
        }

        $select[] = $where;

        return $select;
    }

}

class Connection
{

    public static $connectionMap = [];      // Connection实例映射
    public static $defaultHash = '';        // Connection默认实例索引
    public static $medooMap = [];           // Medoo实例映射
    public $medoo = null;                   // Medoo实例
    public $tableSetting = [];              // 自定义表配置
    public $hash = '';                      // 配置hash
    public $medooHash = '';                 // Medoo配置hash

    /**
     * 构造方法
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->hash = md5(json_encode($config));

        $this->tableSetting = $config['table_setting'] ?? [];
        unset($config['table_setting']);

        $medooHash = md5(json_encode($config));
        if (!isset(self::$medooMap[$medooHash])) {
            self::$medooMap[$medooHash] = new Medoo\Medoo($config);
        }

        $this->medoo = self::$medooMap[$medooHash];
    }

    /**
     * 初始化默认数据库连接
     * @param array $config
     * @return void
     */
    public static function init(array $config): void
    {
        if (!!self::$defaultHash) {
            trigger_error('Connection repet init', E_USER_ERROR);
        }

        self::$defaultHash = md5(json_encode($config));

        Connection::$connectionMap[self::$defaultHash] = new Connection($config);
    }

    /**
     * 获取实例
     * @return Connection
     */
    public static function getInstance(array $config = []): Connection
    {
        if (!self::$defaultHash) {
            trigger_error('Connection not init', E_USER_ERROR);
        }

        if (!$config) {
            return Connection::$connectionMap[self::$defaultHash];
        }

        $hash = md5(json_encode($config));
        foreach (Connection::$instances as $instance) {
            if ($instance->hash === $hash) {
                return $instance;
            }
        }

        Connection::$instances[] = new Connection($config);

        return end(Connection::$instances);
    }

}
