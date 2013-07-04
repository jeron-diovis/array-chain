<?php
/**
 * @author Jeron Diovis <void.jeron.diovis@gmail.com>
 * @version 1.0
 * @license MIT license
 */

/**
 * Class ArrayHelper
 *
 * This class introduces an alternative interface for special methods, provided by {@link ArrayChain}.
 *
 * The difference is that you pass source array to be processed as first argument to each of such methods.
 *
 * It allows to perform processing without creation an instance of ArrayChain an calling 'toArray' manually.
 * Useful, if you need to perform just one processing, without chaining.
 *
 * Also, you can create a full-functional chain instance through this helper, using {@link chain} method
 *
 * @method array map(array $data, mixed $valueField, mixed $keyField = null, $preserveKeys = false)
 * @method array join(array $leftArray, array $rightArray, array $joinKeys, array $options  = array())
 * @method array group(array $data, mixed $groupFields, $byValues = true, $skipNull = true)
 * @method array invoke(array $data, string $methodName, callable $dataSource = null)
 */
class ArrayHelper extends CApplicationComponent {

	/**
	 * @var ArrayChain|null
	 */
	protected $_processor = null;

	/**
	 * @var string[] List of {@link ArrayChain} methods allowed to be called through this helper
	 */
	protected $_allowedChainMethods = array(
		'map',
		'join',
		'group',
		'invoke',
	);

	public function __construct() {
		$this->_processor = new ArrayChain(array());
	}

	protected function setDataToProcessor(array $data) {
		$this->_processor->copyFrom($data);
		return $this;
	}

	/**
	 * Creates an ArrayChain instance with given data
	 * @param array $data
	 * @return ArrayChain
	 */
	public function chain(array $data) {
		return new ArrayChain($data);
	}

	public function __call($method, $arguments) {
		if (in_array($method, $this->_allowedChainMethods)) {
			$data = $arguments[0];
			$arguments = array_slice($arguments, 1);
			$this->setDataToProcessor($data);
			return call_user_func_array(array($this->_processor, $method), $arguments)->toArray();
		} else {
			return parent::__call($method, $arguments);
		}
	}
}

/**
 * @class ArrayChain
 *
 * ArrayChain provides several helper methods and some 'syntax sugar' to simplify complex array processing.
 */
class ArrayChain extends CMap {

	/**
	 * @var array Stores the initial data, with which chain was created
	 *
	 * @see saveProgress
	 * @see rollback
	 */
	protected $_backup = array();

	/**
	 * @var bool Whether to allow to call built-in function's by their names when it is passed to callable parameters.
	 * <br> Example: <br>
	 * <code>
	 *  $chain = new ArrayChain($someArray); <br>
	 *  $chain->map('count'); // will apply built-in 'count' function to each array element <br>
	 *  $chain->useBuiltIn(false)->map('count') // will select a value under key 'count' from each array element <br>
	 * </code>
	 */
	protected $_isBuiltInFunctionsAllowed = true;

	/**
	 * Since CMap's data storage property is private, we can't just set new value to it. All that we can - to use 'copyFrom' method to save new data.
	 * But it can a pretty slow on large arrays, especially if you call several chain methods one by one.
	 * Using reflection can solve this problem, allowing to write new value directly.
	 *
	 * By default using reflection is enabled, but you can disable it, if this extension is not available or just if you think that it will not affect a performance.
	 * In this case, just change this value right here, in this class. Do not confuse.
	 * @var bool
	 */
	protected $_useReflection = true;

	/**
	 * @var ReflectionProperty
	 */
	private $_reflectionProperty;

	const LEFT_JOIN = 1;
	const INNER_JOIN = 2;

	const ONE_TO_ONE = 1;
	const ONE_TO_MANY = 2;

	public function __construct(array $data) {
		if ($this->_useReflection) {
			$this->_reflectionProperty = new ReflectionProperty('CMap', '_d');
			$this->_reflectionProperty->setAccessible(true);
		}
		$this->save($data);
		$this->_backup = $data;
	}

