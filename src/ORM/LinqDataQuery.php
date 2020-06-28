<?php

namespace Signify\ORM;

use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use YaLinqo\Enumerable;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\DataQueryManipulator;

class LinqDataQuery extends DataQuery {

    /**
     *
     * @var array|\Iterator|\IteratorAggregate|\Traversable|Enumerable
     */
    protected $source;

    /**
     * An array of closures to use in the final LINQ expression.
     * @var array
     */
    protected $where = array();

    /**
     * Finds the field name, operator, and placeholder for a where statement.
     *
     * Named groups are:
     * <ul><li>field - matches the field name</li>
     * <li>operator - matches the comparison operator</li>
     * <li>placeholder - matches with ? or (?)</li></ul>
     * @var string
     */
    protected $whereRegex = '/(MATCH \()?"(?<field>[a-zA-Z0-9_-]+?)"(?(1)\)|) (?<operator>[a-zA-Z<>= ]*?) (?<placeholder>(?(1)\(\?\)|\?))/';

    /**
     * An array of closure templates for where statements.
     * @var array
     */
    protected $operatorMethods = [];

    /**
     * Create a new DataQuery.
     *
     * @param array|\Iterator|\IteratorAggregate|\Traversable|Enumerable $source - a source
     * for which to construct a new query.
     * @param string $dataClass The name of the DataObject class that you wish to query
     */
    public function __construct($source = null)
    {
        $this->source = $source;

        // Set up closures to use in LINQ expressions.
        $like = function($a,$b,$i=true){
            $b = preg_quote($b);
            $b = str_replace(preg_quote('%'), '.*', $b);
            $b = str_replace(preg_quote('_'), '.', $b);
            $regex = '/^'.$b.'$/';
            if ($i) $regex.='i';
            return preg_match($regex, $a) == 1;
        };
        $in = function($a,$b){
            if (is_string($b)) {
                $b = explode(',',preg_replace('/[() ]/','',$b));
            }
            return in_array($a,$b);
        };
        $this->operatorMethods = [
            '=' => function($a,$b){return is_null($b) ? $a === $b : $a == $b;},
            '>' => function($a,$b){return $a > $b;},
            '<' => function($a,$b){return $a < $b;},
            '>=' => function($a,$b){return $a >= $b;},
            '<=' => function($a,$b){return $a <= $b;},
            '<>' => function($a,$b){return is_null($b) ? $a !== $b : $a != $b;},
            'LIKE BINARY' => function($a,$b)use($like){return $like($a,$b,false);},
            'LIKE' => $like,
            'IN' => $in,
            'AGAINST' => $like,
            'IS' => function($a,$b){return $a === $b;},
            'IS NOT' => function($a,$b){return $a !== $b;},
        ];
    }

    /**
     * Return the {@link DataObject} class that is being queried.
     *
     * @return string
     */
    public function dataClass()
    {
        // TODO try to get a class based on the contents of $this->source
        $dummyDataObject = new class extends DataObject {
            public function dbObject($fieldName) {
                return DBField::create_field('Text', '', $fieldName);
            }
        };
        return $dummyDataObject->ClassName;
    }

    /**
     * Required to ensure some SearchFilters function correctly.
     * {@inheritDoc}
     * @see \SilverStripe\ORM\DataQuery::applyRelation()
     * @see \SilverStripe\ORM\Filters\ExactMatchFilter::oneFilter
     */
    public function applyRelation($relation, $linearOnly = false) {
        return $this->dataClass();
    }

    /**
     * Get the source for this query.
     * @return array|\Iterator|\IteratorAggregate|\Traversable|Enumerable
     */
    public function getSource() {
        return $this->source;
    }

    /**
     * Set the source for this query.
     * @param array|\Iterator|\IteratorAggregate|\Traversable|Enumerable $source
     */
    public function setSource($source) {
        $this->source = $source;
    }

