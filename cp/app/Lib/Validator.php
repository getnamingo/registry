<?php

namespace App\Lib;

use Respect\Validation\Validator as Respect;
use Respect\Validation\Exceptions\NestedValidationException;

/**
 * Validator
 *
 * @author  Hezekiah O. <support@hezecom.com>
 */
class Validator
{
	protected $errors;

	public function validate($request, array $rules)
	{
		$data = $request->getParsedBody();

		foreach ($rules as $field => $rule) {
		    $fieldName = str_replace('_',' ',$field);
			try {
				$rule->setName(ucfirst($fieldName))->assert($data[$field]);
			} catch (NestedValidationException $e) {
				$this->errors[$field] = $e->getMessages();
			}
		}
		$_SESSION['errors'] = $this->errors;
		return $this;
	}

	public function failed()
	{
		return !empty($this->errors);
	}
}