	public function useBuiltIn($value) {
		$this->_isBuiltInFunctionsAllowed = $value;
		return $this;
	}

	public function isBuiltInAllowed() {
		return $this->_isBuiltInFunctionsAllowed;
	}

	/**
	 * Skips chain progress by replacing current data with previously saved state.
	 * Can be useful, if you use chain methods in some external cycle and need to get different result on each iteration
	 * @return ArrayChain
	 */
	public function rollback() {
		$this->save($this->_backup);
		return $this;
	}

	/**
	 * Saved current chain state, so {@link rollback} will restore it instead of initial state.
	 * @return ArrayChain
	 */
	public function saveProgress() {
		$this->_backup = $this->toArray();
		return $this;
	}

	/**
	 * Saves given data to data storage property. Used internally to save the result of every data processing method.
	 * @param array $data
	 */
	private function save(array $data) {
		if ($this->_useReflection) {
			$this->_reflectionProperty->setValue($this, $data);
		} else {
			$this->copyFrom($data);
		}
	}

	// main methods, data processing :

	/**
	 * 'Map' method provides a some kind of 'syntax sugar' for 'array_map' function, but has no ability to map several arrays at once.
	 *
	 * <br> Examples: <br>
	 * <code>
	 *  $chain = new ArrayChain(array(
	 *      'first' => array('id' => 1, 'bio' => array('name' => 'me', 'age' => 20)),
	 *      'second' => array('id' => 2, 'bio' => array('name' => 'he', 'age' => 18)),
	 *  ));
	 *
	 * // Simplify an extracting of single field value:
	 * $chain->map('bio.name') // array('me', 'he')
	 * // Create a maps with user-defined keys:
	 * $chain->map('bio.name', 'bio.age') // array( 20 => 'me', 18 => 'he' )
	 * $chain->map('bio.name', function($data) { return $data['id'] * 5; }) // array( 5 => 'me', 10 => 'he' )
	 * // Preserve an original keys:
	 * $chain->map('bio.name', null, true) // array( 'first' => 'me', 'second' => 'he' )
	 * </code>
	 *
	 * Calling this method with single callable argument is absolutely equivalent to calling origin 'array_map'. <br>
	 * Calling with two arguments without using path aliases has same effect as {@link CHtml::listData} <br>
	 *
	 * @param null|string|array $valueField Fields to be values in map
	 * @param null|string|callable $keyField Field name to be used as key in map. Can be a path alias, but path must lead to string|numeric value, not to array|object
	 * @param bool $preserveKeys Whether to save keys of source array. If false, resulting array will have a numeric zero-based keys. Has no effect, if $keyField is not null.
	 * @return ArrayChain
	 */
	public function map($valueField, $keyField = null, $preserveKeys = false) {
		$data = $this->toArray();
		if (is_null($keyField) && !$preserveKeys) {
			$data = array_values($data);
		}

		$map = array();
		foreach ($data as $key => $element) {
			if (is_null($keyField)) {
				$keyFieldValue = $key;
			} else {
				$keyFieldValue = $this->processField($element, $keyField);
			}
			$valueFieldValue = $this->getValuesFromElement($element, $valueField);
			$map[$keyFieldValue] = $valueFieldValue;
		}

		$this->save($map);
		return $this;
	}


	/**
	 * Invokes specified method of each array element.
	 *
	 * @param string $methodName Path to method which must be called
	 * All extra arguments will be passed to called method.
	 * @return ArrayChain
	 */
	public function invoke($methodName) {
		$elements = $this->toArray();
		$result = array();
		$args = func_get_args();
		$methodName = array_shift($args);
		foreach ($elements as $element) {
			list($caller, $callee) = $this->parsePathPartial($element, $methodName, -1);
			$callback = array($caller, array_pop($callee));
			if ($args === array()) {
				$result[] = call_user_func($callback);
			} else {
				$result[] = call_user_func_array($callback, $args);
			}
		}
		$this->save($result); // called methods can change data state
		return $this;
	}