    /**
     * {@inheritDoc}
     * @throws \InvalidArgumentException if a given SQL statement is not supported.
     * @see \SilverStripe\ORM\DataQuery::where()
     */
    public function where($filter) {
        if (!is_array($filter)) {
            if (strpos($filter, '?') === false && strpos($filter, 'NULL') !== false) {
                $filter = str_replace('NULL', '?', $filter);
            }
            $filter = array($filter => null);
        }
        // Add where clause closures based on the provided $filter.
        foreach ($filter as $key => $value) {
            if (!is_array($value)) {
                $value = [$value];
            }
            preg_match_all($this->whereRegex, $key, $matches);
            if (empty($matches[0])) {
                continue;
            }
            for ($i = 0; $i < count($matches[0]); $i++) {
                $this->where[] = $this->prepareWhereClosure($matches['field'][$i], $matches['operator'][$i], $value[$i]);
            }

        }
        return $this;
    }

    /**
     * {@inheritDoc}
     * @throws \InvalidArgumentException if a given SQL statement is not supported.
     * @see \SilverStripe\ORM\DataQuery::whereAny()
     */
    public function whereAny($filter)
    {
        if (!is_array($filter)) {
            $filter = array($filter);
        }
        // Create an array of where clause closures based on the provided $filter.
        $whereAny = array();
        foreach ($filter as $key => $value) {
            if (!is_array($value)) {
                $value = [$value];
            }
            preg_match_all($this->whereRegex, $key, $matches);
            if (empty($matches[0])) {
                continue;
            }
            for ($i = 0; $i < count($matches[0]); $i++) {
                $whereAny[] = $this->prepareWhereClosure($matches['field'][$i], $matches['operator'][$i], $value[$i]);
            }

        }
        // Create a LINQ closure which returns true if any of the closures in $whereAny return true.
        $this->where[] = function($obj) use ($whereAny) {
            foreach ($whereAny as $closure) {
                if ($closure($obj)) {
                    return true;
                }
            }
            return false;
        };

        return $this;
    }

    /**
     * Prepare a closure to be used in a `where` LINQ query.
     * @param string $fieldName
     * @param string $operator
     * @param mixed $compareTo
     * @throws \InvalidArgumentException if a given SQL statement is not supported.
     * @return \Closure
     */
    protected function prepareWhereClosure($fieldName, $operator, $compareTo) {
        if (array_key_exists($operator, $this->operatorMethods)) {
            $closure = $this->operatorMethods[$operator];
            return function($obj) use ($closure, $fieldName, $compareTo) {
                if (is_array($obj)) {
                    if (isset($obj[$fieldName])) {
                        $value = $obj[$fieldName];
                    }
                } else if (property_exists($obj, $fieldName) || isset($obj->$fieldName)) {
                    $value = $obj->$fieldName;
                }
                if (!array_key_exists('value', get_defined_vars())) {
                    return false;
                }
                return $closure($value, $compareTo);
            };
        } else {
            throw new \InvalidArgumentException("SQL Operation '{$operator}' is not supported.");
        }
    }

    /**
     * Execute the query and return the result as an array.
     *
     * @return Array
     */
    public function execute()
    {
        return $this->getFinalisedQuery()->toArray();
    }

    /**
     * Note that $queriedColumns is not used in this context.
     * @return Enumerable
     * {@inheritDoc}
     * @see \SilverStripe\ORM\DataQuery::getFinalisedQuery()
     */
    public function getFinalisedQuery($queriedColumns = NULL) {
        $list = Enumerable::from($this->getSource());

        foreach ($this->where as $closure) {
            $list = $list->where($closure);
        }

        return $list;
    }

    /**
     * @return Enumerable
     * {@inheritDoc}
     * @see \SilverStripe\ORM\DataQuery::query()
     */
    public function query()
    {
        return $this->getFinalisedQuery();
    }
    /**
     * {@inheritDoc}
     * @see \SilverStripe\ORM\DataQuery::count()
     */
    public function count()
    {
        return $this->getFinalisedQuery()->count();
    }

