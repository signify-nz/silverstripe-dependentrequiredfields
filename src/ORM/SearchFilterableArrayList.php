<?php

namespace Signify\ORM;

use SilverStripe\ORM\ArrayList;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\Filters\SearchFilter;

class SearchFilterableArrayList extends ArrayList {

    /**
     * Filter the list to include items with these charactaristics.
     * Note that search filters can also be used. See {@link https://docs.silverstripe.org/en/4/developer_guides/model/searchfilters/}
     *
     * @return ArrayList
     * @see SS_List::filter()
     * @example $list->filter('Name', 'bob'); // only bob in the list
     * @example $list->filter('Name', array('aziz', 'bob'); // aziz and bob in list
     * @example $list->filter(array('Name'=>'bob, 'Age'=>21)); // bob with the Age 21 in list
     * @example $list->filter(array('Name'=>'bob, 'Age'=>array(21, 43))); // bob with the Age 21 or 43
     * @example $list->filter(array('Name'=>array('aziz','bob'), 'Age'=>array(21, 43)));
     *          // aziz with the age 21 or 43 and bob with the Age 21 or 43
     */
    public function filter()
    {
        $linqQuery = new LinqDataQuery($this);
        $filters = call_user_func_array([$this, 'normaliseFilterArgs'], func_get_args());
        foreach ($filters as $filterKey => $filterData) {
            $searchFilter = $this->createSearchFilter($filterKey, $filterData);
            $searchFilter->apply($linqQuery);
        }
        return new SearchFilterableArrayList($linqQuery->execute());
    }

    /**
     * Given a filter expression and value construct a {@see SearchFilter} instance
     *
     * @param string $filter E.g. `Name:ExactMatch:not`, `Name:ExactMatch`, `Name:not`, `Name`
     * @param mixed $value Value of the filter
     * @return SearchFilter
     * @see \SilverStripe\ORM\DataList::createSearchFilter
     */
    protected function createSearchFilter($filter, $value)
    {
        // Field name is always the first component
        $fieldArgs = explode(':', $filter);
        $fieldName = array_shift($fieldArgs);

        // Inspect type of second argument to determine context
        $secondArg = array_shift($fieldArgs);
        $modifiers = $fieldArgs;
        if (!$secondArg) {
            // Use default filter if none specified. E.g. `->filter(['Name' => $myname])`
            $filterServiceName = 'DataListFilter.default';
        } else {
            // The presence of a second argument is by default ambiguous; We need to query
            // Whether this is a valid modifier on the default filter, or a filter itself.
            /** @var SearchFilter $defaultFilterInstance */
            $defaultFilterInstance = Injector::inst()->get('DataListFilter.default');
            if (in_array(strtolower($secondArg), $defaultFilterInstance->getSupportedModifiers())) {
                // Treat second (and any subsequent) argument as modifiers, using default filter
                $filterServiceName = 'DataListFilter.default';
                array_unshift($modifiers, $secondArg);
            } else {
                // Second argument isn't a valid modifier, so assume is filter identifier
                $filterServiceName = "DataListFilter.{$secondArg}";
            }
        }

        // Build instance
        return Injector::inst()->create($filterServiceName, $fieldName, $value, $modifiers);
    }

}