	/**
	 * Groups elements which has same keys or values, depending on set options.
	 *
	 * <br> Examples: <br>
	 * <code>
	 *  $chain = new ArrayChain(array(
	 *      'first' => array('id' => 1, 'bio' => array('name' => 'me', 'age' => 20), 'job' => 'Programmer'),
	 *      'second' => array('id' => 2, 'bio' => array('name' => 'he', 'age' => 18)),
	 *      'third' => array('id' => 3, 'bio' => array('name' => 'she', 'age' => 20)),
	 *  ));
	 *
	 * // -----------------------------------------------------------
	 *
	 * // Basic :
	 * $chain->group('bio.age');
	 * // Result:
	 *  array(
	 *     20 => array(
	 *         'first' => array('id' => 1, 'bio' => array('name' => 'me', 'age' => 20), 'job' => 'Programmer'),
	 *         'third' => array('id' => 3, 'bio' => array('name' => 'she', 'age' => 20)),
	 *     ),
	 *    18 => array(
	 *      'second' => array('id' => 2, 'bio' => array('name' => 'he', 'age' => 18)),
	 *    ),
	 * )
	 *
	 * // -----------------------------------------------------------
	 *
	 * // Set a group name :
	 * $chain->group(array('ages' => 'bio.age'));
	 * // Result:
	 * array(
	 *    'ages' => array(
	 *       // here the same as in 'basic' example
	 *    )
	 * )
	 *
	 * // -----------------------------------------------------------
	 *
	 * // Several groups, using functions :
	 * $chain->group(array(
	 *      'ages' => 'bio.age',
	 *      'name_length' => function($el) { return strlen($el['bio']['name']); },
	 * ));
	 * // Result:
	 * array(
	 *    'ages' => array(
	 *       // here the same as in 'basic' example
	 *    ),
	 *    'name_length' => array(
	 *        2 => array(
	 *          'first' => array('id' => 1, 'bio' => array('name' => 'me', 'age' => 20), 'job' => 'Programmer'),
	 *          'second' => array('id' => 2, 'bio' => array('name' => 'he', 'age' => 18)),
	 *        ),
	 *        3 => array(
	 *          'third' => array('id' => 3, 'bio' => array('name' => 'she', 'age' => 20)),
	 *        ),
	 *     )
	 * )
	 *
	 * // -----------------------------------------------------------
	 *
	 * // Change grouping mode:
	 * $chain->group(
	 *      array(
	 *          'ages' => 'bio.age',
	 *          'name_length' => function($el) { return strlen($el['bio']['name']); },
	 *          'just_entire_element' => null
	 *      ),
	 *      false // <= attention here
	 * );
	 * // Result :
	 * array(
	 *    'ages' => array(
	 *       'first' => 20,
	 *       'second' => 18,
	 *       'third' => 20,
	 *    ),
	 *    'name_length' => array(
	 *       'first' => 2,
	 *       'second' => 2,
	 *       'third' => 3,
	 *     ),
	 *    'just_entire_element' => // the entire source array is here
	 * )
	 *
	 * // -----------------------------------------------------------
	 *
	 * // Skipping empty results:
	 * $chain->group('job', false); // skipping is enabled by default
	 * // Result:
	 * array(
	 *   'first' => 'Programmer'
	 * )
	 *
	 * $chain->group('job', false, false);
	 * // Result:
	 * array(
	 *   'first' => 'Programmer',
	 *   'second' => null,
	 *   'third' => null,
	 * )
	 *
	 * * $chain->group('job', true, false);
	 * // Result:
	 * array(
	 *   'Programmer' => array(
	 *      'first' => array('id' => 1, 'bio' => array('name' => 'me', 'age' => 20), 'job' => 'Programmer'),
	 *   ),
	 *  '' => array(
	 *      'second' => array('id' => 2, 'bio' => array('name' => 'he', 'age' => 18)),
	 *      'third' => array('id' => 3, 'bio' => array('name' => 'she', 'age' => 20)),
	 *   ),
	 * )
	 * </code>
	 *
	 * @param string|callable|array $fields list of fields by which grouping will be done.
	 *      If value is not an array, or is a callback set in array form, it will be wrapped to array.
	 * @param bool $byValues How to group data. See examples above.
	 * @param bool $skipNull Whether to exclude from result records which has no specified key or value (equals to null).

	 * @return ArrayChain
	 * @throws Exception
	 */
	public function group($fields, $byValues = true, $skipNull = true) {
		if (is_null($fields)) {
			throw new Exception(__METHOD__ . ': fields list to be grouped must be specified!');
		}

		$isArrayGiven = true;
		if (!is_array($fields) || (is_array($fields) && is_callable($fields))) {
			$fields = array($fields);
			$isArrayGiven = false;
		}

		$result = array();
		$data = $this->toArray();
		foreach ($data as $key => $element) {
			foreach ($fields as $groupName => $groupedFields) {
				$fieldValue = $this->getValuesFromElement($element, $groupedFields);
				if ($skipNull && is_null($fieldValue)) {
					continue;
				}

				if (!$byValues) {
					$result[$groupName][$key] = $fieldValue;
				} else {
					if (!(is_scalar($fieldValue) || is_null($fieldValue))) {
						throw new Exception(__METHOD__ . ": can\'t group by field '{$groupName}': field value is not a string or number");
					}
					$result[$groupName][$fieldValue][$key] = $element;
				}
			}
		}

		if (count($result) === 1 && !$isArrayGiven) {
			$result = array_shift($result);
		}

		$this->save($result);
		return $this;
	}

