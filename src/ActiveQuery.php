<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace app\components\yql;

use yii\base\InvalidConfigException;

/**
 * ActiveQuery represents a DB query associated with an Active Record class.
 *
 * An ActiveQuery can be a normal query or be used in a relational context.
 *
 * ActiveQuery instances are usually created by [[ActiveRecord::find()]] and [[ActiveRecord::findBySql()]].
 * Relational queries are created by [[ActiveRecord::hasOne()]] and [[ActiveRecord::hasMany()]].
 *
 * Normal Query
 * ------------
 *
 * ActiveQuery mainly provides the following methods to retrieve the query results:
 *
 * - [[one()]]: returns a single record populated with the first row of data.
 * - [[all()]]: returns all records based on the query results.
 * - [[count()]]: returns the number of records.
 * - [[sum()]]: returns the sum over the specified column.
 * - [[average()]]: returns the average over the specified column.
 * - [[min()]]: returns the min over the specified column.
 * - [[max()]]: returns the max over the specified column.
 * - [[scalar()]]: returns the value of the first column in the first row of the query result.
 * - [[column()]]: returns the value of the first column in the query result.
 * - [[exists()]]: returns a value indicating whether the query result has data or not.
 *
 * Because ActiveQuery extends from [[Query]], one can use query methods, such as [[where()]],
 * [[orderBy()]] to customize the query options.
 *
 * ActiveQuery also provides the following additional query options:
 *
 * - [[with()]]: list of relations that this query should be performed with.
 * - [[joinWith()]]: reuse a relation query definition to add a join to a query.
 * - [[indexBy()]]: the name of the column by which the query result should be indexed.
 * - [[asArray()]]: whether to return each record as an array.
 *
 * These options can be configured using methods of the same name. For example:
 *
 * ```php
 * $customers = Customer::find()->with('orders')->asArray()->all();
 * ```
 *
 * Relational query
 * ----------------
 *
 * In relational context ActiveQuery represents a relation between two Active Record classes.
 *
 * Relational ActiveQuery instances are usually created by calling [[ActiveRecord::hasOne()]] and
 * [[ActiveRecord::hasMany()]]. An Active Record class declares a relation by defining
 * a getter method which calls one of the above methods and returns the created ActiveQuery object.
 *
 * A relation is specified by [[link]] which represents the association between columns
 * of different tables; and the multiplicity of the relation is indicated by [[multiple]].
 *
 * If a relation involves a junction table, it may be specified by [[via()]] or [[viaTable()]] method.
 * These methods may only be called in a relational context. Same is true for [[inverseOf()]], which
 * marks a relation as inverse of another relation and [[onCondition()]] which adds a condition that
 * is to be added to relational query join condition.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @author Carsten Brandt <mail@cebe.cc>
 * @since 2.0
 */
class ActiveQuery extends Query
{

    /**
     * @event Event an event that is triggered when the query is initialized via [[init()]].
     */
    const EVENT_INIT = 'init';

    /**
     * @var string the SQL statement to be executed for retrieving AR records.
     * This is set by [[ActiveRecord::findBySql()]].
     */
    public $yql;
    /**
     * @var string the name of the ActiveRecord class.
     */
    public $modelClass;
    /**
     * @var boolean whether to return each record as an array. If false (default), an object
     * of [[modelClass]] will be created to represent each record.
     */
    public $asArray;


    /**
     * Constructor.
     * @param string $modelClass the model class associated with this query
     * @param array $config configurations to be applied to the newly created query object
     */
    public function __construct($modelClass, $config = [])
    {
        $this->modelClass = $modelClass;
        parent::__construct($config);
    }

    /**
     * Initializes the object.
     * This method is called at the end of the constructor. The default implementation will trigger
     * an [[EVENT_INIT]] event. If you override this method, make sure you call the parent implementation at the end
     * to ensure triggering of the event.
     */
    public function init()
    {
        parent::init();
        $this->trigger(self::EVENT_INIT);
    }

    /**
     * Executes query and returns all results as an array.
     * @param Connection $db the DB connection used to create the DB command.
     * If null, the DB connection returned by [[modelClass]] will be used.
     * @return array|ActiveRecord[] the query results. If the query results in nothing, an empty array will be returned.
     */
    public function all($db = null)
    {
        $results = parent::all($db);
        if(count($results) == 0) {
            return array();
        }
        $modelClass = $this->modelClass;
        return $results->{$modelClass::resultParam()};
    }

    /**
     * @inheritdoc
     */
    public function prepare($builder)
    {
        if (empty($this->from)) {
            /* @var $modelClass ActiveRecord */
            $modelClass = $this->modelClass;
            $this->from = $modelClass::tableName();
        }

        if (empty($this->select)) {
            $this->select = ["*"];
        }

        return Query::create($this);
    }

    /**
     * @inheritdoc
     */
    public function populate($rows)
    {
        if (empty($rows)) {
            return [];
        }

        $models = $this->createModels($rows);
        if (!$this->asArray) {
            foreach ($models as $model) {
                $model->afterFind();
            }
        }

        return $models;
    }

    /**
     * Executes query and returns a single row of result.
     * @param Connection $db the DB connection used to create the DB command.
     * If null, the DB connection returned by [[modelClass]] will be used.
     * @return ActiveRecord|array|null a single row of query result. Depending on the setting of [[asArray]],
     * the query result may be either an array or an ActiveRecord object. Null will be returned
     * if the query results in nothing.
     */
    public function one($db = null)
    {
        $row = parent::one($db);
        if ($row !== false) {
            $models = $this->populate([$row]);
            return reset($models) ?: null;
        } else {
            return null;
        }
    }

    /**
     * Creates a DB command that can be used to execute this query.
     * @param Connection $db the DB connection used to create the DB command.
     * If null, the DB connection returned by [[modelClass]] will be used.
     * @return Command the created DB command instance.
     */
    public function createCommand($db = null)
    {
        /* @var $modelClass ActiveRecord */
        $modelClass = $this->modelClass;
        if ($db === null) {
            $db = $modelClass::getDb();
        }

        if ($this->yql === null) {
            list ($yql, $params) = $db->getQueryBuilder()->build($this);
        } else {
            $yql = $this->yql;
            $params = $this->params;
        }

        return $db->createCommand($yql, $params);
    }
    
    /**
     * Sets the [[asArray]] property.
     * @param boolean $value whether to return the query results in terms of arrays instead of Active Records.
     * @return $this the query object itself
     */
    public function asArray($value = true)
    {
        $this->asArray = $value;
        return $this;
    }

    /**
     * Converts found rows into model instances
     * @param array $rows
     * @return array|ActiveRecord[]
     */
    private function createModels($rows)
    {
        $models = [];
        $modelClass = $this->modelClass;
        $param = $modelClass::resultParam();
        if ($this->asArray) {   
            return $rows->{$param};
        } else {
            /* @var $class ActiveRecord */
            $class = $this->modelClass;
            foreach ($rows->{$param} as $row) {
                $model = $class::instantiate($row);
                $modelClass = get_class($model);
                $modelClass::populateRecord($model, $row);
                $models[] = $model;
            }
            return $models;
        }
    }
}
