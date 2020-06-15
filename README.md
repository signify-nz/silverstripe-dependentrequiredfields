# Dependent Required Fields

The Dependent Required Fields module provides a validator which extends RequiredFields and allows for fields to be required based on the values of other fields.

SearchFilters are used to provide a variety of ways to compare values, depending on what causes the fields to be required.
See [SilverStripe's documentation on SearchFilters](https://docs.silverstripe.org/en/4/developer_guides/model/searchfilters/) for more about how to use those.

## Requirements

* [SilverStripe Framework ^4](https://github.com/silverstripe/silverstripe-framework)

## Installation

__Composer:__
```
    composer require signify-nz/silverstripe-dependentrequiredfields
```

## Example Usage
In the below example, we have fields with various levels of dependency on whether they are required or not.  
`AlwaysRequiredField` will be required regardless of the values of other fields.  
`ExactValueField` will only be required if the value of `DependencyField` exactly equals `someExactValue`.  
`StartsWithField` will only be required if the value of `DependencyField` starts with the string `some`.  
```php
<?php
    public function getCMSValidator() {
        return DependentRequiredFields::create([
            'AlwaysRequiredField',
            'ExactValueField' => ['DependencyField' => 'someExactValue'],
            'StartsWithField' => ['DependencyField:StartsWith' => 'some'],
        ]);
    }
```

Additional methods are also provided to add or remove dependently required fields. Note that the field `AlwaysRequiredField` from the above example cannot be added or removed using these methods; the `addRequiredField` and `removeRequiredField` fields provided by RequiredField should be used instead.
```php
$validator->addDependentRequiredField('GreaterThanField', ['DependencyField:GreaterThan' => '10']);
$validator->removeDependentRequiredField('ExactValueField');
```