	/**
	 * Filters given array to select elements matching specified join configuration.
	 * Effect is similar to SQL JOIN with remaining only left key and joined right columns.
	 *
	 * It's a multifunctional method which allows to perform a complex data transformations.
	 *
	 * <br> Examples: <br>
	 * <code>
	 *  $chain = new ArrayChain(array(
	 *      'first' => array('id' => 1, 'status' => 'active', 'info' => array('job' => 'Programmer')),
	 *      'second' => array('id' => 2, 'status' => 'active', 'info' => array('job' => 'Tester')),
	 *      'third' => array('id' => 3, 'status' => 'pending', 'info' => array('job' => 'Html-coder')),
	 *  ));
	 *
	 *  $joynee = array(
	 *      'profiles' => array(
	 *          array('user_id' => 1, 'age' => 20, 'name' => 'Me'),
	 *          array('user_id' => 2, 'age' => 18, 'name' => 'She'),
	 *          array('user_id' => 3, 'age' => 21, 'name' => 'He'),
	 *      ),
	 *      'jobs' => array(
	 *          array( 'vacancy' => 'Programmer', 'salary' => 5000, 'min_experience' => 3),
	 *          array( 'vacancy' => 'Tester', 'salary' => 4000, 'min_experience' => 2),
	 *          array( 'vacancy' => 'Html-coder', 'salary' => 4500, 'min_experience' => 1),
	 *      ),
	 *  );
	 *
	 * // -----------------------------------------------------------
	 *
	 * // Basic :
	 * $chain->join($joynee['profiles'], array('l' => 'id', 'r' => 'user_id'));
	 * // Result:
	 *  array(
	 *     1 => array( 0 => array('user_id' => 1, 'age' => 20, 'name' => 'Me') ),
	 *     2 => array( 0 => array('user_id' => 2, 'age' => 18, 'name' => 'She') ),
	 *     3 => array( 0 => array('user_id' => 3, 'age' => 21, 'name' => 'He') ),
	 * )
	 *
	 * // -----------------------------------------------------------
	 *
	 * // Join ratio:
	 * $chain->join($joynee['profiles'], array('l' => 'id', 'r' => 'user_id'), array(
	 *      'joinRatio' => ArrayChain::ONE_TO_ONE
	 * ));
	 * // Result :
	 * array(
	 *     1 => array('user_id' => 1, 'age' => 20, 'name' => 'Me'),
	 *     2 => array('user_id' => 2, 'age' => 18, 'name' => 'She'),
	 *     3 => array('user_id' => 3, 'age' => 21, 'name' => 'He'),
	 * )
	 *
	 * // -----------------------------------------------------------
	 *
	 * // Selection, projection, using path aliases and functions :
	 *  $chain->join($joynee['profiles'],
	 *      array(
	 *          'l' => 'id',
	 *          'r' => function($el) { return strlen($el['name']); },
	 *      ),
	 *      array(
	 *          'project' => 'info.job',
	 *          'select' => function($el) { return strtoupper($el['name']); }
     *      )
	 * );
	 * // Result:
	 *  array(
	 *     'Programmer' => array(), // <= nothing to join to id == 1, because there are no right items with name length == 1.
	 *     'Tester' => array( 'ME', 'HE' ),
	 *     'Html-coder' => array( 'SHE' )
	 * )
	 *
	 * // -----------------------------------------------------------
	 *
	 * // Join type:
	 * $chain->join($joynee['profiles'], array(
	 *    'l' => 'id',
	 *    'r' => function($el) { return $el['user_id'] - 2; },
	 * ))
	 * // Result:
	 * array(
	 *      1 => array('user_id' => 3, 'age' => 21, 'name' => 'He'),
	 *      2 => array(),
	 *      3 => array(),
	 * )
	 *
	 * $chain->join($joynee['profiles'],
	 *      array(
	 *          'l' => 'id',
	 *          'r' => function($el) { return $el['user_id'] - 2; },
	 *      ),
	 *      array('joinType' => ArrayChain::INNER_JOIN)
	 * )
	 * // Result:
	 * array(
	 *    1 => array('user_id' => 3, 'age' => 21, 'name' => 'He'),
	 * )
	 * // -----------------------------------------------------------
	 *
	 * // Multiple joining:
	 * $chain->join($joynee['jobs'],
	 *  array(
	 *      'money' => array(
	 *          'l' => 'info.job',
	 *          'r' => 'vacancy',
	 *      ),
	 *      'experience' => array(
	 *          'l' => 'id',
	 *          'r' => 'min_experience',
	 *      ),
	 * ),
	 * array(
	 *      'project' => array(
	 *          'experience' => 'status',
	 *      ),
	 *      'select' => array(
	 *          'money' => array(
	 *              'salary',
	 *              'formatted' => function($el) { return $el['salary'] / 1000 . 'K'; }
	 *          )
	 *      ),
	 *
	 * ))
	 * // Result:
	 * array(
	 *      'money' => array(
	 *          // keys are equals to left key, because projection for group 'money' is not specified
	 *          'Programmer' => array(
	 *              0 => array( 'salary' => 5000, 'formatted' => '5K' )
	 *          ),
	 *         'Tester' => array(
	 *              0 => array( 'salary' => 4000, 'formatted' => '4K' )
	 *          ),
	 *          'Html-coder' => array(
	 *              0 => array( 'salary' => 4500, 'formatted' => '4.5K' )
	 *          ),
	 *      ),
	 *      'experience' => array(
	 *          // values are entire right items, because selection for group 'experience' is not specified
	 *          'active' => array(
	 *              0 => array( 'vacancy' => 'Html-coder', 'salary' => 4500, 'min_experience' => 1),
	 *              1 => array( 'vacancy' => 'Tester', 'salary' => 4000, 'min_experience' => 2),
	 *          ),
	 *         'pending' => array(
	 *              0 => array( 'vacancy' => 'Programmer', 'salary' => 5000, 'min_experience' => 3),
	 *          ),
	 *      ),
	 * )
	 * // Note, if you specify 'select' or 'project' value as string, without wrapping it to array, it will be applied to all groups.
	 *
	 * </code>
	 *
	 * @param array $rightArray
	 * @param array $joinKeys array('l' => left_key_field, 'r' => right_key_field) OR array of such arrays.
	 *              The second form is used for multiple joining simultaneously; using such form changes the behavior of 'select' and 'project' options (see below).
	 * @param array $options <br>
	 *      Following options available: <br> <br>
	 *		'project' => null|string|array - field from left array whose value should be a key in result array. <br>
	 *              If null, left key value will be used. <br>
	 *              Array form is used for multiple joining, then keys must match to created groups names, and values are projected fields <br>
	 *      <br>
	 * 		'select' => null|string|array - values to be joined from right array (see {@link getValuesFromElement}) <br>
	 *              If multiple joining is performing, and you want to specify array selector, you need additionally to wrap it to array <br>
	 *      <br>
	 * 		'joinType' => ArrayChain::LEFT_JOIN | ArrayChain::INNER_JOIN - just like in SQL. Default to ArrayHelper::LEFT_JOIN. <br>
	 *      <br>
	 * 		'joinRatio' => ArrayChain::ONE_TO_ONE | ArrayChain::ONE_TO_MANY - whether joined values should be single values or arrays of values. <br>
	 * 						Default to ArrayChain::ONE_TO_ONE - that means, that even is there are several values in right array to be joined to current key, only one of them will be returned. <br>
	 *
	 * @throws Exception
	 * @return ArrayChain
	 */
	public function join(array $rightArray, array $joinKeys, array $options = array()) {
		$isKeysArrayGiven = true;
		if (count($joinKeys) === 2 && array_diff(array_keys($joinKeys), array('l', 'r')) === array()) {
			$joinKeys = array($joinKeys);
			$isKeysArrayGiven = false;
		}

		$joinType = $options['joinType'] ?: self::LEFT_JOIN;
		$joinRatio = $options['joinRatio'] ?: self::ONE_TO_MANY;

		$leftArray = $this->toArray();
		$result = array();
		foreach ($joinKeys as $groupName => $keys) {
			$leftKey = $keys['l'];
			$rightKey = $keys['r'];

			$select = $options['select'];
			if (is_array($select) && $isKeysArrayGiven) {
				$select = $select[$groupName];
			}
			$project = $options['project'];
			if (is_array($project) && $isKeysArrayGiven) {
				$project = $project[$groupName];
			}
			if ($project === null) {
				$project = $leftKey;
			}

			$rightArrayInternal = $rightArray;
			$groupResult = array();
			foreach ($leftArray as $leftArrayItem) {
				$leftKeyValue   = $leftKey !== null ? $this->processField($leftArrayItem, $leftKey) : $leftArrayItem;
				$projectedValue = $project !== null ? $this->processField($leftArrayItem, $project) : $leftKeyValue;

				if (is_array($projectedValue) || is_object($projectedValue)) {
					throw new Exception(__METHOD__ . ': Invalid join key for left array. Cannot set array/object as array key');
				}

				if ($joinType === self::LEFT_JOIN && !is_array($groupResult[$projectedValue])) {
					$groupResult[$projectedValue] = array();
				}

				$usedIndexes = array();
				foreach ($rightArrayInternal as $itemIndex => $rightArrayItem) {
					$rightKeyValue = $rightKey !== null ? $this->processField($rightArrayItem, $rightKey) : $rightArrayItem;
					if ($rightKeyValue === $leftKeyValue) {
						$groupResult[$projectedValue][] = $this->getValuesFromElement($rightArrayItem, $select);
						$usedIndexes[$itemIndex] = $itemIndex;
					}
				}

				$joinedItemsCount = count($groupResult[$projectedValue]);
				if ($joinedItemsCount > 0) {
					//reduce right array to speed up further iterations
					$rightArrayInternal = array_diff_key($rightArrayInternal, $usedIndexes);
				}
			}
			if ($joinRatio === self::ONE_TO_ONE) {
				foreach ($groupResult as &$groupedValues) {
					$groupedValues = array_shift($groupedValues);
				}
			}
			$result[$groupName] = $groupResult;
		}

		if (!$isKeysArrayGiven) {
			$result = array_shift($result);
		}

		$this->save($result);
		return $this;
	}

