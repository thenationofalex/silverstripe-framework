<?php

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;

/**
 * Base class for filtering implementations,
 * which work together with {@link SearchContext}
 * to create or amend a query for {@link DataObject} instances.
 * See {@link SearchContext} for more information.
 *
 * Each search filter must be registered in config as an "Injector" service with
 * the "DataListFilter." prefix. E.g.
 *
 * <code>
 * Injector:
 *   DataListFilter.EndsWith:
 *     class: EndsWithFilter
 * </code>
 *
 * @package framework
 * @subpackage search
 */
abstract class SearchFilter extends Object {

	/**
	 * @var string Classname of the inspected {@link DataObject}
	 */
	protected $model;

	/**
	 * @var string
	 */
	protected $name;

	/**
	 * @var string
	 */
	protected $fullName;

	/**
	 * @var mixed
	 */
	protected $value;

	/**
	 * @var array
	 */
	protected $modifiers;

	/**
	 * @var string Name of a has-one, has-many or many-many relation (not the classname).
	 * Set in the constructor as part of the name in dot-notation, and used in
	 * {@link applyRelation()}.
	 */
	protected $relation;

	/**
	 * @param string $fullName Determines the name of the field, as well as the searched database
	 *  column. Can contain a relation name in dot notation, which will automatically join
	 *  the necessary tables (e.g. "Comments.Name" to join the "Comments" has-many relationship and
	 *  search the "Name" column when applying this filter to a SiteTree class).
	 * @param mixed $value
	 * @param array $modifiers
	 */
	public function __construct($fullName = null, $value = false, array $modifiers = array()) {
		parent::__construct();
		$this->fullName = $fullName;

		// sets $this->name and $this->relation
		$this->addRelation($fullName);
		$this->value = $value;
		$this->setModifiers($modifiers);
	}

	/**
	 * Called by constructor to convert a string pathname into
	 * a well defined relationship sequence.
	 *
	 * @param string $name
	 */
	protected function addRelation($name) {
		if (strstr($name, '.')) {
			$parts = explode('.', $name);
			$this->name = array_pop($parts);
			$this->relation = $parts;
		} else {
			$this->name = $name;
		}
	}

	/**
	 * Set the root model class to be selected by this
	 * search query.
	 *
	 * @param string $className
	 */
	public function setModel($className) {
		$this->model = $className;
	}

	/**
	 * Set the current value(s) to be filtered on.
	 *
	 * @param string|array $value
	 */
	public function setValue($value) {
		$this->value = $value;
	}

	/**
	 * Accessor for the current value to be filtered on.
	 *
	 * @return string|array
	 */
	public function getValue() {
		return $this->value;
	}

	/**
	 * Set the current modifiers to apply to the filter
	 *
	 * @param array $modifiers
	 */
	public function setModifiers(array $modifiers) {
		$modifiers = array_map('strtolower', $modifiers);

		// Validate modifiers are supported
		$allowed = $this->getSupportedModifiers();
		$unsupported = array_diff($modifiers, $allowed);
		if ($unsupported) {
			throw new InvalidArgumentException(
				get_class($this) . ' does not accept ' . implode(', ', $unsupported) . ' as modifiers'
			);
		}

		$this->modifiers = $modifiers;
	}

	/**
	 * Gets supported modifiers for this filter
	 *
	 * @return array
	 */
	public function getSupportedModifiers()
	{
		// By default support 'not' as a modifier for all filters
		return ['not'];
	}

	/**
	 * Accessor for the current modifiers to apply to the filter.
	 *
	 * @return array
	 */
	public function getModifiers() {
		return $this->modifiers;
	}

	/**
	 * The original name of the field.
	 *
	 * @return string
	 */
	public function getName() {
		return $this->name;
	}

	/**
	 * @param String
	 */
	public function setName($name) {
		$this->name = $name;
	}

	/**
	 * The full name passed to the constructor,
	 * including any (optional) relations in dot notation.
	 *
	 * @return string
	 */
	public function getFullName() {
		return $this->fullName;
	}

