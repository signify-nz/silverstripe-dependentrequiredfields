<?php

namespace Signify\ORM;

use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use YaLinqo\Enumerable;

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
            '=' => function($a,$b){return $a == $b;},
            '>' => function($a,$b){return $a > $b;},
            '<' => function($a,$b){return $a < $b;},
            '>=' => function($a,$b){return $a >= $b;},
            '<=' => function($a,$b){return $a <= $b;},
            '<>' => function($a,$b){return $a != $b;},
            'LIKE BINARY' => function($a,$b)use($like){return $like($a,$b,false);},
            'LIKE' => $like,
            'IN' => $in,
            'AGAINST' => $like,
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
                if (!isset($value)) {
                    return false;
                }
                return $closure($value, $compareTo);
            };
        } else {
            throw new \InvalidArgumentException("SQL Operation '{$operator}' not supported.");
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
    }

    /**
     * Not implemented as this is not required.
     * {@inheritDoc}
     * @throws \BadMethodCallException
     * @see \SilverStripe\ORM\DataQuery::initialiseQuery()
     */
    protected function initialiseQuery($source = null)
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not implemented.');
    }

}