	// implement some standard array functions to support chaining for convenience:

	/**
	 * @param callable $filterFunction If this value is null, will be used a function which filters out only exactly 'null' values (instead of default php php which filters out 'empty' values like '0' or '')
	 * @return ArrayChain
	 */
	public function filter($filterFunction) {
		if (is_null($filterFunction)) {
			$filterFunction = function($value) { return !is_null($value); };
		}

		$this->save(array_filter($this->toArray(), $filterFunction));
		return $this;
	}

	/**
	 * @param callable $function
	 * @param mixed $functionData
	 * @return ArrayChain
	 */
	public function walk($function, $functionData = null) {
		$data = $this->toArray();
		array_walk($data, $function, $functionData); // function can change data if argument is a reference
		$this->save($data);
		return $this;
	}

	/**
	 * @param callable $function
	 * @param mixed $functionData
	 * @return ArrayChain
	 */
	public function walk_recursive($function, $functionData = null) {
		$data = $this->toArray();
		array_walk_recursive($data, $function, $functionData); // function can change data if argument is a reference
		$this->save($data);
		return $this;
	}


	/**
	 * @param callable $function
	 * @param mixed $initialValue
	 * @return ArrayChain
	 */
	public function reduce($function, $initialValue = null) {
		return array_reduce($this->toArray(), $function, $initialValue);
	}

