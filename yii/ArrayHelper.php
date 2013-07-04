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

	//

	/**
	 * Creates an array with a structure, specified by keys of {@link $map} array, and values, specified by corresponding values.
	 *
	 * Usage:
	 * <code>
	 * $source = array('external' => array('internal' => 42));
	 * $map = array('some.nested.field' => 'external.internal');
	 * $result = Yii::app()->arrayHelper->mapAttributes($map, $source);
	 *
	 * // Result:
	 * array(
	 *      'some' => array(
	 *          'nested' => array(
	 *              'field' => 42
	 *          )
	 *      )
	 * )
	 * </code>
	 *
	 * @param array $map
	 * @param object|array $dataSource
	 * @return array
	 *
	 * @see createArrayPath
	 * @see parsePathAlias
	 */
	public function mapAttributes(array $map, $dataSource) {
		$result = array();
		foreach ($map as $resultAttributePath => $sourceAttributePath) {
			// TODO: not optimal, too much merges
			$this->createArrayPath($result, $resultAttributePath, $this->parsePathAlias($dataSource, $sourceAttributePath));
		}
		return $result;
	}

	/**
	 * Creates sub-arrays in given array according to given path alias.
	 * If exact path already exists, value will we overridden.
	 *
	 * @param array $array Array to be modified
	 * @param string $path keys.separated.by.dots
	 * @param mixed $value this value will be assigned to last path element
	 * @return array
	 *
	 * @see mapAttributes
	 */
	private function createArrayPath(&$array, $path, $value = null) {
		$pathParts = explode('.', $path);
		$head = array();
		$result = &$head;
		while (count($pathParts)) {
			$fieldName = array_shift($pathParts);
			$head[$fieldName] = array();
			$head = &$head[$fieldName];
		}
		$head = $value;
		$array = CMap::mergeArray($array, $result);
		return $array;
	}

	/**
	 * Extracts from given array or an object value, specified by given path alias.
	 *
	 * @param object|array $dataStorage
	 * @param string $path  keys.separated.by.dots
	 * @return mixed
	 */
	private function parsePathAlias($dataStorage, $path) {
		$pathParts = explode('.', $path);
		$result = $dataStorage;
		while (count($pathParts)) {
			$fieldName = array_shift($pathParts);
			$result = is_object($dataStorage) ? $result->$fieldName : $result[$fieldName];
		}
		return $result;
	}
}