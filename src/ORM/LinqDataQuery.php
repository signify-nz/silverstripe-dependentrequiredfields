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

    public function where($filter) {
        foreach ($filter as $key => $value) {
            if (!is_array($value)) {
                $value = [$value];
            }
            $matches = [true];
            $count = 0;
            while (!empty($matches[0])) {
                preg_match($this->whereRegex, $key, $matches);
                if (empty($matches[0])) {
                    break;
                }

                $key = str_replace($matches[0], '', $key);
                if (array_key_exists($matches['operator'], $this->operatorMethods)) {
                    $closure = $this->operatorMethods[$matches['operator']];
                    $fieldName = $matches['field'];
                    $compareTo = $value[$count];
                    $this->where[] = function($obj) use ($closure, $fieldName, $compareTo) {
                        $value = is_array($obj) ? $obj[$fieldName] : $obj->$fieldName;
                        return $closure($value, $compareTo);
                    };
                } else {
                    throw new \InvalidArgumentException("SQL Operation '{$matches['operator']}' not supported.");
                }

                $count++;
            }

        }
    }

    /**
     * Execute the query and return the result as {@link SS_Query} object.
     *
     * @return ArrayList
     */
    public function execute()
    {
        $list = Enumerable::from($this->getSource());

        foreach ($this->where as $closure) {
            $list = $list->where($closure);
        }
        return new SearchFilterableArrayList($list->toArray());
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