	/**
	 * @return ArrayChain
	 */
	public function intersect() {
		$this->save(array_intersect($this->toArray(), func_get_args()));
		return $this;
	}

	/**
	 * @return ArrayChain
	 */
	public function diff() {
		$this->save(array_diff($this->toArray(), func_get_args()));
		return $this;
	}

	/**
	 * @return ArrayChain
	 */
	public function intersect_key() {
		$this->save(array_intersect_key($this->toArray(), func_get_args()));
		return $this;
	}

	/**
	 * @return ArrayChain
	 */
	public function diff_key() {
		$this->save(array_diff_key($this->toArray(), func_get_args()));
		return $this;
	}

	// override some CMap methods to support chaining :

	/**
	 * @overridden to allow to export array data to given variable, without breaking chain
	 * @param &$buffer
	 * @return array|ArrayChain
	 */
	public function toArray(&$buffer = null) {
		$data = parent::toArray();
		if (!is_null($buffer)) {
			$buffer = $data;
			return $this;
		}
		return $data;
	}

	/**
	 * @overridden to allow to use path aliases instead of just keys to extract data.
	 * Also writing value to given buffer is allowed, to support chaining
	 *
	 * @param string $key
	 * @param &$buffer
	 * @return mixed
	 */
	public function itemAt($key, &$buffer = null) {
		$data = $this->parsePath($this->toArray(), $key);
		if (!is_null($buffer)) {
			$buffer = $data;
			return $this;
		}
		return $data;
	}