	/**
	 * @param String
	 */
	public function setFullName($name) {
		$this->fullName = $name;
	}

	/**
	 * Normalizes the field name to table mapping.
	 *
	 * @return string
	 */
	public function getDbName() {
		// Special handler for "NULL" relations
		if($this->name === "NULL") {
			return $this->name;
		}

		// Ensure that we're dealing with a DataObject.
		if (!is_subclass_of($this->model, 'SilverStripe\\ORM\\DataObject')) {
			throw new InvalidArgumentException(
				"Model supplied to " . get_class($this) . " should be an instance of DataObject."
			);
		}

		// Find table this field belongs to
		$table = DataObject::getSchema()->tableForField($this->model, $this->name);
		if(!$table) {
			// fallback to the provided name in the event of a joined column
			// name (as the candidate class doesn't check joined records)
			$parts = explode('.', $this->fullName);
			return '"' . implode('"."', $parts) . '"';
		}

		return sprintf('"%s"."%s"', $table, $this->name);
	}

	/**
	 * Return the value of the field as processed by the DBField class
	 *
	 * @return string
	 */
	public function getDbFormattedValue() {
		// SRM: This code finds the table where the field named $this->name lives
		// Todo: move to somewhere more appropriate, such as DataMapper, the magical class-to-be?
		$candidateClass = $this->model;
		$dbField = singleton($this->model)->dbObject($this->name);
		$dbField->setValue($this->value);
		return $dbField->RAW();
	}

	/**
	 * Apply filter criteria to a SQL query.
	 *
	 * @param DataQuery $query
	 * @return DataQuery
	 */
	public function apply(DataQuery $query) {
		if(($key = array_search('not', $this->modifiers)) !== false) {
			unset($this->modifiers[$key]);
			return $this->exclude($query);
		}
		if(is_array($this->value)) {
			return $this->applyMany($query);
		} else {
			return $this->applyOne($query);
		}
	}

	/**
	 * Apply filter criteria to a SQL query with a single value.
	 *
	 * @param DataQuery $query
	 * @return DataQuery
	 */
	abstract protected function applyOne(DataQuery $query);

	/**
	 * Apply filter criteria to a SQL query with an array of values.
	 *
	 * @param DataQuery $query
	 * @return DataQuery
	 */
	protected function applyMany(DataQuery $query) {
		throw new InvalidArgumentException(get_class($this) . " can't be used to filter by a list of items.");
	}

	/**
	 * Exclude filter criteria from a SQL query.
	 *
	 * @param DataQuery $query
	 * @return DataQuery
	 */
	public function exclude(DataQuery $query) {
		if(($key = array_search('not', $this->modifiers)) !== false) {
			unset($this->modifiers[$key]);
			return $this->apply($query);
		}
		if(is_array($this->value)) {
			return $this->excludeMany($query);
		} else {
			return $this->excludeOne($query);
		}
	}

	/**
	 * Exclude filter criteria from a SQL query with a single value.
	 *
	 * @param DataQuery $query
	 * @return DataQuery
	 */
	abstract protected function excludeOne(DataQuery $query);

	/**
	 * Exclude filter criteria from a SQL query with an array of values.
	 *
	 * @param DataQuery $query
	 * @return DataQuery
	 */
	protected function excludeMany(DataQuery $query) {
		throw new InvalidArgumentException(get_class($this) . " can't be used to filter by a list of items.");
	}

	/**
	 * Determines if a field has a value,
	 * and that the filter should be applied.
	 * Relies on the field being populated with
	 * {@link setValue()}
	 *
	 * @return boolean
	 */
	public function isEmpty() {
		return false;
	}

	/**
	 * Determines case sensitivity based on {@link getModifiers()}.
	 *
	 * @return Mixed TRUE or FALSE to enforce sensitivity, NULL to use field collation.
	 */
	protected function getCaseSensitive() {
		$modifiers = $this->getModifiers();
		if(in_array('case', $modifiers)) return true;
		else if(in_array('nocase', $modifiers)) return false;
		else return null;
	}

}
