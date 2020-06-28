<?php

namespace Signify\Tests;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\ValidationResult;
use Signify\Forms\DependentRequiredFields;

class DependentRequiredFieldsTest extends SapphireTest {

    protected $usesDatabase = false;

    protected $testFields = [
        'field1' => null,
        'field2' => null,
        'field3' => null,
        'field4' => 'value1',
        'field5' => 'value2',
        'field6' => 'value3',
        'Notrequired' => null,
    ];

    protected $dependentRequiredFields = [
        'field1' => ['field2' => null],
        'field2' => ['field4' => 'value1'],
        'field3' => ['field5:EndsWith' => 2],
        'field4' => ['field6:StartsWith' => 'value'],
    ];

    protected $standardRequiredFields = [
        'field1',
        'field4',
    ];

    /**
     * @useDatabase false
     */
    public function testValidationFromConstructor() {
        $form = $this->createForm($this->testFields, $this->dependentRequiredFields);

        $validator = $form->getValidator();

        /* @var $result ValidationResult */
        $result = $validator->validate();
        $flatResult = $this->getFlatValidationResult($result);

        // Validation should fail.
        static::assertFalse($result->isValid());

        // Errors should result from these.
        self::assertTrue($this->resultIsError($flatResult, 'field1'));
        self::assertTrue($this->resultIsError($flatResult, 'field2'));
        self::assertTrue($this->resultIsError($flatResult, 'field3'));

        // No errors should result from these.
        self::assertFalse($this->resultIsError($flatResult, 'field4'));
        self::assertFalse($this->resultIsError($flatResult, 'field5'));
        self::assertFalse($this->resultIsError($flatResult, 'field6'));
        self::assertFalse($this->resultIsError($flatResult, 'Notrequired'));
    }

    /**
     * @useDatabase false
     */
    public function testEmptyValidator() {
        $form = $this->createForm($this->testFields);
        $validator = $form->getValidator();

        /* @var $result ValidationResult */
        $result = $validator->validate();

        // Validation should not fail.
        static::assertTrue($result->isValid());
    }

    /**
     * @useDatabase false
     */
    public function testValidationAddedLater() {
        $form = $this->createForm($this->testFields);
        $validator = $form->getValidator();

        foreach ($this->dependentRequiredFields as $fieldName => $dependency) {
            $validator->addDependentRequiredField($fieldName, $dependency);
        }

        /* @var $result ValidationResult */
        $result = $validator->validate();
        $flatResult = $this->getFlatValidationResult($result);

        // Validation should fail.
        static::assertFalse($result->isValid());

        // Errors should result from these.
        self::assertTrue($this->resultIsError($flatResult, 'field1'));
        self::assertTrue($this->resultIsError($flatResult, 'field2'));
        self::assertTrue($this->resultIsError($flatResult, 'field3'));

        // No errors should result from these.
        self::assertFalse($this->resultIsError($flatResult, 'field4'));
        self::assertFalse($this->resultIsError($flatResult, 'field5'));
        self::assertFalse($this->resultIsError($flatResult, 'field6'));
        self::assertFalse($this->resultIsError($flatResult, 'Notrequired'));
    }

    /**
     * @useDatabase false
     */
    public function testValidationRemoved() {
        $form = $this->createForm($this->testFields, $this->dependentRequiredFields);
        $validator = $form->getValidator();

        foreach ($this->dependentRequiredFields as $fieldName => $dependency) {
            $validator->removeDependentRequiredField($fieldName);
        }

        /* @var $result ValidationResult */
        $result = $validator->validate();

        // Validation should not fail.
        static::assertTrue($result->isValid());
    }

    /**
     * @useDatabase false
     */
    public function testRequiredFieldsWithoutDependencies() {
        $form = $this->createForm($this->testFields, $this->standardRequiredFields);
        $validator = $form->getValidator();

        /* @var $result ValidationResult */
        $result = $validator->validate();
        $flatResult = $this->getFlatValidationResult($result);

        // Validation should fail.
        static::assertFalse($result->isValid());

        // Errors should result from these.
        self::assertTrue($this->resultIsError($flatResult, 'field1'));

        // No errors should result from these.
        self::assertFalse($this->resultIsError($flatResult, 'field2'));
        self::assertFalse($this->resultIsError($flatResult, 'field3'));
        self::assertFalse($this->resultIsError($flatResult, 'field4'));
        self::assertFalse($this->resultIsError($flatResult, 'field5'));
        self::assertFalse($this->resultIsError($flatResult, 'field6'));
        self::assertFalse($this->resultIsError($flatResult, 'Notrequired'));

        // Test pre-existing adder and remover methods.
        $validator->addRequiredField('field2');
        $validator->addRequiredField('field5');
        $validator->removeRequiredField('field1');
        $validator->removeRequiredField('field4');

        /* @var $result ValidationResult */
        $result = $validator->validate();
        $flatResult = $this->getFlatValidationResult($result);

        // Validation should fail.
        static::assertFalse($result->isValid());

        // Errors should result from these.
        self::assertTrue($this->resultIsError($flatResult, 'field2'));

        // No errors should result from these.
        self::assertFalse($this->resultIsError($flatResult, 'field1'));
        self::assertFalse($this->resultIsError($flatResult, 'field3'));
        self::assertFalse($this->resultIsError($flatResult, 'field4'));
        self::assertFalse($this->resultIsError($flatResult, 'field5'));
        self::assertFalse($this->resultIsError($flatResult, 'field6'));
        self::assertFalse($this->resultIsError($flatResult, 'Notrequired'));
    }

    /**
     * Helper method to quickly create a form with given fields and requirements.
     *
     * @param array $fields
     * Associative array of field names to values.
     * @param array $requiredFields
     * The array of required fields which would be passed to a DependentRequiredFields constructor.
     * @return \SilverStripe\Forms\Form
     * @see DependentRequiredFields::__construct()
     */
    protected function createForm(array $fields, array $requiredFields = []) : Form {
        $fieldList = FieldList::create();
        foreach ($fields as $name => $value) {
            $fieldList->add(TextField::create($name, $name, $value));
        }

        $validator = new DependentRequiredFields($requiredFields);

        return Form::create(null, 'test', $fieldList, null, $validator);
    }

    /**
     * Get an associative array of field name to message type from a ValidationResult.
     * @param ValidationResult $result
     * @return array
     */
    protected function getFlatValidationResult(ValidationResult $result) {
        $flatResults = array();
        foreach ($result->getMessages() as $key => $metadata) {
            $flatResults[$metadata['fieldName']] = $metadata['messageType'];
        }
        return $flatResults;
    }

    /**
     * Check if a given field recieved a validation error for this validation result.
     * @param array $flatResult
     * Validation result that has been flattened by {@link self::getFlatValidationResult()}.
     * @param string $fieldName
     * Name of the field to check.
     * @return boolean
     */
    protected function resultIsError($flatResult, $fieldName) {
        return isset($flatResult[$fieldName]) && $flatResult[$fieldName] === 'required';
    }

}