	/**
	 * @param mixed $key
	 * @param mixed $value
	 * @return ArrayChain
	 */
	public function add($key, $value) {
		parent::add($key, $value);
		return $this;
	}

	/**
	 * @return ArrayChain
	 */
	public function clear() {
		parent::clear();
		return $this;
	}

	/**
	 * @param mixed $data
	 * @return ArrayChain
	 */
	public function copyFrom($data) {
		parent::copyFrom($data);
		return $this;
	}

	/**
	 * @param mixed $data
	 * @param bool $recursive
	 * @return ArrayChain
	 */
	public function mergeWith($data, $recursive = true) {
		parent::mergeWith($data, $recursive);
		return $this;
	}

	/**
	 * @param mixed $key
	 * @param mixed &$returnValue Buffer to which removed element will be written
	 * @return ArrayChain
	 */
	public function remove($key, &$returnValue = null) {
		$returnValue = parent::remove($key);
		return $this;
	}

	// internal :

	/**
	 * Checks the argument is callable, taking into account an internal logic of {@link $_isBuiltInFunctionsAllowed} property
	 *
	 * @param string|array $value
	 * @return bool
	 */
	private function checkIsCallable($value) {
		return (!is_string($value) && is_callable($value))
			|| (is_string($value) && $this->_isBuiltInFunctionsAllowed && function_exists($value));
	}

