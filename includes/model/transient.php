<?php
class TransientResponse {
	public $name;
	public $productsMap_before_clear;
	public $age;
	public $cleared;
	public $productsMap_after_clear;

	public function __construct($name, $productsMapBeforeClear, $age, $cleared, $productsMapAfterClear) {
		$this->name = $name;
		$this->productsMap_before_clear = $productsMapBeforeClear;
		$this->age = $age;
		$this->cleared = $cleared;
		$this->productsMap_after_clear = $productsMapAfterClear;
	}
}

class TransientClearCacheResponse {
	public $transients = array();

	public function addTransient($transientName, $productsMapBeforeClear, $age, $cleared, $productsMapAfterClear) {
		$transientResponse = new TransientResponse(
			$transientName,
			$productsMapBeforeClear,
			$age,
			$cleared,
			$productsMapAfterClear
		);

		$this->transients[$transientName] = $transientResponse;
	}
}