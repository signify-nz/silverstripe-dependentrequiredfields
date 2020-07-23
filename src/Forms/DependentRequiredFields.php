<?php

namespace Signify\Forms;

use SilverStripe\Forms\RequiredFields;
use SilverStripe\ORM\ArrayLib;
use Signify\ORM\SearchFilterableArrayList;

/**
 * Dependent Required Fields allows you to set which fields need to be present before
 * submitting the form, as well as indicating fields which are only required if certain
 * other field value constraints are met.
 *
 * The validation provided by {@link RequiredFields} still applies if no dependencies
 * are declared for a field.
 */
class DependentRequiredFields extends RequiredFields
{

    /**
     * Associative list of fields which may be required, depending on some other values.
     *
     * Uses SearchFilters, and sets the field whose name is the key into the array as required only if
     * the value of the field it depends on matches the filter provided in the value of the array.
     *
     * @var array
     */
    protected $dependentRequired;

    /**
     * Pass an array with the required fields as keys and their dependency arrays as values.
     * Dependency arrays should have the name of the field upon which it is dependent (and any SearchFilter modifiers)
     * as a key, and the value to compare against as the value. Multiple filters are supported.
     *
     * example:<br>
     * // 'AlwaysRequiredField' will be required regardless of other fields.<br>
     * // 'ExactValueField' will be required only if the value of 'DependencyField' is exactly 'someExactValue'<br>
     * // 'StartsWithField' will be required only if the value of 'DependencyField' starts with the string 'some'<br>
     * DependentRequiredFields::create([<br>
     *   'AlwaysRequiredField',<br>
     *   'ExactValueField' => ['DependencyField' => 'someExactValue'],<br>
     *   'StartsWithField' => ['DependencyField:StartsWith' => 'some'],<br>
     * ]);
     *
     * @link https://docs.silverstripe.org/en/4/developer_guides/model/searchfilters/
     */
    public function __construct()
    {
        parent::__construct([]);
        $args = func_get_args();
        if (isset($args[0]) && is_array($args[0])) {
            $args = $args[0];
        }
        $this->dependentRequired = array();
        if (!empty($args)) {
            if (is_array($args)) {
                foreach ($args as $key => $value) {
                    if (is_numeric($key)) {
                        $this->required[$value] = $value;
                    } else {
                        $this->dependentRequired[$key] = $value;
                    }
                }
            } else {
                $this->required = ArrayLib::valuekey([$args]);
            }
        }
    }

    public function php($data)
    {
        $valid = parent::php($data);
        $fields = $this->form->Fields();

        foreach ($this->dependentRequired as $fieldName => $filter) {
            $isRequired = true;
            foreach ($filter as $filterKey => $filterData) {
                // Field is already not required, no need to re-process.
                if (!$isRequired) {
                    break;
                }
                $dependencyFieldName = explode(':', $filterKey)[0];
                $dependencyValue = isset($data[$dependencyFieldName]) ? $data[$dependencyFieldName] : null;
                $tempObj = new \stdClass();
                $tempObj->$dependencyFieldName = $dependencyValue;
                $filterList = SearchFilterableArrayList::create([$tempObj]);
                $isRequired = $filterList->filter($filterKey, $filterData)->count() !== 0;
                if ($fieldName != 'AdditionalAttendants') {
//                     var_dump([$isRequired, $tempObj, $filterData, $filterKey]);die();
                }
            }

            // Field is required but has no value
            if ($isRequired && empty($data[$fieldName])) {
                $formField = $fields->dataFieldByName($fieldName);
                $title = ($formField && !empty($formField->Title())) ? $formField->Title() : $fieldName;
                $errorMessage = _t(
                    'SilverStripe\\Forms\\Form.FIELDISREQUIRED',
                    '{name} is required',
                    [
                        'name' => strip_tags(
                            '"' . $title . '"'
                        )
                    ]
                );
                $this->validationError(
                    $fieldName,
                    $errorMessage,
                    "required"
                );
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     *
     * @param string $field
     * Name of the field to add as a dependent required field.
     * @param array $dependency
     * A valid SearchFilter array.
     * <p>
     * example:<br>
     * // 'StartsWithField' will be required only if the value of 'DependencyField' starts with the string 'some'<br>
     * addDependentRequiredField(<br>
     *   'StartsWithField', ['DependencyField:StartsWith' => 'some'],<br>
     * );
     * </p>
     *
     * @link https://docs.silverstripe.org/en/4/developer_guides/model/searchfilters/
     * @return $this
     */
    public function addDependentRequiredField($field, $dependency)
    {
        $this->dependentRequired[$field] = $dependency;

        return $this;
    }

    /**
     * Removes a required field
     *
     * @param string $field
     * Name of the field to add as a dependent required field.
     *
     * @return $this
     */
    public function removeDependentRequiredField($field)
    {
        unset($this->dependentRequired[$field]);

        return $this;
    }
}