	/**
	 * Extracts from given element a value, specified by attribute name/path alias or extractor function
	 * @param $element
	 * @param string|callable $fieldName
	 * @return mixed
	 */
	private function processField($element, $fieldName) {
		if ($this->checkIsCallable($fieldName)) {
			return call_user_func($fieldName, $element);
		} else {
			return $this->parsePath($element, $fieldName);
		}
	}

	/**
	 * Extracts from array or an object a value under given path.separated.by.dots.with.0.numbers.1.support.2.also
	 *
	 * @param array|Object $element
	 * @param string $path Attribute path
	 * @param int $offset Specifies which part of path must be parsed.
	 *      If 0, entire path will be passed, till the end.
	 *      If > 0, only specified count of steps will be passed
	 *      If < 0, parsing will stop on specified steps count before end
	 *
	 *      If > 0 and value > path length - entire path will be passed
	 *      If < 0 and absolute value > path length - no steps will be passed
	 * @return array($passed_path_last_value, array_of_remained_steps)
	 */
	private function parsePathPartial($element, $path, $offset = 0) {
		$pathParts = explode('.', $path);
		for ($i = 0, $len = count($pathParts); $i < $len; $i++) {
			$break = ($offset > 0 && $i >= $offset)
				  || ($offset < 0 && $i >= $offset + $len);
			if ($break) break;
			$element = $this->getFieldValue($element, array_shift($pathParts));
		}
		return array($element, $pathParts);
	}

	/**
	 * @param array|Object $element
	 * @param string $path Attribute path
	 * @return mixed
	 */
	private function parsePath($element, $path) {
		return array_shift($this->parsePathPartial($element, $path, 0));
	}

	/**
	 * Gets the value of the given field from array or an object.
	 *
	 * @param array|Object $element
	 * @param string $fieldName Attribute name
	 * @return mixed
	 * @throws Exception
	 */
	private function getFieldValue($element, $fieldName) {
		if (is_array($element)) {
			return $element[$fieldName];
		} elseif (is_object($element)) {
			return $element->$fieldName;
		} else {
			throw new Exception(__METHOD__ . ": cannot process field '{$fieldName}': parent element should be an array or an object.");
		}
	}

	/**
	 * Get from given element values of all fields, specified in @param $valueField
	 *
	 * @param array|Object $element
	 * @param string|callable|array $valueField
	 * @return mixed
	 * @throws Exception
	 */
	private function getValuesFromElement($element, $valueField) {
		if ($valueField === null) {
			return $element;
		}
		if ($this->checkIsCallable($valueField)) {
			return call_user_func($valueField, $element);
		} elseif (is_array($valueField)) {
			$result = array();
			foreach ($valueField as $key => $fieldName) {
				if ($this->checkIsCallable($fieldName)) {
					$result[$key] = call_user_func($fieldName, $element);
				} elseif (!is_array($fieldName)) {
					$result[$this->basename($fieldName)] = $this->parsePath($element, $fieldName);
				} else {
					$result[$this->basename($key)] = $this->getValuesFromElement($this->parsePath($element, $key), $fieldName);
				}
			}
			return $result;
		} else {
			return $this->parsePath($element, $valueField);
		}
	}

	/**
	 * If field name is a path alias, return last part of this path
	 *
	 * @param $fieldName
	 * @return string
	 */
	private function basename($fieldName) {
		return array_pop(explode('.', $fieldName));
	}
}