    /**
     * {@inheritDoc}
     * @see \SilverStripe\ORM\DataQuery::max()
     */
    public function max($field)
    {
        return $this->getFinalisedQuery()->max(function($obj) use ($field){
            return is_array($obj) ? $obj[$field] : $obj->$field;
        });
    }

    /**
     * {@inheritDoc}
     * @see \SilverStripe\ORM\DataQuery::min()
     */
    public function min($field)
    {
        return $this->getFinalisedQuery()->min(function($obj) use ($field){
            return is_array($obj) ? $obj[$field] : $obj->$field;
        });
    }

    /**
     * {@inheritDoc}
     * @see \SilverStripe\ORM\DataQuery::avg()
     */
    public function avg($field)
    {
        return $this->getFinalisedQuery()->average(function($obj) use ($field){
            return is_array($obj) ? $obj[$field] : $obj->$field;
        });
    }

    /**
     * {@inheritDoc}
     * @see \SilverStripe\ORM\DataQuery::sum()
     */
    public function sum($field)
    {
        return $this->getFinalisedQuery()->sum(function($obj) use ($field){
            return is_array($obj) ? $obj[$field] : $obj->$field;
        });
    }

    /**
     * Not implemented as this is not required.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::removeFilterOn()
     */
    public function removeFilterOn($fieldExpression) {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not required.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::setQueriedColumns()
     */
    public function setQueriedColumns($queriedColumns)
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not required.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::aggregate()
     */
    public function aggregate($expression)
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not required.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::firstRow()
     */
    public function firstRow()
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not required.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::lastRow()
     */
    public function lastRow()
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not required.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::groupby()
     */
    public function groupby($groupby)
    {
        // TODO consider implementing this method.
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not required.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::having()
     */
    public function having($having) {
        // TODO consider implementing this method.
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not required.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::sort()
     */
    public function sort($sort = null, $direction = null, $clear = true)
    {
        // TODO consider implementing this method.
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not required.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::reverseSort()
     */
    public function reverseSort()
    {
        // TODO consider implementing this method.
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not required.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::limit()
     */
    public function limit($limit, $offset = 0)
    {
        // TODO consider implementing this method.
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not required.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::distinct()
     */
    public function distinct($value)
    {
        // TODO consider implementing this method, but consider carefully how to determine distinction.
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not required.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::distinct()
     */
    public function subtract(DataQuery $subtractQuery, $field = 'ID')
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not required.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::column()
     */
    public function column($field = 'ID')
    {
        // TODO consider implementing this method.
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not required.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::selectField()
     */
    public function selectField($fieldExpression, $alias = null)
    {
        // TODO consider implementing this method.
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not applicable.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::sql()
     */
    public function sql(&$parameters = []) {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not applicable.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::disjunctiveGroup()
     */
    public function disjunctiveGroup()
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not applicable.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::conjunctiveGroup()
     */
    public function conjunctiveGroup()
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not applicable.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::innerJoin()
     */
    public function innerJoin($table, $onClause, $alias = null, $order = 20, $parameters = array())
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not applicable.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::leftJoin()
     */
    public function leftJoin($table, $onClause, $alias = null, $order = 20, $parameters = array())
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not applicable.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::applyRelationPrefix()
     */
    public static function applyRelationPrefix($relation)
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not applicable.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::selectFromTable()
     */
    public function selectFromTable($table, $fields)
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not applicable.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::addSelectFromTable()
     */
    public function addSelectFromTable($table, $fields)
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not applicable.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::setQueryParam()
     */
    public function setQueryParam($key, $value)
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not applicable.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::getQueryParam()
     */
    public function getQueryParam($key)
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not applicable.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::getQueryParams()
     */
    public function getQueryParams()
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not applicable.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::getDataQueryManipulators()
     */
    public function getDataQueryManipulators()
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

    /**
     * Not implemented as this is not applicable.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::pushQueryManipulator()
     */
    public function pushQueryManipulator(DataQueryManipulator $manipulator)
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

}

