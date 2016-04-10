<?php

namespace FilipBenco\yql;

use yii\data\BaseDataProvider;
use yii\base\InvalidConfigException;
use app\components\yql\Connection;
use yii\di\Instance;

class YqlDataProvider extends BaseDataProvider {
    
    /**
     * @var Connection|array|string the DB connection object or the application component ID of the DB connection.
     * Starting from version 2.0.2, this can also be a configuration array for creating the object.
     */
    public $db = 'yql';
    /**
     * @var string the SQL statement to be used for fetching data rows.
     */
    public $yql;
    
    /**
     * @var array parameters (name=>value) to be bound to the SQL statement.
     */
    public $params = [];
    
    public $mapName;
    
    public $key = null;
    
    /**
     * Initializes the DB connection component.
     * This method will initialize the [[db]] property to make sure it refers to a valid DB connection.
     * @throws InvalidConfigException if [[db]] is invalid.
     */
    public function init()
    {
        parent::init();
        $this->db = Instance::ensure($this->db, Connection::className());
        if ($this->yql === null) {
            throw new InvalidConfigException('The "yql" property must be set.');
        }
        if ($this->mapName === null) {
            throw new InvalidConfigException('The "mapName" property must be set.');
        }
    }
    
    protected function prepareKeys($models) {
        $keys = [];
        if ($this->key !== null) {
            foreach ($models as $model) {
                if (is_string($this->key)) {
                    $keys[] = $model[$this->key];
                } else {
                    $keys[] = call_user_func($this->key, $model);
                }
            }

            return $keys;
        } else {
            return array_keys($models);
        }
    }

    protected function prepareModels() {
        $sort = $this->getSort();
        $pagination = $this->getPagination();
        if ($pagination === false && $sort === false) {
            return $this->db->createCommand($this->yql, $this->params)->query()->results->{$this->mapName};
        }
        
        $orders = [];
        $limit = $offset = null;

        if ($sort !== false) {
            $orders = $sort->getOrders();
        }

        if ($pagination !== false) {
            $pagination->totalCount = $this->getTotalCount();
            $limit = $pagination->getLimit();
            $offset = $pagination->getOffset();
        }

        $yql = $this->db->getQueryBuilder()->buildSort($this->yql, $orders);
        $yql = $this->db->getQueryBuilder()->applyLimit($yql, $limit, $offset);

        $query = $this->db->createCommand($yql, $this->params)->query();
        if($query->count == 0) {
            return array();
        }
        return $query->results->{$this->mapName};
    }

    protected function prepareTotalCount() {
        return $this->db->createCommand($this->yql, $this->params)->query()->count;
    }

}