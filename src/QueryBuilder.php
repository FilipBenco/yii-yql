<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace FilipBenco\yql;

use yii\base\InvalidParamException;
use yii\base\NotSupportedException;

/**
 * QueryBuilder builds a SELECT SQL statement based on the specification given as a [[Query]] object.
 *
 * SQL statements are created from [[Query]] objects using the [[build()]]-method.
 *
 * QueryBuilder is also used by [[Command]] to build SQL statements such as INSERT, UPDATE, DELETE, CREATE TABLE.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class QueryBuilder extends \yii\base\Object
{
    /**
     * The prefix for automatically generated query binding parameters.
     */
    const PARAM_PREFIX = ':qp';

    /**
     * @var Connection the database connection.
     */
    public $db;
    /**
     * @var string the separator between different fragments of a SQL statement.
     * Defaults to an empty space. This is mainly used by [[build()]] when generating a SQL statement.
     */
    public $separator = ' ';
    /**
     * @var array the abstract column types mapped to physical column types.
     * This is mainly used to support creating/modifying tables using DB-independent data type specifications.
     * Child classes should override this property to declare supported type mappings.
     */
    public $typeMap = [];

    /**
     * @var array map of query condition to builder methods.
     * These methods are used by [[buildCondition]] to build SQL conditions from array syntax.
     */
    protected $conditionBuilders = [
        'AND' => 'buildAndCondition',
        'OR' => 'buildAndCondition',
        'IN' => 'buildInCondition',
        'NOT IN' => 'buildInCondition',
        'LIKE' => 'buildLikeCondition',
        'NOT LIKE' => 'buildLikeCondition',
        'OR LIKE' => 'buildLikeCondition',
        'OR NOT LIKE' => 'buildLikeCondition',
        'MATCHES' => 'buildMatchesCondition',
        'NOT MATCHES' => 'buildMatchesCondition',
    ];


    /**
     * Constructor.
     * @param Connection $connection the database connection.
     * @param array $config name-value pairs that will be used to initialize the object properties
     */
    public function __construct($connection, $config = [])
    {
        $this->db = $connection;
        parent::__construct($config);
    }

    /**
     * Generates a SELECT SQL statement from a [[Query]] object.
     * @param Query $query the [[Query]] object from which the SQL statement will be generated.
     * @param array $params the parameters to be bound to the generated SQL statement. These parameters will
     * be included in the result with the additional parameters generated during the query building process.
     * @return array the generated SQL statement (the first array element) and the corresponding
     * parameters to be bound to the SQL statement (the second array element). The parameters returned
     * include those provided in `$params`.
     */
    public function build($query, $params = [])
    {
        $query = $query->prepare($this);

        $params = empty($params) ? $query->params : array_merge($params, $query->params);

        $clauses = [
            $this->buildSelect($query->select),
            $this->buildFrom($query->from, $query->limit, $query->offset),
            $this->buildWhere($query->where, $params),
        ];

        $yql = $this->buildSort(implode($this->separator, array_filter($clauses)),
                $query->sort);

        return [$yql, $params];
    }

    
    /**
     * @param array $columns
     * @param array $params the binding parameters to be populated
     * @param boolean $distinct
     * @param string $selectOption
     * @return string the SELECT clause built from [[Query::$select]].
     */
    public function buildSelect($columns)
    {
        $select = 'SELECT';

        if (empty($columns)) {
            return $select . ' *';
        }
        return $select . ' ' . implode(', ', $columns);
    }

    /**
     * @param string $table
     * @param array $params the binding parameters to be populated
     * @return string the FROM clause built from [[Query::$from]].
     */
    public function buildFrom($table, $limit, $offset)
    {
        if (empty($table)) {
            return '';
        }
        return 'FROM ' . $table . $this->getLimit($limit, $offset);
    }

    /**
     * @param string|array $condition
     * @param array $params the binding parameters to be populated
     * @return string the WHERE clause built from [[Query::$where]].
     */
    public function buildWhere($condition, &$params)
    {
        $where = $this->buildCondition($condition, $params);
        return $where === '' ? '' : 'WHERE ' . $where;
    }
    
    /**
     * Builds the ORDER BY and LIMIT/OFFSET clauses and appends them to the given SQL.
     * @param string $yql the existing YQL (without ORDER BY)
     * @param array $orderBy the order by columns. See [[Query::orderBy]] for more details on how to specify this parameter.
     * @return string the SQL completed with ORDER BY/LIMIT/OFFSET (if any)
     */
    public function buildSort($yql, $columns)
    {        
        if (empty($columns)) {
            return $yql;
        }
        $orders = [];
        foreach ($columns as $name => $direction) {
            $mYql = 'field="'.$this->db->quoteColumnName($name).'"';
            $mYql .= ', descending="'.(($direction == SORT_DESC)?'true':'false').'"';
            $orders[] = $mYql;
        }

        $yql .= $this->separator . '| sort(' . implode(', ', $orders) . ')';
        return $yql;
    }
    
    public function applyLimit($yql, $limit, $offset) {
        list($first, $second) = explode('FROM ', $yql, 2);
        list($table, $conditions) = explode(' ', trim($second),2);
        return $first . 'FROM ' . $table . $this->getLimit($limit, $offset) . ' ' . $conditions;
    }
    
    protected function getLimit($limit, $offset) {
        if(empty($limit)) {
            return '';
        }
        if($offset === null || $offset == 0) {
            return '('.$limit.')';
        } else if($limit !== null) {
            return '('.$limit.','.$offset.')';
        }
        return '';
    }

    /**
     * Checks to see if the given limit is effective.
     * @param mixed $limit the given limit
     * @return boolean whether the limit is effective
     */
    protected function hasLimit($limit)
    {
        return ctype_digit((string) $limit);
    }

    /**
     * Checks to see if the given offset is effective.
     * @param mixed $offset the given offset
     * @return boolean whether the offset is effective
     */
    protected function hasOffset($offset)
    {
        $offset = (string) $offset;
        return ctype_digit($offset) && $offset !== '0';
    }

    /**
     * Parses the condition specification and generates the corresponding SQL expression.
     * @param string|array|Expression $condition the condition specification. Please refer to [[Query::where()]]
     * on how to specify a condition.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     */
    public function buildCondition($condition, &$params)
    {
        if ($condition instanceof Expression) {
            foreach ($condition->params as $n => $v) {
                $params[$n] = $v;
            }
            return $condition->expression;
        } elseif (!is_array($condition)) {
            return (string) $condition;
        } elseif (empty($condition)) {
            return '';
        }

        if (isset($condition[0])) { // operator format: operator, operand 1, operand 2, ...
            $operator = strtoupper($condition[0]);
            if (isset($this->conditionBuilders[$operator])) {
                $method = $this->conditionBuilders[$operator];
            } else {
                $method = 'buildSimpleCondition';
            }
            array_shift($condition);
            return $this->$method($operator, $condition, $params);
        } else { // hash format: 'column1' => 'value1', 'column2' => 'value2', ...
            return $this->buildHashCondition($condition, $params);
        }
    }

    /**
     * Creates a condition based on column-value pairs.
     * @param array $condition the condition specification.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     */
    public function buildHashCondition($condition, &$params)
    {
        $parts = [];
        foreach ($condition as $column => $value) {
            if (is_array($value) || $value instanceof Query) {
                $parts[] = $this->buildInCondition('IN', [$column, $value], $params);
            } else {
                if ($value === null) {
                    $parts[] = "$column IS NULL";
                } else {
                    $phName = self::PARAM_PREFIX . count($params);
                    $parts[] = "$column=$phName";
                    $params[$phName] = $value;
                }
            }
        }
        return count($parts) === 1 ? $parts[0] : implode(' AND ', $parts);
    }

    /**
     * Connects two or more SQL expressions with the `AND` or `OR` operator.
     * @param string $operator the operator to use for connecting the given operands
     * @param array $operands the SQL expressions to connect.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     */
    public function buildAndCondition($operator, $operands, &$params)
    {
        $parts = [];
        foreach ($operands as $operand) {
            if (is_array($operand)) {
                $operand = $this->buildCondition($operand, $params);
            }
            if ($operand !== '') {
                $parts[] = $operand;
            }
        }
        if (!empty($parts)) {
            return implode(" $operator ", $parts);
        } else {
            return '';
        }
    }

    /**
     * Inverts an SQL expressions with `NOT` operator.
     * @param string $operator the operator to use for connecting the given operands
     * @param array $operands the SQL expressions to connect.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    public function buildNotCondition($operator, $operands, &$params)
    {
        if (count($operands) !== 1) {
            throw new InvalidParamException("Operator '$operator' requires exactly one operand.");
        }

        $operand = reset($operands);
        if (is_array($operand)) {
            $operand = $this->buildCondition($operand, $params);
        }
        if ($operand === '') {
            return '';
        }

        return "$operator ($operand)";
    }

    /**
     * Creates an SQL expressions with the `IN` operator.
     * @param string $operator the operator to use (e.g. `IN` or `NOT IN`)
     * @param array $operands the first operand is the column name. If it is an array
     * a composite IN condition will be generated.
     * The second operand is an array of values that column value should be among.
     * If it is an empty array the generated expression will be a `false` value if
     * operator is `IN` and empty if operator is `NOT IN`.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws Exception if wrong number of operands have been given.
     */
    public function buildInCondition($operator, $operands, &$params)
    {
        if (!isset($operands[0], $operands[1])) {
            throw new Exception("Operator '$operator' requires two operands.");
        }

        list($column, $values) = $operands;

        if ($values === [] || $column === []) {
            return $operator === 'IN' ? '0=1' : '';
        }

        if ($values instanceof Query) {
            return $this->buildSubqueryInCondition($operator, $column, $values, $params);
        }

        $values = (array) $values;

        if (count($column) > 1) {
            return $this->buildCompositeInCondition($operator, $column, $values, $params);
        }

        if (is_array($column)) {
            $column = reset($column);
        }
        foreach ($values as $i => $value) {
            if (is_array($value)) {
                $value = isset($value[$column]) ? $value[$column] : null;
            }
            if ($value === null) {
                $values[$i] = 'NULL';
            } elseif ($value instanceof Expression) {
                $values[$i] = $value->expression;
                foreach ($value->params as $n => $v) {
                    $params[$n] = $v;
                }
            } else {
                $phName = self::PARAM_PREFIX . count($params);
                $params[$phName] = $value;
                $values[$i] = $phName;
            }
        }
        if (strpos($column, '(') === false) {
            $column = $this->db->quoteColumnName($column);
        }

        if (count($values) > 1) {
            return "$column $operator (" . implode(', ', $values) . ')';
        } else {
            $operator = $operator === 'IN' ? '=' : '<>';
            return $column . $operator . reset($values);
        }
    }

    /**
     * Builds SQL for IN condition
     *
     * @param string $operator
     * @param array $columns
     * @param Query $values
     * @param array $params
     * @return string SQL
     */
    protected function buildSubqueryInCondition($operator, $columns, $values, &$params)
    {
        list($sql, $params) = $this->build($values, $params);
        if (is_array($columns)) {
            foreach ($columns as $i => $col) {
                if (strpos($col, '(') === false) {
                    $columns[$i] = $this->db->quoteColumnName($col);
                }
            }
            return '(' . implode(', ', $columns) . ") $operator ($sql)";
        } else {
            if (strpos($columns, '(') === false) {
                $columns = $this->db->quoteColumnName($columns);
            }
            return "$columns $operator ($sql)";
        }
    }

    /**
     * Builds SQL for IN condition
     *
     * @param string $operator
     * @param array $columns
     * @param array $values
     * @param array $params
     * @return string SQL
     */
    protected function buildCompositeInCondition($operator, $columns, $values, &$params)
    {
        $vss = [];
        foreach ($values as $value) {
            $vs = [];
            foreach ($columns as $column) {
                if (isset($value[$column])) {
                    $phName = self::PARAM_PREFIX . count($params);
                    $params[$phName] = $value[$column];
                    $vs[] = $phName;
                } else {
                    $vs[] = 'NULL';
                }
            }
            $vss[] = '(' . implode(', ', $vs) . ')';
        }
        foreach ($columns as $i => $column) {
            if (strpos($column, '(') === false) {
                $columns[$i] = $this->db->quoteColumnName($column);
            }
        }

        return '(' . implode(', ', $columns) . ") $operator (" . implode(', ', $vss) . ')';
    }

    /**
     * Creates an SQL expressions with the `LIKE` operator.
     * @param string $operator the operator to use (e.g. `LIKE`, `NOT LIKE`, `OR LIKE` or `OR NOT LIKE`)
     * @param array $operands an array of two or three operands
     *
     * - The first operand is the column name.
     * - The second operand is a single value or an array of values that column value
     *   should be compared with. If it is an empty array the generated expression will
     *   be a `false` value if operator is `LIKE` or `OR LIKE`, and empty if operator
     *   is `NOT LIKE` or `OR NOT LIKE`.
     * - An optional third operand can also be provided to specify how to escape special characters
     *   in the value(s). The operand should be an array of mappings from the special characters to their
     *   escaped counterparts. If this operand is not provided, a default escape mapping will be used.
     *   You may use `false` or an empty array to indicate the values are already escaped and no escape
     *   should be applied. Note that when using an escape mapping (or the third operand is not provided),
     *   the values will be automatically enclosed within a pair of percentage characters.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    public function buildLikeCondition($operator, $operands, &$params)
    {
        if (!isset($operands[0], $operands[1])) {
            throw new InvalidParamException("Operator '$operator' requires two operands.");
        }

        $escape = isset($operands[2]) ? $operands[2] : ['%' => '\%', '_' => '\_', '\\' => '\\\\'];
        unset($operands[2]);

        if (!preg_match('/^(AND |OR |)(((NOT |))I?LIKE)/', $operator, $matches)) {
            throw new InvalidParamException("Invalid operator '$operator'.");
        }
        $andor = ' ' . (!empty($matches[1]) ? $matches[1] : 'AND ');
        $not = !empty($matches[3]);
        $operator = $matches[2];

        list($column, $values) = $operands;

        if (!is_array($values)) {
            $values = [$values];
        }

        if (empty($values)) {
            return $not ? '' : '0=1';
        }

        if (strpos($column, '(') === false) {
            $column = $this->db->quoteColumnName($column);
        }

        $parts = [];
        foreach ($values as $value) {
            if ($value instanceof Expression) {
                foreach ($value->params as $n => $v) {
                    $params[$n] = $v;
                }
                $phName = $value->expression;
            } else {
                $phName = self::PARAM_PREFIX . count($params);
                $params[$phName] = empty($escape) ? $value : ('%' . strtr($value, $escape) . '%');
            }
            $parts[] = "$column $operator $phName";
        }

        return implode($andor, $parts);
    }

    /**
     * Creates an SQL expressions with the `EXISTS` operator.
     * @param string $operator the operator to use (e.g. `EXISTS` or `NOT EXISTS`)
     * @param array $operands contains only one element which is a [[Query]] object representing the sub-query.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws InvalidParamException if the operand is not a [[Query]] object.
     */
    public function buildMatchesCondition($operator, $operands, &$params)
    {
        if ($operands[0] instanceof Query) {
            list($sql, $params) = $this->build($operands[0], $params);
            return "$operator ($sql)";
        } else {
            throw new InvalidParamException('Subquery for EXISTS operator must be a Query object.');
        }
    }

    /**
     * Creates an SQL expressions like `"column" operator value`.
     * @param string $operator the operator to use. Anything could be used e.g. `>`, `<=`, etc.
     * @param array $operands contains two column names.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    public function buildSimpleCondition($operator, $operands, &$params)
    {
        if (count($operands) !== 2) {
            throw new InvalidParamException("Operator '$operator' requires two operands.");
        }

        list($column, $value) = $operands;

        if (strpos($column, '(') === false) {
            $column = $this->db->quoteColumnName($column);
        }

        if ($value === null) {
            return "$column $operator NULL";
        } elseif ($value instanceof Expression) {
            foreach ($value->params as $n => $v) {
                $params[$n] = $v;
            }
            return "$column $operator {$value->expression}";
        } elseif ($value instanceof Query) {
            list($sql, $params) = $this->build($value, $params);
            return "$column $operator ($sql)";
        } else {
            $phName = self::PARAM_PREFIX . count($params);
            $params[$phName] = $value;
            return "$column $operator $phName";
        }
    }
}
