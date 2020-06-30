<?php

namespace Signify\ORM;

use SilverStripe\ORM\ArrayList;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\Filters\SearchFilter;

/**
 * An ArrayList implementation that can be filtered using SearchFilters.
 *
 * @see \SilverStripe\ORM\ArrayList
 * @link https://docs.silverstripe.org/en/4/developer_guides/model/searchfilters/
 */
class SearchFilterableArrayList extends ArrayList {

    /**
     * Find the first item of this list where the given key = value
     * Note that search filters can also be used.
     *
     * {@inheritDoc}
     * @see \SilverStripe\ORM\ArrayList::find()
     * @link https://docs.silverstripe.org/en/4/developer_guides/model/searchfilters/
     */
    public function find($key, $value)
    {
        return $this->filter($key, $value)->first();
    }

    /**
     * Filter the list to include items with these charactaristics.
     * Note that search filters can also be used.
     *
     * {@inheritDoc}
     * @see \SilverStripe\ORM\ArrayList::filter()
     * @link https://docs.silverstripe.org/en/4/developer_guides/model/searchfilters/
     */
    public function filter()
    {
        $filters = call_user_func_array([$this, 'normaliseFilterArgs'], func_get_args());
        $linqQuery = $this->createFilteredQuery($filters);
        return new SearchFilterableArrayList($linqQuery->execute());
    }

    /**
     * Return a copy of this list which contains items matching any of these charactaristics.
     * Note that search filters can also be used.
     *
     * {@inheritDoc}
     * @see \SilverStripe\ORM\ArrayList::filterAny()
     * @link https://docs.silverstripe.org/en/4/developer_guides/model/searchfilters/
     */
    public function filterAny()
    {
        $filters = call_user_func_array([$this, 'normaliseFilterArgs'], func_get_args());
        $subQuery = $this->createFilteredQuery($filters);
        $linqQuery = new LinqDataQuery($this);
        $linqQuery->whereAny($subQuery);
        return new SearchFilterableArrayList($linqQuery->execute());
    }

    /**
     * Exclude the list to not contain items with these charactaristics
     * Note that search filters can also be used.
     *
     * {@inheritDoc}
     * @see \SilverStripe\ORM\ArrayList::exclude()
     * @link https://docs.silverstripe.org/en/4/developer_guides/model/searchfilters/
     */
    public function exclude()
    {
        $filters = call_user_func_array([$this, 'normaliseFilterArgs'], func_get_args());
        $subQuery = $this->createFilteredQuery($filters, false);
        $linqQuery = new LinqDataQuery($this);
        $linqQuery->whereAny($subQuery);
        return new SearchFilterableArrayList($linqQuery->execute());
    }

    /**
     * Exclude the list to not contain items matching any of these charactaristics
     * Note that search filters can also be used.
     *
     * @see \Signify\ORM\SearchFilterableArrayList::exclude()
     * @link https://docs.silverstripe.org/en/4/developer_guides/model/searchfilters/
     */
    public function excludeAny()
    {
        $filters = call_user_func_array([$this, 'normaliseFilterArgs'], func_get_args());
        $linqQuery = $this->createFilteredQuery($filters, false);
        return new SearchFilterableArrayList($linqQuery->execute());
    }

    /**
     * Create a linq query which has the provided filters applied.
     *
     * @param array $filters
     * @return \Signify\ORM\LinqDataQuery
     */
    protected function createFilteredQuery($filters, $inclusive = true) {
        $linqQuery = new LinqDataQuery($this);
        foreach ($filters as $filterKey => $filterData) {
            $searchFilter = $this->createSearchFilter($filterKey, $filterData);
            if ($inclusive) {
                $searchFilter->apply($linqQuery);
            } else {
                $searchFilter->exclude($linqQuery);
            }
        }
        return $linqQuery;
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

